<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * WP-CLI commands. Only loaded when WP_CLI is active (see insightx-backup.php).
 * Drives the same ISX_Job/ISX_Export/ISX_Import pipeline the admin UI uses,
 * synchronously to completion via ISX_Admin::run_job_to_completion() instead
 * of the browser's AJAX poll loop.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_CLI_Command {

	/**
	 * Export this site to a .wpress package.
	 *
	 * ## OPTIONS
	 *
	 * [--to=<provider>]
	 * : Also upload the finished backup to a configured Storage provider slug
	 * (see "wp isx providers"). Omit to keep it local only.
	 *
	 * ## EXAMPLES
	 *
	 *     wp isx export
	 *     wp isx export --to=amazon_s3
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function export( $args, $assoc_args ) {
		$job = ISX_Job::create( 'export' );
		$job->set( 'options', array() );

		if ( ! empty( $assoc_args['to'] ) ) {
			$provider = sanitize_key( $assoc_args['to'] );
			if ( ! isset( ISX_Destinations::providers()[ $provider ] ) ) {
				WP_CLI::error( "ไม่รู้จัก provider: {$provider}" );
			}
			if ( ! ISX_Destinations::is_configured( $provider ) ) {
				WP_CLI::error( "provider '{$provider}' ยังไม่ได้ตั้งค่า credential — ไปที่หน้า \"ตั้งค่า Storage\" ก่อน" );
			}
			$job->set( 'to_storage', $provider );
		}
		$job->save();

		$progress = WP_CLI\Utils\make_progress_bar( 'กำลังส่งออก', 100 );
		$last     = 0;
		$result   = ISX_Admin::run_job_to_completion(
			$job,
			function ( $tick ) use ( $progress, &$last ) {
				$pct = isset( $tick['progress'] ) ? (int) $tick['progress'] : $last;
				for ( ; $last < $pct; $last++ ) {
					$progress->tick();
				}
			}
		);
		$progress->finish();

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( isset( $result['message'] ) ? $result['message'] : 'ส่งออกไม่สำเร็จ' );
		}

		WP_CLI::success( isset( $result['message'] ) ? $result['message'] : 'ส่งออกเสร็จสิ้น' );
		if ( ! empty( $result['backup'] ) ) {
			$path = ISX_Backups::path( $result['backup'] );
			WP_CLI::log( 'ไฟล์: ' . ( $path !== null ? $path : $result['backup'] ) );
		}
		if ( ! empty( $result['size'] ) ) {
			WP_CLI::log( 'ขนาด: ' . $result['size'] );
		}
	}

	/**
	 * Import a .wpress package, overwriting this site's files and database.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a .wpress package already on this server.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp isx import /tmp/site-backup.wpress
	 *     wp isx import /tmp/site-backup.wpress --yes
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function import( $args, $assoc_args ) {
		$file = isset( $args[0] ) ? $args[0] : '';
		if ( $file === '' || ! is_file( $file ) ) {
			WP_CLI::error( 'ไม่พบไฟล์: ' . $file );
		}
		if ( ! preg_match( '/\.wpress$/i', $file ) ) {
			WP_CLI::error( 'ต้องเป็นไฟล์ .wpress' );
		}

		WP_CLI::confirm( 'การนำเข้าจะเขียนทับเว็บปัจจุบันทั้งหมด (ไฟล์ + ฐานข้อมูล) ยืนยันหรือไม่?', $assoc_args );

		$job = ISX_Job::create( 'import' );
		if ( ! copy( $file, $job->archive() ) ) {
			WP_CLI::error( 'คัดลอกไฟล์เข้า job ไม่สำเร็จ' );
		}

		$progress = WP_CLI\Utils\make_progress_bar( 'กำลังนำเข้า', 100 );
		$last     = 0;
		$result   = ISX_Admin::run_job_to_completion(
			$job,
			function ( $tick ) use ( $progress, &$last ) {
				$pct = isset( $tick['progress'] ) ? (int) $tick['progress'] : $last;
				for ( ; $last < $pct; $last++ ) {
					$progress->tick();
				}
			}
		);
		$progress->finish();

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( isset( $result['message'] ) ? $result['message'] : 'นำเข้าไม่สำเร็จ' );
		}

		WP_CLI::success( isset( $result['message'] ) ? $result['message'] : 'นำเข้าเสร็จสิ้น — โปรดล็อกอินใหม่' );
	}

	/**
	 * List configured Storage provider slugs (for "wp isx export --to=<slug>").
	 */
	public function providers() {
		foreach ( ISX_Destinations::providers() as $slug => $meta ) {
			$status = ISX_Destinations::is_configured( $slug ) ? 'configured' : 'not configured';
			WP_CLI::log( sprintf( '%-16s %s (%s)', $slug, $meta['label'], $status ) );
		}
	}

	/**
	 * Release multipart uploads a provider still has pending.
	 *
	 * An upload that starts and never finishes leaves its parts in the bucket
	 * without ever becoming an object, so they keep costing storage while being
	 * invisible to (and undeletable from) a provider's object browser.
	 *
	 * ## OPTIONS
	 *
	 * [--to=<provider>]
	 * : Only this provider. Defaults to every configured one. See "wp isx providers".
	 *
	 * [--dry-run]
	 * : List what would be released without releasing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp isx cleanup-uploads
	 *     wp isx cleanup-uploads --to=cloudflare_r2 --dry-run
	 *
	 * @subcommand cleanup-uploads
	 */
	public function cleanup_uploads( $args, $assoc_args ) {
		$only    = isset( $assoc_args['to'] ) ? sanitize_key( $assoc_args['to'] ) : '';
		$dry_run = ! empty( $assoc_args['dry-run'] );

		$slugs = array();
		foreach ( ISX_Destinations::providers() as $slug => $meta ) {
			if ( $only !== '' && $slug !== $only ) {
				continue;
			}
			if ( ! ISX_Destinations::is_configured( $slug ) ) {
				continue;
			}
			$slugs[] = $slug;
		}

		if ( empty( $slugs ) ) {
			WP_CLI::error( $only !== '' ? 'ยังไม่ได้ตั้งค่า provider นี้' : 'ยังไม่ได้ตั้งค่า provider ใดเลย' );
		}

		$found  = 0;
		$closed = 0;
		$failed = 0;

		foreach ( $slugs as $slug ) {
			$client  = new ISX_S3_Client( ISX_Destinations::get( $slug ) );
			$prefix  = ISX_Destinations::prefix( $slug );
			$uploads = $client->list_multipart_uploads( $prefix );

			if ( is_wp_error( $uploads ) ) {
				WP_CLI::warning( sprintf( '%s: %s', $slug, $uploads->get_error_message() ) );
				$failed++;
				continue;
			}
			if ( empty( $uploads ) ) {
				WP_CLI::log( sprintf( '%s: ไม่มี upload ที่ค้าง', $slug ) );
				continue;
			}

			foreach ( $uploads as $upload ) {
				$found++;
				WP_CLI::log( sprintf( '%s: %s (เริ่ม %s)', $slug, $upload['key'], $upload['initiated'] ) );

				if ( $dry_run ) {
					continue;
				}

				$target = $client->resolve_target( $upload['key'] );
				$result = $client->multipart_abort( $target['host'], $target['uri'], $upload['upload_id'] );

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( '  ล้างไม่สำเร็จ: %s', $result->get_error_message() ) );
					$failed++;
					continue;
				}
				$closed++;
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'พบ %d รายการที่ค้าง (dry-run — ไม่ได้ล้าง)', $found ) );
			return;
		}
		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( 'ล้างแล้ว %d รายการ, ไม่สำเร็จ %d รายการ', $closed, $failed ) );
		}
		WP_CLI::success( sprintf( 'ล้างแล้ว %d รายการ', $closed ) );
	}
}
