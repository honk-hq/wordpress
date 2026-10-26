<?php
/**
 * Builds the JSON body of POST /v1/messages from an event's fields.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Message building: WordPress text to plain text, the 16 KiB body, idempotency keys, and the
 * links the modules put on buttons. Field limits are the server's: it delivers first, shortens or
 * leaves out what doesn't fit, and lists each correction in the 202's warnings, which the
 * delivery log shows (contracts/API.md §5.1).
 */
final class Honk_Payload {

	const MAX_BODY_BYTES = 16384;

	const MAX_KEY_LENGTH = 128;

	/**
	 * Buttons per message and characters per button title: the plugin's own buttons stay within
	 * them (Honk_Details).
	 */
	const MAX_ACTIONS = 3;

	const MAX_ACTION_TITLE_CHARS = 40;

	const MAX_URL_BYTES = 2048;

	const ELLIPSIS = '…';

	/**
	 * Short text fields, made one line.
	 */
	const SHORT_FIELDS = array( 'source', 'environment', 'channel', 'group_key' );

	const CATEGORIES = array( 'infrastructure', 'security', 'backups', 'deployments', 'payments', 'customers', 'sales', 'automation', 'personal', 'other' );

	/**
	 * Turns an event's fields into a message: WordPress text (tags, entities) becomes plain text,
	 * empty fields are left out, and the encoded body fits in 16 KiB. Lengths, links and levels are
	 * left to the server, which corrects them and says so in its warnings.
	 *
	 * @param array $fields Message fields (contract names).
	 * @return array Empty when there is neither a message nor a title.
	 */
	public static function normalize( array $fields ) {
		$out = array();

		$title = isset( $fields['title'] ) ? self::plain( $fields['title'], true ) : '';
		if ( '' !== $title ) {
			$out['title'] = $title;
		}

		$message = isset( $fields['message'] ) ? self::plain( $fields['message'], false ) : '';
		if ( '' === $message && '' === $title ) {
			return array();
		}
		// Without text the message repeats its title: servers before deliver-first (2026-10-25)
		// refuse a message without one.
		$out['message'] = '' !== $message ? $message : $title;

		foreach ( array( 'severity', 'priority' ) as $name ) {
			if ( isset( $fields[ $name ] ) && is_scalar( $fields[ $name ] ) && '' !== (string) $fields[ $name ] ) {
				$out[ $name ] = (string) $fields[ $name ];
			}
		}

		foreach ( self::SHORT_FIELDS as $name ) {
			if ( isset( $fields[ $name ] ) ) {
				$value = self::plain( $fields[ $name ], true );
				if ( '' !== $value ) {
					$out[ $name ] = $value;
				}
			}
		}

		// "event" is the default; problem and recovery open and close an incident.
		if ( isset( $fields['event_type'] ) && is_string( $fields['event_type'] ) && '' !== $fields['event_type'] && 'event' !== $fields['event_type'] ) {
			$out['event_type'] = $fields['event_type'];
		}

		if ( isset( $fields['category'] ) && is_string( $fields['category'] ) && '' !== $fields['category'] ) {
			$out['category'] = $fields['category'];
		}

		if ( isset( $fields['url'] ) && is_string( $fields['url'] ) && '' !== trim( $fields['url'] ) ) {
			$out['url'] = trim( $fields['url'] );
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

		if ( isset( $fields['actions'] ) && is_array( $fields['actions'] ) ) {
			$actions = self::actions( $fields['actions'], isset( $out['url'] ) ? $out['url'] : '' );
			if ( ! empty( $actions ) ) {
				$out['actions'] = $actions;
			}
		}

		return self::fit( $out );
	}

	/**
	 * Shrinks a message until its JSON body fits in 16 KiB, the one limit the server refuses
	 * instead of correcting: first the message text, then the metadata, then the buttons.
	 *
	 * @param array $payload Normalized payload.
	 * @return array
	 */
	public static function fit( array $payload ) {
		$size = strlen( self::encode( $payload ) );
		if ( $size <= self::MAX_BODY_BYTES ) {
			return $payload;
		}
		if ( isset( $payload['message'] ) ) {
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
		}
		if ( $size > self::MAX_BODY_BYTES ) {
			unset( $payload['metadata'] );
			$size = strlen( self::encode( $payload ) );
		}
		if ( $size > self::MAX_BODY_BYTES ) {
			unset( $payload['actions'] );
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
	 * Metadata as JSON values: keys made of the characters the API accepts ([A-Za-z0-9_.-], others
	 * become "_", at most 64), plain-text strings, and only scalar values. How many keys and how
	 * long a value may be is the server's to correct.
	 *
	 * @param array $meta Metadata.
	 * @return array
	 */
	public static function metadata( array $meta ) {
		$out = array();
		foreach ( $meta as $key => $value ) {
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
					$out[ $key ] = $text;
				}
			}
		}
		return $out;
	}

	/**
	 * Buttons as the API takes them: a plain-text title and a link, in order. Rows without a title
	 * or a link are left out, and so is an https button that only repeats the message's own link.
	 * Which links and how many buttons the API accepts is the server's to correct: it leaves out
	 * an invalid button with a warning and keeps the others.
	 *
	 * @param array  $actions Buttons: title, url.
	 * @param string $url     The message's link.
	 * @return array<int, array{title: string, url: string}>
	 */
	public static function actions( array $actions, $url = '' ) {
		$out = array();
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || ! isset( $action['title'], $action['url'] ) || ! is_string( $action['url'] ) ) {
				continue;
			}
			$title = self::plain( $action['title'], true );
			$link  = trim( $action['url'] );
			if ( '' === $title || '' === $link || $link === $url ) {
				continue;
			}
			$out[] = array(
				'title' => $title,
				'url'   => $link,
			);
		}
		return $out;
	}

	/**
	 * A mailto: link to one email address, with a percent-encoded subject, or '' when the address
	 * isn't one a mailto: link can carry as it is (letters, digits and . _ + - before the @, a
	 * dotted domain).
	 *
	 * @param mixed  $email   Email address.
	 * @param string $subject Subject ('' for none).
	 * @return string
	 */
	public static function mailto_url( $email, $subject = '' ) {
		$email = is_scalar( $email ) ? trim( (string) $email ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9._+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $email ) || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return '';
		}
		$subject = self::truncate_chars( self::plain( $subject, true ), 120 );
		return 'mailto:' . $email . ( '' !== $subject ? '?subject=' . rawurlencode( $subject ) : '' );
	}

	/**
	 * A tel: link to a phone number as entered, reduced to its digits (a leading + kept, and the
	 * national 0 in "+44 (0)20…" dropped), or '' when the text isn't only a phone number: anything
	 * but digits, spaces, + ( ) . / and dashes (an extension, a note), or fewer than 3 or more
	 * than 20 digits. Letters are never stripped: "555 0142 ext. 12" would dial the wrong number.
	 *
	 * @param mixed $phone Phone number as entered.
	 * @return string
	 */
	public static function tel_url( $phone ) {
		$phone = self::plain( $phone, true );
		if ( ! preg_match( '/^\+?[0-9 ().\/\-\x{2010}-\x{2015}\x{2212}]+$/u', $phone ) ) {
			return '';
		}
		$plus   = '+' === $phone[0];
		$digits = preg_replace( '/\D/', '', $plus ? preg_replace( '/\(\s*0\s*\)/', '', $phone ) : $phone );
		if ( strlen( $digits ) < 3 || strlen( $digits ) > 20 ) {
			return '';
		}
		return 'tel:' . ( $plus ? '+' : '' ) . $digits;
	}

	/**
	 * The URL if it is https:// without credentials or fragment, else ''.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function https_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > self::MAX_URL_BYTES ) {
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
