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

	/**
	 * How many archive entries ajax_backups_list_content() walks per request.
	 * The walk only reads each entry's header and seeks past its content, so
	 * this is cheap — but a package with hundreds of thousands of entries still
	 * needs splitting across requests to stay inside max_execution_time.
	 *
	 * @var int
	 */
	const CONTENT_ENTRIES_PER_BATCH = 20000;

	/**
	 * Cap on how many individual files one request may name.
	 *
	 * The listing summarises directories, so this only bites inside a single
	 * folder holding more files than anyone can read — an uploads month with
	 * 40,000 images, say. Past this the UI says how many were left out rather
	 * than trying to render them.
	 *
	 * @var int
	 */
	const CONTENT_FILES_PER_BATCH = 500;

	/**
	 * How long a decompressed scratch copy of a gzipped package may sit in the
	 * backups directory before it is swept (seconds). Browsing a gzipped
	 * package needs a seekable copy, and re-inflating a multi-GB file for every
	 * request in a scan would be far worse than keeping one around briefly.
	 *
	 * @var int
	 */
	const CONTENT_PEEK_TTL = 3600;

	/**
	 * Seconds of zero overall-progress movement after which run_step() declares
	 * a job wedged and fails it (see the watchdog in run_step). The time-budgeted
	 * work steps advance the bar roughly every STEP_TIME_BUDGET seconds, so this
	 * only trips on a genuine stall, never on a merely slow-but-progressing job.
	 *
	 * @var int
	 */
	const STALL_LIMIT = 300;

	/**
	 * True while run_job_to_completion() is driving a job synchronously (WP-CLI /
	 * scheduled backup). In that mode the background drivers (loopback + WP-Cron
	 * self-scheduling) are suppressed — the tight loop already runs the job to
	 * the end in this one process, and a loopback back into admin-ajax may not
	 * even resolve from a CLI context.
	 *
	 * @var bool
	 */
	private static $driving_synchronously = false;

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_sweep_uploads' ) );

		add_action( 'wp_ajax_isx_export_start', array( __CLASS__, 'ajax_export_start' ) );
		add_action( 'wp_ajax_isx_import_create', array( __CLASS__, 'ajax_import_create' ) );
		add_action( 'wp_ajax_isx_import_chunk', array( __CLASS__, 'ajax_import_chunk' ) );
		add_action( 'wp_ajax_isx_run', array( __CLASS__, 'ajax_run' ) );
		// A restore/import overwrites wp_users/wp_usermeta mid-job, which
		// invalidates the browser's current login session — WP's admin-ajax.php
		// then routes the *next* poll to wp_ajax_nopriv_* instead of wp_ajax_*
		// before our code ever runs. ajax_run() already authenticates with the
		// job's own on-disk secret (not the WP session) specifically for this
		// reason, but that only helps once the request reaches it, so the
		// nopriv hook needs to exist too — same pattern All-in-One WP Migration
		// uses for its own wp_ajax_nopriv_ai1wm_import / ai1wm_status, guarded
		// by their own per-job secret key the same way ajax_run() is here.
		add_action( 'wp_ajax_nopriv_isx_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_isx_import_decrypt', array( __CLASS__, 'ajax_import_decrypt' ) );
		// Cancelling authenticates on the job secret like isx_run, and needs the
		// nopriv hook for the same reason: an import can log the session out
		// mid-job, and that is exactly when someone reaches for "Cancel".
		add_action( 'wp_ajax_isx_job_cancel', array( __CLASS__, 'ajax_job_cancel' ) );
		add_action( 'wp_ajax_nopriv_isx_job_cancel', array( __CLASS__, 'ajax_job_cancel' ) );
		// Browser-side failures (the poll request never coming back) are invisible
		// server-side, so the JS beacons them here. nopriv for the same reason
		// isx_run has it — an import can log the session out mid-job.
		add_action( 'wp_ajax_isx_client_log', array( __CLASS__, 'ajax_client_log' ) );
		add_action( 'wp_ajax_nopriv_isx_client_log', array( __CLASS__, 'ajax_client_log' ) );
		add_action( 'wp_ajax_isx_list_tables', array( __CLASS__, 'ajax_list_tables' ) );
		add_action( 'wp_ajax_isx_download', array( __CLASS__, 'ajax_download' ) );

		add_action( 'wp_ajax_isx_log_poll', array( __CLASS__, 'ajax_log_poll' ) );

		add_action( 'wp_ajax_isx_backups_list', array( __CLASS__, 'ajax_backups_list' ) );
		add_action( 'wp_ajax_isx_backups_delete', array( __CLASS__, 'ajax_backups_delete' ) );
		add_action( 'wp_ajax_isx_backups_restore', array( __CLASS__, 'ajax_backups_restore' ) );
		add_action( 'wp_ajax_isx_backups_list_content', array( __CLASS__, 'ajax_backups_list_content' ) );
		add_action( 'wp_ajax_isx_backups_verify', array( __CLASS__, 'ajax_backups_verify' ) );

		add_action( 'wp_ajax_isx_storage_save', array( __CLASS__, 'ajax_storage_save' ) );
		add_action( 'wp_ajax_isx_storage_dir_save', array( __CLASS__, 'ajax_storage_dir_save' ) );
		add_action( 'wp_ajax_isx_storage_import_list', array( __CLASS__, 'ajax_storage_import_list' ) );
		add_action( 'wp_ajax_isx_storage_import_prepare', array( __CLASS__, 'ajax_storage_import_prepare' ) );

		add_action( 'wp_ajax_isx_schedule_save', array( __CLASS__, 'ajax_schedule_save' ) );
		add_action( 'wp_ajax_isx_cleanup_uploads', array( __CLASS__, 'ajax_cleanup_uploads' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval

		add_action( 'wp_ajax_isx_reset_run', array( __CLASS__, 'ajax_reset_run' ) );
		add_action( 'wp_ajax_isx_reset_password_reveal', array( __CLASS__, 'ajax_reset_password_reveal' ) );
	}

	/**
	 * WP only ships hourly/twicedaily/daily/weekly out of the box — add a
	 * monthly interval for the scheduled-backup option.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Monthly', 'insightx-backup' ),
			);
		}
		return $schedules;
	}

	/**
	 * The capability a screen/action really needs.
	 *
	 * On multisite, 'export'/'import'/'manage_options' belong to every
	 * sub-site admin, but an import writes PHP into the network-shared
	 * plugins/themes dirs and replaces shared tables, and Reset Hub deletes
	 * every network plugin — so everything here is super-admin only there.
	 *
	 * @param string $cap Capability on a single site.
	 * @return string
	 */
	private static function cap( $cap ) {
		return is_multisite() ? 'manage_network_options' : $cap;
	}

	public static function menu() {
		add_menu_page(
			'InsightX Backup',
			'InsightX Backup',
			self::cap( 'export' ),
			'isx_export',
			array( __CLASS__, 'page_export' ),
			'dashicons-database-export',
			76
		);
		add_submenu_page( 'isx_export', __( 'Export', 'insightx-backup' ), __( 'Export', 'insightx-backup' ), self::cap( 'export' ), 'isx_export', array( __CLASS__, 'page_export' ) );
		add_submenu_page( 'isx_export', __( 'Import', 'insightx-backup' ), __( 'Import', 'insightx-backup' ), self::cap( 'import' ), 'isx_import', array( __CLASS__, 'page_import' ) );
		$isx_backups_label = __( 'Backups', 'insightx-backup' );
		$isx_backup_count  = count( ISX_Backups::all() );
		if ( $isx_backup_count > 0 ) {
			$isx_backups_label .= sprintf(
				' <span class="update-plugins count-%1$d"><span class="plugin-count" aria-hidden="true">%1$d</span></span>',
				$isx_backup_count
			);
		}
		add_submenu_page( 'isx_export', __( 'Backups', 'insightx-backup' ), $isx_backups_label, self::cap( 'export' ), 'isx_backups', array( __CLASS__, 'page_backups' ) );
		add_submenu_page( 'isx_export', __( 'Connections', 'insightx-backup' ), __( 'Connections', 'insightx-backup' ), self::cap( 'export' ), 'isx_connections', array( __CLASS__, 'page_connections' ) );
		add_submenu_page( 'isx_export', __( 'Storage Settings', 'insightx-backup' ), __( 'Storage Settings', 'insightx-backup' ), self::cap( 'export' ), 'isx_settings', array( __CLASS__, 'page_settings' ) );
		// Stricter cap than the rest of this plugin ('export'/'import') — these
		// tools purge plugins/themes/media or wipe the database outright.
		add_submenu_page( 'isx_export', __( 'Reset Hub', 'insightx-backup' ), __( 'Reset Hub', 'insightx-backup' ), self::cap( 'manage_options' ), 'isx_reset_hub', array( __CLASS__, 'page_reset_hub' ) );
		add_submenu_page( 'isx_export', __( 'Log', 'insightx-backup' ), __( 'Log', 'insightx-backup' ), self::cap( 'export' ), 'isx_log', array( __CLASS__, 'page_log' ) );
	}

	/**
	 * Cache-busting version for an admin asset, tracking the file's own mtime on
	 * top of ISX_VERSION.
	 *
	 * ISX_VERSION alone only busts the browser cache when someone remembers to
	 * bump it, and a JS change shipped without that bump is invisible: the PHP
	 * views update (they're read from disk every request) while the browser keeps
	 * running yesterday's script against today's markup. That's exactly how the
	 * "Cancel" button ended up rendered by a 0.1.7 view with no 0.1.7 click
	 * handler behind it. These are admin-only assets, so the mtime differing
	 * between servers costs nothing.
	 *
	 * @param string $rel Plugin-relative asset path, e.g. 'assets/js/isx-admin.js'.
	 * @return string
	 */
	private static function asset_ver( $rel ) {
		$mtime = @filemtime( ISX_PATH . $rel );
		return $mtime ? ISX_VERSION . '.' . $mtime : ISX_VERSION;
	}

	public static function assets( $hook ) {
		$is_isx_page = strpos( $hook, 'isx_export' ) !== false
			|| strpos( $hook, 'isx_import' ) !== false
			|| strpos( $hook, 'isx_backups' ) !== false
			|| strpos( $hook, 'isx_connections' ) !== false
			|| strpos( $hook, 'isx_settings' ) !== false
			|| strpos( $hook, 'isx_log' ) !== false
			|| strpos( $hook, 'isx_reset_hub' ) !== false;

		if ( ! $is_isx_page ) {
			return;
		}

		// An import/restore overwrites wp_users & wp_usermeta mid-request, which
		// invalidates the current admin's session. WP's own Heartbeat API keeps
		// polling in the background on every admin page using that same session,
		// so its next tick fails auth and pops a "Your session has expired" modal
		// right over our own progress UI. Nothing to fix there — just don't let
		// Heartbeat run while an isx admin screen is open.
		wp_deregister_script( 'heartbeat' );

		wp_enqueue_style( 'isx-admin', ISX_URL . 'assets/css/isx-admin.css', array(), self::asset_ver( 'assets/css/isx-admin.css' ) );
		wp_enqueue_script( 'isx-admin', ISX_URL . 'assets/js/isx-admin.js', array( 'jquery' ), self::asset_ver( 'assets/js/isx-admin.js' ), true );
		wp_localize_script(
			'isx-admin',
			'isx',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'chunk_size'  => 4 * 1024 * 1024,
				// Gates the per-poll trace beacons only — failures are always
				// reported, so nothing is lost when this is off. Without the
				// gate the beacons would double the request rate of every poll
				// loop on every site, for logs nobody is collecting.
				'verbose'     => ISX_Logger::is_verbose(),
				// Lets downloadUrl() in isx-admin.js build a direct, PHP-free
				// link the moment an export finishes — null when the storage
				// path lives outside the web root, where admin-ajax.php's
				// streamed download is the only way to reach it.
				'backups_url' => ISX_Backups::base_url(),
				'i18n'        => self::js_i18n(),
			)
		);

		wp_enqueue_script( 'isx-storage', ISX_URL . 'assets/js/isx-storage.js', array( 'jquery', 'isx-admin' ), self::asset_ver( 'assets/js/isx-storage.js' ), true );
		wp_localize_script(
			'isx-storage',
			'isx_storage',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'providers' => ISX_Destinations::js_data(),
			)
		);

		if ( strpos( $hook, 'isx_reset_hub' ) !== false ) {
			wp_enqueue_script( 'isx-reset', ISX_URL . 'assets/js/isx-reset.js', array( 'jquery', 'isx-admin' ), self::asset_ver( 'assets/js/isx-reset.js' ), true );
		}
	}

	/**
	 * Every UI string the admin scripts show, keyed by its English source, for
	 * t() in isx-admin.js. Must list each t('...') literal used in assets/js —
	 * tests/run.php fails if one is missing.
	 *
	 * @return array<string, string>
	 */
	private static function js_i18n() {
		return array(
			'The server dropped the connection midway (HTTP %s) — the job took longer than the server allows (see the Log page)' => __( 'The server dropped the connection midway (HTTP %s) — the job took longer than the server allows (see the Log page)', 'insightx-backup' ),
			'Session expired (HTTP 403) — please refresh this page and start again' => __( 'Session expired (HTTP 403) — please refresh this page and start again', 'insightx-backup' ),
			'Server error (HTTP 500) — see the Log page for details' => __( 'Server error (HTTP 500) — see the Log page for details', 'insightx-backup' ),
			'Cannot reach the server — the connection dropped or the server is not responding' => __( 'Cannot reach the server — the connection dropped or the server is not responding', 'insightx-backup' ),
			'Connection failed %1$s times in a row (HTTP %2$s) — see the Log page' => __( 'Connection failed %1$s times in a row (HTTP %2$s) — see the Log page', 'insightx-backup' ),
			'Connection failed' => __( 'Connection failed', 'insightx-backup' ),
			'Could not cancel' => __( 'Could not cancel', 'insightx-backup' ),
			'Please enter the password' => __( 'Please enter the password', 'insightx-backup' ),
			'Import cancelled' => __( 'Import cancelled', 'insightx-backup' ),
			'Incorrect password' => __( 'Incorrect password', 'insightx-backup' ),
			'An error occurred' => __( 'An error occurred', 'insightx-backup' ),
			'Poll succeeded' => __( 'Poll succeeded', 'insightx-backup' ),
			'Poll failed (connection failed)' => __( 'Poll failed (connection failed)', 'insightx-backup' ),
			'Stopped polling: too many consecutive failures' => __( 'Stopped polling: too many consecutive failures', 'insightx-backup' ),
			' — stopped waiting after %s retries. The job may still be running on the server. Refresh this page to see the latest status' => __( ' — stopped waiting after %s retries. The job may still be running on the server. Refresh this page to see the latest status', 'insightx-backup' ),
			'Please enter an encryption password' => __( 'Please enter an encryption password', 'insightx-backup' ),
			'Passwords do not match' => __( 'Passwords do not match', 'insightx-backup' ),
			'Remove this row' => __( 'Remove this row', 'insightx-backup' ),
			'%s tables selected' => __( '%s tables selected', 'insightx-backup' ),
			'No tables selected' => __( 'No tables selected', 'insightx-backup' ),
			'Could not load the table list' => __( 'Could not load the table list', 'insightx-backup' ),
			'%s rows' => __( '%s rows', 'insightx-backup' ),
			'No tables found' => __( 'No tables found', 'insightx-backup' ),
			'%s items selected' => __( '%s items selected', 'insightx-backup' ),
			'No files selected' => __( 'No files selected', 'insightx-backup' ),
			'Could not start' => __( 'Could not start', 'insightx-backup' ),
			'Could not connect to the server' => __( 'Could not connect to the server', 'insightx-backup' ),
			'%1$s — retrying (%2$s/%3$s)...' => __( '%1$s — retrying (%2$s/%3$s)...', 'insightx-backup' ),
			'Upload failed' => __( 'Upload failed', 'insightx-backup' ),
			'Uploading...' => __( 'Uploading...', 'insightx-backup' ),
			'Preparing' => __( 'Preparing', 'insightx-backup' ),
			'Exporting database' => __( 'Exporting database', 'insightx-backup' ),
			'Packing files' => __( 'Packing files', 'insightx-backup' ),
			'File created' => __( 'File created', 'insightx-backup' ),
			'Uploading to Storage' => __( 'Uploading to Storage', 'insightx-backup' ),
			'Uploading file' => __( 'Uploading file', 'insightx-backup' ),
			'Verifying package' => __( 'Verifying package', 'insightx-backup' ),
			'Clearing existing files' => __( 'Clearing existing files', 'insightx-backup' ),
			'Restoring files' => __( 'Restoring files', 'insightx-backup' ),
			'Importing database' => __( 'Importing database', 'insightx-backup' ),
			'Finalizing' => __( 'Finalizing', 'insightx-backup' ),
			'%1$s hr %2$s min' => __( '%1$s hr %2$s min', 'insightx-backup' ),
			'%1$s min %2$s sec' => __( '%1$s min %2$s sec', 'insightx-backup' ),
			'%s sec' => __( '%s sec', 'insightx-backup' ),
			'Elapsed: %s' => __( 'Elapsed: %s', 'insightx-backup' ),
			'No running job found on this page — please refresh to see the latest status' => __( 'No running job found on this page — please refresh to see the latest status', 'insightx-backup' ),
			'Cancel clicked but there is no activeJob' => __( 'Cancel clicked but there is no activeJob', 'insightx-backup' ),
			'Cancel the export?' => __( 'Cancel the export?', 'insightx-backup' ),
			'Cancelled' => __( 'Cancelled', 'insightx-backup' ),
			'Please choose a .wpress file' => __( 'Please choose a .wpress file', 'insightx-backup' ),
			'Importing will overwrite the entire current site. Continue?' => __( 'Importing will overwrite the entire current site. Continue?', 'insightx-backup' ),
			'Done' => __( 'Done', 'insightx-backup' ),
			'Upload failed, please try again' => __( 'Upload failed, please try again', 'insightx-backup' ),
			'This provider is not configured yet — go to the "Connections" menu first' => __( 'This provider is not configured yet — go to the "Connections" menu first', 'insightx-backup' ),
			'Downloading from Storage...' => __( 'Downloading from Storage...', 'insightx-backup' ),
			'Download failed' => __( 'Download failed', 'insightx-backup' ),
			'Loading list...' => __( 'Loading list...', 'insightx-backup' ),
			'Could not load the list' => __( 'Could not load the list', 'insightx-backup' ),
			'No .wpress files found in this bucket' => __( 'No .wpress files found in this bucket', 'insightx-backup' ),
			'Import' => __( 'Import', 'insightx-backup' ),
			'Importing this file will overwrite the current site. Continue?' => __( 'Importing this file will overwrite the current site. Continue?', 'insightx-backup' ),
			'Choose a provider from the "Import from" menu first' => __( 'Choose a provider from the "Import from" menu first', 'insightx-backup' ),
			'Testing connection...' => __( 'Testing connection...', 'insightx-backup' ),
			'Saved' => __( 'Saved', 'insightx-backup' ),
			'Could not save' => __( 'Could not save', 'insightx-backup' ),
			'Saving...' => __( 'Saving...', 'insightx-backup' ),
			'Checking...' => __( 'Checking...', 'insightx-backup' ),
			'Cleanup failed' => __( 'Cleanup failed', 'insightx-backup' ),
			'Cleanup failed — see the Log page for details' => __( 'Cleanup failed — see the Log page for details', 'insightx-backup' ),
			'Delete this backup?' => __( 'Delete this backup?', 'insightx-backup' ),
			'No backups yet' => __( 'No backups yet', 'insightx-backup' ),
			'Could not delete' => __( 'Could not delete', 'insightx-backup' ),
			'%s files' => __( '%s files', 'insightx-backup' ),
			'Showing %1$s of %2$s files in this folder' => __( 'Showing %1$s of %2$s files in this folder', 'insightx-backup' ),
			'No files in this folder' => __( 'No files in this folder', 'insightx-backup' ),
			'Reading the package contents is not progressing — the package file may be corrupted' => __( 'Reading the package contents is not progressing — the package file may be corrupted', 'insightx-backup' ),
			'Could not load the list (HTTP %s) — the server may have run out of memory or time' => __( 'Could not load the list (HTTP %s) — the server may have run out of memory or time', 'insightx-backup' ),
			'Could not load the list — cannot reach the server' => __( 'Could not load the list — cannot reach the server', 'insightx-backup' ),
			'Verification failed' => __( 'Verification failed', 'insightx-backup' ),
			'Verification passed' => __( 'Verification passed', 'insightx-backup' ),
			'Verification did not pass' => __( 'Verification did not pass', 'insightx-backup' ),
			'Verification failed (HTTP %s) — the server returned an error, see the Log page for details' => __( 'Verification failed (HTTP %s) — the server returned an error, see the Log page for details', 'insightx-backup' ),
			'Verification failed — cannot reach the server' => __( 'Verification failed — cannot reach the server', 'insightx-backup' ),
			'Reading package contents...' => __( 'Reading package contents...', 'insightx-backup' ),
			'No files found in this package' => __( 'No files found in this package', 'insightx-backup' ),
			'Total: %s' => __( 'Total: %s', 'insightx-backup' ),
			'Reading...' => __( 'Reading...', 'insightx-backup' ),
			'All plugins except InsightX Backup will be deactivated and deleted. This cannot be undone.' => __( 'All plugins except InsightX Backup will be deactivated and deleted. This cannot be undone.', 'insightx-backup' ),
			'All themes will be deleted and the default theme activated. This cannot be undone.' => __( 'All themes will be deleted and the default theme activated. This cannot be undone.', 'insightx-backup' ),
			'Every file in the media library will be deleted. This cannot be undone.' => __( 'Every file in the media library will be deleted. This cannot be undone.', 'insightx-backup' ),
			'Everything in the database will be permanently deleted and the site returned to its initial state. This cannot be undone.' => __( 'Everything in the database will be permanently deleted and the site returned to its initial state. This cannot be undone.', 'insightx-backup' ),
			'The entire site (plugins, themes, media and database) will be reset to its initial state. This cannot be undone.' => __( 'The entire site (plugins, themes, media and database) will be reset to its initial state. This cannot be undone.', 'insightx-backup' ),
			"Database reset complete\n\nAdmin account: %1\$s\nNew password: %2\$s\n\nSave this password now — it is shown only once. This page will now reload" => __( "Database reset complete\n\nAdmin account: %1\$s\nNew password: %2\$s\n\nSave this password now — it is shown only once. This page will now reload", 'insightx-backup' ),
			'Database reset complete, but the new password could not be retrieved (%s) — reset the password from the login screen (Lost your password) or WP-CLI' => __( 'Database reset complete, but the new password could not be retrieved (%s) — reset the password from the login screen (Lost your password) or WP-CLI', 'insightx-backup' ),
			'Token expired' => __( 'Token expired', 'insightx-backup' ),
			'Database reset complete, but the new password could not be retrieved — reset the password from the login screen (Lost your password) or WP-CLI' => __( 'Database reset complete, but the new password could not be retrieved — reset the password from the login screen (Lost your password) or WP-CLI', 'insightx-backup' ),
			'Creating backup...' => __( 'Creating backup...', 'insightx-backup' ),
			'Could not create the backup' => __( 'Could not create the backup', 'insightx-backup' ),
			'Backup created (%s)' => __( 'Backup created (%s)', 'insightx-backup' ),
			'Backup created' => __( 'Backup created', 'insightx-backup' ),
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

	public static function page_connections() {
		require ISX_PATH . 'views/connections.php';
	}

	public static function page_settings() {
		require ISX_PATH . 'views/settings.php';
	}

	public static function page_log() {
		if ( isset( $_POST['isx_log_clear'] ) && check_admin_referer( 'isx_log_clear' ) && current_user_can( 'export' ) ) {
			ISX_Logger::clear();
			wp_safe_redirect( admin_url( 'admin.php?page=isx_log&cleared=1' ) );
			exit;
		}

		if ( isset( $_POST['isx_log_verbose_save'] ) && check_admin_referer( 'isx_log_verbose' ) && current_user_can( 'export' ) ) {
			ISX_Logger::set_verbose( ! empty( $_POST['isx_verbose'] ) );
			wp_safe_redirect( admin_url( 'admin.php?page=isx_log&verbose_saved=1' ) );
			exit;
		}

		// Served as a download because a full verbose trace is thousands of
		// lines — far past what's practical to select out of the page.
		if ( isset( $_POST['isx_log_download'] ) && check_admin_referer( 'isx_log_download' ) && current_user_can( 'export' ) ) {
			$filename = 'isx-log-' . gmdate( 'Ymd-His' ) . '.txt';
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			echo self::log_system_info() . "\n" . ISX_Logger::as_text(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text download
			exit;
		}

		require ISX_PATH . 'views/log.php';
	}

	/**
	 * Environment facts that change how the pipeline behaves, gathered in one
	 * place so a log handed over for support arrives with its context.
	 *
	 * The Cloudflare check matters most: these headers are added by Cloudflare
	 * itself on requests it forwards, so their presence is direct proof the
	 * site sits behind it — which makes its edge timeout a candidate for any
	 * long-running request that dies part-way.
	 *
	 * @return string
	 */
	public static function log_system_info() {
		$curl = function_exists( 'curl_version' ) ? curl_version() : array();

		$lines = array(
			'plugin          : InsightX Backup ' . ISX_VERSION,
			'wordpress       : ' . get_bloginfo( 'version' ),
			'php             : ' . PHP_VERSION,
			'curl            : ' . ( isset( $curl['version'] ) ? $curl['version'] : __( 'none', 'insightx-backup' ) ),
			'ssl             : ' . ( isset( $curl['ssl_version'] ) ? $curl['ssl_version'] : '-' ),
			'max_execution   : ' . ini_get( 'max_execution_time' ),
			'memory_limit    : ' . ini_get( 'memory_limit' ),
			'site_url        : ' . site_url(),
			'verbose_log     : ' . ( ISX_Logger::is_verbose() ? __( 'on', 'insightx-backup' ) : __( 'off', 'insightx-backup' ) ),
		);

		$behind_cf = ! empty( $_SERVER['HTTP_CF_RAY'] ) || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$lines[]   = 'cloudflare      : ' . ( $behind_cf ? __( 'yes (CF header found)', 'insightx-backup' ) : __( 'no CF header found', 'insightx-backup' ) );
		if ( $behind_cf && ! empty( $_SERVER['HTTP_CF_RAY'] ) ) {
			$lines[] = 'cf_ray          : ' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_RAY'] ) );
		}

		return implode( "\n", $lines );
	}

	public static function page_reset_hub() {
		require ISX_PATH . 'views/reset-hub.php';
	}

	/* ---------------- Export / Import pipeline AJAX ---------------- */

	/**
	 * Keep buckets free of multipart uploads that no job can finish any more.
	 *
	 * The registry pass is cheap and immediate — it only calls out when there is
	 * a stranded upload of this site's own to release — so it runs on every
	 * admin request. The listing pass costs one request per configured provider
	 * and exists for uploads with no registry entry (left by an older version,
	 * or by another site sharing the bucket folder), so it is throttled to once
	 * a day.
	 */
	public static function maybe_sweep_uploads() {
		if ( ! current_user_can( 'export' ) ) {
			return;
		}

		$include_foreign = get_transient( 'isx_upload_sweep_done' ) === false;
		if ( $include_foreign ) {
			set_transient( 'isx_upload_sweep_done', 1, DAY_IN_SECONDS );
		}

		ISX_Export::sweep_orphaned_uploads( $include_foreign );
	}

	public static function ajax_export_start() {
		self::guard( 'export' );

		// Release anything a previous export left pending on a bucket before
		// starting another one. Registry-only (no listing call), so this costs
		// nothing unless there is actually something stranded to clean up.
		ISX_Export::sweep_orphaned_uploads();

		$job = ISX_Job::create( 'export' );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Could not create the job. See the Log page for details', 'insightx-backup' ) ) );
		}

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
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Could not create the job. See the Log page for details', 'insightx-backup' ) ) );
		}
		// Creating the (empty) target up front is also the earliest cheap proof
		// that the storage directory is actually writable — better to say so now
		// than after the browser has spent an hour uploading chunks into it.
		if ( false === @file_put_contents( $job->archive(), '' ) ) {
			$job->cleanup();
			ISX_Logger::log_error(
				'import',
				__( 'Could not create the upload destination file', 'insightx-backup' ),
				array( 'job' => $job->id(), 'free_space' => @disk_free_space( dirname( $job->archive() ) ) )
			);
			wp_send_json_error( array( 'message' => __( 'Could not create the file to receive data — check write permissions and free space on the server', 'insightx-backup' ) ) );
		}
		wp_send_json_success( array( 'job' => $job->id(), 'secret' => $job->get( 'secret' ) ) );
	}

	public static function ajax_import_chunk() {
		self::guard( 'import' );
		$job_id = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		$job    = ISX_Job::load( $job_id );
		if ( ! $job || $job->get( 'type' ) !== 'import' ) {
			ISX_Logger::log_error( 'import', __( 'Invalid job (chunk upload)', 'insightx-backup' ), array( 'job' => $job_id ) );
			wp_send_json_error( array( 'message' => __( 'Invalid job', 'insightx-backup' ) ) );
		}
		if ( empty( $_FILES['chunk']['tmp_name'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) {
			// Common causes: upload_max_filesize/post_max_size smaller than the
			// chunk size, or the request hit a proxy/host body-size limit.
			ISX_Logger::log_error( 'import', __( 'No chunk data found', 'insightx-backup' ), array( 'job' => $job_id ) );
			wp_send_json_error( array( 'message' => __( 'No chunk data found', 'insightx-backup' ) ) );
		}

		// A chunk whose response was lost (timeout, proxy reset) is retried by
		// the browser; plain appending wrote it twice and the package then
		// failed verification after the whole upload. With its offset known,
		// the archive is cut back to where this chunk starts first.
		if ( isset( $_POST['offset'] ) ) {
			$offset = max( 0, (int) $_POST['offset'] );
			clearstatcache( true, $job->archive() );
			$size = is_file( $job->archive() ) ? (int) filesize( $job->archive() ) : 0;
			if ( $size < $offset ) {
				ISX_Logger::log_error( 'import', __( 'Uploaded chunk does not follow the previous one', 'insightx-backup' ), array( 'job' => $job->id(), 'size' => $size, 'offset' => $offset ) );
				wp_send_json_error( array( 'message' => __( 'Part of the upload is missing — please start the import again', 'insightx-backup' ) ) );
			}
			if ( $size > $offset ) {
				$fh = fopen( $job->archive(), 'c+b' );
				if ( $fh === false || ! ftruncate( $fh, $offset ) ) {
					wp_send_json_error( array( 'message' => __( 'Could not write the uploaded file — the server disk may be full. Please check free space and try again', 'insightx-backup' ) ) );
				}
				fclose( $fh );
			}
		}

		$out = fopen( $job->archive(), 'ab' );
		$in  = fopen( $_FILES['chunk']['tmp_name'], 'rb' );
		// This is where the uploaded package's bytes actually land, so it's the
		// most likely place for a full disk to bite on an import — and the
		// consequence used to be invisible: every chunk reported "received",
		// then extraction failed much later with "Invalid package file", which
		// points at the file rather than at the disk that truncated it.
		$ok = ( $out !== false && $in !== false );
		if ( $ok ) {
			while ( ! feof( $in ) ) {
				$buf = fread( $in, 1048576 );
				if ( $buf === false ) {
					break;
				}
				if ( @fwrite( $out, $buf ) !== strlen( $buf ) ) {
					$ok = false;
					break;
				}
			}
		}
		if ( $in !== false ) {
			fclose( $in );
		}
		if ( $out !== false && ! fclose( $out ) ) {
			$ok = false;
		}

		if ( ! $ok ) {
			$message = __( 'Could not write the uploaded file — the server disk may be full. Please check free space and try again', 'insightx-backup' );
			ISX_Logger::log_error(
				'import',
				$message,
				array(
					'job'        => $job->id(),
					'free_space' => @disk_free_space( dirname( $job->archive() ) ),
				)
			);
			wp_send_json_error( array( 'message' => $message ) );
		}

		wp_send_json_success( array( 'received' => true ) );
	}

	public static function ajax_run() {
		$job_id = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		$job    = ISX_Job::load( $job_id );
		if ( ! $job ) {
			// Job dir/state.json missing or unreadable — mid-run causes seen in
			// practice: disk full, PHP-FPM/host killed the process while
			// state.json was mid-write, or someone changed the storage path
			// (Settings → Storage Settings) while a job was still in flight.
			ISX_Logger::log_error(
				'system',
				__( 'Job not found', 'insightx-backup' ),
				array(
					'job'      => $job_id,
					'searched' => implode( ', ', ISX_Job::search_paths() ),
				)
			);
			wp_send_json_error( array( 'message' => __( 'Job not found', 'insightx-backup' ) ) );
		}

		// Authenticate against the on-disk per-job secret rather than the WP
		// session: an import rewrites wp_users/wp_options and would otherwise
		// break auth partway through the poll loop.
		$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
		if ( ! hash_equals( (string) $job->get( 'secret' ), $secret ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid secret', 'insightx-backup' ) ) );
		}

		// This request is itself a loopback successor — clear the throttle so
		// that when its step finishes, spawn_loopback() is free to chain the
		// next one (otherwise the self-driving chain would stall on its own
		// throttle). See spawn_loopback().
		if ( isset( $_POST['isx_lb'] ) ) {
			delete_transient( 'isx_lb_' . $job->id() );
			// Pairs with the "Firing loopback" entry in spawn_loopback(). Spawns
			// without matching arrivals mean the site can't reach itself over
			// HTTP — a firewall/WAF/reverse proxy in front of the domain eating
			// the self-request is the usual cause, and it's invisible otherwise.
			ISX_Logger::log_debug( 'system', __( 'Loopback arrived', 'insightx-backup' ), array( 'job' => $job->id() ) );
		}

		$result = self::run_step( $job );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Stop a running job at the user's request.
	 *
	 * Ending the job here rather than just closing the tab matters for an
	 * upload: walking away leaves parts in the bucket as an "ongoing multipart
	 * upload" that no object browser can delete, and the loopback/cron chain
	 * would keep re-firing the job in the meantime. Marking it done stops both.
	 *
	 * The flag goes down first, and the job is only *finished* while holding the
	 * lock. Writing the finished state straight to disk from here used to look
	 * like it worked and didn't: a step running concurrently holds the lock, and
	 * writes its own in-memory state back over state.json when it returns, so the
	 * job simply carried on — and finish()'s scratch-file sweep was meanwhile
	 * deleting the archive out from under that still-running step. When the lock
	 * is busy the flag alone is enough: run_step() honours it and finishes the
	 * job itself, from inside the lock.
	 */
	public static function ajax_job_cancel() {
		$job_id = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		$job    = ISX_Job::load( $job_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Job not found', 'insightx-backup' ) ) );
		}

		// Same on-disk secret as ajax_run(), for the same reason: a restore can
		// invalidate the WP session partway through the run.
		$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
		if ( ! hash_equals( (string) $job->get( 'secret' ), $secret ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid secret', 'insightx-backup' ) ) );
		}

		$message = __( 'Cancelled by user', 'insightx-backup' );

		if ( $job->get( 'step' ) === 'done' ) {
			// Already finished between the click and this request — don't
			// rewrite the outcome it reported.
			wp_send_json_success(
				array(
					'progress' => 100,
					'done'     => true,
					'message'  => (string) $job->get( 'last_message', $message ),
				)
			);
		}

		$job->request_cancel();

		$finished = $job->with_lock(
			function ( ISX_Job $locked_job ) {
				if ( $locked_job->get( 'step' ) === 'done' ) {
					return true;
				}
				self::finish_cancelled(
					$locked_job,
					(string) $locked_job->get( 'type', 'export' ),
					(string) $locked_job->get( 'step', '' )
				);
				return true;
			}
		);

		// Lock busy: a step is mid-run and owns the job's files. It checks the
		// flag on the way out and finishes the job there, so the poll loop still
		// lands on a done job — just a beat later.
		if ( $finished === false ) {
			ISX_Logger::log_debug(
				(string) $job->get( 'type', 'export' ),
				__( 'Cancel: job is running, waiting for the current step to finish', 'insightx-backup' ),
				array( 'job' => $job->id() )
			);
		}

		// The flag, not the (translated) message text, tells the client this
		// ended as a cancel rather than a job that finished on its own.
		wp_send_json_success(
			array(
				'progress'  => 100,
				'done'      => true,
				'cancelled' => true,
				'message'   => $message,
			)
		);
	}

	/**
	 * Record a browser-side event in the same log as everything else.
	 *
	 * The failure users actually report — the progress bar stopping with
	 * "Connection failed" — happens when a poll request never comes back, so
	 * the server never runs a line of code for it and the log stays empty. The
	 * XHR status the browser saw is the one piece of evidence that identifies
	 * the culprit: 522/524/520 is a reverse proxy giving up on a slow origin,
	 * 403 (or a body mentioning Cloudflare) is a firewall rule, 0 is the
	 * connection dropped outright, 502/504 is the origin/gateway itself.
	 *
	 * Deliberately permissive: no capability check (an import can log the user
	 * out mid-job) and it never fails loudly, since this only ever writes a log
	 * line and refusing one would defeat the point.
	 */
	public static function ajax_client_log() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$level   = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : 'debug';
		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		$context = array();

		if ( isset( $_POST['context'] ) && is_array( $_POST['context'] ) ) {
			foreach ( wp_unslash( $_POST['context'] ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value sanitised below
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$context[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		$context['from'] = 'browser';

		if ( $level === 'error' ) {
			ISX_Logger::log_error( 'client', $message, $context );
		} elseif ( $level === 'warn' ) {
			ISX_Logger::log_warn( 'client', $message, $context );
		} else {
			ISX_Logger::log_debug( 'client', $message, $context );
		}

		wp_send_json_success();
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
	 * Raise this request's PHP memory limit for the heavy backup/restore work,
	 * but never lower an already-higher (or unlimited) limit. A wide row (a
	 * multi-MB wp_options value, a long post_content) held inside a get_results
	 * batch, or a large file buffered during archive I/O, can otherwise OOM the
	 * request on hosts left at a low default — one of the "large backups fail"
	 * causes. Filterable via isx_memory_limit.
	 *
	 * @return void
	 */
	private static function raise_memory_limit() {
		$current = trim( (string) ini_get( 'memory_limit' ) );
		if ( $current === '-1' ) {
			return; // Already unlimited.
		}
		$target = (string) apply_filters( 'isx_memory_limit', '256M' );
		if ( wp_convert_hr_to_bytes( $target ) > wp_convert_hr_to_bytes( $current ) ) {
			@ini_set( 'memory_limit', $target ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
		}
	}

	/**
	 * Turn a fatal error into a JSON answer instead of a dead response.
	 *
	 * A fatal (or an OOM, or a hit execution limit) ends the request mid-stream:
	 * admin-ajax replies 500 with an HTML body, jQuery's parser fails, and every
	 * one of these screens can only report "the server can't be reached" — which
	 * points the reader at their network when the real cause is a named error on
	 * a named line. This has cost real debugging time twice now (see the 0.1.16
	 * and 0.1.20 entries in readme.txt), so the endpoints that walk a multi-GB
	 * package say what actually happened.
	 *
	 * Only registers once per request, and stays quiet when the response already
	 * went out (the normal path: wp_send_json_* sends headers, then exits).
	 *
	 * @param string $context Short label naming the operation, shown to the user.
	 * @return void
	 */
	private static function report_fatals_as_json( $context ) {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		register_shutdown_function(
			function () use ( $context ) {
				$error = error_get_last();
				if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}
				// Something already reached the browser — either the successful
				// response, or PHP's own error output with display_errors on.
				// Appending JSON to that would only corrupt it further.
				if ( headers_sent() ) {
					return;
				}

				$where = basename( (string) $error['file'] ) . ':' . (int) $error['line'];
				ISX_Logger::log_error(
					'backup',
					$context . __( 'failed midway: ', 'insightx-backup' ) . $error['message'],
					array( 'file' => $error['file'], 'line' => (int) $error['line'] )
				);
				wp_send_json_error(
					array(
						'message' => sprintf(
							__( '%sfailed midway: %s (%s) — see the Log page for details', 'insightx-backup' ),
							$context,
							$error['message'],
							$where
						),
					)
				);
			}
		);
	}

	/**
	 * End a job that the user asked to cancel, and describe it the way the poll
	 * loop expects. Only ever called from inside with_lock(): releasing a pending
	 * multipart upload and letting finish() sweep the scratch files is only safe
	 * once no step can still be writing to them.
	 *
	 * @param ISX_Job $job  Job, already locked.
	 * @param string  $type 'export' | 'import'.
	 * @param string  $step Step the job was on when it was stopped.
	 * @return array
	 */
	private static function finish_cancelled( ISX_Job $job, $type, $step ) {
		$message = __( 'Cancelled by user', 'insightx-backup' );

		if ( $type !== 'import' ) {
			ISX_Export::abort_pending_upload( $job );
		}
		$job->finish( $message, true );

		ISX_Logger::log_warn(
			$type !== '' ? $type : 'export',
			__( 'Stopped by cancel request', 'insightx-backup' ),
			array(
				'job'  => $job->id(),
				'step' => $step,
			)
		);

		return array(
			'progress'       => 100,
			'done'           => true,
			'error'          => true,
			'message'        => $message,
			'phase'          => $step,
			'phase_progress' => 100,
		);
	}

	/**
	 * Execute exactly one pipeline step under an exclusive per-job lock, and
	 * annotate the result with elapsed time and phase progress. If another
	 * driver (the other of "browser poll" / "cron tick") currently holds the
	 * lock, returns the job's last known progress instead of blocking, so the
	 * UI keeps showing motion rather than appearing to freeze.
	 *
	 * @param ISX_Job $job
	 * @return array
	 */
	private static function run_step( ISX_Job $job ) {
		@set_time_limit( 0 );
		self::raise_memory_limit();

		$result = $job->with_lock(
			function ( ISX_Job $locked_job ) {
				if ( $locked_job->get( 'step' ) === 'done' ) {
					$done_result = array(
						'progress'       => 100,
						'done'           => true,
						'error'          => (bool) $locked_job->get( 'last_error', false ),
						'message'        => (string) $locked_job->get( 'last_message', __( 'Done', 'insightx-backup' ) ),
						'phase'          => 'finalize',
						'phase_progress' => 100,
					);

					// A poll landing after another driver (loopback/cron) already
					// finished the job would otherwise miss 'backup', leaving the
					// download button's href stuck at '#'. Read it back here too.
					$backup_name = (string) $locked_job->get( 'backup_name', '' );
					if ( $backup_name !== '' ) {
						$done_result['backup'] = $backup_name;
						$archive_size          = (int) $locked_job->get( 'archive_size', 0 );
						if ( $archive_size > 0 ) {
							$done_result['size'] = size_format( $archive_size );
						}
					}

					return $done_result;
				}

				$type        = (string) $locked_job->get( 'type' );
				$step_before = (string) $locked_job->get( 'step', 'init' );

				// Someone hit "Cancel" while this job was between steps (or while a
				// previous step held the lock, in which case ajax_job_cancel() left
				// the flag for whoever got here first). Finishing it here — inside
				// the lock, before any new work starts — is the only place that can
				// release a pending upload and sweep the scratch files without
				// racing a step that still has them open.
				if ( $locked_job->is_cancel_requested() ) {
					return self::finish_cancelled( $locked_job, $type, $step_before );
				}

				$started = microtime( true );
				$result = ( $type === 'import' ) ? ISX_Import::run( $locked_job ) : ISX_Export::run( $locked_job );
				$took   = round( microtime( true ) - $started, 2 );

				// Every step is supposed to return within STEP_TIME_BUDGET (~10s)
				// so no single HTTP request runs long. One that badly overshoots
				// is the request a proxy's edge timeout would cut off — which is
				// exactly what "Connection failed" looks like from the browser.
				ISX_Logger::log_debug(
					$type,
					__( 'Step finished', 'insightx-backup' ),
					array(
						'job'        => $locked_job->id(),
						'step'       => $step_before,
						'step_after' => (string) $locked_job->get( 'step', '' ),
						'took'       => $took,
						'progress'   => isset( $result['progress'] ) ? (int) $result['progress'] : null,
						'msg'        => isset( $result['message'] ) ? $result['message'] : '',
					)
				);

				// Cancelled while this step was running — but only if the step is
				// still mid-pipeline. A step like finalize() is atomic: it packs the
				// archive, saves it into Backups, and calls $job->finish() itself
				// as its very last action, all within this one call, uninterrupted.
				// If the cancel flag happened to land at that exact moment, the job
				// is already 'done' with a real outcome (success or its own genuine
				// failure) by the time we get here — overwriting that with "Cancel"
				// would relabel a finished export as cancelled while leaving the
				// backup file it already wrote sitting right there in the list,
				// which is more confusing than the flag arriving one poll too late.
				if ( $locked_job->get( 'step' ) !== 'done' && $locked_job->is_cancel_requested() ) {
					return self::finish_cancelled( $locked_job, $type, $step_before );
				}

				// Annotate with which pipeline phase this tick's work belongs to
				// (the step that was current *before* run() advanced it) and how
				// far along within just that phase, so the UI can show each
				// phase as its own 0-100% bar instead of one bar for the whole job.
				$range           = self::phase_range( $type, $step_before, $locked_job );
				$progress_val    = isset( $result['progress'] ) ? (float) $result['progress'] : $range[1];
				$span            = max( 1, $range[1] - $range[0] );
				$result['phase'] = $step_before;
				// Two decimals, not a whole number: a phase like 'files' can spend
				// minutes inside a single integer percent on a big site, and a bar
				// reading a flat "18%" the whole time looks wedged. The extra digits
				// are real movement, not decoration — the underlying figure is a
				// float ratio, it was only ever the display that rounded it away.
				$result['phase_progress'] = round( max( 0, min( 100, ( $progress_val - $range[0] ) / $span * 100 ) ), 2 );

				if ( ! empty( $result['error'] ) ) {
					ISX_Logger::log_error(
						$type,
						isset( $result['message'] ) ? $result['message'] : __( 'An unknown error occurred', 'insightx-backup' ),
						array(
							'job'      => $locked_job->id(),
							'step'     => $step_before,
							'took'     => $took,
							'progress' => isset( $result['progress'] ) ? (int) $result['progress'] : null,
							'elapsed'  => max( 0, time() - (int) $locked_job->get( 'created', time() ) ),
						)
					);
				}

				// Some early-failure paths still delete the job dir outright
				// (see cleanup() call sites) — only persist the "last known"
				// breadcrumb fields when it's still there.
				if ( is_dir( $locked_job->dir() ) ) {
					$locked_job->set( 'last_message', isset( $result['message'] ) ? $result['message'] : '' );
					$locked_job->set( 'last_progress', isset( $result['progress'] ) ? $result['progress'] : 0 );
					$locked_job->set( 'last_phase', $result['phase'] );
					$locked_job->set( 'last_phase_progress', $result['phase_progress'] );

					// Heartbeat: proof that a step actually executed, which is all
					// this branch can mean — it is only reachable by the driver
					// holding the lock, right after run() returned.
					//
					// It deliberately does NOT require progress to have increased.
					// A step can do real work without moving the integer bar (a
					// multipart part is ~1% of the upload phase, which is 3% of
					// the job), and the watchdog below used to kill exactly those:
					// a long upload reported 97 every tick, so after 5 minutes it
					// was declared wedged mid-transfer.
					$locked_job->set( 'heartbeat', time() );
					$locked_job->set( 'heartbeat_progress', isset( $result['progress'] ) ? (int) $result['progress'] : 0 );

					$locked_job->save();
				}

				return $result;
			}
		);

		$lock_skipped = ( $result === false );

		if ( $lock_skipped ) {
			// Lock held by the other driver right now — echo the last known
			// state instead of executing (and definitely instead of double-running).
			// Logged because a run of nothing *but* these means every driver is
			// bouncing off a lock nobody is releasing.
			ISX_Logger::log_debug(
				(string) $job->get( 'type', 'export' ),
				__( 'Skipped step — lock held by another driver', 'insightx-backup' ),
				array(
					'job'   => $job->id(),
					'phase' => (string) $job->get( 'last_phase', '' ),
				)
			);
			$result = array(
				'progress'       => (int) $job->get( 'last_progress', $job->get( 'progress', 0 ) ),
				'done'           => false,
				'message'        => (string) $job->get( 'last_message', __( 'Working...', 'insightx-backup' ) ),
				'phase'          => (string) $job->get( 'last_phase', 'init' ),
				'phase_progress' => (float) $job->get( 'last_phase_progress', 0 ),
			);
		}

		$created = (int) $job->get( 'created', time() );
		$elapsed = max( 0, time() - $created );
		$result['elapsed'] = $elapsed;

		// Watchdog: if overall progress hasn't moved for STALL_LIMIT seconds the
		// job is genuinely wedged (not merely slow — the time-budgeted steps move
		// the bar every ~10s). Fail it loudly instead of leaving the UI spinning
		// forever and the loopback/cron chain re-firing a dead job.
		if ( empty( $result['done'] ) && empty( $result['error'] ) ) {
			$heartbeat = (int) $job->get( 'heartbeat', $created );
			if ( time() - $heartbeat > self::STALL_LIMIT ) {
				ISX_Logger::log_error(
					(string) $job->get( 'type', 'export' ),
					__( 'Job stalled', 'insightx-backup' ),
					array(
						'job'          => $job->id(),
						'phase'        => (string) $job->get( 'last_phase', '' ),
						'stalled_for'  => time() - $heartbeat,
						'stuck_at'     => (int) $job->get( 'heartbeat_progress', -1 ),
						'elapsed'      => $elapsed,
						'last_message' => (string) $job->get( 'last_message', '' ),
					)
				);
				$result['error']   = true;
				$result['done']    = true;
				$result['message'] = sprintf( __( 'Job stalled (no progress for over %d minutes)', 'insightx-backup' ), (int) round( self::STALL_LIMIT / 60 ) );

				// An export that already produced its package before stalling
				// (the stall almost always happens in the upload step, which
				// runs after finalize() has stored the file) still has a usable
				// backup. Point at it instead of leaving the user to guess
				// whether anything survived.
				$stalled_backup = (string) $job->get( 'backup_name', '' );
				if ( $stalled_backup !== '' ) {
					/* translators: %s: backup file name */
					$result['message'] .= sprintf( __( ' — the backup file was already created and is in the "Backups" menu (%s)', 'insightx-backup' ), $stalled_backup );
				}

				// A job wedged mid-upload has parts sitting in the bucket as an
				// "ongoing multipart upload" that no object browser can delete.
				// Then actually end the job: reporting done+error only tells the
				// browser to stop, and a job left un-finished never reaches
				// step 'done', so gc_done_jobs() would skip its scratch dir
				// forever.
				ISX_Export::abort_pending_upload( $job );
				$job->finish( $result['message'], true );

				return $result;
			}
		}

		// A step just ran and isn't finished yet — keep the job advancing even if
		// this is the last poll the browser ever sends (tab closed, user
		// navigated away). Two independent fallbacks: a non-blocking loopback
		// request that chains itself to completion (the reliable driver — doesn't
		// depend on site traffic), plus a WP-Cron tick as a backstop. Both funnel
		// through the same per-job lock, so they never double-run a step.
		//
		// Not when we just bounced off the lock, though: someone else is mid-step
		// and will spawn its own successor when it returns. Calling for
		// reinforcements here meant every 200ms browser poll during a slow step
		// added a loopback request and a cron tick that could do nothing but
		// bounce off the same lock — a self-sustaining request storm competing
		// for the very PHP workers the running step needed.
		// Waiting for a password is not "more work": only the browser can
		// supply it (isx_import_decrypt, which resumes the poll). Scheduling
		// another cron step + loopback here re-ran init() back-to-back forever
		// on an abandoned prompt, pinning PHP workers and keeping the job's
		// heartbeat alive so the stall watchdog never ended it.
		if ( empty( $result['done'] ) && empty( $result['needs_password'] ) && ! self::$driving_synchronously && ! $lock_skipped ) {
			self::schedule_cron( $job->id() );
			self::spawn_loopback( $job );
		}

		return $result;
	}

	/**
	 * Drive a job to completion in the current PHP process instead of via
	 * browser polling — used by WP-CLI (ISX_CLI_Command) and the scheduled
	 * backup cron callback (run_scheduled_backup()), neither of which has a
	 * JS poll loop to lean on. Just runs run_step() in a tight loop; still
	 * goes through the same per-job lock, so it's safe to run alongside a
	 * browser tab or WP-Cron tick that happens to be working the same job.
	 *
	 * @param ISX_Job       $job
	 * @param callable|null $on_tick Optional callback invoked with each step's result.
	 * @return array The final ("done") result.
	 */
	public static function run_job_to_completion( ISX_Job $job, $on_tick = null ) {
		$prev                        = self::$driving_synchronously;
		self::$driving_synchronously = true;
		try {
			do {
				$result = self::run_step( $job );
				if ( is_callable( $on_tick ) ) {
					$on_tick( $result );
				}
			} while ( empty( $result['done'] ) && empty( $result['needs_password'] ) );
		} finally {
			self::$driving_synchronously = $prev;
		}

		return $result;
	}

	/**
	 * The [0,100] overall-progress window each pipeline step in
	 * ISX_Import::run() / ISX_Export::run() occupies (mirrors the constants
	 * those two classes compute their own $progress from) — used to turn the
	 * single overall progress number back into a 0-100% figure local to just
	 * the current step, so the UI can render each step as its own bar.
	 *
	 * @param string  $type import|export
	 * @param string  $step
	 * @param ISX_Job $job
	 * @return array [start, end]
	 */
	private static function phase_range( $type, $step, ISX_Job $job ) {
		$ranges = self::phase_ranges( $type, $job );
		return isset( $ranges[ $step ] ) ? $ranges[ $step ] : array( 0, 100 );
	}

	/**
	 * The full ordered [0,100] window map for every phase of a job type —
	 * used by phase_range() to turn the overall job progress number back
	 * into a 0-100% figure local to just the current step.
	 *
	 * @param string  $type import|export
	 * @param ISX_Job $job
	 * @return array<string, array{0:int,1:int}>
	 */
	private static function phase_ranges( $type, ISX_Job $job ) {
		if ( $type === 'import' ) {
			return array(
				'init'     => array( 0, 1 ),
				'verify'   => array( 1, 3 ),
				'clean'    => array( 3, 6 ),
				'extract'  => array( 6, 62 ),
				'database' => array( 62, 95 ),
				'finalize' => array( 95, 100 ),
			);
		}
		// finalize() only stops at 97 (instead of 100), and the 'upload' phase
		// only exists at all, when the export also uploads to a Storage
		// destination afterwards — see upload().
		$has_upload   = $job->get( 'to_storage', '' ) !== '';
		$finalize_end = $has_upload ? 97 : 100;
		$ranges       = array(
			'init'      => array( 0, 5 ),
			'database'  => array( 5, 50 ),
			'pack_meta' => array( 50, 55 ),
			'files'     => array( 55, 95 ),
			'finalize'  => array( 95, $finalize_end ),
		);
		if ( $has_upload ) {
			$ranges['upload'] = array( 97, 100 );
		}
		return $ranges;
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
	 * Fire a fire-and-forget loopback request that re-enters ajax_run() for this
	 * job, so the pipeline keeps advancing without any browser tab and without
	 * depending on WP-Cron actually firing (unreliable on low-traffic / Local
	 * installs — the classic "closed the tab and the backup froze" cause). Same
	 * technique All-in-One WP Migration uses. Non-blocking with a near-zero
	 * timeout: this request doesn't wait for the successor.
	 *
	 * A short transient throttles it to ~one in-flight loopback per job, so a
	 * still-open browser tab polling every 200ms doesn't also spawn a second
	 * parallel chain — the loopback successor clears the throttle at the top of
	 * ajax_run() (isx_lb marker) so the chain itself never stalls on it. The
	 * per-job lock in run_step() makes any accidental overlap harmless anyway.
	 *
	 * @param ISX_Job $job
	 * @return void
	 */
	private static function spawn_loopback( ISX_Job $job ) {
		// The whole point of the loopback chain is to keep the job moving without
		// the browser. A cancelled job must not be kept moving.
		if ( $job->is_cancel_requested() ) {
			return;
		}

		$throttle = 'isx_lb_' . $job->id();
		if ( get_transient( $throttle ) ) {
			// Worth recording: "no loopback was sent" and "one was sent but never
			// arrived" look identical in the log otherwise.
			ISX_Logger::log_debug( 'system', __( 'Skipped loopback (throttled)', 'insightx-backup' ), array( 'job' => $job->id() ) );
			return; // A loopback is already in flight for this job.
		}
		set_transient( $throttle, 1, 20 );

		$url = admin_url( 'admin-ajax.php' );
		ISX_Logger::log_debug( 'system', __( 'Firing loopback', 'insightx-backup' ), array( 'job' => $job->id(), 'url' => $url ) );

		// Verify the loopback's TLS certificate by default. The request goes
		// site-to-self, so on most hosts it verifies fine; only when
		// verification itself fails for a legitimate reason (self-signed/local
		// certs, hostname mismatch behind a proxy) do we retry unverified — and
		// say so in the log instead of silently downgrading every request.
		// Filterable for hosts that must skip verification on the first attempt.
		$sslverify = (bool) apply_filters( 'isx_loopback_sslverify', true );

		$args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => $sslverify,
			'cookies'   => array(),
			'body'      => array(
				'action' => 'isx_run',
				'job'    => $job->id(),
				'secret' => (string) $job->get( 'secret' ),
				'isx_lb' => '1',
			),
		);

		$sent = wp_remote_post( $url, $args );

		// Non-blocking means no response to inspect, but failures that happen
		// before the request is even on the wire (DNS, connect refused, TLS)
		// still come back as a WP_Error. A TLS verification failure is the one
		// case where retrying without sslverify is a deliberate, logged choice.
		if ( is_wp_error( $sent ) && $sslverify && self::is_loopback_ssl_failure( $sent ) ) {
			ISX_Logger::log_warn(
				'system',
				__( 'Loopback rejected because TLS certificate verification failed — resending without certificate verification (SSL verify off)', 'insightx-backup' ),
				array(
					'job'   => $job->id(),
					'error' => $sent->get_error_message(),
				)
			);
			$args['sslverify'] = false;
			$sent = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $sent ) ) {
			ISX_Logger::log_error(
				'system',
				__( 'Loopback request failed: ', 'insightx-backup' ) . $sent->get_error_message(),
				array(
					'job'  => $job->id(),
					'url'  => $url,
					'code' => $sent->get_error_code(),
				)
			);
		}
	}

	/**
	 * Whether a loopback WP_Error is specifically a TLS certificate
	 * verification failure — the only case where spawn_loopback() retries
	 * without sslverify. Anything else stays a hard error.
	 *
	 * @param WP_Error $error
	 * @return bool
	 */
	private static function is_loopback_ssl_failure( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$message = strtolower( (string) $error->get_error_message() );

		// cURL errno values that mean certificate verification failed:
		// 60/66/77/83 (verify), 51/58/59 (peer cert / issuer problems).
		if ( preg_match( '/curl error (?:60|66|77|83|51|58|59):/', $message ) ) {
			return true;
		}
		foreach ( array( 'ssl certificate', 'certificate problem', 'certificate verify', 'self-signed', 'unable to get local issuer', 'peer certificate' ) as $needle ) {
			if ( strpos( $message, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decrypt a password-protected .wpress package in place before an import
	 * job's poll loop continues (triggered from the "needs_password" state).
	 */
	public static function ajax_import_decrypt() {
		$job = ISX_Job::load( isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '' );
		if ( ! $job || $job->get( 'type' ) !== 'import' ) {
			wp_send_json_error( array( 'message' => __( 'Job not found', 'insightx-backup' ) ) );
		}

		$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
		if ( ! hash_equals( (string) $job->get( 'secret' ), $secret ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid secret', 'insightx-backup' ) ) );
		}

		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		if ( $password === '' ) {
			wp_send_json_error( array( 'message' => __( 'Please enter the password', 'insightx-backup' ) ) );
		}

		@set_time_limit( 0 );
		self::raise_memory_limit();

		$tmp    = $job->archive() . '.dec';
		$result = ISX_Crypto::decrypt_file( $password, $job->archive(), $tmp );
		if ( is_wp_error( $result ) ) {
			@unlink( $tmp );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// rename() overwrites atomically on POSIX, so the still-encrypted archive
		// isn't deleted ahead of a move that could fail and leave the job with
		// no package at all. Windows won't overwrite, hence the retry.
		if ( ! @rename( $tmp, $job->archive() ) ) {
			@unlink( $job->archive() );
			if ( ! @rename( $tmp, $job->archive() ) ) {
				@unlink( $tmp );
				ISX_Logger::log_error(
					'import',
					__( 'Could not move the decrypted file', 'insightx-backup' ),
					array( 'job' => $job->id(), 'free_space' => @disk_free_space( dirname( $job->archive() ) ) )
				);
				wp_send_json_error( array( 'message' => __( 'Decryption succeeded but the file could not be saved — the disk may be full', 'insightx-backup' ) ) );
			}
		}
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

	/**
	 * Stream a stored backup to the browser.
	 *
	 * The naive version of this (open, loop, echo) fails on exactly the files
	 * people most want to download. set_time_limit(0) has no effect on PHP-FPM's
	 * request_terminate_timeout, nginx's fastcgi_read_timeout or a CDN's edge
	 * timeout, so a multi-hundred-MB archive over a slow uplink gets its worker
	 * killed part-way through and the browser shows a bare 502 — and with no
	 * Range support the retry restarts from byte zero and dies the same way,
	 * forever. Hence: byte ranges (so an interrupted transfer resumes instead of
	 * restarting), no buffering anywhere in the chain (so nothing accumulates in
	 * memory), and an X-Sendfile/X-Accel-Redirect fast path that hands the file
	 * to the web server entirely, where none of these limits apply.
	 */
	public static function ajax_download() {
		self::guard( 'export', true );

		$name = isset( $_GET['backup'] ) ? sanitize_text_field( wp_unslash( $_GET['backup'] ) ) : '';
		$path = ISX_Backups::path( $name );
		if ( $path === null ) {
			wp_die( esc_html__( 'File not found', 'insightx-backup' ) );
		}

		@set_time_limit( 0 ); // phpcs:ignore
		self::raise_memory_limit();

		// zlib.output_compression buffers the whole response to compress it —
		// on an already-compressed octet-stream that buys nothing and costs the
		// entire file in memory, which is its own route to a dead worker.
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		clearstatcache( true, $path );

		$size     = (float) filesize( $path );
		$filename = basename( $path );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Accept-Ranges: bytes' );
		// Tells nginx not to buffer the response (it otherwise spools the whole
		// file to disk before sending a single byte).
		header( 'X-Accel-Buffering: no' );

		// Fast path: let the web server do the sending. Opt-in, because it needs
		// a matching internal location/alias in the server config — return the
		// URI the server should serve from the isx_download_accel filter.
		$accel = apply_filters( 'isx_download_accel', '', $path, $filename );
		if ( is_string( $accel ) && $accel !== '' ) {
			header( 'X-Accel-Redirect: ' . $accel ); // nginx
			header( 'X-Sendfile: ' . $path );        // Apache mod_xsendfile
			exit;
		}

		if ( $size <= 0 ) {
			header( 'Content-Length: 0' );
			exit;
		}

		list( $start, $end ) = self::parse_range( $size );

		if ( $start === null ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . sprintf( '%.0f', $size ) );
			exit;
		}

		$length = $end - $start + 1;
		if ( $start > 0 || $end < $size - 1 ) {
			status_header( 206 );
			header( sprintf( 'Content-Range: bytes %.0f-%.0f/%.0f', $start, $end, $size ) );
		}
		// sprintf rather than casting to int: a >2GB archive overflows a 32-bit
		// int and would advertise a negative or truncated length.
		header( 'Content-Length: ' . sprintf( '%.0f', $length ) );

		$fh = fopen( $path, 'rb' );
		if ( $fh === false ) {
			wp_die( esc_html__( 'Could not open the file', 'insightx-backup' ) );
		}
		if ( $start > 0 ) {
			fseek( $fh, (int) $start );
		}

		$chunk_size = 524288; // 512KB
		while ( $length > 0 && ! feof( $fh ) ) {
			$chunk = fread( $fh, (int) min( $chunk_size, $length ) );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}
			echo $chunk; // phpcs:ignore
			$length -= strlen( $chunk );
			flush();

			// The client closed the tab or cancelled — stop reading rather than
			// keep grinding through a few hundred MB nobody is receiving.
			if ( connection_aborted() ) {
				break;
			}
		}
		fclose( $fh );
		exit;
	}

	/**
	 * Resolve the requested byte range against a file size.
	 *
	 * @param float $size
	 * @return array { 0: start|null (null = unsatisfiable), 1: end }
	 */
	private static function parse_range( $size ) {
		$header = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $header === '' || $size <= 0 ) {
			return array( 0.0, max( 0.0, $size - 1 ) );
		}

		// Only a single range is honoured; multipart/byteranges buys nothing for
		// a plain file download and every client that matters falls back to a
		// whole-file request when we ignore it.
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $m ) ) {
			return array( 0.0, $size - 1 );
		}

		$from = $m[1];
		$to   = $m[2];

		if ( $from === '' ) {
			// Suffix range: "bytes=-500" means the last 500 bytes.
			if ( $to === '' ) {
				return array( null, 0 );
			}
			$length = (float) $to;
			if ( $length <= 0 ) {
				return array( null, 0 );
			}
			$start = max( 0.0, $size - $length );
			return array( $start, $size - 1 );
		}

		$start = (float) $from;
		$end   = $to === '' ? $size - 1 : (float) $to;

		if ( $start > $end || $start >= $size ) {
			return array( null, 0 );
		}

		return array( $start, min( $end, $size - 1 ) );
	}

	/* ---------------- Backups AJAX ---------------- */

	/**
	 * Tail the log for the Log screen's real-time view: everything written
	 * since the client's cursor, pre-rendered as the same escaped HTML the
	 * initial page load uses (ISX_Logger::render_line_html()) so the two paths
	 * can never draw an entry differently.
	 */
	public static function ajax_log_poll() {
		self::guard( 'export' );

		$since = isset( $_POST['since'] ) ? max( 0, (int) $_POST['since'] ) : 0;
		$level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '';

		$result = ISX_Logger::entries_since( $since );

		$html = '';
		foreach ( $result['entries'] as $entry ) {
			if ( $level !== '' && ( isset( $entry['level'] ) ? $entry['level'] : 'error' ) !== $level ) {
				continue;
			}
			$html .= ISX_Logger::render_line_html( $entry );
		}

		wp_send_json_success(
			array(
				'html'   => $html,
				'cursor' => $result['cursor'],
			)
		);
	}

	public static function ajax_backups_list() {
		self::guard( 'export' );
		wp_send_json_success( array( 'backups' => ISX_Backups::all() ) );
	}

	public static function ajax_backups_delete() {
		self::guard( 'export' );
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! ISX_Backups::delete( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete', 'insightx-backup' ) ) );
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
			ISX_Logger::log_error( 'backup', __( 'Backup file not found (restore)', 'insightx-backup' ), array( 'name' => $name ) );
			wp_send_json_error( array( 'message' => __( 'Backup file not found', 'insightx-backup' ) ) );
		}

		$job = ISX_Job::create( 'import' );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Could not create the job. See the Log page for details', 'insightx-backup' ) ) );
		}
		// A local restore doubles the archive on disk for the duration of the
		// job, so this is a very ordinary place to run out of room — and an
		// unchecked copy() meant the restore started against a truncated
		// package and failed later as "Invalid package file".
		if ( ! @copy( $path, $job->archive() ) ) {
			$job->cleanup();
			ISX_Logger::log_error(
				'backup',
				__( 'Could not copy the backup file for restore', 'insightx-backup' ),
				array( 'name' => $name, 'free_space' => @disk_free_space( dirname( $job->archive() ) ) )
			);
			wp_send_json_error( array( 'message' => __( 'Could not prepare the file for restore — the server disk may be full', 'insightx-backup' ) ) );
		}

		wp_send_json_success( array( 'job' => $job->id(), 'secret' => $job->get( 'secret' ) ) );
	}

	/**
	 * List what a local backup holds, one directory level at a time, without
	 * extracting anything.
	 *
	 * This used to answer with every entry in the package in one response: it
	 * collected them all into an array, copied that array, then JSON-encoded
	 * the copy. On a site whose media library runs to six figures that is
	 * several hundred megabytes of PHP arrays for a request whose job is to
	 * show a folder listing — it hit memory_limit and died, and because a
	 * fatal answers with a 500 rather than a JSON error, the browser had
	 * nothing to show but a bare "could not load" with no reason attached.
	 * The browser end was no better off: it rebuilt the whole tree in JS and
	 * rendered every folder expanded, so even a response that arrived intact
	 * would have locked the tab up building a DOM with a node per file.
	 *
	 * So the contract is now "give me the children of one directory". The walk
	 * reads each entry's header and seeks past its content — it never reads
	 * file data — and folds anything below the requested directory into a
	 * per-folder count and byte total. Memory is proportional to how many
	 * children that one directory has, not to the size of the package, and the
	 * UI expands a level at a time on demand.
	 *
	 * Answers in batches: a package with hundreds of thousands of entries takes
	 * more than one request to walk, so each response carries a resume offset
	 * and the caller keeps asking until `done`. The figures in each response
	 * are that batch's contribution alone — the caller adds them up.
	 */
	public static function ajax_backups_list_content() {
		self::guard( 'export' );
		self::report_fatals_as_json( __( 'Reading backup contents', 'insightx-backup' ) );
		self::raise_memory_limit();

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$path = ISX_Backups::path( $name );
		if ( $path === null ) {
			wp_send_json_error( array( 'message' => __( 'Backup file not found', 'insightx-backup' ) ) );
		}

		if ( ISX_Crypto::is_encrypted_file( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'This file is password-encrypted, so its contents cannot be listed — it can be restored directly', 'insightx-backup' ) ) );
		}

		// Directory being listed, '' for the root of the package. Normalised to
		// a trailing slash so a simple prefix test can't match a sibling whose
		// name merely starts the same way ("uploads" vs "uploads-old").
		$prefix = isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : '';
		$prefix = ltrim( str_replace( '\\', '/', $prefix ), '/' );
		if ( $prefix !== '' ) {
			$prefix = rtrim( $prefix, '/' ) . '/';
		}

		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$offset = max( ISX_Archive::first_offset(), $offset );

		$read_path = self::content_read_path( $path, $offset );
		if ( is_wp_error( $read_path ) ) {
			wp_send_json_error( array( 'message' => $read_path->get_error_message() ) );
		}

		$dirs       = array();
		$files      = array();
		$files_seen = 0;
		$bytes_seen = 0;
		$prefix_len = strlen( $prefix );

		$result = ISX_Archive::read_batch(
			$read_path,
			$offset,
			self::CONTENT_ENTRIES_PER_BATCH,
			function ( $header ) use ( &$dirs, &$files, &$files_seen, &$bytes_seen, $prefix, $prefix_len ) {
				$entry_path = isset( $header['p'] ) ? (string) $header['p'] : '';
				if ( $prefix_len > 0 && strpos( $entry_path, $prefix ) !== 0 ) {
					return true; // Not under the directory being listed.
				}

				$rest = $prefix_len > 0 ? substr( $entry_path, $prefix_len ) : $entry_path;
				if ( $rest === '' ) {
					return true;
				}

				// "u" (original size) is only present on compressed entries —
				// report the real content size, not the smaller on-disk one.
				$size = isset( $header['u'] ) ? (int) $header['u'] : ( isset( $header['s'] ) ? (int) $header['s'] : 0 );

				$slash = strpos( $rest, '/' );
				if ( $slash === false ) {
					// A file sitting directly in this directory.
					$files_seen++;
					$bytes_seen += $size;
					if ( count( $files ) < self::CONTENT_FILES_PER_BATCH ) {
						$files[] = array( 'name' => $rest, 'size' => $size );
					}
					return true;
				}

				// Anything deeper folds into the child directory it lives under,
				// which is the whole reason this scales: one bucket per child,
				// however many files are beneath it.
				$dir = substr( $rest, 0, $slash );
				if ( ! isset( $dirs[ $dir ] ) ) {
					$dirs[ $dir ] = array( 'files' => 0, 'bytes' => 0 );
				}
				$dirs[ $dir ]['files']++;
				$dirs[ $dir ]['bytes'] += $size;

				return true;
			}
		);

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid package file', 'insightx-backup' ) ) );
		}

		usort(
			$files,
			function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		$size = (int) @filesize( $read_path );

		self::warn_if_memory_tight( $name, $prefix );

		wp_send_json_success(
			array(
				// Everything below is this batch's contribution only; the caller
				// merges successive batches.
				'dirs'       => $dirs,
				'files'      => $files,
				'files_seen' => $files_seen,
				'bytes_seen' => $bytes_seen,
				'offset'     => (int) $result['offset'],
				'done'       => ! empty( $result['done'] ),
				// Same shape ajax_backups_verify() reports, so the progress bar
				// on the client is driven identically.
				'percent'    => $size > 0 ? round( (int) $result['offset'] / $size * 100, 2 ) : 100,
			)
		);
	}

	/**
	 * The seekable file to walk when listing a package's contents.
	 *
	 * A gzipped package can't be seeked through, so it has to be inflated to a
	 * scratch copy first. Listing now takes several requests, and inflating a
	 * multi-GB file on each of them would be far more expensive than the
	 * problem being solved — so the scratch copy is kept and reused for as long
	 * as it is newer than the package it came from, and swept once it goes
	 * stale. Uncompressed packages are read where they lie.
	 *
	 * @param string $path   Backup file.
	 * @param int    $offset Resume point; the sweep only runs at the start of a scan.
	 * @return string|WP_Error Path to read.
	 */
	private static function content_read_path( $path, $offset ) {
		if ( ! ISX_Compress::is_gzip_file( $path ) ) {
			return $path;
		}

		if ( $offset <= ISX_Archive::first_offset() ) {
			self::sweep_content_peeks( $path );
		}

		$peek = $path . '.peek';
		if ( is_file( $peek ) && @filemtime( $peek ) >= @filemtime( $path ) ) {
			return $peek;
		}

		$result = ISX_Compress::gunzip_file( $path, $peek );
		if ( is_wp_error( $result ) ) {
			@unlink( $peek ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $result;
		}

		return $peek;
	}

	/**
	 * Delete scratch copies left behind by earlier browsing sessions, except
	 * the one belonging to the package being looked at right now.
	 *
	 * @param string $keep Backup file whose scratch copy must survive.
	 * @return void
	 */
	private static function sweep_content_peeks( $keep ) {
		$cutoff = time() - self::CONTENT_PEEK_TTL;
		foreach ( (array) glob( ISX_Backups::dir() . '/*.peek' ) as $peek ) {
			if ( $peek === $keep . '.peek' ) {
				continue;
			}
			if ( @filemtime( $peek ) < $cutoff ) {
				@unlink( $peek ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/**
	 * Leave a trace when a request finishes close to memory_limit.
	 *
	 * Running out of memory is a fatal: no exception to catch, no JSON to send,
	 * just a 500 that the browser reports as a bare failure. That is exactly
	 * how the old listing behaved, and it left nothing behind in any log to
	 * explain it. A warning at the point of near-miss means the next one is
	 * diagnosable from the plugin's own log instead of guesswork.
	 *
	 * @param string $name   Backup being listed.
	 * @param string $prefix Directory being listed.
	 * @return void
	 */
	private static function warn_if_memory_tight( $name, $prefix ) {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return; // Unlimited, or unreadable — nothing to compare against.
		}

		$peak = memory_get_peak_usage( true );
		if ( $peak < $limit * 0.8 ) {
			return;
		}

		ISX_Logger::log_warn(
			'backup',
			__( 'Listing the package contents is using nearly all of the memory limit', 'insightx-backup' ),
			array(
				'name'   => $name,
				'prefix' => $prefix,
				'peak'   => $peak,
				'limit'  => $limit,
			)
		);
	}

	/**
	 * Check a stored backup end to end, on demand.
	 *
	 * The import pipeline verifies a package before it touches the site, but
	 * that only tells you a backup was bad at the moment you needed it — which
	 * is the moment it is least useful to find out. This is the same check,
	 * available while nothing is on fire.
	 *
	 * Driven from the browser with a byte offset rather than a job: there is no
	 * state worth persisting between calls, and a bare offset keeps every
	 * request inside the same time budget the pipeline steps use.
	 */
	public static function ajax_backups_verify() {
		self::guard( 'export' );
		self::report_fatals_as_json( __( 'Backup verification', 'insightx-backup' ) );
		self::raise_memory_limit();

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$path = ISX_Backups::path( $name );
		if ( $path === null ) {
			wp_send_json_error( array( 'message' => __( 'Backup file not found', 'insightx-backup' ) ) );
		}

		// Both of these wrap the archive in another container, so the entry walk
		// can't see through them. Say why rather than reporting a false failure.
		if ( ISX_Crypto::is_encrypted_file( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'This file is password-encrypted, so its contents cannot be verified', 'insightx-backup' ) ) );
		}
		if ( ISX_Compress::is_gzip_file( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'This file is GZip-compressed, so its contents cannot be verified', 'insightx-backup' ) ) );
		}

		if ( ! ISX_Archive::is_valid( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Not an InsightX Backup package (or the file is corrupted from the start)', 'insightx-backup' ) ) );
		}

		// Never below first_offset(): the first entry starts after the magic
		// header, and starting at 0 would read those 8 bytes as an entry length.
		$offset  = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$offset  = max( ISX_Archive::first_offset(), $offset );
		$entries = isset( $_POST['entries'] ) ? max( 0, (int) $_POST['entries'] ) : 0;

		$result   = ISX_Archive::verify_batch( $path, $offset, microtime( true ) + 10 );
		$entries += (int) $result['entries'];
		$size     = (int) @filesize( $path );

		if ( empty( $result['ok'] ) ) {
			ISX_Logger::log_error(
				'backup',
				__( 'Backup verification failed: ', 'insightx-backup' ) . $result['error'],
				array( 'name' => $name, 'offset' => (int) $result['offset'], 'size' => $size )
			);
			wp_send_json_success(
				array(
					'done'    => true,
					'ok'      => false,
					'message' => $result['error'],
				)
			);
		}

		if ( empty( $result['done'] ) ) {
			wp_send_json_success(
				array(
					'done'    => false,
					'ok'      => true,
					'offset'  => (int) $result['offset'],
					'entries' => $entries,
					// Two decimals, matching run_step()'s phase_progress: verifying
					// a multi-GB package sits inside one integer percent for a
					// long time, and the client eases between these figures.
					'percent' => $size > 0 ? round( (int) $result['offset'] / $size * 100, 2 ) : 0,
				)
			);
		}

		wp_send_json_success(
			array(
				'done'    => true,
				'ok'      => true,
				'message' => sprintf( __( 'File is intact — %s entries checked', 'insightx-backup' ), number_format_i18n( $entries ) ),
			)
		);
	}

	/* ---------------- Storage settings AJAX ---------------- */

	/**
	 * Change where local job/backup data lives (see isx_resolve_storage_path()
	 * in the main plugin file). Only saves the pointer — an admin who already
	 * has backups sitting in the old location is responsible for moving those
	 * files over themselves, same as this plugin's own directory move for
	 * All-in-One WP Migration did manually.
	 */
	/**
	 * Run the full stranded-upload sweep on demand.
	 *
	 * The automatic sweep already covers this, but its listing pass is throttled
	 * to once a day and only touches uploads past an age cutoff — reasonable
	 * defaults, useless to someone who is looking at leftovers in their
	 * provider's console right now and wants them gone. Until this existed the
	 * only manual route was `wp isx cleanup-uploads`, which rules out anyone
	 * without shell access.
	 *
	 * Deliberately bypasses the daily transient: an explicit click is the one
	 * case where the cost of the listing calls is obviously wanted.
	 */
	public static function ajax_cleanup_uploads() {
		self::guard( 'export' );

		// Empty selection (nothing posted, or the client sent none) means every
		// configured provider — the picker on screen defaults to none selected
		// meaning "all", so an unchecked request must behave the same as before
		// this filter existed.
		$only = array();
		if ( isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ) {
			foreach ( wp_unslash( $_POST['providers'] ) as $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$slug = sanitize_key( $slug );
				if ( array_key_exists( $slug, ISX_Destinations::providers() ) ) {
					$only[] = $slug;
				}
			}
		}

		$result  = ISX_Export::sweep_orphaned_uploads( true, $only ? $only : null );
		$aborted = (int) $result['aborted'];
		$errors  = (array) $result['errors'];

		$message = $aborted > 0
			? sprintf(
				/* translators: %d: number of uploads released */
				__( 'Cleaned up %d stale uploads', 'insightx-backup' ),
				$aborted
			)
			: __( 'No stale uploads found', 'insightx-backup' );

		if ( ! empty( $errors ) ) {
			$providers      = ISX_Destinations::providers();
			$error_lines    = array();
			foreach ( $errors as $slug => $error_message ) {
				$label         = isset( $providers[ $slug ]['label'] ) ? $providers[ $slug ]['label'] : $slug;
				$error_lines[] = $label . ': ' . $error_message;
			}
			/* translators: %s: comma-separated provider errors */
			$message .= sprintf( __( ' — some providers could not be checked (%s)', 'insightx-backup' ), implode( ', ', $error_lines ) );
		}

		wp_send_json_success(
			array(
				'aborted' => $aborted,
				'errors'  => $errors,
				'message' => $message,
			)
		);
	}

	public static function ajax_storage_dir_save() {
		self::guard( 'export' );

		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		$path = untrailingslashit( trim( $path ) );

		if ( $path === '' ) {
			delete_option( 'isx_storage_path' );
			wp_send_json_success(
				array(
					'message' => __( 'Reset to default', 'insightx-backup' ),
					'path'    => ISX_DEFAULT_STORAGE_PATH,
				)
			);
		}

		// The path becomes the home of every backup this site makes, so it must
		// be a plain absolute path: no ".." traversal segments (which could
		// resolve the backup directory outside where the admin believes it is),
		// and never the filesystem root (which would scatter backups and this
		// plugin's protection files across system directories).
		$normalized = str_replace( '\\', '/', $path );
		if ( in_array( '..', explode( '/', $normalized ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid path — ".." is not allowed in the path', 'insightx-backup' ) ) );
		}
		$is_absolute = isset( $normalized[0] ) && ( $normalized[0] === '/' || (bool) preg_match( '/^[A-Za-z]:\//', $normalized ) );
		if ( ! $is_absolute ) {
			wp_send_json_error( array( 'message' => __( 'The path must be absolute, e.g. /var/www/backups', 'insightx-backup' ) ) );
		}
		if ( $normalized === '/' || (bool) preg_match( '/^[A-Za-z]:\/$/', $normalized ) ) {
			wp_send_json_error( array( 'message' => __( 'The filesystem root cannot be used as the storage folder', 'insightx-backup' ) ) );
		}

		$conflict = isx_storage_path_conflict( $path );
		if ( $conflict !== '' ) {
			wp_send_json_error(
				array(
					/* translators: %s: WordPress directory */
					'message' => sprintf( __( 'This folder would contain the WordPress directory %s — choose a dedicated folder for backups instead (e.g. wp-content/insightx-backup)', 'insightx-backup' ), $conflict ),
				)
			);
		}

		$parent = dirname( $path );
		if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
			wp_send_json_error( array( 'message' => __( 'Parent folder not found or not writable: ', 'insightx-backup' ) . $parent ) );
		}

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not create the folder', 'insightx-backup' ) ) );
		}
		if ( ! is_writable( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'This folder is not writable', 'insightx-backup' ) ) );
		}

		// Same protection files the activation hook creates for the default dir
		// (isx_htaccess_deny_all() covers both Apache 2.2 and 2.4).
		$htaccess = $path . '/.htaccess';
		if ( ! file_exists( $htaccess ) || trim( (string) @file_get_contents( $htaccess ) ) === 'Deny from all' ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			file_put_contents( $htaccess, isx_htaccess_deny_all() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$index = $path . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		update_option( 'isx_storage_path', $path );

		wp_send_json_success(
			array(
				'message' => __( 'Saved — takes effect on the next page load (old backups in the previous folder must be moved manually)', 'insightx-backup' ),
				'path'    => $path,
			)
		);
	}

	/**
	 * Save the automatic-backup schedule and (re)register its cron event.
	 * The event itself is registered unconditionally in the main plugin file
	 * (same reasoning as isx_cron_step — it must fire from wp-cron.php, which
	 * isn't is_admin()) and reads this option back when it runs.
	 */
	public static function ajax_schedule_save() {
		self::guard( 'export' );

		$enabled  = ! empty( $_POST['enabled'] ) && $_POST['enabled'] !== '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$interval = isset( $_POST['interval'] ) ? sanitize_key( wp_unslash( $_POST['interval'] ) ) : 'weekly';
		if ( ! in_array( $interval, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$interval = 'weekly';
		}
		$to_storage = isset( $_POST['to_storage'] ) ? sanitize_key( wp_unslash( $_POST['to_storage'] ) ) : '';
		if ( $to_storage !== '' && ! isset( ISX_Destinations::providers()[ $to_storage ] ) ) {
			$to_storage = '';
		}
		$retain = isset( $_POST['retain'] ) ? max( 1, (int) $_POST['retain'] ) : 5;

		$schedule = array(
			'enabled'    => $enabled,
			'interval'   => $interval,
			'to_storage' => $to_storage,
			'retain'     => $retain,
		);
		update_option( 'isx_schedule', $schedule );

		wp_clear_scheduled_hook( 'isx_scheduled_backup' );
		if ( $enabled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $interval, 'isx_scheduled_backup' );
		}

		wp_send_json_success(
			array(
				'message' => $enabled ? __( 'Saved — automatic backup enabled', 'insightx-backup' ) : __( 'Saved — automatic backup disabled', 'insightx-backup' ),
			)
		);
	}

	/**
	 * Cron callback for the scheduled-backup option (see ajax_schedule_save()).
	 * Runs a full export synchronously in this one PHP process — there's no
	 * browser tab to drive it via AJAX polling here — then trims local backups
	 * down to the configured retain count.
	 *
	 * @return void
	 */
	public static function run_scheduled_backup() {
		$schedule = (array) get_option( 'isx_schedule', array() );
		if ( empty( $schedule['enabled'] ) ) {
			return;
		}

		$job = ISX_Job::create( 'export' );
		if ( ! $job ) {
			return;
		}
		$job->set( 'options', array() );
		$to_storage = isset( $schedule['to_storage'] ) ? $schedule['to_storage'] : '';
		if ( $to_storage !== '' && ISX_Destinations::is_configured( $to_storage ) ) {
			$job->set( 'to_storage', $to_storage );
		}
		$job->save();

		$result = self::run_job_to_completion( $job );

		// Retention used to run unconditionally, on a return value nobody read.
		// Pruning "down to the newest N" right after a run that produced no new
		// backup is acting on a promotion that never happened — so a failed run
		// must not be allowed to age out a good backup.
		if ( ! empty( $result['error'] ) ) {
			ISX_Logger::log_error(
				'export',
				__( 'Automatic backup failed: ', 'insightx-backup' ) . ( isset( $result['message'] ) ? $result['message'] : __( 'Unknown reason', 'insightx-backup' ) ),
				array( 'job' => $job->id() )
			);
			return;
		}

		$retain = isset( $schedule['retain'] ) ? max( 1, (int) $schedule['retain'] ) : 5;
		$all    = ISX_Backups::all(); // Newest-first already.
		foreach ( array_slice( $all, $retain ) as $old ) {
			ISX_Backups::delete( $old['name'] );
		}

		// And the same trim on the bucket, which nothing has ever cleaned up.
		if ( $to_storage !== '' ) {
			ISX_Backups::prune_remote( $to_storage, $retain );
		}
	}

	public static function ajax_storage_save() {
		self::guard( 'export' );

		$slug = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! isset( ISX_Destinations::providers()[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid destination', 'insightx-backup' ) ) );
		}

		$posted  = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : array();
		$current = ISX_Destinations::all();

		if ( empty( $posted['secret_key'] ) && ! empty( $current[ $slug ]['secret_key'] ) ) {
			$posted['secret_key'] = $current[ $slug ]['secret_key'];
		}

		// A stale cached copy of isx-storage.js — one built before a field
		// existed — posts a config without that key at all. Treating "absent"
		// as "cleared" would silently wipe a stored value the user never
		// touched, so an absent key keeps whatever is already saved, the same
		// rule the secret_key fallback above follows. Note this tests isset()
		// rather than empty(): a key posted as '' is a deliberate clear and
		// must still go through. path_style stays out of this — an unchecked
		// box always posts a key, so backfilling it would make it unclickable.
		foreach ( array( 'endpoint', 'region', 'bucket', 'prefix', 'access_key' ) as $field ) {
			if ( ! isset( $posted[ $field ] ) && isset( $current[ $slug ][ $field ] ) ) {
				$posted[ $field ] = $current[ $slug ][ $field ];
			}
		}

		$all          = ISX_Destinations::all();
		$all[ $slug ] = $posted;
		ISX_Destinations::save( $all );

		// Saving always succeeds (partial credentials are fine — the user may
		// be filling a provider in over several saves), but the UI's "connected"
		// badge should only light up once the credentials actually work — not
		// just once bucket/access_key/secret_key are non-empty. A typo'd bucket
		// name or wrong key would otherwise still show as "Connected successfully".
		$connected = false;
		$message   = __( 'Saved — some fields are still missing', 'insightx-backup' );

		if ( ISX_Destinations::is_configured( $slug ) ) {
			$saved  = ISX_Destinations::get( $slug );
			$client = new ISX_S3_Client( $saved );
			$result = $client->test_connection();

			if ( is_wp_error( $result ) ) {
				$connected = false;
				$message   = __( 'Connection failed: ', 'insightx-backup' ) . $result->get_error_message();
			} else {
				$connected = true;
				$message   = __( 'Connected successfully', 'insightx-backup' );
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
			wp_send_json_error( array( 'message' => __( 'Invalid destination', 'insightx-backup' ) ) );
		}
		if ( ! ISX_Destinations::is_configured( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'This provider is not configured yet — go to the "Storage Settings" menu first', 'insightx-backup' ) ) );
		}

		$client = new ISX_S3_Client( ISX_Destinations::get( $slug ) );
		$result = $client->list_objects( ISX_Destinations::prefix( $slug ) );

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
			wp_send_json_error( array( 'message' => __( 'Invalid destination', 'insightx-backup' ) ) );
		}
		if ( ! ISX_Destinations::is_configured( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'This provider is not configured yet', 'insightx-backup' ) ) );
		}
		// Confine imports to the folder this destination writes to, so a crafted
		// key can't pull an arbitrary object out of the bucket.
		if ( strpos( $key, ISX_Destinations::prefix( $slug ) ) !== 0 || substr( $key, -7 ) !== '.wpress' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file name', 'insightx-backup' ) ) );
		}

		@set_time_limit( 0 );
		self::raise_memory_limit();

		$job = ISX_Job::create( 'import' );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Could not create the job. See the Log page for details', 'insightx-backup' ) ) );
		}
		$client = new ISX_S3_Client( ISX_Destinations::get( $slug ) );
		$result = $client->get_object( $key, $job->archive() );

		if ( is_wp_error( $result ) ) {
			ISX_Logger::log_error(
				'import',
				__( 'Download from Storage failed: ', 'insightx-backup' ) . $result->get_error_message(),
				array( 'job' => $job->id(), 'provider' => $slug, 'key' => $key )
			);
			$job->cleanup();
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'job' => $job->id(), 'secret' => $job->get( 'secret' ) ) );
	}

	/* ---------------- Reset Hub AJAX ---------------- */

	private static $reset_dispatch = array(
		'plugins'  => array( 'ISX_Reset', 'purge_plugins' ),
		'theme'    => array( 'ISX_Reset', 'reset_theme' ),
		'media'    => array( 'ISX_Reset', 'clean_media' ),
		'database' => array( 'ISX_Reset', 'reset_database' ),
		'full'     => array( 'ISX_Reset', 'full_site_reset' ),
	);

	/**
	 * Single entry point for all 5 Reset Hub tools. Every tool here is
	 * destructive and irreversible, so — on top of guard()'s capability+nonce
	 * check — the current user's account password must also be re-entered
	 * and verified before anything runs.
	 */
	public static function ajax_reset_run() {
		self::guard( 'manage_options' );
		self::verify_reset_password();

		$tool = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
		if ( ! isset( self::$reset_dispatch[ $tool ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown tool', 'insightx-backup' ) ) );
		}

		ISX_Logger::log_error( 'reset', sprintf( __( 'Reset Hub tool started: %s', 'insightx-backup' ), $tool ), array( 'user' => wp_get_current_user()->user_login ) );

		$result = call_user_func( self::$reset_dispatch[ $tool ] );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	/**
	 * One-time reveal of the password a database/full reset just generated.
	 *
	 * Deliberately does NOT go through guard(): the reset rotates the admin's
	 * session token mid-request, which invalidates the nonce the page was
	 * rendered with — the same reason job polling authenticates on an on-disk
	 * secret instead of the WP session. The authenticator here is the
	 * single-use token the reset response handed back (32 random chars, stored
	 * encrypted-at-rest in a short-lived transient, deleted on read), and the
	 * capability check still keeps a logged-in non-admin from consuming
	 * someone else's token.
	 */
	public static function ajax_reset_password_reveal() {
		if ( ! current_user_can( self::cap( 'manage_options' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'insightx-backup' ) ) );
		}

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid token', 'insightx-backup' ) ) );
		}

		// Read-then-delete: exactly one reveal per reset. A second call gets
		// the same answer as a forged one — "expired or already used".
		$stored = get_transient( 'isx_reset_pw_' . $token );
		delete_transient( 'isx_reset_pw_' . $token );

		if ( $stored === false ) {
			wp_send_json_error(
				array(
					'message' => __( 'Token expired or already used — reset the password from the login screen (Lost your password) or WP-CLI', 'insightx-backup' ),
				)
			);
		}

		$password = ISX_Crypto::decrypt_string( (string) $stored );
		if ( $password === '' ) {
			wp_send_json_error(
				array(
					'message' => __( 'Could not read the password — reset it from the login screen (Lost your password) or WP-CLI', 'insightx-backup' ),
				)
			);
		}

		wp_send_json_success( array( 'admin_password' => $password ) );
	}

	/**
	 * Gate for every Reset Hub tool — checked in addition to guard(), since
	 * capability + nonce alone isn't enough confirmation before wiping data.
	 * Terminates the request (wp_send_json_error exits) on failure.
	 */
	private static function verify_reset_password() {
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		if ( $password === '' ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your password to confirm', 'insightx-backup' ) ) );
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			wp_send_json_error( array( 'message' => __( 'Incorrect password', 'insightx-backup' ) ) );
		}
	}

	/**
	 * Both rejection paths used to die silently (via wp_send_json_error() /
	 * check_ajax_referer()'s own wp_die()) with nothing in the log — so a
	 * request that never even reached ISX_Job::create() (expired nonce, a
	 * capability check failing right after WP finishes booting, ...) looked
	 * identical to total silence from the browser's side. Logged here instead,
	 * before either exit path.
	 */
	private static function guard( $cap, $is_navigation = false ) {
		// $_REQUEST, not $_POST: ajax_download() is a plain GET the browser
		// navigates to, and logging a blank action for it made a rejected
		// download indistinguishable from a rejected form post in the log.
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$allowed = is_multisite()
			? current_user_can( self::cap( $cap ) )
			: ( current_user_can( $cap ) || current_user_can( 'manage_options' ) );
		if ( ! $allowed ) {
			ISX_Logger::log_warn( 'system', __( 'AJAX request rejected: permission denied', 'insightx-backup' ), array( 'action' => $action, 'cap' => $cap ) );
			self::reject( __( 'Permission denied', 'insightx-backup' ), $is_navigation );
		}
		// Import and Reset Hub write and delete plugin/theme code — exactly
		// what DISALLOW_FILE_MODS exists to forbid from the dashboard.
		if ( ( $cap === 'import' || $cap === 'manage_options' ) && defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			ISX_Logger::log_warn( 'system', __( 'AJAX request rejected: file modifications are disabled (DISALLOW_FILE_MODS)', 'insightx-backup' ), array( 'action' => $action ) );
			self::reject( __( 'File modifications are disabled on this site (DISALLOW_FILE_MODS), so import and reset are not available', 'insightx-backup' ), $is_navigation );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			ISX_Logger::log_warn( 'system', __( 'AJAX request rejected: invalid or expired nonce', 'insightx-backup' ), array( 'action' => $action ) );
			self::reject( __( 'Invalid or expired nonce. Please refresh this page', 'insightx-backup' ), $is_navigation );
		}
	}

	/**
	 * Refuse a request in whichever form the caller can actually read. A
	 * download link is a top-level navigation, not an XHR — answering it with
	 * wp_send_json_error() made the browser save the rejection as a file named
	 * "…​.wpress" containing JSON, which looks exactly like a corrupt backup.
	 *
	 * @param string $message
	 * @param bool   $is_navigation
	 * @return void
	 */
	private static function reject( $message, $is_navigation ) {
		if ( $is_navigation ) {
			wp_die( esc_html( $message ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		wp_send_json_error( array( 'message' => $message ) );
	}
}
