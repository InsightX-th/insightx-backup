<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Streamed gzip compression for the finished .wpress package ("Compression
 * Options" on the export screen). Uses the standard gz* stream API so the
 * whole file never has to sit in memory, and the result is a normal .gz
 * container (not a custom format).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Compress {

	const CHUNK_SIZE = 1048576;
	const MAGIC       = "\x1f\x8b"; // gzip magic bytes.

	/**
	 * @param string $src
	 * @param string $dest
	 * @param int    $level 1 (fastest) – 9 (smallest).
	 * @return true|WP_Error
	 */
	public static function gzip_file( $src, $dest, $level = 6 ) {
		if ( ! function_exists( 'gzopen' ) ) {
			return new WP_Error( 'isx_compress_ext', __( 'The PHP zlib extension is required to compress files', 'insightx-backup' ) );
		}
		$in = fopen( $src, 'rb' );
		if ( $in === false ) {
			return new WP_Error( 'isx_compress_open', __( 'Could not open the source file', 'insightx-backup' ) );
		}
		$out = gzopen( $dest, 'wb' . max( 1, min( 9, (int) $level ) ) );
		if ( $out === false ) {
			fclose( $in );
			return new WP_Error( 'isx_compress_open', __( 'Could not open the destination file', 'insightx-backup' ) );
		}

		while ( ! feof( $in ) ) {
			$chunk = fread( $in, self::CHUNK_SIZE );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}
			gzwrite( $out, $chunk );
		}

		fclose( $in );
		gzclose( $out );
		return true;
	}

	/**
	 * @param string $src
	 * @param string $dest
	 * @return true|WP_Error
	 */
	public static function gunzip_file( $src, $dest ) {
		if ( ! function_exists( 'gzopen' ) ) {
			return new WP_Error( 'isx_compress_ext', __( 'The PHP zlib extension is required to decompress files', 'insightx-backup' ) );
		}
		$in = gzopen( $src, 'rb' );
		if ( $in === false ) {
			return new WP_Error( 'isx_compress_open', __( 'Could not open the source file', 'insightx-backup' ) );
		}
		$out = fopen( $dest, 'wb' );
		if ( $out === false ) {
			gzclose( $in );
			return new WP_Error( 'isx_compress_open', __( 'Could not open the destination file', 'insightx-backup' ) );
		}

		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, self::CHUNK_SIZE );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}
			fwrite( $out, $chunk );
		}

		gzclose( $in );
		fclose( $out );
		return true;
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public static function is_gzip_file( $path ) {
		if ( ! is_file( $path ) ) {
			return false;
		}
		$handle = fopen( $path, 'rb' );
		if ( $handle === false ) {
			return false;
		}
		$magic = fread( $handle, 2 );
		fclose( $handle );
		return $magic === self::MAGIC;
	}
}
