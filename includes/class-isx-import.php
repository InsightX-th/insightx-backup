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
	const DB_LINES_PER_BATCH = 2000;
	const CLEAN_PER_BATCH    = 1000;

	/**
	 * Soft wall-clock budget (seconds) for a single run() call — mirrors
	 * ISX_Export::STEP_TIME_BUDGET. The extract and database steps keep looping
	 * batches until it elapses instead of returning after one small batch,
	 * collapsing the number of HTTP round-trips a large import needs.
	 *
	 * @var int
	 */
	const STEP_TIME_BUDGET = 10;

	/**
	 * Microtime deadline for the current run() call's work loop.
	 *
	 * @return float
	 */
	private static function deadline() {
		return microtime( true ) + (float) apply_filters( 'isx_step_time_budget', self::STEP_TIME_BUDGET );
	}

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
			case 'verify':
				return self::verify( $job );
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
			// Overwrite in one step where the OS allows it, so a failed move can
			// never leave the job with neither the .gz nor the plain archive.
			if ( ! @rename( $tmp, $job->archive() ) ) {
				@unlink( $job->archive() );
				if ( ! @rename( $tmp, $job->archive() ) ) {
					@unlink( $tmp );
					$job->cleanup();
					return array(
						'progress' => 0,
						'done'     => true,
						'error'    => true,
						'message'  => 'คลายบีบอัดสำเร็จแต่บันทึกไฟล์ไม่ได้ — พื้นที่ดิสก์ของเซิร์ฟเวอร์อาจเต็ม',
					);
				}
			}
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

		// Verify before clean(), never the other way round. clean() deletes the
		// existing wp-content tree, and it cannot be undone — so every check
		// that can reject a package has to happen while the old site is still
		// standing. is_valid() above compares 8 magic bytes, which a package
		// truncated at 30% passes; verify() walks the whole thing.
		$job->set( 'step', 'verify' );
		$job->set( 'cursor', array( 'vo' => ISX_Archive::first_offset(), 'entries' => 0 ) );
		$job->set( 'progress', 1 );
		$job->save();

		return array( 'progress' => 1, 'done' => false, 'message' => 'ตรวจสอบแพ็กเกจ...' );
	}

	/**
	 * Confirm the package is complete and readable end to end, before anything
	 * on this site is touched.
	 *
	 * The failure this prevents is the expensive one: a package that is short
	 * (an upload that hit a full disk, a download from Storage that dropped
	 * mid-transfer, a file copied between machines incompletely) used to be
	 * accepted on its magic bytes, after which clean() wiped wp-content and
	 * extract() then discovered the package ran out — leaving a site with
	 * neither its old contents nor the new ones. Now a bad package costs
	 * nothing but the time spent reading it.
	 *
	 * Resumable, like every other step: seeks past entry content rather than
	 * reading it, so even a 6GB package is a handful of quick requests.
	 */
	private static function verify( ISX_Job $job ) {
		$cursor = (array) $job->get( 'cursor', array( 'vo' => ISX_Archive::first_offset(), 'entries' => 0 ) );

		$result = ISX_Archive::verify_batch( $job->archive(), (int) $cursor['vo'], self::deadline() );

		$entries          = (int) $cursor['entries'] + (int) $result['entries'];
		$cursor['vo']     = $result['offset'];
		$cursor['entries'] = $entries;

		if ( empty( $result['ok'] ) ) {
			$message = 'แพ็กเกจไม่สมบูรณ์ จึงยกเลิกการนำเข้าก่อนเริ่ม — ' . $result['error'] . ' (เว็บไซต์ปัจจุบันยังอยู่ครบ ไม่ถูกแก้ไข)';
			ISX_Logger::log_error(
				'import',
				$message,
				array(
					'job'     => $job->id(),
					'entries' => $entries,
					'offset'  => (int) $result['offset'],
					'size'    => @filesize( $job->archive() ),
				)
			);
			$job->finish( $message, true );
			return array( 'progress' => 100, 'done' => true, 'error' => true, 'message' => $message );
		}

		if ( empty( $result['done'] ) ) {
			// 1% → 3% of the overall bar, weighted by how much of the file has
			// been walked, so a big package still shows movement here.
			$size     = (int) @filesize( $job->archive() );
			$progress = $size > 0 ? 1 + 2 * min( 1, (int) $result['offset'] / $size ) : 1;
			$job->set( 'cursor', $cursor );
			$job->set( 'progress', $progress );
			$job->save();

			return array(
				'progress' => $progress,
				'done'     => false,
				'message'  => sprintf( 'ตรวจสอบแพ็กเกจ — %d รายการ', $entries ),
			);
		}

		ISX_Logger::log_info(
			'import',
			sprintf( 'ตรวจสอบแพ็กเกจผ่าน (%d รายการ)', $entries ),
			array( 'job' => $job->id() )
		);

		$job->set( 'step', 'clean' );
		$job->set( 'progress', 3 );
		$job->save();

		return array( 'progress' => 3, 'done' => false, 'message' => 'ตรวจสอบแพ็กเกจผ่านแล้ว เริ่มล้างไฟล์เดิม...' );
	}

	private static function clean( ISX_Job $job ) {
		$list = $job->clean_list();

		// Built once on first entry into this step, then consumed with a byte
		// cursor across however many polls it takes.
		if ( ! is_file( $list ) ) {
			// Captured now, while active_plugins/template still describe THIS
			// site — finalize() sweeps exactly these, long after the database
			// import has replaced those options with the package's values.
			$deferred = ISX_Files::deferred_roots();
			$job->set( 'deferred_roots', $deferred );

			$clean_result = ISX_Files::build_clean_list( $list, $deferred );
			if ( empty( $clean_result['ok'] ) ) {
				// Nothing has been deleted yet at this point, so the site is
				// still intact — but the list this whole step walks is short,
				// and acting on it would delete a subset and call it clean.
				@unlink( $list );
				return self::restore_write_failed( $job );
			}
			$total = $clean_result['total'];
			$job->set( 'clean_total', $total );
			$job->set( 'clean_offset', 0 );
			$job->set( 'cleaned_files', 0 );
			$job->save();
		}

		$result = ISX_Files::clean_from_list(
			$list,
			(int) $job->get( 'clean_offset', 0 ),
			self::deadline(),
			self::CLEAN_PER_BATCH
		);

		$cleaned = (int) $job->get( 'cleaned_files', 0 ) + $result['deleted'];
		$job->set( 'cleaned_files', $cleaned );
		$job->set( 'clean_offset', $result['offset'] );

		if ( $result['done'] ) {
			ISX_Files::prune_empty_dirs( (array) $job->get( 'deferred_roots', array() ) );
			$job->set( 'cursor', array( 'offset' => ISX_Archive::first_offset() ) );
			$job->set( 'step', 'extract' );
			$job->set( 'progress', 6 );
			$job->save();
			return array( 'progress' => 6, 'done' => false, 'message' => 'ล้างไฟล์เดิมแล้ว เริ่มกู้คืนไฟล์...' );
		}

		// 3% → 6% of the overall bar, weighted by how much of the list is done,
		// so the step reports real movement instead of sitting on a constant.
		$total    = max( 1, (int) $job->get( 'clean_total', 0 ) );
		$progress = 3 + 3 * min( 1, $cleaned / $total );
		$job->set( 'progress', $progress );
		$job->save();

		return array(
			'progress' => $progress,
			'done'     => false,
			'message'  => sprintf( 'ลบไฟล์เดิม (%d/%d ไฟล์)...', $cleaned, $total ),
		);
	}

	private static function extract( ISX_Job $job ) {
		$cursor  = (array) $job->get( 'cursor', array( 'offset' => ISX_Archive::first_offset() ) );
		$db_dump = $job->db_dump();

		$manifest_ref = array( 'data' => $job->get( 'manifest', array() ) );

		$callback = function ( $header, $handle ) use ( $db_dump, &$manifest_ref ) {
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
				return ISX_Archive::stream_entry_to_file( $handle, $header, $db_dump );
			}
			// Content files.
			return ISX_Files::restore_stream( $header, $handle );
		};

		$done_entries = (int) $job->get( 'done_entries', 0 );
		$deadline     = self::deadline();
		$phase_done   = false;

		// Keep restoring entry batches until the time budget is spent (or the
		// whole archive is extracted), instead of one small batch per request.
		do {
			$result           = ISX_Archive::read_batch( $job->archive(), (int) $cursor['offset'], self::ENTRIES_PER_BATCH, $callback );
			$cursor['offset'] = $result['offset'];
			if ( empty( $result['ok'] ) ) {
				// The site is already mid-restore at this point — wp-content was
				// cleaned in the previous step — so this is not a "try again
				// later" failure the way an export one is. Say so plainly rather
				// than leaving a half-restored site with an ambiguous message.
				$job->set( 'cursor', $cursor );
				$job->set( 'done_entries', $done_entries );
				$job->save();
				return self::restore_write_failed( $job );
			}
			$done_entries += self::ENTRIES_PER_BATCH;
			if ( $result['done'] ) {
				$phase_done = true;
				break;
			}
		} while ( microtime( true ) < $deadline && ! $job->is_cancel_requested() );

		$job->set( 'cursor', $cursor );
		$job->set( 'manifest', $manifest_ref['data'] );
		$job->set( 'done_entries', $done_entries );

		// Prefer a real files-done/total ratio (total_files is in manifest.json,
		// always the archive's first entry, so it's known from the first batch
		// on for any package exported after this field was added) — falls back
		// to the old fixed-cap guess for older packages that don't have it, so
		// this doesn't hard-depend on re-exporting everything.
		$total_files = isset( $manifest_ref['data']['total_files'] ) ? (int) $manifest_ref['data']['total_files'] : 0;
		if ( $total_files > 0 ) {
			$progress = 6 + 54 * min( 1, $done_entries / ( $total_files + 2 ) );
		} else {
			$progress = min( 60, 6 + $done_entries / 20 );
		}
		if ( $phase_done ) {
			$job->set( 'cursor', array( 'db_offset' => 0 ) );
			$job->set( 'step', 'database' );
			$progress = 62;
		}
		$job->set( 'progress', $progress );
		$job->save();

		return array( 'progress' => $progress, 'done' => false, 'message' => 'กู้คืนไฟล์...' );
	}

	/**
	 * A file failed to write while restoring the package onto the site —
	 * checked via ISX_Archive::read_batch()'s 'ok' flag, which is false when
	 * ISX_Files::restore_stream() or the database.sql copy in extract()'s
	 * callback returned false. Same class of bug as the export side
	 * (ISX_Export::write_failed()): PHP's fwrite() doesn't throw when a disk
	 * is full, so this used to go completely unnoticed and report
	 * "นำเข้าเสร็จสิ้น" over a site missing whatever failed to write.
	 *
	 * Unlike an export, clean() has already deleted the old wp-content by the
	 * time this can fire — there is no "your old site is still fine" fallback
	 * to point at, so the message says so plainly instead of suggesting a
	 * simple retry.
	 *
	 * @return array Step result.
	 */
	private static function restore_write_failed( ISX_Job $job ) {
		$message = 'กู้คืนไฟล์ล้มเหลว (พื้นที่ดิสก์ของเซิร์ฟเวอร์อาจเต็ม หรือไฟล์ .wpress เสียหาย) — เว็บอยู่ในสถานะกู้คืนไม่สมบูรณ์ กรุณาตรวจสอบพื้นที่ว่างแล้วนำเข้าซ้ำ';
		$job->finish( $message, true );

		ISX_Logger::log_error(
			'import',
			$message,
			array(
				'job'        => $job->id(),
				'step'       => (string) $job->get( 'step', '' ),
				'free_space' => @disk_free_space( WP_CONTENT_DIR ),
			)
		);

		return array(
			'progress' => 100,
			'done'     => true,
			'error'    => true,
			'message'  => $message,
		);
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

		$deadline  = self::deadline();
		$processed = 0;
		$done      = false;
		while ( true ) {
			$pos  = ftell( $fh );
			$line = fgets( $fh );
			if ( $line === false ) {
				$done = true;
				break;
			}

			// Stop on the line cap, the wall-clock budget, or a cancel request —
			// but never mid-options (see the atomic-options note above): the file
			// must be left at a table boundary the next poll can safely resume
			// from, and a cancel is no reason to leave wp_options half-written.
			$is_options = ISX_Database::line_table( $line ) === $options_table;
			if ( ! $is_options && ( $processed >= self::DB_LINES_PER_BATCH || microtime( true ) >= $deadline || $job->is_cancel_requested() ) ) {
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

		$progress = min( 94, 62 + $done_lines / 100 );
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

		// Files half of clean-then-restore, deferred half: the clean step left
		// the previously-active theme / plugins / mu-plugins / drop-ins in
		// place so the site could keep booting between polls (see
		// ISX_Files::deferred_roots()). The package is fully extracted by now,
		// so whatever it did not write over is a leftover of the old site and
		// can go.
		$deferred = (array) $job->get( 'deferred_roots', array() );
		$started  = (int) $job->get( 'created', 0 );
		if ( ! empty( $deferred ) && $started > 0 ) {
			$swept = ISX_Files::sweep_deferred( $deferred, $started );
			ISX_Logger::log_debug( 'import', 'ลบไฟล์เก่าที่เหลือค้าง', array( 'job' => $job->id(), 'deleted' => $swept ) );
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
