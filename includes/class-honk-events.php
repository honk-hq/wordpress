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
			'security'    => __( 'Security', 'honk' ),
			'health'      => __( 'Site health', 'honk' ),
			'content'     => __( 'Content & users', 'honk' ),
			'forms'       => __( 'Forms', 'honk' ),
			'woocommerce' => __( 'WooCommerce', 'honk' ),
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
				return array( __( 'Administrator signs in from a new device', 'honk' ), __( 'An account that can manage the site signs in from a new browser, device or network. Devices are recognized without storing IP addresses or browser details.', 'honk' ) );
			case 'login_failures_burst':
				return array( __( 'Many failed sign-ins', 'honk' ), __( 'Someone may be trying to guess a password. You’ll get an all-clear once it stops.', 'honk' ) );
			case 'new_administrator':
				return array( __( 'New administrator', 'honk' ), __( 'Someone was added as an administrator or given the Administrator role.', 'honk' ) );
			case 'role_changed':
				return array( __( 'User role changed', 'honk' ), __( 'Someone’s role was changed, added or removed.', 'honk' ) );
			case 'site_identity_changed':
				return array( __( 'Admin email or site address changed', 'honk' ), __( 'The administration email, the WordPress address or the site address changed.', 'honk' ) );
			case 'plugin_changed':
				return array( __( 'Plugin installed, activated, deactivated or deleted', 'honk' ), '' );
			case 'theme_changed':
				return array( __( 'Theme installed, switched or deleted', 'honk' ), '' );
			case 'file_edited':
				return array( __( 'Theme or plugin file edited in the dashboard', 'honk' ), '' );
			case 'updates_installed':
				return array( __( 'Updates installed', 'honk' ), __( 'WordPress, a plugin or a theme was updated, by hand or automatically.', 'honk' ) );
			case 'fatal_error':
				return array( __( 'Critical error', 'honk' ), __( 'A PHP error crashed a page and WordPress showed its “critical error” message. This doesn’t replace WordPress’s own email.', 'honk' ) );
			case 'site_health_critical':
				return array( __( 'Site Health critical issues', 'honk' ), __( 'Checked daily. You’ll get an all-clear once they’re fixed.', 'honk' ) );
			case 'auto_update_failed':
				return array( __( 'Automatic update failed', 'honk' ), '' );
			case 'updates_available':
				return array( __( 'Updates available', 'honk' ), __( 'Checked daily. One notification lists the WordPress, plugin and theme updates whenever the list changes.', 'honk' ) );
			case 'cron_overdue':
				return array( __( 'Scheduled tasks running late', 'honk' ), __( 'WordPress’s scheduled tasks (WP-Cron) are more than 30 minutes late.', 'honk' ) );
			case 'disk_space_low':
				return array( __( 'Disk almost full', 'honk' ), __( 'Less than 5% or 1 GB of disk space left. Only works if your host lets WordPress check it.', 'honk' ) );
			case 'user_registered':
				return array( __( 'New user registration', 'honk' ), '' );
			case 'comment_pending':
				return array( __( 'Comment awaiting moderation', 'honk' ), '' );
			case 'post_pending':
				return array( __( 'Post pending review', 'honk' ), '' );
			case 'post_published':
				return array( __( 'Post published', 'honk' ), '' );
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
				return array( __( 'New order', 'honk' ), __( 'When an order is paid or put on hold, just like WooCommerce’s “New order” email.', 'honk' ) );
			case 'woo_order_status':
				return array( __( 'Order status changed', 'honk' ), __( 'Other status changes, such as Completed or Cancelled.', 'honk' ) );
			case 'woo_payment_failed':
				return array( __( 'Failed payment', 'honk' ), '' );
			case 'woo_refund':
				return array( __( 'Refund', 'honk' ), '' );
			case 'woo_low_stock':
				return array( __( 'Low stock', 'honk' ), __( 'You’ll get an all-clear when it’s back in stock.', 'honk' ) );
			case 'woo_out_of_stock':
				return array( __( 'Out of stock', 'honk' ), __( 'You’ll get an all-clear when it’s back in stock.', 'honk' ) );
			case 'woo_new_customer':
				return array( __( 'New customer account', 'honk' ), '' );
			case 'woo_new_review':
				return array( __( 'New product review', 'honk' ), '' );
			case 'woo_subscription_failed':
				return array( __( 'Subscription renewal failed', 'honk' ), __( 'WooCommerce Subscriptions.', 'honk' ) );
			case 'woo_daily_summary':
				return array( __( 'Daily sales summary', 'honk' ), __( 'Yesterday’s orders and revenue, every morning at 8:00 site time.', 'honk' ) );
		}
		return array( $id, '' );
	}
}
