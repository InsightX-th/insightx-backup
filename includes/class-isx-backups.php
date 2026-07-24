<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Persistent local backups — finished export archives copied out of their
 * ephemeral job directory into storage/backups/ so they survive job cleanup
 * and can be listed, downloaded, deleted, or re-imported later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Backups {

	/**
	 * @return string
	 */
	public static function dir() {
		$dir = ISX_STORAGE_PATH . '/backups';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		return $dir;
	}

	/**
	 * Move a finished job archive into the backups directory.
	 *
	 * @param string $job_archive_path
	 * @return string The stored file name.
	 */
	public static function store( $job_archive_path ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = ( $host ? $host : 'backup' ) . '-' . gmdate( 'dmY' ) . '-' . substr( wp_generate_password( 8, false ), 0, 6 ) . '.wpress';
		$dest = self::dir() . '/' . $name;
		if ( ! @rename( $job_archive_path, $dest ) ) {
			copy( $job_archive_path, $dest );
		}
		return $name;
	}

	/**
	 * List backups newest-first.
	 *
	 * @return array [ { name, size, size_human, mtime } ]
	 */
	public static function all() {
		$dir  = self::dir();
		$list = array();
		foreach ( glob( $dir . '/*.wpress' ) as $path ) {
			$list[] = array(
				'name'       => basename( $path ),
				'size'       => filesize( $path ),
				'size_human' => size_format( filesize( $path ), 2 ),
				'mtime'      => filemtime( $path ),
			);
		}
		usort(
			$list,
			function ( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);
		return $list;
	}

	/**
	 * @param string $name
	 * @return string|null Absolute path, or null if the name is invalid / missing.
	 */
	public static function path( $name ) {
		$name = self::sanitize_name( $name );
		if ( $name === '' ) {
			return null;
		}
		$path = self::dir() . '/' . $name;
		return is_file( $path ) ? $path : null;
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public static function delete( $name ) {
		$path = self::path( $name );
		if ( $path === null ) {
			return false;
		}
		return @unlink( $path );
	}

	/**
	 * Format a timestamp as a Thai Buddhist-era date, e.g. "24/07/69"
	 * (24 July 2026 → พ.ศ. 2569).
	 *
	 * @param int $timestamp
	 * @return string
	 */
	public static function format_thai_date( $timestamp ) {
		$buddhist_year = ( (int) wp_date( 'Y', $timestamp ) + 543 ) % 100;
		return wp_date( 'd/m/', $timestamp ) . sprintf( '%02d', $buddhist_year );
	}

	/**
	 * @param string $name
	 * @return string
	 */
	public static function sanitize_name( $name ) {
		$name = basename( (string) $name );
		return preg_match( '/^[A-Za-z0-9_.-]+\.wpress$/', $name ) ? $name : '';
	}
}
