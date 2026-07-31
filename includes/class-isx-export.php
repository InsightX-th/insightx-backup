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
		if ( empty( $list_result['ok'] ) ) {
			return self::write_failed( $job, $job->file_list() );
		}
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
		if ( $fh === false ) {
			return self::write_failed( $job, $job->db_dump() );
		}

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
				if ( ! ISX_Database::dump_schema( $fh, $table ) ) {
					fclose( $fh );
					return self::write_failed( $job, $job->db_dump() );
				}
				$cursor['table_rows'] = ISX_Database::row_count( $table );
				$cursor['keyset']     = ISX_Database::keyset_column( $table );
				$cursor['last_pk']    = null;
			}
			$table_rows = (int) $cursor['table_rows'];
			$keyset     = isset( $cursor['keyset'] ) ? $cursor['keyset'] : null;
			$last_pk    = isset( $cursor['last_pk'] ) ? $cursor['last_pk'] : null;

			$res = ISX_Database::dump_rows( $fh, $table, $off, self::ROWS_PER_BATCH, $where, $search, $replace, $keyset, $last_pk );
			if ( empty( $res['ok'] ) ) {
				fclose( $fh );
				return self::write_failed( $job, $job->db_dump() );
			}
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
		} while ( microtime( true ) < $deadline && ! $job->is_cancel_requested() );

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
		if ( ! ISX_Archive::add_data( $job->archive(), 'package.json', wp_json_encode( $job->get( 'manifest', array() ) ) ) ) {
			return self::write_failed( $job );
		}
		if ( is_file( $job->db_dump() ) ) {
			// Unlike pack_batch()'s tolerance for a vanished source file, this one
			// is a file we just wrote ourselves a moment ago — anything other than
			// a clean write (including null, "not found") means the disk, not a
			// race with a live site, and the export cannot continue without it.
			if ( ISX_Archive::add_file( $job->archive(), $job->db_dump(), 'database.sql', $compress ) !== true ) {
				return self::write_failed( $job );
			}
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
		// request — the same round-trip collapse the database step gets. The
		// cancel check is what makes "ยกเลิก" feel immediate on a big site: this
		// is the phase that runs for hours, and without it the click would sit
		// there until the whole time budget was spent.
		do {
			$result       = ISX_Files::pack_batch( $job->archive(), $job->file_list(), (int) $cursor['fo'], self::FILES_PER_BATCH, $compress );
			$cursor['fo'] = $result['offset'];
			$done_files  += $result['added'];
			if ( empty( $result['ok'] ) ) {
				// Persist the cursor first — pack_batch() already rewound the
				// offset to before the failed entry, so a retried export (or a
				// look at the job's own state) doesn't believe this batch packed.
				$job->set( 'cursor', $cursor );
				$job->set( 'done_files', $done_files );
				$job->save();
				return self::write_failed( $job );
			}
			if ( $result['done'] ) {
				$phase_done = true;
				break;
			}
		} while ( microtime( true ) < $deadline && ! $job->is_cancel_requested() );

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
			'mu-plugins' => 'ปลั๊กอิน',
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
		if ( ! ISX_Archive::finish( $job->archive() ) ) {
			return self::write_failed( $job );
		}

		$backup_name = ISX_Backups::store( $job->archive() );
		if ( $backup_name === null ) {
			return self::write_failed( $job, ISX_Backups::dir() );
		}
		$backup_path = ISX_Backups::path( $backup_name );

		$options = (array) $job->get( 'options', array() );

		if ( $backup_path !== null && ! empty( $options['encrypt'] ) ) {
			$password = ISX_Crypto::decrypt_string( (string) $job->get( 'encrypt_password_enc', '' ) );
			if ( $password === '' ) {
				return self::encrypt_failed( $job, $backup_path, 'อ่านรหัสผ่านที่บันทึกไว้ไม่ได้' );
			}

			$tmp    = $backup_path . '.enc';
			$result = ISX_Crypto::encrypt_file( $password, $backup_path, $tmp );
			if ( is_wp_error( $result ) ) {
				// Falling through here used to hand back the *unencrypted*
				// archive under the same name, with nothing in the UI to say the
				// encryption the user explicitly asked for hadn't happened. A
				// backup that is silently not encrypted is worse than no backup.
				@unlink( $tmp );
				return self::encrypt_failed( $job, $backup_path, $result->get_error_message() );
			}

			// rename() replaces the destination atomically on POSIX, so the
			// plaintext original is never deleted ahead of a move that might
			// fail. Windows won't overwrite, hence the unlink-then-retry.
			if ( ! @rename( $tmp, $backup_path ) ) {
				@unlink( $backup_path );
				if ( ! @rename( $tmp, $backup_path ) ) {
					@unlink( $tmp );
					return self::encrypt_failed( $job, $backup_path, 'ย้ายไฟล์ที่เข้ารหัสแล้วไม่สำเร็จ' );
				}
			}
			clearstatcache( true, $backup_path );
		}

		$size = $backup_path !== null ? filesize( $backup_path ) : 0;
		$job->set( 'backup_name', $backup_name );
		$job->set( 'archive_size', $size );

		$provider = $job->get( 'to_storage', '' );
		if ( $provider !== '' ) {
			$job->set( 'step', 'upload' );
			$job->set( 'progress', 97 );
			$job->save();
			return array( 'progress' => 97, 'done' => false, 'message' => 'สร้างไฟล์สำรองเสร็จแล้ว' );
		}

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
			$job->finish( $message, true );
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

		if ( $total <= ISX_S3_Client::part_size() ) {
			$result = $client->put_object( $key, $backup );
			return is_wp_error( $result )
				? self::upload_failed( $job, $result->get_error_message() )
				: self::upload_finished( $job, $backup_name );
		}

		$target = $client->resolve_target( $key );

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
			$job->set( 'up_part_size', ISX_S3_Client::part_size_for( $total ) );
			$job->set( 'up_parts', array() );
			$job->set( 'up_retries', 0 );
			$job->save();

			// Also record it outside the job directory. The job's own state file
			// is the fast path for aborting, but it disappears with the storage
			// directory — changed path, reinstall, wiped disk — and then nothing
			// knows the upload id any more. This registry lives in wp_options,
			// so sweep_orphaned_uploads() can still release the upload the
			// moment its job is gone, without waiting for an age cutoff.
			self::register_pending_upload( $upload_id, $provider, $job->id(), $target['host'], $target['uri'] );

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
					self::forget_pending_upload( $upload_id );
					return self::upload_failed( $job, $etag->get_error_message() );
				}

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

				return array(
					'progress' => 97 + 3 * ( $offset / $total ),
					'done'     => false,
					'message'  => sprintf( 'กำลังลองใหม่ (%d/%d)', $retries, self::UPLOAD_MAX_RETRIES ),
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

			// A single part can take far longer than the whole step budget on a
			// slow link, so cancelling has to be checked per part, not just per
			// step — otherwise the click waits out the current part before
			// anything happens. run_step() releases the multipart upload.
			if ( microtime( true ) >= $deadline || $job->is_cancel_requested() ) {
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
			self::forget_pending_upload( $upload_id );
			return self::upload_failed( $job, $result->get_error_message() );
		}
		self::forget_pending_upload( $upload_id );

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
	 * A write to the archive, the database dump, or the backups directory
	 * failed — checked by every write on the export path (see ISX_Archive,
	 * ISX_Database::dump_schema()/dump_rows(), ISX_Files::pack_batch(),
	 * ISX_Backups::store()), all of which used to be trusted blindly. PHP
	 * doesn't throw when fwrite()/rename()/copy() come up short on a full
	 * disk — it just quietly writes less than asked, or nothing — so an
	 * unchecked return here was the difference between "no backup" and a
	 * truncated .wpress reported as ส่งออกเสร็จสิ้น.
	 *
	 * @return array Step result.
	 */
	private static function write_failed( ISX_Job $job, $path = null ) {
		$path = $path !== null ? $path : $job->archive();
		$free = @disk_free_space( dirname( $path ) );

		// Name the file and the space left, the way All-in-One WP Migration does
		// ("Out of disk space. Could not write content to file. File: …"): on a
		// shared host the path is what tells the user *which* mount filled up,
		// and without it "ดิสก์เต็ม" sends people to check the wrong volume.
		$message = 'พื้นที่ดิสก์ไม่พอ เขียนไฟล์ไม่สำเร็จ';
		if ( $free !== false ) {
			$message .= ' (เหลือ ' . size_format( (float) $free ) . ')';
		}
		$message .= ' — ไฟล์: ' . $path;

		$job->finish( $message, true );

		ISX_Logger::log_error(
			'export',
			$message,
			array(
				'job'        => $job->id(),
				'step'       => (string) $job->get( 'step', '' ),
				'file'       => $path,
				'free_space' => $free,
			)
		);

		return array(
			'progress' => 100,
			'done'     => true,
			'error'    => true,
			'message'  => $message,
		);
	}

	/**
	 * Encryption was requested but couldn't be delivered. The half-made backup
	 * is deleted rather than kept: it is plaintext, it sits in a directory whose
	 * only protection on nginx is an unguessable filename, and leaving it would
	 * mean the "ข้อมูลสำรอง" list shows a file the user would reasonably assume
	 * is encrypted.
	 *
	 * @param string $backup_path Plaintext archive to remove, or null.
	 * @param string $reason      Already human-readable.
	 * @return array Step result.
	 */
	private static function encrypt_failed( ISX_Job $job, $backup_path, $reason ) {
		if ( $backup_path !== null ) {
			@unlink( $backup_path );
		}

		$message = 'เข้ารหัสข้อมูลสำรองไม่สำเร็จ: ' . $reason . ' — ยกเลิกไฟล์สำรองนี้แล้วเพื่อไม่ให้เหลือไฟล์ที่ไม่ได้เข้ารหัสไว้';
		$job->finish( $message, true );

		ISX_Logger::log_error(
			'export',
			$message,
			array(
				'job'        => $job->id(),
				'free_space' => @disk_free_space( dirname( (string) $backup_path ) ),
			)
		);

		return array(
			'progress' => 100,
			'done'     => true,
			'error'    => true,
			'message'  => $message,
		);
	}

	/**
	 * @param string $message Reason, already human-readable.
	 * @return array Step result.
	 */
	private static function upload_failed( ISX_Job $job, $message ) {
		$message = 'อัปโหลดไม่สำเร็จ: ' . $message;
		$job->finish( $message, true );

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

	// Registry of multipart uploads this site started, so one can still be
	// released after its job directory is gone. Keyed by upload id.
	const PENDING_OPTION = 'isx_pending_uploads';

	// Age cutoff for uploads that are NOT in the registry — leftovers from
	// before this bookkeeping existed, or from another site sharing the bucket
	// folder. A pending multipart upload is also what an upload *in progress*
	// looks like, and there is no way to tell a stranded one from a live one
	// from the outside, so these need to be clearly stale before being touched.
	// Uploads this site owns are released immediately instead, no cutoff.
	const FOREIGN_UPLOAD_MAX_AGE = 12 * HOUR_IN_SECONDS;

	/**
	 * @return void
	 */
	private static function register_pending_upload( $upload_id, $provider, $job_id, $host, $uri ) {
		$pending = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		$pending[ $upload_id ] = array(
			'provider' => $provider,
			'job'      => $job_id,
			'host'     => $host,
			'uri'      => $uri,
			'started'  => time(),
		);
		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * @return void
	 */
	private static function forget_pending_upload( $upload_id ) {
		$pending = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $pending ) || ! isset( $pending[ $upload_id ] ) ) {
			return;
		}
		unset( $pending[ $upload_id ] );
		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * Release every multipart upload that can no longer belong to a live job.
	 *
	 * Two passes, because "is this upload dead?" is answerable with certainty
	 * only for uploads this site started:
	 *
	 *  1. Registry pass — an entry whose job is finished or gone cannot be
	 *     uploading any more, so it is aborted right away. No listing call and
	 *     no waiting: this is what makes a failed export stop costing storage
	 *     within the same minute rather than a day later.
	 *  2. Listing pass (only when $include_foreign) — anything the bucket
	 *     reports under this destination's folder that the registry has never
	 *     heard of: pre-upgrade leftovers, or another site writing to the same
	 *     folder. Those get the age cutoff, since aborting one that is actually
	 *     mid-upload elsewhere would break that site's export.
	 *
	 * @param bool $include_foreign Also do the listing pass (one HTTP request
	 *                              per configured provider).
	 * @return int Uploads aborted.
	 */
	public static function sweep_orphaned_uploads( $include_foreign = false ) {
		$pending = get_option( self::PENDING_OPTION, array() );
		$pending = is_array( $pending ) ? $pending : array();
		$aborted = 0;
		$known   = array();

		foreach ( $pending as $upload_id => $entry ) {
			$provider = isset( $entry['provider'] ) ? (string) $entry['provider'] : '';
			$host     = isset( $entry['host'] ) ? (string) $entry['host'] : '';
			$uri      = isset( $entry['uri'] ) ? (string) $entry['uri'] : '';

			$known[ $upload_id ] = true;

			if ( $host === '' || $uri === '' || ! ISX_Destinations::is_configured( $provider ) ) {
				self::forget_pending_upload( $upload_id );
				continue;
			}

			// Still being driven by a job that hasn't finished — leave it alone.
			$job = isset( $entry['job'] ) ? ISX_Job::load( (string) $entry['job'] ) : null;
			if ( $job !== null && $job->get( 'step' ) !== 'done' ) {
				continue;
			}

			$client = new ISX_S3_Client( ISX_Destinations::get( $provider ) );
			$result = $client->multipart_abort( $host, $uri, $upload_id );

			if ( is_wp_error( $result ) ) {
				// Keep the entry so the next sweep retries it — the endpoint may
				// simply have been unreachable this minute.
				ISX_Logger::log_warn(
					's3',
					'ล้าง upload ที่ค้างไม่สำเร็จ: ' . $result->get_error_message(),
					array( 'provider' => $provider, 'upload_id' => $upload_id )
				);
				continue;
			}

			self::forget_pending_upload( $upload_id );
			$aborted++;
			ISX_Logger::log_info(
				's3',
				'ล้าง upload ที่ค้างของงานที่จบไปแล้ว',
				array( 'provider' => $provider, 'upload_id' => $upload_id )
			);
		}

		if ( ! $include_foreign ) {
			return $aborted;
		}

		$max_age = (int) apply_filters( 'isx_foreign_upload_max_age', self::FOREIGN_UPLOAD_MAX_AGE );

		foreach ( ISX_Destinations::providers() as $slug => $meta ) {
			if ( ! ISX_Destinations::is_configured( $slug ) ) {
				continue;
			}
			$client  = new ISX_S3_Client( ISX_Destinations::get( $slug ) );
			$uploads = $client->list_multipart_uploads( ISX_Destinations::prefix( $slug ) );
			if ( is_wp_error( $uploads ) ) {
				continue;
			}

			foreach ( $uploads as $upload ) {
				if ( isset( $known[ $upload['upload_id'] ] ) ) {
					continue; // Handled by the registry pass above.
				}
				$started = strtotime( (string) $upload['initiated'] );
				if ( $started === false || time() - $started < $max_age ) {
					continue;
				}

				$target = $client->resolve_target( $upload['key'] );
				$result = $client->multipart_abort( $target['host'], $target['uri'], $upload['upload_id'] );
				if ( is_wp_error( $result ) ) {
					continue;
				}
				$aborted++;
				ISX_Logger::log_info(
					's3',
					'ล้าง upload ที่ค้างที่ไม่มีเจ้าของ',
					array( 'provider' => $slug, 'key' => $upload['key'] )
				);
			}
		}

		return $aborted;
	}

	/**
	 * Abort the multipart upload this job left pending on the bucket, if any.
	 *
	 * upload() only ever aborts from inside a request that still holds the
	 * upload id in a local variable, so a job that dies any other way — stall
	 * watchdog, cancelled by the user, worker killed mid-part — leaves parts
	 * sitting in the bucket as an "ongoing multipart upload" that no object
	 * browser can delete. The three paths that end a job from the outside call
	 * this so those parts don't accumulate.
	 *
	 * @return bool Whether an upload was pending and is now aborted.
	 */
	public static function abort_pending_upload( ISX_Job $job ) {
		$upload_id = (string) $job->get( 'up_id', '' );
		$host      = (string) $job->get( 'up_host', '' );
		$uri       = (string) $job->get( 'up_uri', '' );
		$provider  = (string) $job->get( 'to_storage', '' );

		if ( $upload_id === '' || $host === '' || $uri === '' ) {
			return false; // Never reached multipart, or already aborted.
		}
		if ( ! ISX_Destinations::is_configured( $provider ) ) {
			return false; // Credentials are gone; nothing can be signed.
		}

		// Drop the local record before the call, not after: this job is being
		// ended either way, and a stale up_id would let a later tick try to
		// resume or re-abort the same upload. If the abort itself fails the
		// upload is still findable from the Storage settings cleanup screen,
		// which lists what the bucket reports rather than what jobs remember.
		$job->set( 'up_id', '' );
		$job->save();

		$client = new ISX_S3_Client( ISX_Destinations::get( $provider ) );
		$result = $client->multipart_abort( $host, $uri, $upload_id );

		if ( ! is_wp_error( $result ) ) {
			self::forget_pending_upload( $upload_id );
		}
		// A failed abort keeps its registry entry on purpose: the next sweep
		// retries it once the endpoint answers again.

		$context = array(
			'job'       => $job->id(),
			'host'      => $host,
			'upload_id' => $upload_id,
		);

		if ( is_wp_error( $result ) ) {
			$context['error'] = $result->get_error_message();
			ISX_Logger::log_warn( 's3', 'ยกเลิกการอัปโหลดที่ค้างไม่สำเร็จ — ล้างได้จากหน้าตั้งค่า Storage', $context );
			return false;
		}

		ISX_Logger::log_info( 's3', 'ยกเลิกการอัปโหลดที่ค้างบน Storage แล้ว', $context );
		return true;
	}
}
