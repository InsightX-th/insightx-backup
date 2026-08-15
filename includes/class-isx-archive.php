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
 *     [N bytes]  header JSON     {"p":"path","s":size,"m":mtime,"z":0|1,"u":origSize,"c":"crc32"}
 *     [size bytes] content — raw, or raw-DEFLATE (RFC1951) compressed when "z":1.
 *       "s" is always the stored (on-disk) byte count, used to seek to the next
 *       entry regardless of compression; "u" (only present when "z":1) is the
 *       original decompressed size, for progress/size display.
 *   end marker:
 *     [4 bytes]  0x00000000  (a zero-length header terminates the archive)
 *
 * "c" (written by current-format exports) is the lowercase-hex CRC32 of the
 * ORIGINAL (uncompressed) content. Readers verify a restored file against it,
 * so a same-length corruption — a bad sector, a proxy mangling bytes — that
 * every size-based check would pass is caught instead of silently restored.
 * Entries written before this field existed carry no "c" and are restored
 * without the check.
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
	 * verify_batch() fully reads and CRC-checks entries up to this many stored
	 * bytes; larger ones are only seeked past, because reading them costs as
	 * much as the restore itself. The bulk of theme/plugin/config files sits
	 * far below this, and a same-length corruption in any of them is caught
	 * while the old site is still standing — the seek-only check it replaces
	 * let those through to surface only after clean() had wiped wp-content.
	 * Filterable per site: isx_verify_crc_max_bytes.
	 */
	const VERIFY_CRC_MAX_BYTES = 4194304; // 4 MB.

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
	 * The source is first snapshotted to a scratch file (raw or deflated,
	 * hashing the original bytes as they stream through), then the scratch is
	 * appended to the archive. Snapshotting is what makes the entry's header
	 * — written before its content, in this append-only format — honest:
	 *
	 *  - The CRC in the header describes *exactly* the bytes the entry stores.
	 *    A separate hash pass (the previous design) raced a file being
	 *    rewritten on a live site — the copy stored version A, the hash had
	 *    recorded version B, and restore then hard-stopped a legitimately
	 *    packed entry as "corrupt" after the old site was already wiped.
	 *  - The stored size is exact, and a source that changes size mid-read
	 *    (growing: only its original-size prefix is kept; shrinking: the entry
	 *    is failed) can't misalign every subsequent entry.
	 *  - The source is read once, not twice (the old hash pass was a second
	 *    full read of every file).
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

		// Snapshot pass: stream the source into a scratch file, hashing the
		// ORIGINAL bytes as they go (the CRC the reader verifies is over the
		// uncompressed content). hash_init/hash_update stream, so this never
		// buffers the file in memory; filterable to disable per site.
		$tmp     = $path . '.entrytmp';
		$tmp_out = fopen( $tmp, 'wb' );
		if ( $tmp_out === false ) {
			return false;
		}
		$crc_ctx = apply_filters( 'isx_entry_checksum', true ) ? hash_init( 'crc32b' ) : false;
		if ( $compress ) {
			stream_filter_append( $tmp_out, 'zlib.deflate', STREAM_FILTER_WRITE );
		}

		$in = fopen( $source_abs, 'rb' );
		if ( $in === false ) {
			fclose( $tmp_out );
			@unlink( $tmp );
			return false;
		}
		$ok        = true;
		$remaining = (int) $original_size;
		while ( $remaining > 0 ) {
			$buffer = fread( $in, (int) min( self::CHUNK, $remaining ) );
			if ( $buffer === false || $buffer === '' ) {
				$ok = false; // Source shrank mid-read — the snapshot would be short.
				break;
			}
			if ( $crc_ctx ) {
				hash_update( $crc_ctx, $buffer );
			}
			if ( ! self::write_ok( $tmp_out, $buffer ) ) {
				$ok = false;
				break;
			}
			$remaining -= strlen( $buffer );
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
		$crc = $crc_ctx ? hash_final( $crc_ctx ) : '';

		// Commit pass: the snapshot is immutable now, so the header (real CRC,
		// exact stored size) followed by a byte-exact copy of it into the
		// archive is guaranteed self-consistent.
		$out = fopen( $path, 'ab' );
		if ( $out === false ) {
			@unlink( $tmp );
			return false;
		}
		$ok  = self::write_header( $out, $archive_name, $stored_size, $mtime, $compress, $compress ? $original_size : null, $crc );
		$ok  = $ok && self::copy_exactly( $tmp, $out, $stored_size );
		$ok  = fclose( $out ) && $ok;
		@unlink( $tmp );
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
		$crc = apply_filters( 'isx_entry_checksum', true ) ? hash( 'crc32b', $content ) : '';
		if ( $compress ) {
			$deflated = gzdeflate( $content );
			$ok = self::write_header( $out, $archive_name, strlen( $deflated ), time(), true, strlen( $content ), $crc )
				&& self::write_ok( $out, $deflated );
		} else {
			$ok = self::write_header( $out, $archive_name, strlen( $content ), time(), false, null, $crc )
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
		$closed = fclose( $out );

		// Verify the restored bytes against the CRC32 the export recorded in
		// the header. This is what makes a restored file provably the same as
		// what was packed: a same-length corruption (bad sector, proxy
		// mangling bytes, a truncated inflate) passes every size-based check
		// but fails this, and the entry is failed loudly instead of landing
		// silently. hash_file() reads the just-written file back from the
		// page cache, so the cost is a second (cached) read.
		if ( $closed && $ok && ! empty( $header['c'] ) ) {
			$crc = @hash_file( 'crc32b', $dest_path );
			if ( $crc === false || $crc !== $header['c'] ) {
				@unlink( $dest_path );
				$closed = false; // Callers hard-stop on a false return.
			}
		}

		// Restore the original modification time so the clone matches the
		// source in more than bytes — some plugins and cache layers key off
		// file mtimes. Old packages without an 'm' field are left at "now".
		if ( $closed && $ok && ! empty( $header['m'] ) ) {
			@touch( $dest_path, (int) $header['m'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		return $closed && $ok;
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
		// fread() throws on a length of 0 under PHP 8, and an entry is allowed
		// to be empty — see verify_batch() for where that actually bit.
		$len  = (int) $header['s'];
		$data = $len > 0 ? fread( $handle, $len ) : '';
		if ( $data === false ) {
			return false;
		}
		if ( $len > 0 && ! empty( $header['z'] ) ) {
			$data = @gzinflate( $data ); // phpcs:ignore
			if ( $data === false ) {
				return false; // Corrupt deflate stream — don't trust the bytes.
			}
		}
		if ( ! empty( $header['c'] ) && hash( 'crc32b', $data ) !== $header['c'] ) {
			return false; // Same-length corruption the size checks would miss.
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

			// Small entries are fully read and CRC-checked rather than seeked
			// past: a same-length corruption passes a size check, and verify()
			// is the last gate before clean() wipes the old site — every check
			// that can reject a package has to happen while it's still
			// standing. Large entries (media) stay seek-only; extract()
			// CRC-checks those as they're restored.
			$content_end = ftell( $handle ) + (int) $header['s'];
			if ( $size !== false && $content_end > $size ) {
				fclose( $handle );
				return $fail(
					$offset,
					$entries,
					sprintf( 'ไฟล์แพ็กเกจไม่สมบูรณ์ (ข้อมูลของ "%s" ขาดหายไป)', isset( $header['p'] ) ? $header['p'] : '?' )
				);
			}
			$len = (int) $header['s'];
			if ( $len <= (int) apply_filters( 'isx_verify_crc_max_bytes', self::VERIFY_CRC_MAX_BYTES ) && ! empty( $header['c'] ) ) {
				// Zero-byte entries are ordinary in a real site (empty index.php
				// guards, placeholder files, minified assets that compiled to
				// nothing) and the export records crc32b('') = "00000000" for
				// them, so they reach this branch — but fread() with a length of
				// 0 is a ValueError on PHP 8, which killed the whole verify
				// request and surfaced in the browser as "can't reach the
				// server". Read nothing and let the CRC below confirm it.
				$stored = $len > 0 ? fread( $handle, $len ) : '';
				if ( $len > 0 && ( $stored === false || strlen( $stored ) !== $len ) ) {
					fclose( $handle );
					return $fail( $offset, $entries, sprintf( 'ไฟล์แพ็กเกจไม่สมบูรณ์ (อ่านข้อมูลของ "%s" ไม่ครบ)', isset( $header['p'] ) ? $header['p'] : '?' ) );
				}
				$original = $stored;
				if ( $len > 0 && ! empty( $header['z'] ) ) {
					$original = @gzinflate( $stored ); // phpcs:ignore
					if ( $original === false ) {
						fclose( $handle );
						return $fail( $offset, $entries, sprintf( 'ไฟล์แพ็กเกจเสียหาย (ข้อมูลของ "%s" บีบอัดไม่ถูกต้อง)', isset( $header['p'] ) ? $header['p'] : '?' ) );
					}
				}
				if ( hash( 'crc32b', $original ) !== $header['c'] ) {
					fclose( $handle );
					return $fail( $offset, $entries, sprintf( 'ไฟล์แพ็กเกจเสียหาย (ข้อมูลของ "%s" ไม่ตรงกับค่าตรวจสอบ)', isset( $header['p'] ) ? $header['p'] : '?' ) );
				}
				$content_end = ftell( $handle ); // Already consumed.
			} else {
				// Seek past the content rather than read it — verification is about
				// the bytes being *there*, and reading 6GB to prove that would take
				// as long as the restore itself.
				fseek( $handle, $content_end, SEEK_SET );
			}

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
	private static function write_header( $out, $name, $size, $mtime, $compressed = false, $original_size = null, $crc = '' ) {
		$data = array(
			'p' => self::sanitize_relative( $name ),
			's' => (int) $size,
			'm' => (int) $mtime,
		);
		if ( $compressed ) {
			$data['z'] = 1;
			$data['u'] = (int) $original_size;
		}
		if ( $crc !== '' ) {
			$data['c'] = $crc;
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
