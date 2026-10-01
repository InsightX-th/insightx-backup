<?php
/**
 * Reset Hub "database" on the e2e DESTINATION, then checks it: the plugin
 * stays active (so the one-time password can be revealed), the revealed
 * password logs in, and tables of other installs/apps in the same database
 * survive.
 *
 * Run by tests/e2e/run.sh: wp eval-file reset-check.php
 */

global $wpdb;
$fails = 0;
$check = function ( $name, $ok, $detail = '' ) use ( &$fails ) {
	WP_CLI::log( ( $ok ? '  PASS' : '  FAIL' ) . " [reset] $name" . ( ! $ok && $detail !== '' ? "  -> $detail" : '' ) );
	if ( ! $ok ) {
		$fails++;
	}
};

wp_set_current_user( 1 );
$result = ISX_Reset::reset_database();
wp_cache_flush();

$check( 'database reset reports success', ! empty( $result['ok'] ), wp_json_encode( $result ) );
$check( 'InsightX Backup still active afterwards', in_array( 'insightx-backup/insightx-backup.php', (array) get_option( 'active_plugins' ), true ), wp_json_encode( get_option( 'active_plugins' ) ) );

$token  = isset( $result['stats']['admin_password_token'] ) ? $result['stats']['admin_password_token'] : '';
$stored = $token !== '' ? get_transient( 'isx_reset_pw_' . $token ) : false;
$pass   = $stored ? ISX_Crypto::decrypt_string( (string) $stored ) : '';
$check( 'one-time password token can be revealed', strlen( $pass ) >= 20 );
$check( 'revealed password logs in as the preserved admin', $pass !== '' && wp_authenticate( $result['stats']['admin_login'], $pass ) instanceof WP_User );
$check( 'site URL preserved', get_option( 'siteurl' ) === 'https://dest.test' );
$check( 'bystander table (non-WordPress) untouched', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM orders' ) === 3 );
$check( 'another install sharing the prefix (dst_staging_) untouched', $wpdb->get_var( 'SELECT user_login FROM dst_staging_users WHERE ID = 1' ) === 'staging-admin' );
$check( 'imported content is gone (it was a reset)', ! get_option( 'isx_e2e_marker' ) );

if ( $fails > 0 ) {
	WP_CLI::error( "$fails check(s) failed (reset)" );
}
WP_CLI::success( 'all checks passed (reset)' );
