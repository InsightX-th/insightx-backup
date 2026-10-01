<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Storage-path helpers needed before the classes load (the storage path is
 * resolved at plugin load). Kept dependency-free so tests/run.php can load it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WordPress directory a storage path would swallow, or '' when safe.
 *
 * The storage dir is excluded from exports, never cleaned or restored over by
 * imports, and gets a deny-all .htaccess. Pointing it at (or above) a core
 * directory therefore turned every theme/plugin/upload URL into a 403 and
 * made exports pack nothing and imports restore nothing — while both still
 * reported success. A dedicated folder anywhere else, including below
 * wp-content, is fine.
 *
 * @param string $path Absolute path.
 * @return string
 */
function isx_storage_path_conflict( $path ) {
	$norm      = untrailingslashit( str_replace( '\\', '/', (string) $path ) );
	if ( $norm === '' || preg_match( '#^[A-Za-z]:$#', $norm ) ) {
		return '/'; // The filesystem root contains everything.
	}
	$real      = realpath( $path );
	$candidate = $real !== false ? untrailingslashit( str_replace( '\\', '/', $real ) ) : $norm;
	$content   = untrailingslashit( WP_CONTENT_DIR );
	$protected = array(
		ABSPATH,
		ABSPATH . 'wp-admin',
		ABSPATH . 'wp-includes',
		$content,
		$content . '/themes',
		$content . '/uploads',
		defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : $content . '/plugins',
		defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : $content . '/mu-plugins',
		ISX_PATH,
	);
	foreach ( $protected as $dir ) {
		$dir = untrailingslashit( str_replace( '\\', '/', $dir ) );
		foreach ( array_unique( array( $norm, $candidate ) ) as $p ) {
			// Equal to, or an ancestor of, a WordPress directory.
			if ( $p !== '' && strpos( $dir . '/', $p . '/' ) === 0 ) {
				return $dir;
			}
		}
	}
	return '';
}
