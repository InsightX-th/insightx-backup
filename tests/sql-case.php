<?php
/**
 * Runs ISX_Database::import_line() for the dump lines given on stdin (JSON
 * array) against a fake $wpdb, and prints every SQL statement that would
 * have been sent to MySQL (JSON array). Used by tests/run.php — the suite's
 * own ISX_Database is a stub, so the real class runs in this process.
 */
define( 'ABSPATH', '/' );
function __( $t ) { return $t; }
function is_multisite() { return false; }
class ISX_Logger { public static function __callStatic( $n, $a ) {} }
class ISX_Serialize { public static function replace( $v ) { return $v; } }
class Fake_WPDB {
	public $prefix = 'wp_';
	public $dbh = null;
	public $last_error = '';
	public $sent = array();
	public $tables = array( 'wp_options', 'wp_posts', 'wp_users', 'wp_staging_options', 'wp_staging_posts', 'wp_staging_users', 'orders' );
	public function query( $sql ) { $this->sent[] = $sql; return 1; }
	public function get_col( $sql ) { return $this->tables; }
	public function suppress_errors( $s = true ) { return false; }
	public function _real_escape( $s ) { return addslashes( $s ); }
	public function remove_placeholder_escape( $s ) { return $s; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
require dirname( __DIR__ ) . '/includes/class-isx-database.php';
foreach ( json_decode( stream_get_contents( STDIN ), true ) as $line ) {
	ISX_Database::import_line( $line, array(), array(), 'wp_', 'wp_' );
}
echo json_encode( $GLOBALS['wpdb']->sent );
