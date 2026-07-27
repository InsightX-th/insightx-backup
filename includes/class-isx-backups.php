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
		}
		// This folder is deliberately *not* deny-all (unlike the rest of
		// storage/): url() below hands out a direct link straight to the file
		// here, served by the web server itself instead of streamed through
		// PHP — the only way to make a large download immune to PHP-FPM's
		// request_terminate_timeout / a host's memory limit / a proxy's edge
		// timeout, all of which killed downloads that went through
		// admin-ajax.php on a slow connection. All-in-One WP Migration protects
		// its own backups the same way (AddType + no directory listing, not
		// Deny), which is where this came from. What stands between a backup
		// and anyone who can guess its URL is the filename's random suffix
		// (see store()) — the .htaccess below only stops directory listing.
		$htaccess = $dir . '/.htaccess';
		$expected = self::htaccess_contents();
		if ( ! is_file( $htaccess ) || (string) @file_get_contents( $htaccess ) !== $expected ) { // phpcs:ignore
			file_put_contents( $htaccess, $expected );
		}
		return $dir;
	}

	/**
	 * @return string
	 */
	private static function htaccess_contents() {
		return "<IfModule mod_mime.c>\n"
			. "\tAddType application/octet-stream .wpress\n"
			. "</IfModule>\n"
			. "<IfModule mod_dir.c>\n"
			. "\tDirectoryIndex index.php\n"
			. "</IfModule>\n"
			. "<IfModule mod_autoindex.c>\n"
			. "\tOptions -Indexes\n"
			. "</IfModule>\n";
	}

	/**
	 * Whether dir() sits somewhere the web server can reach directly — only
	 * true for the default location (under this plugin, itself under
	 * wp-content) or a custom path an admin pointed inside ABSPATH. A custom
	 * path outside the web root (e.g. a sibling directory on the same disk)
	 * has no URL at all, so downloads there must keep going through PHP.
	 *
	 * @return bool
	 */
	public static function is_web_reachable() {
		return strpos( untrailingslashit( self::dir() ), untrailingslashit( ABSPATH ) ) === 0;
	}

	/**
	 * URL of dir() itself (no filename), or null when it isn't web-reachable.
	 * Exposed to isx-admin.js (see ISX_Admin::assets()) so the browser can
	 * build a download link for a backup it only knows the name of — right
	 * after an export finishes — without a round trip back to PHP first.
	 *
	 * @return string|null
	 */
	public static function base_url() {
		if ( ! self::is_web_reachable() ) {
			return null;
		}
		$dir = untrailingslashit( self::dir() );

		if ( strpos( $dir, untrailingslashit( WP_CONTENT_DIR ) ) === 0 ) {
			$rel  = substr( $dir, strlen( untrailingslashit( WP_CONTENT_DIR ) ) );
			$base = content_url( str_replace( '\\', '/', $rel ) );
		} else {
			$rel  = substr( $dir, strlen( untrailingslashit( ABSPATH ) ) );
			$base = site_url( str_replace( '\\', '/', $rel ) );
		}

		return trailingslashit( $base );
	}

	/**
	 * Direct, PHP-free download URL for a stored backup, or null when the
	 * storage path isn't under the web root (see is_web_reachable()) and
	 * ajax_download()'s streamed fallback is the only option.
	 *
	 * @param string $name
	 * @return string|null
	 */
	public static function url( $name ) {
		$base = self::base_url();
		return $base === null ? null : $base . rawurlencode( $name );
	}

	/**
	 * Move a finished job archive into the backups directory.
	 *
	 * @param string $job_archive_path
	 * @return string The stored file name.
	 */
	public static function store( $job_archive_path ) {
		// The random part is the only thing standing between a backup and anyone
		// who can guess its URL: nginx never reads the .htaccess we drop next to
		// these files, so on an nginx host the directory may well be readable
		// straight off the web. Host and date are trivially guessable, so the
		// suffix carries all of the entropy — 16 alphanumerics, not 6.
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = ( $host ? $host : 'backup' ) . '-' . gmdate( 'dmY' ) . '-' . wp_generate_password( 16, false, false ) . '.wpress';
		$dest = self::dir() . '/' . $name;
		if ( ! @rename( $job_archive_path, $dest ) ) {
			copy( $job_archive_path, $dest );
		}
		return $name;
	}

	/**
	 * List backups newest-first.
	 *
	 * @return array [ { name, size, size_human, mtime, url } ]
	 */
	public static function all() {
		$dir  = self::dir();
		$list = array();
		foreach ( glob( $dir . '/*.wpress' ) as $path ) {
			$name     = basename( $path );
			$list[] = array(
				'name'       => $name,
				'size'       => filesize( $path ),
				'size_human' => size_format( filesize( $path ), 2 ),
				'mtime'      => filemtime( $path ),
				// null when the storage path is outside the web root — the UI
				// falls back to the streamed admin-ajax.php download for those.
				'url'        => self::url( $name ),
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
