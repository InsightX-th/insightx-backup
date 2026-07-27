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
	 * Run one prepared cURL handle and capture everything worth knowing when it
	 * goes wrong — cURL's numeric errno (28 timeout / 52 empty reply / 56 recv
	 * failure / 35 TLS / 7 refused all present as the same vague "connection
	 * failed" to the user, but mean completely different things), the response
	 * headers, and the per-phase timings.
	 *
	 * The headers are the part that answers "was it Cloudflare?": a reverse
	 * proxy in front of the endpoint stamps `cf-ray` / `server: cloudflare` on
	 * the response it synthesises, so an error carrying those did NOT come from
	 * the storage backend. The timings say *where* a stalled request died —
	 * DNS, TCP, TLS, or mid-transfer — and `size_upload` says how far it got.
	 *
	 * @param resource|CurlHandle $ch      Configured handle; closed here.
	 * @param array               $context Request description for the log.
	 * @return array {
	 *     @type bool   $ok      Transport succeeded AND status was 2xx.
	 *     @type string $body    Response body ('' when written straight to a file).
	 *     @type int    $status  HTTP status (0 when the transport itself failed).
	 *     @type int    $errno   cURL errno (0 when none).
	 *     @type string $error   cURL error string.
	 *     @type array  $headers Lower-cased response headers.
	 *     @type array  $diag    Flattened timings/context, as logged.
	 * }
	 */
	private function exec_curl( $ch, array $context ) {
		$headers = array();
		curl_setopt(
			$ch,
			CURLOPT_HEADERFUNCTION,
			function ( $handle, $line ) use ( &$headers ) {
				$len   = strlen( $line );
				$parts = explode( ':', $line, 2 );
				if ( count( $parts ) === 2 ) {
					$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}
				return $len;
			}
		);

		$body   = curl_exec( $ch );
		$errno  = curl_errno( $ch );
		$error  = curl_error( $ch );
		$info   = curl_getinfo( $ch );
		$status = isset( $info['http_code'] ) ? (int) $info['http_code'] : 0;
		curl_close( $ch );

		// CURLOPT_FILE writes the body straight to disk and curl_exec() returns
		// a bare bool — normalise so callers always get a string.
		$body_str = is_string( $body ) ? $body : '';
		$failed   = ( $body === false || $errno !== 0 );

		$diag = array_merge(
			$context,
			array(
				'status'    => $status,
				'errno'     => $errno,
				'curl_err'  => $error,
				// Cumulative offsets from the start of the request: the first
				// one that jumps is the phase that hurt.
				't_dns'     => isset( $info['namelookup_time'] ) ? round( $info['namelookup_time'], 3 ) : null,
				't_conn'    => isset( $info['connect_time'] ) ? round( $info['connect_time'], 3 ) : null,
				't_tls'     => isset( $info['appconnect_time'] ) ? round( $info['appconnect_time'], 3 ) : null,
				't_ttfb'    => isset( $info['starttransfer_time'] ) ? round( $info['starttransfer_time'], 3 ) : null,
				't_total'   => isset( $info['total_time'] ) ? round( $info['total_time'], 3 ) : null,
				'sent'      => isset( $info['size_upload'] ) ? (int) $info['size_upload'] : null,
				'recv'      => isset( $info['size_download'] ) ? (int) $info['size_download'] : null,
				'up_bps'    => isset( $info['speed_upload'] ) ? (int) $info['speed_upload'] : null,
				'srv'       => isset( $headers['server'] ) ? $headers['server'] : null,
				// Present only when a Cloudflare edge produced the response.
				'cf_ray'    => isset( $headers['cf-ray'] ) ? $headers['cf-ray'] : null,
				'retry_aft' => isset( $headers['retry-after'] ) ? $headers['retry-after'] : null,
				'amz_req'   => isset( $headers['x-amz-request-id'] ) ? $headers['x-amz-request-id'] : null,
			)
		);

		$ok = ( ! $failed && $status >= 200 && $status < 300 );

		if ( $ok ) {
			ISX_Logger::log_debug( 's3', 'S3 request สำเร็จ', $diag );
		} else {
			// S3 answers errors as XML (<Error><Code>…), a proxy answers with an
			// HTML page — the excerpt alone identifies which one replied.
			$diag['body'] = self::excerpt( $body_str );
			ISX_Logger::log_error(
				's3',
				$failed
					? sprintf( 'S3 transport ล้มเหลว (cURL %d: %s)', $errno, $error )
					: sprintf( 'S3 ตอบกลับ HTTP %d', $status ),
				$diag
			);
		}

		return array(
			'ok'      => $ok,
			'body'    => $body_str,
			'status'  => $status,
			'errno'   => $errno,
			'error'   => $error,
			'headers' => $headers,
			'diag'    => $diag,
		);
	}

	/**
	 * Turn a failed exec_curl() result into the WP_Error the callers return.
	 * Keeps the original codes/messages, with cURL's errno appended — that
	 * number is the searchable part, and it never used to reach the user.
	 *
	 * @param array $res
	 * @return WP_Error
	 */
	private static function curl_error_to_wp( array $res ) {
		if ( $res['errno'] !== 0 || $res['status'] === 0 ) {
			$message = $res['error'] !== ''
				? sprintf( __( 'การเชื่อมต่อล้มเหลว (cURL %1$d: %2$s)', 'insightx-backup' ), (int) $res['errno'], $res['error'] )
				: __( 'การเชื่อมต่อล้มเหลว', 'insightx-backup' );
			return new WP_Error( 'isx_s3_transport', $message );
		}

		return new WP_Error(
			'isx_s3_http_' . $res['status'],
			sprintf( __( 'เซิร์ฟเวอร์ตอบกลับ HTTP %1$d: %2$s', 'insightx-backup' ), (int) $res['status'], self::extract_error_message( $res['body'] ) )
		);
	}

	/**
	 * First slice of a response body, whitespace-collapsed — enough to tell an
	 * S3 error document from a proxy's HTML block page without bloating the log.
	 *
	 * @param string $body
	 * @return string
	 */
	private static function excerpt( $body ) {
		$body = trim( preg_replace( '/\s+/', ' ', (string) $body ) );
		if ( $body === '' ) {
			return '';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 500 ) : substr( $body, 0, 500 );
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

		ISX_Logger::log_info(
			's3',
			'เริ่มอัปโหลดไปยัง Storage',
			array(
				'host' => $host,
				'key'  => $key,
				'size' => $size,
				'mode' => $size > self::MULTIPART_THRESHOLD ? 'multipart' : 'single',
			)
		);

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

		$res = $this->exec_curl(
			$ch,
			array(
				'op'   => 'put_object',
				'host' => $host,
				'key'  => $key,
				'size' => $size,
			)
		);
		fclose( $handle );

		if ( ! $res['ok'] ) {
			return self::curl_error_to_wp( $res );
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

		ISX_Logger::log_info(
			's3',
			'อัปโหลด multipart สำเร็จ',
			array(
				'host'  => $host,
				'parts' => count( $parts ),
				'size'  => $size,
			)
		);
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

		$res = $this->exec_curl(
			$ch,
			array(
				'op'   => 'multipart_initiate',
				'host' => $host,
				'uri'  => $uri,
			)
		);

		if ( ! $res['ok'] ) {
			return self::curl_error_to_wp( $res );
		}

		$upload_id = self::xml_value( $res['body'], 'UploadId' );
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
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 0 );

		// Per-part timings are the clearest signal in the whole log: they show
		// whether a big upload degrades gradually or dies at one specific part.
		$res = $this->exec_curl(
			$ch,
			array(
				'op'        => 'multipart_part',
				'host'      => $host,
				'part'      => (int) $part_number,
				'part_size' => strlen( $chunk ),
				'upload_id' => $upload_id,
			)
		);

		if ( ! $res['ok'] ) {
			return self::curl_error_to_wp( $res );
		}

		if ( isset( $res['headers']['etag'] ) ) {
			return trim( $res['headers']['etag'], '"' );
		}
		ISX_Logger::log_error( 's3', 'อัปโหลดส่วนไฟล์สำเร็จแต่ไม่มี ETag ในคำตอบ', $res['diag'] );
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

		$res = $this->exec_curl(
			$ch,
			array(
				'op'        => 'multipart_complete',
				'host'      => $host,
				'parts'     => count( $parts ),
				'upload_id' => $upload_id,
			)
		);

		if ( ! $res['ok'] ) {
			return self::curl_error_to_wp( $res );
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

		$this->exec_curl(
			$ch,
			array(
				'op'        => 'multipart_abort',
				'host'      => $host,
				'upload_id' => $upload_id,
			)
		);
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

		$res = $this->exec_curl(
			$ch,
			array(
				'op'   => 'get_object',
				'host' => $host,
				'key'  => $key,
			)
		);
		fclose( $out );

		if ( ! $res['ok'] ) {
			// The body landed in the destination file rather than in memory, so
			// recover it from there before deleting the partial download.
			if ( $res['status'] !== 0 && is_readable( $dest_path ) ) {
				$res['body'] = (string) file_get_contents( $dest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			@unlink( $dest_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return self::curl_error_to_wp( $res );
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

		$res = $this->exec_curl(
			$ch,
			array(
				'op'     => 'list_objects',
				'host'   => $host,
				'prefix' => $prefix,
			)
		);

		if ( ! $res['ok'] ) {
			return self::curl_error_to_wp( $res );
		}

		$objects = array();
		if ( preg_match_all( '#<Contents>(.*?)</Contents>#is', $res['body'], $matches ) ) {
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

		$res = $this->exec_curl(
			$ch,
			array(
				'op'   => 'test_connection',
				'host' => $host,
			)
		);

		if ( ! $res['ok'] ) {
			return self::curl_error_to_wp( $res );
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
