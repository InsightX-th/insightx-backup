<?php
/**
 * Plugin Name: InsightX Backup
 * Plugin URI: https://insightx.in.th/
 * Description: ย้าย/สำรอง WordPress ทั้งเว็บ (ฐานข้อมูล + ไฟล์) เป็นแพ็กเกจเดียว แล้ว import กลับหรือส่งขึ้น S3 ได้ — เขียนขึ้นใหม่ทั้งหมดโดย InsightX.
 * Version: 0.1.2
 * Author: InsightX
 * Author URI: https://insightx.in.th/
 * Text Domain: insightx-backup
 * License: GPLv3 or later
 *
 * Copyright (C) 2026 InsightX. Original work — not derived from any third-party plugin.
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ISX_VERSION', '0.1.2' );
define( 'ISX_FILE', __FILE__ );
define( 'ISX_PATH', plugin_dir_path( __FILE__ ) );
define( 'ISX_URL', plugin_dir_url( __FILE__ ) );

// === GitLab Plugin Update Checker ===
// Self-hosted GitLab (not gitlab.com), so PucFactory::buildUpdateChecker()'s
// URL-based auto-detection doesn't apply (it only recognizes the exact host
// "gitlab.com" and only accepts a URL string, not a pre-built API object) —
// instantiate the versioned VCS classes directly instead, same as PUC's own
// docs recommend for self-hosted/enterprise VCS. Public repo, no access
// token needed. CI (.gitlab-ci.yml) attaches a built zip to each tag's
// GitLab Release; enableReleaseAssets() makes updates install that zip
// instead of GitLab's raw (un-built) source archive for the tag.
require_once ISX_PATH . 'libs/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5p6\Vcs\GitLabApi as ISX_GitLabApi;
use YahnisElsts\PluginUpdateChecker\v5p6\Vcs\PluginUpdateChecker as ISX_VcsPluginUpdateChecker;

$isx_update_checker = new ISX_VcsPluginUpdateChecker(
	new ISX_GitLabApi( 'https://gitlab.insightx.dev/plugin-wordpress/insightx-backup' ),
	__FILE__,
	'insightx-backup'
);
$isx_update_checker->getVcsApi()->enableReleaseAssets();

/**
 * Where in-progress job data and finished local backups live. Defaults to the
 * plugin's own storage/ dir, but an admin can point it elsewhere from Settings
 * (see ISX_Admin::ajax_storage_dir_save()) so backups survive a plugin
 * delete/reinstall or land on a bigger disk. Falls back to the default and
 * forgets the option if the saved path is no longer usable.
 *
 * @return string
 */
function isx_resolve_storage_path() {
	$default = untrailingslashit( ISX_PATH . 'storage' );
	$custom  = get_option( 'isx_storage_path', '' );
	if ( $custom === '' ) {
		return $default;
	}
	$custom = untrailingslashit( $custom );
	$parent = dirname( $custom );
	if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
		delete_option( 'isx_storage_path' );
		return $default;
	}
	return $custom;
}
define( 'ISX_STORAGE_PATH', isx_resolve_storage_path() );

/**
 * Simple, explicit class loader (no reliance on any third-party autoloader).
 */
require_once ISX_PATH . 'includes/class-isx-logger.php';
require_once ISX_PATH . 'includes/class-isx-crypto.php';
require_once ISX_PATH . 'includes/class-isx-compress.php';
require_once ISX_PATH . 'includes/class-isx-serialize.php';
require_once ISX_PATH . 'includes/class-isx-archive.php';
require_once ISX_PATH . 'includes/class-isx-database.php';
require_once ISX_PATH . 'includes/class-isx-files.php';
require_once ISX_PATH . 'includes/class-isx-job.php';
require_once ISX_PATH . 'includes/class-isx-backups.php';
require_once ISX_PATH . 'includes/class-isx-destinations.php';
require_once ISX_PATH . 'includes/class-isx-s3-client.php';
require_once ISX_PATH . 'includes/class-isx-export.php';
require_once ISX_PATH . 'includes/class-isx-import.php';
require_once ISX_PATH . 'includes/class-isx-admin.php';

// The background driver hook must be registered on every request — including
// wp-cron.php runs, which are NOT is_admin() — otherwise a scheduled tick has
// no callback to invoke and a job would only ever advance while a browser
// tab is actively polling it.
add_action( 'isx_cron_step', array( 'ISX_Admin', 'cron_step' ) );
add_action( 'isx_scheduled_backup', array( 'ISX_Admin', 'run_scheduled_backup' ) );

if ( is_admin() ) {
	ISX_Admin::boot();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ISX_PATH . 'includes/class-isx-cli.php';
	// Registered on cli_init rather than directly here — All-in-One WP
	// Migration's own changelog notes moving their command registration from
	// plugins_loaded to cli_init specifically to fix ordering issues, so we
	// follow the same, already-proven-safe hook.
	add_action(
		'cli_init',
		function () {
			WP_CLI::add_command( 'isx', 'ISX_CLI_Command' );
		}
	);
}

/**
 * Activation: prepare a protected storage directory for in-progress jobs.
 */
function isx_activate() {
	if ( ! is_dir( ISX_STORAGE_PATH ) ) {
		wp_mkdir_p( ISX_STORAGE_PATH );
	}
	// Protect the storage directory from direct web access.
	$htaccess = ISX_STORAGE_PATH . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Deny from all\n" );
	}
	$index = ISX_STORAGE_PATH . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php // Silence is golden.\n" );
	}
}
register_activation_hook( __FILE__, 'isx_activate' );

/**
 * Deactivation: stop the scheduled-backup cron so it doesn't keep firing
 * (and failing to find its own hook) after the plugin is off.
 */
function isx_deactivate() {
	wp_clear_scheduled_hook( 'isx_scheduled_backup' );
}
register_deactivation_hook( __FILE__, 'isx_deactivate' );
