<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Import pipeline. Extracts a .wpress package back onto this site and rewrites the
 * database (URLs / paths / table prefix) with a serialized-safe search & replace.
 * Runs in bounded slices driven by AJAX polling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Import {

	const ENTRIES_PER_BATCH  = 40;
	const DB_LINES_PER_BATCH = 300;
	const CLEAN_PER_BATCH    = 1000;

	/**
	 * Advance the import by one step.
	 *
	 * @param ISX_Job $job
	 * @return array
	 */
	public static function run( ISX_Job $job ) {
		$step = $job->get( 'step', 'init' );

		switch ( $step ) {
			case 'init':
				return self::init( $job );
			case 'clean':
				return self::clean( $job );
			case 'extract':
				return self::extract( $job );
			case 'database':
				return self::database( $job );
			case 'finalize':
				return self::finalize( $job );
		}

		return array( 'progress' => 100, 'done' => true, 'message' => 'เสร็จสิ้น' );
	}

	private static function init( ISX_Job $job ) {
		// An encrypted package can't be validated/extracted until the user
		// supplies the password (via isx_import_decrypt); pause here and let
		// the JS poller prompt for it, then resume by re-entering init().
		if ( ! $job->get( 'decrypted' ) && ISX_Crypto::is_encrypted_file( $job->archive() ) ) {
			return array(
				'progress'       => 0,
				'done'           => false,
				'needs_password' => true,
				'message'        => 'ไฟล์นี้เข้ารหัสด้วยรหัสผ่าน กรุณากรอกรหัสผ่าน',
			);
		}

		// A gzip-compressed package (export-time "Compression Options") is
		// decompressed in one pass into a plain archive before extraction starts.
		if ( ! $job->get( 'decompressed' ) && ISX_Compress::is_gzip_file( $job->archive() ) ) {
			$tmp    = $job->archive() . '.gunz';
			$result = ISX_Compress::gunzip_file( $job->archive(), $tmp );
			if ( is_wp_error( $result ) ) {
				@unlink( $tmp );
				$job->cleanup();
				return array( 'progress' => 0, 'done' => true, 'error' => true, 'message' => $result->get_error_message() );
			}
			@unlink( $job->archive() );
			rename( $tmp, $job->archive() );
			$job->set( 'decompressed', true );
			$job->save();
		}

		if ( ! ISX_Archive::is_valid( $job->archive() ) ) {
			$job->cleanup();
			return array( 'progress' => 0, 'done' => true, 'error' => true, 'message' => 'ไฟล์แพ็กเกจไม่ถูกต้อง (.wpress)' );
		}

		// Capture this (target) site's values for the search & replace.
		global $wpdb;
		$job->set(
			'target',
			array(
				'siteurl'      => untrailingslashit( get_option( 'siteurl' ) ),
				'home'         => untrailingslashit( get_option( 'home' ) ),
				'abspath'      => untrailingslashit( ABSPATH ),
				'content_dir'  => untrailingslashit( WP_CONTENT_DIR ),
				'content_url'  => untrailingslashit( content_url() ),
				'table_prefix' => $wpdb->prefix,
			)
		);

		// Clean-then-restore: the package fully replaces this site, so wipe
		// the existing wp-content tree (minus this plugin + its storage)
		// before extracting — otherwise plugins/themes/uploads the old site
		// had but the package doesn't would silently survive the import.
		// This only ever runs AFTER the archive validated above, so a corrupt
		// upload can never wipe a site it then fails to restore.
		$job->set( 'step', 'clean' );
		$job->set( 'progress', 3 );
		$job->save();

		return array( 'progress' => 3, 'done' => false, 'message' => 'ตรวจสอบแพ็กเกจ...' );
	}

	private static function clean( ISX_Job $job ) {
		$result = ISX_Files::clean_content_batch( self::CLEAN_PER_BATCH );

		$cleaned = (int) $job->get( 'cleaned_files', 0 ) + $result['deleted'];
		$job->set( 'cleaned_files', $cleaned );

		if ( $result['done'] ) {
			$job->set( 'cursor', array( 'offset' => ISX_Archive::first_offset() ) );
			$job->set( 'step', 'extract' );
			$job->set( 'progress', 6 );
			$job->save();
			return array( 'progress' => 6, 'done' => false, 'message' => 'ล้างไฟล์เดิมแล้ว เริ่มกู้คืนไฟล์...' );
		}

		$job->set( 'progress', 4 );
		$job->save();

		return array( 'progress' => 4, 'done' => false, 'message' => sprintf( 'ลบไฟล์เดิม (%d ไฟล์)...', $cleaned ) );
	}

	private static function extract( ISX_Job $job ) {
		$cursor  = (array) $job->get( 'cursor', array( 'offset' => ISX_Archive::first_offset() ) );
		$db_dump = $job->db_dump();

		$manifest_ref = array( 'data' => $job->get( 'manifest', array() ) );

		$result = ISX_Archive::read_batch(
			$job->archive(),
			(int) $cursor['offset'],
			self::ENTRIES_PER_BATCH,
			function ( $header, $handle ) use ( $db_dump, &$manifest_ref ) {
				$path = isset( $header['p'] ) ? $header['p'] : '';

				// "package.json"/"database.sql" are current; "manifest.json"/
				// "database.isxdb" are what packages exported before this
				// format change used — keep reading both so old backups still
				// import fine.
				if ( $path === 'package.json' || $path === 'manifest.json' ) {
					$manifest_ref['data'] = json_decode( ISX_Archive::read_entry_string( $handle, $header ), true );
					return;
				}
				if ( $path === 'database.sql' || $path === 'database.isxdb' ) {
					ISX_Archive::stream_entry_to_file( $handle, $header, $db_dump );
					return;
				}
				// Content files.
				ISX_Files::restore_stream( $header, $handle );
			}
		);

		$cursor['offset'] = $result['offset'];
		$job->set( 'cursor', $cursor );
		$job->set( 'manifest', $manifest_ref['data'] );

		$done_entries = (int) $job->get( 'done_entries', 0 ) + self::ENTRIES_PER_BATCH;
		$job->set( 'done_entries', $done_entries );

		// Prefer a real files-done/total ratio (total_files is in manifest.json,
		// always the archive's first entry, so it's known from the first batch
		// on for any package exported after this field was added) — falls back
		// to the old fixed-cap guess for older packages that don't have it, so
		// this doesn't hard-depend on re-exporting everything.
		$total_files = isset( $manifest_ref['data']['total_files'] ) ? (int) $manifest_ref['data']['total_files'] : 0;
		if ( $total_files > 0 ) {
			$progress = 6 + (int) ( 54 * min( 1, $done_entries / ( $total_files + 2 ) ) );
		} else {
			$progress = min( 60, 6 + (int) ( $done_entries / 20 ) );
		}
		if ( $result['done'] ) {
			$job->set( 'cursor', array( 'db_offset' => 0 ) );
			$job->set( 'step', 'database' );
			$progress = 62;
		}
		$job->set( 'progress', $progress );
		$job->save();

		return array( 'progress' => $progress, 'done' => false, 'message' => 'กู้คืนไฟล์...' );
	}

	private static function database( ISX_Job $job ) {
		if ( ! is_file( $job->db_dump() ) ) {
			$job->set( 'step', 'finalize' );
			$job->save();
			return array( 'progress' => 95, 'done' => false, 'message' => 'เตรียมปิดงาน...' );
		}

		list( $search, $replace, $old_prefix, $new_prefix ) = self::replacements( $job );
		$manifest    = (array) $job->get( 'manifest', array() );
		$skip_emails = ! empty( $manifest['no_replace_email_domain'] );

		$cursor = (array) $job->get( 'cursor', array( 'db_offset' => 0 ) );
		$fh     = fopen( $job->db_dump(), 'rb' );
		fseek( $fh, (int) $cursor['db_offset'] );

		// {source_prefix}options is what every single WP request needs just to
		// bootstrap (active_plugins, siteurl, template, ...) — if its rows got
		// split across more than one poll the normal way, a request landing in
		// between would find the table only half-rebuilt, fail to see this
		// plugin in active_plugins, never load it at all, and the import would
		// be permanently stuck (no wp_ajax_isx_run left to poll). All-in-One WP
		// Migration hits the exact same problem and solves it the same way —
		// see their set_atomic_tables() call for wp_options. Every other table
		// still cuts at the normal per-request line cap.
		$options_table = $old_prefix . 'options';

		$imported_tables = (array) $job->get( 'imported_tables', array() );

		$processed = 0;
		$done      = false;
		while ( true ) {
			$pos  = ftell( $fh );
			$line = fgets( $fh );
			if ( $line === false ) {
				$done = true;
				break;
			}

			$is_options = ISX_Database::line_table( $line ) === $options_table;
			if ( ! $is_options && $processed >= self::DB_LINES_PER_BATCH ) {
				fseek( $fh, $pos );
				break;
			}

			// Record which (target-prefixed) tables the package rebuilds, so
			// finalize() can drop leftover same-prefix tables it didn't.
			$created = ISX_Database::created_table( $line, $old_prefix, $new_prefix );
			if ( $created !== null && ! in_array( $created, $imported_tables, true ) ) {
				$imported_tables[] = $created;
			}

			ISX_Database::import_line( $line, $search, $replace, $old_prefix, $new_prefix, $skip_emails );
			$processed++;
		}
		$cursor['db_offset'] = ftell( $fh );
		fclose( $fh );

		$job->set( 'imported_tables', $imported_tables );
		$job->set( 'cursor', $cursor );

		$done_lines = (int) $job->get( 'done_lines', 0 ) + $processed;
		$job->set( 'done_lines', $done_lines );

		$progress = min( 94, 62 + (int) ( $done_lines / 100 ) );
		if ( $done ) {
			$job->set( 'step', 'finalize' );
			$progress = 95;
		}
		$job->set( 'progress', $progress );
		$job->save();

		return array( 'progress' => $progress, 'done' => false, 'message' => sprintf( 'นำเข้าฐานข้อมูล (%d แถว)...', $done_lines ) );
	}

	private static function finalize( ISX_Job $job ) {
		// Clean-then-restore, database half: drop same-prefix tables the
		// package didn't rebuild (stale tables from the previous site).
		// Deliberately only here — after the ENTIRE dump has been applied —
		// never before/during import, because WP must be able to bootstrap
		// off a consistent table set between polls. Skipped entirely when
		// the package carried no database dump ("ไม่รวมฐานข้อมูล" export
		// option): imported_tables being empty then means "the import didn't
		// touch the DB", not "drop everything".
		$imported_tables = (array) $job->get( 'imported_tables', array() );
		if ( ! empty( $imported_tables ) ) {
			ISX_Database::drop_extra_tables( $imported_tables );
		}

		// Flush rewrite rules on next load; clear caches.
		delete_option( 'rewrite_rules' );
		wp_cache_flush();
		self::purge_content_cache();

		// Safety net on top of database()'s atomic-options handling: if this
		// plugin's own entry still didn't survive the active_plugins rewrite
		// for some unforeseen reason, put it back before finishing — otherwise
		// the next request wouldn't load this plugin at all and the import
		// would look finished here but be unrecoverable from the UI.
		$active = (array) get_option( 'active_plugins', array() );
		$self   = plugin_basename( ISX_FILE );
		if ( ! in_array( $self, $active, true ) ) {
			$active[] = $self;
			update_option( 'active_plugins', $active );
		}

		// The package has been fully extracted onto the site; the job's scratch
		// files (copied archive, extracted DB dump) can go. finish() keeps a
		// "done" marker on disk so a duplicate poll racing this one (browser
		// tab vs. WP-Cron, see with_lock()) reports success instead of
		// "ไม่พบงาน" for a directory that no longer exists.
		$job->finish( 'นำเข้าเสร็จสิ้น — โปรดล็อกอินใหม่' );

		return array(
			'progress' => 100,
			'done'     => true,
			'message'  => 'นำเข้าเสร็จสิ้น — โปรดล็อกอินใหม่',
		);
	}

	/**
	 * Empty wp-content/cache/ after the DB rewrite. Restored packages can carry
	 * a minifier/page-cache plugin's static output (Autoptimize CSS/JS, etc.)
	 * byte-verbatim — ISX_Serialize::replace() only rewrites serialized DB
	 * values, never these on-disk files — so without this they'd keep serving
	 * asset URLs baked for the old domain until the cache plugin happens to
	 * regenerate them on its own. Every cache plugin already tolerates a
	 * missing/empty cache dir and rebuilds on the next request, so this is
	 * safe to do unconditionally instead of guessing which plugin is active.
	 *
	 * @return void
	 */
	private static function purge_content_cache() {
		$dir = untrailingslashit( WP_CONTENT_DIR ) . '/cache';
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/**
	 * Build the ordered search/replace arrays from source (manifest) → target.
	 *
	 * @param ISX_Job $job
	 * @return array [ search[], replace[], old_prefix, new_prefix ]
	 */
	private static function replacements( ISX_Job $job ) {
		$src = (array) $job->get( 'manifest', array() );
		$dst = (array) $job->get( 'target', array() );

		$pairs = array();
		// Order matters: most-specific (longest, nested) first.
		self::add_pair( $pairs, isset( $src['content_url'] ) ? $src['content_url'] : '', isset( $dst['content_url'] ) ? $dst['content_url'] : '' );
		self::add_pair( $pairs, isset( $src['siteurl'] ) ? $src['siteurl'] : '', isset( $dst['siteurl'] ) ? $dst['siteurl'] : '' );
		self::add_pair( $pairs, isset( $src['home'] ) ? $src['home'] : '', isset( $dst['home'] ) ? $dst['home'] : '' );
		self::add_pair( $pairs, isset( $src['content_dir'] ) ? $src['content_dir'] : '', isset( $dst['content_dir'] ) ? $dst['content_dir'] : '' );
		self::add_pair( $pairs, isset( $src['abspath'] ) ? $src['abspath'] : '', isset( $dst['abspath'] ) ? $dst['abspath'] : '' );

		$search  = array();
		$replace = array();
		foreach ( $pairs as $pair ) {
			$search[]  = $pair[0];
			$replace[] = $pair[1];
		}

		return array(
			$search,
			$replace,
			isset( $src['table_prefix'] ) ? $src['table_prefix'] : '',
			isset( $dst['table_prefix'] ) ? $dst['table_prefix'] : '',
		);
	}

	private static function add_pair( &$pairs, $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;
		if ( $from !== '' && $from !== $to ) {
			$pairs[] = array( $from, $to );
		}
	}
}
