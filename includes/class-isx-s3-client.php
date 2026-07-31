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
	//
	// The threshold is the part size itself: anything big enough to need a second
	// part should take the multipart path, because that's the only path the
	// export step can pause and resume across requests. A single PUT, however
	// small the file, is all-or-nothing within one PHP request.
	//
	// 5MB is S3's minimum part size (every part but the last must reach it), and
	// small parts are what make the upload feel responsive: progress moves once
	// per part, a failed part costs 5MB of re-transfer rather than 20MB, and peak
	// memory stays low.
	const MULTIPART_PART_SIZE = 5 * 1024 * 1024;

	// S3 refuses a multipart upload with more parts than this.
	const MULTIPART_MAX_PARTS = 10000;

	// Marker-following cap for list_multipart_uploads(). A thousand pending
	// uploads is already far past "something went wrong repeatedly", so this
	// only exists to keep one admin request bounded.
	const MULTIPART_LIST_MAX_PAGES = 20;

	/**
	 * @return int Bytes per multipart part; never below S3's 5MB floor.
	 */
	public static function part_size() {
		$size = (int) apply_filters( 'isx_s3_part_size', self::MULTIPART_PART_SIZE );
		return max( self::MULTIPART_PART_SIZE, $size );
	}

	/**
	 * Part size for a file of a known size — the base part size, grown just
	 * enough that the file fits within S3's 10,000-part ceiling.
	 *
	 * At the 5MB default that ceiling lands at 50GB, which a large media library
	 * can exceed; without this the upload would run for hours and only then be
	 * rejected at the last part.
	 *
	 * @param int $total Archive size in bytes.
	 * @return int
	 */
	public static function part_size_for( $total ) {
		$size  = self::part_size();
		$total = max( 0, (int) $total );

		if ( $total > $size * self::MULTIPART_MAX_PARTS ) {
			// Round up to a whole MB so the numbers stay readable in the log.
			$size = (int) ( ceil( $total / self::MULTIPART_MAX_PARTS / 1048576 ) * 1048576 );
		}
		return $size;
	}

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
	 * Resolve an object key into the (host, uri) pair every signed request for
	 * it needs — virtual-hosted vs. path-style is a per-endpoint decision, so a
	 * caller driving a multipart upload across several HTTP requests has to
	 * carry the resolved pair rather than re-derive it each round.
	 *
	 * @param string $key
	 * @return array{key:string,host:string,uri:string}
	 */
	public function resolve_target( $key ) {
		$key  = ltrim( $key, '/' );
		$host = $this->path_style ? $this->host : $this->bucket . '.' . $this->host;
		$path = $this->path_style ? '/' . $this->bucket . '/' . $key : '/' . $key;

		return array(
			'key'  => $key,
			'host' => $host,
			'uri'  => self::encode_path( $path ),
		);
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

		$target = $this->resolve_target( $key );
		$key    = $target['key'];
		$host   = $target['host'];
		$uri    = $target['uri'];
		$size   = filesize( $file_path );

		ISX_Logger::log_info(
			's3',
			'เริ่มอัปโหลดไปยัง Storage',
			array(
				'host' => $host,
				'key'  => $key,
				'size' => $size,
				'mode' => $size > self::part_size() ? 'multipart' : 'single',
			)
		);

		if ( $size > self::part_size() ) {
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

		$part_size   = self::part_size_for( $size );
		$parts       = array();
		$part_number = 0;
		$offset      = 0;

		while ( $offset < $size ) {
			$length = min( $part_size, $size - $offset );
			++$part_number;

			$etag = $this->multipart_upload_part( $host, $uri, $upload_id, $part_number, $file_path, $offset, $length );
			if ( is_wp_error( $etag ) ) {
				$this->multipart_abort( $host, $uri, $upload_id );
				return $etag;
			}
			$parts[] = array(
				'number' => $part_number,
				'etag'   => $etag,
			);
			$offset += $length;
		}

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
	 * Public because ISX_Export drives a multipart upload one part per HTTP
	 * request (see ISX_Export::upload()) rather than looping to completion here.
	 *
	 * @return string|WP_Error UploadId
	 */
	public function multipart_initiate( $host, $uri, $content_type = 'application/octet-stream' ) {
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
	 * Upload one part, streamed straight off disk.
	 *
	 * Takes a file/offset/length rather than the bytes themselves: handing cURL
	 * a string via CURLOPT_POSTFIELDS means the part exists twice in memory (our
	 * copy plus cURL's), which is what used to push peak memory to ~3x the part
	 * size. A read callback bounded by $length keeps it at one small buffer.
	 *
	 * @param string $file_path
	 * @param int    $offset Byte offset of this part within the file.
	 * @param int    $length Bytes to send; must be S3's 5MB minimum unless last.
	 * @return string|WP_Error ETag of the uploaded part
	 */
	public function multipart_upload_part( $host, $uri, $upload_id, $part_number, $file_path, $offset, $length ) {
		$handle = fopen( $file_path, 'rb' );
		if ( $handle === false ) {
			return new WP_Error( 'isx_s3_open', __( 'เปิดไฟล์เพื่ออัปโหลดไม่สำเร็จ', 'insightx-backup' ) );
		}
		if ( fseek( $handle, $offset ) === -1 ) {
			fclose( $handle );
			return new WP_Error( 'isx_s3_seek', __( 'เลื่อนตำแหน่งไฟล์เพื่ออัปโหลดไม่สำเร็จ', 'insightx-backup' ) );
		}

		$query   = 'partNumber=' . (int) $part_number . '&uploadId=' . rawurlencode( $upload_id );
		$url     = $this->scheme . '://' . $host . $uri . '?' . $query;
		$payload = 'UNSIGNED-PAYLOAD';
		$headers = $this->signed_headers( 'PUT', $host, $uri, $query, $payload );
		$headers[] = 'Content-Length: ' . (int) $length;
		$headers[] = 'Expect:';

		// Stop at exactly $length even though the handle can read past it —
		// every part but the last ends mid-file.
		$remaining = (int) $length;

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_UPLOAD, true );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
		curl_setopt( $ch, CURLOPT_INFILE, $handle );
		curl_setopt( $ch, CURLOPT_INFILESIZE, (int) $length );
		curl_setopt(
			$ch,
			CURLOPT_READFUNCTION,
			function ( $curl_handle, $file_handle, $want ) use ( &$remaining ) {
				if ( $remaining <= 0 ) {
					return '';
				}
				$chunk = fread( $file_handle, (int) min( $want, $remaining ) );
				if ( $chunk === false || $chunk === '' ) {
					return '';
				}
				$remaining -= strlen( $chunk );
				return $chunk;
			}
		);
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
				'part_size' => (int) $length,
				'offset'    => (int) $offset,
				'upload_id' => $upload_id,
			)
		);
		fclose( $handle );

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
	 * @param array $parts Ordered list of {number, etag}.
	 * @return true|WP_Error
	 */
	public function multipart_complete( $host, $uri, $upload_id, array $parts ) {
		$query = 'uploadId=' . rawurlencode( $upload_id );
		$url   = $this->scheme . '://' . $host . $uri . '?' . $query;

		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $part ) {
			// htmlspecialchars() rather than esc_xml(): that helper only exists
			// from WordPress 5.5, and calling it on an older install would fatal
			// on every multipart upload — i.e. every package over 5MB. An ETag
			// is a hex digest anyway, so this is belt-and-braces either way.
			$xml .= '<Part><PartNumber>' . (int) $part['number'] . '</PartNumber><ETag>"' . htmlspecialchars( (string) $part['etag'], ENT_QUOTES ) . '"</ETag></Part>';
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

		// CompleteMultipartUpload is the one S3 call where 200 does not mean
		// success. Assembling the parts can take minutes, so S3 sends the
		// response headers immediately and drips whitespace to hold the
		// connection open — by the time it knows the outcome the status line is
		// long gone, and a failure arrives as an <Error> document under HTTP
		// 200. Trusting the status alone reports an upload as finished when the
		// object was never created, which is the worst possible lie for a
		// backup tool to tell.
		if ( stripos( $res['body'], '<Error' ) !== false ) {
			$code = self::xml_value( $res['body'], 'Code' );
			return new WP_Error(
				'isx_s3_complete_failed',
				sprintf(
					/* translators: 1: S3 error code, 2: message */
					__( 'รวมไฟล์ที่อัปโหลดไม่สำเร็จ (%1$s): %2$s', 'insightx-backup' ),
					$code !== '' ? $code : 'unknown',
					self::extract_error_message( $res['body'] )
				)
			);
		}

		// A genuine success always names the object it created. An empty body
		// under 200 means the connection was cut mid-assembly (a proxy timing
		// out on those keep-alive spaces is the usual cause) — the upload may
		// or may not have landed, and "may have" is not good enough to report
		// as done.
		if ( stripos( $res['body'], '<CompleteMultipartUploadResult' ) === false ) {
			return new WP_Error(
				'isx_s3_complete_incomplete',
				__( 'เซิร์ฟเวอร์ตอบกลับไม่ครบระหว่างรวมไฟล์ที่อัปโหลด — ตรวจสอบว่าไฟล์ขึ้นถึง Storage จริงหรือไม่ก่อนลองใหม่', 'insightx-backup' )
			);
		}

		return true;
	}

	/**
	 * Cleanup so a failed multipart upload doesn't leave orphaned parts billing
	 * against the bucket. The result is returned for callers that clean up on
	 * the user's behalf and must report what happened (the cleanup screen);
	 * the in-pipeline callers ignore it on purpose, since they already have a
	 * real error of their own to report.
	 *
	 * @return true|WP_Error
	 */
	public function multipart_abort( $host, $uri, $upload_id ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'isx_s3_no_curl', __( 'ต้องมีส่วนขยาย PHP cURL เพื่อเข้าถึง S3', 'insightx-backup' ) );
		}

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

		$res = $this->exec_curl(
			$ch,
			array(
				'op'        => 'multipart_abort',
				'host'      => $host,
				'upload_id' => $upload_id,
			)
		);

		// 404 NoSuchUpload is the goal state, not a failure: the upload is gone
		// because it was already aborted, or because it completed and became a
		// real object. Reporting that as an error is how a finished export ends
		// up showing "HTTP 404: The specified multipart upload does not exist"
		// next to a backup that is sitting there perfectly fine.
		if ( ! $res['ok'] && $res['status'] !== 404 ) {
			return self::curl_error_to_wp( $res );
		}
		return true;
	}

	/**
	 * List multipart uploads that were started but never completed or aborted
	 * (ListMultipartUploads). These hold uploaded parts in the bucket yet never
	 * become objects, so they are invisible to ListObjectsV2 and cannot be
	 * removed through a provider's object browser — only AbortMultipartUpload
	 * clears them, which is what the cleanup screen uses this listing to do.
	 *
	 * @param string $prefix Confine the listing to one folder.
	 * @return array|WP_Error List of {key, upload_id, initiated}.
	 */
	public function list_multipart_uploads( $prefix = '' ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'isx_s3_no_curl', __( 'ต้องมีส่วนขยาย PHP cURL เพื่อเข้าถึง S3', 'insightx-backup' ) );
		}

		$host = $this->path_style ? $this->host : $this->bucket . '.' . $this->host;
		$path = $this->path_style ? '/' . $this->bucket : '/';
		$uri  = self::encode_path( $path );

		$uploads    = array();
		$key_marker = '';
		$id_marker  = '';

		// A bucket shared by several sites can hold more pending uploads than
		// one response returns, so follow the markers — but under a hard cap,
		// since a provider that keeps reporting IsTruncated without advancing
		// the markers would otherwise spin this request forever.
		for ( $page = 0; $page < self::MULTIPART_LIST_MAX_PAGES; $page++ ) {
			// signed_headers() signs the query string verbatim, so the
			// parameters have to arrive already sorted by name the way SigV4's
			// canonical query demands: key-marker, prefix, upload-id-marker,
			// uploads.
			$params = array();
			if ( $key_marker !== '' ) {
				$params[] = 'key-marker=' . rawurlencode( $key_marker );
			}
			$params[] = 'prefix=' . rawurlencode( $prefix );
			if ( $id_marker !== '' ) {
				$params[] = 'upload-id-marker=' . rawurlencode( $id_marker );
			}
			$params[] = 'uploads=';

			$query   = implode( '&', $params );
			$url     = $this->scheme . '://' . $host . $uri . '?' . $query;
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
					'op'     => 'list_multipart_uploads',
					'host'   => $host,
					'prefix' => $prefix,
				)
			);

			if ( ! $res['ok'] ) {
				return self::curl_error_to_wp( $res );
			}

			if ( preg_match_all( '#<Upload>(.*?)</Upload>#is', $res['body'], $matches ) ) {
				foreach ( $matches[1] as $chunk ) {
					$key       = self::xml_value( $chunk, 'Key' );
					$upload_id = self::xml_value( $chunk, 'UploadId' );
					if ( $key === '' || $upload_id === '' ) {
						continue;
					}
					$uploads[] = array(
						'key'       => $key,
						'upload_id' => $upload_id,
						'initiated' => self::xml_value( $chunk, 'Initiated' ),
					);
				}
			}

			if ( strtolower( self::xml_value( $res['body'], 'IsTruncated' ) ) !== 'true' ) {
				break;
			}

			$next_key = self::xml_value( $res['body'], 'NextKeyMarker' );
			$next_id  = self::xml_value( $res['body'], 'NextUploadIdMarker' );
			if ( $next_key === '' || ( $next_key === $key_marker && $next_id === $id_marker ) ) {
				break; // No usable cursor — stop rather than re-request the same page.
			}
			$key_marker = $next_key;
			$id_marker  = $next_id;
		}

		return $uploads;
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
		// Checked, not assumed: this handle is where a multi-GB package lands,
		// so a local disk filling up mid-download shows up here as a failed
		// flush and nowhere else.
		$closed = fclose( $out );

		if ( ! $res['ok'] ) {
			// The body landed in the destination file rather than in memory, so
			// recover it from there before deleting the partial download.
			if ( $res['status'] !== 0 && is_readable( $dest_path ) ) {
				$res['body'] = (string) file_get_contents( $dest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			@unlink( $dest_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return self::curl_error_to_wp( $res );
		}

		// A short download is the failure this method exists to not hand
		// onwards: whatever it writes here is about to be treated as a package
		// to restore a site from. cURL catches most truncation itself
		// (CURLE_PARTIAL_FILE), but not a body cut exactly at a chunk boundary
		// by a proxy, and not a local write that came up short — so compare
		// against what the server said it was sending.
		clearstatcache( true, $dest_path );
		$written  = (int) @filesize( $dest_path );
		$expected = isset( $res['headers']['content-length'] ) ? (int) $res['headers']['content-length'] : -1;

		if ( ! $closed || ( $expected >= 0 && $written !== $expected ) ) {
			@unlink( $dest_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error(
				'isx_s3_short_download',
				sprintf(
					/* translators: 1: bytes received, 2: bytes expected */
					__( 'ดาวน์โหลดไม่ครบ (ได้ %1$s จาก %2$s) — อาจเน็ตหลุดกลางทางหรือพื้นที่ดิสก์ไม่พอ', 'insightx-backup' ),
					size_format( $written ),
					$expected >= 0 ? size_format( $expected ) : '?'
				)
			);
		}

		return true;
	}

	/**
	 * List every object under a prefix (ListObjectsV2), following continuation
	 * tokens until the bucket says it's done.
	 *
	 * The pagination is not optional detail: S3 caps a single response at 1000
	 * keys and reports the cut with IsTruncated, so the previous single-request
	 * version silently returned a partial list on any bucket past that mark.
	 * That was survivable while this only fed a read-only listing screen, but
	 * retention deletes based on this list — and pruning "everything past the
	 * newest N" of a truncated list is how you delete the wrong backups.
	 *
	 * @param string $prefix
	 * @return array|WP_Error
	 */
	public function list_objects( $prefix = '' ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'isx_s3_no_curl', __( 'ต้องมีส่วนขยาย PHP cURL เพื่อเข้าถึง S3', 'insightx-backup' ) );
		}

		$objects = array();
		$token   = '';
		// A bucket shared with something else could have a lot under this
		// prefix; cap the walk so a misconfiguration can't spin forever.
		$max_pages = 100;

		for ( $page = 0; $page < $max_pages; $page++ ) {
			$host = $this->path_style ? $this->host : $this->bucket . '.' . $this->host;
			$path = $this->path_style ? '/' . $this->bucket : '/';
			$uri  = self::encode_path( $path );

			// SigV4 signs the canonical query string, which must be sorted by
			// key — "continuation-token" sorts before "list-type" and "prefix".
			$params = array( 'list-type' => '2', 'prefix' => $prefix );
			if ( $token !== '' ) {
				$params['continuation-token'] = $token;
			}
			ksort( $params );
			$pairs = array();
			foreach ( $params as $k => $v ) {
				$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
			}
			$query = implode( '&', $pairs );
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
					'page'   => $page + 1,
				)
			);

			if ( ! $res['ok'] ) {
				return self::curl_error_to_wp( $res );
			}

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

			if ( strtolower( self::xml_value( $res['body'], 'IsTruncated' ) ) !== 'true' ) {
				break;
			}
			$token = self::xml_value( $res['body'], 'NextContinuationToken' );
			if ( $token === '' ) {
				break; // Truncated but no token to follow — nothing sane left to do.
			}
		}

		return $objects;
	}

	/**
	 * Delete a single object (DeleteObject).
	 *
	 * @param string $key Object key, prefix included.
	 * @return true|WP_Error
	 */
	public function delete_object( $key ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'isx_s3_no_curl', __( 'ต้องมีส่วนขยาย PHP cURL เพื่อเข้าถึง S3', 'insightx-backup' ) );
		}

		$target = $this->resolve_target( $key );

		$payload = hash( 'sha256', '' );
		$headers = $this->signed_headers( 'DELETE', $target['host'], $target['uri'], '', $payload );

		$ch = curl_init( $this->scheme . '://' . $target['host'] . $target['uri'] );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );

		$res = $this->exec_curl(
			$ch,
			array(
				'op'   => 'delete_object',
				'host' => $target['host'],
				'key'  => $key,
			)
		);

		// S3 answers DeleteObject with 204, and treats deleting something that
		// isn't there as success — so does this, since the caller's goal ("that
		// key is gone") is met either way.
		if ( ! $res['ok'] && $res['status'] !== 404 ) {
			return self::curl_error_to_wp( $res );
		}
		return true;
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
