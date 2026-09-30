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
			'plugins'    => $content . '/plugins',
			'themes'     => $content . '/themes',
			'uploads'    => $content . '/uploads',
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
	 * "Do not export cache files" filter (exclude_cache) instead, same as AI1WM's
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
	 * @return array { total:int, bounds: array<string,int>, ok:bool } bounds maps
	 *               each dirs() key (plus 'other' for root-level entries) to the
	 *               cumulative file count once that category's block ends —
	 *               lets the files-packing step report which category the
	 *               current position falls into. `ok` is false when the list
	 *               couldn't be written in full: a truncated list is uniquely
	 *               nasty, because `total` is counted from the loop rather than
	 *               read back from the file, so the export would pack fewer
	 *               files than the site has, still reach 100%, and report
	 *               "Export complete" over a backup with files silently missing.
	 */
	public static function build_list( $list_file, $filters = array() ) {
		$fh = fopen( $list_file, 'wb' );
		if ( $fh === false ) {
			return array( 'total' => 0, 'bounds' => array(), 'ok' => false );
		}

		$exclude_dirs      = isset( $filters['exclude_dirs'] ) ? (array) $filters['exclude_dirs'] : array();
		$keep_only_subdirs = isset( $filters['keep_only_subdirs'] ) ? (array) $filters['keep_only_subdirs'] : array();
		$exclude_cache     = ! empty( $filters['exclude_cache'] );
		$exclude_paths     = isset( $filters['exclude_paths'] ) ? (array) $filters['exclude_paths'] : array();

		$content  = untrailingslashit( WP_CONTENT_DIR );
		$excluded = self::excluded();
		$count    = 0;
		$bounds   = array();
		$ok       = true;

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

				if ( ! self::write_line( $fh, $abs . "\t" . self::NS . $rel . "\n" ) ) {
					$ok = false;
					break 2;
				}
				$count++;
			}

			$bounds[ $dir_key ] = $count;
		}

		// Everything else directly under wp-content (drop-ins, languages/, a
		// custom folder some other plugin created there) — not gated by
		// exclude_dirs/keep_only_subdirs since those options only make sense
		// for the four named dirs above.
		foreach ( $ok ? self::other_entries() : array() as $abs_entry ) {
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
					if ( ! self::write_line( $fh, $abs . "\t" . self::NS . $rel . "\n" ) ) {
						$ok = false;
						break 2;
					}
					$count++;
				}
			} elseif ( is_file( $abs_entry ) ) {
				$rel = ltrim( str_replace( '\\', '/', substr( $abs_entry, strlen( $content ) ) ), '/' );
				if ( ! empty( $exclude_paths ) && self::path_excluded( $rel, $exclude_paths ) ) {
					continue;
				}
				if ( ! self::write_line( $fh, $abs_entry . "\t" . self::NS . $rel . "\n" ) ) {
					$ok = false;
					break;
				}
				$count++;
			}
		}
		$bounds['other'] = $count;

		if ( ! fclose( $fh ) ) {
			$ok = false;
		}
		return array( 'total' => $count, 'bounds' => $bounds, 'ok' => $ok );
	}

	/**
	 * Append one line to a work list, telling the truth about whether it landed.
	 * See build_list()'s @return note for why a short write here can't just be
	 * shrugged off.
	 *
	 * @param resource $fh
	 * @param string   $line
	 * @return bool
	 */
	private static function write_line( $fh, $line ) {
		// Silenced: see ISX_Archive::write_ok() — the caller turns this into a
		// real error, and a PHP notice would corrupt the JSON response it
		// travels back in.
		return @fwrite( $fh, $line ) === strlen( $line );
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
			return array( 'added' => 0, 'offset' => $byte_offset, 'done' => true, 'ok' => true );
		}
		fseek( $fh, $byte_offset );

		$added         = 0;
		$done          = false;
		$ok            = true;
		$before_line_at = $byte_offset;
		while ( $added < $limit ) {
			$before_line_at = ftell( $fh );
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
				$result = ISX_Archive::add_file( $archive_path, $parts[0], $parts[1], $compress );
				if ( $result === false ) {
					// Leave the offset at the start of this line, not past it — a
					// disk-full write is a hard stop, not a file to skip, so the
					// caller must not resume as if this entry were packed.
					$ok = false;
					break;
				}
				// null means the source vanished between build_list() and now —
				// normal churn on a live site (a plugin update, a cache file
				// rotating out) and not a reason to fail the whole export.
				$added++;
			}
		}

		$offset = $ok ? ftell( $fh ) : $before_line_at;
		fclose( $fh );

		return array( 'added' => $added, 'offset' => $offset, 'done' => $done, 'ok' => $ok );
	}

	/**
	 * Paths the clean-then-restore pass must never delete, at any point: our
	 * own plugin directory, the storage path (the in-progress job's own state
	 * and the kept local backups live there), and WP's transient upgrade dir.
	 *
	 * @return array Absolute paths.
	 */
	private static function permanent_protected() {
		return array(
			untrailingslashit( ISX_PATH ),
			untrailingslashit( ISX_STORAGE_PATH ),
			untrailingslashit( WP_CONTENT_DIR ) . '/upgrade',
		);
	}

	/**
	 * Code the *running* site still needs to boot between polls, so it must
	 * survive the clean pass and only be swept once the package has been fully
	 * extracted over the top (see sweep_deferred()).
	 *
	 * This mirrors what ISX_Import::database() already does for the database
	 * half — wp_options is written atomically and stale tables are dropped only
	 * in finalize(), precisely so a request landing between two polls can still
	 * bootstrap. The files half needs the same rule: deleting the active theme,
	 * the active plugins or mu-plugins mid-import kills the very admin-ajax
	 * requests that are supposed to finish the import, leaving the site wiped
	 * half-way with no way to recover from the UI.
	 *
	 * Captured into the job at clean time rather than recomputed later: by the
	 * time finalize() runs, the database import has already replaced
	 * active_plugins/template with the *incoming* site's values, so asking
	 * again would describe the wrong site entirely.
	 *
	 * @return array Absolute paths (dirs or files).
	 */
	public static function deferred_roots() {
		$content = untrailingslashit( WP_CONTENT_DIR );
		$roots   = array( $content . '/mu-plugins' );

		foreach ( self::active_plugin_entries() as $entry ) {
			$roots[] = $content . '/plugins/' . $entry;
		}
		foreach ( self::active_theme_dirs() as $dir ) {
			$roots[] = $content . '/themes/' . $dir;
		}

		// Drop-ins sit directly at the wp-content root and are loaded on every
		// single request; wp-content/index.php too.
		$dropins = array(
			'index.php',
			'object-cache.php',
			'advanced-cache.php',
			'db.php',
			'db-error.php',
			'maintenance.php',
			'php-error.php',
			'fatal-error-handler.php',
		);
		foreach ( $dropins as $dropin ) {
			$roots[] = $content . '/' . $dropin;
		}

		return $roots;
	}

	/**
	 * Build the clean-then-restore work list once, up front: every existing
	 * wp-content file the import is going to replace, one absolute path per
	 * line. The import poller then walks it with a byte cursor.
	 *
	 * Written as a list rather than re-walking the tree on every batch (the
	 * previous behaviour) because that re-walk was O(n) per batch and so O(n²)
	 * overall — on a site with 200k files and a 1000-file batch it meant
	 * walking the whole tree 200 times before the clean step could finish.
	 *
	 * Scope deliberately matches build_list() — dirs() plus other_entries() —
	 * so the clean pass can never delete something an export would not have
	 * packed in the first place.
	 *
	 * @param string $list_file Absolute path to write the list to.
	 * @param array  $deferred  Roots to leave for sweep_deferred(), from deferred_roots().
	 * @return array { total:int, ok:bool } — ok is false when the list couldn't
	 *               be written in full. Acting on a truncated clean list would
	 *               leave old files behind that the package is supposed to
	 *               replace, quietly turning clean-then-restore back into the
	 *               plain overwrite this plugin exists to avoid.
	 */
	public static function build_clean_list( $list_file, array $deferred ) {
		$fh = fopen( $list_file, 'wb' );
		if ( $fh === false ) {
			return array( 'total' => 0, 'ok' => false );
		}

		$skip  = array_merge( self::permanent_protected(), $deferred );
		$roots = array_merge( array_values( self::dirs() ), self::other_entries() );
		$count = 0;
		$ok    = true;

		foreach ( $roots as $root ) {
			if ( is_file( $root ) ) {
				if ( ! self::is_excluded( $root, $skip ) ) {
					if ( ! self::write_line( $fh, $root . "\n" ) ) {
						$ok = false;
						break;
					}
					$count++;
				}
				continue;
			}
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() && ! $file->isLink() ) {
					continue;
				}
				$abs = $file->getPathname();
				if ( self::is_excluded( $abs, $skip ) ) {
					continue;
				}
				if ( ! self::write_line( $fh, $abs . "\n" ) ) {
					$ok = false;
					break 2;
				}
				$count++;
			}
		}

		if ( ! fclose( $fh ) ) {
			$ok = false;
		}
		return array( 'total' => $count, 'ok' => $ok );
	}

	/**
	 * Delete a bounded slice of the list built by build_clean_list(), resuming
	 * from a byte offset and stopping on either the file cap or the wall-clock
	 * deadline (whichever comes first), so a single HTTP request can never run
	 * long enough for a proxy to cut it off.
	 *
	 * @param string $list_file
	 * @param int    $offset   Byte offset to resume from.
	 * @param float  $deadline microtime(true) value to stop at.
	 * @param int    $limit    Max files to delete this call.
	 * @return array { deleted:int, offset:int, done:bool }
	 */
	public static function clean_from_list( $list_file, $offset, $deadline, $limit ) {
		$fh = fopen( $list_file, 'rb' );
		if ( $fh === false ) {
			return array( 'deleted' => 0, 'offset' => $offset, 'done' => true );
		}
		fseek( $fh, (int) $offset );

		$deleted = 0;
		$done    = false;
		while ( true ) {
			if ( $deleted >= $limit || microtime( true ) >= $deadline ) {
				break;
			}
			$line = fgets( $fh );
			if ( $line === false ) {
				$done = true;
				break;
			}
			$path = trim( $line );
			if ( $path === '' ) {
				continue;
			}
			// A path that is already gone still counts as progress — otherwise a
			// re-run over a partially cleaned tree would report zero movement.
			if ( @unlink( $path ) || ! file_exists( $path ) ) { // phpcs:ignore
				$deleted++;
			}
		}

		$offset = ftell( $fh );
		fclose( $fh );

		return array( 'deleted' => $deleted, 'offset' => $offset, 'done' => $done );
	}

	/**
	 * Remove directories the clean pass emptied out. Runs once, after the whole
	 * list has been consumed — rmdir() fails silently on a non-empty dir, which
	 * is exactly the behaviour we want for anything still holding protected or
	 * deferred content.
	 *
	 * @param array $deferred Roots left standing by the clean pass.
	 * @return void
	 */
	public static function prune_empty_dirs( array $deferred ) {
		$skip = array_merge( self::permanent_protected(), $deferred );

		foreach ( array_merge( array_values( self::dirs() ), self::other_entries() ) as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $entry ) {
				if ( ! $entry->isDir() || $entry->isLink() ) {
					continue;
				}
				$abs = $entry->getPathname();
				if ( self::is_excluded( $abs, $skip ) ) {
					continue;
				}
				@rmdir( $abs ); // phpcs:ignore
			}
		}
	}

	/**
	 * Finish the job the clean pass deliberately left undone: delete what is
	 * left of the previously-active theme / plugins / mu-plugins / drop-ins
	 * that the package did NOT restore over.
	 *
	 * "Did not restore over" is decided from the job's restored.list — the
	 * exact set of files the extract step wrote back out of the archive (see
	 * restore_stream() / ISX_Job::restored_list()) — not from mtimes. mtimes
	 * can't tell the two apart: stream_entry_to_file() restores each file's
	 * original mtime from the archive, so a theme that shipped in the package
	 * can look every bit as "old" as a leftover of the previous site, and the
	 * old mtime heuristic deleted freshly-restored themes/plugins exactly when
	 * the package was exported a while ago. Called from ISX_Import::finalize(),
	 * i.e. only once the extract and the database import have both completed
	 * and the site no longer needs the old code to boot.
	 *
	 * @param array  $roots        The deferred roots captured when the clean step ran.
	 * @param string $restored_log Path to the restored.list written by the extract step.
	 * @return int Number of files deleted.
	 */
	public static function sweep_deferred( array $roots, $restored_log ) {
		$protected = self::permanent_protected();
		$restored  = self::read_path_set( $restored_log );
		$deleted   = 0;
		$fresh     = array();

		// Defense in depth on top of the per-container gate: an entirely
		// empty log means the extract never reported what it restored (ran
		// under pre-0.1.21 code, or the file vanished). Sweeping on that
		// would treat every file under the deferred roots — freshly-restored
		// themes included — as a leftover. The container gate alone would
		// already skip everything here (no container has restored entries),
		// but say so explicitly: leave the old files in place, deleted ones
		// are not recoverable.
		if ( empty( $restored ) ) {
			return 0;
		}

		foreach ( $roots as $root ) {
			if ( self::is_excluded( $root, $protected ) ) {
				continue;
			}
			if ( is_file( $root ) ) {
				// A drop-in at the wp-content root (index.php, advanced-cache.php…)
				// — if the package didn't restore it, it's a leftover of the old
				// site (the source never had it, or it was excluded from the
				// export) and the package defines the new site's wp-content.
				if ( ! isset( $restored[ str_replace( '\\', '/', $root ) ] ) && @unlink( $root ) ) { // phpcs:ignore
					$deleted++;
				}
				continue;
			}
			if ( ! is_dir( $root ) ) {
				continue;
			}

			// Decided one level up, at plugins/ or themes/ rather than at the
			// individual plugin or theme: "the package restored no plugins at
			// all" means it was exported with plugins excluded
			// ("Do not export plugins"), and wiping the target's plugins on the
			// strength of an archive that never mentioned them would strip a
			// working site for no reason. But once the package *did* restore
			// plugins, one that got nothing written into it really was dropped
			// by the source site, and should go.
			$container = self::sweep_container( $root );
			if ( ! isset( $fresh[ $container ] ) ) {
				$fresh[ $container ] = self::container_has_restored( $container, $restored );
			}
			if ( ! $fresh[ $container ] ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $entry ) {
				$abs = $entry->getPathname();
				if ( self::is_excluded( $abs, $protected ) ) {
					continue;
				}
				if ( $entry->isDir() && ! $entry->isLink() ) {
					@rmdir( $abs ); // phpcs:ignore
					continue;
				}
				if ( ! isset( $restored[ str_replace( '\\', '/', $abs ) ] ) && @unlink( $abs ) ) { // phpcs:ignore
					$deleted++;
				}
			}
		}

		return $deleted;
	}

	/**
	 * The directory whose "did the package restore anything here?" answer
	 * governs a deferred root: plugins/ or themes/ for an individual plugin or
	 * theme, otherwise the root itself (mu-plugins/ has no such grouping, so it
	 * only ever answers for itself).
	 *
	 * @param string $root
	 * @return string
	 */
	private static function sweep_container( $root ) {
		$parent = dirname( untrailingslashit( str_replace( '\\', '/', $root ) ) );
		return in_array( basename( $parent ), array( 'plugins', 'themes' ), true ) ? $parent : $root;
	}

	/**
	 * Read a newline-delimited list of absolute paths into an associative set
	 * (path => true, separators normalised to '/') for O(1) membership checks.
	 *
	 * @param string $log
	 * @return array<string,bool>
	 */
	private static function read_path_set( $log ) {
		$set = array();
		if ( ! is_file( $log ) ) {
			return $set;
		}
		$fh = @fopen( $log, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $fh === false ) {
			return $set;
		}
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );
			if ( $line !== '' ) {
				$set[ str_replace( '\\', '/', $line ) ] = true;
			}
		}
		fclose( $fh );
		return $set;
	}

	/**
	 * Whether the restored set contains anything under $container — the "did
	 * the package restore anything into plugins/ or themes/ at all?" answer
	 * that gates the per-root sweep (see sweep_deferred()).
	 *
	 * @param string          $container
	 * @param array<string,bool> $restored
	 * @return bool
	 */
	private static function container_has_restored( $container, array $restored ) {
		$prefix = str_replace( '\\', '/', untrailingslashit( $container ) ) . '/';
		foreach ( $restored as $path => $unused ) {
			if ( strpos( $path, $prefix ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Source→target URL map applied to restored TEXT files (CSS/JS/HTML…),
	 * set by ISX_Import before the extract step. The database half of an
	 * import already rewrites URLs; on-disk assets — builder-generated CSS,
	 * theme font references, minifier output that isn't in cache/ — are
	 * plain files the DB rewrite can't reach, and without this they keep
	 * serving the old site's URLs (broken styles/fonts) after an import.
	 *
	 * @var array<string,string>
	 */
	private static $url_replace_map = array();

	/**
	 * @param array<string,string> $map old => new URL strings.
	 * @return void
	 */
	public static function set_url_replace_map( array $map ) {
		self::$url_replace_map = $map;
	}

	/**
	 * Path to the job's restored.list (set by ISX_Import before the extract
	 * step). While set, restore_stream() appends every file it writes back out
	 * of the archive, so finalize()'s sweep_deferred() knows exactly what the
	 * package restored — see sweep_deferred() for why mtimes can't answer that.
	 *
	 * @var string|null
	 */
	private static $restored_log = null;

	/**
	 * @param string $path Absolute path to the restored.list file.
	 * @return void
	 */
	public static function set_restored_log( $path ) {
		self::$restored_log = $path;
	}

	/** Text-file size cap (bytes) for the post-restore URL pass. Anything
	 *  larger is restored untouched: a URL split across a chunk boundary
	 *  would be missed or half-replaced, and the biggest text files are
	 *  almost never worth the risk. Filterable per site. */
	const TEXT_REPLACE_MAX_BYTES = 8388608; // 8 MB

	/**
	 * Whether a content-relative path is a text asset worth rewriting.
	 *
	 * @param string $rel Content-relative path.
	 * @return bool
	 */
	private static function is_text_asset( $rel ) {
		$ext = strtolower( (string) pathinfo( $rel, PATHINFO_EXTENSION ) );
		return in_array(
			$ext,
			array( 'css', 'js', 'mjs', 'json', 'html', 'htm', 'xml', 'txt', 'svg', 'scss', 'sass', 'less', 'webmanifest', 'map' ),
			true
		);
	}

	/**
	 * Stream one archive entry back to disk when it belongs to the content
	 * namespace, then rewrite old-domain URLs inside text assets.
	 *
	 * @param array    $header
	 * @param resource $handle
	 * @return bool|null True once written, false if the write failed (disk
	 *                   full, or the archive ran dry mid-entry), null if this
	 *                   entry isn't ours to restore. The null matters: the
	 *                   import driver treats a literal false as "stop the whole
	 *                   restore", so "not a wp-content entry" must not share
	 *                   that value — same split ISX_Archive::add_file() uses.
	 */
	public static function restore_stream( $header, $handle ) {
		$path = isset( $header['p'] ) ? $header['p'] : '';
		if ( strpos( $path, self::NS ) !== 0 ) {
			return null;
		}
		$rel  = substr( $path, strlen( self::NS ) );
		$dest = untrailingslashit( WP_CONTENT_DIR ) . '/' . $rel;

		// Never overwrite the plugin driving this very import (or its storage
		// dir / WP's upgrade dir) with the package's copy of itself. The
		// package carries this plugin's own files — export packs plugins/ as
		// it finds them — and restoring them mid-extract swaps the running
		// code for whatever version the SOURCE exported (plugins/ come first
		// in the archive, so it happens in the opening polls): from then on
		// every step, including the finalize sweep, runs that version's
		// logic, restored.list logging stops, and an old package silently
		// re-enables the very sweep bug this version fixes. The target keeps
		// its installed version instead, so the whole import runs on one
		// consistent codebase. The clean pass and the sweep already leave
		// these paths alone (permanent_protected()), so skipping them here
		// keeps all three passes in agreement.
		if ( self::is_excluded( $dest, self::permanent_protected() ) ) {
			return null;
		}

		wp_mkdir_p( dirname( $dest ) );

		$ok = ISX_Archive::stream_entry_to_file( $handle, $header, $dest );
		if ( $ok ) {
			self::log_restored( $dest );
		}
		if ( $ok && ! empty( self::$url_replace_map ) && self::is_text_asset( $rel ) ) {
			$size = @filesize( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $size !== false && $size <= (int) apply_filters( 'isx_replace_file_urls_max_bytes', self::TEXT_REPLACE_MAX_BYTES ) ) {
				$content = @file_get_contents( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
				if ( $content === false ) {
					return false; // Just wrote it; unreadable now is a real failure.
				}
				// Skip anything with a NUL byte — almost certainly binary, and
				// running strtr over binary is where corruption sneaks in.
				if ( strpos( $content, "\0" ) === false ) {
					$replaced = strtr( $content, self::$url_replace_map );
					if ( $replaced !== $content && @file_put_contents( $dest, $replaced ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
						return false; // Same class as a failed restore write.
					}
				}
			}
		}
		return $ok;
	}

	/**
	 * Append one restored path to the job's restored.list, if the import set
	 * one (see set_restored_log()). Locked so concurrent poll requests — the
	 * browser and WP-Cron can race the same job — can't interleave two lines
	 * into one.
	 *
	 * @param string $dest
	 * @return void
	 */
	private static function log_restored( $dest ) {
		if ( self::$restored_log === null ) {
			return;
		}
		$fh = @fopen( self::$restored_log, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $fh === false ) {
			return;
		}
		if ( flock( $fh, LOCK_EX ) ) { // phpcs:ignore
			@fwrite( $fh, $dest . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			flock( $fh, LOCK_UN ); // phpcs:ignore
		}
		fclose( $fh );
	}

	/**
	 * @param string $abs
	 * @param array  $excluded
	 * @return bool
	 */
	private static function is_excluded( $abs, $excluded ) {
		$abs = str_replace( '\\', '/', $abs );
		foreach ( $excluded as $prefix ) {
			$prefix = untrailingslashit( str_replace( '\\', '/', $prefix ) );
			// Match the entry itself or something *inside* it — a bare strpos()
			// prefix test would also swallow siblings whose name merely starts
			// the same way ("themes/fruit3" matching "themes/fruit30").
			if ( $abs === $prefix || strpos( $abs, $prefix . '/' ) === 0 ) {
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
