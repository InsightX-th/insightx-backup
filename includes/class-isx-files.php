<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * File enumeration, packing and restore.
 *
 * Content files are stored inside the archive under a "wpcontent/…" namespace so
 * they map cleanly back onto WP_CONTENT_DIR on any target site regardless of its
 * physical wp-content path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Files {

	const NS = 'wpcontent/';

	/**
	 * Content sub-directories included in a package.
	 *
	 * @return array relative-subdir => absolute-path
	 */
	public static function dirs() {
		$content = untrailingslashit( WP_CONTENT_DIR );
		return array(
			'uploads'    => $content . '/uploads',
			'plugins'    => $content . '/plugins',
			'themes'     => $content . '/themes',
			'mu-plugins' => $content . '/mu-plugins',
		);
	}

	/**
	 * Immediate theme sub-directory names that are currently in use (the active
	 * theme, plus its parent when it's a child theme).
	 *
	 * @return array
	 */
	public static function active_theme_dirs() {
		$dirs       = array();
		$stylesheet = get_stylesheet();
		$template   = get_template();
		if ( $stylesheet ) {
			$dirs[] = $stylesheet;
		}
		if ( $template && $template !== $stylesheet ) {
			$dirs[] = $template;
		}
		return $dirs;
	}

	/**
	 * Immediate plugins sub-directory names (or bare filenames for single-file
	 * plugins) that are currently active — site-wide and, on multisite,
	 * network-active.
	 *
	 * @return array
	 */
	public static function active_plugin_entries() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$entries = array();
		foreach ( $active as $plugin_file ) {
			$slash     = strpos( $plugin_file, '/' );
			$entries[] = $slash !== false ? substr( $plugin_file, 0, $slash ) : $plugin_file;
		}
		return array_values( array_unique( $entries ) );
	}

	/**
	 * Anything living directly under wp-content that isn't one of the named
	 * dirs() above — language files, drop-ins (advanced-cache.php,
	 * object-cache.php, db.php…), or custom folders another plugin creates
	 * right at the wp-content root. Included generically (same approach as
	 * All-in-One WP Migration) rather than via a fixed whitelist, so a
	 * package captures the whole wp-content tree, not just these four dirs.
	 *
	 * @return array Absolute paths (files or dirs).
	 */
	private static function other_entries() {
		$content  = untrailingslashit( WP_CONTENT_DIR );
		$known    = array_values( self::dirs() );
		$excluded = self::excluded();
		$entries  = array();

		$names = @scandir( $content ); // phpcs:ignore
		if ( ! is_array( $names ) ) {
			return $entries;
		}

		foreach ( $names as $name ) {
			if ( $name === '.' || $name === '..' ) {
				continue;
			}
			$abs = $content . '/' . $name;
			if ( in_array( $abs, $known, true ) || self::is_excluded( $abs, $excluded ) ) {
				continue;
			}
			$entries[] = $abs;
		}

		return $entries;
	}

	/**
	 * Absolute paths that must never be packed (our own working data; WP's own
	 * transient upgrade scratch space, which core empties out after every
	 * update and has no restore value). Cache is deliberately NOT in this
	 * list — unlike these, a "cache" dir can hold real, restorable output
	 * (e.g. a page-cache plugin), so whether to skip it is the opt-in
	 * "ไม่รวมไฟล์แคช" filter (exclude_cache) instead, same as AI1WM's
	 * default-include-unless-asked behaviour.
	 *
	 * @return array
	 */
	private static function excluded() {
		return array(
			untrailingslashit( ISX_STORAGE_PATH ),
			untrailingslashit( WP_CONTENT_DIR ) . '/upgrade',
		);
	}

	/**
	 * Build a newline-delimited work list: "<abs>\t<archive_rel>".
	 *
	 * @param string $list_file
	 * @param array  $filters {
	 *     @type array $exclude_dirs      dirs() keys to skip entirely, e.g. array('uploads').
	 *     @type array $keep_only_subdirs dirs() key => array of immediate child names to KEEP
	 *                                    (everything else under that dir is skipped). Used for
	 *                                    "exclude inactive themes/plugins".
	 *     @type bool  $exclude_cache     Skip any path segment named "cache" or "cache-*".
	 *     @type array $exclude_paths     Content-relative path prefixes to skip, e.g.
	 *                                    array('uploads/2019', 'plugins/old-plugin').
	 * }
	 * @return int Number of files listed.
	 */
	public static function build_list( $list_file, $filters = array() ) {
		$fh = fopen( $list_file, 'wb' );
		if ( $fh === false ) {
			return 0;
		}

		$exclude_dirs      = isset( $filters['exclude_dirs'] ) ? (array) $filters['exclude_dirs'] : array();
		$keep_only_subdirs = isset( $filters['keep_only_subdirs'] ) ? (array) $filters['keep_only_subdirs'] : array();
		$exclude_cache     = ! empty( $filters['exclude_cache'] );
		$exclude_paths     = isset( $filters['exclude_paths'] ) ? (array) $filters['exclude_paths'] : array();

		$content  = untrailingslashit( WP_CONTENT_DIR );
		$excluded = self::excluded();
		$count    = 0;

		foreach ( self::dirs() as $dir_key => $abs_dir ) {
			if ( in_array( $dir_key, $exclude_dirs, true ) ) {
				continue;
			}
			if ( ! is_dir( $abs_dir ) ) {
				continue;
			}

			$dir_rel = ltrim( str_replace( '\\', '/', substr( $abs_dir, strlen( $content ) ) ), '/' );
			$keep    = isset( $keep_only_subdirs[ $dir_key ] ) ? $keep_only_subdirs[ $dir_key ] : null;

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $abs_dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$abs = $file->getPathname();
				if ( self::is_excluded( $abs, $excluded ) ) {
					continue;
				}

				$rel   = ltrim( str_replace( '\\', '/', substr( $abs, strlen( $content ) ) ), '/' );
				$after = substr( $rel, strlen( $dir_rel ) + 1 );

				if ( $keep !== null ) {
					$slash         = strpos( $after, '/' );
					$first_segment = $slash !== false ? substr( $after, 0, $slash ) : $after;
					if ( ! in_array( $first_segment, $keep, true ) ) {
						continue;
					}
				}

				if ( $exclude_cache && self::has_cache_segment( $after ) ) {
					continue;
				}

				if ( ! empty( $exclude_paths ) && self::path_excluded( $rel, $exclude_paths ) ) {
					continue;
				}

				fwrite( $fh, $abs . "\t" . self::NS . $rel . "\n" );
				$count++;
			}
		}

		// Everything else directly under wp-content (drop-ins, languages/, a
		// custom folder some other plugin created there) — not gated by
		// exclude_dirs/keep_only_subdirs since those options only make sense
		// for the four named dirs above.
		foreach ( self::other_entries() as $abs_entry ) {
			if ( is_dir( $abs_entry ) ) {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $abs_entry, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
				foreach ( $iterator as $file ) {
					if ( ! $file->isFile() ) {
						continue;
					}
					$abs = $file->getPathname();
					if ( self::is_excluded( $abs, $excluded ) ) {
						continue;
					}
					$rel = ltrim( str_replace( '\\', '/', substr( $abs, strlen( $content ) ) ), '/' );
					if ( $exclude_cache && self::has_cache_segment( $rel ) ) {
						continue;
					}
					if ( ! empty( $exclude_paths ) && self::path_excluded( $rel, $exclude_paths ) ) {
						continue;
					}
					fwrite( $fh, $abs . "\t" . self::NS . $rel . "\n" );
					$count++;
				}
			} elseif ( is_file( $abs_entry ) ) {
				$rel = ltrim( str_replace( '\\', '/', substr( $abs_entry, strlen( $content ) ) ), '/' );
				if ( ! empty( $exclude_paths ) && self::path_excluded( $rel, $exclude_paths ) ) {
					continue;
				}
				fwrite( $fh, $abs_entry . "\t" . self::NS . $rel . "\n" );
				$count++;
			}
		}

		fclose( $fh );
		return $count;
	}

	/**
	 * Pack a batch of files listed in $list_file into the archive.
	 *
	 * @param string $archive_path
	 * @param string $list_file
	 * @param int    $byte_offset  Where to resume reading the list.
	 * @param int    $limit        Max files this batch.
	 * @param bool   $compress     Store each file raw-DEFLATE compressed.
	 * @return array { added:int, offset:int, done:bool }
	 */
	public static function pack_batch( $archive_path, $list_file, $byte_offset, $limit, $compress = false ) {
		$fh = fopen( $list_file, 'rb' );
		if ( $fh === false ) {
			return array( 'added' => 0, 'offset' => $byte_offset, 'done' => true );
		}
		fseek( $fh, $byte_offset );

		$added = 0;
		$done  = false;
		while ( $added < $limit ) {
			$line = fgets( $fh );
			if ( $line === false ) {
				$done = true;
				break;
			}
			$line = rtrim( $line, "\r\n" );
			if ( $line === '' ) {
				continue;
			}
			$parts = explode( "\t", $line, 2 );
			if ( count( $parts ) === 2 ) {
				ISX_Archive::add_file( $archive_path, $parts[0], $parts[1], $compress );
				$added++;
			}
		}

		$offset = ftell( $fh );
		fclose( $fh );

		return array( 'added' => $added, 'offset' => $offset, 'done' => $done );
	}

	/**
	 * Delete a bounded batch of the target site's existing wp-content files
	 * (clean-then-restore: the import replaces the whole tree with the
	 * package's contents, so anything already on disk is stale by
	 * definition). Never touches this plugin's own directory, the storage
	 * path (in-progress job + kept local backups live there), or WP's
	 * transient upgrade dir. Each call re-walks the tree and deletes up to
	 * $limit files depth-first (then prunes now-empty dirs), so the import
	 * poller can just call it repeatedly until 'done'.
	 *
	 * @param int $limit Max files to delete this call.
	 * @return array { deleted:int, done:bool }
	 */
	public static function clean_content_batch( $limit ) {
		$content   = untrailingslashit( WP_CONTENT_DIR );
		$protected = array(
			untrailingslashit( ISX_PATH ),
			untrailingslashit( ISX_STORAGE_PATH ),
			$content . '/upgrade',
		);

		$deleted = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $content, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			if ( $deleted >= $limit ) {
				return array( 'deleted' => $deleted, 'done' => false );
			}
			$abs = $entry->getPathname();
			if ( self::is_excluded( $abs, $protected ) ) {
				continue;
			}
			if ( $entry->isDir() && ! $entry->isLink() ) {
				// CHILD_FIRST: children come before their dir, so by the time
				// we see a dir it's empty unless something inside is protected
				// (e.g. plugins/ still holds this plugin) — rmdir just fails
				// silently on non-empty dirs, which is exactly what we want.
				@rmdir( $abs );
				continue;
			}
			if ( @unlink( $abs ) ) {
				$deleted++;
			}
		}

		return array( 'deleted' => $deleted, 'done' => true );
	}

	/**
	 * Stream one archive entry back to disk when it belongs to the content
	 * namespace. Returns true if it handled the entry.
	 *
	 * @param array    $header
	 * @param resource $handle
	 * @return bool
	 */
	public static function restore_stream( $header, $handle ) {
		$path = isset( $header['p'] ) ? $header['p'] : '';
		if ( strpos( $path, self::NS ) !== 0 ) {
			return false;
		}
		$rel  = substr( $path, strlen( self::NS ) );
		$dest = untrailingslashit( WP_CONTENT_DIR ) . '/' . $rel;
		wp_mkdir_p( dirname( $dest ) );

		ISX_Archive::stream_entry_to_file( $handle, $header, $dest );
		return true;
	}

	/**
	 * @param string $abs
	 * @param array  $excluded
	 * @return bool
	 */
	private static function is_excluded( $abs, $excluded ) {
		$abs = str_replace( '\\', '/', $abs );
		foreach ( $excluded as $prefix ) {
			$prefix = str_replace( '\\', '/', $prefix );
			if ( strpos( $abs, $prefix ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any path segment looks like a cache directory ("cache", "cache-busting", …).
	 *
	 * @param string $rel_after_dir
	 * @return bool
	 */
	private static function has_cache_segment( $rel_after_dir ) {
		foreach ( explode( '/', $rel_after_dir ) as $segment ) {
			if ( preg_match( '/^cache(-.*)?$/i', $segment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $rel           Content-relative path, e.g. "uploads/2024/x.jpg".
	 * @param array  $exclude_paths Content-relative prefixes to exclude.
	 * @return bool
	 */
	private static function path_excluded( $rel, $exclude_paths ) {
		foreach ( $exclude_paths as $prefix ) {
			$prefix = trim( (string) $prefix, '/' );
			if ( $prefix === '' ) {
				continue;
			}
			if ( $rel === $prefix || strpos( $rel, $prefix . '/' ) === 0 ) {
				return true;
			}
		}
		return false;
	}
}
