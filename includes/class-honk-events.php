<?php
/**
 * The catalog of events: sections, defaults and labels.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every event the plugin can send, with its defaults.
 *
 * Defaults are plain data (no translation calls) so they can be read at any time, even before
 * `init`. Labels are translated on demand for the settings screen.
 */
final class Honk_Events {

	/**
	 * Event id => [ section, enabled, severity, priority, category ].
	 *
	 * Severity is the canonical Honk value (contract §2.1): info = Light honk, success =
	 * Beep-beep, warning = Loud honk, error = Long honk, critical = Blast.
	 */
	const DEFAULTS = array(
		// Security.
		'admin_login_new_device'  => array( 'security', true, 'warning', 'normal', 'security' ),
		'login_failures_burst'    => array( 'security', true, 'error', 'high', 'security' ),
		'new_administrator'       => array( 'security', true, 'critical', 'high', 'security' ),
		'role_changed'            => array( 'security', true, 'warning', 'normal', 'security' ),
		'site_identity_changed'   => array( 'security', true, 'error', 'high', 'security' ),
		'plugin_changed'          => array( 'security', true, 'warning', 'normal', 'security' ),
		'theme_changed'           => array( 'security', true, 'warning', 'normal', 'security' ),
		'file_edited'             => array( 'security', true, 'error', 'high', 'security' ),
		'updates_installed'       => array( 'security', true, 'info', 'low', 'deployments' ),
		// Site health.
		'fatal_error'             => array( 'health', true, 'error', 'high', 'infrastructure' ),
		'site_health_critical'    => array( 'health', true, 'error', 'normal', 'infrastructure' ),
		'auto_update_failed'      => array( 'health', true, 'error', 'high', 'infrastructure' ),
		'updates_available'       => array( 'health', true, 'info', 'low', 'infrastructure' ),
		'cron_overdue'            => array( 'health', true, 'warning', 'normal', 'infrastructure' ),
		'disk_space_low'          => array( 'health', true, 'warning', 'normal', 'infrastructure' ),
		// Content and users.
		'user_registered'         => array( 'content', true, 'info', 'low', 'customers' ),
		'comment_pending'         => array( 'content', true, 'info', 'low', 'other' ),
		'post_pending'            => array( 'content', true, 'info', 'normal', 'other' ),
		'post_published'          => array( 'content', false, 'success', 'low', 'other' ),
		// Forms.
		'form_cf7'                => array( 'forms', true, 'info', 'normal', 'customers' ),
		'form_wpforms'            => array( 'forms', true, 'info', 'normal', 'customers' ),
		'form_gravityforms'       => array( 'forms', true, 'info', 'normal', 'customers' ),
		'form_elementor'          => array( 'forms', true, 'info', 'normal', 'customers' ),
		'form_fluentforms'        => array( 'forms', true, 'info', 'normal', 'customers' ),
		'form_ninjaforms'         => array( 'forms', true, 'info', 'normal', 'customers' ),
		// WooCommerce.
		'woo_new_order'           => array( 'woocommerce', true, 'success', 'normal', 'sales' ),
		'woo_order_status'        => array( 'woocommerce', true, 'info', 'low', 'sales' ),
		'woo_payment_failed'      => array( 'woocommerce', true, 'warning', 'normal', 'payments' ),
		'woo_refund'              => array( 'woocommerce', true, 'warning', 'normal', 'payments' ),
		'woo_low_stock'           => array( 'woocommerce', true, 'warning', 'normal', 'sales' ),
		'woo_out_of_stock'        => array( 'woocommerce', true, 'error', 'high', 'sales' ),
		'woo_new_customer'        => array( 'woocommerce', true, 'success', 'low', 'customers' ),
		'woo_new_review'          => array( 'woocommerce', true, 'info', 'low', 'customers' ),
		'woo_subscription_failed' => array( 'woocommerce', true, 'error', 'high', 'payments' ),
		'woo_daily_summary'       => array( 'woocommerce', false, 'info', 'low', 'sales' ),
	);

	/**
	 * How each form plugin is detected: [ event id => constant or class it defines ].
	 */
	const FORM_PLUGINS = array(
		'form_cf7'          => 'WPCF7_VERSION',
		'form_wpforms'      => 'WPFORMS_VERSION',
		'form_gravityforms' => 'GFForms',
		'form_elementor'    => 'ELEMENTOR_PRO_VERSION',
		'form_fluentforms'  => 'FLUENTFORM_VERSION',
		'form_ninjaforms'   => 'Ninja_Forms',
	);

	/**
	 * Whether an event id exists.
	 *
	 * @param string $id Event id.
	 * @return bool
	 */
	public static function exists( $id ) {
		return isset( self::DEFAULTS[ $id ] );
	}

	/**
	 * Section of an event (security, health, content, forms, woocommerce).
	 *
	 * @param string $id Event id.
	 * @return string
	 */
	public static function section( $id ) {
		return self::exists( $id ) ? self::DEFAULTS[ $id ][0] : 'other';
	}

	/**
	 * Default settings of an event.
	 *
	 * @param string $id Event id.
	 * @return array{enabled: bool, severity: string, priority: string}
	 */
	public static function defaults( $id ) {
		$d = self::DEFAULTS[ $id ];
		return array(
			'enabled'  => (bool) $d[1],
			'severity' => $d[2],
			'priority' => $d[3],
		);
	}

	/**
	 * Honk category (taxonomy v1) of an event.
	 *
	 * @param string $id Event id.
	 * @return string
	 */
	public static function category( $id ) {
		return self::exists( $id ) ? self::DEFAULTS[ $id ][4] : 'other';
	}

	/**
	 * Whether the plugin an event depends on is active (WooCommerce, a form plugin…).
	 *
	 * @param string $id Event id.
	 * @return bool
	 */
	public static function is_available( $id ) {
		if ( isset( self::FORM_PLUGINS[ $id ] ) ) {
			$probe = self::FORM_PLUGINS[ $id ];
			return defined( $probe ) || class_exists( $probe );
		}
		if ( 'woo_subscription_failed' === $id ) {
			return class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_get_subscription' );
		}
		if ( 'woocommerce' === self::section( $id ) ) {
			return class_exists( 'WooCommerce' );
		}
		return true;
	}

	/**
	 * Translated section titles, in display order.
	 *
	 * @return array<string, string>
	 */
	public static function sections() {
		return array(
			'security'    => __( 'Security', 'honk-me' ),
			'health'      => __( 'Site health', 'honk-me' ),
			'content'     => __( 'Content & users', 'honk-me' ),
			'forms'       => __( 'Forms', 'honk-me' ),
			'woocommerce' => __( 'WooCommerce', 'honk-me' ),
		);
	}

	/**
	 * Translated label and description of an event (settings screen).
	 *
	 * @param string $id Event id.
	 * @return array{0: string, 1: string}
	 */
	public static function label( $id ) {
		switch ( $id ) {
			case 'admin_login_new_device':
				return array( __( 'Administrator signs in from a new device', 'honk-me' ), __( 'An account that can manage the site signs in from a new browser, device or network. Devices are recognized without storing IP addresses or browser details.', 'honk-me' ) );
			case 'login_failures_burst':
				return array( __( 'Many failed sign-ins', 'honk-me' ), __( 'Someone may be trying to guess a password. You’ll get an all-clear once it stops.', 'honk-me' ) );
			case 'new_administrator':
				return array( __( 'New administrator', 'honk-me' ), __( 'Someone was added as an administrator or given the Administrator role.', 'honk-me' ) );
			case 'role_changed':
				return array( __( 'User role changed', 'honk-me' ), __( 'Someone’s role was changed, added or removed.', 'honk-me' ) );
			case 'site_identity_changed':
				return array( __( 'Admin email or site address changed', 'honk-me' ), __( 'The administration email, the WordPress address or the site address changed.', 'honk-me' ) );
			case 'plugin_changed':
				return array( __( 'Plugin installed, activated, deactivated or deleted', 'honk-me' ), '' );
			case 'theme_changed':
				return array( __( 'Theme installed, switched or deleted', 'honk-me' ), '' );
			case 'file_edited':
				return array( __( 'Theme or plugin file edited in the dashboard', 'honk-me' ), '' );
			case 'updates_installed':
				return array( __( 'Updates installed', 'honk-me' ), __( 'WordPress, a plugin or a theme was updated, by hand or automatically.', 'honk-me' ) );
			case 'fatal_error':
				return array( __( 'Critical error', 'honk-me' ), __( 'A PHP error crashed a page and WordPress showed its “critical error” message. This doesn’t replace WordPress’s own email.', 'honk-me' ) );
			case 'site_health_critical':
				return array( __( 'Site Health critical issues', 'honk-me' ), __( 'Checked daily. You’ll get an all-clear once they’re fixed.', 'honk-me' ) );
			case 'auto_update_failed':
				return array( __( 'Automatic update failed', 'honk-me' ), '' );
			case 'updates_available':
				return array( __( 'Updates available', 'honk-me' ), __( 'Checked daily. One notification lists the WordPress, plugin and theme updates whenever the list changes.', 'honk-me' ) );
			case 'cron_overdue':
				return array( __( 'Scheduled tasks running late', 'honk-me' ), __( 'WordPress’s scheduled tasks (WP-Cron) are more than 30 minutes late.', 'honk-me' ) );
			case 'disk_space_low':
				return array( __( 'Disk almost full', 'honk-me' ), __( 'Less than 5% or 1 GB of disk space left. Only works if your host lets WordPress check it.', 'honk-me' ) );
			case 'user_registered':
				return array( __( 'New user registration', 'honk-me' ), '' );
			case 'comment_pending':
				return array( __( 'Comment awaiting moderation', 'honk-me' ), '' );
			case 'post_pending':
				return array( __( 'Post pending review', 'honk-me' ), '' );
			case 'post_published':
				return array( __( 'Post published', 'honk-me' ), '' );
			case 'form_cf7':
				return array( 'Contact Form 7', '' );
			case 'form_wpforms':
				return array( 'WPForms', '' );
			case 'form_gravityforms':
				return array( 'Gravity Forms', '' );
			case 'form_elementor':
				return array( 'Elementor Pro Forms', '' );
			case 'form_fluentforms':
				return array( 'Fluent Forms', '' );
			case 'form_ninjaforms':
				return array( 'Ninja Forms', '' );
			case 'woo_new_order':
				return array( __( 'New order', 'honk-me' ), __( 'When an order is paid or put on hold, just like WooCommerce’s “New order” email.', 'honk-me' ) );
			case 'woo_order_status':
				return array( __( 'Order status changed', 'honk-me' ), __( 'Other status changes, such as Completed or Cancelled.', 'honk-me' ) );
			case 'woo_payment_failed':
				return array( __( 'Failed payment', 'honk-me' ), '' );
			case 'woo_refund':
				return array( __( 'Refund', 'honk-me' ), '' );
			case 'woo_low_stock':
				return array( __( 'Low stock', 'honk-me' ), __( 'You’ll get an all-clear when it’s back in stock.', 'honk-me' ) );
			case 'woo_out_of_stock':
				return array( __( 'Out of stock', 'honk-me' ), __( 'You’ll get an all-clear when it’s back in stock.', 'honk-me' ) );
			case 'woo_new_customer':
				return array( __( 'New customer account', 'honk-me' ), '' );
			case 'woo_new_review':
				return array( __( 'New product review', 'honk-me' ), '' );
			case 'woo_subscription_failed':
				return array( __( 'Subscription renewal failed', 'honk-me' ), __( 'WooCommerce Subscriptions.', 'honk-me' ) );
			case 'woo_daily_summary':
				return array( __( 'Daily sales summary', 'honk-me' ), __( 'Yesterday’s orders and revenue, every morning at 8:00 site time.', 'honk-me' ) );
		}
		return array( $id, '' );
	}
}
