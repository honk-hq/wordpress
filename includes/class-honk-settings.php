<?php
/**
 * Settings: storage, defaults and sanitizing.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `honk_settings` option.
 */
final class Honk_Settings {

	const OPTION = 'honk_settings';

	const SITE_ID_OPTION = 'honk_site_id';

	const DEFAULT_SERVER = 'https://honk-me.app';

	const SEVERITIES = array( 'info', 'success', 'warning', 'error', 'critical' );

	const PRIORITIES = array( 'low', 'normal', 'high', 'urgent' );

	const ENVIRONMENTS = array( 'auto', 'production', 'staging', 'development' );

	/**
	 * Languages the plugin ships translations for (plus English).
	 */
	const LANGUAGES = array( 'en_US', 'ro_RO', 'es_ES', 'fr_FR', 'de_DE' );

	/**
	 * Per-request cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Registers the setting and keeps the cache fresh.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_setting' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			'honk',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Clears the per-request cache.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'server_url'      => self::DEFAULT_SERVER,
			'api_key'         => '',
			'environment'     => 'auto',
			'include_pii'     => false,
			'language'        => '',
			'burst_threshold' => 10,
			'burst_minutes'   => 5,
			'heartbeat'       => false,
			'events'          => array(),
			'details'         => array(),
		);
	}

	/**
	 * All settings, merged with the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
			foreach ( array( 'events', 'details' ) as $list ) {
				if ( ! is_array( self::$cache[ $list ] ) ) {
					self::$cache[ $list ] = array();
				}
			}
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $name Setting name.
	 * @return mixed
	 */
	public static function get( $name ) {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Settings of one event: enabled, severity, priority.
	 *
	 * @param string $id Event id.
	 * @return array{enabled: bool, severity: string, priority: string}
	 */
	public static function event( $id ) {
		$defaults = Honk_Events::defaults( $id );
		$events   = self::get( 'events' );
		$stored   = isset( $events[ $id ] ) && is_array( $events[ $id ] ) ? $events[ $id ] : array();
		$merged   = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );

		$merged['enabled'] = (bool) $merged['enabled'];
		if ( ! in_array( $merged['severity'], self::SEVERITIES, true ) ) {
			$merged['severity'] = $defaults['severity'];
		}
		if ( ! in_array( $merged['priority'], self::PRIORITIES, true ) ) {
			$merged['priority'] = $defaults['priority'];
		}
		return $merged;
	}

	/**
	 * Whether an event is switched on (and the plugin it needs is active).
	 *
	 * @param string $id Event id.
	 * @return bool
	 */
	public static function is_enabled( $id ) {
		if ( ! Honk_Events::exists( $id ) ) {
			return false;
		}
		$event = self::event( $id );
		return $event['enabled'] && Honk_Events::is_available( $id );
	}

	/**
	 * Whether an ingestion key is saved. Nothing is ever sent before that.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::api_key() && '' !== self::server_url();
	}

	/**
	 * The server URL without a trailing slash.
	 *
	 * @return string
	 */
	public static function server_url() {
		return untrailingslashit( (string) self::get( 'server_url' ) );
	}

	/**
	 * The ingestion key.
	 *
	 * @return string
	 */
	public static function api_key() {
		return (string) self::get( 'api_key' );
	}

	/**
	 * The key as it may be shown: its public prefix and bullets.
	 *
	 * @param string $key Ingestion key.
	 * @return string
	 */
	public static function mask_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		if ( preg_match( '/^(honk_[A-Za-z0-9]+_)/', $key, $m ) ) {
			return $m[1] . str_repeat( '•', 8 );
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', 8 );
	}

	/**
	 * Whether customer names and emails may appear in messages.
	 *
	 * @return bool
	 */
	public static function include_pii() {
		return (bool) self::get( 'include_pii' );
	}

	/**
	 * The Honk environment: the setting, or derived from wp_get_environment_type().
	 *
	 * @return string
	 */
	public static function environment() {
		$env = (string) self::get( 'environment' );
		if ( 'auto' !== $env && in_array( $env, self::ENVIRONMENTS, true ) ) {
			return $env;
		}
		return self::detected_environment();
	}

	/**
	 * The environment WordPress reports, in Honk's words.
	 *
	 * @return string
	 */
	public static function detected_environment() {
		$type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		switch ( $type ) {
			case 'staging':
				return 'staging';
			case 'development':
			case 'local':
				return 'development';
		}
		return 'production';
	}

	/**
	 * Locale of notification texts: the plugin setting, or the site language.
	 *
	 * @return string
	 */
	public static function notification_locale() {
		$language = (string) self::get( 'language' );
		if ( '' !== $language && in_array( $language, self::LANGUAGES, true ) ) {
			return $language;
		}
		$locale = get_locale();
		return $locale ? $locale : 'en_US';
	}

	/**
	 * The message source: the site's host (and path on sub-directory installs).
	 *
	 * @return string
	 */
	public static function source() {
		$url  = home_url();
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$src  = $host . ( $port ? ':' . $port : '' ) . ( '' !== $path ? '/' . $path : '' );
		return '' !== $src ? $src : 'WordPress';
	}

	/**
	 * A random id for this site, used to scope idempotency keys.
	 *
	 * @return string
	 */
	public static function site_id() {
		$id = get_option( self::SITE_ID_OPTION );
		if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9]{8}$/', $id ) ) {
			$id = strtolower( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 ) );
			update_option( self::SITE_ID_OPTION, $id, true );
		}
		return $id;
	}

	/**
	 * Whether a server URL is acceptable: https, or http for a local or private host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_valid_server_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		if ( 'https' === $parts['scheme'] ) {
			return true;
		}
		return 'http' === $parts['scheme'] && self::is_local_host( $parts['host'] );
	}

	/**
	 * Whether a host is local or private (http is allowed there, for development).
	 *
	 * @param string $host Host name or IP.
	 * @return bool
	 */
	public static function is_local_host( $host ) {
		$host = strtolower( trim( $host, '[]' ) );
		if ( in_array( $host, array( 'localhost', 'host.docker.internal', '::1' ), true ) ) {
			return true;
		}
		if ( preg_match( '/\.(localhost|local|test|internal)$/', $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}

	/**
	 * Sanitizes the settings form. Fields that are not posted keep their stored value.
	 *
	 * @param mixed $input Posted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old   = self::all();
		$input = is_array( $input ) ? $input : array();
		$out   = $old;

		if ( isset( $input['server_url'] ) ) {
			$url = untrailingslashit( esc_url_raw( trim( (string) $input['server_url'] ), array( 'https', 'http' ) ) );
			if ( '' === $url ) {
				$out['server_url'] = self::DEFAULT_SERVER;
			} elseif ( self::is_valid_server_url( $url ) ) {
				$out['server_url'] = $url;
			} else {
				add_settings_error( self::OPTION, 'server_url', __( 'The server URL must start with https:// (http:// works only for local addresses). Your previous URL was kept.', 'honk' ) );
			}
		}

		if ( ! empty( $input['remove_key'] ) ) {
			$out['api_key'] = '';
		} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			$key = trim( sanitize_text_field( (string) $input['api_key'] ) );
			if ( self::is_valid_key( $key ) ) {
				$out['api_key'] = $key;
			} else {
				add_settings_error( self::OPTION, 'api_key', __( 'That doesn’t look like a Honk API key (they start with honk_). Your previous key was kept.', 'honk' ) );
			}
		}

		if ( isset( $input['environment'] ) ) {
			$env                = sanitize_key( (string) $input['environment'] );
			$out['environment'] = in_array( $env, self::ENVIRONMENTS, true ) ? $env : 'auto';
		}

		if ( isset( $input['language'] ) ) {
			$lang            = (string) $input['language'];
			$out['language'] = in_array( $lang, self::LANGUAGES, true ) ? $lang : '';
		}

		if ( isset( $input['burst_threshold'] ) ) {
			$out['burst_threshold'] = max( 3, min( 1000, absint( $input['burst_threshold'] ) ) );
		}
		if ( isset( $input['burst_minutes'] ) ) {
			$out['burst_minutes'] = max( 1, min( 120, absint( $input['burst_minutes'] ) ) );
		}

		// Checkboxes: the form posts a marker so unchecked boxes can be told apart from absent ones.
		if ( isset( $input['_form'] ) ) {
			$out['include_pii'] = ! empty( $input['include_pii'] );
			$out['heartbeat']   = ! empty( $input['heartbeat'] );
			$events             = isset( $input['events'] ) && is_array( $input['events'] ) ? $input['events'] : array();
			$out['events']      = self::sanitize_events( $events, $old['events'] );
			$details            = isset( $input['details'] ) && is_array( $input['details'] ) ? $input['details'] : array();
			$out['details']     = Honk_Details::sanitize( $details, $old['details'] );
		} else {
			// An already sanitized array (WordPress sanitizes again when it first adds the option).
			if ( array_key_exists( 'include_pii', $input ) ) {
				$out['include_pii'] = (bool) $input['include_pii'];
			}
			if ( array_key_exists( 'heartbeat', $input ) ) {
				$out['heartbeat'] = (bool) $input['heartbeat'];
			}
			if ( isset( $input['events'] ) && is_array( $input['events'] ) ) {
				$out['events'] = self::sanitize_events( $input['events'], array() );
			}
			if ( isset( $input['details'] ) && is_array( $input['details'] ) ) {
				$out['details'] = Honk_Details::sanitize( $input['details'], array() );
			}
		}

		self::flush();
		return $out;
	}

	/**
	 * Sanitizes the per-event rows.
	 *
	 * @param array $posted Posted rows: id => [ enabled, severity, priority ].
	 * @param array $stored Stored rows.
	 * @return array
	 */
	public static function sanitize_events( array $posted, array $stored ) {
		$out = array();
		foreach ( array_keys( Honk_Events::DEFAULTS ) as $id ) {
			$row = isset( $posted[ $id ] ) && is_array( $posted[ $id ] ) ? $posted[ $id ] : null;
			if ( null === $row || ! isset( $row['severity'] ) ) {
				// Not on the form (e.g. a form plugin that is not active): keep what was stored.
				if ( isset( $stored[ $id ] ) ) {
					$out[ $id ] = $stored[ $id ];
				}
				continue;
			}
			$defaults   = Honk_Events::defaults( $id );
			$severity   = sanitize_key( (string) $row['severity'] );
			$priority   = sanitize_key( isset( $row['priority'] ) ? (string) $row['priority'] : '' );
			$out[ $id ] = array(
				'enabled'  => ! empty( $row['enabled'] ),
				'severity' => in_array( $severity, self::SEVERITIES, true ) ? $severity : $defaults['severity'],
				'priority' => in_array( $priority, self::PRIORITIES, true ) ? $priority : $defaults['priority'],
			);
		}
		return $out;
	}

	/**
	 * Whether a string looks like an ingestion key: honk_<id>_<secret>, printable ASCII.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return (bool) preg_match( '/^honk_[A-Za-z0-9]+_[\x21-\x7E]{8,}$/', $key ) && strlen( $key ) <= 256;
	}

	/**
	 * Translated name of a severity on the Honk scale.
	 *
	 * @param string $severity Canonical severity.
	 * @return string
	 */
	public static function severity_label( $severity ) {
		switch ( $severity ) {
			case 'info':
				return __( 'Light honk', 'honk' );
			case 'success':
				return __( 'Beep-beep', 'honk' );
			case 'warning':
				return __( 'Loud honk', 'honk' );
			case 'error':
				return __( 'Long honk', 'honk' );
			case 'critical':
				return __( 'Blast', 'honk' );
		}
		return $severity;
	}

	/**
	 * Translated name of a priority.
	 *
	 * @param string $priority Priority.
	 * @return string
	 */
	public static function priority_label( $priority ) {
		switch ( $priority ) {
			case 'low':
				return __( 'Low', 'honk' );
			case 'normal':
				return __( 'Normal', 'honk' );
			case 'high':
				return __( 'High', 'honk' );
			case 'urgent':
				return __( 'Urgent', 'honk' );
		}
		return $priority;
	}
}
