<?php
/**
 * Plugin Name: InsightX Backup
 * Plugin URI: https://insightx.in.th/
 * Description: ย้าย/สำรอง WordPress ทั้งเว็บ (ฐานข้อมูล + ไฟล์) เป็นแพ็กเกจเดียว แล้ว import กลับหรือส่งขึ้น S3 ได้ — เขียนขึ้นใหม่ทั้งหมดโดย InsightX.
 * Version: 0.1.14
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

define( 'ISX_VERSION', '0.1.14' );
define( 'ISX_FILE', __FILE__ );
define( 'ISX_PATH', plugin_dir_path( __FILE__ ) );
define( 'ISX_URL', plugin_dir_url( __FILE__ ) );

// === GitHub Plugin Update Checker ===
// gitlab.insightx.dev is an internal self-hosted GitLab, only reachable over
// the office LAN/VPN — customer sites (e.g. sounddd.shop) couldn't reach it
// to check for updates (cURL error 28 / puc-no-update-source). Moved to a
// public GitHub repo so update checks work from anywhere. GitHub is
// URL-autodetected by PucFactory, so no need to build the VCS API by hand.
// GitHub Actions (.github/workflows/release.yml) attaches a built zip to
// each tag's GitHub Release; enableReleaseAssets() makes updates install
// that zip instead of GitHub's raw (un-built) source archive for the tag.
require_once ISX_PATH . 'libs/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$isx_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/InsightX-th/insightx-backup',
	__FILE__,
	'insightx-backup'
);
$isx_update_checker->getVcsApi()->enableReleaseAssets();

/**
 * Where in-progress job data and finished local backups live. Defaults to the
 * plugin's own storage/ dir, but an admin can point it elsewhere from Settings
 * (see ISX_Admin::ajax_storage_dir_save()) so backups survive a plugin
 * delete/reinstall or land on a bigger disk. Falls back to the default when the
 * saved path isn't usable — but never rewrites the option to say so.
 *
 * Two things here used to make in-flight jobs vanish with "ไม่พบงาน":
 *
 *  1. The check asked whether the *parent* was writable. For the usual kind of
 *     custom path (/home/user/isx-storage) the parent is the account's home
 *     directory, which PHP normally cannot write to — so a perfectly good,
 *     writable storage directory failed the test.
 *  2. Failing the test called delete_option(). A getter that runs on every
 *     single request silently discarding the admin's setting meant
 *     ISX_STORAGE_PATH flipped back to the default mid-run, and every job
 *     already living under the custom path became unreachable.
 *
 * So: test the directory itself, and leave the option alone. Only
 * ISX_Admin::ajax_storage_dir_save() — where the admin is actually asking for a
 * change — may write it.
 *
 * @return string
 */
function isx_resolve_storage_path() {
	$default = untrailingslashit( ISX_PATH . 'storage' );
	$custom  = get_option( 'isx_storage_path', '' );
	if ( ! is_string( $custom ) || $custom === '' ) {
		return $default;
	}

	$custom = untrailingslashit( $custom );
	if ( is_dir( $custom ) && is_writable( $custom ) ) {
		return $custom;
	}

	// Not there yet (a fresh save, or a disk that came back after a reboot) —
	// try to create it before giving up on it.
	if ( ! is_dir( $custom ) && wp_mkdir_p( $custom ) && is_writable( $custom ) ) {
		return $custom;
	}

	$GLOBALS['isx_storage_path_fallback'] = $custom;
	return $default;
}
define( 'ISX_STORAGE_PATH', isx_resolve_storage_path() );

/**
 * Surface the fallback above, which is otherwise completely invisible: backups
 * quietly start landing somewhere other than where the admin pointed them.
 */
add_action(
	'admin_notices',
	function () {
		if ( empty( $GLOBALS['isx_storage_path_fallback'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>InsightX Backup:</strong> %s <code>%s</code> %s</p></div>',
			esc_html__( 'ใช้โฟลเดอร์เก็บข้อมูล', 'insightx-backup' ),
			esc_html( $GLOBALS['isx_storage_path_fallback'] ),
			esc_html__( 'ไม่ได้ (ไม่มีอยู่จริงหรือเขียนไม่ได้) — กำลังใช้โฟลเดอร์เริ่มต้นแทน กรุณาแก้ไขที่หน้าตั้งค่า', 'insightx-backup' )
		);
	}
);

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
require_once ISX_PATH . 'includes/class-isx-reset.php';
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
 * Deny-all rules for a storage directory.
 *
 * The previous one-liner was "Deny from all" — Apache 2.2 syntax, which on a
 * 2.4 server without mod_access_compat is not just ignored but a hard 500 for
 * everything in the directory. Emit both dialects, each guarded by the module
 * that understands it.
 *
 * Note this does nothing at all on nginx, which never reads .htaccess — the
 * unguessable file names from ISX_Backups::store() are what actually protect
 * backups there.
 *
 * @return string
 */
function isx_htaccess_deny_all() {
	return "<IfModule mod_authz_core.c>\n"
		. "\tRequire all denied\n"
		. "</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n"
		. "\tOrder allow,deny\n"
		. "\tDeny from all\n"
		. "</IfModule>\n";
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
	if ( ! file_exists( $htaccess ) || trim( (string) @file_get_contents( $htaccess ) ) === 'Deny from all' ) {
		file_put_contents( $htaccess, isx_htaccess_deny_all() );
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
