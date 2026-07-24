<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Reset Hub — destructive site-reset tools (plugin purge, theme reset, media
 * clean-up, database reset, full site reset). Pure logic only: no $_POST, no
 * AJAX, no capability checks — those live in ISX_Admin's ajax_reset_*()
 * handlers, which call into this class after guard()+password checks pass.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Reset {

	/**
	 * Deactivate and delete every plugin except InsightX Backup itself.
	 *
	 * @return array { ok, message, stats }
	 */
	public static function purge_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$self    = plugin_basename( ISX_FILE );
		$targets = array_values( array_diff( array_keys( get_plugins() ), array( $self ) ) );

		if ( empty( $targets ) ) {
			return array(
				'ok'      => true,
				'message' => 'ไม่มีปลั๊กอินอื่นให้ล้าง',
				'stats'   => array( 'count' => 0 ),
			);
		}

		deactivate_plugins( $targets, true );

		$deleted = delete_plugins( $targets );
		if ( is_wp_error( $deleted ) ) {
			return array(
				'ok'      => false,
				'message' => 'ลบไฟล์ปลั๊กอินไม่สำเร็จ: ' . $deleted->get_error_message(),
				'stats'   => array( 'count' => count( $targets ) ),
			);
		}

		return array(
			'ok'      => true,
			// translators: %d = จำนวนปลั๊กอินที่ถูกล้าง.
			'message' => sprintf( 'ล้างปลั๊กอินสำเร็จ %d รายการ', count( $targets ) ),
			'stats'   => array( 'count' => count( $targets ) ),
		);
	}

	/**
	 * Switch to the default WordPress theme, then delete every other theme.
	 *
	 * @return array { ok, message, stats }
	 */
	public static function reset_theme() {
		if ( ! function_exists( 'delete_theme' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$default   = wp_get_theme( WP_DEFAULT_THEME );
		$fallback  = $default->exists() ? $default : self::first_available_theme();
		if ( ! $fallback ) {
			return array(
				'ok'      => false,
				'message' => 'ไม่พบธีมเริ่มต้นที่จะสลับไปใช้',
				'stats'   => array( 'count' => 0 ),
			);
		}
		$default_stylesheet = $fallback->get_stylesheet();

		// Must switch away first — delete_theme() on the currently active
		// theme is refused by core.
		switch_theme( $default_stylesheet );
		remove_theme_mods();

		$deleted = 0;
		foreach ( wp_get_themes() as $stylesheet => $theme_obj ) {
			if ( $stylesheet === $default_stylesheet ) {
				continue;
			}
			$result = delete_theme( $stylesheet );
			if ( ! is_wp_error( $result ) ) {
				$deleted++;
			}
		}

		return array(
			'ok'      => true,
			// translators: %d = จำนวนธีมที่ถูกลบ.
			'message' => sprintf( 'รีเซ็ตธีมสำเร็จ ลบไป %d รายการ', $deleted ),
			'stats'   => array( 'count' => $deleted, 'active' => $default_stylesheet ),
		);
	}

	/**
	 * Delete every attachment (media file + postmeta + post row).
	 *
	 * @return array { ok, message, stats }
	 */
	public static function clean_media() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$deleted = 0;
		foreach ( $query->posts as $attachment_id ) {
			if ( wp_delete_attachment( $attachment_id, true ) ) {
				$deleted++;
			}
		}

		return array(
			'ok'      => true,
			// translators: %d = จำนวนไฟล์สื่อที่ถูกลบ.
			'message' => sprintf( 'ล้างคลังสื่อสำเร็จ ลบไป %d รายการ', $deleted ),
			'stats'   => array( 'count' => $deleted ),
		);
	}

	/**
	 * @return WP_Theme|null The first installed theme, used only if
	 *                       WP_DEFAULT_THEME itself isn't installed.
	 */
	private static function first_available_theme() {
		$themes = wp_get_themes();
		return ! empty( $themes ) ? reset( $themes ) : null;
	}

	/**
	 * Wipe every table this WordPress install owns and recreate a fresh
	 * default schema — the most destructive tool in Reset Hub. Runs
	 * synchronously in one request (unlike export/import's multi-request job
	 * polling) because the operation itself is fast (schema drop/recreate,
	 * not a large data copy); @set_time_limit(0) covers slower hosts.
	 *
	 * Re-creates the current admin account under its existing login/email and
	 * re-authenticates the browser's session *before* returning, so the
	 * request that triggered this never locks its own caller out. Preserves
	 * InsightX Backup's own options (storage path, schedule, S3 destinations)
	 * and the site's siteurl/home/admin_email, since losing those would make
	 * the freshly-reset site unreachable or strand existing local backups.
	 *
	 * @return array { ok, message, stats }
	 */
	public static function reset_database() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$current_user = wp_get_current_user();
		$preserve_login = $current_user->exists() ? $current_user->user_login : 'admin';
		$preserve_email = $current_user->exists() ? $current_user->user_email : get_option( 'admin_email' );

		// Site identity + this plugin's own config — captured before DROP TABLE
		// wipes wp_options, restored after populate_options() recreates it.
		$preserve = array(
			'siteurl'          => get_option( 'siteurl' ),
			'home'             => get_option( 'home' ),
			'admin_email'      => $preserve_email,
			'isx_storage_path' => get_option( 'isx_storage_path' ),
			'isx_schedule'     => get_option( 'isx_schedule' ),
			ISX_Destinations::OPTION_KEY => get_option( ISX_Destinations::OPTION_KEY ),
		);

		@set_time_limit( 0 );

		$tables = $wpdb->get_col( "SHOW TABLES LIKE '" . $wpdb->esc_like( $wpdb->prefix ) . "%'" );
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table comes from SHOW TABLES, not user input.
		}

		dbDelta( wp_get_db_schema( 'all' ) );

		populate_options();
		populate_roles();

		foreach ( $preserve as $key => $value ) {
			if ( $value !== false && $value !== '' ) {
				update_option( $key, $value );
			}
		}

		$new_password = wp_generate_password( 24 );
		$user_id      = wp_insert_user(
			array(
				'user_login' => $preserve_login,
				'user_email' => $preserve_email,
				'user_pass'  => $new_password,
				'role'       => 'administrator',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return array(
				'ok'      => false,
				'message' => 'รีเซ็ตฐานข้อมูลสำเร็จ แต่สร้างบัญชีผู้ดูแลใหม่ไม่สำเร็จ: ' . $user_id->get_error_message() . ' — กรุณาสร้างผู้ใช้ใหม่ผ่าน wp-admin/user-new.php หรือ WP-CLI',
				'stats'   => array(),
			);
		}

		// Re-authenticate this same request's session immediately — the DB
		// wipe above invalidated the old wp_users row this browser was
		// logged in as.
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		return array(
			'ok'      => true,
			'message' => 'รีเซ็ตฐานข้อมูลสำเร็จ',
			'stats'   => array(
				'admin_login'    => $preserve_login,
				'admin_password' => $new_password,
			),
		);
	}

	/**
	 * Run every tool in sequence: plugins → theme → media → database. The
	 * database wipe runs last on purpose — plugin purge still needs a working
	 * wp_options to write deactivate_plugins() into, media clean-up still
	 * needs a normal wp_posts to query, and the database step is the one
	 * step that can't be meaningfully retried if something upstream fails.
	 *
	 * @return array { ok, message, stats }
	 */
	public static function full_site_reset() {
		$stats = array();

		$plugins = self::purge_plugins();
		$stats['plugins'] = $plugins['stats'];
		if ( ! $plugins['ok'] ) {
			return array( 'ok' => false, 'message' => 'หยุดที่ขั้นตอนล้างปลั๊กอิน: ' . $plugins['message'], 'stats' => $stats );
		}

		$theme = self::reset_theme();
		$stats['theme'] = $theme['stats'];
		if ( ! $theme['ok'] ) {
			return array( 'ok' => false, 'message' => 'หยุดที่ขั้นตอนรีเซ็ตธีม: ' . $theme['message'], 'stats' => $stats );
		}

		$media = self::clean_media();
		$stats['media'] = $media['stats'];
		if ( ! $media['ok'] ) {
			return array( 'ok' => false, 'message' => 'หยุดที่ขั้นตอนล้างคลังสื่อ: ' . $media['message'], 'stats' => $stats );
		}

		$database = self::reset_database();
		$stats['database'] = $database['stats'];
		if ( ! $database['ok'] ) {
			return array( 'ok' => false, 'message' => 'หยุดที่ขั้นตอนรีเซ็ตฐานข้อมูล: ' . $database['message'], 'stats' => $stats );
		}

		return array(
			'ok'      => true,
			'message' => 'รีเซ็ตทั้งเว็บไซต์สำเร็จ',
			'stats'   => $stats,
		);
	}
}
