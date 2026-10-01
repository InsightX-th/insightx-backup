<?php
/**
 * Seeds the e2e SOURCE site (http://source.test) with data that tends to
 * break migrations, and writes what the destination must end up with to
 * <work>/expected.json for verify.php.
 *
 * Run by tests/e2e/run.sh: wp eval-file seed.php <work-dir>
 */

$work = $args[0];
$src  = 'http://source.test';

// Content with every URL form, plus look-alike hosts that must NOT change.
$content = "สวัสดี 🐘 ภาษาไทย\n"
	. "<a href=\"$src/page/\">abs</a> <a href=\"https://source.test/secure\">https</a> "
	. "<img src=\"//source.test/wp-content/uploads/2026/01/photo.txt\"> "
	. "<a href=\"http://source.test.au/other\">au</a> <a href=\"http://source.testing.com/x\">other</a>";
$post_id = wp_insert_post( array( 'post_title' => 'E2E ไทย', 'post_content' => $content, 'post_status' => 'publish' ) );

// Page-builder JSON with escaped slashes (Elementor style).
update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( array( array( 'settings' => array( 'url' => "$src/img.png" ) ) ) ) ) );

// A value serialized twice (a plugin that serialize()s before update_option()).
update_option( 'isx_e2e_double', serialize( array( 'logo' => "$src/logo.png", 'n' => 7 ) ) );
// A plain object.
$obj        = new stdClass();
$obj->url   = "$src/obj.png";
$obj->label = 'object';
update_option( 'isx_e2e_object', $obj );
update_option( 'isx_e2e_marker', 'from-source' );

// A plugin's own table.
global $wpdb;
$wpdb->query( "CREATE TABLE {$wpdb->prefix}isxe2e (id INT PRIMARY KEY, data LONGTEXT) DEFAULT CHARSET=utf8mb4" );
$wpdb->insert( $wpdb->prefix . 'isxe2e', array( 'id' => 1, 'data' => "row with $src/in-table and quote ' and backslash \\ and NUL-free 😀" ) );

// Uploads, including a 0-byte file.
$uploads = wp_get_upload_dir()['basedir'];
wp_mkdir_p( "$uploads/2026/01" );
file_put_contents( "$uploads/2026/01/photo.txt", str_repeat( 'photo', 1000 ) );
file_put_contents( "$uploads/2026/01/empty.bin", '' );
file_put_contents( "$uploads/bg.png", random_bytes( 4096 ) );

$files = array();
foreach ( array( '2026/01/photo.txt', '2026/01/empty.bin', 'bg.png' ) as $rel ) {
	$files[ $rel ] = hash_file( 'sha256', "$uploads/$rel" );
}

file_put_contents( "$work/expected.json", wp_json_encode( array(
	'post_title'  => 'E2E ไทย',
	'files'       => $files,
	'posts_count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
	'table_rows'  => 1,
) ) );

WP_CLI::success( "seeded source (post #$post_id)" );
