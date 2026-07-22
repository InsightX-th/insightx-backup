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

	const ENTRIES_PER_BATCH = 40;
	const DB_LINES_PER_BATCH = 300;

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

		$job->set( 'cursor', array( 'offset' => ISX_Archive::first_offset() ) );
		$job->set( 'step', 'extract' );
		$job->set( 'progress', 3 );
		$job->save();

		return array( 'progress' => 3, 'done' => false, 'message' => 'ตรวจสอบแพ็กเกจ...' );
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

				if ( $path === 'manifest.json' ) {
					$json               = fread( $handle, (int) $header['s'] );
					$manifest_ref['data'] = json_decode( $json, true );
					return;
				}
				if ( $path === 'database.isxdb' ) {
					$out       = fopen( $db_dump, 'wb' );
					$remaining = (int) $header['s'];
					if ( $out !== false ) {
						while ( $remaining > 0 ) {
							$buf = fread( $handle, min( 1048576, $remaining ) );
							if ( $buf === false || $buf === '' ) {
								break;
							}
							fwrite( $out, $buf );
							$remaining -= strlen( $buf );
						}
						fclose( $out );
					}
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

		$progress = min( 60, 3 + (int) ( $done_entries / 20 ) );
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

		$processed = 0;
		$done      = false;
		while ( $processed < self::DB_LINES_PER_BATCH ) {
			$line = fgets( $fh );
			if ( $line === false ) {
				$done = true;
				break;
			}
			ISX_Database::import_line( $line, $search, $replace, $old_prefix, $new_prefix, $skip_emails );
			$processed++;
		}
		$cursor['db_offset'] = ftell( $fh );
		fclose( $fh );

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
		// Flush rewrite rules on next load; clear caches.
		delete_option( 'rewrite_rules' );
		wp_cache_flush();

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
