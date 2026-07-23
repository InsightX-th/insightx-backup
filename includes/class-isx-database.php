<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Database export & import via $wpdb.
 *
 * The dump is a plain, line-per-statement .sql file — genuine `CREATE TABLE
 * ...;` and `INSERT INTO ... VALUES (...);` statements, one per physical
 * line (CREATE TABLE's normally-multi-line output is collapsed to one line;
 * SQL doesn't care about whitespace between tokens outside quoted values) so
 * the resumable AJAX-batch reader can keep working one fgets() at a time.
 * Every value is written as either the bare keyword NULL or a single-quoted,
 * backslash-escaped string (see build_insert()) — deliberately never a bare
 * numeric literal — which keeps import parsing (parse_value_list()) to two
 * cases instead of needing a real SQL literal grammar.
 *
 * On import, a row is parsed back into a plain column => value array,
 * passed through ISX_Serialize::replace() (serialized-safe search & replace
 * — walks PHP-serialized column values and recomputes their length prefixes,
 * so it's safe to run on the *parsed* values here) and written back with a
 * prepared INSERT. Packages exported before this format existed used a
 * custom tab-delimited T\t/R\t line format instead; import_line() still
 * reads those too (see import_legacy_line()) so old backups keep working.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Database {

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
	 * Append a table's CREATE TABLE statement to the dump handle.
	 *
	 * @param resource $fh
	 * @param string   $table
	 * @return void
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
		fwrite( $fh, $create . ";\n" );
	}

	/**
	 * Append a batch of rows as INSERT statements. Returns the number of rows written.
	 *
	 * @param resource     $fh
	 * @param string       $table
	 * @param int          $offset
	 * @param int          $limit
	 * @param string       $extra_where Raw SQL condition (already trusted/static), no leading AND/WHERE.
	 * @param string|array $search      Find & replace pairs applied to every row before writing (export-time).
	 * @param string|array $replace
	 * @return int
	 */
	public static function dump_rows( $fh, $table, $offset, $limit, $extra_where = '', $search = array(), $replace = array() ) {
		global $wpdb;
		$sql = 'SELECT * FROM `' . self::ident( $table ) . '`';
		if ( $extra_where !== '' ) {
			$sql .= ' WHERE ' . $extra_where;
		}
		$sql .= $wpdb->prepare( ' LIMIT %d, %d', $offset, $limit );

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}
		$has_replace = ! empty( $search );
		foreach ( $rows as $row ) {
			if ( $has_replace ) {
				$row = ISX_Serialize::replace( $row, $search, $replace );
			}
			$insert = self::build_insert( $table, $row );
			if ( $insert !== '' ) {
				fwrite( $fh, $insert . "\n" );
			}
		}
		return count( $rows );
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
			list( $table, $columns, $values ) = $parsed;
			$table = self::retarget_prefix( $table, $old_prefix, $new_prefix );
			$row   = @array_combine( $columns, $values ); // phpcs:ignore
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
	 * Build a single-line, semicolon-terminated INSERT statement for one row.
	 * The exact inverse of parse_insert()/parse_value_list() below.
	 *
	 * @param string $table
	 * @param array  $row
	 * @return string
	 */
	private static function build_insert( $table, array $row ) {
		global $wpdb;
		$columns = array();
		$values  = array();
		foreach ( $row as $column => $value ) {
			$columns[] = '`' . self::ident( $column ) . '`';
			// Always a quoted string or NULL — never a bare numeric literal —
			// so the import-side parser only ever has to handle those two
			// shapes instead of a full SQL literal grammar.
			$values[] = is_null( $value ) ? 'NULL' : "'" . $wpdb->_real_escape( $value ) . "'";
		}
		if ( empty( $columns ) ) {
			return '';
		}
		return 'INSERT INTO `' . self::ident( $table ) . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ');';
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
	 * Parse one of our own "INSERT INTO `table` (`c1`,`c2`) VALUES (v1,v2);"
	 * lines back into [ table, columns[], values[] ], or null if it doesn't
	 * match the exact shape build_insert() writes.
	 *
	 * @param string $line
	 * @return array|null
	 */
	private static function parse_insert( $line ) {
		if ( ! preg_match( '/^INSERT INTO `([^`]+)`\s*\(([^)]*)\)\s*VALUES\s*\(/', $line, $m, PREG_OFFSET_CAPTURE ) ) {
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

		$values_start = $m[0][1] + strlen( $m[0][0] ); // Right after "VALUES (".
		list( $values, $end ) = self::parse_value_list( $line, $values_start );
		if ( $values === null || count( $columns ) !== count( $values ) ) {
			return null;
		}
		return array( $table, $columns, $values );
	}

	/**
	 * Parse a comma-separated VALUES(...) list where every value is either
	 * the bare keyword NULL or a single-quoted, backslash-escaped string
	 * (exactly what build_insert() writes) — starting right after the
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
	 * Rewrite the table name inside a CREATE TABLE statement.
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
