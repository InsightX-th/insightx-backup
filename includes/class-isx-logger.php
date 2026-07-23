<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * File-backed error log for export/import/backup jobs — every user-visible
 * error message the pipelines already return (invalid package, S3 upload
 * failure, decrypt failure, job-not-found, ...) gets a matching entry here
 * with enough context (job id, type, step) to trace what happened after the
 * fact, without needing server/PHP error log access (most sites are hosted
 * externally where the admin can't get to that).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Logger {

	const MAX_ENTRIES = 300;

	/**
	 * @param string $type    export|import|backup|system
	 * @param string $message
	 * @param array  $context Extra fields — job id, step, etc.
	 * @return void
	 */
	public static function log_error( $type, $message, $context = array() ) {
		$entry = array(
			'time'    => time(),
			'type'    => (string) $type,
			'message' => (string) $message,
			'context' => (array) $context,
		);

		$lines   = self::read_lines();
		$lines[] = wp_json_encode( $entry );
		if ( count( $lines ) > self::MAX_ENTRIES ) {
			$lines = array_slice( $lines, -self::MAX_ENTRIES );
		}

		self::ensure_dir();
		file_put_contents( self::file(), implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
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
