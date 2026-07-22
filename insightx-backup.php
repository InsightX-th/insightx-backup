<?php
/**
 * Plugin Name: InsightX Backup
 * Plugin URI: https://insightx.in.th/
 * Description: ย้าย/สำรอง WordPress ทั้งเว็บ (ฐานข้อมูล + ไฟล์) เป็นแพ็กเกจเดียว แล้ว import กลับหรือส่งขึ้น S3 ได้ — เขียนขึ้นใหม่ทั้งหมดโดย InsightX.
 * Version: 0.1.0
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

define( 'ISX_VERSION', '0.1.0' );
define( 'ISX_FILE', __FILE__ );
define( 'ISX_PATH', plugin_dir_path( __FILE__ ) );
define( 'ISX_URL', plugin_dir_url( __FILE__ ) );
define( 'ISX_STORAGE_PATH', ISX_PATH . 'storage' );

/**
 * Simple, explicit class loader (no reliance on any third-party autoloader).
 */
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

if ( is_admin() ) {
	ISX_Admin::boot();
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
