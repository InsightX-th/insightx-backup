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
	 * Uses strtr() rather than str_replace(), and the difference is not
	 * cosmetic. str_replace() with arrays applies each pair to the whole
	 * subject in turn, so a later pair can match text an earlier pair just
	 * wrote — and the URL pairs this is fed absolutely can do that whenever
	 * the destination URL contains the source URL:
	 *
	 *   old https://a.com  ->  new https://a.com.staging.net
	 *   ".../page" came out as "https://a.com.staging.net.staging.net/page"
	 *
	 * because the protocol-relative pair "//a.com" found "//a.com.staging.net"
	 * in the freshly written result and appended the suffix a second time.
	 * Moving a site into a subdirectory (example.com -> example.com/blog) has
	 * exactly the same shape. strtr() with an array walks the subject once,
	 * takes the longest key that matches at each position, and never
	 * re-examines what it has already written.
	 *
	 * @param string       $data
	 * @param string|array $search
	 * @param string|array $replace
	 * @param bool         $skip_emails
	 * @return string
	 */
	private static function replace_plain( $data, $search, $replace, $skip_emails = false ) {
		$map = self::build_map( $search, $replace );
		if ( empty( $map ) ) {
			return $data;
		}

		$data = $skip_emails
			? self::replace_skipping_email_domains( $data, $map )
			: strtr( $data, $map );

		return self::replace_in_base64( $data, $map );
	}

	/**
	 * Page builders that keep chunks of a page as base64 inside the value, so a
	 * URL sitting in there is invisible to any amount of plain replacement.
	 *
	 * Only builders present in the package are considered — see
	 * ISX_Import::active_base64_builders(), which sets this. Left empty (the
	 * default) nothing here does anything at all, which is what should happen
	 * on the overwhelming majority of sites.
	 *
	 * @var array
	 */
	private static $base64_builders = array();

	/**
	 * Declare which base64-bearing builders the site being imported actually
	 * uses. Anything not named here is left alone.
	 *
	 * @param array $builders Subset of: vc, oxygen, whole_value.
	 * @return void
	 */
	public static function set_base64_builders( array $builders ) {
		self::$base64_builders = $builders;
	}

	/**
	 * Rewrite URLs that live inside base64 payloads.
	 *
	 * Every one of these decodes, replaces, and re-encodes — so each is a
	 * chance to corrupt a value that only looked like base64. Three things keep
	 * that from happening: the builder has to be installed at all, the payload
	 * has to survive a strict encode(decode(x)) === x round trip, and the
	 * decoded text has to actually contain something being replaced. A payload
	 * that fails any of them is returned byte-for-byte untouched, never
	 * re-encoded "just in case".
	 *
	 * @param string $data
	 * @param array  $map Search => replace.
	 * @return string
	 */
	private static function replace_in_base64( $data, array $map ) {
		if ( empty( self::$base64_builders ) || $data === '' ) {
			return $data;
		}

		// WPBakery / Visual Composer: [vc_raw_html]<base64>[/vc_raw_html]
		if ( in_array( 'vc', self::$base64_builders, true ) && strpos( $data, '[vc_raw_html]' ) !== false ) {
			$data = preg_replace_callback(
				'/\[vc_raw_html\]([a-zA-Z0-9\/+]+={0,2})\[\/vc_raw_html\]/S',
				function ( $m ) use ( $map ) {
					return '[vc_raw_html]' . self::recode_base64( $m[1], $map ) . '[/vc_raw_html]';
				},
				$data
			);
		}

		// Oxygen Builder: "code-php":"<base64>" inside its JSON. All-in-One WP
		// Migration matches \\" here because it works on raw SQL text where the
		// JSON quotes are escaped a second time; this runs on the parsed value,
		// where they are not.
		if ( in_array( 'oxygen', self::$base64_builders, true ) && strpos( $data, 'code-' ) !== false ) {
			$data = preg_replace_callback(
				'/"(code-php|code-css|code-js)":"([a-zA-Z0-9\/+]+={0,2})"/S',
				function ( $m ) use ( $map ) {
					return '"' . $m[1] . '":"' . self::recode_base64( $m[2], $map ) . '"';
				},
				$data
			);
		}

		// BeTheme / OptimizePress / Avada Fusion Builder store a whole option or
		// meta value as one base64 blob. All-in-One WP Migration hunts for
		// anything quoted that looks like base64, which on raw SQL means "a
		// string value" — here the equivalent test is simply whether the entire
		// value is base64, which is both narrower and exact.
		if ( in_array( 'whole_value', self::$base64_builders, true ) && preg_match( '/^[a-zA-Z0-9\/+]+={0,2}$/', $data ) ) {
			$data = self::recode_base64( $data, $map );
		}

		return $data;
	}

	/**
	 * Decode a base64 payload, replace inside it, and re-encode — or hand back
	 * exactly what came in if it isn't really base64 or holds nothing to change.
	 *
	 * @param string $payload
	 * @param array  $map
	 * @return string
	 */
	private static function recode_base64( $payload, array $map ) {
		$decoded = base64_decode( $payload, true ); // phpcs:ignore
		// Strict decode plus a round trip: a value can decode "successfully"
		// and still not be the bytes it came from.
		if ( $decoded === false || base64_encode( $decoded ) !== $payload ) {
			return $payload;
		}

		$replaced = strtr( $decoded, $map );
		if ( $replaced === $decoded ) {
			return $payload; // Nothing matched — hand back the original bytes.
		}

		return base64_encode( $replaced );
	}

	/**
	 * Pair up the search/replace lists into the map strtr() wants, dropping
	 * empty keys (strtr() warns on those) and anything that would replace a
	 * string with itself.
	 *
	 * @param string|array $search
	 * @param string|array $replace
	 * @return array
	 */
	private static function build_map( $search, $replace ) {
		$searches = (array) $search;
		$replaces = (array) $replace;

		$map = array();
		foreach ( $searches as $i => $from ) {
			$from = (string) $from;
			if ( $from === '' ) {
				continue;
			}
			$to = isset( $replaces[ $i ] ) ? (string) $replaces[ $i ] : '';
			if ( $from === $to ) {
				continue;
			}
			$map[ $from ] = $to;
		}
		return $map;
	}

	/**
	 * strtr() over the whole map, leaving any email address whose domain part
	 * contains one of the search strings untouched.
	 *
	 * Best-effort: every matching address is swapped for a placeholder before
	 * the replacement runs and put back afterwards. Protecting them all in one
	 * pass matters for the same reason replace_plain() uses strtr() — doing it
	 * per pair meant each pass could act on what the previous one produced.
	 *
	 * @param string $subject
	 * @param array  $map Search => replace.
	 * @return string
	 */
	private static function replace_skipping_email_domains( $subject, array $map ) {
		$alternatives = array();
		foreach ( array_keys( $map ) as $from ) {
			$alternatives[] = preg_quote( $from, '/' );
		}

		$pattern   = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]*(?:' . implode( '|', $alternatives ) . ')[a-zA-Z0-9.-]*/';
		$protected = array();

		$masked = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( &$protected ) {
				$token               = "\0ISX_EMAIL_" . count( $protected ) . "\0";
				$protected[ $token ] = $matches[0];
				return $token;
			},
			$subject
		);

		// null means PCRE gave up (backtrack limit, a pathological subject).
		// Fall back to replacing the untouched original: losing the email
		// protection is a far smaller problem than returning nothing.
		if ( $masked === null ) {
			return strtr( $subject, $map );
		}

		$masked = strtr( $masked, $map );

		if ( ! empty( $protected ) ) {
			$masked = strtr( $masked, $protected );
		}
		return $masked;
	}
}
