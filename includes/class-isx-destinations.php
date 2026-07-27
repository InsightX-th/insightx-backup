<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * S3-compatible export/import destinations. Stores per-provider credentials
 * (endpoint / region / bucket / keys) encrypted at rest.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Destinations {

	const OPTION_KEY = 'isx_destinations';
	const ENC_PREFIX = 'ISXENC1:';

	/**
	 * Folder every backup is written under when a destination doesn't name its
	 * own. Also what installs from before the folder field existed are using,
	 * so it has to stay the fallback — changing it would orphan their backups.
	 */
	const DEFAULT_PREFIX = 'insightx-migrate';

	/**
	 * Supported providers: slug => meta. All are S3-compatible; the defaults
	 * just seed sensible path-style / endpoint hints per provider.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'amazon_s3'           => array(
				'label'           => __( 'Amazon S3', 'insightx-backup' ),
				'path_style'      => false,
				'endpoint_locked' => true,
				'endpoint_hint'   => 'เว้นว่างไว้ (ใช้ s3.<region>.amazonaws.com อัตโนมัติ)',
				'placeholders'    => array(
					'endpoint'   => 'ไม่ต้องกรอก (คำนวณจาก Region)',
					'region'     => 'us-east-1',
					'bucket'     => 'my-bucket',
					'access_key' => 'Access Key',
				),
			),
			'minio'               => array(
				'label'         => __( 'Minio', 'insightx-backup' ),
				'path_style'    => true,
				'endpoint_hint' => 'เช่น https://minio.example.com:9000',
				'placeholders'  => array(
					'endpoint'   => 'https://minio.example.com:9000',
					'region'     => 'us-east-1',
					'bucket'     => 'my-bucket',
					'access_key' => 'Access Key',
				),
			),
			'garage'              => array(
				'label'         => __( 'Garage', 'insightx-backup' ),
				'path_style'    => true,
				'endpoint_hint' => 'เช่น https://garage.example.com',
				'placeholders'  => array(
					'endpoint'   => 'https://garage.example.com',
					'region'     => 'garage',
					'bucket'     => 'my-bucket',
					'access_key' => 'Access Key',
				),
			),
			'cloudflare_r2'       => array(
				'label'         => __( 'Cloudflare R2', 'insightx-backup' ),
				'path_style'    => true,
				'endpoint_hint' => 'เช่น https://<accountid>.r2.cloudflarestorage.com',
				'placeholders'  => array(
					'endpoint'   => 'https://<accountid>.r2.cloudflarestorage.com',
					'region'     => 'auto',
					'bucket'     => 'my-bucket',
					'access_key' => 'Access Key',
				),
			),
			'digitalocean_spaces' => array(
				'label'         => __( 'DigitalOcean Spaces', 'insightx-backup' ),
				'path_style'    => false,
				'endpoint_hint' => 'เช่น https://sgp1.digitaloceanspaces.com',
				'placeholders'  => array(
					'endpoint'   => 'https://sgp1.digitaloceanspaces.com',
					'region'     => 'sgp1',
					'bucket'     => 'my-space',
					'access_key' => 'Access Key',
				),
			),
			'gcs'                 => array(
				'label'         => __( 'Google Cloud Storage', 'insightx-backup' ),
				'path_style'    => false,
				'endpoint_hint' => 'https://storage.googleapis.com',
				'placeholders'  => array(
					'endpoint'   => 'https://storage.googleapis.com',
					'region'     => 'auto',
					'bucket'     => 'my-bucket',
					'access_key' => 'Access Key',
				),
			),
			'other'               => array(
				'label'         => __( 'Other (S3-compatible)', 'insightx-backup' ),
				'path_style'    => true,
				'endpoint_hint' => 'S3 endpoint แบบเต็ม',
				'placeholders'  => array(
					'endpoint'   => 'https://s3.example.com',
					'region'     => 'us-east-1',
					'bucket'     => 'my-bucket',
					'access_key' => 'Access Key',
				),
			),
		);
	}

	/**
	 * Inline brand-mark SVG for a provider.
	 *
	 * @param string $slug
	 * @return string HTML
	 */
	public static function icon( $slug ) {
		switch ( $slug ) {
			case 'amazon_s3':
				return '<svg viewBox="0 0 512 512" xmlns:xlink="http://www.w3.org/1999/xlink"><path fill="#e05243" d="M260 348l-137 33V131l137 32z"/><path fill="#8c3123" d="M256 349l133 32V131l-133 32v186"/><g fill="#e05243"><path id="isx-s3-a" d="M256 64v97l58 14V93zm133 67v250l26-13V143zm-133 77v97l58-8v-82zm58 129l-58 14v97l58-29z"/></g><use fill="#8c3123" transform="rotate(180 256 256)" xlink:href="#isx-s3-a"/><path fill="#5e1f18" d="M314 175l-58 11-58-11 58-15 58 15"/><path fill="#f2b0a9" d="M314 337l-58-11-58 11 58 16 58-16"/></svg>';
			case 'minio':
				return '<svg viewBox="0 0 24 24"><path fill="#C6234A" d="M12 2 21 7v10l-9 5-9-5V7l9-5Z"/><path fill="#E4344F" d="M12 2 21 7l-9 5-9-5 9-5Z"/></svg>';
			case 'garage':
				return '<svg viewBox="0 0 24 24"><path fill="#FF9329" d="M12 3 3 7.5v9L12 21l9-4.5v-9L12 3Z"/><path fill="#45C8FF" d="M12 3 3 7.5 12 12l9-4.5L12 3Z"/><circle cx="12" cy="14" r="3" fill="#4E4E4E"/></svg>';
			case 'cloudflare_r2':
				return '<svg viewBox="0 0 24 24"><path fill="#F38020" d="M17.5 10.1a5.5 5.5 0 0 0-10.5-1.5A4 4 0 0 0 4 12.4 4 4 0 0 0 8 16.4h9.3a3.3 3.3 0 0 0 .2-6.3Z"/></svg>';
			case 'digitalocean_spaces':
				return '<svg viewBox="0 0 24 24"><path fill="#0080FF" d="M12 2a10 10 0 1 0 0 20v-4a6 6 0 1 1 0-12V2Z"/></svg>';
			case 'gcs':
				return '<svg viewBox="0 0 24 24"><path fill="#4285F4" d="M15.7 9.2a5.5 5.5 0 0 0-10.4 1.9A4 4 0 0 0 6 19h9.3a4.3 4.3 0 0 0 .4-9.8Z"/><circle cx="6.2" cy="20.6" r="1.1" fill="#EA4335"/><circle cx="9.4" cy="20.6" r="1.1" fill="#FBBC05"/><circle cx="12.6" cy="20.6" r="1.1" fill="#34A853"/></svg>';
			case 'other':
			default:
				return '<svg viewBox="0 0 24 24"><circle cx="6" cy="12" r="1.6" fill="#64748b"/><circle cx="12" cy="12" r="1.6" fill="#64748b"/><circle cx="18" cy="12" r="1.6" fill="#64748b"/></svg>';
		}
	}

	/**
	 * All stored destinations (secret keys decrypted).
	 *
	 * @return array slug => config
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$out = array();
		foreach ( self::providers() as $slug => $meta ) {
			$config       = isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) ? $stored[ $slug ] : array();
			$out[ $slug ] = array(
				'endpoint'   => isset( $config['endpoint'] ) ? $config['endpoint'] : '',
				'region'     => isset( $config['region'] ) ? $config['region'] : '',
				'bucket'     => isset( $config['bucket'] ) ? $config['bucket'] : '',
				// Empty means "use DEFAULT_PREFIX" — stored as typed so the
				// settings field can show blank rather than pre-filling a value
				// the user never chose.
				'prefix'     => isset( $config['prefix'] ) ? $config['prefix'] : '',
				'access_key' => isset( $config['access_key'] ) ? $config['access_key'] : '',
				'secret_key' => self::maybe_decrypt( isset( $config['secret_key'] ) ? $config['secret_key'] : '' ),
				'path_style' => isset( $config['path_style'] ) ? (bool) $config['path_style'] : $meta['path_style'],
			);
		}
		return $out;
	}

	/**
	 * @param string $slug
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Object-key prefix for a destination, always with a trailing slash so it
	 * concatenates straight onto a filename.
	 *
	 * @param string $slug
	 * @return string e.g. "insightx-migrate/" or "backups/production/"
	 */
	public static function prefix( $slug ) {
		$config = self::get( $slug );
		$prefix = $config && isset( $config['prefix'] ) ? self::sanitize_prefix( $config['prefix'] ) : '';

		return ( $prefix === '' ? self::DEFAULT_PREFIX : $prefix ) . '/';
	}

	/**
	 * Reduce a typed folder name to something safe to paste into an S3 key.
	 *
	 * Nested folders are allowed ("backups/production"), but each segment is
	 * stripped to characters S3 handles without escaping, and "." / ".." are
	 * dropped — a key is not a filesystem path, so traversal segments would
	 * just create bizarrely-named folders rather than escape anywhere, but they
	 * also break the prefix match that finds backups again later.
	 *
	 * @param string $value
	 * @return string No leading or trailing slash.
	 */
	public static function sanitize_prefix( $value ) {
		$value    = str_replace( '\\', '/', trim( (string) $value ) );
		$segments = array();

		foreach ( explode( '/', $value ) as $segment ) {
			$segment = preg_replace( '/[^A-Za-z0-9._-]/', '', $segment );
			// Drop empties and any all-dots segment — "." and ".." plus the
			// "..." variants, none of which name a real folder.
			if ( $segment === '' || trim( $segment, '.' ) === '' ) {
				continue;
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * @param string $slug
	 * @return bool
	 */
	public static function is_configured( $slug ) {
		$config = self::get( $slug );
		return $config && $config['bucket'] !== '' && $config['access_key'] !== '' && $config['secret_key'] !== '';
	}

	/**
	 * Persist all destinations. Expects raw (decrypted) secret keys.
	 *
	 * @param array $destinations slug => config
	 * @return void
	 */
	public static function save( array $destinations ) {
		$clean = array();
		foreach ( self::providers() as $slug => $meta ) {
			$config         = isset( $destinations[ $slug ] ) && is_array( $destinations[ $slug ] ) ? $destinations[ $slug ] : array();
			$clean[ $slug ] = array(
				'endpoint'   => isset( $config['endpoint'] ) ? esc_url_raw( trim( $config['endpoint'] ) ) : '',
				'region'     => isset( $config['region'] ) ? sanitize_text_field( $config['region'] ) : '',
				'bucket'     => isset( $config['bucket'] ) ? sanitize_text_field( $config['bucket'] ) : '',
				'prefix'     => isset( $config['prefix'] ) ? self::sanitize_prefix( $config['prefix'] ) : '',
				'access_key' => isset( $config['access_key'] ) ? sanitize_text_field( $config['access_key'] ) : '',
				'secret_key' => self::maybe_encrypt( isset( $config['secret_key'] ) ? trim( $config['secret_key'] ) : '' ),
				'path_style' => ! empty( $config['path_style'] ),
			);
		}
		update_option( self::OPTION_KEY, $clean );
	}

	/**
	 * Data for JS localize. Never exposes the real secret.
	 *
	 * @return array
	 */
	public static function js_data() {
		$all  = self::all();
		$data = array();
		foreach ( self::providers() as $slug => $meta ) {
			$config        = isset( $all[ $slug ] ) ? $all[ $slug ] : array();
			$data[ $slug ] = array(
				'label'         => $meta['label'],
				'endpoint_hint' => $meta['endpoint_hint'],
				'placeholders'  => isset( $meta['placeholders'] ) ? $meta['placeholders'] : array(),
				'endpoint'      => isset( $config['endpoint'] ) ? $config['endpoint'] : '',
				'region'        => isset( $config['region'] ) ? $config['region'] : 'us-east-1',
				'bucket'        => isset( $config['bucket'] ) ? $config['bucket'] : '',
				'prefix'        => isset( $config['prefix'] ) ? $config['prefix'] : '',
				'prefix_default' => self::DEFAULT_PREFIX,
				'access_key'    => isset( $config['access_key'] ) ? $config['access_key'] : '',
				'path_style'    => ! empty( $config['path_style'] ),
				'has_secret'    => ! empty( $config['secret_key'] ),
				'configured'    => self::is_configured( $slug ),
			);
		}
		return $data;
	}

	private static function crypto_key() {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	private static function maybe_encrypt( $value ) {
		if ( $value === '' ) {
			return '';
		}
		if ( strpos( $value, self::ENC_PREFIX ) === 0 ) {
			return $value;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $value, 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, $iv );
		if ( $ciphertext === false ) {
			return $value;
		}
		return self::ENC_PREFIX . base64_encode( $iv . $ciphertext );
	}

	private static function maybe_decrypt( $value ) {
		if ( $value === '' || strpos( $value, self::ENC_PREFIX ) !== 0 ) {
			return $value;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $value, strlen( self::ENC_PREFIX ) ), true );
		if ( $raw === false || strlen( $raw ) <= 16 ) {
			return '';
		}
		$iv         = substr( $raw, 0, 16 );
		$ciphertext = substr( $raw, 16 );
		$plain      = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, $iv );
		return $plain === false ? '' : $plain;
	}
}
