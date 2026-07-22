<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Self-contained S3-compatible client (AWS Signature V4). Streamed PUT/GET so
 * large archives never have to fit in memory. Works with Amazon S3, Minio,
 * Garage, Cloudflare R2, DigitalOcean Spaces, Google Cloud Storage (interop),
 * and any other S3-compatible endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_S3_Client {

	// A single PUT above this size risks tripping a reverse proxy's request-body
	// cap in front of the endpoint (Cloudflare's free-plan limit is 100MB) —
	// switch to S3 multipart upload instead of one giant request.
	const MULTIPART_THRESHOLD = 80 * 1024 * 1024;
	const MULTIPART_PART_SIZE = 20 * 1024 * 1024;

	private $region;
	private $bucket;
	private $access_key;
	private $secret_key;
	private $path_style;
	private $scheme;
	private $host;

	public function __construct( array $args ) {
		$this->region     = ! empty( $args['region'] ) ? $args['region'] : 'us-east-1';
		$this->bucket     = isset( $args['bucket'] ) ? $args['bucket'] : '';
		$this->access_key = isset( $args['access_key'] ) ? $args['access_key'] : '';
		$this->secret_key = isset( $args['secret_key'] ) ? $args['secret_key'] : '';
		$this->path_style = ! empty( $args['path_style'] );

		$endpoint = isset( $args['endpoint'] ) ? trim( $args['endpoint'] ) : '';
		if ( $endpoint !== '' ) {
			$parsed       = wp_parse_url( $endpoint );
			$this->scheme = ! empty( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
			$this->host   = ! empty( $parsed['host'] ) ? $parsed['host'] : preg_replace( '#^https?://#', '', untrailingslashit( $endpoint ) );
			if ( ! empty( $parsed['port'] ) ) {
				$this->host .= ':' . $parsed['port'];
			}
		} else {
			$this->scheme     = 'https';
			$this->host       = 's3.' . $this->region . '.amazonaws.com';
			$this->path_style = false;
		}
	}

	/**
	 * Stream-upload a local file.
	 *
	 * @param string $key
	 * @param string $file_path
	 * @param string $content_type
	 * @return true|WP_Error
	 */
	public function put_object( $key, $file_path, $content_type = 'application/octet-stream' ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'isx_s3_no_curl', __( 'ต้องมีส่วนขยาย PHP cURL เพื่ออัปโหลดไปยัง S3', 'insightx-backup' ) );
		}
		if ( ! is_file( $file_path ) ) {
			return new WP_Error( 'isx_s3_no_file', __( 'ไม่พบไฟล์ที่จะอัปโหลด', 'insightx-backup' ) );
		}

		$key  = ltrim( $key, '/' );
		$host = $this->path_style ? $this->host : $this->bucket . '.' . $this->host;
		$path = $this->path_style ? '/' . $this->bucket . '/' . $key : '/' . $key;
		$uri  = self::encode_path( $path );
		$size = filesize( $file_path );

		if ( $size > self::MULTIPART_THRESHOLD ) {
			return $this->put_object_multipart( $host, $uri, $file_path, $size, $content_type );
		}

		$url     = $this->scheme . '://' . $host . $uri;
		$payload = 'UNSIGNED-PAYLOAD';
		$headers = $this->signed_headers( 'PUT', $host, $uri, '', $payload );
		$headers[] = 'Content-Type: ' . $content_type;
		$headers[] = 'Content-Length: ' . $size;
		$headers[] = 'Expect:';

		$handle = fopen( $file_path, 'rb' );
		if ( $handle === false ) {
			return new WP_Error( 'isx_s3_open', __( 'เปิดไฟล์เพื่ออัปโหลดไม่สำเร็จ', 'insightx-backup' ) );
		}

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_PUT, true );
		curl_setopt( $ch, CURLOPT_UPLOAD, true );
		curl_setopt( $ch, CURLOPT_INFILE, $handle );
		curl_setopt( $ch, CURLOPT_INFILESIZE, $size );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 0 );

		$body   = curl_exec( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error  = curl_error( $ch );
		curl_close( $ch );
		fclose( $handle );

		if ( $body === false || $error !== '' ) {
			return new WP_Error( 'isx_s3_transport', $error !== '' ? $error : __( 'การเชื่อมต่อล้มเหลว', 'insightx-backup' ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'isx_s3_http_' . $status,
				sprintf( __( 'เซิร์ฟเวอร์ตอบกลับ HTTP %1$d: %2$s', 'insightx-backup' ), (int) $status, self::extract_error_message( $body ) )
			);
		}
		return true;
	}

	/**
	 * Upload a large file as an S3 multipart upload — CreateMultipartUpload,
	 * then one signed PUT per ~20MB part, then CompleteMultipartUpload. Keeps
	 * every individual request well under a reverse proxy's body-size cap,
	 * unlike a single PUT of the whole archive.
	 *
	 * @param string $host
	 * @param string $uri
	 * @param string $file_path
	 * @param int    $size
	 * @param string $content_type
	 * @return true|WP_Error
	 */
	private function put_object_multipart( $host, $uri, $file_path, $size, $content_type ) {
		$upload_id = $this->multipart_initiate( $host, $uri, $content_type );
		if ( is_wp_error( $upload_id ) ) {
			return $upload_id;
		}

		$handle = fopen( $file_path, 'rb' );
		if ( $handle === false ) {
			$this->multipart_abort( $host, $uri, $upload_id );
			return new WP_Error( 'isx_s3_open', __( 'เปิดไฟล์เพื่ออัปโหลดไม่สำเร็จ', 'insightx-backup' ) );
		}

		$parts       = array();
		$part_number = 0;

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::MULTIPART_PART_SIZE );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}
			++$part_number;

			$etag = $this->multipart_upload_part( $host, $uri, $upload_id, $part_number, $chunk );
			if ( is_wp_error( $etag ) ) {
				fclose( $handle );
				$this->multipart_abort( $host, $uri, $upload_id );
				return $etag;
			}
			$parts[] = array(
				'number' => $part_number,
				'etag'   => $etag,
			);
		}
		fclose( $handle );

		if ( empty( $parts ) ) {
			$this->multipart_abort( $host, $uri, $upload_id );
			return new WP_Error( 'isx_s3_no_file', __( 'ไม่พบไฟล์ที่จะอัปโหลด', 'insightx-backup' ) );
		}

		$result = $this->multipart_complete( $host, $uri, $upload_id, $parts );
		if ( is_wp_error( $result ) ) {
			$this->multipart_abort( $host, $uri, $upload_id );
			return $result;
		}
		return true;
	}

	/**
	 * @return string|WP_Error UploadId
	 */
	private function multipart_initiate( $host, $uri, $content_type ) {
		$query   = 'uploads=';
		$url     = $this->scheme . '://' . $host . $uri . '?' . $query;
		$payload = hash( 'sha256', '' );
		$headers = $this->signed_headers( 'POST', $host, $uri, $query, $payload );
		$headers[] = 'Content-Type: ' . $content_type;
		$headers[] = 'Content-Length: 0';

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, '' );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );

		$body   = curl_exec( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error  = curl_error( $ch );
		curl_close( $ch );

		if ( $body === false || $error !== '' ) {
			return new WP_Error( 'isx_s3_transport', $error !== '' ? $error : __( 'การเชื่อมต่อล้มเหลว', 'insightx-backup' ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'isx_s3_http_' . $status,
				sprintf( __( 'เซิร์ฟเวอร์ตอบกลับ HTTP %1$d: %2$s', 'insightx-backup' ), (int) $status, self::extract_error_message( $body ) )
			);
		}

		$upload_id = self::xml_value( (string) $body, 'UploadId' );
		if ( $upload_id === '' ) {
			return new WP_Error( 'isx_s3_multipart', __( 'เริ่ม multipart upload ไม่สำเร็จ — ไม่พบ UploadId', 'insightx-backup' ) );
		}
		return $upload_id;
	}

	/**
	 * @return string|WP_Error ETag of the uploaded part
	 */
	private function multipart_upload_part( $host, $uri, $upload_id, $part_number, $chunk ) {
		$query   = 'partNumber=' . (int) $part_number . '&uploadId=' . rawurlencode( $upload_id );
		$url     = $this->scheme . '://' . $host . $uri . '?' . $query;
		$payload = 'UNSIGNED-PAYLOAD';
		$headers = $this->signed_headers( 'PUT', $host, $uri, $query, $payload );
		$headers[] = 'Content-Length: ' . strlen( $chunk );
		$headers[] = 'Expect:';

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $chunk );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 0 );

		$response     = curl_exec( $ch );
		$status       = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size  = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$error        = curl_error( $ch );
		curl_close( $ch );

		if ( $response === false || $error !== '' ) {
			return new WP_Error( 'isx_s3_transport', $error !== '' ? $error : __( 'การเชื่อมต่อล้มเหลว', 'insightx-backup' ) );
		}

		$response_headers = substr( (string) $response, 0, $header_size );
		$body             = substr( (string) $response, $header_size );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'isx_s3_http_' . $status,
				sprintf( __( 'เซิร์ฟเวอร์ตอบกลับ HTTP %1$d: %2$s', 'insightx-backup' ), (int) $status, self::extract_error_message( $body ) )
			);
		}

		if ( preg_match( '/^ETag:\s*"?([^"\r\n]+)"?/mi', $response_headers, $m ) ) {
			return $m[1];
		}
		return new WP_Error( 'isx_s3_multipart', __( 'อัปโหลดส่วนไฟล์ไม่สำเร็จ — ไม่พบ ETag', 'insightx-backup' ) );
	}

	/**
	 * @return true|WP_Error
	 */
	private function multipart_complete( $host, $uri, $upload_id, array $parts ) {
		$query = 'uploadId=' . rawurlencode( $upload_id );
		$url   = $this->scheme . '://' . $host . $uri . '?' . $query;

		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $part ) {
			$xml .= '<Part><PartNumber>' . (int) $part['number'] . '</PartNumber><ETag>"' . esc_xml( $part['etag'] ) . '"</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$payload = hash( 'sha256', $xml );
		$headers = $this->signed_headers( 'POST', $host, $uri, $query, $payload );
		$headers[] = 'Content-Type: application/xml';
		$headers[] = 'Content-Length: ' . strlen( $xml );

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $xml );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );

		$body   = curl_exec( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error  = curl_error( $ch );
		curl_close( $ch );

		if ( $body === false || $error !== '' ) {
			return new WP_Error( 'isx_s3_transport', $error !== '' ? $error : __( 'การเชื่อมต่อล้มเหลว', 'insightx-backup' ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'isx_s3_http_' . $status,
				sprintf( __( 'เซิร์ฟเวอร์ตอบกลับ HTTP %1$d: %2$s', 'insightx-backup' ), (int) $status, self::extract_error_message( $body ) )
			);
		}
		return true;
	}

	/**
	 * Best-effort cleanup so a failed multipart upload doesn't leave orphaned
	 * parts billing against the bucket. Errors here are swallowed — the
	 * caller already has a real error to report back to the user.
	 *
	 * @return void
	 */
	private function multipart_abort( $host, $uri, $upload_id ) {
		$query   = 'uploadId=' . rawurlencode( $upload_id );
		$url     = $this->scheme . '://' . $host . $uri . '?' . $query;
		$payload = hash( 'sha256', '' );
		$headers = $this->signed_headers( 'DELETE', $host, $uri, $query, $payload );

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
		curl_exec( $ch );
		curl_close( $ch );
	}

	/**
	 * Stream-download an object to a local file.
	 *
	 * @param string $key
	 * @param string $dest_path
	 * @return true|WP_Error
	 */
	public function get_object( $key, $dest_path ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'isx_s3_no_curl', __( 'ต้องมีส่วนขยาย PHP cURL เพื่อดาวน์โหลดจาก S3', 'insightx-backup' ) );
		}

		$key     = ltrim( $key, '/' );
		$host    = $this->path_style ? $this->host : $this->bucket . '.' . $this->host;
		$path    = $this->path_style ? '/' . $this->bucket . '/' . $key : '/' . $key;
		$uri     = self::encode_path( $path );
		$url     = $this->scheme . '://' . $host . $uri;
		$payload = hash( 'sha256', '' );
		$headers = $this->signed_headers( 'GET', $host, $uri, '', $payload );

		$out = fopen( $dest_path, 'wb' );
		if ( $out === false ) {
			return new WP_Error( 'isx_s3_open', __( 'เปิดไฟล์ปลายทางเพื่อเขียนไม่สำเร็จ', 'insightx-backup' ) );
		}

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_FILE, $out );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 0 );

		$ok     = curl_exec( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error  = curl_error( $ch );
		curl_close( $ch );
		fclose( $out );

		if ( $ok === false || $error !== '' ) {
			@unlink( $dest_path );
			return new WP_Error( 'isx_s3_transport', $error !== '' ? $error : __( 'การเชื่อมต่อล้มเหลว', 'insightx-backup' ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			$body = is_readable( $dest_path ) ? file_get_contents( $dest_path ) : '';
			@unlink( $dest_path );
			return new WP_Error(
				'isx_s3_http_' . $status,
				sprintf( __( 'เซิร์ฟเวอร์ตอบกลับ HTTP %1$d: %2$s', 'insightx-backup' ), (int) $status, self::extract_error_message( $body ) )
			);
		}
		return true;
	}

	/**
	 * List objects under a prefix (ListObjectsV2).
	 *
	 * @param string $prefix
	 * @return array|WP_Error
	 */
	public function list_objects( $prefix = '' ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'isx_s3_no_curl', __( 'ต้องมีส่วนขยาย PHP cURL เพื่อเข้าถึง S3', 'insightx-backup' ) );
		}

		$host  = $this->path_style ? $this->host : $this->bucket . '.' . $this->host;
		$path  = $this->path_style ? '/' . $this->bucket : '/';
		$uri   = self::encode_path( $path );
		$query = 'list-type=2&prefix=' . rawurlencode( $prefix );
		$url   = $this->scheme . '://' . $host . $uri . '?' . $query;

		$payload = hash( 'sha256', '' );
		$headers = $this->signed_headers( 'GET', $host, $uri, $query, $payload );

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );

		$body   = curl_exec( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error  = curl_error( $ch );
		curl_close( $ch );

		if ( $body === false || $error !== '' ) {
			return new WP_Error( 'isx_s3_transport', $error !== '' ? $error : __( 'การเชื่อมต่อล้มเหลว', 'insightx-backup' ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'isx_s3_http_' . $status,
				sprintf( __( 'เซิร์ฟเวอร์ตอบกลับ HTTP %1$d: %2$s', 'insightx-backup' ), (int) $status, self::extract_error_message( $body ) )
			);
		}

		$objects = array();
		if ( preg_match_all( '#<Contents>(.*?)</Contents>#is', (string) $body, $matches ) ) {
			foreach ( $matches[1] as $chunk ) {
				$key = self::xml_value( $chunk, 'Key' );
				if ( $key === '' ) {
					continue;
				}
				$objects[] = array(
					'key'           => $key,
					'size'          => (int) self::xml_value( $chunk, 'Size' ),
					'last_modified' => self::xml_value( $chunk, 'LastModified' ),
				);
			}
		}
		return $objects;
	}

	/**
	 * Cheap credential/connectivity check — a ListObjectsV2 capped at one key,
	 * so a wrong bucket/key/secret/endpoint surfaces as a real HTTP error
	 * instead of the settings screen just trusting that the fields are
	 * non-empty and calling that "connected".
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'isx_s3_no_curl', __( 'ต้องมีส่วนขยาย PHP cURL เพื่อเชื่อมต่อ S3', 'insightx-backup' ) );
		}

		$host  = $this->path_style ? $this->host : $this->bucket . '.' . $this->host;
		$path  = $this->path_style ? '/' . $this->bucket : '/';
		$uri   = self::encode_path( $path );
		$query = 'list-type=2&max-keys=1';
		$url   = $this->scheme . '://' . $host . $uri . '?' . $query;

		$payload = hash( 'sha256', '' );
		$headers = $this->signed_headers( 'GET', $host, $uri, $query, $payload );

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );

		$body   = curl_exec( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error  = curl_error( $ch );
		curl_close( $ch );

		if ( $body === false || $error !== '' ) {
			return new WP_Error( 'isx_s3_transport', $error !== '' ? $error : __( 'การเชื่อมต่อล้มเหลว', 'insightx-backup' ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'isx_s3_http_' . $status,
				sprintf( __( 'เซิร์ฟเวอร์ตอบกลับ HTTP %1$d: %2$s', 'insightx-backup' ), (int) $status, self::extract_error_message( $body ) )
			);
		}
		return true;
	}

	private function signed_headers( $method, $host, $uri, $canonical_query, $payload_hash ) {
		$now  = gmdate( 'Ymd\THis\Z' );
		$date = gmdate( 'Ymd' );

		$canonical_headers = 'host:' . $host . "\n"
			. 'x-amz-content-sha256:' . $payload_hash . "\n"
			. 'x-amz-date:' . $now . "\n";
		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request = $method . "\n" . $uri . "\n" . $canonical_query . "\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $payload_hash;

		$scope          = $date . '/' . $this->region . '/s3/aws4_request';
		$string_to_sign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$authorization = 'AWS4-HMAC-SHA256 '
			. 'Credential=' . $this->access_key . '/' . $scope . ', '
			. 'SignedHeaders=' . $signed_headers . ', '
			. 'Signature=' . $signature;

		return array(
			'Host: ' . $host,
			'x-amz-date: ' . $now,
			'x-amz-content-sha256: ' . $payload_hash,
			'Authorization: ' . $authorization,
		);
	}

	private static function xml_value( $xml, $tag ) {
		if ( preg_match( '#<' . preg_quote( $tag, '#' ) . '>(.*?)</' . preg_quote( $tag, '#' ) . '>#is', $xml, $m ) ) {
			return trim( html_entity_decode( $m[1] ) );
		}
		return '';
	}

	private static function encode_path( $path ) {
		$parts = explode( '/', $path );
		$parts = array_map( 'rawurlencode', $parts );
		return implode( '/', $parts );
	}

	private static function extract_error_message( $body ) {
		if ( is_string( $body ) && preg_match( '#<Message>(.*?)</Message>#is', $body, $m ) ) {
			return trim( html_entity_decode( $m[1] ) );
		}
		$snippet = is_string( $body ) ? trim( wp_strip_all_tags( $body ) ) : '';
		return $snippet !== '' ? mb_substr( $snippet, 0, 200 ) : __( 'ไม่ทราบสาเหตุ', 'insightx-backup' );
	}
}
