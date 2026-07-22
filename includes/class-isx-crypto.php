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
	const CHUNK_SIZE      = 1048576;

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
	 * Password-protect a file, streamed. Container layout:
	 *   [8 bytes]  magic "ISXENC01"
	 *   [16 bytes] PBKDF2 salt
	 *   repeated:  [16 bytes IV][4 bytes ciphertext length][ciphertext]
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
		$out = fopen( $dest_path, 'wb' );
		if ( $out === false ) {
			fclose( $in );
			return new WP_Error( 'isx_crypto_open', __( 'เปิดไฟล์ปลายทางไม่สำเร็จ', 'insightx-backup' ) );
		}

		$salt = random_bytes( 16 );
		$key  = self::derive_key( $password, $salt );

		fwrite( $out, self::FILE_MAGIC );
		fwrite( $out, $salt );

		while ( ! feof( $in ) ) {
			$chunk = fread( $in, self::CHUNK_SIZE );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}
			$iv         = random_bytes( 16 );
			$ciphertext = openssl_encrypt( $chunk, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			fwrite( $out, $iv );
			fwrite( $out, pack( 'N', strlen( $ciphertext ) ) );
			fwrite( $out, $ciphertext );
		}

		fclose( $in );
		fclose( $out );
		return true;
	}

	/**
	 * Reverse of encrypt_file(). Returns WP_Error on a wrong password / corrupt
	 * container (detected via padding validation failing mid-stream).
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

		$magic = fread( $in, strlen( self::FILE_MAGIC ) );
		if ( $magic !== self::FILE_MAGIC ) {
			fclose( $in );
			return new WP_Error( 'isx_crypto_magic', __( 'ไฟล์นี้ไม่ได้เข้ารหัสด้วย InsightX Backup', 'insightx-backup' ) );
		}
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
			fwrite( $out, $plain );
		}

		fclose( $in );
		fclose( $out );

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
		$magic = fread( $handle, strlen( self::FILE_MAGIC ) );
		fclose( $handle );
		return $magic === self::FILE_MAGIC;
	}

	private static function derive_key( $password, $salt ) {
		return hash_pbkdf2( 'sha256', $password, $salt, 100000, 32, true );
	}
}
