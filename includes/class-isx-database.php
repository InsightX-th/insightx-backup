<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Database export & import via $wpdb.
 *
 * Export writes a line-based dump where each line is one of:
 *   T\t<table>\t<base64(CREATE TABLE ...)>
 *   R\t<table>\t<base64(serialize(row_assoc))>
 * Rows are PHP-serialized so binary/serialized column values survive intact;
 * base64 keeps every record on a single line. On import each row is unserialized,
 * passed through ISX_Serialize::replace (serialized-safe search & replace) and
 * written back with a prepared INSERT.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Database {

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
	 * Append a table's schema line to the dump handle.
	 *
	 * @param resource $fh
	 * @param string   $table
	 * @return void
	 */
	public static function dump_schema( $fh, $table ) {
		global $wpdb;
		$row = $wpdb->get_row( 'SHOW CREATE TABLE `' . self::ident( $table ) . '`', ARRAY_N );
		$create = isset( $row[1] ) ? $row[1] : '';
		fwrite( $fh, 'T' . "\t" . $table . "\t" . base64_encode( $create ) . "\n" );
	}

	/**
	 * Append a batch of rows. Returns the number of rows written.
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
			fwrite( $fh, 'R' . "\t" . $table . "\t" . base64_encode( serialize( $row ) ) . "\n" );
		}
		return count( $rows );
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
		$parts = explode( "\t", $line, 3 );
		if ( count( $parts ) < 3 ) {
			return;
		}
		list( $type, $table, $payload ) = $parts;
		$table = self::retarget_prefix( $table, $old_prefix, $new_prefix );

		if ( $type === 'T' ) {
			$create = base64_decode( $payload );
			// Point the CREATE at the (possibly re-prefixed) table name.
			$create = self::rewrite_create_table( $create, $table );
			$wpdb->query( 'DROP TABLE IF EXISTS `' . self::ident( $table ) . '`' );
			$wpdb->query( $create );
			return;
		}

		if ( $type === 'R' ) {
			$row = @unserialize( base64_decode( $payload ) ); // phpcs:ignore
			if ( ! is_array( $row ) ) {
				return;
			}
			$row = ISX_Serialize::replace( $row, $search, $replace, $skip_emails );
			self::insert_row( $table, $row );
		}
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
		$columns = array();
		$values  = array();
		foreach ( $row as $column => $value ) {
			$columns[] = '`' . self::ident( $column ) . '`';
			if ( is_null( $value ) ) {
				$values[] = 'NULL';
			} else {
				$values[] = "'" . $wpdb->_real_escape( $value ) . "'";
			}
		}
		if ( empty( $columns ) ) {
			return;
		}
		$sql = 'INSERT INTO `' . self::ident( $table ) . '` (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')';
		$wpdb->query( $sql );
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
