<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISX archive container — an original, streamable package format.
 *
 * Layout:
 *   [8 bytes]  magic "ISXPK\0\0\1"
 *   repeated entries, each:
 *     [4 bytes]  header length  (uint32, little-endian)
 *     [N bytes]  header JSON     {"p":"path","s":size,"m":mtime}
 *     [size bytes] raw content
 *   end marker:
 *     [4 bytes]  0x00000000  (a zero-length header terminates the archive)
 *
 * The format is append-friendly: a job can add a few entries per AJAX request
 * (open in append mode, no terminator) and write the terminator only at the end.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Archive {

	const MAGIC     = "ISXPK\0\0\1";
	const CHUNK     = 1048576; // 1 MB streaming buffer.

	/**
	 * Create a new (empty) archive with just the magic header.
	 *
	 * @param string $path
	 * @return bool
	 */
	public static function init( $path ) {
		$handle = fopen( $path, 'wb' );
		if ( $handle === false ) {
			return false;
		}
		fwrite( $handle, self::MAGIC );
		fclose( $handle );
		return true;
	}

	/**
	 * Append a file from disk (streamed).
	 *
	 * @param string $path         Archive path.
	 * @param string $source_abs   Absolute path to the source file.
	 * @param string $archive_name Relative name to store it under.
	 * @return bool
	 */
	public static function add_file( $path, $source_abs, $archive_name ) {
		if ( ! is_file( $source_abs ) || ! is_readable( $source_abs ) ) {
			return false;
		}
		$size = filesize( $source_abs );
		$out  = fopen( $path, 'ab' );
		if ( $out === false ) {
			return false;
		}
		self::write_header( $out, $archive_name, $size, @filemtime( $source_abs ) );

		$in = fopen( $source_abs, 'rb' );
		if ( $in !== false ) {
			while ( ! feof( $in ) ) {
				$buffer = fread( $in, self::CHUNK );
				if ( $buffer === false ) {
					break;
				}
				fwrite( $out, $buffer );
			}
			fclose( $in );
		}
		fclose( $out );
		return true;
	}

	/**
	 * Append an in-memory string as an entry.
	 *
	 * @param string $path
	 * @param string $archive_name
	 * @param string $content
	 * @return bool
	 */
	public static function add_data( $path, $archive_name, $content ) {
		$out = fopen( $path, 'ab' );
		if ( $out === false ) {
			return false;
		}
		self::write_header( $out, $archive_name, strlen( $content ), time() );
		fwrite( $out, $content );
		fclose( $out );
		return true;
	}

	/**
	 * Write the terminating zero-length header.
	 *
	 * @param string $path
	 * @return void
	 */
	public static function finish( $path ) {
		$out = fopen( $path, 'ab' );
		if ( $out !== false ) {
			fwrite( $out, pack( 'V', 0 ) );
			fclose( $out );
		}
	}

	/**
	 * Validate the magic header.
	 *
	 * @param string $path
	 * @return bool
	 */
	public static function is_valid( $path ) {
		if ( ! is_file( $path ) ) {
			return false;
		}
		$handle = fopen( $path, 'rb' );
		if ( $handle === false ) {
			return false;
		}
		$magic = fread( $handle, strlen( self::MAGIC ) );
		fclose( $handle );
		return $magic === self::MAGIC;
	}

	/**
	 * Iterate entries. Calls $callback( array $header, resource $handle ) for each
	 * entry; the callback must consume exactly $header['s'] bytes from $handle
	 * (or return false to stop). If the callback reads nothing, the reader skips
	 * the content automatically.
	 *
	 * @param string   $path
	 * @param callable $callback
	 * @return bool
	 */
	public static function each( $path, $callback ) {
		$handle = fopen( $path, 'rb' );
		if ( $handle === false ) {
			return false;
		}
		$magic = fread( $handle, strlen( self::MAGIC ) );
		if ( $magic !== self::MAGIC ) {
			fclose( $handle );
			return false;
		}

		while ( true ) {
			$len_raw = fread( $handle, 4 );
			if ( $len_raw === false || strlen( $len_raw ) < 4 ) {
				break; // physical EOF.
			}
			$unpacked = unpack( 'V', $len_raw );
			$len      = $unpacked[1];
			if ( $len === 0 ) {
				break; // terminator.
			}
			$header_json = fread( $handle, $len );
			$header      = json_decode( $header_json, true );
			if ( ! is_array( $header ) || ! isset( $header['s'] ) ) {
				break;
			}

			$start = ftell( $handle );
			$stop  = call_user_func( $callback, $header, $handle );

			// Ensure the stream is positioned right after this entry's content,
			// regardless of how much the callback consumed.
			fseek( $handle, $start + (int) $header['s'], SEEK_SET );

			if ( $stop === false ) {
				break;
			}
		}

		fclose( $handle );
		return true;
	}

	/**
	 * Extract every entry to $base_dir (files whose path is inside the base).
	 *
	 * @param string $path
	 * @param string $base_dir
	 * @return bool
	 */
	public static function extract_all( $path, $base_dir ) {
		return self::each(
			$path,
			function ( $header, $handle ) use ( $base_dir ) {
				$rel = self::sanitize_relative( $header['p'] );
				if ( $rel === '' ) {
					return true;
				}
				$dest = rtrim( $base_dir, '/\\' ) . '/' . $rel;
				wp_mkdir_p( dirname( $dest ) );

				$out       = fopen( $dest, 'wb' );
				$remaining = (int) $header['s'];
				if ( $out !== false ) {
					while ( $remaining > 0 ) {
						$read   = min( self::CHUNK, $remaining );
						$buffer = fread( $handle, $read );
						if ( $buffer === false || $buffer === '' ) {
							break;
						}
						fwrite( $out, $buffer );
						$remaining -= strlen( $buffer );
					}
					fclose( $out );
				}
				return true;
			}
		);
	}

	/**
	 * The byte offset of the first entry (right after the magic header).
	 *
	 * @return int
	 */
	public static function first_offset() {
		return strlen( self::MAGIC );
	}

	/**
	 * Resumable iteration: process up to $max entries starting at $offset.
	 * Calls $callback( array $header, resource $handle ) per entry. Returns
	 * { offset:int (resume point), done:bool }.
	 *
	 * @param string   $path
	 * @param int      $offset
	 * @param int      $max
	 * @param callable $callback
	 * @return array
	 */
	public static function read_batch( $path, $offset, $max, $callback ) {
		$handle = fopen( $path, 'rb' );
		if ( $handle === false ) {
			return array( 'offset' => $offset, 'done' => true );
		}
		fseek( $handle, $offset );

		$done      = false;
		$processed = 0;
		while ( $processed < $max ) {
			$len_raw = fread( $handle, 4 );
			if ( $len_raw === false || strlen( $len_raw ) < 4 ) {
				$done = true;
				break;
			}
			$unpacked = unpack( 'V', $len_raw );
			$len      = $unpacked[1];
			if ( $len === 0 ) {
				$done = true;
				break;
			}
			$header = json_decode( fread( $handle, $len ), true );
			if ( ! is_array( $header ) || ! isset( $header['s'] ) ) {
				$done = true;
				break;
			}

			$start = ftell( $handle );
			call_user_func( $callback, $header, $handle );
			fseek( $handle, $start + (int) $header['s'], SEEK_SET );
			$offset = ftell( $handle );
			$processed++;
		}

		fclose( $handle );
		return array( 'offset' => $offset, 'done' => $done );
	}

	/**
	 * Write an entry header.
	 *
	 * @param resource $out
	 * @param string   $name
	 * @param int      $size
	 * @param int      $mtime
	 * @return void
	 */
	private static function write_header( $out, $name, $size, $mtime ) {
		$header = wp_json_encode(
			array(
				'p' => self::sanitize_relative( $name ),
				's' => (int) $size,
				'm' => (int) $mtime,
			)
		);
		fwrite( $out, pack( 'V', strlen( $header ) ) );
		fwrite( $out, $header );
	}

	/**
	 * Normalise a stored path and strip any traversal.
	 *
	 * @param string $name
	 * @return string
	 */
	private static function sanitize_relative( $name ) {
		$name = str_replace( '\\', '/', (string) $name );
		$name = ltrim( $name, '/' );
		$parts = array();
		foreach ( explode( '/', $name ) as $segment ) {
			if ( $segment === '' || $segment === '.' || $segment === '..' ) {
				continue;
			}
			$parts[] = $segment;
		}
		return implode( '/', $parts );
	}
}
