<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Two unrelated crypto needs share this file:
 *  - encrypt_string()/decrypt_string(): wp_salt()-derived AES for values that
 *    must sit at rest briefly in job state (e.g. a backup password carried
 *    across the many polling requests between "start export" and "finalize").
 *  - encrypt_file()/decrypt_file(): a user-supplied password protects the
 *    finished .wpress package itself, streamed in fixed-size chunks so
 *    multi-gigabyte archives never have to fit in memory.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Crypto {

	const STRING_PREFIX = 'ISXENC1:';
	const FILE_MAGIC     = 'ISXENC01';
	// Authenticated container (AES-256-CBC + HMAC-SHA256). New encryptions use
	// this; decrypt_file() still reads FILE_MAGIC files so older backups keep
	// working.
	const FILE_MAGIC_V2 = 'ISXENC02';
	const CHUNK_SIZE     = 1048576;

	private static function site_key() {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	public static function encrypt_string( $value ) {
		if ( $value === '' ) {
			return '';
		}
		if ( strpos( $value, self::STRING_PREFIX ) === 0 ) {
			return $value;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $value, 'aes-256-cbc', self::site_key(), OPENSSL_RAW_DATA, $iv );
		if ( $ciphertext === false ) {
			return $value;
		}
		return self::STRING_PREFIX . base64_encode( $iv . $ciphertext );
	}

	public static function decrypt_string( $value ) {
		if ( $value === '' || strpos( $value, self::STRING_PREFIX ) !== 0 ) {
			return $value;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $value, strlen( self::STRING_PREFIX ) ), true );
		if ( $raw === false || strlen( $raw ) <= 16 ) {
			return '';
		}
		$iv         = substr( $raw, 0, 16 );
		$ciphertext = substr( $raw, 16 );
		$plain      = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::site_key(), OPENSSL_RAW_DATA, $iv );
		return $plain === false ? '' : $plain;
	}

	/**
	 * Password-protect a file, streamed. Container layout (v2, authenticated):
	 *   [8 bytes]  magic "ISXENC02"
	 *   [16 bytes] PBKDF2 salt
	 *   repeated:  [16 bytes IV][4 bytes ciphertext length][ciphertext]
	 *   [32 bytes] HMAC-SHA256 over salt + every (IV, length, ciphertext) group
	 *
	 * The trailing HMAC makes the container authenticated encryption: an
	 * attacker who can modify the file can no longer produce a ciphertext that
	 * decrypts to a valid-looking archive (the old padding check alone caught
	 * only some tampering). The MAC key is derived from the same password,
	 * split out of one PBKDF2 output, so no key material is reused between
	 * AES and HMAC.
	 *
	 * @param string $password
	 * @param string $src_path
	 * @param string $dest_path
	 * @return true|WP_Error
	 */
	public static function encrypt_file( $password, $src_path, $dest_path ) {
		$in = fopen( $src_path, 'rb' );
		if ( $in === false ) {
			return new WP_Error( 'isx_crypto_open', __( 'เปิดไฟล์ต้นทางไม่สำเร็จ', 'insightx-backup' ) );
		}
		$out = @fopen( $dest_path, 'wb' );
		if ( $out === false ) {
			fclose( $in );
			return new WP_Error( 'isx_crypto_open', __( 'เปิดไฟล์ปลายทางไม่สำเร็จ (พื้นที่ดิสก์อาจเต็ม)', 'insightx-backup' ) );
		}

		$salt         = random_bytes( 16 );
		$key_material = self::derive_keys( $password, $salt );
		$enc_key      = substr( $key_material, 0, 32 );
		$mac_key      = substr( $key_material, 32, 32 );

		// Every write is checked: a truncated ciphertext is not recoverable by
		// any amount of trying later, and an unchecked fwrite() here would have
		// produced one silently the moment the disk filled up.
		$ok  = self::write_ok( $out, self::FILE_MAGIC_V2 ) && self::write_ok( $out, $salt );
		$mac = hash_init( 'sha256', HASH_HMAC, $mac_key );
		if ( $ok ) {
			hash_update( $mac, $salt );
		}

		while ( $ok && ! feof( $in ) ) {
			$chunk = fread( $in, self::CHUNK_SIZE );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}
			$iv         = random_bytes( 16 );
			$ciphertext = openssl_encrypt( $chunk, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
			if ( $ciphertext === false ) {
				$ok = false;
				break;
			}
			$group = $iv . pack( 'N', strlen( $ciphertext ) ) . $ciphertext;
			hash_update( $mac, $group );
			$ok = self::write_ok( $out, $group );
		}

		if ( $ok ) {
			$ok = self::write_ok( $out, hash_final( $mac, true ) );
		}

		fclose( $in );
		if ( ! fclose( $out ) ) {
			$ok = false;
		}

		if ( ! $ok ) {
			@unlink( $dest_path );
			return new WP_Error( 'isx_crypto_write', __( 'เขียนไฟล์ที่เข้ารหัสไม่สำเร็จ — พื้นที่ดิสก์ของเซิร์ฟเวอร์อาจเต็ม', 'insightx-backup' ) );
		}
		return true;
	}

	/**
	 * fwrite() that reports a short write for what it is. PHP returns the byte
	 * count rather than throwing when a disk fills, so an unchecked write is
	 * how a half-written container ends up looking like a finished one.
	 *
	 * @param resource $handle
	 * @param string   $data
	 * @return bool
	 */
	private static function write_ok( $handle, $data ) {
		// Silenced for the same reason as ISX_Archive::write_ok(): the return
		// value is handled, and a stray PHP notice ahead of an admin-ajax JSON
		// body turns a clear error message into an unparseable response.
		return @fwrite( $handle, $data ) === strlen( $data );
	}

	/**
	 * Reverse of encrypt_file(). Returns WP_Error on a wrong password / corrupt
	 * container. Detects the container version by magic and dispatches: v1
	 * (legacy, padding check only) keeps working untouched, v2 additionally
	 * verifies the trailing HMAC before the plaintext is trusted.
	 *
	 * @param string $password
	 * @param string $src_path
	 * @param string $dest_path
	 * @return true|WP_Error
	 */
	public static function decrypt_file( $password, $src_path, $dest_path ) {
		$in = fopen( $src_path, 'rb' );
		if ( $in === false ) {
			return new WP_Error( 'isx_crypto_open', __( 'เปิดไฟล์ต้นทางไม่สำเร็จ', 'insightx-backup' ) );
		}
		$magic = fread( $in, strlen( self::FILE_MAGIC_V2 ) );
		fclose( $in );

		if ( $magic === self::FILE_MAGIC_V2 ) {
			return self::decrypt_file_v2( $password, $src_path, $dest_path );
		}
		if ( $magic !== self::FILE_MAGIC ) {
			return new WP_Error( 'isx_crypto_magic', __( 'ไฟล์นี้ไม่ได้เข้ารหัสด้วย InsightX Backup', 'insightx-backup' ) );
		}
		return self::decrypt_file_v1( $password, $src_path, $dest_path );
	}

	/**
	 * Legacy (pre-HMAC) container reader — kept so backups made by older
	 * versions of this plugin still decrypt.
	 */
	private static function decrypt_file_v1( $password, $src_path, $dest_path ) {
		$in = fopen( $src_path, 'rb' );
		if ( $in === false ) {
			return new WP_Error( 'isx_crypto_open', __( 'เปิดไฟล์ต้นทางไม่สำเร็จ', 'insightx-backup' ) );
		}

		fread( $in, strlen( self::FILE_MAGIC ) ); // Magic already matched.
		$salt = fread( $in, 16 );
		if ( strlen( $salt ) < 16 ) {
			fclose( $in );
			return new WP_Error( 'isx_crypto_corrupt', __( 'ไฟล์เสียหาย', 'insightx-backup' ) );
		}
		$key = self::derive_key( $password, $salt );

		$out = fopen( $dest_path, 'wb' );
		if ( $out === false ) {
			fclose( $in );
			return new WP_Error( 'isx_crypto_open', __( 'เปิดไฟล์ปลายทางไม่สำเร็จ', 'insightx-backup' ) );
		}

		while ( ! feof( $in ) ) {
			$iv = fread( $in, 16 );
			if ( $iv === false || strlen( $iv ) < 16 ) {
				break;
			}
			$len_raw = fread( $in, 4 );
			if ( $len_raw === false || strlen( $len_raw ) < 4 ) {
				break;
			}
			$len        = unpack( 'N', $len_raw )[1];
			$ciphertext = $len > 0 ? fread( $in, $len ) : '';
			$plain      = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			if ( $plain === false ) {
				fclose( $in );
				fclose( $out );
				@unlink( $dest_path );
				return new WP_Error( 'isx_crypto_password', __( 'รหัสผ่านไม่ถูกต้อง หรือไฟล์เสียหาย', 'insightx-backup' ) );
			}
			if ( ! self::write_ok( $out, $plain ) ) {
				fclose( $in );
				fclose( $out );
				@unlink( $dest_path );
				return new WP_Error( 'isx_crypto_write', __( 'เขียนไฟล์ที่ถอดรหัสไม่สำเร็จ — พื้นที่ดิสก์ของเซิร์ฟเวอร์อาจเต็ม', 'insightx-backup' ) );
			}
		}

		fclose( $in );
		if ( ! fclose( $out ) ) {
			@unlink( $dest_path );
			return new WP_Error( 'isx_crypto_write', __( 'เขียนไฟล์ที่ถอดรหัสไม่สำเร็จ — พื้นที่ดิสก์ของเซิร์ฟเวอร์อาจเต็ม', 'insightx-backup' ) );
		}

		if ( ! ISX_Archive::is_valid( $dest_path ) ) {
			@unlink( $dest_path );
			return new WP_Error( 'isx_crypto_password', __( 'รหัสผ่านไม่ถูกต้อง หรือไฟล์เสียหาย', 'insightx-backup' ) );
		}

		return true;
	}

	/**
	 * Authenticated (v2) container reader. The HMAC over the whole ciphertext
	 * is verified before the decrypted archive is accepted — a wrong password
	 * or any tampering fails here with the same generic message, so no
	 * padding-based oracle is exposed.
	 */
	private static function decrypt_file_v2( $password, $src_path, $dest_path ) {
		$size = (int) @filesize( $src_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$in   = fopen( $src_path, 'rb' );
		if ( $in === false ) {
			return new WP_Error( 'isx_crypto_open', __( 'เปิดไฟล์ต้นทางไม่สำเร็จ', 'insightx-backup' ) );
		}

		fread( $in, strlen( self::FILE_MAGIC_V2 ) ); // Magic already matched.
		$salt = fread( $in, 16 );
		if ( strlen( $salt ) < 16 ) {
			fclose( $in );
			return new WP_Error( 'isx_crypto_corrupt', __( 'ไฟล์เสียหาย', 'insightx-backup' ) );
		}
		$key_material = self::derive_keys( $password, $salt );
		$enc_key      = substr( $key_material, 0, 32 );
		$mac_key      = substr( $key_material, 32, 32 );

		$out = fopen( $dest_path, 'wb' );
		if ( $out === false ) {
			fclose( $in );
			return new WP_Error( 'isx_crypto_open', __( 'เปิดไฟล์ปลายทางไม่สำเร็จ', 'insightx-backup' ) );
		}

		$mac = hash_init( 'sha256', HASH_HMAC, $mac_key );
		hash_update( $mac, $salt );

		$pos = strlen( self::FILE_MAGIC_V2 ) + 16;
		$ok  = true;
		while ( $size - $pos > 32 ) { // The last 32 bytes are the HMAC.
			$iv = fread( $in, 16 );
			if ( $iv === false || strlen( $iv ) < 16 ) {
				$ok = false;
				break;
			}
			$len_raw = fread( $in, 4 );
			if ( $len_raw === false || strlen( $len_raw ) < 4 ) {
				$ok = false;
				break;
			}
			$len        = unpack( 'N', $len_raw )[1];
			$ciphertext = $len > 0 ? fread( $in, $len ) : '';
			if ( strlen( $ciphertext ) !== $len ) {
				$ok = false;
				break;
			}
			$pos += 16 + 4 + $len;
			hash_update( $mac, $iv . $len_raw . $ciphertext );

			$plain = openssl_decrypt( $ciphertext, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
			if ( $plain === false ) {
				$ok = false;
				break;
			}
			if ( ! self::write_ok( $out, $plain ) ) {
				$ok = false;
				break;
			}
		}

		if ( $ok ) {
			$expected = hash_final( $mac, true );
			$got      = fread( $in, 32 );
			$ok       = strlen( $got ) === 32 && hash_equals( $expected, $got );
		}

		fclose( $in );
		if ( ! fclose( $out ) ) {
			$ok = false;
		}

		if ( ! $ok ) {
			@unlink( $dest_path );
			return new WP_Error( 'isx_crypto_password', __( 'รหัสผ่านไม่ถูกต้อง หรือไฟล์เสียหาย', 'insightx-backup' ) );
		}

		if ( ! ISX_Archive::is_valid( $dest_path ) ) {
			@unlink( $dest_path );
			return new WP_Error( 'isx_crypto_password', __( 'รหัสผ่านไม่ถูกต้อง หรือไฟล์เสียหาย', 'insightx-backup' ) );
		}

		return true;
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public static function is_encrypted_file( $path ) {
		if ( ! is_file( $path ) ) {
			return false;
		}
		$handle = fopen( $path, 'rb' );
		if ( $handle === false ) {
			return false;
		}
		$magic = fread( $handle, strlen( self::FILE_MAGIC_V2 ) );
		fclose( $handle );
		return $magic === self::FILE_MAGIC || $magic === self::FILE_MAGIC_V2;
	}

	private static function derive_key( $password, $salt ) {
		return hash_pbkdf2( 'sha256', $password, $salt, 100000, 32, true );
	}

	/**
	 * v2 key material: 64 bytes from one PBKDF2 pass — first 32 the AES key,
	 * last 32 the HMAC key — so both keys come from the same expensive
	 * derivation but no key material is shared between the two algorithms.
	 *
	 * @return string 64 raw bytes.
	 */
	private static function derive_keys( $password, $salt ) {
		return hash_pbkdf2( 'sha256', $password, $salt, 100000, 64, true );
	}
}
