<?php
/**
 * Translations: the bundled languages and the language of notification texts.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the bundled translations and switches language while a notification text is written.
 *
 * Notification texts follow the site language (or the plugin's own language setting), not the
 * language of whoever happens to trigger the event, such as an administrator who browses the
 * dashboard in another language.
 */
final class Honk_I18n {

	const DOMAIN = 'honk';

	/**
	 * Registers the loaders.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'load_current' ), 1 );
		add_action( 'change_locale', array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads the translations of the current locale.
	 *
	 * @return void
	 */
	public static function load_current() {
		self::load( determine_locale() );
	}

	/**
	 * Loads the translations of a locale: a language pack from translate.wordpress.org when one is
	 * installed, otherwise the copy bundled in languages/.
	 *
	 * @param string $locale Locale, e.g. ro_RO.
	 * @return void
	 */
	public static function load( $locale ) {
		unload_textdomain( self::DOMAIN, true );
		$file = self::file( (string) $locale );
		if ( '' !== $file ) {
			load_textdomain( self::DOMAIN, $file, $locale );
		}
	}

	/**
	 * The .mo file for a locale, or '' when there is none.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function file( $locale ) {
		if ( '' === $locale || 'en_US' === $locale || ! preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', $locale ) ) {
			return '';
		}
		$candidates = array(
			WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . $locale . '.mo',
			HONK_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo',
		);
		foreach ( $candidates as $file ) {
			if ( is_readable( $file ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Runs a callback with the notification language active and returns its result.
	 *
	 * Uses switch_to_locale() when the site has that language installed (so WordPress and
	 * WooCommerce strings, such as order statuses, follow too); otherwise switches only the Honk
	 * strings.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function with_locale( $callback ) {
		$target  = Honk_Settings::notification_locale();
		$current = determine_locale();
		$loaded  = is_textdomain_loaded( self::DOMAIN );

		if ( $target === $current && ( $loaded || did_action( 'init' ) ) ) {
			return call_user_func( $callback );
		}

		$switched = did_action( 'after_setup_theme' ) && $target !== $current && switch_to_locale( $target );
		if ( ! $switched ) {
			self::load( $target );
		}
		try {
			return call_user_func( $callback );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			} elseif ( $loaded ) {
				self::load( $current );
			} else {
				unload_textdomain( self::DOMAIN, true );
			}
		}
	}
}
