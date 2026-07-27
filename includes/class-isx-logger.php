<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * File-backed log for export/import/backup jobs — every user-visible error
 * message the pipelines already return (invalid package, S3 upload failure,
 * decrypt failure, job-not-found, ...) gets a matching entry here with enough
 * context (job id, type, step) to trace what happened after the fact, without
 * needing server/PHP error log access (most sites are hosted externally where
 * the admin can't get to that).
 *
 * On top of that baseline, a "verbose" mode (isx_verbose_log option) turns on
 * a full diagnostic trace — every pipeline step with its duration, every S3
 * request with cURL timings/errno/response headers, every loopback spawn and
 * arrival, plus browser-side AJAX failures beaconed back from isx-admin.js.
 * That trace is what makes it possible to tell an S3 problem apart from a
 * reverse proxy (Cloudflare) cutting a request that ran too long.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Logger {

	// Errors alone are sparse, so a small ring buffer is plenty. A verbose
	// trace writes tens of entries per job step, and truncating it would throw
	// away exactly the lead-up an admin needs — hence the much larger cap.
	const MAX_ENTRIES         = 300;
	const MAX_ENTRIES_VERBOSE = 3000;

	// Rough per-entry size, used only to decide when the file has grown enough
	// to be worth trimming — generous on purpose, since overshooting the cap
	// for a while costs nothing but trimming too eagerly costs a rewrite.
	const BYTES_PER_ENTRY = 600;

	const VERBOSE_OPTION = 'isx_verbose_log';

	/** Identifies all entries written by the same PHP request. */
	private static $request_id = null;

	/** Cached so a verbose run doesn't hit the options table on every entry. */
	private static $verbose = null;

	public static function log_error( $type, $message, $context = array() ) {
		self::write( 'error', $type, $message, $context );
	}

	public static function log_warn( $type, $message, $context = array() ) {
		self::write( 'warn', $type, $message, $context );
	}

	public static function log_info( $type, $message, $context = array() ) {
		self::write( 'info', $type, $message, $context );
	}

	/**
	 * Diagnostic trace — dropped entirely unless verbose mode is on, so the
	 * normal log stays readable and normal runs pay nothing for it.
	 */
	public static function log_debug( $type, $message, $context = array() ) {
		if ( ! self::is_verbose() ) {
			return;
		}
		self::write( 'debug', $type, $message, $context );
	}

	/**
	 * @param string $level   debug|info|warn|error
	 * @param string $type    export|import|backup|reset|system|s3|client
	 * @param string $message
	 * @param array  $context Extra fields — job id, step, timings, etc.
	 * @return void
	 */
	private static function write( $level, $type, $message, $context = array() ) {
		$entry = array(
			'time'    => time(),
			// Sub-second resolution: with verbose tracing, consecutive entries
			// routinely land inside the same second and the whole point is
			// seeing how long each one took.
			'ts'      => round( microtime( true ), 3 ),
			'level'   => (string) $level,
			'type'    => (string) $type,
			'message' => (string) $message,
			'req'     => self::request_id(),
			'driver'  => self::driver(),
			'mem'     => memory_get_usage( true ),
			'peak'    => memory_get_peak_usage( true ),
			'context' => (array) $context,
		);

		self::ensure_dir();
		$file = self::file();

		// Append rather than rewrite. A verbose run logs every step, every S3
		// request and every poll, and re-reading plus rewriting the whole file
		// each time would add I/O to exactly the operations whose timings are
		// being measured. Trimming to the cap is deferred to whenever the file
		// grows past a rough size ceiling instead of running on every write.
		file_put_contents( $file, wp_json_encode( $entry ) . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$max = self::is_verbose() ? self::MAX_ENTRIES_VERBOSE : self::MAX_ENTRIES;
		if ( (int) @filesize( $file ) > $max * self::BYTES_PER_ENTRY ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::trim( $max );
		}
	}

	/**
	 * Cut the log back to its newest $max entries.
	 *
	 * @param int $max
	 * @return void
	 */
	private static function trim( $max ) {
		$lines = self::read_lines();
		if ( count( $lines ) <= $max ) {
			return;
		}
		$lines = array_slice( $lines, -$max );
		file_put_contents( self::file(), implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public static function is_verbose() {
		if ( self::$verbose === null ) {
			self::$verbose = (bool) get_option( self::VERBOSE_OPTION, false );
		}
		return self::$verbose;
	}

	/**
	 * @param bool $on
	 * @return void
	 */
	public static function set_verbose( $on ) {
		self::$verbose = (bool) $on;
		update_option( self::VERBOSE_OPTION, self::$verbose ? 1 : 0 );
	}

	/**
	 * Short per-request id. Browser polls, the loopback chain and cron ticks
	 * all work the same job concurrently — without this, interleaved entries
	 * in the log can't be attributed to the request that wrote them.
	 *
	 * @return string
	 */
	private static function request_id() {
		if ( self::$request_id === null ) {
			self::$request_id = substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		return self::$request_id;
	}

	/**
	 * Which driver is executing this request — the loopback marker is set by
	 * ISX_Admin::spawn_loopback(), so an entry tagged "loopback" proves the
	 * self-request actually arrived (see ajax_run()).
	 *
	 * @return string browser|loopback|cron|cli|web
	 */
	private static function driver() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( ! empty( $_POST['isx_lb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only tag for the log entry
			return 'loopback';
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'cron';
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return 'browser';
		}
		return 'web';
	}

	/**
	 * Newest entries first.
	 *
	 * @return array[]
	 */
	public static function entries() {
		$entries = array();
		foreach ( array_reverse( self::read_lines() ) as $line ) {
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$entries[] = $decoded;
			}
		}
		return $entries;
	}

	/** @return array type => label, shared between the initial page render and the live-poll endpoint. */
	public static function type_labels() {
		return array(
			'export' => __( 'ส่งออก', 'insightx-backup' ),
			'import' => __( 'นำเข้า', 'insightx-backup' ),
			'backup' => __( 'ข้อมูลสำรอง', 'insightx-backup' ),
			'reset'  => __( 'รีเซ็ต', 'insightx-backup' ),
			'system' => __( 'ระบบ', 'insightx-backup' ),
			's3'     => __( 'Storage', 'insightx-backup' ),
			'client' => __( 'เบราว์เซอร์', 'insightx-backup' ),
		);
	}

	/** @return array level => hex color. */
	public static function level_colors() {
		return array(
			'error' => '#ff6b6b',
			'warn'  => '#ffbd2e',
			'info'  => '#7dd3fc',
			'debug' => '#94a3b8',
		);
	}

	/**
	 * Render one entry as the same escaped HTML markup the Log screen's
	 * terminal view uses — factored out so the initial page render and the
	 * isx_log_poll AJAX endpoint (real-time tail) can never drift apart.
	 *
	 * @param array $entry
	 * @return string
	 */
	public static function render_line_html( array $entry ) {
		$type_labels  = self::type_labels();
		$level_colors = self::level_colors();

		$type    = isset( $entry['type'] ) ? $entry['type'] : 'system';
		$level   = isset( $entry['level'] ) ? $entry['level'] : 'error';
		$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

		$detail = array();
		foreach ( $context as $key => $val ) {
			if ( $val === '' || $val === null ) {
				continue;
			}
			$detail[] = $key . '=' . ( is_scalar( $val ) ? $val : wp_json_encode( $val ) );
		}

		$time  = isset( $entry['time'] ) ? date_i18n( 'Y-m-d H:i:s', (int) $entry['time'] ) : '';
		$label = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : $type;
		$color = isset( $level_colors[ $level ] ) ? $level_colors[ $level ] : '#94a3b8';

		$line = sprintf(
			'[%s] <span style="color:%s;font-weight:600;">%-5s</span> <span class="isx-terminal-tag">%s</span> %s',
			esc_html( $time ),
			esc_attr( $color ),
			esc_html( strtoupper( $level ) ),
			esc_html( strtoupper( $type ) ),
			esc_html( '(' . $label . ') ' . ( isset( $entry['message'] ) ? $entry['message'] : '' ) )
		);

		if ( $detail ) {
			$line .= "\n    " . '<span class="isx-terminal-ctx">' . esc_html( implode( '  ', $detail ) ) . '</span>';
		}

		$meta = array();
		if ( ! empty( $entry['req'] ) ) {
			$meta[] = 'req=' . $entry['req'];
		}
		if ( ! empty( $entry['driver'] ) ) {
			$meta[] = 'driver=' . $entry['driver'];
		}
		if ( $meta ) {
			$line .= "\n    " . '<span class="isx-terminal-ctx" style="opacity:.6;">' . esc_html( implode( '  ', $meta ) ) . '</span>';
		}

		return $line . "\n";
	}

	/**
	 * Total number of log lines currently on disk — a monotonically
	 * increasing cursor the Log screen polls against: "give me everything
	 * past line N" never needs the client to know entry identities, only a
	 * count.
	 *
	 * @return int
	 */
	public static function total_count() {
		return count( self::read_lines() );
	}

	/**
	 * Entries written after cursor $since, oldest-first (the order new lines
	 * should be appended to the terminal in), capped at $limit per call so a
	 * verbose run that logged thousands of lines between two polls can't blow
	 * up a single AJAX response.
	 *
	 * @param int $since
	 * @param int $limit
	 * @return array { entries: array[], cursor: int }
	 */
	public static function entries_since( $since, $limit = 300 ) {
		$lines = self::read_lines();
		$total = count( $lines );
		$since = max( 0, min( $since, $total ) );

		$slice = array_slice( $lines, $since, $limit );

		$entries = array();
		foreach ( $slice as $line ) {
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$entries[] = $decoded;
			}
		}

		return array(
			'entries' => $entries,
			// Not $total: if $limit truncated the slice, the next poll must
			// resume right after what was actually returned, or the lines in
			// between would be skipped forever.
			'cursor'  => $since + count( $slice ),
		);
	}

	/**
	 * The whole log as plain text, one entry per line — what the Log screen's
	 * download button hands over so a full trace can be sent on for support
	 * instead of copied out of a <pre>.
	 *
	 * @return string
	 */
	public static function as_text() {
		$out = array();
		foreach ( self::entries() as $entry ) {
			$time  = isset( $entry['time'] ) ? date_i18n( 'Y-m-d H:i:s', (int) $entry['time'] ) : '';
			$level = isset( $entry['level'] ) ? strtoupper( $entry['level'] ) : 'ERROR';
			$parts = array();
			if ( isset( $entry['context'] ) && is_array( $entry['context'] ) ) {
				foreach ( $entry['context'] as $key => $val ) {
					if ( $val === '' || $val === null ) {
						continue;
					}
					$parts[] = $key . '=' . ( is_scalar( $val ) ? $val : wp_json_encode( $val ) );
				}
			}
			$out[] = sprintf(
				'[%s] %-5s %-8s req=%s driver=%s %s%s',
				$time,
				$level,
				isset( $entry['type'] ) ? $entry['type'] : '',
				isset( $entry['req'] ) ? $entry['req'] : '-',
				isset( $entry['driver'] ) ? $entry['driver'] : '-',
				isset( $entry['message'] ) ? $entry['message'] : '',
				$parts ? ' | ' . implode( ' ', $parts ) : ''
			);
		}
		return implode( "\n", $out ) . "\n";
	}

	public static function clear() {
		if ( is_file( self::file() ) ) {
			@unlink( self::file() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	private static function file() {
		return ISX_STORAGE_PATH . '/logs/isx-error.log';
	}

	private static function ensure_dir() {
		$dir = dirname( self::file() );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! is_file( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$index = $dir . '/index.php';
		if ( ! is_file( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	private static function read_lines() {
		$file = self::file();
		if ( ! is_file( $file ) ) {
			return array();
		}
		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $content === false || trim( $content ) === '' ) {
			return array();
		}
		return array_values( array_filter( explode( "\n", trim( $content ) ) ) );
	}
}
