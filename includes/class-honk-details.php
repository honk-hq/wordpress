<?php
/**
 * What each notification says: the details an event can include, the site's choices, and the
 * one function that turns a message outline into its title and text.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Details of each event.
 *
 * Every module describes its message as an outline (a "spec"): the title and the lines, made of
 * parts, each part tagged with the detail it shows. compose() keeps the parts whose details are
 * on. The settings screen's preview runs the same outline, built from sample data, through the
 * same rules in assets/admin.js, so the preview reads exactly like the message.
 *
 * Personal details are on by default but only count while "Include customer names and emails" is
 * on: with the defaults, every message reads as it did in 0.1.0.
 */
final class Honk_Details {

	/**
	 * Event id => detail => [ personal data, on by default ]. Events without optional details have
	 * an empty list. The order is the order on the settings screen.
	 */
	const FACTS = array(
		// Security.
		'admin_login_new_device'  => array(
			'username' => array( false, true ),
			'device'   => array( false, true ),
			'network'  => array( false, true ),
			'ip'       => array( true, true ),
		),
		'login_failures_burst'    => array(
			'ip_count'  => array( false, true ),
			'usernames' => array( true, true ),
		),
		'new_administrator'       => array(
			'name'  => array( true, true ),
			'email' => array( true, true ),
		),
		'role_changed'            => array(
			'name'  => array( true, true ),
			'email' => array( true, true ),
		),
		'site_identity_changed'   => array(
			'emails' => array( true, true ),
		),
		'plugin_changed'          => array(
			'actor' => array( false, true ),
			'file'  => array( false, true ),
		),
		'theme_changed'           => array(
			'actor'    => array( false, true ),
			'previous' => array( false, true ),
		),
		'file_edited'             => array(
			'actor' => array( false, true ),
			'tip'   => array( false, true ),
		),
		'updates_installed'       => array(
			'actor' => array( false, true ),
		),
		// Site health.
		'fatal_error'             => array(
			'error' => array( false, true ),
			'file'  => array( false, true ),
		),
		'site_health_critical'    => array(),
		'auto_update_failed'      => array(),
		'updates_available'       => array(),
		'cron_overdue'            => array(
			'hook'   => array( false, true ),
			'advice' => array( false, true ),
		),
		'disk_space_low'          => array(),
		// Content and users.
		'user_registered'         => array(
			'name'     => array( true, true ),
			'username' => array( true, true ),
			'email'    => array( true, true ),
			'role'     => array( false, true ),
		),
		'comment_pending'         => array(
			'post'  => array( false, true ),
			'name'  => array( true, true ),
			'email' => array( true, true ),
			'text'  => array( true, true ),
		),
		'post_pending'            => array(
			'author'  => array( false, true ),
			'excerpt' => array( false, false ),
		),
		'post_published'          => array(
			'author'  => array( false, true ),
			'excerpt' => array( false, false ),
		),
		// Forms.
		'form_cf7'                => array( 'values' => array( true, true ) ),
		'form_wpforms'            => array( 'values' => array( true, true ) ),
		'form_gravityforms'       => array( 'values' => array( true, true ) ),
		'form_elementor'          => array( 'values' => array( true, true ) ),
		'form_fluentforms'        => array( 'values' => array( true, true ) ),
		'form_ninjaforms'         => array( 'values' => array( true, true ) ),
		// WooCommerce.
		'woo_new_order'           => array(
			'total'    => array( false, true ),
			'count'    => array( false, true ),
			'status'   => array( false, true ),
			'payment'  => array( false, true ),
			'products' => array( false, false ),
			'shipping' => array( false, false ),
			'name'     => array( true, true ),
			'email'    => array( true, true ),
			'phone'    => array( true, false ),
			'city'     => array( true, false ),
			'note'     => array( true, false ),
		),
		'woo_order_status'        => array(
			'total'    => array( false, true ),
			'count'    => array( false, true ),
			'products' => array( false, false ),
			'payment'  => array( false, false ),
			'name'     => array( true, true ),
			'email'    => array( true, true ),
			'phone'    => array( true, false ),
		),
		'woo_payment_failed'      => array(
			'total'    => array( false, true ),
			'count'    => array( false, true ),
			'payment'  => array( false, true ),
			'products' => array( false, false ),
			'name'     => array( true, true ),
			'email'    => array( true, true ),
			'phone'    => array( true, false ),
		),
		'woo_refund'              => array(
			'total'  => array( false, true ),
			'count'  => array( false, true ),
			'reason' => array( false, true ),
			'name'   => array( true, false ),
			'email'  => array( true, false ),
		),
		'woo_low_stock'           => array(
			'sku'   => array( false, true ),
			'stock' => array( false, true ),
		),
		'woo_out_of_stock'        => array(
			'sku'   => array( false, true ),
			'stock' => array( false, true ),
		),
		'woo_new_customer'        => array(
			'name'     => array( true, true ),
			'username' => array( true, true ),
			'email'    => array( true, true ),
		),
		'woo_new_review'          => array(
			'rating' => array( false, true ),
			'name'   => array( true, true ),
			'email'  => array( true, true ),
			'text'   => array( true, true ),
		),
		'woo_subscription_failed' => array(
			'renewal' => array( false, true ),
			'name'    => array( true, true ),
			'email'   => array( true, true ),
		),
		'woo_daily_summary'       => array(
			'items'   => array( false, true ),
			'refunds' => array( false, true ),
		),
	);

	/**
	 * Form events whose plugin lists each form's fields (so fields can be left out one by one).
	 */
	const FORM_FIELD_EVENTS = array( 'form_cf7', 'form_wpforms', 'form_gravityforms' );

	/**
	 * Limits on the stored list of left-out form fields.
	 */
	const MAX_FORMS = 50;

	const MAX_FIELDS = 100;

	/**
	 * The details an event can include.
	 *
	 * @param string $event_id Event id.
	 * @return array<string, array{0: bool, 1: bool}>
	 */
	public static function facts( $event_id ) {
		return isset( self::FACTS[ $event_id ] ) ? self::FACTS[ $event_id ] : array();
	}

	/**
	 * Whether a detail is personal data (and so needs "Include customer names and emails").
	 *
	 * @param string $event_id Event id.
	 * @param string $fact     Detail.
	 * @return bool
	 */
	public static function is_personal( $event_id, $fact ) {
		$facts = self::facts( $event_id );
		return isset( $facts[ $fact ] ) && $facts[ $fact ][0];
	}

	/**
	 * The site's choice for a detail (its default until someone changes it), whatever the privacy
	 * switch says.
	 *
	 * @param string $event_id Event id.
	 * @param string $fact     Detail.
	 * @return bool
	 */
	public static function chosen( $event_id, $fact ) {
		$facts = self::facts( $event_id );
		if ( ! isset( $facts[ $fact ] ) ) {
			return false;
		}
		$all = Honk_Settings::get( 'details' );
		if ( is_array( $all ) && isset( $all[ $event_id ] ) && is_array( $all[ $event_id ] ) && array_key_exists( $fact, $all[ $event_id ] ) ) {
			return (bool) $all[ $event_id ][ $fact ];
		}
		return $facts[ $fact ][1];
	}

	/**
	 * Whether a detail goes into the message: chosen, and allowed by the privacy switch when it is
	 * personal data.
	 *
	 * @param string $event_id Event id.
	 * @param string $fact     Detail.
	 * @return bool
	 */
	public static function on( $event_id, $fact ) {
		if ( ! self::chosen( $event_id, $fact ) ) {
			return false;
		}
		return ! self::is_personal( $event_id, $fact ) || Honk_Settings::include_pii();
	}

	/**
	 * Every detail of an event: whether it goes into the message.
	 *
	 * @param string $event_id Event id.
	 * @return array<string, bool>
	 */
	public static function states( $event_id ) {
		$out = array();
		foreach ( array_keys( self::facts( $event_id ) ) as $fact ) {
			$out[ $fact ] = self::on( $event_id, $fact );
		}
		return $out;
	}

	/**
	 * Fields left out of a form's notification (form plugins that list their fields).
	 *
	 * @param string $event_id Event id.
	 * @param string $form_id  Form id.
	 * @return string[] Field keys.
	 */
	public static function skipped_fields( $event_id, $form_id ) {
		$all = Honk_Settings::get( 'details' );
		if ( ! is_array( $all ) || empty( $all[ $event_id ]['skip'][ $form_id ] ) || ! is_array( $all[ $event_id ]['skip'][ $form_id ] ) ) {
			return array();
		}
		return array_map( 'strval', $all[ $event_id ]['skip'][ $form_id ] );
	}

	/*
	 * Outlines.
	 */

	/**
	 * One part of a title or a line.
	 *
	 * @param string          $text Text ('' parts are left out).
	 * @param string|string[] $when Details that must be on.
	 * @param string|string[] $not  Details that must be off (for wording that changes without a detail).
	 * @return array
	 */
	public static function part( $text, $when = array(), $not = array() ) {
		$part = array( 't' => (string) $text );
		if ( array() !== (array) $when ) {
			$part['if'] = array_values( (array) $when );
		}
		if ( array() !== (array) $not ) {
			$part['not'] = array_values( (array) $not );
		}
		return $part;
	}

	/**
	 * One line of the message.
	 *
	 * @param array  $parts Parts (part()).
	 * @param string $sep   Between the parts that remain.
	 * @param array  $extra any: details of which at least one must be on for the line to show;
	 *                      empty: text when no part remains (for example "Anonymous");
	 *                      wrap: a sentence the remaining parts are put into, at "\x01".
	 * @return array
	 */
	public static function line( array $parts, $sep = ' · ', array $extra = array() ) {
		return array_merge(
			array(
				'parts' => array_values( $parts ),
				'sep'   => (string) $sep,
			),
			$extra
		);
	}

	/**
	 * A line with a single part.
	 *
	 * @param string          $text Text.
	 * @param string|string[] $when Details that must be on.
	 * @return array
	 */
	public static function text( $text, $when = array() ) {
		return self::line( array( self::part( $text, $when ) ) );
	}

	/**
	 * Turns an outline into the message's title and text, keeping only what the details allow.
	 * assets/admin.js has the same rules (compose()) for the preview.
	 *
	 * @param string $event_id Event id.
	 * @param array  $spec     title: parts; lines: lines; fallback: text when no line remains.
	 * @param array  $on       Detail => whether it is on (null: the site's settings).
	 * @return array{title: string, message: string, pii: bool} pii: a personal detail is part of the text.
	 */
	public static function compose( $event_id, array $spec, $on = null ) {
		$on    = is_array( $on ) ? $on : self::states( $event_id );
		$pii   = false;
		$facts = self::facts( $event_id );
		$keep  = function ( array $part ) use ( $on, $facts, &$pii ) {
			foreach ( isset( $part['if'] ) ? $part['if'] : array() as $fact ) {
				if ( empty( $on[ $fact ] ) ) {
					return false;
				}
			}
			foreach ( isset( $part['not'] ) ? $part['not'] : array() as $fact ) {
				if ( ! empty( $on[ $fact ] ) ) {
					return false;
				}
			}
			foreach ( isset( $part['if'] ) ? $part['if'] : array() as $fact ) {
				if ( isset( $facts[ $fact ] ) && $facts[ $fact ][0] ) {
					$pii = true;
				}
			}
			return '' !== $part['t'];
		};

		$title = '';
		foreach ( isset( $spec['title'] ) ? $spec['title'] : array() as $part ) {
			if ( $keep( $part ) ) {
				$title .= $part['t'];
			}
		}

		$lines = array();
		foreach ( isset( $spec['lines'] ) ? $spec['lines'] : array() as $line ) {
			if ( ! empty( $line['any'] ) ) {
				$any = false;
				foreach ( $line['any'] as $fact ) {
					$any = $any || ! empty( $on[ $fact ] );
				}
				if ( ! $any ) {
					continue;
				}
			}
			$texts = array();
			foreach ( $line['parts'] as $part ) {
				if ( $keep( $part ) ) {
					$texts[] = $part['t'];
				}
			}
			if ( empty( $texts ) ) {
				if ( ! isset( $line['empty'] ) ) {
					continue;
				}
				$texts = array( $line['empty'] );
			}
			$text    = implode( $line['sep'], $texts );
			$lines[] = isset( $line['wrap'] ) ? str_replace( "\x01", $text, $line['wrap'] ) : $text;
		}

		$message = implode( "\n", $lines );
		if ( '' === $message && isset( $spec['fallback'] ) ) {
			$message = (string) $spec['fallback'];
		}
		return array(
			'title'   => $title,
			'message' => $message,
			'pii'     => $pii,
		);
	}

	/**
	 * Message fields from an outline: title, message and pii, for Honk_Notifier::emit().
	 *
	 * @param string $event_id Event id.
	 * @param array  $spec     Outline.
	 * @param array  $fields   Other fields (group_key, metadata…).
	 * @return array
	 */
	public static function fields( $event_id, array $spec, array $fields = array() ) {
		$composed = self::compose( $event_id, $spec );
		$out      = array_merge(
			array(
				'title'   => $composed['title'],
				'message' => $composed['message'],
			),
			$fields
		);
		if ( $composed['pii'] ) {
			$out['pii'] = true;
		}
		return $out;
	}

	/*
	 * Settings.
	 */

	/**
	 * Sanitizes the posted details. Events that are not on the form keep what was stored.
	 *
	 * @param array $posted Posted: event id => detail => '0'|'1', plus fields => form id => field key
	 *                      => '0'|'1' for form plugins that list their fields (or skip => form id
	 *                      => keys, from an already sanitized array).
	 * @param array $stored Stored details.
	 * @return array
	 */
	public static function sanitize( array $posted, array $stored ) {
		$out = array();
		foreach ( self::FACTS as $event_id => $facts ) {
			$old = isset( $stored[ $event_id ] ) && is_array( $stored[ $event_id ] ) ? $stored[ $event_id ] : array();
			$row = isset( $posted[ $event_id ] ) && is_array( $posted[ $event_id ] ) ? $posted[ $event_id ] : null;
			if ( null === $row ) {
				if ( ! empty( $old ) ) {
					$out[ $event_id ] = self::sanitize_row( $event_id, $old, array() );
				}
				continue;
			}
			$out[ $event_id ] = self::sanitize_row( $event_id, $row, $old );
		}
		return $out;
	}

	/**
	 * Sanitizes one event's details.
	 *
	 * @param string $event_id Event id.
	 * @param array  $row      Posted or stored values.
	 * @param array  $old      Stored values (for details missing from $row).
	 * @return array
	 */
	private static function sanitize_row( $event_id, array $row, array $old ) {
		$clean = array();
		foreach ( self::facts( $event_id ) as $fact => $meta ) {
			if ( array_key_exists( $fact, $row ) && is_scalar( $row[ $fact ] ) ) {
				$clean[ $fact ] = ! empty( $row[ $fact ] );
			} elseif ( array_key_exists( $fact, $old ) ) {
				$clean[ $fact ] = (bool) $old[ $fact ];
			} else {
				$clean[ $fact ] = $meta[1];
			}
		}
		if ( in_array( $event_id, self::FORM_FIELD_EVENTS, true ) ) {
			$skip = isset( $old['skip'] ) && is_array( $old['skip'] ) ? self::sanitize_skip( $old['skip'] ) : array();
			if ( isset( $row['skip'] ) && is_array( $row['skip'] ) ) {
				$skip = self::sanitize_skip( $row['skip'] );
			}
			if ( isset( $row['fields'] ) && is_array( $row['fields'] ) ) {
				foreach ( $row['fields'] as $form_id => $fields ) {
					$form_id = self::form_key( $form_id );
					if ( '' === $form_id || ! is_array( $fields ) ) {
						continue;
					}
					$left_out = array();
					foreach ( $fields as $key => $included ) {
						$key = self::field_key( $key );
						if ( '' !== $key && is_scalar( $included ) && empty( $included ) ) {
							$left_out[] = $key;
						}
					}
					if ( $left_out ) {
						$skip[ $form_id ] = array_slice( array_values( array_unique( $left_out ) ), 0, self::MAX_FIELDS );
					} else {
						unset( $skip[ $form_id ] );
					}
				}
			}
			if ( $skip ) {
				$clean['skip'] = array_slice( $skip, 0, self::MAX_FORMS, true );
			}
		}
		return $clean;
	}

	/**
	 * Sanitizes a stored list of left-out fields: form id => field keys.
	 *
	 * @param array $skip Value.
	 * @return array<string, string[]>
	 */
	private static function sanitize_skip( array $skip ) {
		$out = array();
		foreach ( $skip as $form_id => $keys ) {
			$form_id = self::form_key( $form_id );
			if ( '' === $form_id || ! is_array( $keys ) ) {
				continue;
			}
			$clean = array();
			foreach ( $keys as $key ) {
				$key = is_scalar( $key ) ? self::field_key( $key ) : '';
				if ( '' !== $key ) {
					$clean[] = $key;
				}
			}
			if ( $clean ) {
				$out[ $form_id ] = array_slice( array_values( array_unique( $clean ) ), 0, self::MAX_FIELDS );
			}
			if ( count( $out ) >= self::MAX_FORMS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A form id as stored: letters, digits, _ and -, at most 64 characters.
	 *
	 * @param mixed $id Form id.
	 * @return string
	 */
	public static function form_key( $id ) {
		return is_scalar( $id ) ? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id ), 0, 64 ) : '';
	}

	/**
	 * A field key as stored (a Contact Form 7 field name, a WPForms or Gravity Forms field id):
	 * letters, digits and _ - . :, at most 64 characters.
	 *
	 * @param mixed $key Field key.
	 * @return string
	 */
	public static function field_key( $key ) {
		return is_scalar( $key ) ? substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $key ), 0, 64 ) : '';
	}

	/*
	 * Labels (settings screen).
	 */

	/**
	 * Translated label of a detail.
	 *
	 * @param string $event_id Event id.
	 * @param string $fact     Detail.
	 * @return string
	 */
	public static function label( $event_id, $fact ) {
		$section = Honk_Events::section( $event_id );
		switch ( $fact ) {
			case 'total':
				return __( 'Order total', 'honk' );
			case 'count':
				return __( 'Number of items', 'honk' );
			case 'status':
				return __( 'Order status', 'honk' );
			case 'payment':
				return __( 'Payment method', 'honk' );
			case 'products':
				return __( 'Products (the first three)', 'honk' );
			case 'shipping':
				return __( 'Shipping method', 'honk' );
			case 'phone':
				return __( 'Phone number', 'honk' );
			case 'city':
				return __( 'Billing city and country', 'honk' );
			case 'note':
				return __( 'Customer’s note', 'honk' );
			case 'reason':
				return __( 'Refund reason', 'honk' );
			case 'renewal':
				return __( 'Renewal order number', 'honk' );
			case 'sku':
				return __( 'SKU', 'honk' );
			case 'stock':
				return __( 'Units in stock', 'honk' );
			case 'items':
				return __( 'Items sold', 'honk' );
			case 'refunds':
				return __( 'Refunded amount', 'honk' );
			case 'rating':
				return __( 'Rating', 'honk' );
			case 'post':
				return __( 'Post title', 'honk' );
			case 'author':
				return __( 'Author', 'honk' );
			case 'excerpt':
				return __( 'Excerpt', 'honk' );
			case 'values':
				return __( 'What people entered', 'honk' );
			case 'username':
				return __( 'Username', 'honk' );
			case 'role':
				return __( 'Role', 'honk' );
			case 'device':
				return __( 'Browser and operating system', 'honk' );
			case 'network':
				return __( 'Network (for example 203.0.113.0/24)', 'honk' );
			case 'ip':
				return __( 'Full IP address', 'honk' );
			case 'ip_count':
				return __( 'Number of IP addresses', 'honk' );
			case 'usernames':
				return __( 'Usernames tried', 'honk' );
			case 'emails':
				return __( 'Old and new email address', 'honk' );
			case 'actor':
				return __( 'Who made the change', 'honk' );
			case 'previous':
				return __( 'Previous theme', 'honk' );
			case 'tip':
				return __( 'How to turn off the file editors', 'honk' );
			case 'error':
				return __( 'Error message', 'honk' );
			case 'hook':
				return __( 'Name of the oldest task', 'honk' );
			case 'advice':
				return __( 'What to check', 'honk' );
			case 'file':
				return 'plugin_changed' === $event_id ? __( 'Plugin file', 'honk' ) : __( 'File and line', 'honk' );
			case 'name':
				if ( 'woo_new_review' === $event_id ) {
					return __( 'Reviewer’s name', 'honk' );
				}
				if ( 'comment_pending' === $event_id ) {
					return __( 'Commenter’s name', 'honk' );
				}
				return 'woocommerce' === $section && 'woo_new_customer' !== $event_id ? __( 'Customer name', 'honk' ) : __( 'Name', 'honk' );
			case 'email':
				if ( 'woo_new_review' === $event_id ) {
					return __( 'Reviewer’s email', 'honk' );
				}
				if ( 'comment_pending' === $event_id ) {
					return __( 'Commenter’s email', 'honk' );
				}
				return 'woocommerce' === $section && 'woo_new_customer' !== $event_id ? __( 'Customer email', 'honk' ) : __( 'Email address', 'honk' );
			case 'text':
				return 'woo_new_review' === $event_id ? __( 'Review text', 'honk' ) : __( 'Comment text', 'honk' );
		}
		return $fact;
	}

	/**
	 * What a notification always says, as a list for "Always included: %s."
	 *
	 * @param string $event_id Event id.
	 * @return string[]
	 */
	public static function always( $event_id ) {
		$order_link = __( 'a link to the order', 'honk' );
		$order      = __( 'the order number', 'honk' );
		$who        = __( 'who made the change', 'honk' );
		if ( 'forms' === Honk_Events::section( $event_id ) ) {
			return array( __( 'the form’s name', 'honk' ) );
		}
		switch ( $event_id ) {
			case 'admin_login_new_device':
				return array( __( 'whether the browser or the network is new', 'honk' ), __( 'what to do if it wasn’t you', 'honk' ) );
			case 'login_failures_burst':
				return array( __( 'the number of failed sign-ins', 'honk' ), __( 'an all-clear when they stop', 'honk' ) );
			case 'new_administrator':
				return array( __( 'the username', 'honk' ), $who );
			case 'role_changed':
				return array( __( 'the username', 'honk' ), __( 'the old and new role', 'honk' ), $who );
			case 'site_identity_changed':
				return array( __( 'what changed', 'honk' ), __( 'the old and new site address', 'honk' ), $who );
			case 'plugin_changed':
				return array( __( 'the plugin’s name', 'honk' ), __( 'what happened', 'honk' ) );
			case 'theme_changed':
				return array( __( 'the theme’s name', 'honk' ), __( 'what happened', 'honk' ) );
			case 'file_edited':
				return array( __( 'the file', 'honk' ), __( 'the plugin or theme it belongs to', 'honk' ) );
			case 'updates_installed':
				return array( __( 'what was updated, with the new versions', 'honk' ) );
			case 'fatal_error':
				return array( __( 'the plugin or theme that caused it', 'honk' ), __( 'whether WordPress emailed a recovery link', 'honk' ) );
			case 'site_health_critical':
				return array( __( 'the critical issues', 'honk' ), __( 'an all-clear once they’re fixed', 'honk' ) );
			case 'auto_update_failed':
				return array( __( 'what failed to update, and why', 'honk' ) );
			case 'updates_available':
				return array( __( 'the available updates, with their versions', 'honk' ) );
			case 'cron_overdue':
				return array( __( 'how many tasks are late', 'honk' ), __( 'an all-clear when they’re on time again', 'honk' ) );
			case 'disk_space_low':
				return array( __( 'the free and total disk space', 'honk' ), __( 'an all-clear when there’s room again', 'honk' ) );
			case 'user_registered':
				return array( __( 'a link to the user’s profile', 'honk' ) );
			case 'comment_pending':
				return array( __( 'a link to the comments awaiting moderation', 'honk' ) );
			case 'post_pending':
			case 'post_published':
				return array( __( 'the title', 'honk' ), __( 'the post type', 'honk' ), __( 'a link to the post', 'honk' ) );
			case 'woo_new_order':
			case 'woo_payment_failed':
				return array( $order, $order_link );
			case 'woo_order_status':
				return array( $order, __( 'the old and new status', 'honk' ), $order_link );
			case 'woo_refund':
				return array( __( 'the refunded amount', 'honk' ), $order, $order_link );
			case 'woo_low_stock':
			case 'woo_out_of_stock':
				return array( __( 'the product’s name', 'honk' ), __( 'a link to the product', 'honk' ), __( 'an all-clear when it’s back in stock', 'honk' ) );
			case 'woo_new_customer':
				return array( __( 'a link to the customer’s profile', 'honk' ) );
			case 'woo_new_review':
				return array( __( 'the product’s name', 'honk' ), __( 'whether it’s awaiting moderation', 'honk' ), __( 'a link to the reviews', 'honk' ) );
			case 'woo_subscription_failed':
				return array( __( 'the subscription number', 'honk' ), __( 'the amount', 'honk' ), __( 'a link to the subscription', 'honk' ) );
			case 'woo_daily_summary':
				return array( __( 'the date', 'honk' ), __( 'the number of orders', 'honk' ), __( 'the revenue', 'honk' ) );
		}
		return array();
	}
}
