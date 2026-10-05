<?php
/**
 * Remembers recently sent event keys so a repeated hook never queues a second message.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * A 24-hour memory of idempotency keys (the server keeps the same window, contract §5).
 *
 * The server's Idempotency-Key handling is the safety net across requests; this local memory
 * also catches repeats whose text differs (which the server would answer with 409).
 */
final class Honk_Dedupe {

	const OPTION = 'honk_recent_keys';

	const WINDOW = DAY_IN_SECONDS;

	const MAX_KEYS = 2000;

	/**
	 * Keys claimed in this request.
	 *
	 * @var array<string, bool>
	 */
	private static $request = array();

	/**
	 * Whether a key was claimed within the last 24 hours.
	 *
	 * @param string $key Idempotency key.
	 * @return bool
	 */
	public static function seen( $key ) {
		$hash = md5( $key );
		if ( isset( self::$request[ $hash ] ) ) {
			return true;
		}
		$keys = self::load();
		return isset( $keys[ $hash ] ) && $keys[ $hash ] > time() - self::WINDOW;
	}

	/**
	 * Claims a key. Returns false if it was already claimed within 24 hours.
	 *
	 * @param string $key Idempotency key.
	 * @return bool
	 */
	public static function claim( $key ) {
		if ( self::seen( $key ) ) {
			return false;
		}
		$hash                   = md5( $key );
		self::$request[ $hash ] = true;

		$now  = time();
		$keys = self::load();
		foreach ( $keys as $h => $t ) {
			if ( $t <= $now - self::WINDOW ) {
				unset( $keys[ $h ] );
			}
		}
		$keys[ $hash ] = $now;
		if ( count( $keys ) > self::MAX_KEYS ) {
			asort( $keys );
			$keys = array_slice( $keys, -self::MAX_KEYS, null, true );
		}
		update_option( self::OPTION, $keys, false );
		return true;
	}

	/**
	 * Forgets everything claimed in this request (tests).
	 *
	 * @return void
	 */
	public static function reset_request() {
		self::$request = array();
	}

	/**
	 * Stored keys: md5 => time.
	 *
	 * @return array<string, int>
	 */
	private static function load() {
		$keys = get_option( self::OPTION, array() );
		return is_array( $keys ) ? $keys : array();
	}
}
