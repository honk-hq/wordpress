<?php
/**
 * The language of notification texts.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes notification texts in the notification language.
 *
 * The plugin ships no translation files: WordPress downloads the plugin's translations from
 * translate.wordpress.org (language packs) for the languages installed on the site and loads them
 * by itself. Notification texts follow the site language (or the plugin's own language setting),
 * not the language of whoever happens to trigger the event, such as an administrator who browses
 * the dashboard in another language.
 */
final class Honk_I18n {

	const DOMAIN = 'honk-me';

	/**
	 * Runs a callback with the notification language active and returns its result.
	 *
	 * Uses switch_to_locale(), so WordPress, WooCommerce and Honk Me strings (order statuses
	 * included) all follow. It works for any language installed on the site; for another one, or
	 * before WordPress can switch languages (very early in a request), the text is written in the
	 * current language.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function with_locale( $callback ) {
		$target = Honk_Settings::notification_locale();
		if ( determine_locale() === $target || ! did_action( 'after_setup_theme' ) || ! function_exists( 'switch_to_locale' ) ) {
			return call_user_func( $callback );
		}
		$switched = switch_to_locale( $target );
		try {
			return call_user_func( $callback );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Languages the notifications can be written in: English and every language installed on the
	 * site, with their names in their own language when WordPress knows them.
	 *
	 * @return array<string, string> Locale => name.
	 */
	public static function languages() {
		$known     = array(
			'en_US' => 'English (United States)',
			'ro_RO' => 'Română',
			'es_ES' => 'Español',
			'fr_FR' => 'Français',
			'de_DE' => 'Deutsch',
		);
		$available = get_site_transient( 'available_translations' );
		$out       = array( 'en_US' => $known['en_US'] );
		foreach ( (array) get_available_languages() as $locale ) {
			if ( is_array( $available ) && isset( $available[ $locale ]['native_name'] ) ) {
				$out[ $locale ] = (string) $available[ $locale ]['native_name'];
			} else {
				$out[ $locale ] = isset( $known[ $locale ] ) ? $known[ $locale ] : $locale;
			}
		}
		return $out;
	}

	/**
	 * Whether a string is a well-formed WordPress locale (en_US, de_DE_formal, pt_BR…).
	 *
	 * @param string $locale Locale.
	 * @return bool
	 */
	public static function is_locale( $locale ) {
		return (bool) preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', (string) $locale );
	}
}
