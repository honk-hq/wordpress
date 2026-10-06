<?php
/**
 * Heartbeat: the site checks in every 5 minutes, so Honk can honk when it stops.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Heartbeats need server support that Honk does not have yet. The module stays off until
 * GET /v1/config reports "heartbeats": true; the check-in is one function (check_in()), so wiring
 * it to the final endpoint is a one-line change.
 */
final class Honk_Heartbeat {

	const HOOK = 'honk_heartbeat';

	const SCHEDULE = 'honk_five_minutes';

	const INTERVAL = 300;

	/**
	 * Path of the check-in endpoint (provisional until the server ships heartbeats).
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
			'display'  => did_action( 'init' ) ? __( 'Every 5 minutes (Honk heartbeat)', 'honk-me' ) : 'Every 5 minutes (Honk heartbeat)',
		);
		return $schedules;
	}

	/**
	 * Whether the heartbeat should run: switched on, a key saved, and the server supports it
	 * (cached GET /v1/config; never fetched on a page load).
	 *
	 * @return bool
	 */
	public static function is_active() {
		return Honk_Settings::is_configured()
			&& (bool) Honk_Settings::get( 'heartbeat' )
			&& self::server_supports( false );
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
	 * Checks in with Honk. This is the one place that talks to the heartbeat endpoint.
	 *
	 * @return array|null Client result, or null when the heartbeat is not active.
	 */
	public static function check_in() {
		if ( ! self::is_active() ) {
			return null;
		}
		$body     = Honk_Payload::encode(
			array(
				'name'             => 'wordpress',
				'source'           => Honk_Settings::source(),
				'environment'      => Honk_Settings::environment(),
				'interval_seconds' => self::INTERVAL,
				'grace_seconds'    => 2 * self::INTERVAL,
			)
		);
		$response = wp_remote_post(
			Honk_Settings::server_url() . self::ENDPOINT,
			array(
				'timeout'     => Honk_Client::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => Honk_Client::user_agent(),
				'headers'     => array(
					'Authorization' => 'Bearer ' . Honk_Settings::api_key(),
					'Content-Type'  => 'application/json',
				),
				'body'        => $body,
				'data_format' => 'body',
			)
		);
		$result   = Honk_Client::interpret( $response );
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
}
