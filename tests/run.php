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
echo "\n========================================\n";
echo "TOTAL: $PASS passed, $FAIL failed\n";
exit( $FAIL === 0 ? 0 : 1 );
