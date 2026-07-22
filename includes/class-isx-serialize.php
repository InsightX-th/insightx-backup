<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Serialized-safe search & replace.
 *
 * WordPress stores arrays/objects as PHP-serialized strings whose format embeds
 * the byte length of every string (e.g. s:11:"hello world";). A naive str_replace
 * on such data corrupts it because the recorded length no longer matches. This
 * class walks the actual value, replacing inside strings and re-serializing so the
 * lengths are always recomputed correctly. It recurses through nested arrays,
 * objects and doubly-serialized strings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Serialize {

	/**
	 * Recursively replace $search with $replace inside $data, keeping any
	 * PHP-serialized structures valid.
	 *
	 * @param mixed        $data
	 * @param string|array $search
	 * @param string|array $replace
	 * @param bool         $skip_emails Best-effort: don't touch $search occurrences
	 *                                  that sit inside the domain part of an email
	 *                                  address (used for the "อย่าแทนที่โดเมนอีเมล" option).
	 * @return mixed
	 */
	public static function replace( $data, $search, $replace, $skip_emails = false ) {
		// A serialized string: unserialize, replace inside, re-serialize.
		if ( is_string( $data ) && $data !== '' && is_serialized( $data ) ) {
			$unserialized = @unserialize( $data ); // phpcs:ignore
			if ( $unserialized !== false || $data === 'b:0;' ) {
				return serialize( self::replace( $unserialized, $search, $replace, $skip_emails ) );
			}
		}

		if ( is_array( $data ) ) {
			$result = array();
			foreach ( $data as $key => $value ) {
				$new_key            = is_string( $key ) ? self::replace_plain( $key, $search, $replace, $skip_emails ) : $key;
				$result[ $new_key ] = self::replace( $value, $search, $replace, $skip_emails );
			}
			return $result;
		}

		if ( is_object( $data ) ) {
			// __PHP_Incomplete_Class means the class isn't loaded here — leave it
			// untouched to avoid breaking it further.
			if ( $data instanceof __PHP_Incomplete_Class ) {
				return $data;
			}
			$clone = clone $data;
			foreach ( get_object_vars( $clone ) as $key => $value ) {
				$clone->{$key} = self::replace( $value, $search, $replace, $skip_emails );
			}
			return $clone;
		}

		if ( is_string( $data ) ) {
			return self::replace_plain( $data, $search, $replace, $skip_emails );
		}

		// int / float / bool / null — nothing to replace.
		return $data;
	}

	/**
	 * Plain string replacement (no serialization concerns).
	 *
	 * @param string       $data
	 * @param string|array $search
	 * @param string|array $replace
	 * @param bool         $skip_emails
	 * @return string
	 */
	private static function replace_plain( $data, $search, $replace, $skip_emails = false ) {
		if ( ! $skip_emails ) {
			return str_replace( $search, $replace, $data );
		}

		$searches = (array) $search;
		$replaces = (array) $replace;
		foreach ( $searches as $i => $from ) {
			if ( $from === '' ) {
				continue;
			}
			$to   = isset( $replaces[ $i ] ) ? $replaces[ $i ] : '';
			$data = self::replace_skipping_email_domain( $from, $to, $data );
		}
		return $data;
	}

	/**
	 * str_replace($from, $to, $subject) that leaves any email address whose
	 * domain part contains $from untouched. Best-effort: protects matched
	 * addresses with a placeholder token before the plain replace, then restores
	 * them.
	 */
	private static function replace_skipping_email_domain( $from, $to, $subject ) {
		$pattern   = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]*' . preg_quote( $from, '/' ) . '[a-zA-Z0-9.-]*/';
		$protected = array();

		$subject = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( &$protected ) {
				$token               = "\0ISX_EMAIL_" . count( $protected ) . "\0";
				$protected[ $token ] = $matches[0];
				return $token;
			},
			$subject
		);

		$subject = str_replace( $from, $to, $subject );

		if ( ! empty( $protected ) ) {
			$subject = strtr( $subject, $protected );
		}
		return $subject;
	}
}
