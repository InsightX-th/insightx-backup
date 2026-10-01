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
				WP_CLI::error( sprintf( __( 'Unknown provider: %s', 'insightx-backup' ), $provider ) );
			}
			if ( ! ISX_Destinations::is_configured( $provider ) ) {
				WP_CLI::error( sprintf( __( 'Provider \'%s\' has no credentials configured — go to the "Storage Settings" page first', 'insightx-backup' ), $provider ) );
			}
			$job->set( 'to_storage', $provider );
		}
		$job->save();

		$progress = WP_CLI\Utils\make_progress_bar( __( 'Exporting', 'insightx-backup' ), 100 );
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
			WP_CLI::error( isset( $result['message'] ) ? $result['message'] : __( 'Export failed', 'insightx-backup' ) );
		}

		WP_CLI::success( isset( $result['message'] ) ? $result['message'] : __( 'Export complete', 'insightx-backup' ) );
		if ( ! empty( $result['backup'] ) ) {
			$path = ISX_Backups::path( $result['backup'] );
			WP_CLI::log( __( 'File: ', 'insightx-backup' ) . ( $path !== null ? $path : $result['backup'] ) );
		}
		if ( ! empty( $result['size'] ) ) {
			WP_CLI::log( __( 'Size: ', 'insightx-backup' ) . $result['size'] );
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
	 * [--password=<password>]
	 * : Password of an encrypted package. Asked for (hidden) when the package
	 * is encrypted and this is omitted — prefer that over typing it here,
	 * where it lands in shell history and the process list.
	 *
	 * ## EXAMPLES
	 *
	 *     wp isx import /tmp/site-backup.wpress
	 *     wp isx import /tmp/site-backup.wpress --yes
	 *     wp isx import /tmp/encrypted.wpress --yes
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function import( $args, $assoc_args ) {
		$file = isset( $args[0] ) ? $args[0] : '';
		if ( $file === '' || ! is_file( $file ) ) {
			WP_CLI::error( __( 'File not found: ', 'insightx-backup' ) . $file );
		}
		if ( ! preg_match( '/\.wpress$/i', $file ) ) {
			WP_CLI::error( __( 'Must be a .wpress file', 'insightx-backup' ) );
		}

		WP_CLI::confirm( __( 'Importing will overwrite the entire current site (files + database). Continue?', 'insightx-backup' ), $assoc_args );

		$encrypted = ISX_Crypto::is_encrypted_file( $file );
		$password  = isset( $assoc_args['password'] ) ? (string) $assoc_args['password'] : '';
		if ( $encrypted && $password === '' ) {
			// Only ask on a real terminal: from cron/CI a hidden prompt either
			// throws on EOF or waits forever on an idle stdin.
			$interactive = function_exists( 'stream_isatty' ) ? @stream_isatty( STDIN ) : ( function_exists( 'posix_isatty' ) && @posix_isatty( STDIN ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $interactive ) {
				WP_CLI::error( __( 'This package is password-encrypted — pass --password=<password>', 'insightx-backup' ) );
			}
			$password = (string) \cli\prompt( __( 'This file is password-encrypted. Please enter the password', 'insightx-backup' ), false, ': ', true );
		}

		$job = ISX_Job::create( 'import' );
		if ( $encrypted ) {
			// Decrypted straight into the job — the same end state the
			// dashboard reaches through isx_import_decrypt.
			$decrypted = ISX_Crypto::decrypt_file( $password, $file, $job->archive() );
			if ( is_wp_error( $decrypted ) ) {
				$job->cleanup();
				WP_CLI::error( $decrypted->get_error_message() );
			}
			$job->set( 'decrypted', true );
			$job->save();
		} elseif ( ! copy( $file, $job->archive() ) ) {
			WP_CLI::error( __( 'Could not copy the file into the job', 'insightx-backup' ) );
		}

		$progress = WP_CLI\Utils\make_progress_bar( __( 'Importing', 'insightx-backup' ), 100 );
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

		if ( ! empty( $result['needs_password'] ) ) {
			// Only reachable if the package is encrypted in a way detected
			// later than is_encrypted_file() (e.g. inside a gzip) — never loop.
			$job->cleanup();
			WP_CLI::error( __( 'This package is password-encrypted — pass --password=<password>', 'insightx-backup' ) );
		}
		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( isset( $result['message'] ) ? $result['message'] : __( 'Import failed', 'insightx-backup' ) );
		}

		WP_CLI::success( isset( $result['message'] ) ? $result['message'] : __( 'Import complete — please log in again', 'insightx-backup' ) );
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
			WP_CLI::error( $only !== '' ? __( 'This provider is not configured yet', 'insightx-backup' ) : __( 'No provider has been configured yet', 'insightx-backup' ) );
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
				WP_CLI::log( sprintf( __( '%s: no stale uploads', 'insightx-backup' ), $slug ) );
				continue;
			}

			foreach ( $uploads as $upload ) {
				$found++;
				WP_CLI::log( sprintf( __( '%s: %s (started %s)', 'insightx-backup' ), $slug, $upload['key'], $upload['initiated'] ) );

				if ( $dry_run ) {
					continue;
				}

				$target = $client->resolve_target( $upload['key'] );
				$result = $client->multipart_abort( $target['host'], $target['uri'], $upload['upload_id'] );

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( __( '  Cleanup failed: %s', 'insightx-backup' ), $result->get_error_message() ) );
					$failed++;
					continue;
				}
				$closed++;
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( __( 'Found %d stale uploads (dry-run — nothing cleaned)', 'insightx-backup' ), $found ) );
			return;
		}
		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( __( 'Cleaned %d, failed %d', 'insightx-backup' ), $closed, $failed ) );
		}
		WP_CLI::success( sprintf( __( 'Cleaned %d', 'insightx-backup' ), $closed ) );
	}
}
