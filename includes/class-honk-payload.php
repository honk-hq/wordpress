<?php
/**
 * Builds the JSON body of POST /v1/messages within the contract's limits.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Message normalization: field limits (contracts/openapi.yaml), the 16 KiB body, idempotency keys.
 */
final class Honk_Payload {

	const MAX_BODY_BYTES = 16384;

	const MAX_MESSAGE_BYTES = 8192;

	const MAX_TITLE_CHARS = 160;

	const MAX_KEY_LENGTH = 128;

	const ELLIPSIS = '…';

	/**
	 * Character limits (Unicode code points) of the short string fields.
	 */
	const LIMITS = array(
		'source'      => 64,
		'environment' => 32,
		'channel'     => 64,
		'group_key'   => 128,
	);

	const EVENT_TYPES = array( 'event', 'problem', 'recovery' );

	const CATEGORIES = array( 'infrastructure', 'security', 'backups', 'deployments', 'payments', 'customers', 'sales', 'automation', 'personal', 'other' );

	/**
	 * Normalizes a message: drops unknown or empty fields, trims, truncates and makes sure the
	 * encoded body fits in 16 KiB.
	 *
	 * @param array $fields Message fields (contract names).
	 * @return array
	 */
	public static function normalize( array $fields ) {
		$out = array();

		$title = isset( $fields['title'] ) ? self::plain( $fields['title'], true ) : '';
		if ( '' !== $title ) {
			$out['title'] = self::truncate_chars( $title, self::MAX_TITLE_CHARS );
		}

		$message = isset( $fields['message'] ) ? self::plain( $fields['message'], false ) : '';
		if ( '' === $message ) {
			$message = '' !== $title ? $title : '-';
		}
		$out['message'] = self::truncate_bytes( $message, self::MAX_MESSAGE_BYTES );

		$severity = isset( $fields['severity'] ) ? strtolower( (string) $fields['severity'] ) : 'info';
		$aliases  = array(
			'light' => 'info',
			'beep'  => 'success',
			'loud'  => 'warning',
			'long'  => 'error',
			'blast' => 'critical',
		);
		if ( isset( $aliases[ $severity ] ) ) {
			$severity = $aliases[ $severity ];
		}
		$out['severity'] = in_array( $severity, Honk_Settings::SEVERITIES, true ) ? $severity : 'info';

		$priority        = isset( $fields['priority'] ) ? strtolower( (string) $fields['priority'] ) : 'normal';
		$out['priority'] = in_array( $priority, Honk_Settings::PRIORITIES, true ) ? $priority : 'normal';

		foreach ( self::LIMITS as $name => $max ) {
			if ( isset( $fields[ $name ] ) ) {
				$value = self::plain( $fields[ $name ], true );
				if ( '' !== $value ) {
					$out[ $name ] = self::truncate_chars( $value, $max, '' );
				}
			}
		}

		$event_type = isset( $fields['event_type'] ) ? (string) $fields['event_type'] : 'event';
		if ( in_array( $event_type, self::EVENT_TYPES, true ) && 'event' !== $event_type ) {
			// Problem and recovery need a group key (contract §7.1).
			if ( isset( $out['group_key'] ) ) {
				$out['event_type'] = $event_type;
			}
		}

		if ( isset( $fields['category'] ) && in_array( $fields['category'], self::CATEGORIES, true ) ) {
			$out['category'] = $fields['category'];
		}

		if ( isset( $fields['url'] ) ) {
			$url = self::https_url( $fields['url'] );
			if ( '' !== $url ) {
				$out['url'] = $url;
			}
		}

		if ( isset( $fields['occurred_at'] ) && is_int( $fields['occurred_at'] ) && $fields['occurred_at'] > 0 ) {
			$out['occurred_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $fields['occurred_at'] );
		}

		if ( isset( $fields['metadata'] ) && is_array( $fields['metadata'] ) ) {
			$meta = self::metadata( $fields['metadata'] );
			if ( ! empty( $meta ) ) {
				$out['metadata'] = $meta;
			}
		}

		return self::fit( $out );
	}

	/**
	 * Shrinks a message until its JSON body fits in 16 KiB: first the message text, then the
	 * metadata.
	 *
	 * @param array $payload Normalized payload.
	 * @return array
	 */
	public static function fit( array $payload ) {
		$size = strlen( self::encode( $payload ) );
		if ( $size <= self::MAX_BODY_BYTES ) {
			return $payload;
		}
		$over = $size - self::MAX_BODY_BYTES;
		// JSON escaping can make the text longer than its bytes, so shrink with some margin.
		$target             = max( 64, strlen( $payload['message'] ) - ( $over * 2 ) - 64 );
		$payload['message'] = self::truncate_bytes( $payload['message'], $target );
		$size               = strlen( self::encode( $payload ) );
		$length             = strlen( $payload['message'] );
		while ( $size > self::MAX_BODY_BYTES && $length > 64 ) {
			$payload['message'] = self::truncate_bytes( $payload['message'], (int) ( $length * 0.8 ) );
			$size               = strlen( self::encode( $payload ) );
			$length             = strlen( $payload['message'] );
		}
		if ( $size > self::MAX_BODY_BYTES ) {
			unset( $payload['metadata'] );
		}
		return $payload;
	}

	/**
	 * JSON body (UTF-8, unescaped slashes and Unicode: smaller, and what the server measures).
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	public static function encode( array $payload ) {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '{}' : $json;
	}

	/**
	 * Plain text: no tags, decoded entities, normalized line breaks; one line when $single_line.
	 *
	 * @param mixed $value       Value.
	 * @param bool  $single_line Collapse all whitespace to single spaces.
	 * @return string
	 */
	public static function plain( $value, $single_line ) {
		if ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_check_invalid_utf8( $text, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = str_replace( "\xc2\xa0", ' ', $text );
		if ( $single_line ) {
			$text = preg_replace( '/\s+/u', ' ', $text );
		} else {
			$text = preg_replace( '/[ \t]+/u', ' ', $text );
			$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		}
		return trim( (string) $text );
	}

	/**
	 * Truncates to at most $max code points, ending with an ellipsis when cut.
	 *
	 * @param string $text     Text.
	 * @param int    $max      Maximum length in code points.
	 * @param string $ellipsis Appended when the text is cut.
	 * @return string
	 */
	public static function truncate_chars( $text, $max, $ellipsis = self::ELLIPSIS ) {
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}
		$keep = $max - mb_strlen( $ellipsis, 'UTF-8' );
		return rtrim( mb_substr( $text, 0, max( 0, $keep ), 'UTF-8' ) ) . $ellipsis;
	}

	/**
	 * Truncates to at most $max UTF-8 bytes on a character boundary, ending with an ellipsis.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length in bytes.
	 * @return string
	 */
	public static function truncate_bytes( $text, $max ) {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$keep = max( 0, $max - strlen( self::ELLIPSIS ) );
		$cut  = substr( $text, 0, $keep );
		// Step back over an incomplete UTF-8 sequence at the end.
		$len = strlen( $cut );
		$i   = $len - 1;
		while ( $i >= 0 && 0x80 === ( ord( $cut[ $i ] ) & 0xC0 ) ) {
			--$i;
		}
		if ( $i >= 0 ) {
			$lead = ord( $cut[ $i ] );
			$need = 1;
			if ( $lead >= 0xF0 ) {
				$need = 4;
			} elseif ( $lead >= 0xE0 ) {
				$need = 3;
			} elseif ( $lead >= 0xC0 ) {
				$need = 2;
			}
			if ( $len - $i < $need ) {
				$cut = substr( $cut, 0, $i );
			}
		}
		return rtrim( $cut ) . self::ELLIPSIS;
	}

	/**
	 * Metadata within the contract: at most 16 keys matching [A-Za-z0-9_.-]{1,64}, scalar values,
	 * strings of at most 512 characters.
	 *
	 * @param array $meta Metadata.
	 * @return array
	 */
	public static function metadata( array $meta ) {
		$out = array();
		foreach ( $meta as $key => $value ) {
			if ( count( $out ) >= 16 ) {
				break;
			}
			$key = preg_replace( '/[^A-Za-z0-9_.-]/', '_', (string) $key );
			$key = substr( $key, 0, 64 );
			if ( '' === $key || null === $value ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( is_float( $value ) ) {
				$out[ $key ] = is_finite( $value ) ? $value : (string) $value;
			} elseif ( is_scalar( $value ) ) {
				$text = self::plain( $value, true );
				if ( '' !== $text ) {
					$out[ $key ] = self::truncate_chars( $text, 512 );
				}
			}
		}
		return $out;
	}

	/**
	 * The URL if it is https:// without credentials or fragment, else ''.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function https_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		return esc_url_raw( $url, array( 'https' ) );
	}

	/**
	 * The Idempotency-Key for a logical event key: scoped to this site, printable ASCII, at most
	 * 128 characters (long keys are hashed).
	 *
	 * @param string $logical Event key, e.g. "order-1234-new".
	 * @param string $site_id Site id.
	 * @return string
	 */
	public static function idempotency_key( $logical, $site_id ) {
		$clean = preg_replace( '/[^\x21-\x7E]/', '_', (string) $logical );
		$key   = 'wp-' . $site_id . '-' . $clean;
		if ( strlen( $key ) > self::MAX_KEY_LENGTH ) {
			$key = 'wp-' . $site_id . '-h-' . hash( 'sha256', (string) $logical );
		}
		return $key;
	}
}
