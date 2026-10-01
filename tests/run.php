<?php
/**
 * Test suite for the import restore pipeline (v0.1.21+).
 *
 * Covers the fixes that stopped an import from deleting freshly-restored
 * themes/fonts/customizations:
 *
 *   - restore_stream() never overwrites the plugin driving the import (the
 *     package carries the plugin's own files, and restoring them mid-extract
 *     swaps the running code for the version the source exported).
 *   - sweep_deferred() decides "old site leftover" from the job's restored.list
 *     instead of file mtimes, and fails safe when the log is missing.
 *   - reassert_package_theme() re-applies the package's template/stylesheet/
 *     active_plugins from the manifest at the end (like All-in-One WP Migration).
 *
 * Loads the REAL ISX_Archive / ISX_Files / ISX_Import classes; stubs only
 * WordPress functions and the ISX_Job / ISX_Logger / ISX_Database contracts.
 * Self-contained (no framework): run with `php tests/run.php`, exits non-zero
 * on any failure. CI runs this on every push/PR (see .github/workflows/tests.yml).
 *
 * Run: php tests/run.php
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', sys_get_temp_dir() . '/isx_wp/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ISX_PATH', WP_CONTENT_DIR . '/plugins/insightx-backup' );
define( 'ISX_STORAGE_PATH', ISX_PATH . '/storage' );
define( 'ISX_FILE', ISX_PATH . '/insightx-backup.php' );

// ---------- WordPress function stubs ----------
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function wp_mkdir_p( $dir ) { return @mkdir( $dir, 0777, true ) || is_dir( $dir ); }
function apply_filters( $tag, $value ) { return $value; }
function plugin_basename( $file ) { return 'insightx-backup/insightx-backup.php'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function __( $text, $domain = 'default' ) { return $text; }
function delete_option( $k ) { unset( $GLOBALS['isx_opts'][ $k ] ); return true; }
function wp_cache_flush() { return true; }
function maybe_unserialize( $v ) { return $v; }
function maybe_serialize( $v ) { return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['isx_opts'] ) ? $GLOBALS['isx_opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['isx_opts'][ $k ] = $v; return true; }
$GLOBALS['isx_opts'] = array();

// ---------- Stub classes (contracts only) ----------
class ISX_Job {
	public $state = array();
	public $calls = array();
	public function __construct( $state = array() ) { $this->state = $state; }
	public function id() { return 'isx_testjob1234567890'; }
	public function archive() { return sys_get_temp_dir() . '/isx_job/archive.wpress'; }
	public function db_dump() { return sys_get_temp_dir() . '/isx_job/database.isxdb'; }
	public function restored_list() { return sys_get_temp_dir() . '/isx_job/restored.list'; }
	public function clean_list() { return sys_get_temp_dir() . '/isx_job/clean.list'; }
	public function preserved_options() { return sys_get_temp_dir() . '/isx_job/preserved.json'; }
	public function get( $k, $d = null ) { return array_key_exists( $k, $this->state ) ? $this->state[ $k ] : $d; }
	public function set( $k, $v ) { $this->state[ $k ] = $v; return $this; }
	public function save() { return true; }
	public function is_cancel_requested() { return false; }
	public function finish( $m, $e = false ) { $this->calls['finish'] = array( $m, $e ); $this->state['step'] = 'done'; return null; }
}
class ISX_JobE2E extends ISX_Job { // archive path override for the extract() e2e test.
	public $arc;
	public function archive() { return $this->arc; }
}
class ISX_Logger {
	public static $calls = array();
	public static function log( $lvl, $type, $msg, $ctx = array() ) { self::$calls[] = array( $lvl, $type, $msg, $ctx ); }
	public static function log_debug( $t, $m, $c = array() ) { self::log( 'debug', $t, $m, $c ); }
	public static function log_info( $t, $m, $c = array() ) { self::log( 'info', $t, $m, $c ); }
	public static function log_warn( $t, $m, $c = array() ) { self::log( 'warn', $t, $m, $c ); }
	public static function log_error( $t, $m, $c = array() ) { self::log( 'error', $t, $m, $c ); }
}
class ISX_Database {
	public static $calls = array();
	public static function drop_extra_tables( $tables ) { self::$calls[] = $tables; }
}

// ---------- Load the real classes (this file lives in tests/, plugin root is one up) ----------
$PLUGIN = dirname( __DIR__ );
require $PLUGIN . '/includes/class-isx-archive.php';
require $PLUGIN . '/includes/class-isx-files.php';
require $PLUGIN . '/includes/class-isx-serialize.php';
require $PLUGIN . '/includes/class-isx-import.php';

$PASS = 0; $FAIL = 0; $group = '';
function t( $name, $cond, $extra = '' ) {
	global $PASS, $FAIL, $group;
	echo ( $cond ? '  PASS' : '  FAIL' ) . " [$group] $name" . ( $extra !== '' ? "  -> $extra" : '' ) . "\n";
	$cond ? $PASS++ : $FAIL++;
}
function group( $g ) { global $group; $group = $g; echo "\n=== $g ===\n"; }

$JOB_DIR = sys_get_temp_dir() . '/isx_job';
@mkdir( $JOB_DIR, 0777, true );
@mkdir( WP_CONTENT_DIR, 0777, true );

// =====================================================================
group( 'GROUP 1: restore_stream() — self-skip + logging + URL rewrite' );
// =====================================================================
$GLOBALS['isx_opts'] = array();
ISX_Files::set_restored_log( $JOB_DIR . '/restored.list' );
@unlink( $JOB_DIR . '/restored.list' );
ISX_Files::set_url_replace_map( array( 'http://old.example.com' => 'http://new.example.com' ) );

$arc = $JOB_DIR . '/archive.wpress';
@unlink( $arc );
ISX_Archive::init( $arc );

$src = $JOB_DIR . '/src';
@mkdir( "$src/self", 0777, true );
@mkdir( "$src/fonts", 0777, true );
file_put_contents( "$src/self/insightx-backup.php", '<?php // self code' );
file_put_contents( "$src/self/storage-data.bin", str_repeat( "\x00\x01", 64 ) );
file_put_contents( "$src/zip.zip", 'ZIPBYTES' );
file_put_contents( "$src/style.css", 'body{background:url(http://old.example.com/wp-content/uploads/fonts/x.woff2)}' );
file_put_contents( "$src/fonts/body.woff2", "\x00\x01\x02\x03WOFF" );
file_put_contents( "$src/index.php", '<?php // silence' );

ISX_Archive::add_file( $arc, "$src/self/insightx-backup.php", 'wpcontent/plugins/insightx-backup/insightx-backup.php', false );
ISX_Archive::add_file( $arc, "$src/self/storage-data.bin", 'wpcontent/plugins/insightx-backup/storage/data.bin', false );
ISX_Archive::add_file( $arc, "$src/zip.zip", 'wpcontent/plugins/insightx-backup.zip', false );
ISX_Archive::add_file( $arc, "$src/style.css", 'wpcontent/themes/fruit3/style.css', true );
ISX_Archive::add_file( $arc, "$src/fonts/body.woff2", 'wpcontent/uploads/fonts/body.woff2', true );
ISX_Archive::add_file( $arc, "$src/index.php", 'wpcontent/index.php', false );
ISX_Archive::add_data( $arc, 'package.json', '{"siteurl":"http://old.example.com"}' );
ISX_Archive::add_data( $arc, 'database.sql', 'CREATE TABLE x;' );
ISX_Archive::finish( $arc );

$db_dump = $JOB_DIR . '/database.isxdb';
@unlink( $db_dump );
ISX_Archive::each(
	$arc,
	function ( $h, $fh ) use ( $db_dump ) {
		if ( $h['p'] === 'database.sql' ) {
			return ISX_Archive::stream_entry_to_file( $fh, $h, $db_dump );
		}
		return ISX_Files::restore_stream( $h, $fh );
	}
);

t( 'self plugin file NOT written', ! file_exists( WP_CONTENT_DIR . '/plugins/insightx-backup/insightx-backup.php' ) );
t( 'self storage data NOT written', ! file_exists( WP_CONTENT_DIR . '/plugins/insightx-backup/storage/data.bin' ) );
t( 'sibling zip restored', file_exists( WP_CONTENT_DIR . '/plugins/insightx-backup.zip' ) );
t( 'theme css restored', file_exists( WP_CONTENT_DIR . '/themes/fruit3/style.css' ) );
$css = file_get_contents( WP_CONTENT_DIR . '/themes/fruit3/style.css' );
t( 'theme css old URL rewritten', strpos( $css, 'http://new.example.com/wp-content/uploads/fonts/x.woff2' ) !== false, $css );
t( 'binary font restored byte-exact', file_get_contents( WP_CONTENT_DIR . '/uploads/fonts/body.woff2' ) === "\x00\x01\x02\x03WOFF" );
t( 'drop-in index.php restored', file_exists( WP_CONTENT_DIR . '/index.php' ) );
t( 'database.sql extracted to job dir', file_exists( $db_dump ) );

$log = file( $JOB_DIR . '/restored.list', FILE_IGNORE_NEW_LINES );
$log_norm = implode( "\n", $log );
t( 'restored.list has exactly 4 entries (zip, css, woff2, index.php)', count( $log ) === 4, implode( ';', $log ) );
t( 'restored.list excludes self plugin', strpos( $log_norm, 'insightx-backup/insightx-backup.php' ) === false && strpos( $log_norm, 'storage/data.bin' ) === false );
t( 'restored.list includes theme css', strpos( $log_norm, 'themes/fruit3/style.css' ) !== false );
t( 'restored.list includes font', strpos( $log_norm, 'uploads/fonts/body.woff2' ) !== false );

// =====================================================================
group( 'GROUP 2: sweep_deferred() — restored-list semantics' );
// =====================================================================
$content = WP_CONTENT_DIR;
$rl = $JOB_DIR . '/restored.list';

// B1: restored theme kept; old theme files swept; target plugins kept when package restored none.
foreach ( array( "$content/themes/oldtheme/style.css" ) as $f ) { wp_mkdir_p( dirname( $f ) ); file_put_contents( $f, 'x' ); }
$d = ISX_Files::sweep_deferred(
	array( "$content/themes/fruit3", "$content/themes/oldtheme", "$content/plugins/oldplug" ),
	$rl
);
t( 'B1 restored theme kept', file_exists( "$content/themes/fruit3/style.css" ) );
t( 'B1 old theme files swept', ! file_exists( "$content/themes/oldtheme/style.css" ) );
t( 'B1 deleted count == 1', $d === 1, $d );

// B2: package restored plugins -> dropped plugin swept, restored plugin kept.
wp_mkdir_p( "$content/plugins/kept" ); wp_mkdir_p( "$content/plugins/dropped" );
file_put_contents( "$content/plugins/kept/a.php", 'x' );
file_put_contents( "$content/plugins/dropped/b.php", 'x' );
file_put_contents( $rl, $rl . "\n" . "$content/plugins/kept/a.php\n" ); // append kept entry to log
ISX_Files::sweep_deferred( array( "$content/plugins/kept", "$content/plugins/dropped" ), $rl );
t( 'B2 restored plugin kept', file_exists( "$content/plugins/kept/a.php" ) );
t( 'B2 dropped plugin swept', ! file_exists( "$content/plugins/dropped/b.php" ) );

// B3: empty / missing log -> sweep deletes NOTHING.
wp_mkdir_p( "$content/themes/zz" );
file_put_contents( "$content/themes/zz/style.css", 'x' );
$d = ISX_Files::sweep_deferred( array( "$content/themes/zz" ), sys_get_temp_dir() . '/nonexistent.list' );
t( 'B3 empty log -> nothing deleted', $d === 0 && file_exists( "$content/themes/zz/style.css" ), $d );

// B4: backslash-normalized log paths (Windows) still match.
wp_mkdir_p( "$content/themes/win" );
file_put_contents( "$content/themes/win/a.css", 'x' );
file_put_contents( "$content/themes/win/b.css", 'x' );
file_put_contents( sys_get_temp_dir() . '/win.list', str_replace( '/', '\\', "$content/themes/win/a.css" ) . "\n" );
ISX_Files::sweep_deferred( array( "$content/themes/win" ), sys_get_temp_dir() . '/win.list' );
t( 'B4 windows-slash restored file kept', file_exists( "$content/themes/win/a.css" ) );
t( 'B4 windows-slash unreported file swept', ! file_exists( "$content/themes/win/b.css" ) );

// B5: protected paths never swept even when not in the log.
wp_mkdir_p( "$content/plugins/insightx-backup/storage" );
file_put_contents( "$content/plugins/insightx-backup/storage/keep.bin", 'x' );
ISX_Files::sweep_deferred( array( "$content/plugins/insightx-backup" ), sys_get_temp_dir() . '/nonexistent.list' );
t( 'B5 protected plugin dir never swept', file_exists( "$content/plugins/insightx-backup/storage/keep.bin" ) );

// =====================================================================
group( 'GROUP 3: reassert_package_theme() — manifest re-assertion' );
// =====================================================================
$rm = new ReflectionMethod( 'ISX_Import', 'reassert_package_theme' );
$rm->setAccessible( true );

// C1: full manifest re-asserts stylesheet/template/plugins.
$GLOBALS['isx_opts'] = array( 'stylesheet' => 'junk', 'template' => 'junk', 'active_plugins' => array( 'x.php' ) );
$job = new ISX_Job( array( 'manifest' => array(
	'stylesheet'      => 'fruit3',
	'template'        => 'plant3',
	'active_plugins'  => array( 'woo/woo.php', 'seo/seo.php', 'woo/woo.php' ), // duplicate on purpose
) ) );
$rm->invoke( null, $job );
t( 'C1 stylesheet re-asserted', $GLOBALS['isx_opts']['stylesheet'] === 'fruit3', $GLOBALS['isx_opts']['stylesheet'] );
t( 'C1 template re-asserted', $GLOBALS['isx_opts']['template'] === 'plant3', $GLOBALS['isx_opts']['template'] );
t( 'C1 active_plugins re-asserted, unique', $GLOBALS['isx_opts']['active_plugins'] === array( 'woo/woo.php', 'seo/seo.php' ), json_encode( $GLOBALS['isx_opts']['active_plugins'] ) );

// C2: empty strings / missing keys -> options untouched (old packages).
$GLOBALS['isx_opts'] = array( 'stylesheet' => 'keep-me', 'template' => 'keep-me' );
$job = new ISX_Job( array( 'manifest' => array( 'stylesheet' => '', 'template' => null ) ) );
$rm->invoke( null, $job );
t( 'C2 empty stylesheet skipped', $GLOBALS['isx_opts']['stylesheet'] === 'keep-me' );
t( 'C2 null template skipped', $GLOBALS['isx_opts']['template'] === 'keep-me' );

// C3: no manifest keys at all -> no-op, no fatal (pre-0.1.21 package).
$GLOBALS['isx_opts'] = array( 'stylesheet' => 'keep', 'template' => 'keep' );
$job = new ISX_Job( array( 'manifest' => array( 'siteurl' => 'http://x' ) ) );
$rm->invoke( null, $job );
t( 'C3 old package -> untouched', $GLOBALS['isx_opts']['stylesheet'] === 'keep' && $GLOBALS['isx_opts']['template'] === 'keep' );

// =====================================================================
group( 'GROUP 4: extract() end-to-end — wiring, self-skip, manifest, db dump' );
// =====================================================================
$arc2 = $JOB_DIR . '/e2e/archive.wpress';
@mkdir( $JOB_DIR . '/e2e', 0777, true );
@unlink( $arc2 );
ISX_Archive::init( $arc2 );
file_put_contents( $JOB_DIR . '/e2e/self.php', 'SELF' );
file_put_contents( $JOB_DIR . '/e2e/theme.css', 'body{font:url(http://old.example.com/wp-content/uploads/fonts/body.woff2)}' );
file_put_contents( $JOB_DIR . '/e2e/font.woff2', 'FONTDATA' );
ISX_Archive::add_data( $arc2, 'package.json', json_encode( array(
	'siteurl'     => 'http://old.example.com',
	'home'        => 'http://old.example.com',
	'content_url' => 'http://old.example.com/wp-content',
	'uploads_url' => 'http://old.example.com/wp-content/uploads',
	'template'    => 'plant3',
	'stylesheet'  => 'fruit3',
	'active_plugins' => array( 'woo/woo.php' ),
	'total_files' => 3,
) ) );
ISX_Archive::add_data( $arc2, 'database.sql', "CREATE TABLE wp_options (...);\n" );
ISX_Archive::add_file( $arc2, $JOB_DIR . '/e2e/self.php', 'wpcontent/plugins/insightx-backup/insightx-backup.php', false );
ISX_Archive::add_file( $arc2, $JOB_DIR . '/e2e/theme.css', 'wpcontent/themes/fruit3/style.css', true );
ISX_Archive::add_file( $arc2, $JOB_DIR . '/e2e/font.woff2', 'wpcontent/uploads/fonts/body.woff2', true );
ISX_Archive::finish( $arc2 );

$job = new ISX_JobE2E( array(
	'target' => array(
		'siteurl'     => 'http://new.example.com',
		'home'        => 'http://new.example.com',
		'content_url' => 'http://new.example.com/wp-content',
		'uploads_url' => 'http://new.example.com/wp-content/uploads',
	),
) );
$job->arc = $arc2;

$ex = new ReflectionMethod( 'ISX_Import', 'extract' );
$ex->setAccessible( true );
// Fresh import: the log must not exist yet (extract creates it empty). The
// earlier groups used the same job dir, so clear it to simulate a new job.
@unlink( $JOB_DIR . '/restored.list' );
$res = $ex->invoke( null, $job );

t( 'D1 extract completed (step -> database)', $job->get( 'step' ) === 'database', $job->get( 'step' ) );
t( 'D2 self plugin NOT on disk', ! file_exists( WP_CONTENT_DIR . '/plugins/insightx-backup/insightx-backup.php' ) );
t( 'D3 theme css restored', file_exists( WP_CONTENT_DIR . '/themes/fruit3/style.css' ) );
$css2 = file_get_contents( WP_CONTENT_DIR . '/themes/fruit3/style.css' );
t( 'D4 theme css URL rewritten to new domain', strpos( $css2, 'http://new.example.com/wp-content/uploads/fonts/body.woff2' ) !== false, $css2 );
t( 'D5 font restored', file_get_contents( WP_CONTENT_DIR . '/uploads/fonts/body.woff2' ) === 'FONTDATA' );
t( 'D6 db dump extracted', file_exists( $JOB_DIR . '/database.isxdb' ) );
$rl2 = file( $JOB_DIR . '/restored.list', FILE_IGNORE_NEW_LINES );
t( 'D7 restored.list has theme + font only', count( $rl2 ) === 2 && implode( "\n", $rl2 ) === WP_CONTENT_DIR . '/themes/fruit3/style.css' . "\n" . WP_CONTENT_DIR . '/uploads/fonts/body.woff2' );
$mf = $job->get( 'manifest', array() );
t( 'D8 manifest persisted incl. theme keys', isset( $mf['stylesheet'] ) && $mf['stylesheet'] === 'fruit3' && $mf['active_plugins'] === array( 'woo/woo.php' ) );

// =====================================================================
group( 'GROUP 5: finalize() end-to-end — sweep call, reassert guard, lockout safety net' );
// =====================================================================
// E1: package WITH database -> sweep runs, reassert runs, lockout handled.
$GLOBALS['isx_opts'] = array(
	'active_plugins' => array( 'woo/woo.php', 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'insightx-backup/insightx-backup.php' ),
);
ISX_Database::$calls = array();
ISX_Logger::$calls = array();

wp_mkdir_p( "$content/themes/fruit3" );
file_put_contents( "$content/themes/fruit3/leftover.js", 'x' );
file_put_contents( $JOB_DIR . '/restored.list', "$content/themes/fruit3/style.css\n" );

$job = new ISX_Job( array(
	'imported_tables' => array( 'wp_options' ),
	'deferred_roots'  => array( "$content/themes/fruit3" ),
	'manifest'        => array(
		'stylesheet'      => 'fruit3',
		'template'        => 'plant3',
		'active_plugins'  => array( 'woo/woo.php', 'seo/seo.php' ),
	),
	'target'          => array( 'siteurl' => 'http://new.example.com' ),
) );
$fin = new ReflectionMethod( 'ISX_Import', 'finalize' );
$fin->setAccessible( true );
$res = $fin->invoke( null, $job );

t( 'E1 sweep deleted the leftover', ! file_exists( "$content/themes/fruit3/leftover.js" ) );
t( 'E1 restored theme file kept', file_exists( "$content/themes/fruit3/style.css" ) );
t( 'E1 drop_extra_tables called', count( ISX_Database::$calls ) === 1 && ISX_Database::$calls[0] === array( 'wp_options' ) );
t( 'E1 stylesheet re-asserted', $GLOBALS['isx_opts']['stylesheet'] === 'fruit3' );
t( 'E1 active_plugins re-asserted (source list)', in_array( 'seo/seo.php', $GLOBALS['isx_opts']['active_plugins'], true ) );
t( 'E1 lockout SSL plugin removed', ! in_array( 'really-simple-ssl/rlrsssl-really-simple-ssl.php', $GLOBALS['isx_opts']['active_plugins'], true ) );
t( 'E1 self plugin re-added by safety net', in_array( 'insightx-backup/insightx-backup.php', $GLOBALS['isx_opts']['active_plugins'], true ), json_encode( $GLOBALS['isx_opts']['active_plugins'] ) );
t( 'E1 job finished', isset( $job->calls['finish'] ) && $job->calls['finish'][1] === false );

// E2: package WITHOUT database -> no drop, no reassert, sweep still runs.
$GLOBALS['isx_opts'] = array( 'stylesheet' => 'target-theme', 'template' => 'target-theme', 'active_plugins' => array( 'a/a.php' ) );
ISX_Database::$calls = array();
wp_mkdir_p( "$content/themes/fruit3" );
file_put_contents( "$content/themes/fruit3/leftover.js", 'x' );
file_put_contents( $JOB_DIR . '/restored.list', "$content/themes/fruit3/style.css\n" );
$job = new ISX_Job( array(
	'imported_tables' => array(), // no database carried
	'deferred_roots'  => array( "$content/themes/fruit3" ),
	'manifest'        => array( 'stylesheet' => 'fruit3', 'template' => 'plant3', 'active_plugins' => array( 'woo/woo.php' ) ),
	'target'          => array( 'siteurl' => 'http://new.example.com' ),
) );
$fin->invoke( null, $job );
t( 'E2 no drop_extra_tables', count( ISX_Database::$calls ) === 0 );
t( 'E2 no reassert (target theme kept)', $GLOBALS['isx_opts']['stylesheet'] === 'target-theme' );
t( 'E2 sweep still ran', ! file_exists( "$content/themes/fruit3/leftover.js" ) );

// =====================================================================
group( 'SECURITY 1: restore_stream() refuses traversal paths (zip-slip)' );
// =====================================================================
$evil = $JOB_DIR . '/evil.wpress';
@unlink( $evil );
ISX_Archive::init( $evil );
$evil_names = array(
	'wpcontent/../zipslip-wp-config.php',
	'wpcontent/uploads/../../zipslip-outside.php',
	'wpcontent/plugins/./insightx-backup/insightx-backup.php',
	'wpcontent/uploads//double.php',
	'wpcontent/uploads\\..\\..\\zipslip-backslash.php',
	'wpcontent/uploads/ok.txt',
);
// ISX_Archive's writer sanitizes names itself, so build the entries with
// placeholders and then patch the raw headers the way an attacker's own
// tool would: new JSON name + the matching 4-byte length prefix.
foreach ( $evil_names as $i => $name ) {
	ISX_Archive::add_data( $evil, "wpcontent/placeholder$i.txt", '<?php // evil' );
}
ISX_Archive::finish( $evil );
$raw = file_get_contents( $evil );
foreach ( $evil_names as $i => $name ) {
	$needle = '"p":"wpcontent\/placeholder' . $i . '.txt"';
	$pos    = strpos( $raw, $needle );
	$start  = strrpos( substr( $raw, 0, $pos ), '{' );
	$len    = unpack( 'V', substr( $raw, $start - 4, 4 ) )[1];
	$json   = str_replace( $needle, '"p":' . json_encode( $name ), substr( $raw, $start, $len ) );
	$raw    = substr( $raw, 0, $start - 4 ) . pack( 'V', strlen( $json ) ) . $json . substr( $raw, $start + $len );
}
file_put_contents( $evil, $raw );
$results = array();
ISX_Archive::each( $evil, function ( $h, $fh ) use ( &$results ) {
	$results[ $h['p'] ] = ISX_Files::restore_stream( $h, $fh );
	return true;
} );
t( 'Z1 "../" out of wp-content refused', $results['wpcontent/../zipslip-wp-config.php'] === null && ! file_exists( dirname( WP_CONTENT_DIR ) . '/zipslip-wp-config.php' ) );
t( 'Z2 deeper "../../" refused', $results['wpcontent/uploads/../../zipslip-outside.php'] === null && ! file_exists( dirname( WP_CONTENT_DIR ) . '/zipslip-outside.php' ) );
t( 'Z3 "./" cannot sneak past self-protection', $results['wpcontent/plugins/./insightx-backup/insightx-backup.php'] === null );
t( 'Z4 empty segment refused', $results['wpcontent/uploads//double.php'] === null );
t( 'Z5 backslash traversal refused', $results['wpcontent/uploads\\..\\..\\zipslip-backslash.php'] === null && ! file_exists( dirname( WP_CONTENT_DIR ) . '/zipslip-backslash.php' ) );
t( 'Z6 a normal entry still restores', $results['wpcontent/uploads/ok.txt'] === true && is_file( WP_CONTENT_DIR . '/uploads/ok.txt' ) );

// =====================================================================
group( 'SECURITY 2: the log file name is not guessable (nginx ignores .htaccess)' );
// =====================================================================
// The suite stubs ISX_Logger, so the real class runs in its own process.
$LOG_DIR = sys_get_temp_dir() . '/isx_logtest_' . getmypid();
@mkdir( "$LOG_DIR/logs", 0777, true );
file_put_contents( "$LOG_DIR/logs/isx-error.log", "old line\n" );
$snippet = "$LOG_DIR/run.php";
file_put_contents( $snippet, '<?php
define( "ABSPATH", "/" );
define( "ISX_STORAGE_PATH", ' . var_export( $LOG_DIR, true ) . ' );
$GLOBALS["o"] = array();
function get_option( $k, $d = false ) { return isset( $GLOBALS["o"][ $k ] ) ? $GLOBALS["o"][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS["o"][ $k ] = $v; return true; }
function wp_generate_password( $n ) { return substr( bin2hex( random_bytes( $n ) ), 0, $n ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function isx_htaccess_deny_all() { return "deny\n"; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function __( $t ) { return $t; }
require ' . var_export( $PLUGIN . '/includes/class-isx-logger.php', true ) . ';
ISX_Logger::log_error( "export", "uploaded site-30092026-AbC.wpress" );
' );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $snippet ), $out, $code );
$logs = glob( "$LOG_DIR/logs/isx-error*.log" );
t( 'L1 log written under a random 24-char name', $code === 0 && count( $logs ) === 1 && preg_match( '#/isx-error-[A-Za-z0-9]{24}\.log$#', $logs[0] ), json_encode( $logs ) );
t( 'L2 the old fixed-name log is moved, not left readable', ! file_exists( "$LOG_DIR/logs/isx-error.log" ) && strpos( (string) @file_get_contents( $logs[0] ), 'old line' ) === 0 );
array_map( 'unlink', glob( "$LOG_DIR/logs/{,.}*", GLOB_BRACE ) ?: array() );

// =====================================================================
group( 'SECURITY 3: capability gate (multisite super admin, DISALLOW_FILE_MODS)' );
// =====================================================================
// Runs ISX_Admin::guard() in its own process: every scenario gets fresh
// is_multisite()/capability/constant stubs. Prints "allowed" or the reject reason.
function isx_guard_case( $plugin, $cap, array $user_caps, $multisite, $file_mods_off ) {
	$code = '<?php
define( "ABSPATH", "/" );' . ( $file_mods_off ? ' define( "DISALLOW_FILE_MODS", true );' : '' ) . '
class ISX_Logger { public static function __callStatic( $n, $a ) {} }
class ISX_Reject extends Exception {}
function is_multisite() { return ' . var_export( $multisite, true ) . '; }
function current_user_can( $c ) { return in_array( $c, ' . var_export( $user_caps, true ) . ', true ); }
function check_ajax_referer() { return true; }
function sanitize_text_field( $s ) { return $s; }
function wp_unslash( $s ) { return $s; }
function __( $t ) { return $t; }
function wp_send_json_error( $d ) { throw new ISX_Reject( is_array( $d ) ? $d["message"] : (string) $d ); }
function wp_die( $m = "" ) { throw new ISX_Reject( (string) $m ); }
function status_header() {}
function nocache_headers() {}
function add_action() {} function add_filter() {}
require ' . var_export( $plugin . '/includes/class-isx-admin.php', true ) . ';
$m = new ReflectionMethod( "ISX_Admin", "guard" );
if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
try { $m->invoke( null, ' . var_export( $cap, true ) . ' ); echo "allowed"; } catch ( ISX_Reject $e ) { echo "rejected: " . $e->getMessage(); }
';
	$f = tempnam( sys_get_temp_dir(), 'isxg' );
	file_put_contents( $f, $code );
	$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $f ) . ' 2>&1' );
	unlink( $f );
	return trim( (string) $out );
}
$admin_caps = array( 'export', 'import', 'manage_options' );
t( 'G1 single site: admin may import', isx_guard_case( $PLUGIN, 'import', $admin_caps, false, false ) === 'allowed' );
$r = isx_guard_case( $PLUGIN, 'import', $admin_caps, true, false );
t( 'G2 multisite: sub-site admin may NOT import', strpos( $r, 'rejected' ) === 0, $r );
$r = isx_guard_case( $PLUGIN, 'manage_options', $admin_caps, true, false );
t( 'G3 multisite: sub-site admin may NOT use Reset Hub', strpos( $r, 'rejected' ) === 0, $r );
t( 'G4 multisite: super admin may', isx_guard_case( $PLUGIN, 'import', array_merge( $admin_caps, array( 'manage_network_options' ) ), true, false ) === 'allowed' );
$r = isx_guard_case( $PLUGIN, 'import', $admin_caps, false, true );
t( 'G5 DISALLOW_FILE_MODS blocks import', strpos( $r, 'rejected' ) === 0 && strpos( $r, 'DISALLOW_FILE_MODS' ) !== false, $r );
t( 'G6 DISALLOW_FILE_MODS still allows export', isx_guard_case( $PLUGIN, 'export', $admin_caps, false, true ) === 'allowed' );
t( 'G7 no capability → rejected', strpos( isx_guard_case( $PLUGIN, 'export', array(), false, false ), 'rejected' ) === 0 );

// =====================================================================
group( 'B5: an import keeps finding its own job after wp_options is replaced' );
// =====================================================================
class ISX_Test_Options_DB {
	public $options = 'wp_options';
	public $rows    = array();
	public function prepare( $q ) { $a = func_get_args(); array_shift( $a ); return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); }
	public function get_var( $q ) { preg_match( "/= '([^']+)'/", $q, $m ); return isset( $this->rows[ $m[1] ] ) ? 1 : 0; }
	public function update( $t, $d, $w ) { $this->rows[ $w['option_name'] ] = $d['option_value']; }
	public function insert( $t, $d ) { $this->rows[ $d['option_name'] ] = $d['option_value']; }
	public function delete( $t, $w ) { unset( $this->rows[ $w['option_name'] ] ); }
}
$GLOBALS['wpdb'] = new ISX_Test_Options_DB();
// State right after the package's wp_options landed: the SOURCE's values.
$GLOBALS['wpdb']->rows = array( 'isx_storage_path' => '/source/site/storage', 'isx_log_key' => 'SOURCEKEY', 'blogname' => 'Source' );
// The target's own pre-import snapshot: custom storage path, no log key yet.
file_put_contents( ( new ISX_Job() )->preserved_options(), json_encode( array( 'isx_storage_path' => base64_encode( '/home/u/isx-storage' ), 'isx_foo' => base64_encode( 'x' ) ) ) );
$m = new ReflectionMethod( 'ISX_Import', 'reassert_locator_options' );
if ( PHP_VERSION_ID < 80100 ) {
	$m->setAccessible( true );
}
$m->invoke( null, new ISX_Job() );
$rows = $GLOBALS['wpdb']->rows;
t( 'B5a target storage path put back immediately', $rows['isx_storage_path'] === '/home/u/isx-storage' );
t( 'B5b a locator option the target never had is removed', ! isset( $rows['isx_log_key'] ) );
t( 'B5c everything else in wp_options is left to the import', $rows['blogname'] === 'Source' && ! isset( $rows['isx_foo'] ) );
@unlink( ( new ISX_Job() )->preserved_options() );
unset( $GLOBALS['wpdb'] );

// =====================================================================
group( 'B6: tables of another install sharing the DB are never ours' );
// =====================================================================
function isx_foreign_case( $plugin, array $tables, $multisite ) {
	$code = '<?php
define( "ABSPATH", "/" );
function is_multisite() { return ' . var_export( $multisite, true ) . '; }
function __( $t ) { return $t; }
class WPDB_T { public $prefix = "wp_"; public function get_col() { return ' . var_export( $tables, true ) . '; } }
$GLOBALS["wpdb"] = new WPDB_T();
require ' . var_export( $plugin . '/includes/class-isx-database.php', true ) . ';
echo json_encode( ISX_Database::tables() );
';
	$f = tempnam( sys_get_temp_dir(), 'isxd' );
	file_put_contents( $f, $code );
	$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $f ) . ' 2>&1' );
	unlink( $f );
	return json_decode( (string) $out, true );
}
$db = array( 'wp_options', 'wp_posts', 'wp_users', 'wp_woocommerce_sessions', 'wp_staging_options', 'wp_staging_posts', 'wp_staging_users', 'wp_2_options', 'wp_2_posts', 'other_options' );
$got = isx_foreign_case( $PLUGIN, $db, false );
t( 'T1 single site: other installs (wp_staging_, wp_2_) excluded, own plugin tables kept', $got === array( 'wp_options', 'wp_posts', 'wp_users', 'wp_woocommerce_sessions' ), json_encode( $got ) );
$got = isx_foreign_case( $PLUGIN, $db, true );
t( 'T2 multisite: wp_2_ sub-site tables stay part of the network', is_array( $got ) && in_array( 'wp_2_options', $got, true ) && ! in_array( 'wp_staging_options', $got, true ) );
$got = isx_foreign_case( $PLUGIN, array( 'wp_options', 'wp_posts', 'wp_optionsbackup' ), false );
t( 'T3 a lone "<x>options" without its posts table is not an install', is_array( $got ) && in_array( 'wp_optionsbackup', $got, true ) );

// =====================================================================
group( 'B7: a job waiting for a password is not re-driven forever' );
// =====================================================================
function isx_password_case( $plugin, $mode ) {
	$code = '<?php
define( "ABSPATH", "/" );
function __( $t ) { return $t; }
function size_format( $b ) { return $b . " B"; }
function wp_raise_memory_limit() {}
function apply_filters( $t, $v ) { return $v; }
function wp_convert_hr_to_bytes( $v ) { return 268435456; }
function add_action() {} function add_filter() {}
function wp_next_scheduled() { return false; }
function wp_schedule_single_event() { echo "SCHEDULED;"; }
function wp_remote_post() { echo "LOOPBACK;"; }
class ISX_Logger { public static function __callStatic( $n, $a ) {} }
class ISX_Export { public static function run( $j ) { return array( "done" => true ); } }
class ISX_Import { public static $calls = 0; public static function run( $j ) { self::$calls++; return array( "progress" => 0, "done" => false, "needs_password" => true, "message" => "pw" ); } }
class ISX_Job {
	private $s = array( "type" => "import", "step" => "init" );
	public function with_lock( $cb ) { return $cb( $this ); }
	public function get( $k, $d = null ) { return isset( $this->s[ $k ] ) ? $this->s[ $k ] : $d; }
	public function set( $k, $v ) { $this->s[ $k ] = $v; return $this; }
	public function save() { return true; }
	public function id() { return "isx_test"; }
	public function dir() { return sys_get_temp_dir(); }
	public function is_cancel_requested() { return false; }
}
require ' . var_export( $plugin . '/includes/class-isx-admin.php', true ) . ';
if ( ' . var_export( $mode, true ) . ' === "sync" ) {
	ISX_Admin::run_job_to_completion( new ISX_Job() );
	echo "RETURNED after " . ISX_Import::$calls . " step(s)";
} else {
	$m = new ReflectionMethod( "ISX_Admin", "run_step" );
	if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
	$m->invoke( null, new ISX_Job() );
	echo "STEP DONE";
}
';
	$f = tempnam( sys_get_temp_dir(), 'isxp' );
	file_put_contents( $f, $code );
	// perl alarm: an infinite loop must fail the test, not hang the suite.
	$out = shell_exec( 'perl -e ' . escapeshellarg( 'alarm 10; exec @ARGV' ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $f ) . ' 2>&1' );
	unlink( $f );
	return trim( (string) $out );
}
$r = isx_password_case( $PLUGIN, 'sync' );
t( 'P1 WP-CLI/synchronous driver stops at the password prompt (no infinite loop)', $r === 'RETURNED after 1 step(s)', $r );
$r = isx_password_case( $PLUGIN, 'async' );
t( 'P2 no cron event or loopback is chained while waiting for the password', $r === 'STEP DONE', $r );

// =====================================================================
group( 'B9: the storage folder can never swallow a WordPress directory' );
// =====================================================================
require_once $PLUGIN . '/includes/functions-isx-storage.php';
$wc = untrailingslashit( WP_CONTENT_DIR );
foreach ( array( ABSPATH, $wc, "$wc/", "$wc/plugins", "$wc/uploads", "$wc/themes", dirname( ABSPATH ), '/', ISX_PATH ) as $bad ) {
	t( 'S9 refused: ' . str_replace( sys_get_temp_dir(), '<tmp>', $bad ), isx_storage_path_conflict( $bad ) !== '' );
}
foreach ( array( "$wc/insightx-backup", "$wc/uploads/isx-backups", '/srv/isx-backups', ISX_PATH . '/storage' ) as $ok ) {
	t( 'S9 allowed: ' . str_replace( sys_get_temp_dir(), '<tmp>', $ok ), isx_storage_path_conflict( $ok ) === '' );
}

// =====================================================================
group( 'B10: import rewrites URLs inside stdClass, never wakes other classes' );
// =====================================================================
if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data ) { return is_string( $data ) && ( $data === 'b:0;' || @unserialize( $data, array( 'allowed_classes' => false ) ) !== false ); }
}
require_once $PLUGIN . '/includes/class-isx-serialize.php';
class ISX_Test_Wakeup { public $u; public function __wakeup() { $GLOBALS['isx_woke'] = true; } }
$GLOBALS['isx_woke'] = false;
$o = new stdClass();
$o->url = 'https://old.example.com/a.jpg';
$out = ISX_Serialize::replace( serialize( array( 'data' => $o ) ), 'https://old.example.com', 'https://new.example.com' );
$un  = @unserialize( $out, array( 'allowed_classes' => array( 'stdClass' ) ) );
t( 'O1 URL inside a serialized stdClass is rewritten', is_array( $un ) && $un['data']->url === 'https://new.example.com/a.jpg', $out );
$gadget = 'O:15:"ISX_Test_Wakeup":1:{s:1:"u";s:23:"https://old.example.com";}';
$out    = ISX_Serialize::replace( $gadget, 'https://old.example.com', 'https://new.example.com' );
t( 'O2 other classes are never instantiated and come back byte-identical', $GLOBALS['isx_woke'] === false && $out === $gadget, $out );

// =====================================================================
group( 'URL replacement stops at a host/path boundary' );
// =====================================================================
$map = array( 'https://old.com' => 'https://new.com', '/var/www/old' => '/srv/new' );
$in  = 'a https://old.com/x b https://old.com c https://old.com.au d https://old.community e https://old.com:8080/p f https://old.com-shop g "https://old.com" h /var/www/old/wp i /var/www/old2';
$out = ISX_Serialize::replace_bounded( $in, $map );
t( 'U1 own URLs replaced (path, end, port, quotes)', strpos( $out, 'a https://new.com/x b https://new.com c' ) === 0 && strpos( $out, 'https://new.com:8080/p' ) !== false && strpos( $out, '"https://new.com"' ) !== false && strpos( $out, '/srv/new/wp' ) !== false, $out );
t( 'U2 other domains/paths that merely start the same are left alone', strpos( $out, 'https://old.com.au' ) !== false && strpos( $out, 'https://old.community' ) !== false && strpos( $out, 'https://old.com-shop' ) !== false && strpos( $out, '/var/www/old2' ) !== false, $out );
t( 'U3 longest key still wins (strtr semantics)', ISX_Serialize::replace_bounded( 'https://old.com/blog/x', array( 'https://old.com' => 'A', 'https://old.com/blog' => 'B' ) ) === 'B/x' );
t( 'U4 sentence punctuation after a URL is a boundary', ISX_Serialize::replace_bounded( 'see https://old.com. Next', $map ) === 'see https://new.com. Next' );

// =====================================================================
group( 'Chunked upload: a retried chunk is not written twice' );
// =====================================================================
$chunk_arc  = sys_get_temp_dir() . '/isx_chunk_' . getmypid() . '.wpress';
$chunk_port = 19000 + ( getmypid() % 1000 );
@unlink( $chunk_arc );
$chunk_srv = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:$chunk_port", __DIR__ . '/chunk-router.php' ),
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
	$chunk_pipes,
	null,
	array( 'ISX_CHUNK_ARCHIVE' => $chunk_arc )
);
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $chunk_port ); $i++ ) {
	usleep( 100000 );
}
$send_chunk = function ( $data, $offset ) use ( $chunk_port ) {
	$tmp = tempnam( sys_get_temp_dir(), 'isxc' );
	file_put_contents( $tmp, $data );
	$ch = curl_init( "http://127.0.0.1:$chunk_port/" );
	curl_setopt_array( $ch, array(
		CURLOPT_POST           => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POSTFIELDS     => array( 'job' => 'x', 'offset' => $offset, 'chunk' => new CURLFile( $tmp, 'application/octet-stream', 'part' ) ),
	) );
	$res = json_decode( (string) curl_exec( $ch ), true );
	curl_close( $ch );
	unlink( $tmp );
	return is_array( $res ) && ! empty( $res['success'] );
};
$ok = $send_chunk( 'AAAAA', 0 ) && $send_chunk( 'BBBBB', 5 ) && $send_chunk( 'BBBBB', 5 ) && $send_chunk( 'CC', 10 );
clearstatcache();
t( 'K1 retried chunk overwrites itself: archive is exactly A+B+C', $ok && file_get_contents( $chunk_arc ) === 'AAAAABBBBBCC', (string) @file_get_contents( $chunk_arc ) );
t( 'K2 a gap (missing earlier chunk) is refused', $send_chunk( 'ZZ', 40 ) === false && file_get_contents( $chunk_arc ) === 'AAAAABBBBBCC' );
proc_terminate( $chunk_srv );
@unlink( $chunk_arc );

// =====================================================================
group( 'Crafted entry sizes cannot loop the readers' );
// =====================================================================
t( 'H1 valid header accepted', ISX_Archive::valid_header( array( 'p' => 'wpcontent/a.txt', 's' => 0 ) ) && ISX_Archive::valid_header( array( 'p' => 'x', 's' => 12 ) ) );
t( 'H2 negative / fractional / non-numeric / missing size rejected', ! ISX_Archive::valid_header( array( 'p' => 'x', 's' => -40 ) )
	&& ! ISX_Archive::valid_header( array( 'p' => 'x', 's' => 1.5 ) )
	&& ! ISX_Archive::valid_header( array( 'p' => 'x', 's' => 'abc' ) )
	&& ! ISX_Archive::valid_header( array( 'p' => 'x' ) )
	&& ! ISX_Archive::valid_header( array( 's' => 3 ) ) );
$loop = $JOB_DIR . '/loop.wpress';
@unlink( $loop );
ISX_Archive::init( $loop );
ISX_Archive::add_data( $loop, 'wpcontent/a.txt', 'x' );
ISX_Archive::finish( $loop );
$raw = file_get_contents( $loop );
$raw = str_replace( '"s":1,', '"s":-30,', $raw );
$pos = strpos( $raw, '{"p"' );
$len = unpack( 'V', substr( $raw, $pos - 4, 4 ) )[1];
$json = substr( $raw, $pos, strpos( $raw, '}', $pos ) - $pos + 1 );
$raw = substr( $raw, 0, $pos - 4 ) . pack( 'V', strlen( $json ) ) . $json . substr( $raw, $pos + $len );
file_put_contents( $loop, $raw );
$seen    = 0;
$started = microtime( true );
ISX_Archive::each( $loop, function () use ( &$seen ) { return ++$seen < 1000; } );
t( 'H3 each() stops on a negative size instead of looping', $seen === 0 && microtime( true ) - $started < 2, "callbacks=$seen" );

// =====================================================================
group( 'Deleting a backup also deletes its decompressed .peek copy' );
// =====================================================================
if ( ! function_exists( 'isx_htaccess_deny_all' ) ) {
	function isx_htaccess_deny_all() { return "deny\n"; }
}
require_once $PLUGIN . '/includes/class-isx-backups.php';
$bdir = ISX_Backups::dir();
file_put_contents( "$bdir/site-01012026-Abc.wpress", 'gz' );
file_put_contents( "$bdir/site-01012026-Abc.wpress.peek", 'plain copy' );
t( 'K3 backup and its .peek both removed', ISX_Backups::delete( 'site-01012026-Abc.wpress' ) && ! file_exists( "$bdir/site-01012026-Abc.wpress" ) && ! file_exists( "$bdir/site-01012026-Abc.wpress.peek" ) );
t( 'K4 backups dir always gets an index.php', is_file( "$bdir/index.php" ) );

// =====================================================================
group( 'I18N: every t() string in assets/js is localized by ISX_Admin::js_i18n()' );

$admin_src = file_get_contents( __DIR__ . '/../includes/class-isx-admin.php' );
$fn_start  = strpos( $admin_src, 'function js_i18n()' );
$fn_body   = substr( $admin_src, $fn_start, strpos( $admin_src, "\n\t}\n", $fn_start ) - $fn_start );
$php_keys  = array();
$tokens    = token_get_all( '<?php ' . $fn_body );
foreach ( $tokens as $i => $tok ) {
	if ( is_array( $tok ) && $tok[0] === T_CONSTANT_ENCAPSED_STRING ) {
		$j = $i + 1;
		while ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
			$j++;
		}
		if ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_DOUBLE_ARROW ) {
			$php_keys[ eval( 'return ' . $tok[1] . ';' ) ] = true;
		}
	}
}
$missing = array();
foreach ( glob( __DIR__ . '/../assets/js/*.js' ) as $js_file ) {
	preg_match_all( "/\\bt\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents( $js_file ), $m );
	foreach ( $m[1] as $raw ) {
		$text = json_decode( '"' . str_replace( array( "\\'", '"' ), array( "'", '\\"' ), $raw ) . '"' );
		if ( ! isset( $php_keys[ $text ] ) ) {
			$missing[] = basename( $js_file ) . ': ' . $raw;
		}
	}
}
t( 'I1 js_i18n() found keys', count( $php_keys ) > 50, (string) count( $php_keys ) );
t( 'I2 no JS string missing from js_i18n()', empty( $missing ), implode( ' | ', $missing ) );

// =====================================================================
echo "\n========================================\n";
echo "TOTAL: $PASS passed, $FAIL failed\n";
exit( $FAIL === 0 ? 0 : 1 );
