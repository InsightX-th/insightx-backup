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
 *     [N bytes]  header JSON     {"p":"path","s":size,"m":mtime,"z":0|1,"u":origSize}
 *     [size bytes] content — raw, or raw-DEFLATE (RFC1951) compressed when "z":1.
 *       "s" is always the stored (on-disk) byte count, used to seek to the next
 *       entry regardless of compression; "u" (only present when "z":1) is the
 *       original decompressed size, for progress/size display.
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
	 * @param bool   $compress     Store raw-DEFLATE compressed instead of raw.
	 * @return bool
	 */
	public static function add_file( $path, $source_abs, $archive_name, $compress = false ) {
		if ( ! is_file( $source_abs ) || ! is_readable( $source_abs ) ) {
			return false;
		}
		$original_size = filesize( $source_abs );
		$mtime         = @filemtime( $source_abs );

		if ( $compress ) {
			// Compress to a scratch file first so the real stored size is known
			// up front (headers precede content in this append-only format, so
			// there's no going back to patch it in afterwards) — still fully
			// streamed via a filter, never buffers the whole file in memory,
			// so this is safe for large uploads too.
			$tmp = $path . '.entrytmp';
			$tmp_out = fopen( $tmp, 'wb' );
			if ( $tmp_out === false ) {
				return false;
			}
			stream_filter_append( $tmp_out, 'zlib.deflate', STREAM_FILTER_WRITE );
			$in = fopen( $source_abs, 'rb' );
			if ( $in === false ) {
				fclose( $tmp_out );
				@unlink( $tmp );
				return false;
			}
			while ( ! feof( $in ) ) {
				$buffer = fread( $in, self::CHUNK );
				if ( $buffer === false ) {
					break;
				}
				fwrite( $tmp_out, $buffer );
			}
			fclose( $in );
			fclose( $tmp_out ); // Flushes the deflate filter.

			$out = fopen( $path, 'ab' );
			if ( $out === false ) {
				@unlink( $tmp );
				return false;
			}
			self::write_header( $out, $archive_name, filesize( $tmp ), $mtime, true, $original_size );
			$tin = fopen( $tmp, 'rb' );
			if ( $tin !== false ) {
				while ( ! feof( $tin ) ) {
					$buffer = fread( $tin, self::CHUNK );
					if ( $buffer === false ) {
						break;
					}
					fwrite( $out, $buffer );
				}
				fclose( $tin );
			}
			fclose( $out );
			@unlink( $tmp );
			return true;
		}

		$out = fopen( $path, 'ab' );
		if ( $out === false ) {
			return false;
		}
		self::write_header( $out, $archive_name, $original_size, $mtime );

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
	 * @param bool   $compress
	 * @return bool
	 */
	public static function add_data( $path, $archive_name, $content, $compress = false ) {
		$out = fopen( $path, 'ab' );
		if ( $out === false ) {
			return false;
		}
		if ( $compress ) {
			$deflated = gzdeflate( $content );
			self::write_header( $out, $archive_name, strlen( $deflated ), time(), true, strlen( $content ) );
			fwrite( $out, $deflated );
		} else {
			self::write_header( $out, $archive_name, strlen( $content ), time() );
			fwrite( $out, $content );
		}
		fclose( $out );
		return true;
	}

	/**
	 * Stream an entry's content out to a destination file, transparently
	 * inflating first if it was stored compressed. Shared by every consumer
	 * that materialises an entry back onto disk (restore_stream(), the DB
	 * dump restore copy, extract_all()) so the decompression logic — the
	 * fiddly part — exists in exactly one place.
	 *
	 * @param resource $handle    Archive handle, positioned at the entry's content.
	 * @param array    $header
	 * @param string   $dest_path
	 * @return bool
	 */
	public static function stream_entry_to_file( $handle, $header, $dest_path ) {
		$out = fopen( $dest_path, 'wb' );
		if ( $out === false ) {
			return false;
		}
		if ( ! empty( $header['z'] ) ) {
			stream_filter_append( $out, 'zlib.inflate' );
		}

		$remaining = (int) $header['s'];
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
		return true;
	}

	/**
	 * Read an entry's full content into memory as a string (for small entries
	 * like manifest/package.json — never used for arbitrarily large files,
	 * see stream_entry_to_file() for those), inflating first if compressed.
	 *
	 * @param resource $handle
	 * @param array    $header
	 * @return string|false
	 */
	public static function read_entry_string( $handle, $header ) {
		$data = fread( $handle, (int) $header['s'] );
		if ( $data === false ) {
			return false;
		}
		if ( ! empty( $header['z'] ) ) {
			$data = @gzinflate( $data ); // phpcs:ignore
		}
		return $data;
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
				self::stream_entry_to_file( $handle, $header, $dest );
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
	 * @param int      $size           Stored (on-disk) byte count.
	 * @param int      $mtime
	 * @param bool     $compressed
	 * @param int|null $original_size  Only meaningful when $compressed is true.
	 * @return void
	 */
	private static function write_header( $out, $name, $size, $mtime, $compressed = false, $original_size = null ) {
		$data = array(
			'p' => self::sanitize_relative( $name ),
			's' => (int) $size,
			'm' => (int) $mtime,
		);
		if ( $compressed ) {
			$data['z'] = 1;
			$data['u'] = (int) $original_size;
		}
		$header = wp_json_encode( $data );
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
