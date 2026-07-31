<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Database export & import via $wpdb.
 *
 * The dump is a plain, line-per-statement .sql file — genuine, directly
 * runnable table-definition and row-insert statements (see build_insert()
 * and dump_schema()), one per physical line (a table definition's normally
 * multi-line output is collapsed to one line; SQL doesn't care about
 * whitespace between tokens outside quoted values) so the resumable
 * AJAX-batch reader can keep working one fgets() at a time. Every value is
 * written as either the bare keyword NULL or a single-quoted,
 * backslash-escaped string (see build_insert()) — deliberately never a bare
 * numeric literal — which keeps import parsing (parse_value_list()) to two
 * cases instead of needing a real SQL literal grammar.
 *
 * On import, a row is parsed back into a plain column => value array,
 * passed through ISX_Serialize::replace() (serialized-safe search & replace
 * — walks PHP-serialized column values and recomputes their length prefixes,
 * so it's safe to run on the *parsed* values here) and written back through
 * a prepared row-insert helper. Packages exported before this format existed
 * used a custom tab-delimited T\t/R\t line format instead; import_line()
 * still reads those too (see import_legacy_line()) so old backups keep working.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Database {

	/**
	 * Soft cap on the byte length of a single multi-row INSERT statement in the
	 * dump. Keeps each statement comfortably under MySQL's max_allowed_packet
	 * (default 1M on old servers; multi-MB on modern ones) on both the export
	 * write and the import replay. A lone row wider than this still gets its
	 * own single-row statement — never split.
	 *
	 * @var int
	 */
	const MAX_STATEMENT_BYTES = 800000;

	/**
	 * Columns that store a literal key/name prefixed with the table prefix
	 * (e.g. wp_usermeta.meta_key "wp_capabilities", wp_options.option_name
	 * "wp_user_roles"). Rewritten by leading-prefix match only — never a
	 * blanket string replace — so unrelated content that happens to contain
	 * the prefix text isn't touched.
	 *
	 * @var array
	 */
	private static $prefixed_columns = array( 'meta_key', 'option_name' );

	/**
	 * Backslash-escape map used by build_insert()/unescape_value() — the
	 * same set $wpdb->_real_escape() (mysqli_real_escape_string) produces,
	 * so unescape_value() is its exact inverse.
	 *
	 * @var array
	 */
	private static $escape_map = array(
		'0'  => "\0",
		'n'  => "\n",
		'r'  => "\r",
		'Z'  => "\x1a",
		'\\' => '\\',
		"'"  => "'",
		'"'  => '"',
	);

	/**
	 * Tables that belong to this site (base prefix).
	 *
	 * @return array
	 */
	public static function tables() {
		global $wpdb;
		$all = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$prefix = $wpdb->prefix;
		$tables = array();
		foreach ( $all as $table ) {
			if ( strpos( $table, $prefix ) === 0 ) {
				$tables[] = $table;
			}
		}
		return $tables;
	}

	/**
	 * Approximate row count for progress reporting.
	 *
	 * @param string $table
	 * @return int
	 */
	public static function row_count( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . self::ident( $table ) . '`' );
	}

	/**
	 * The column to use for keyset (seek) pagination when dumping a table, or
	 * null if the table has no suitable key. Only a single-column integer
	 * PRIMARY KEY qualifies (e.g. wp_postmeta.meta_id, wp_posts.ID) — that lets
	 * dump_rows() page with "WHERE pk > last ORDER BY pk LIMIT n" instead of
	 * "LIMIT offset, n", which is what saves large tables from the O(n²)
	 * deep-offset scan MySQL does for a big OFFSET. Composite PKs, non-integer
	 * PKs and keyless tables fall back to offset pagination.
	 *
	 * @param string $table
	 * @return string|null
	 */
	public static function keyset_column( $table ) {
		global $wpdb;
		$keys = $wpdb->get_results( 'SHOW KEYS FROM `' . self::ident( $table ) . "` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( ! is_array( $keys ) || count( $keys ) !== 1 ) {
			// No PK, or a composite PK (more than one row for PRIMARY).
			return null;
		}
		$column = isset( $keys[0]['Column_name'] ) ? $keys[0]['Column_name'] : '';
		if ( $column === '' ) {
			return null;
		}
		// Confirm the PK column is an integer type — keyset comparison ("> %d")
		// is only well-defined (and index-ordered the way we page) for integers.
		$col = $wpdb->get_row( 'SHOW COLUMNS FROM `' . self::ident( $table ) . '` LIKE ' . $wpdb->prepare( '%s', $column ), ARRAY_A );
		$type = isset( $col['Type'] ) ? strtolower( $col['Type'] ) : '';
		if ( strpos( $type, 'int' ) === false ) {
			return null;
		}
		return $column;
	}

	/**
	 * Append a table's definition statement to the dump handle.
	 *
	 * @param resource $fh
	 * @param string   $table
	 * @return bool False if the write failed (e.g. disk full).
	 */
	public static function dump_schema( $fh, $table ) {
		global $wpdb;
		$row    = $wpdb->get_row( 'SHOW CREATE TABLE `' . self::ident( $table ) . '`', ARRAY_N );
		$create = isset( $row[1] ) ? trim( $row[1] ) : '';
		// MySQL pretty-prints this across many lines — collapse to one
		// physical line (still valid SQL; whitespace between tokens is
		// insignificant outside quoted literals) to keep one dump line per
		// statement for the resumable batch reader.
		$create = preg_replace( '/\s*\r?\n\s*/', ' ', $create );
		$line   = $create . ";\n";
		return @fwrite( $fh, $line ) === strlen( $line );
	}

	/**
	 * Append a batch of rows to the dump as one or more multi-row INSERT
	 * statements (grouped up to MAX_STATEMENT_BYTES per statement, one physical
	 * line each). Pages by keyset when $keyset_column is given (WHERE pk > last
	 * ORDER BY pk LIMIT n) — avoiding the O(n²) deep-OFFSET scan on big tables —
	 * otherwise by LIMIT offset, n.
	 *
	 * @param resource     $fh
	 * @param string       $table
	 * @param int          $offset        Row offset (offset mode only).
	 * @param int          $limit
	 * @param string       $extra_where   Raw SQL condition (already trusted/static), no leading AND/WHERE.
	 * @param string|array $search        Find & replace pairs applied to every row before writing (export-time).
	 * @param string|array $replace
	 * @param string|null  $keyset_column PK column to seek-paginate on, or null for offset paging.
	 * @param int|null     $last_pk       Highest PK written so far (keyset mode); null for the first batch.
	 * @return array { written:int, last_pk:int|null, ok:bool }
	 */
	public static function dump_rows( $fh, $table, $offset, $limit, $extra_where = '', $search = array(), $replace = array(), $keyset_column = null, $last_pk = null ) {
		global $wpdb;

		$sql   = 'SELECT * FROM `' . self::ident( $table ) . '`';
		$conds = array();
		if ( $extra_where !== '' ) {
			$conds[] = $extra_where;
		}
		if ( $keyset_column !== null && $last_pk !== null ) {
			$conds[] = '`' . self::ident( $keyset_column ) . '` > ' . $wpdb->prepare( '%d', $last_pk );
		}
		if ( ! empty( $conds ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $conds );
		}
		if ( $keyset_column !== null ) {
			$sql .= ' ORDER BY `' . self::ident( $keyset_column ) . '` ASC' . $wpdb->prepare( ' LIMIT %d', $limit );
		} else {
			$sql .= $wpdb->prepare( ' LIMIT %d, %d', $offset, $limit );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array( 'written' => 0, 'last_pk' => $last_pk, 'ok' => true );
		}

		$has_replace = ! empty( $search );
		$new_last_pk = $last_pk;
		$columns_sql = null;
		$group       = array();
		$group_bytes = 0;
		$ok          = true;

		foreach ( $rows as $row ) {
			if ( $keyset_column !== null && isset( $row[ $keyset_column ] ) ) {
				$new_last_pk = (int) $row[ $keyset_column ];
			}
			if ( $has_replace ) {
				$row = ISX_Serialize::replace( $row, $search, $replace );
			}
			if ( empty( $row ) ) {
				continue;
			}
			if ( $columns_sql === null ) {
				$columns_sql = self::columns_sql( $row );
			}
			$tuple = self::value_tuple( $row );
			$tlen  = strlen( $tuple );

			// Flush the current group before it would exceed the statement cap,
			// so each INSERT line stays under max_allowed_packet. A single tuple
			// wider than the cap still lands alone in its own statement.
			if ( ! empty( $group ) && ( $group_bytes + $tlen + 1 ) > self::MAX_STATEMENT_BYTES ) {
				if ( ! self::write_insert( $fh, $table, $columns_sql, $group ) ) {
					$ok = false;
					break;
				}
				$group       = array();
				$group_bytes = 0;
			}
			$group[]      = $tuple;
			$group_bytes += $tlen + 1;
		}
		if ( $ok && ! empty( $group ) ) {
			$ok = self::write_insert( $fh, $table, $columns_sql, $group );
		}

		return array( 'written' => count( $rows ), 'last_pk' => $new_last_pk, 'ok' => $ok );
	}

	/**
	 * Peek the (still source-prefixed — retargeting happens inside
	 * import_line(), not here) table name a dump line belongs to, without
	 * otherwise touching anything. Used by the import batcher to detect table
	 * boundaries so it can keep splitting most tables across requests while
	 * never cutting the options table mid-way (see ISX_Import::database()).
	 *
	 * @param string $line
	 * @return string|null
	 */
	public static function line_table( $line ) {
		$line = rtrim( $line, "\r\n" );
		if ( $line === '' ) {
			return null;
		}
		if ( strpos( $line, "T\t" ) === 0 || strpos( $line, "R\t" ) === 0 ) {
			$parts = explode( "\t", $line, 3 );
			return isset( $parts[1] ) ? $parts[1] : null;
		}
		if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $line, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/^INSERT INTO `([^`]+)`/', $line, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * If this dump line creates a table, return that table's name as it will
	 * exist on the TARGET site (prefix already retargeted); null for any
	 * other line. Lets the import batcher record exactly which tables the
	 * package rebuilt, so finalize() can drop leftover same-prefix tables
	 * that were on the target before the import but aren't in the package.
	 *
	 * @param string $line
	 * @param string $old_prefix
	 * @param string $new_prefix
	 * @return string|null
	 */
	public static function created_table( $line, $old_prefix, $new_prefix ) {
		$line = rtrim( $line, "\r\n" );
		if ( strpos( $line, "T\t" ) === 0 ) {
			$parts = explode( "\t", $line, 3 );
			return isset( $parts[1] ) ? self::retarget_prefix( $parts[1], $old_prefix, $new_prefix ) : null;
		}
		if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $line, $m ) ) {
			return self::retarget_prefix( $m[1], $old_prefix, $new_prefix );
		}
		return null;
	}

	/**
	 * Drop every table that matches this site's prefix but is NOT in $keep —
	 * the clean-then-restore counterpart for the database: tables the old
	 * site had that the imported package doesn't rebuild would otherwise
	 * survive the import with their stale rows. Only ever called from
	 * finalize() (after the whole dump has been applied), never before or
	 * during the import, and callers must skip it entirely when the package
	 * had no database dump.
	 *
	 * @param array $keep Target-prefixed table names to preserve.
	 * @return void
	 */
	public static function drop_extra_tables( array $keep ) {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			if ( ! in_array( $table, $keep, true ) ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . self::ident( $table ) . '`' );
			}
		}
	}

	/**
	 * Handle one dump line during import.
	 *
	 * @param string       $line
	 * @param string|array $search
	 * @param string|array $replace
	 * @param string       $old_prefix
	 * @param string       $new_prefix
	 * @param bool         $skip_emails Best-effort: don't rewrite $search occurrences
	 *                                  inside the domain part of an email address.
	 * @return void
	 */
	public static function import_line( $line, $search, $replace, $old_prefix, $new_prefix, $skip_emails = false ) {
		global $wpdb;
		$line = rtrim( $line, "\r\n" );
		if ( $line === '' ) {
			return;
		}

		// Old packages (before this SQL-text format) used a custom
		// tab-delimited "T\t<table>\t<payload>" / "R\t<table>\t<payload>" line
		// format — keep reading those too.
		if ( strpos( $line, "T\t" ) === 0 || strpos( $line, "R\t" ) === 0 ) {
			self::import_legacy_line( $line, $search, $replace, $old_prefix, $new_prefix, $skip_emails );
			return;
		}

		if ( strpos( $line, 'CREATE TABLE ' ) === 0 ) {
			if ( ! preg_match( '/^CREATE TABLE `([^`]+)`/', $line, $m ) ) {
				return;
			}
			$table  = self::retarget_prefix( $m[1], $old_prefix, $new_prefix );
			$create = rtrim( self::rewrite_create_table( $line, $table ), ';' );
			$wpdb->query( 'DROP TABLE IF EXISTS `' . self::ident( $table ) . '`' );
			$wpdb->query( $create ); // phpcs:ignore WordPress.DB.PreparedSQL
			return;
		}

		if ( strpos( $line, 'INSERT INTO ' ) === 0 ) {
			$parsed = self::parse_insert( $line );
			if ( $parsed === null ) {
				return;
			}
			list( $table, $columns, $rows ) = $parsed;
			$table    = self::retarget_prefix( $table, $old_prefix, $new_prefix );
			$out_rows = array();
			foreach ( $rows as $values ) {
				$row = @array_combine( $columns, $values ); // phpcs:ignore
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( self::$prefixed_columns as $col ) {
					if ( isset( $row[ $col ] ) && is_string( $row[ $col ] ) ) {
						$row[ $col ] = self::retarget_prefix( $row[ $col ], $old_prefix, $new_prefix );
					}
				}
				$out_rows[] = ISX_Serialize::replace( $row, $search, $replace, $skip_emails );
			}
			if ( ! empty( $out_rows ) ) {
				self::insert_rows( $table, $out_rows );
			}
		}
	}

	/**
	 * The pre-format-change "T\t"/"R\t" line reader — unchanged from before,
	 * kept only so backups made by older versions of this plugin still import.
	 *
	 * @param string       $line
	 * @param string|array $search
	 * @param string|array $replace
	 * @param string       $old_prefix
	 * @param string       $new_prefix
	 * @param bool         $skip_emails
	 * @return void
	 */
	private static function import_legacy_line( $line, $search, $replace, $old_prefix, $new_prefix, $skip_emails ) {
		global $wpdb;
		$parts = explode( "\t", $line, 3 );
		if ( count( $parts ) < 3 ) {
			return;
		}
		list( $type, $table, $payload ) = $parts;
		$table = self::retarget_prefix( $table, $old_prefix, $new_prefix );

		if ( $type === 'T' ) {
			$create = base64_decode( $payload ); // phpcs:ignore
			$create = self::rewrite_create_table( $create, $table );
			$wpdb->query( 'DROP TABLE IF EXISTS `' . self::ident( $table ) . '`' );
			$wpdb->query( $create ); // phpcs:ignore WordPress.DB.PreparedSQL
			return;
		}

		if ( $type === 'R' ) {
			$row = @unserialize( base64_decode( $payload ) ); // phpcs:ignore
			if ( ! is_array( $row ) ) {
				return;
			}
			foreach ( self::$prefixed_columns as $col ) {
				if ( isset( $row[ $col ] ) && is_string( $row[ $col ] ) ) {
					$row[ $col ] = self::retarget_prefix( $row[ $col ], $old_prefix, $new_prefix );
				}
			}
			$row = ISX_Serialize::replace( $row, $search, $replace, $skip_emails );
			self::insert_row( $table, $row );
		}
	}

	/**
	 * Backtick-quoted, comma-joined column list for a row — the "(col1,col2)"
	 * part shared by every INSERT statement in a single-table batch.
	 *
	 * @param array $row
	 * @return string
	 */
	private static function columns_sql( array $row ) {
		$columns = array();
		foreach ( $row as $column => $value ) {
			$columns[] = '`' . self::ident( $column ) . '`';
		}
		return implode( ',', $columns );
	}

	/**
	 * One parenthesised value tuple "('v1',NULL,...)" for a row. Every value is
	 * a single-quoted escaped string or the bare keyword NULL — never a bare
	 * numeric literal — so the import-side parser (parse_value_list()) only ever
	 * has to handle those two shapes instead of a full SQL literal grammar.
	 *
	 * @param array $row
	 * @return string
	 */
	private static function value_tuple( array $row ) {
		$values = array();
		foreach ( $row as $value ) {
			$values[] = is_null( $value ) ? 'NULL' : "'" . self::escape_value( $value ) . "'";
		}
		return '(' . implode( ',', $values ) . ')';
	}

	/**
	 * Write one (possibly multi-row) INSERT line to the dump handle:
	 * "INSERT INTO `t` (cols) VALUES (r1),(r2),...;". Still exactly one physical
	 * line per statement, so the resumable fgets() batch reader is unaffected.
	 *
	 * @param resource $fh
	 * @param string   $table
	 * @param string   $columns_sql
	 * @param array    $tuples      Value tuples from value_tuple().
	 * @return void
	 */
	private static function write_insert( $fh, $table, $columns_sql, array $tuples ) {
		if ( empty( $tuples ) ) {
			return true;
		}
		$line = 'INSERT INTO `' . self::ident( $table ) . '` (' . $columns_sql . ') VALUES ' . implode( ',', $tuples ) . ";\n";
		// Silenced: handled below by the caller; see ISX_Archive::write_ok().
		return @fwrite( $fh, $line ) === strlen( $line );
	}

	/**
	 * Build a single-line, semicolon-terminated single-row INSERT statement.
	 * Still used by insert_row() for the legacy "R\t" import path; the SQL-text
	 * dump now writes rows through write_insert() (multi-row) instead.
	 *
	 * @param string $table
	 * @param array  $row
	 * @return string
	 */
	private static function build_insert( $table, array $row ) {
		if ( empty( $row ) ) {
			return '';
		}
		return 'INSERT INTO `' . self::ident( $table ) . '` (' . self::columns_sql( $row ) . ') VALUES ' . self::value_tuple( $row ) . ';';
	}

	/**
	 * Escape one column value for build_insert(). Uses the raw mysqli link —
	 * the same approach All-in-One WP Migration's database driver takes — so
	 * wpdb's placeholder-escape layer never touches the data: wpdb's
	 * _real_escape() ends with add_placeholder_escape(), which rewrites every
	 * literal "%" into a per-request {64-hex-hash} token. Nothing in this
	 * dump/import path ever runs remove_placeholder_escape() (and the hash
	 * differs between the export and import requests anyway), so any value
	 * that went through _real_escape() would keep the hash forever — turning
	 * e.g. "100%" into "100{e845…}" on the restored site.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function escape_value( $value ) {
		global $wpdb;
		if ( $wpdb->dbh instanceof mysqli ) {
			return mysqli_real_escape_string( $wpdb->dbh, $value );
		}
		// No mysqli handle (custom db drop-in) — fall back to wpdb's escaping
		// with its placeholder hashes stripped back to literal "%".
		return $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $value ) );
	}

	/**
	 * Prepared INSERT with NULL handling.
	 *
	 * @param string $table
	 * @param array  $row
	 * @return void
	 */
	private static function insert_row( $table, array $row ) {
		global $wpdb;
		$sql = rtrim( self::build_insert( $table, $row ), ';' );
		if ( $sql !== '' ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Parse one of our own row-insert dump lines — table name, backtick-quoted
	 * column list, then one OR MORE parenthesised value tuples ("(...),(...)") —
	 * back into [ table, columns[], rows[] ] where each row is a values[] array,
	 * or null if it doesn't match the exact shape write_insert()/build_insert()
	 * writes. A legacy single-row line is just the one-tuple case, so old
	 * packages keep importing unchanged.
	 *
	 * @param string $line
	 * @return array|null
	 */
	private static function parse_insert( $line ) {
		if ( ! preg_match( '/^INSERT INTO `([^`]+)`\s*\(([^)]*)\)\s*VALUES\s*/', $line, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$table       = $m[1][0];
		$columns_str = $m[2][0];
		$columns     = array_map(
			function ( $c ) {
				return trim( $c, " `" );
			},
			explode( ',', $columns_str )
		);

		$len  = strlen( $line );
		$i    = $m[0][1] + strlen( $m[0][0] ); // Right after "VALUES ".
		$rows = array();
		while ( $i < $len ) {
			while ( $i < $len && $line[ $i ] === ' ' ) {
				$i++;
			}
			if ( $i >= $len || $line[ $i ] !== '(' ) {
				break; // End of tuples (reached ";" or line end).
			}
			$i++; // Consume the opening "(".
			list( $values, $end ) = self::parse_value_list( $line, $i );
			if ( $values === null || count( $columns ) !== count( $values ) ) {
				return null;
			}
			$rows[] = $values;
			$i      = $end;
			while ( $i < $len && $line[ $i ] === ' ' ) {
				$i++;
			}
			if ( $i < $len && $line[ $i ] === ',' ) {
				$i++;
				continue; // Another tuple follows.
			}
			break;
		}

		if ( empty( $rows ) ) {
			return null;
		}
		return array( $table, $columns, $rows );
	}

	/**
	 * Insert a batch of already-parsed rows (same table, same columns) as one
	 * multi-row query. Rows must have gone through prefix retargeting and
	 * search-replace already; escaping happens here via value_tuple().
	 *
	 * @param string $table
	 * @param array  $rows Array of column => value assoc arrays.
	 * @return void
	 */
	private static function insert_rows( $table, array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return;
		}
		$columns_sql = self::columns_sql( $rows[0] );
		$tuples      = array();
		foreach ( $rows as $row ) {
			$tuples[] = self::value_tuple( $row );
		}
		$sql = 'INSERT INTO `' . self::ident( $table ) . '` (' . $columns_sql . ') VALUES ' . implode( ',', $tuples );
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Parse a comma-separated, parenthesised value list where every value is
	 * either the bare keyword NULL or a single-quoted, backslash-escaped
	 * string (exactly what build_insert() writes) — starting right after the
	 * opening "(". Quote-aware, so commas/parens/quotes inside a string
	 * value never confuse it.
	 *
	 * @param string $str
	 * @param int    $offset Position right after the opening "(".
	 * @return array [ values[]|null, offset_after_closing_paren|null ]
	 */
	private static function parse_value_list( $str, $offset ) {
		$len    = strlen( $str );
		$i      = $offset;
		$values = array();

		while ( $i < $len ) {
			while ( $i < $len && $str[ $i ] === ' ' ) {
				$i++;
			}
			if ( $i >= $len ) {
				return array( null, null );
			}

			if ( $str[ $i ] === "'" ) {
				$i++;
				$buf    = '';
				$closed = false;
				while ( $i < $len ) {
					$ch = $str[ $i ];
					if ( $ch === '\\' && $i + 1 < $len ) {
						$buf .= $ch . $str[ $i + 1 ];
						$i    += 2;
						continue;
					}
					if ( $ch === "'" ) {
						$i++;
						$closed = true;
						break;
					}
					$buf .= $ch;
					$i++;
				}
				if ( ! $closed ) {
					return array( null, null );
				}
				$values[] = self::unescape_value( $buf );
			} elseif ( strtoupper( substr( $str, $i, 4 ) ) === 'NULL' ) {
				$values[] = null;
				$i       += 4;
			} else {
				return array( null, null );
			}

			while ( $i < $len && $str[ $i ] !== ',' && $str[ $i ] !== ')' ) {
				$i++;
			}
			if ( $i >= $len ) {
				return array( null, null );
			}
			if ( $str[ $i ] === ',' ) {
				$i++;
				continue;
			}
			$i++; // Closing ")".
			return array( $values, $i );
		}

		return array( null, null );
	}

	/**
	 * Exact inverse of $wpdb->_real_escape() (mysqli_real_escape_string)
	 * as applied by build_insert().
	 *
	 * @param string $escaped
	 * @return string
	 */
	private static function unescape_value( $escaped ) {
		$out = '';
		$len = strlen( $escaped );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $escaped[ $i ];
			if ( $ch === '\\' && $i + 1 < $len && isset( self::$escape_map[ $escaped[ $i + 1 ] ] ) ) {
				$out .= self::$escape_map[ $escaped[ $i + 1 ] ];
				$i++;
				continue;
			}
			$out .= $ch;
		}
		return $out;
	}

	/**
	 * Swap the table's leading prefix for the target one.
	 */
	private static function retarget_prefix( $table, $old_prefix, $new_prefix ) {
		if ( $old_prefix !== '' && $old_prefix !== $new_prefix && strpos( $table, $old_prefix ) === 0 ) {
			return $new_prefix . substr( $table, strlen( $old_prefix ) );
		}
		return $table;
	}

	/**
	 * Rewrite the table name inside a table-definition statement.
	 */
	private static function rewrite_create_table( $create, $table ) {
		return preg_replace(
			'/^CREATE TABLE `[^`]+`/',
			'CREATE TABLE `' . str_replace( '`', '', $table ) . '`',
			$create,
			1
		);
	}

	/**
	 * Strip backticks from an identifier.
	 */
	private static function ident( $name ) {
		return str_replace( '`', '', (string) $name );
	}
}
