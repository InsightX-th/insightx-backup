<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Admin UI + AJAX router that drives the export / import pipelines, the local
 * backups list, and the S3 storage settings / destinations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Admin {

	const NONCE = 'isx_admin';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'wp_ajax_isx_export_start', array( __CLASS__, 'ajax_export_start' ) );
		add_action( 'wp_ajax_isx_import_create', array( __CLASS__, 'ajax_import_create' ) );
		add_action( 'wp_ajax_isx_import_chunk', array( __CLASS__, 'ajax_import_chunk' ) );
		add_action( 'wp_ajax_isx_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_isx_import_decrypt', array( __CLASS__, 'ajax_import_decrypt' ) );
		add_action( 'wp_ajax_isx_list_tables', array( __CLASS__, 'ajax_list_tables' ) );
		add_action( 'wp_ajax_isx_download', array( __CLASS__, 'ajax_download' ) );

		add_action( 'wp_ajax_isx_backups_list', array( __CLASS__, 'ajax_backups_list' ) );
		add_action( 'wp_ajax_isx_backups_delete', array( __CLASS__, 'ajax_backups_delete' ) );
		add_action( 'wp_ajax_isx_backups_restore', array( __CLASS__, 'ajax_backups_restore' ) );
		add_action( 'wp_ajax_isx_backups_list_content', array( __CLASS__, 'ajax_backups_list_content' ) );

		add_action( 'wp_ajax_isx_storage_save', array( __CLASS__, 'ajax_storage_save' ) );
		add_action( 'wp_ajax_isx_storage_import_list', array( __CLASS__, 'ajax_storage_import_list' ) );
		add_action( 'wp_ajax_isx_storage_import_prepare', array( __CLASS__, 'ajax_storage_import_prepare' ) );
	}

	public static function menu() {
		add_menu_page(
			'InsightX Backup',
			'InsightX Backup',
			'export',
			'isx_export',
			array( __CLASS__, 'page_export' ),
			'dashicons-database-export',
			76
		);
		add_submenu_page( 'isx_export', __( 'ส่งออก', 'insightx-backup' ), __( 'ส่งออก', 'insightx-backup' ), 'export', 'isx_export', array( __CLASS__, 'page_export' ) );
		add_submenu_page( 'isx_export', __( 'นำเข้า', 'insightx-backup' ), __( 'นำเข้า', 'insightx-backup' ), 'import', 'isx_import', array( __CLASS__, 'page_import' ) );
		add_submenu_page( 'isx_export', __( 'ข้อมูลสำรอง', 'insightx-backup' ), __( 'ข้อมูลสำรอง', 'insightx-backup' ), 'export', 'isx_backups', array( __CLASS__, 'page_backups' ) );
		add_submenu_page( 'isx_export', __( 'ตั้งค่า Storage', 'insightx-backup' ), __( 'ตั้งค่า Storage', 'insightx-backup' ), 'export', 'isx_settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		$is_isx_page = strpos( $hook, 'isx_export' ) !== false
			|| strpos( $hook, 'isx_import' ) !== false
			|| strpos( $hook, 'isx_backups' ) !== false
			|| strpos( $hook, 'isx_settings' ) !== false;

		if ( ! $is_isx_page ) {
			return;
		}

		wp_enqueue_style( 'isx-admin', ISX_URL . 'assets/css/isx-admin.css', array(), ISX_VERSION );
		wp_enqueue_script( 'isx-admin', ISX_URL . 'assets/js/isx-admin.js', array( 'jquery' ), ISX_VERSION, true );
		wp_localize_script(
			'isx-admin',
			'isx',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE ),
				'chunk_size' => 4 * 1024 * 1024,
			)
		);

		wp_enqueue_script( 'isx-storage', ISX_URL . 'assets/js/isx-storage.js', array( 'jquery', 'isx-admin' ), ISX_VERSION, true );
		wp_localize_script(
			'isx-storage',
			'isx_storage',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'providers' => ISX_Destinations::js_data(),
			)
		);
	}

	public static function page_export() {
		require ISX_PATH . 'views/export.php';
	}

	public static function page_import() {
		require ISX_PATH . 'views/import.php';
	}

	public static function page_backups() {
		require ISX_PATH . 'views/backups.php';
	}

	public static function page_settings() {
		require ISX_PATH . 'views/settings.php';
	}

	/* ---------------- Export / Import pipeline AJAX ---------------- */

	public static function ajax_export_start() {
		self::guard( 'export' );
		$job = ISX_Job::create( 'export' );

		$provider = isset( $_POST['to_storage'] ) ? sanitize_key( wp_unslash( $_POST['to_storage'] ) ) : '';
		if ( $provider !== '' && isset( ISX_Destinations::providers()[ $provider ] ) ) {
			$job->set( 'to_storage', $provider );
		}

		$replace_old = isset( $_POST['replace_old'] ) && is_array( $_POST['replace_old'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['replace_old'] ) ) : array();
		$replace_new = isset( $_POST['replace_new'] ) && is_array( $_POST['replace_new'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['replace_new'] ) ) : array();
		// Drop pairs with an empty "search for" value.
		$clean_old = array();
		$clean_new = array();
		foreach ( $replace_old as $i => $old_value ) {
			if ( $old_value === '' ) {
				continue;
			}
			$clean_old[] = $old_value;
			$clean_new[] = isset( $replace_new[ $i ] ) ? $replace_new[ $i ] : '';
		}

		$bool = function ( $key ) {
			return ! empty( $_POST[ $key ] ) && $_POST[ $key ] !== '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		};

		$compression = isset( $_POST['compression'] ) ? sanitize_key( wp_unslash( $_POST['compression'] ) ) : 'none';
		if ( $compression !== 'gzip' ) {
			$compression = 'none';
		}

		$exclude_selected_tables = isset( $_POST['exclude_selected_tables'] ) && is_array( $_POST['exclude_selected_tables'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['exclude_selected_tables'] ) )
			: array();

		$exclude_selected_files = isset( $_POST['exclude_selected_files'] ) && is_array( $_POST['exclude_selected_files'] )
			? array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['exclude_selected_files'] ) ) )
			: array();

		$options = array(
			'replace_old'              => $clean_old,
			'replace_new'              => $clean_new,
			'compression'              => $compression,
			'exclude_spam_comments'    => $bool( 'exclude_spam_comments' ),
			'exclude_post_revisions'   => $bool( 'exclude_post_revisions' ),
			'exclude_database'         => $bool( 'exclude_database' ),
			'exclude_selected_tables'  => $exclude_selected_tables,
			'no_replace_email_domain'  => $bool( 'no_replace_email_domain' ),
			'exclude_media'            => $bool( 'exclude_media' ),
			'exclude_themes'           => $bool( 'exclude_themes' ),
			'exclude_inactive_themes'  => $bool( 'exclude_inactive_themes' ),
			'exclude_mu_plugins'       => $bool( 'exclude_mu_plugins' ),
			'exclude_plugins'          => $bool( 'exclude_plugins' ),
			'exclude_inactive_plugins' => $bool( 'exclude_inactive_plugins' ),
			'exclude_cache_files'      => $bool( 'exclude_cache_files' ),
			'exclude_selected_files'   => $exclude_selected_files,
			'encrypt'                  => $bool( 'encrypt' ),
		);
		$job->set( 'options', $options );

		if ( $options['encrypt'] ) {
			$password = isset( $_POST['encrypt_password'] ) ? (string) wp_unslash( $_POST['encrypt_password'] ) : '';
			if ( $password !== '' ) {
				$job->set( 'encrypt_password_enc', ISX_Crypto::encrypt_string( $password ) );
			} else {
				$options['encrypt'] = false;
				$job->set( 'options', $options );
			}
		}

		$job->save();

		wp_send_json_success( array( 'job' => $job->id(), 'secret' => $job->get( 'secret' ) ) );
	}

	public static function ajax_import_create() {
		self::guard( 'import' );
		$job = ISX_Job::create( 'import' );
		file_put_contents( $job->archive(), '' );
		wp_send_json_success( array( 'job' => $job->id(), 'secret' => $job->get( 'secret' ) ) );
	}

	public static function ajax_import_chunk() {
		self::guard( 'import' );
		$job = ISX_Job::load( isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '' );
		if ( ! $job || $job->get( 'type' ) !== 'import' ) {
			wp_send_json_error( array( 'message' => 'งานไม่ถูกต้อง' ) );
		}
		if ( empty( $_FILES['chunk']['tmp_name'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => 'ไม่พบข้อมูล chunk' ) );
		}

		$out = fopen( $job->archive(), 'ab' );
		$in  = fopen( $_FILES['chunk']['tmp_name'], 'rb' );
		if ( $out && $in ) {
			while ( ! feof( $in ) ) {
				$buf = fread( $in, 1048576 );
				if ( $buf === false ) {
					break;
				}
				fwrite( $out, $buf );
			}
		}
		if ( $in ) {
			fclose( $in );
		}
		if ( $out ) {
			fclose( $out );
		}

		wp_send_json_success( array( 'received' => true ) );
	}

	public static function ajax_run() {
		$job = ISX_Job::load( isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '' );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'ไม่พบงาน' ) );
		}

		// Authenticate against the on-disk per-job secret rather than the WP
		// session: an import rewrites wp_users/wp_options and would otherwise
		// break auth partway through the poll loop.
		$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
		if ( ! hash_equals( (string) $job->get( 'secret' ), $secret ) ) {
			wp_send_json_error( array( 'message' => 'secret ไม่ถูกต้อง' ) );
		}

		$result = self::run_step( $job );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	/**
	 * WP-Cron driver: keeps a job advancing even if no browser tab is polling
	 * it (tab closed, user navigated to another admin page, etc). Chains
	 * itself via wp_schedule_single_event() until the job reports done.
	 * Cron ticks and browser polls both funnel through run_step(), which
	 * takes an exclusive lock per job so the two drivers never race on the
	 * same pipeline step.
	 *
	 * @param string $job_id
	 * @return void
	 */
	public static function cron_step( $job_id ) {
		$job = ISX_Job::load( $job_id );
		if ( ! $job ) {
			return;
		}

		self::run_step( $job );
	}

	/**
	 * Execute exactly one pipeline step under an exclusive per-job lock, and
	 * annotate the result with elapsed / estimated-remaining time. If another
	 * driver (the other of "browser poll" / "cron tick") currently holds the
	 * lock, returns the job's last known progress instead of blocking, so the
	 * UI keeps showing motion rather than appearing to freeze.
	 *
	 * @param ISX_Job $job
	 * @return array
	 */
	private static function run_step( ISX_Job $job ) {
		@set_time_limit( 0 );

		$result = $job->with_lock(
			function ( ISX_Job $locked_job ) {
				if ( $locked_job->get( 'step' ) === 'done' ) {
					return array(
						'progress' => 100,
						'done'     => true,
						'message'  => (string) $locked_job->get( 'last_message', 'เสร็จสิ้น' ),
					);
				}

				$result = ( $locked_job->get( 'type' ) === 'import' ) ? ISX_Import::run( $locked_job ) : ISX_Export::run( $locked_job );

				// Some early-failure paths still delete the job dir outright
				// (see cleanup() call sites) — only persist the "last known"
				// breadcrumb fields when it's still there.
				if ( is_dir( $locked_job->dir() ) ) {
					$locked_job->set( 'last_message', isset( $result['message'] ) ? $result['message'] : '' );
					$locked_job->set( 'last_progress', isset( $result['progress'] ) ? $result['progress'] : 0 );
					$locked_job->save();
				}

				return $result;
			}
		);

		if ( $result === false ) {
			// Lock held by the other driver right now — echo the last known
			// state instead of executing (and definitely instead of double-running).
			$result = array(
				'progress' => (int) $job->get( 'last_progress', $job->get( 'progress', 0 ) ),
				'done'     => false,
				'message'  => (string) $job->get( 'last_message', 'กำลังดำเนินการ...' ),
			);
		}

		$created = (int) $job->get( 'created', time() );
		$elapsed = max( 0, time() - $created );
		$result['elapsed'] = $elapsed;

		// A step just ran (successfully or "busy") and isn't finished yet — make
		// sure a background WP-Cron tick is pending so the job keeps advancing
		// even if this happens to be the last poll the browser ever sends
		// (tab closed, user navigated away, etc).
		if ( empty( $result['done'] ) ) {
			self::schedule_cron( $job->id() );
		}

		if ( empty( $result['done'] ) && ! empty( $result['progress'] ) && (int) $result['progress'] > 0 ) {
			$progress         = (int) $result['progress'];
			$total_estimated  = $elapsed / ( $progress / 100 );
			$result['eta']    = max( 0, (int) round( $total_estimated - $elapsed ) );
		} else {
			$result['eta'] = null;
		}

		return $result;
	}

	/**
	 * Schedule (or re-schedule) the next background cron tick for a job,
	 * de-duplicating so a job never has two pending ticks at once.
	 *
	 * @param string $job_id
	 * @return void
	 */
	private static function schedule_cron( $job_id ) {
		if ( ! wp_next_scheduled( 'isx_cron_step', array( $job_id ) ) ) {
			wp_schedule_single_event( time() + 1, 'isx_cron_step', array( $job_id ) );
		}
	}

	/**
	 * Decrypt a password-protected .wpress package in place before an import
	 * job's poll loop continues (triggered from the "needs_password" state).
	 */
	public static function ajax_import_decrypt() {
		$job = ISX_Job::load( isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '' );
		if ( ! $job || $job->get( 'type' ) !== 'import' ) {
			wp_send_json_error( array( 'message' => 'ไม่พบงาน' ) );
		}

		$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
		if ( ! hash_equals( (string) $job->get( 'secret' ), $secret ) ) {
			wp_send_json_error( array( 'message' => 'secret ไม่ถูกต้อง' ) );
		}

		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		if ( $password === '' ) {
			wp_send_json_error( array( 'message' => 'กรุณากรอกรหัสผ่าน' ) );
		}

		@set_time_limit( 0 );

		$tmp    = $job->archive() . '.dec';
		$result = ISX_Crypto::decrypt_file( $password, $job->archive(), $tmp );
		if ( is_wp_error( $result ) ) {
			@unlink( $tmp );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		@unlink( $job->archive() );
		rename( $tmp, $job->archive() );
		$job->set( 'decrypted', true );
		$job->save();

		wp_send_json_success();
	}

	/**
	 * List DB tables + row counts for the "Exclude the selected database
	 * tables" picker on the export screen.
	 */
	public static function ajax_list_tables() {
		self::guard( 'export' );

		$tables = array();
		foreach ( ISX_Database::tables() as $table ) {
			$tables[] = array(
				'name' => $table,
				'rows' => ISX_Database::row_count( $table ),
			);
		}

		wp_send_json_success( array( 'tables' => $tables ) );
	}

	public static function ajax_download() {
		self::guard( 'export' );
		check_admin_referer( self::NONCE, 'nonce' );

		$name = isset( $_GET['backup'] ) ? sanitize_text_field( wp_unslash( $_GET['backup'] ) ) : '';
		$path = ISX_Backups::path( $name );
		if ( $path === null ) {
			wp_die( esc_html__( 'ไม่พบไฟล์', 'insightx-backup' ) );
		}

		// Backups run well past PHP's default max_execution_time (a few hundred
		// MB over a slow connection easily takes longer than 30s) — without
		// this the download gets cut off mid-stream on larger archives.
		@set_time_limit( 0 );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		$fh = fopen( $path, 'rb' );
		while ( ! feof( $fh ) ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore
			flush();
		}
		fclose( $fh );
		exit;
	}

	/* ---------------- Backups AJAX ---------------- */

	public static function ajax_backups_list() {
		self::guard( 'export' );
		wp_send_json_success( array( 'backups' => ISX_Backups::all() ) );
	}

	public static function ajax_backups_delete() {
		self::guard( 'export' );
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! ISX_Backups::delete( $name ) ) {
			wp_send_json_error( array( 'message' => 'ลบไม่สำเร็จ' ) );
		}
		wp_send_json_success();
	}

	/**
	 * Start an import job directly from a local backup (no upload needed).
	 */
	public static function ajax_backups_restore() {
		self::guard( 'import' );
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$path = ISX_Backups::path( $name );
		if ( $path === null ) {
			wp_send_json_error( array( 'message' => 'ไม่พบไฟล์ข้อมูลสำรอง' ) );
		}

		$job = ISX_Job::create( 'import' );
		copy( $path, $job->archive() );

		wp_send_json_success( array( 'job' => $job->id(), 'secret' => $job->get( 'secret' ) ) );
	}

	/**
	 * List the files packed inside a local backup, without extracting them.
	 */
	public static function ajax_backups_list_content() {
		self::guard( 'export' );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$path = ISX_Backups::path( $name );
		if ( $path === null ) {
			wp_send_json_error( array( 'message' => 'ไม่พบไฟล์ข้อมูลสำรอง' ) );
		}

		if ( ISX_Crypto::is_encrypted_file( $path ) ) {
			wp_send_json_error( array( 'message' => 'ไฟล์นี้เข้ารหัสด้วยรหัสผ่าน จึงแสดงรายการไม่ได้ — กู้คืนได้โดยตรง' ) );
		}

		$read_path = $path;
		$tmp       = null;
		if ( ISX_Compress::is_gzip_file( $path ) ) {
			$tmp    = $path . '.peek';
			$result = ISX_Compress::gunzip_file( $path, $tmp );
			if ( is_wp_error( $result ) ) {
				@unlink( $tmp );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$read_path = $tmp;
		}

		$entries = array();
		$ok      = ISX_Archive::each(
			$read_path,
			function ( $header ) use ( &$entries ) {
				$path_in_archive = isset( $header['p'] ) ? $header['p'] : '';
				if ( $path_in_archive === 'database.isxdb' || $path_in_archive === 'manifest.json' ) {
					return;
				}
				$entries[] = array(
					'path' => $path_in_archive,
					'size' => isset( $header['s'] ) ? (int) $header['s'] : 0,
				);
			}
		);

		if ( $tmp !== null ) {
			@unlink( $tmp );
		}

		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => 'ไฟล์แพ็กเกจไม่ถูกต้อง' ) );
		}

		usort(
			$entries,
			function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		$out = array();
		foreach ( $entries as $entry ) {
			$out[] = array(
				'path' => $entry['path'],
				'size' => size_format( $entry['size'], 2 ),
			);
		}

		wp_send_json_success( array( 'entries' => $out ) );
	}

	/* ---------------- Storage settings AJAX ---------------- */

	public static function ajax_storage_save() {
		self::guard( 'export' );

		$slug = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! isset( ISX_Destinations::providers()[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => 'ปลายทางไม่ถูกต้อง' ) );
		}

		$posted  = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : array();
		$current = ISX_Destinations::all();

		if ( empty( $posted['secret_key'] ) && ! empty( $current[ $slug ]['secret_key'] ) ) {
			$posted['secret_key'] = $current[ $slug ]['secret_key'];
		}

		$all          = ISX_Destinations::all();
		$all[ $slug ] = $posted;
		ISX_Destinations::save( $all );

		// Saving always succeeds (partial credentials are fine — the user may
		// be filling a provider in over several saves), but the UI's "connected"
		// badge should only light up once the credentials actually work — not
		// just once bucket/access_key/secret_key are non-empty. A typo'd bucket
		// name or wrong key would otherwise still show as "เชื่อมต่อสำเร็จ".
		$connected = false;
		$message   = 'บันทึกแล้ว — ยังกรอกไม่ครบ';

		if ( ISX_Destinations::is_configured( $slug ) ) {
			$saved  = ISX_Destinations::get( $slug );
			$client = new ISX_S3_Client( $saved );
			$result = $client->test_connection();

			if ( is_wp_error( $result ) ) {
				$connected = false;
				$message   = 'เชื่อมต่อไม่สำเร็จ: ' . $result->get_error_message();
			} else {
				$connected = true;
				$message   = 'เชื่อมต่อสำเร็จ';
			}
		}

		wp_send_json_success(
			array(
				'message'   => $message,
				'connected' => $connected,
			)
		);
	}

	/**
	 * List .wpress backups sitting in a provider's bucket.
	 */
	public static function ajax_storage_import_list() {
		self::guard( 'import' );

		$slug = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! isset( ISX_Destinations::providers()[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => 'ปลายทางไม่ถูกต้อง' ) );
		}
		if ( ! ISX_Destinations::is_configured( $slug ) ) {
			wp_send_json_error( array( 'message' => 'ยังไม่ได้ตั้งค่า provider นี้ — ไปที่เมนู "ตั้งค่า Storage" ก่อน' ) );
		}

		$client = new ISX_S3_Client( ISX_Destinations::get( $slug ) );
		$result = $client->list_objects( 'insightx-migrate/' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$backups = array();
		foreach ( $result as $object ) {
			if ( substr( $object['key'], -7 ) !== '.wpress' ) {
				continue;
			}
			$backups[] = array(
				'key'           => $object['key'],
				'name'          => basename( $object['key'] ),
				'size'          => size_format( (int) $object['size'], 2 ),
				'last_modified' => $object['last_modified'],
			);
		}

		wp_send_json_success( array( 'backups' => $backups ) );
	}

	/**
	 * Download a backup from a provider into a fresh import job.
	 */
	public static function ajax_storage_import_prepare() {
		self::guard( 'import' );

		$slug = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$key  = isset( $_POST['key'] ) ? trim( wp_unslash( $_POST['key'] ) ) : '';

		if ( ! isset( ISX_Destinations::providers()[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => 'ปลายทางไม่ถูกต้อง' ) );
		}
		if ( ! ISX_Destinations::is_configured( $slug ) ) {
			wp_send_json_error( array( 'message' => 'ยังไม่ได้ตั้งค่า provider นี้' ) );
		}
		if ( strpos( $key, 'insightx-migrate/' ) !== 0 || substr( $key, -7 ) !== '.wpress' ) {
			wp_send_json_error( array( 'message' => 'ชื่อไฟล์ไม่ถูกต้อง' ) );
		}

		@set_time_limit( 0 );

		$job    = ISX_Job::create( 'import' );
		$client = new ISX_S3_Client( ISX_Destinations::get( $slug ) );
		$result = $client->get_object( $key, $job->archive() );

		if ( is_wp_error( $result ) ) {
			$job->cleanup();
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'job' => $job->id(), 'secret' => $job->get( 'secret' ) ) );
	}

	private static function guard( $cap ) {
		if ( ! current_user_can( $cap ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'ไม่มีสิทธิ์' ) );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}
}
