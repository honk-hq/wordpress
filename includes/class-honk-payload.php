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

	const MAX_ACTIONS = 3;

	const MAX_ACTION_TITLE_CHARS = 40;

	const MAX_URL_BYTES = 2048;

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

		if ( isset( $fields['actions'] ) && is_array( $fields['actions'] ) ) {
			$actions = self::actions( $fields['actions'], isset( $out['url'] ) ? $out['url'] : '' );
			if ( ! empty( $actions ) ) {
				$out['actions'] = $actions;
			}
		}

		return self::fit( $out );
	}

	/**
	 * Shrinks a message until its JSON body fits in 16 KiB: first the message text, then the
	 * metadata, then the buttons.
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
	 * Buttons within the contract (contracts/API.md §13): at most three, each a one-line title of
	 * at most 40 characters and a link the API accepts (action_url()). Invalid buttons are left
	 * out, so one can never get the whole message refused, and so is an https button that only
	 * repeats the message's own link.
	 *
	 * @param array  $actions Buttons: title, url.
	 * @param string $url     The message's link.
	 * @return array<int, array{title: string, url: string}>
	 */
	public static function actions( array $actions, $url = '' ) {
		$out = array();
		foreach ( $actions as $action ) {
			if ( count( $out ) >= self::MAX_ACTIONS ) {
				break;
			}
			if ( ! is_array( $action ) || ! isset( $action['title'], $action['url'] ) ) {
				continue;
			}
			$title = self::truncate_chars( self::plain( $action['title'], true ), self::MAX_ACTION_TITLE_CHARS );
			$link  = self::action_url( $action['url'] );
			if ( '' === $title || 1 !== preg_match( '/^[^\p{Cc}\x{2028}\x{2029}]+$/u', $title ) || '' === $link || $link === $url ) {
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
	 * A button's link if the API accepts it, else '' (trimmed, at most 2048 bytes, no spaces or
	 * control characters):
	 * - https:// as https_url();
	 * - mailto: exactly one address with a dotted domain, and only subject= and body= after "?";
	 * - tel: or tel:// a number: + only first, then digits and - . ( ), at least one digit;
	 * - sms: a number as for tel:, and only body= after "?".
	 * Every other scheme is refused.
	 *
	 * @param mixed $url Link.
	 * @return string
	 */
	public static function action_url( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || strlen( $url ) > self::MAX_URL_BYTES || 1 !== preg_match( '/^[^\s\p{Z}\p{Cc}]+$/u', $url ) ) {
			return '';
		}
		$parts = explode( ':', $url, 2 );
		$rest  = isset( $parts[1] ) ? $parts[1] : '';
		$split = explode( '?', $rest, 2 );
		$query = isset( $split[1] ) ? $split[1] : '';
		switch ( strtolower( $parts[0] ) ) {
			case 'https':
				// Valid percent-encoding and no port 0, as the server parses it.
				$port = wp_parse_url( $url, PHP_URL_PORT );
				return preg_match( '/%(?![0-9A-Fa-f]{2})/', $url ) || 0 === $port ? '' : self::https_url( $url );
			case 'mailto':
				return self::mail_address( $split[0] ) && self::link_query( $query, array( 'subject', 'body' ) ) ? $url : '';
			case 'tel':
				return self::phone_number( (string) preg_replace( '#^//#', '', $rest ) ) ? $url : '';
			case 'sms':
				return self::phone_number( $split[0] ) && self::link_query( $query, array( 'body' ) ) ? $url : '';
		}
		return '';
	}

	/**
	 * Whether the (percent-encoded) address of a mailto: link is one plain address with a dotted
	 * domain, as the server reads it.
	 *
	 * @param string $encoded Address.
	 * @return bool
	 */
	private static function mail_address( $encoded ) {
		if ( preg_match( '/%(?![0-9A-Fa-f]{2})/', $encoded ) ) {
			return false;
		}
		$address = rawurldecode( $encoded );
		$at      = strrpos( $address, '@' );
		if ( '' === $address || preg_match( '/[,<>" ]/', $address ) || ! $at ) {
			return false;
		}
		$atext = '[^\s\p{Cc}()<>\[\]:;@\\\\,".]';
		return 1 === preg_match( '/^' . $atext . '+(?:\.' . $atext . '+)*$/u', substr( $address, 0, $at ) )
			&& 1 === preg_match( '/^(?:' . $atext . '+(?:\.' . $atext . '+)+|\[[!-Z^-~]*\.[!-Z^-~]*\])$/u', substr( $address, $at + 1 ) );
	}

	/**
	 * Whether a tel: or sms: number is one: + only first, then digits and - . ( ), at least one
	 * digit.
	 *
	 * @param string $number Number.
	 * @return bool
	 */
	private static function phone_number( $number ) {
		return 1 === preg_match( '/^\+?[0-9().-]*[0-9][0-9().-]*$/', $number );
	}

	/**
	 * Whether the query of a mailto: or sms: link is valid percent-encoding with only the allowed
	 * keys ('' is no query).
	 *
	 * @param string   $query   Query, without "?".
	 * @param string[] $allowed Keys.
	 * @return bool
	 */
	private static function link_query( $query, array $allowed ) {
		if ( preg_match( '/%(?![0-9A-Fa-f]{2})/', $query ) ) {
			return false;
		}
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$key = explode( '=', $pair, 2 );
			if ( false !== strpos( $pair, ';' ) || ! in_array( urldecode( $key[0] ), $allowed, true ) ) {
				return false;
			}
		}
		return true;
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
