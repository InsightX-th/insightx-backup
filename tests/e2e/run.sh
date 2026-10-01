#!/usr/bin/env bash
#
# End-to-end export → import against a real MySQL.
#
# Builds two throw-away WordPress installs in their own temporary databases
# (never the site's own DB), seeds the source with data that tends to break
# migrations, exports it with `wp isx export`, imports it into the
# destination (different URL, scheme and table prefix) with `wp isx import`,
# then checks the result with verify.php. Everything is removed at the end
# (KEEP=1 keeps it for inspection).
#
# Defaults target a Local (localwp.com) site; override with env vars:
#   MYSQL_BIN, MYSQL_SOCKET, DB_USER, DB_PASS, WP_CORE, WP_CLI, PHP_BIN
# or, for a TCP server (CI), DB_HOST=127.0.0.1:3306 instead of MYSQL_SOCKET.
#
# Run: bash tests/e2e/run.sh
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$HERE/../.." && pwd)"
LOCAL_RES="/Applications/Local.app/Contents/Resources/extraResources"

PHP_BIN="${PHP_BIN:-php}"
WP_CLI="${WP_CLI:-$LOCAL_RES/bin/wp-cli/wp-cli.phar}"
MYSQL_BIN="${MYSQL_BIN:-$(ls "$LOCAL_RES"/lightning-services/mysql-*/bin/darwin-*/bin/mysql 2>/dev/null | head -1 || true)}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
DB_HOST="${DB_HOST:-}"
if [ -z "$DB_HOST" ]; then
	MYSQL_SOCKET="${MYSQL_SOCKET:-$(ls "$HOME"/Library/Application\ Support/Local/run/*/mysql/mysqld.sock 2>/dev/null | head -1 || true)}"
fi
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
WP_CORE="${WP_CORE:-$(cd "$PLUGIN/../../.." && pwd)}"

NEEDS=( "$WP_CLI" "$WP_CORE/wp-includes/version.php" )
[ -n "$DB_HOST" ] || NEEDS+=( "${MYSQL_SOCKET:-}" )
for need in "${NEEDS[@]}"; do
	[ -n "$need" ] && [ -e "$need" ] || { echo "Missing: $need (is Local running? see the env vars at the top)"; exit 2; }
done

if [ -n "$DB_HOST" ]; then
	CONN=( --host="${DB_HOST%%:*}" --port="${DB_HOST##*:}" --protocol=tcp )
	WP_DBHOST="$DB_HOST"
else
	CONN=( --socket="$MYSQL_SOCKET" )
	WP_DBHOST="localhost:$MYSQL_SOCKET"
fi
# MYSQL_PWD rather than -p: no "password on the command line" warning, so
# stderr can stay visible and a failing statement says why.
SQL() { MYSQL_PWD="$DB_PASS" "$MYSQL_BIN" "${CONN[@]}" -u"$DB_USER" "$@"; }
WORK="$(mktemp -d /tmp/isx-e2e.XXXXXX)"
SRC="$WORK/src"
DST="$WORK/dst"
SRC_DB=isx_e2e_src
DST_DB=isx_e2e_dst

cleanup() {
	if [ "${KEEP:-0}" = "1" ]; then
		echo "KEEP=1 — left $WORK and databases $SRC_DB / $DST_DB"
		return
	fi
	SQL -e "DROP DATABASE IF EXISTS $SRC_DB; DROP DATABASE IF EXISTS $DST_DB;" || true
	rm -rf "$WORK"
}
trap cleanup EXIT

wp() { local path="$1"; shift; "$PHP_BIN" -d memory_limit=1G "$WP_CLI" --path="$path" --skip-themes "$@"; }

make_site() { # dir db prefix url
	local dir="$1" db="$2" prefix="$3" url="$4"
	SQL -e "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
	case "$dir" in "$WORK"/*) rm -rf "$dir" ;; *) echo "refusing to clear $dir"; exit 2 ;; esac
	mkdir -p "$dir"
	# WordPress core from the local site, with an empty wp-content of its own.
	rsync -a --exclude wp-content --exclude wp-config.php "$WP_CORE"/ "$dir"/
	mkdir -p "$dir/wp-content/plugins" "$dir/wp-content/themes/isx-e2e" "$dir/wp-content/uploads"
	printf '/*\nTheme Name: ISX E2E\n*/\nbody{background:url(%s/wp-content/uploads/bg.png)}\n' "$url" > "$dir/wp-content/themes/isx-e2e/style.css"
	echo '<?php // e2e theme' > "$dir/wp-content/themes/isx-e2e/index.php"
	rsync -a --exclude .git --exclude tests "$PLUGIN"/ "$dir/wp-content/plugins/insightx-backup/"
	wp "$dir" config create --dbname="$db" --dbuser="$DB_USER" --dbpass="$DB_PASS" \
		--dbhost="$WP_DBHOST" --dbprefix="$prefix" --skip-check --quiet
	wp "$dir" core install --url="$url" --title="E2E $prefix" --admin_user=admin \
		--admin_password="$5" --admin_email=admin@example.com --skip-email --quiet
	wp "$dir" theme activate isx-e2e --quiet
	wp "$dir" plugin activate insightx-backup --quiet
}

echo "== building source (http://source.test, wp_) and destination (https://dest.test, dst_)"
make_site "$SRC" "$SRC_DB" wp_ http://source.test 'Src-Pass-1!'
make_site "$DST" "$DST_DB" dst_ https://dest.test 'Dst-Pass-2!'

# Bystanders in the destination DB that an import or reset must never touch:
# another install whose prefix starts with ours, and a non-WordPress table.
seed_bystanders() {
	SQL "$DST_DB" -e "
		CREATE TABLE dst_staging_options (option_id int, option_name varchar(64), option_value text);
		CREATE TABLE dst_staging_posts (ID int);
		CREATE TABLE dst_staging_users (ID int, user_login varchar(60));
		INSERT INTO dst_staging_users VALUES (1, 'staging-admin');
		CREATE TABLE orders (id int);
		INSERT INTO orders VALUES (1), (2), (3);"
}

echo "== seeding"
wp "$SRC" eval-file "$HERE/seed.php" "$WORK"
seed_bystanders

echo "== export"
wp "$SRC" isx export
PKG="$(ls -t "$SRC"/wp-content/insightx-backup/backups/*.wpress | head -1 || true)"
echo "   package: $(basename "$PKG") ($(wc -c < "$PKG" | tr -d ' ') bytes)"

echo "== import (plain)"
wp "$DST" isx import "$PKG" --yes
wp "$DST" eval-file "$HERE/verify.php" "$WORK" plain

echo "== reset hub: database (plugin stays active, password can be revealed)"
wp "$DST" eval-file "$HERE/reset-check.php"

echo "== import (password-encrypted package via wp isx import --password)"
make_site "$DST" "$DST_DB" dst_ https://dest.test 'Dst-Pass-2!' >/dev/null
seed_bystanders
wp "$SRC" eval "if ( ISX_Crypto::encrypt_file( 'e2e pass', '$PKG', '$WORK/sealed.wpress' ) !== true ) { WP_CLI::error( 'encrypt failed' ); }"
# Non-interactive (cron/CI) without --password must fail fast, not prompt.
if out="$(wp "$DST" isx import "$WORK/sealed.wpress" --yes < /dev/null 2>&1)"; then
	echo "  FAIL [encrypted] imported an encrypted package without a password"; exit 1
fi
case "$out" in *--password*) echo "  PASS [encrypted] no TTY: clear error asking for --password" ;; *) echo "  FAIL [encrypted] unexpected error: $out"; exit 1 ;; esac
if wp "$DST" isx import "$WORK/sealed.wpress" --password='not it' --yes >/dev/null 2>&1; then
	echo "  FAIL [encrypted] a wrong password was accepted"; exit 1
fi
echo "  PASS [encrypted] wrong password refused"
wp "$DST" eval "if ( get_option( 'isx_e2e_marker' ) ) { WP_CLI::error( 'site changed by a refused import' ); }"
echo "  PASS [encrypted] refused import left the site untouched"
wp "$DST" isx import "$WORK/sealed.wpress" --password='e2e pass' --yes
wp "$DST" eval-file "$HERE/verify.php" "$WORK" encrypted

echo "== E2E OK"
