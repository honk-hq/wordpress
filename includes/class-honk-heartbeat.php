<?php
/**
 * Heartbeat: the site checks in every 5 minutes, so Honk can honk when it stops.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks in with POST /v1/heartbeats/check-in (contracts/API.md §11) under a slug made from the
 * site's address. The first check-in creates the heartbeat in the key's project; later ones send
 * only the slug, so what is changed in Honk! Honk! (name, interval, grace) stays. The option shows
 * once GET /v1/config reports "heartbeats": true.
 */
final class Honk_Heartbeat {

	const HOOK = 'honk_heartbeat';

	const SCHEDULE = 'honk_five_minutes';

	const INTERVAL = 300;

	/**
	 * Seconds a check-in may be late before Honk! Honk! opens a problem.
	 */
	const GRACE = 600;

	/**
	 * Path of the check-in endpoint (authenticated with the ingestion key).
	 */
	const ENDPOINT = '/v1/heartbeats/check-in';

	/**
	 * Registers the schedule and the check-in.
	 *
	 * @return void
	 */
	public static function register() {
		// A check-in every 5 minutes is the feature (WordPress.WP.CronInterval prefers 15 minutes or more).
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK, array( __CLASS__, 'check_in' ) );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ), 20 );
	}

	/**
	 * Adds the 5-minute schedule used when Action Scheduler is not available.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL,
			// Translated only once translations may load (some plugins read schedules very early).
			'display'  => did_action( 'init' ) ? __( 'Every 5 minutes (Honk! Honk! heartbeat)', 'honk-me' ) : 'Every 5 minutes (Honk! Honk! heartbeat)',
		);
		return $schedules;
	}

	/**
	 * Whether the heartbeat should run: switched on, a key saved, and the server not known to
	 * lack heartbeats. The cached GET /v1/config lasts 12 hours; while nothing is cached the
	 * heartbeat keeps running, and the next check-in (in the background) asks the server again.
	 *
	 * @param bool $fetch Ask the server when nothing is cached (never on a page load).
	 * @return bool
	 */
	public static function is_active( $fetch = false ) {
		return Honk_Settings::is_configured()
			&& (bool) Honk_Settings::get( 'heartbeat' )
			&& false !== Honk_Client::server_capability( Honk_Settings::server_url(), 'heartbeats', $fetch );
	}

	/**
	 * Whether the server reports heartbeats.
	 *
	 * @param bool $fetch Ask the server when nothing is cached.
	 * @return bool
	 */
	public static function server_supports( $fetch = true ) {
		return Honk_Client::server_supports( Honk_Settings::server_url(), 'heartbeats', $fetch );
	}

	/**
	 * Keeps the recurring check-in scheduled exactly while the heartbeat is active. A small
	 * autoloaded flag avoids touching the schedulers on every request.
	 *
	 * @return void
	 */
	public static function sync_schedule() {
		$active = self::is_active();
		if ( (bool) get_option( 'honk_heartbeat_scheduled', false ) === $active ) {
			return;
		}
		wp_unschedule_hook( self::HOOK );
		if ( Honk_Queue::action_scheduler_ready() ) {
			as_unschedule_all_actions( self::HOOK, array(), Honk_Queue::GROUP );
		}
		if ( $active ) {
			if ( Honk_Queue::action_scheduler_ready() ) {
				as_schedule_recurring_action( time() + 60, self::INTERVAL, self::HOOK, array(), Honk_Queue::GROUP );
			} else {
				wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
			}
		}
		update_option( 'honk_heartbeat_scheduled', $active, true );
	}

	/**
	 * Checks in with Honk. This is the one place that talks to the heartbeat endpoint: the slug
	 * alone, and when Honk! Honk! doesn't know it (the first check-in, or the heartbeat was
	 * deleted there), once more with the name, interval and grace that create it.
	 *
	 * @return array|null Client result, or null when the heartbeat is not active.
	 */
	public static function check_in() {
		if ( ! self::is_active( true ) ) {
			return null;
		}
		$slug   = self::slug();
		$result = self::post(
			array(
				'slug'   => $slug,
				'status' => 'ok',
			)
		);
		if ( 404 === $result['status'] && 'not_found' === $result['code'] ) {
			$result = self::post(
				array(
					'slug'             => $slug,
					'name'             => self::name(),
					'interval_seconds' => self::INTERVAL,
					'grace_seconds'    => self::GRACE,
					'status'           => 'ok',
				)
			);
		}
		update_option(
			'honk_heartbeat_last',
			array(
				'time' => time(),
				'ok'   => $result['ok'],
				'code' => $result['code'],
			),
			false
		);
		return $result;
	}

	/**
	 * The heartbeat's slug: "wp-" and the site's address (host, port, path) in [a-z0-9-], the
	 * same on every check-in. An address too long for 64 characters is cut and ends with a hash
	 * of the whole address.
	 *
	 * @return string
	 */
	public static function slug() {
		$source = strtolower( Honk_Settings::source() );
		$slug   = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $source ), '-' );
		if ( strlen( $slug ) > 61 ) {
			$slug = rtrim( substr( $slug, 0, 52 ), '-' ) . '-' . substr( md5( $source ), 0, 8 );
		}
		return 'wp-' . ( '' !== $slug ? $slug : 'site' );
	}

	/**
	 * The heartbeat's name when it is created: the site's address (at most 100 characters).
	 *
	 * @return string
	 */
	public static function name() {
		return Honk_Payload::truncate_chars( Honk_Settings::source(), 100 );
	}

	/**
	 * One check-in request.
	 *
	 * @param array $fields JSON body.
	 * @return array Client result.
	 */
	private static function post( array $fields ) {
		$response = wp_remote_post(
			Honk_Settings::server_url() . self::ENDPOINT,
			array(
				'timeout'     => Honk_Client::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => Honk_Client::user_agent(),
				'headers'     => array(
					'Authorization' => 'Bearer ' . Honk_Settings::api_key(),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => Honk_Payload::encode( $fields ),
				'data_format' => 'body',
			)
		);
		return Honk_Client::interpret( $response );
	}
}
