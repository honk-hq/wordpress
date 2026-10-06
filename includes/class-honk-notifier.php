<?php
/**
 * Turns an event into a queued Honk message.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one entry point the modules use to send something.
 */
final class Honk_Notifier {

	/**
	 * Queues the message of an event if the event is switched on and the key was not sent yet.
	 *
	 * The builder runs in the notification language and returns the message fields: title,
	 * message, group_key, event_type, url, metadata, occurred_at, and pii (true when the text
	 * contains personal data). It may return null to send nothing.
	 *
	 * @param string   $event_id Event id (Honk_Events).
	 * @param string   $key      Logical key of this occurrence, e.g. "order-1234-new". Repeats of the
	 *                           same key within 24 hours are dropped.
	 * @param callable $builder  Returns the message fields.
	 * @param array    $options  severity (overrides the event's level, e.g. for recoveries);
	 *                           now (start delivery right away, for when WP-Cron is not running).
	 * @return string|false Job id, or false when nothing was queued.
	 */
	public static function emit( $event_id, $key, $builder, array $options = array() ) {
		if ( ! Honk_Settings::is_configured() || ! Honk_Settings::is_enabled( $event_id ) ) {
			return false;
		}
		$idempotency_key = Honk_Payload::idempotency_key( $key, Honk_Settings::site_id() );
		if ( Honk_Dedupe::seen( $idempotency_key ) ) {
			return false;
		}

		$fields = Honk_I18n::with_locale( $builder );
		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return false;
		}

		$event    = Honk_Settings::event( $event_id );
		$priority = $event['priority'];
		if ( isset( $fields['event_type'] ) && 'recovery' === $fields['event_type'] && in_array( $priority, array( 'high', 'urgent' ), true ) ) {
			$priority = 'normal'; // A recovery is good news: never louder than normal.
		}
		$payload = Honk_Payload::normalize(
			array_merge(
				array(
					'source'      => Honk_Settings::source(),
					'environment' => Honk_Settings::environment(),
					'channel'     => Honk_Events::section( $event_id ),
					'category'    => Honk_Events::category( $event_id ),
				),
				$fields,
				array(
					'severity' => isset( $options['severity'] ) ? $options['severity'] : $event['severity'],
					'priority' => $priority,
				)
			)
		);

		/**
		 * Filters a message before it is queued. Return an empty array to drop it.
		 *
		 * @param array  $payload  Message fields (contracts/API.md §5).
		 * @param string $event_id Event id.
		 */
		$payload = apply_filters( 'honk_message', $payload, $event_id );
		if ( ! is_array( $payload ) || empty( $payload['message'] ) ) {
			return false;
		}

		return Honk_Queue::push(
			$event_id,
			$payload,
			$idempotency_key,
			array(
				'pii' => ! empty( $fields['pii'] ),
				'now' => ! empty( $options['now'] ),
			)
		);
	}

	/**
	 * Who did something: the current user's login, "WP-CLI", or "WordPress" for background tasks.
	 *
	 * @return string
	 */
	public static function actor() {
		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			return $user->user_login;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'WP-CLI';
		}
		return wp_doing_cron() ? __( 'a scheduled task', 'honk-me' ) : __( 'WordPress', 'honk-me' );
	}

	/**
	 * How a user is named in a message: their name and email when personal data is allowed,
	 * their login for staff accounts, otherwise only their id.
	 *
	 * @param WP_User|int $user User or id.
	 * @param bool        $pii  Whether personal data may be included.
	 * @return string
	 */
	public static function user_label( $user, $pii ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( (int) $user );
		}
		if ( ! $user ) {
			return __( 'unknown user', 'honk-me' );
		}
		if ( $pii ) {
			$name = trim( $user->display_name );
			return ( '' !== $name && $name !== $user->user_login ? $name . ' · ' : '' ) . $user->user_login . ( $user->user_email ? ' · ' . $user->user_email : '' );
		}
		if ( user_can( $user, 'edit_posts' ) ) {
			return $user->user_login;
		}
		/* translators: %d: user id */
		return sprintf( __( 'user #%d', 'honk-me' ), $user->ID );
	}

	/**
	 * What a message can say about a user.
	 *
	 * @param WP_User $user User.
	 * @return array{display: string, login: string, email: string}
	 */
	public static function user_data( $user ) {
		return array(
			'display' => trim( (string) $user->display_name ),
			'login'   => (string) $user->user_login,
			'email'   => (string) $user->user_email,
		);
	}

	/**
	 * A user as an outline line: "Jane Doe · jane · jane@example.com" (the name only when it
	 * differs from the login), as user_label() writes it with personal data.
	 *
	 * @param array  $d     user_data().
	 * @param string $login Detail that shows the login, or '' when it is always shown.
	 * @return array
	 */
	public static function user_line( array $d, $login = 'username' ) {
		return Honk_Details::line(
			array(
				Honk_Details::part( '' !== $d['display'] && $d['display'] !== $d['login'] ? $d['display'] : '', 'name' ),
				Honk_Details::part( $d['login'], '' !== $login ? $login : array() ),
				Honk_Details::part( $d['email'], 'email' ),
			)
		);
	}

	/**
	 * Admin URL for a message link (only https links are sent as the message URL; others go into
	 * the metadata).
	 *
	 * @param string $path Path relative to wp-admin.
	 * @return string
	 */
	public static function admin_link( $path ) {
		return admin_url( $path );
	}

	/**
	 * The link that approves a comment or review: WordPress's own from its moderation email, which
	 * asks to confirm in the dashboard. '' unless the dashboard is on https (buttons need https).
	 *
	 * @param int $comment_id Comment id.
	 * @return string
	 */
	public static function approve_link( $comment_id ) {
		return Honk_Payload::https_url( admin_url( 'comment.php?action=approve&c=' . absint( $comment_id ) ) );
	}

	/**
	 * Adds a link: as the message URL when it is https, else as metadata "link".
	 *
	 * @param array  $fields Message fields.
	 * @param string $url    URL.
	 * @return array
	 */
	public static function with_link( array $fields, $url ) {
		if ( '' === (string) $url ) {
			return $fields;
		}
		if ( '' !== Honk_Payload::https_url( $url ) ) {
			$fields['url'] = $url;
		} else {
			$fields['metadata']         = isset( $fields['metadata'] ) ? $fields['metadata'] : array();
			$fields['metadata']['link'] = $url;
		}
		return $fields;
	}

	/**
	 * Formats an amount as plain text in a currency (WooCommerce), e.g. "€84.00".
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function money( $amount, $currency = '' ) {
		if ( function_exists( 'wc_price' ) ) {
			$args = $currency ? array( 'currency' => $currency ) : array();
			return Honk_Payload::plain( wc_price( $amount, $args ), true );
		}
		return trim( number_format_i18n( (float) $amount, 2 ) . ' ' . $currency );
	}

	/**
	 * Masks an IP address to its network (/24 for IPv4, /48 for IPv6).
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function mask_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed ) {
				$network = inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) );
				return $network . '/48';
			}
		}
		return '';
	}

	/**
	 * The client IP (REMOTE_ADDR). Sites behind a proxy can use the honk_client_ip filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the client IP used by the security events (e.g. behind a trusted proxy).
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'honk_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
