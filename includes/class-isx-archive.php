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
	 * fwrite() returning fewer bytes than requested (or false) is how a full
	 * disk actually shows up in PHP — it doesn't throw, and every call site
	 * that ignored this return value was quietly shipping a truncated archive
	 * as a "successful" backup. This is the one place that treats it as the
	 * failure it is.
	 *
	 * @param resource $handle
	 * @param string   $data
	 * @return bool
	 */
	private static function write_ok( $handle, $data ) {
		// Silenced deliberately. A short write on a full disk also emits a PHP
		// notice, and these run inside admin-ajax handlers whose response is
		// JSON — a notice printed ahead of it makes the body unparseable, so the
		// browser reports a generic connection failure instead of the "ดิสก์
		// เต็ม" message this return value is about to produce.
		return @fwrite( $handle, $data ) === strlen( $data );
	}

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
		$ok = self::write_ok( $handle, self::MAGIC );
		return fclose( $handle ) && $ok;
	}

	/**
	 * Append a file from disk (streamed).
	 *
	 * @param string $path         Archive path.
	 * @param string $source_abs   Absolute path to the source file.
	 * @param string $archive_name Relative name to store it under.
	 * @param bool   $compress     Store raw-DEFLATE compressed instead of raw.
	 * @return bool|null True on success, false if a write to $path failed (disk
	 *                   full — nothing was committed for this entry, but the
	 *                   archive itself may now be unusable), null if $source_abs
	 *                   simply wasn't there to read (common on a live site —
	 *                   plugins/themes/uploads change under a running backup —
	 *                   and not a reason to abort the whole export).
	 */
	public static function add_file( $path, $source_abs, $archive_name, $compress = false ) {
		if ( ! is_file( $source_abs ) || ! is_readable( $source_abs ) ) {
			return null;
		}
		$original_size = filesize( $source_abs );
		if ( $original_size === false ) {
			return null;
		}
		$mtime = @filemtime( $source_abs );

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
			$ok = true;
			while ( ! feof( $in ) ) {
				$buffer = fread( $in, self::CHUNK );
				if ( $buffer === false ) {
					$ok = false;
					break;
				}
				if ( ! self::write_ok( $tmp_out, $buffer ) ) {
					$ok = false;
					break;
				}
			}
			fclose( $in );

			// The deflate filter buffers, so this fclose() is where a full disk
			// actually reports itself on this path — fwrite() above only ever saw
			// the bytes going *into* the filter, never the (smaller, and possibly
			// unwritable) bytes coming out of it.
			if ( ! fclose( $tmp_out ) ) {
				$ok = false;
			}

			if ( ! $ok ) {
				@unlink( $tmp );
				return false;
			}

			$stored_size = filesize( $tmp );
			if ( $stored_size === false ) {
				@unlink( $tmp );
				return false;
			}

			$out = fopen( $path, 'ab' );
			if ( $out === false ) {
				@unlink( $tmp );
				return false;
			}
			$ok  = self::write_header( $out, $archive_name, $stored_size, $mtime, true, $original_size );
			$ok  = $ok && self::copy_exactly( $tmp, $out, $stored_size );
			$ok  = fclose( $out ) && $ok;
			@unlink( $tmp );
			return $ok;
		}

		$out = fopen( $path, 'ab' );
		if ( $out === false ) {
			return false;
		}
		$ok = self::write_header( $out, $archive_name, $original_size, $mtime );
		$ok = $ok && self::copy_exactly( $source_abs, $out, $original_size );
		$ok = fclose( $out ) && $ok;
		return $ok;
	}

	/**
	 * Copy exactly $size bytes from $source_path into the already-open $out.
	 *
	 * "Exactly" is the whole point, and it is not pedantry: this format writes
	 * an entry's declared size in a header *before* its content, and readers
	 * skip to the next entry with fseek( start + size ). Write fewer bytes than
	 * declared — the source file shrank mid-backup (a rotating log, a cache file
	 * being rewritten, a plugin updating itself) or a read failed — and every
	 * entry after this one is parsed from the wrong offset, so the archive is
	 * silently corrupt from here to the end while the export still reports
	 * success. Writing more (the file grew) misaligns it just the same.
	 *
	 * @param string   $source_path
	 * @param resource $out
	 * @param int      $size
	 * @return bool
	 */
	private static function copy_exactly( $source_path, $out, $size ) {
		// Silenced: a source that disappeared between the caller's is_file()
		// check and here is ordinary churn on a live site, and the caller
		// decides what it means — no reason to spray a PHP warning into the
		// site's error log for every rotated cache file.
		$in = @fopen( $source_path, 'rb' );
		if ( $in === false ) {
			return false;
		}

		$remaining = (int) $size;
		$ok        = true;
		while ( $remaining > 0 ) {
			$buffer = fread( $in, (int) min( self::CHUNK, $remaining ) );
			if ( $buffer === false || $buffer === '' ) {
				$ok = false; // Source is shorter than it claimed to be.
				break;
			}
			if ( ! self::write_ok( $out, $buffer ) ) {
				$ok = false;
				break;
			}
			$remaining -= strlen( $buffer );
		}
		fclose( $in );

		return $ok && $remaining === 0;
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
			$ok = self::write_header( $out, $archive_name, strlen( $deflated ), time(), true, strlen( $content ) )
				&& self::write_ok( $out, $deflated );
		} else {
			$ok = self::write_header( $out, $archive_name, strlen( $content ), time() )
				&& self::write_ok( $out, $content );
		}
		return fclose( $out ) && $ok;
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
	 * @return bool False if the destination write failed (disk full) or the
	 *              source ran out before every declared byte was read (a
	 *              truncated/corrupt archive) — either way $dest_path was left
	 *              short of what the entry actually says it is.
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
		$ok        = true;
		while ( $remaining > 0 ) {
			$read   = min( self::CHUNK, $remaining );
			$buffer = fread( $handle, $read );
			if ( $buffer === false || $buffer === '' ) {
				$ok = false; // Source archive ran dry before the entry said it would.
				break;
			}
			if ( ! self::write_ok( $out, $buffer ) ) {
				$ok = false;
				break;
			}
			$remaining -= strlen( $buffer );
		}

		// For a compressed entry the inflate filter buffers, so fwrite() above
		// reported on bytes entering the filter, not bytes reaching the disk —
		// fclose() is the only place a full disk shows up on that path.
		return fclose( $out ) && $ok;
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
	 * @return bool
	 */
	public static function finish( $path ) {
		$out = fopen( $path, 'ab' );
		if ( $out === false ) {
			return false;
		}
		$ok = self::write_ok( $out, pack( 'V', 0 ) );
		return fclose( $out ) && $ok;
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
	 * Calls $callback( array $header, resource $handle ) per entry — return
	 * exactly `false` from it to signal that entry failed to restore (a write
	 * that hit a full disk, most likely); any other return value is treated as
	 * success. Returns { offset:int (resume point), done:bool, ok:bool }.
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
			return array( 'offset' => $offset, 'done' => true, 'ok' => true );
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

			$start  = ftell( $handle );
			$result = call_user_func( $callback, $header, $handle );
			if ( $result === false ) {
				// Leave $offset at the start of this entry, not past it — same
				// reasoning as pack_batch() on the export side: a failed write
				// is a hard stop, and resuming as if it had restored would leave
				// this file permanently missing with nothing to say so.
				fclose( $handle );
				return array( 'offset' => $offset, 'done' => false, 'ok' => false );
			}
			fseek( $handle, $start + (int) $header['s'], SEEK_SET );
			$offset = ftell( $handle );
			$processed++;
		}

		fclose( $handle );
		return array( 'offset' => $offset, 'done' => $done, 'ok' => true );
	}

	/**
	 * Walk the archive confirming every entry's content is actually present,
	 * resuming from a byte offset so a multi-GB package can be checked across
	 * several requests instead of one that a proxy would cut off.
	 *
	 * is_valid() only compares the 8-byte magic, which a package truncated at
	 * 30% passes without blinking — and the import pipeline deletes wp-content
	 * before it ever touches the entries, so "the package was short" surfaced
	 * only after the site it was meant to restore had already been wiped. This
	 * exists so that check can happen while the site is still intact.
	 *
	 * @param string $path
	 * @param int    $offset   Resume point; first_offset() to start.
	 * @param float  $deadline microtime(true) to stop at and report progress.
	 * @return array { offset:int, done:bool, ok:bool, entries:int, error:string }
	 */
	public static function verify_batch( $path, $offset, $deadline ) {
		$fail = function ( $offset, $entries, $error ) {
			return array( 'offset' => $offset, 'done' => true, 'ok' => false, 'entries' => $entries, 'error' => $error );
		};

		$handle = @fopen( $path, 'rb' );
		if ( $handle === false ) {
			return $fail( $offset, 0, 'เปิดไฟล์แพ็กเกจไม่ได้' );
		}

		$size = filesize( $path );
		if ( fseek( $handle, $offset ) !== 0 ) {
			fclose( $handle );
			return $fail( $offset, 0, 'ไฟล์แพ็กเกจสั้นกว่าที่ควรจะเป็น' );
		}

		$entries = 0;
		while ( true ) {
			$len_raw = fread( $handle, 4 );
			if ( $len_raw === false || strlen( $len_raw ) < 4 ) {
				// Ran out of file without ever meeting the terminator — the
				// classic shape of an upload or download that stopped early.
				fclose( $handle );
				return $fail( $offset, $entries, 'ไฟล์แพ็กเกจไม่สมบูรณ์ (จบกลางคัน ไม่พบเครื่องหมายปิดท้ายไฟล์)' );
			}

			$unpacked = unpack( 'V', $len_raw );
			if ( $unpacked[1] === 0 ) {
				$end = ftell( $handle );
				fclose( $handle );
				return array( 'offset' => $end, 'done' => true, 'ok' => true, 'entries' => $entries, 'error' => '' );
			}

			$header = json_decode( fread( $handle, $unpacked[1] ), true );
			if ( ! is_array( $header ) || ! isset( $header['s'] ) ) {
				fclose( $handle );
				return $fail( $offset, $entries, 'ไฟล์แพ็กเกจเสียหาย (อ่านรายการไฟล์ข้างในไม่ได้)' );
			}

			// Seek past the content rather than read it — verification is about
			// the bytes being *there*, and reading 6GB to prove that would take
			// as long as the restore itself.
			$content_end = ftell( $handle ) + (int) $header['s'];
			if ( $size !== false && $content_end > $size ) {
				fclose( $handle );
				return $fail(
					$offset,
					$entries,
					sprintf( 'ไฟล์แพ็กเกจไม่สมบูรณ์ (ข้อมูลของ "%s" ขาดหายไป)', isset( $header['p'] ) ? $header['p'] : '?' )
				);
			}
			fseek( $handle, $content_end, SEEK_SET );

			$offset = $content_end;
			$entries++;

			if ( microtime( true ) >= $deadline ) {
				fclose( $handle );
				return array( 'offset' => $offset, 'done' => false, 'ok' => true, 'entries' => $entries, 'error' => '' );
			}
		}
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
	 * @return bool
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
		return self::write_ok( $out, pack( 'V', strlen( $header ) ) ) && self::write_ok( $out, $header );
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
