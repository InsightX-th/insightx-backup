<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Export pipeline. Each run() call performs one bounded slice of work and
 * returns progress so a JS driver can poll it to completion without hitting PHP
 * time / memory limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Export {

	const ROWS_PER_BATCH  = 1000;
	const FILES_PER_BATCH = 300;

	/**
	 * Soft wall-clock budget (seconds) for a single run() call. Each work step
	 * keeps doing batches in a loop until this elapses (or the phase finishes),
	 * instead of returning after one small batch — this is what collapses a
	 * huge export from tens of thousands of HTTP round-trips down to dozens.
	 * Filterable so hosts with tighter PHP time limits can lower it.
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
	 * Advance the export by one step.
	 *
	 * @param ISX_Job $job
	 * @return array { progress:int, done:bool, message:string, download?:string }
	 */
	public static function run( ISX_Job $job ) {
		$step = $job->get( 'step', 'init' );

		switch ( $step ) {
			case 'init':
				return self::init( $job );
			case 'database':
				return self::database( $job );
			case 'pack_meta':
				return self::pack_meta( $job );
			case 'files':
				return self::files( $job );
			case 'finalize':
				return self::finalize( $job );
			case 'upload':
				return self::upload( $job );
		}

		return array( 'progress' => 100, 'done' => true, 'message' => 'เสร็จสิ้น' );
	}

	private static function init( ISX_Job $job ) {
		global $wpdb;

		ISX_Archive::init( $job->archive() );

		$options = (array) $job->get( 'options', array() );

		$manifest = array(
			'generator'               => 'InsightX Backup ' . ISX_VERSION,
			'created'                 => time(),
			'siteurl'                 => get_option( 'siteurl' ),
			'home'                    => get_option( 'home' ),
			'abspath'                 => untrailingslashit( ABSPATH ),
			'content_dir'             => untrailingslashit( WP_CONTENT_DIR ),
			'content_url'             => untrailingslashit( content_url() ),
			'table_prefix'            => $wpdb->prefix,
			'wp_version'              => get_bloginfo( 'version' ),
			'no_replace_email_domain' => ! empty( $options['no_replace_email_domain'] ),
		);

		$tables = empty( $options['exclude_database'] ) ? ISX_Database::tables() : array();
		if ( ! empty( $options['exclude_selected_tables'] ) ) {
			$tables = array_values( array_diff( $tables, (array) $options['exclude_selected_tables'] ) );
		}
		$job->set( 'tables', $tables );

		$exclude_dirs = array();
		if ( ! empty( $options['exclude_media'] ) ) {
			$exclude_dirs[] = 'uploads';
		}
		if ( ! empty( $options['exclude_themes'] ) ) {
			$exclude_dirs[] = 'themes';
		}
		if ( ! empty( $options['exclude_mu_plugins'] ) ) {
			$exclude_dirs[] = 'mu-plugins';
		}
		if ( ! empty( $options['exclude_plugins'] ) ) {
			$exclude_dirs[] = 'plugins';
		}

		$keep_only_subdirs = array();
		if ( empty( $options['exclude_themes'] ) && ! empty( $options['exclude_inactive_themes'] ) ) {
			$keep_only_subdirs['themes'] = ISX_Files::active_theme_dirs();
		}
		if ( empty( $options['exclude_plugins'] ) && ! empty( $options['exclude_inactive_plugins'] ) ) {
			$keep_only_subdirs['plugins'] = ISX_Files::active_plugin_entries();
		}

		$filters = array(
			'exclude_dirs'      => $exclude_dirs,
			'keep_only_subdirs' => $keep_only_subdirs,
			'exclude_cache'     => ! empty( $options['exclude_cache_files'] ),
			'exclude_paths'     => isset( $options['exclude_selected_files'] ) ? (array) $options['exclude_selected_files'] : array(),
		);
		$list_result = ISX_Files::build_list( $job->file_list(), $filters );
		$total_files = $list_result['total'];
		$job->set( 'total_files', $total_files );
		$job->set( 'file_category_bounds', $list_result['bounds'] );

		// Written into manifest.json (inside the archive itself) — not just the
		// job's own state — so the import side can compute a true files-done/
		// total percentage instead of the old fixed-cap guess once it reads this
		// back on the other site.
		$manifest['total_files'] = $total_files;
		$job->set( 'manifest', $manifest );

		$job->set( 'cursor', array( 'ti' => 0, 'off' => 0, 'table_rows' => 0 ) );
		$job->set( 'step', 'database' );
		$job->set( 'progress', 5 );
		$job->save();

		return array( 'progress' => 5, 'done' => false, 'message' => 'เตรียมข้อมูล...' );
	}

	private static function database( ISX_Job $job ) {
		global $wpdb;

		$tables  = (array) $job->get( 'tables', array() );
		$cursor  = (array) $job->get( 'cursor', array( 'ti' => 0, 'off' => 0, 'table_rows' => 0 ) );
		$options = (array) $job->get( 'options', array() );

		$search  = isset( $options['replace_old'] ) ? array_values( (array) $options['replace_old'] ) : array();
		$replace = isset( $options['replace_new'] ) ? array_values( (array) $options['replace_new'] ) : array();

		$deadline = self::deadline();
		$progress = (int) $job->get( 'progress', 5 );
		$message  = '';

		// Keep the dump handle open across the whole budget window instead of
		// reopening it per batch — every batch appends to the same file.
		$fh = fopen( $job->db_dump(), 'ab' );

		do {
			$ti  = (int) $cursor['ti'];
			$off = (int) $cursor['off'];

			if ( $ti >= count( $tables ) ) {
				fclose( $fh );
				$job->set( 'cursor', $cursor );
				$job->set( 'step', 'pack_meta' );
				$job->set( 'progress', 50 );
				$job->save();
				return array( 'progress' => 50, 'done' => false, 'message' => 'แพ็กฐานข้อมูล...' );
			}

			$table = $tables[ $ti ];

			$where = '';
			if ( $table === $wpdb->comments && ! empty( $options['exclude_spam_comments'] ) ) {
				$where = "comment_approved != 'spam'";
			} elseif ( $table === $wpdb->posts && ! empty( $options['exclude_post_revisions'] ) ) {
				$where = "post_type != 'revision'";
			}

			// Captured once per table (at off === 0): the total row count (for
			// progress) and the keyset column, if any. dump_rows() then pages
			// by keyset (WHERE pk > last) when a column is available — avoiding
			// the O(n²) deep-OFFSET scan that made big tables slower each batch.
			if ( $off === 0 ) {
				ISX_Database::dump_schema( $fh, $table );
				$cursor['table_rows'] = ISX_Database::row_count( $table );
				$cursor['keyset']     = ISX_Database::keyset_column( $table );
				$cursor['last_pk']    = null;
			}
			$table_rows = (int) $cursor['table_rows'];
			$keyset     = isset( $cursor['keyset'] ) ? $cursor['keyset'] : null;
			$last_pk    = isset( $cursor['last_pk'] ) ? $cursor['last_pk'] : null;

			$res     = ISX_Database::dump_rows( $fh, $table, $off, self::ROWS_PER_BATCH, $where, $search, $replace, $keyset, $last_pk );
			$written = (int) $res['written'];
			if ( $keyset !== null ) {
				$cursor['last_pk'] = $res['last_pk'];
			}

			if ( $written < self::ROWS_PER_BATCH ) {
				$cursor['ti']         = $ti + 1;
				$cursor['off']        = 0;
				$cursor['table_rows'] = 0;
				$cursor['keyset']     = null;
				$cursor['last_pk']    = null;
			} else {
				$cursor['off'] = $off + $written;
			}

			// Fractional progress within the current table blended into the
			// per-table step, so a single huge table (millions of rows in
			// wp_postmeta) still shows the bar moving instead of sitting still.
			$table_fraction = $table_rows > 0 ? min( 1, ( $off + $written ) / $table_rows ) : 1;
			$progress       = 5 + 45 * ( ( $ti + $table_fraction ) / max( 1, count( $tables ) ) );
			$message        = $table_rows > 0
				? sprintf( 'ส่งออกตาราง %s (%d/%d แถว)...', $table, min( $off + $written, $table_rows ), $table_rows )
				: sprintf( 'ส่งออกตาราง %s...', $table );
		} while ( microtime( true ) < $deadline );

		fclose( $fh );
		$job->set( 'cursor', $cursor );
		$job->set( 'progress', $progress );
		$job->save();

		return array(
			'progress' => $progress,
			'done'     => false,
			'message'  => $message,
		);
	}

	private static function pack_meta( ISX_Job $job ) {
		$options  = (array) $job->get( 'options', array() );
		$compress = ! empty( $options['compression'] ) && $options['compression'] === 'gzip';

		// package.json stays uncompressed — it's a few hundred bytes, and
		// keeping it plain lets a package be sanity-checked by eye/grep
		// without needing this plugin's own inflate step.
		ISX_Archive::add_data( $job->archive(), 'package.json', wp_json_encode( $job->get( 'manifest', array() ) ) );
		if ( is_file( $job->db_dump() ) ) {
			ISX_Archive::add_file( $job->archive(), $job->db_dump(), 'database.sql', $compress );
		}
		$job->set( 'cursor', array( 'fo' => 0 ) );
		$job->set( 'step', 'files' );
		$job->set( 'progress', 55 );
		$job->save();

		return array( 'progress' => 55, 'done' => false, 'message' => 'แพ็กไฟล์...' );
	}

	private static function files( ISX_Job $job ) {
		$options  = (array) $job->get( 'options', array() );
		$compress = ! empty( $options['compression'] ) && $options['compression'] === 'gzip';

		$cursor     = (array) $job->get( 'cursor', array( 'fo' => 0 ) );
		$done_files = (int) $job->get( 'done_files', 0 );
		$total      = max( 1, (int) $job->get( 'total_files', 1 ) );
		$deadline   = self::deadline();
		$phase_done = false;

		// Keep packing file batches into the archive until the time budget is
		// spent (or every file is packed), rather than one small batch per
		// request — the same round-trip collapse the database step gets.
		do {
			$result       = ISX_Files::pack_batch( $job->archive(), $job->file_list(), (int) $cursor['fo'], self::FILES_PER_BATCH, $compress );
			$cursor['fo'] = $result['offset'];
			$done_files  += $result['added'];
			if ( $result['done'] ) {
				$phase_done = true;
				break;
			}
		} while ( microtime( true ) < $deadline );

		$job->set( 'cursor', $cursor );
		$job->set( 'done_files', $done_files );

		$progress = 55 + 40 * min( 1, $done_files / $total );
		if ( $phase_done ) {
			$job->set( 'step', 'finalize' );
		}
		$job->set( 'progress', $progress );
		$job->save();

		return array(
			'progress' => $progress,
			'done'     => false,
			'message'  => sprintf( 'กำลังรวบรวม%s — %d/%d รายการ', self::current_category_label( $job, $done_files ), $done_files, $total ),
		);
	}

	/**
	 * Which content category $done_files (the cumulative files-packed count)
	 * currently falls into, based on the per-category boundaries build_list()
	 * recorded — so the status message can say "รวบรวมปลั๊กอิน" / "รวบรวมธีม" /
	 * etc. instead of just a bare item counter.
	 *
	 * @param ISX_Job $job
	 * @param int     $done_files
	 * @return string
	 */
	private static function current_category_label( ISX_Job $job, $done_files ) {
		$labels = array(
			'plugins'    => 'ปลั๊กอิน',
			'themes'     => 'ธีม',
			'uploads'    => 'คลังสื่อ',
			'mu-plugins' => 'ปลั๊กอิน', // รวมกับ plugins เป็นหมวดเดียวในสายตาผู้ใช้
			'other'      => 'ไฟล์อื่นๆ',
		);
		$bounds = (array) $job->get( 'file_category_bounds', array() );
		foreach ( $bounds as $key => $upto ) {
			if ( $done_files <= $upto ) {
				return isset( $labels[ $key ] ) ? $labels[ $key ] : 'ไฟล์';
			}
		}
		return 'ไฟล์';
	}

	private static function finalize( ISX_Job $job ) {
		ISX_Archive::finish( $job->archive() );

		// Every finished export is kept as a local backup (listed under
		// "ข้อมูลสำรอง"), regardless of whether it's also pushed to S3.
		$backup_name = ISX_Backups::store( $job->archive() );
		$backup_path = ISX_Backups::path( $backup_name );

		$options = (array) $job->get( 'options', array() );

		// Compression now happens per-entry during packing (see files()/
		// pack_meta()) — container-level, like All-in-One WP Migration's own
		// archive format — rather than gzip-wrapping the whole finished file
		// here afterwards. That old whole-file step would just double up on
		// (and undo the readability of) what's already compressed inside.

		if ( $backup_path !== null && ! empty( $options['encrypt'] ) ) {
			$password = ISX_Crypto::decrypt_string( (string) $job->get( 'encrypt_password_enc', '' ) );
			if ( $password !== '' ) {
				$tmp    = $backup_path . '.enc';
				$result = ISX_Crypto::encrypt_file( $password, $backup_path, $tmp );
				if ( ! is_wp_error( $result ) ) {
					@unlink( $backup_path );
					rename( $tmp, $backup_path );
				} else {
					@unlink( $tmp );
				}
			}
		}

		$size = $backup_path !== null ? filesize( $backup_path ) : 0;
		$job->set( 'backup_name', $backup_name );
		$job->set( 'archive_size', $size );

		$provider = $job->get( 'to_storage', '' );
		if ( $provider !== '' ) {
			$job->set( 'step', 'upload' );
			$job->set( 'progress', 97 );
			$job->save();
			// The upload step reports its own "กำลังอัปโหลด..." progress from here
			// on; saying it in finalize's message left the UI showing that text
			// with finalize's frozen 100% bar behind it.
			return array( 'progress' => 97, 'done' => false, 'message' => 'สร้างไฟล์สำรองเสร็จแล้ว' );
		}

		// The finished archive already lives in the backups dir; the job's
		// scratch directory (dump, file list) is no longer needed. finish()
		// keeps a "done" marker on disk so a duplicate poll racing this one
		// (browser tab vs. WP-Cron, see with_lock()) reports success instead
		// of "ไม่พบงาน" for a directory that no longer exists.
		$job->finish( 'ส่งออกเสร็จสิ้น' );

		return array(
			'progress' => 100,
			'done'     => true,
			'message'  => 'ส่งออกเสร็จสิ้น',
			'size'     => size_format( (int) $size ),
			'backup'   => $backup_name,
		);
	}

	/**
	 * Number of times a single part may fail before the whole upload is
	 * abandoned. Each retry costs one round-trip, not a blocking sleep, so the
	 * worker is free in between — a flaky endpoint gets several chances without
	 * a 260MB archive being thrown away over one dropped connection.
	 */
	const UPLOAD_MAX_RETRIES = 5;

	/**
	 * Push the finished archive to the configured Storage provider.
	 *
	 * Unlike every other step this one used to run to completion inside a single
	 * request: one put_object() call that looped through every multipart part
	 * back to back. On a big archive that meant one PHP request holding the job
	 * lock for minutes — long enough for the stall watchdog to declare the job
	 * wedged while it was still uploading, with the progress bar frozen because
	 * nothing reported back until it was over, and with every poll/loopback/cron
	 * tick piling onto the same PHP worker pool behind that lock.
	 *
	 * Now it behaves like database()/files(): send parts until the time budget
	 * is spent, persist the cursor, return. The upload survives a killed worker
	 * (it resumes at up_offset), reports real byte-level progress, and each
	 * request stays short.
	 */
	private static function upload( ISX_Job $job ) {
		$provider    = $job->get( 'to_storage', '' );
		$backup_name = $job->get( 'backup_name', '' );
		$backup      = ISX_Backups::path( $backup_name );

		if ( $backup === null || ! ISX_Destinations::is_configured( $provider ) ) {
			$message = 'ยังไม่ได้ตั้งค่า provider นี้ — ไฟล์ถูกเก็บไว้ในข้อมูลสำรองแล้ว';
			$job->finish( $message );
			return array(
				'progress' => 100,
				'done'     => true,
				'error'    => true,
				'message'  => $message,
			);
		}

		$client = new ISX_S3_Client( ISX_Destinations::get( $provider ) );
		$key    = ISX_Destinations::prefix( $provider ) . basename( $backup );
		$total  = (int) filesize( $backup );

		// Small enough to fit one request: a single PUT is both faster (no
		// initiate/complete round-trips) and can't be resumed anyway.
		if ( $total <= ISX_S3_Client::part_size() ) {
			$result = $client->put_object( $key, $backup );
			return is_wp_error( $result )
				? self::upload_failed( $job, $result->get_error_message() )
				: self::upload_finished( $job, $backup_name );
		}

		$target = $client->resolve_target( $key );

		// First visit: open the multipart upload and hand the UploadId to the
		// next round-trip. Nothing else happens this tick — CreateMultipartUpload
		// is the one call that must not be repeated after parts exist.
		$upload_id = (string) $job->get( 'up_id', '' );
		if ( $upload_id === '' ) {
			$upload_id = $client->multipart_initiate( $target['host'], $target['uri'] );
			if ( is_wp_error( $upload_id ) ) {
				return self::upload_failed( $job, $upload_id->get_error_message() );
			}

			$job->set( 'up_id', $upload_id );
			$job->set( 'up_host', $target['host'] );
			$job->set( 'up_uri', $target['uri'] );
			$job->set( 'up_offset', 0 );
			$job->set( 'up_total', $total );
			// Pinned for the life of this upload: every part but the last must be
			// exactly this size, so it cannot be recomputed (or refiltered) on a
			// later request without corrupting the assembled object.
			$job->set( 'up_part_size', ISX_S3_Client::part_size_for( $total ) );
			$job->set( 'up_parts', array() );
			$job->set( 'up_retries', 0 );
			$job->save();

			// No message: the step label, the bar, its percentage and the ETA
			// line already say everything there is to say about an upload. The
			// byte counts this used to print only restated the bar — and
			// size_format() rounded both sides to the same "6 GB" near the end,
			// so it read as finished while 8% was still in flight.
			return array(
				'progress' => 97,
				'done'     => false,
				'message'  => '',
			);
		}

		$host      = (string) $job->get( 'up_host', $target['host'] );
		$uri       = (string) $job->get( 'up_uri', $target['uri'] );
		$offset    = (int) $job->get( 'up_offset', 0 );
		$total     = max( 1, (int) $job->get( 'up_total', $total ) );
		$parts     = (array) $job->get( 'up_parts', array() );
		$part_size = max( 1, (int) $job->get( 'up_part_size', ISX_S3_Client::part_size_for( $total ) ) );
		$deadline  = self::deadline();

		// Send parts back to back while the budget lasts, saving after each one
		// so a worker killed mid-upload resumes at the next part rather than
		// restarting the whole archive.
		while ( $offset < $total ) {
			$length = (int) min( $part_size, $total - $offset );
			$number = count( $parts ) + 1;

			$etag = $client->multipart_upload_part( $host, $uri, $upload_id, $number, $backup, $offset, $length );

			if ( is_wp_error( $etag ) ) {
				$retries = (int) $job->get( 'up_retries', 0 ) + 1;
				$job->set( 'up_retries', $retries );
				$job->save();

				if ( $retries > self::UPLOAD_MAX_RETRIES ) {
					$client->multipart_abort( $host, $uri, $upload_id );
					return self::upload_failed( $job, $etag->get_error_message() );
				}

				// Hand control back instead of sleeping on it: the driver's next
				// tick retries this same part with the worker released in between.
				ISX_Logger::log_error(
					'export',
					sprintf( 'อัปโหลดส่วนที่ %d ไม่สำเร็จ กำลังลองใหม่ (%d/%d)', $number, $retries, self::UPLOAD_MAX_RETRIES ),
					array(
						'job'    => $job->id(),
						'part'   => $number,
						'offset' => $offset,
						'error'  => $etag->get_error_message(),
					)
				);

				// The one thing the bar can't show — worth a line of its own,
				// unlike the routine byte counts.
				return array(
					'progress' => 97 + 3 * ( $offset / $total ),
					'done'     => false,
					'message'  => sprintf( 'อัปโหลดส่วนที่ %d ขัดข้อง กำลังลองใหม่ (%d/%d)', $number, $retries, self::UPLOAD_MAX_RETRIES ),
				);
			}

			$parts[] = array(
				'number' => $number,
				'etag'   => $etag,
			);
			$offset += $length;

			$job->set( 'up_parts', $parts );
			$job->set( 'up_offset', $offset );
			$job->set( 'up_retries', 0 );
			$job->set( 'progress', 97 + 3 * ( $offset / $total ) );
			$job->save();

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( $offset < $total ) {
			return array(
				'progress' => 97 + 3 * ( $offset / $total ),
				'done'     => false,
				'message'  => '',
			);
		}

		$result = $client->multipart_complete( $host, $uri, $upload_id, $parts );
		if ( is_wp_error( $result ) ) {
			$client->multipart_abort( $host, $uri, $upload_id );
			return self::upload_failed( $job, $result->get_error_message() );
		}

		ISX_Logger::log_info(
			's3',
			'อัปโหลด multipart สำเร็จ',
			array(
				'host'  => $host,
				'parts' => count( $parts ),
				'size'  => $total,
			)
		);

		return self::upload_finished( $job, $backup_name );
	}

	/**
	 * @param string $message Reason, already human-readable.
	 * @return array Step result.
	 */
	private static function upload_failed( ISX_Job $job, $message ) {
		$message = 'อัปโหลดไม่สำเร็จ: ' . $message;
		$job->finish( $message );

		return array(
			'progress' => 100,
			'done'     => true,
			'error'    => true,
			'message'  => $message,
		);
	}

	/**
	 * @return array Step result.
	 */
	private static function upload_finished( ISX_Job $job, $backup_name ) {
		$message = 'ส่งออกและอัปโหลดไปยัง Storage สำเร็จ';
		$job->finish( $message );

		return array(
			'progress' => 100,
			'done'     => true,
			'message'  => $message,
			'backup'   => $backup_name,
		);
	}
}
