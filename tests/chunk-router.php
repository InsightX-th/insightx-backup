<?php
/**
 * `php -S` router for the chunk-upload test in tests/run.php: runs the real
 * ISX_Admin::ajax_import_chunk() against a stub job, because PHP's
 * is_uploaded_file() only accepts genuine multipart uploads.
 */
define( 'ABSPATH', '/' );
function __( $t ) { return $t; }
function apply_filters( $t, $v ) { return $v; }
function add_action() {}
function add_filter() {}
function is_multisite() { return false; }
function current_user_can() { return true; }
function check_ajax_referer() { return true; }
function sanitize_text_field( $s ) { return $s; }
function wp_unslash( $s ) { return $s; }
function wp_send_json_success( $d = null ) { echo json_encode( array( 'success' => true, 'data' => $d ) ); exit; }
function wp_send_json_error( $d = null ) { echo json_encode( array( 'success' => false, 'data' => $d ) ); exit; }
class ISX_Logger { public static function __callStatic( $n, $a ) {} }
class ISX_Job {
	public static function load( $id ) { return new self(); }
	public function get( $k ) { return 'import'; }
	public function id() { return 'isx_chunktest'; }
	public function archive() { return getenv( 'ISX_CHUNK_ARCHIVE' ); }
}
require dirname( __DIR__ ) . '/includes/class-isx-admin.php';
ISX_Admin::ajax_import_chunk();
