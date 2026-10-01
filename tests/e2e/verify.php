<?php
/**
 * Checks the e2e DESTINATION (https://dest.test, prefix dst_) after an
 * import of the source package. Exits non-zero on any failure.
 *
 * Run by tests/e2e/run.sh: wp eval-file verify.php <work-dir> <label>
 */

$work     = $args[0];
$label    = isset( $args[1] ) ? $args[1] : '';
$expected = json_decode( file_get_contents( "$work/expected.json" ), true );
$dst      = 'https://dest.test';
$fails    = 0;

wp_cache_flush();
$check = function ( $name, $ok, $detail = '' ) use ( &$fails, $label ) {
	if ( $ok ) {
		WP_CLI::log( "  PASS [$label] $name" );
	} else {
		$fails++;
		WP_CLI::log( "  FAIL [$label] $name" . ( $detail !== '' ? "  -> $detail" : '' ) );
	}
};

global $wpdb;
$check( 'siteurl/home are the destination\'s', get_option( 'siteurl' ) === $dst && get_option( 'home' ) === $dst, get_option( 'siteurl' ) . ' / ' . get_option( 'home' ) );
$check( 'tables live under the destination prefix', $wpdb->prefix === 'dst_' && (bool) $wpdb->get_var( "SHOW TABLES LIKE 'dst_posts'" ) );
$check( 'no source-prefixed tables were created', ! $wpdb->get_var( "SHOW TABLES LIKE 'wp\\_%'" ) );

$post = get_page_by_title( $expected['post_title'], OBJECT, 'post' );
$c    = $post ? $post->post_content : '';
$check( 'post with Thai title imported', (bool) $post );
$check( 'Thai text and emoji intact', strpos( $c, 'สวัสดี 🐘 ภาษาไทย' ) === 0, substr( $c, 0, 40 ) );
$check( 'http/https/protocol-relative URLs rewritten', strpos( $c, "$dst/page/" ) !== false && strpos( $c, "$dst/secure" ) !== false && strpos( $c, '//dest.test/wp-content/uploads/2026/01/photo.txt' ) !== false, $c );
$check( 'look-alike hosts untouched (source.test.au, source.testing.com)', strpos( $c, 'http://source.test.au/other' ) !== false && strpos( $c, 'http://source.testing.com/x' ) !== false, $c );
$check( 'no other source URL left in the post', substr_count( $c, 'source.test' ) === 2, $c );

$el = json_decode( (string) get_post_meta( $post ? $post->ID : 0, '_elementor_data', true ), true );
$check( 'escaped-slash JSON (Elementor) rewritten and still valid JSON', is_array( $el ) && $el[0]['settings']['url'] === "$dst/img.png", wp_json_encode( $el ) );

$double = get_option( 'isx_e2e_double' );
$inner  = is_string( $double ) ? @unserialize( $double, array( 'allowed_classes' => false ) ) : false;
$check( 'double-serialized option still unserializes, URL rewritten', is_array( $inner ) && $inner['logo'] === "$dst/logo.png" && $inner['n'] === 7, var_export( $double, true ) );
$obj = get_option( 'isx_e2e_object' );
$check( 'URL inside a stdClass rewritten', is_object( $obj ) && $obj->url === "$dst/obj.png" && $obj->label === 'object', var_export( $obj, true ) );
$check( 'plain option carried over', get_option( 'isx_e2e_marker' ) === 'from-source' );

$row = $wpdb->get_var( 'SELECT data FROM dst_isxe2e WHERE id = 1' );
$check( 'plugin table imported under the new prefix', $row !== null && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM dst_isxe2e' ) === $expected['table_rows'] );
$check( 'quotes/backslashes/emoji survive the SQL round trip', $row === "row with $dst/in-table and quote ' and backslash \\ and NUL-free 😀", (string) $row );
$check( 'all posts imported', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) === $expected['posts_count'] );

$uploads = wp_get_upload_dir()['basedir'];
foreach ( $expected['files'] as $rel => $sha ) {
	$check( "upload restored byte-for-byte: $rel", is_file( "$uploads/$rel" ) && hash_file( 'sha256', "$uploads/$rel" ) === $sha );
}
$css = (string) @file_get_contents( get_theme_root() . '/isx-e2e/style.css' );
$check( 'theme restored and its CSS URL rewritten', strpos( $css, "$dst/wp-content/uploads/bg.png" ) !== false, $css );

$check( 'bystander table (non-WordPress) untouched', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM orders' ) === 3 );
$check( 'another install sharing the prefix (dst_staging_) untouched', $wpdb->get_var( 'SELECT user_login FROM dst_staging_users WHERE ID = 1' ) === 'staging-admin' );

$user = wp_authenticate( 'admin', 'Src-Pass-1!' );
$check( 'source admin password works on the destination', $user instanceof WP_User );
$check( 'destination\'s own old password no longer works', ! ( wp_authenticate( 'admin', 'Dst-Pass-2!' ) instanceof WP_User ) );
$check( 'InsightX Backup still active after import', in_array( 'insightx-backup/insightx-backup.php', (array) get_option( 'active_plugins' ), true ) );
$check( 'source theme active', get_option( 'stylesheet' ) === 'isx-e2e' );

if ( $fails > 0 ) {
	WP_CLI::error( "$fails check(s) failed ($label)" );
}
WP_CLI::success( "all checks passed ($label)" );
