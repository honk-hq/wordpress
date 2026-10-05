<?php
/**
 * Asynchronous delivery with retries: Action Scheduler when available, else WP-Cron.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queues messages and delivers them in the background, so a slow or unreachable Honk server
 * never slows a page load or a checkout.
 */
final class Honk_Queue {

	const HOOK = 'honk_deliver';

	const GROUP = 'honk';

	const JOB_PREFIX = 'honk_job_';

	const INDEX_OPTION = 'honk_jobs';

	/**
	 * Attempts per message: the first one and five retries.
	 */
	const MAX_ATTEMPTS = 6;

	/**
	 * No retry later than this after the first attempt: the server's idempotency window is 24 h,
	 * so a later retry could notify twice.
	 */
	const MAX_AGE = 82800;

	/**
	 * A Retry-After longer than this (a daily quota) is not waited for.
	 */
	const MAX_RETRY_AFTER = 21600;

	const RUN_ACTION = 'honk_run';

	/**
	 * Whether a WP-Cron event was scheduled in this request (spawn WP-Cron at shutdown).
	 *
	 * @var bool
	 */
	private static $spawn = false;

	/**
	 * Jobs to start right away through a loopback request (see push(), option "now").
	 *
	 * @var string[]
	 */
	private static $kick = array();

	/**
	 * Jobs queued in this request.
	 *
	 * @var string[]
	 */
	private static $pushed = array();

	/**
	 * Deliver this request's jobs before it ends, instead of in the background (set when Honk
	 * itself is being deactivated: its background delivery stops with it).
	 *
	 * @var bool
	 */
	private static $sync = false;

	/**
	 * Registers the delivery hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'deliver' ) );
		add_action( 'wp_ajax_' . self::RUN_ACTION, array( __CLASS__, 'run_from_loopback' ) );
		add_action( 'wp_ajax_nopriv_' . self::RUN_ACTION, array( __CLASS__, 'run_from_loopback' ) );
		add_action( 'shutdown', array( __CLASS__, 'shutdown' ), 50 );
	}

	/**
	 * Queues a message. Returns the job id, or false when the key was already sent.
	 *
	 * @param string $event_id Event id (for the log).
	 * @param array  $payload  Normalized payload (Honk_Payload::normalize()).
	 * @param string $key      Idempotency key.
	 * @param array  $options  pii (bool): the text may contain personal data; now (bool): start
	 *                         delivery through a loopback request instead of waiting for WP-Cron.
	 * @return string|false
	 */
	public static function push( $event_id, array $payload, $key, array $options = array() ) {
		if ( ! Honk_Dedupe::claim( $key ) ) {
			return false;
		}
		$job_id = str_replace( '-', '', wp_generate_uuid4() );
		$job    = array(
			'event'    => $event_id,
			'key'      => $key,
			'body'     => Honk_Payload::encode( $payload ),
			'attempts' => 0,
			'created'  => time(),
			'next'     => time(),
			'pii'      => ! empty( $options['pii'] ),
		);
		add_option( self::JOB_PREFIX . $job_id, $job, '', false );
		self::index( $job_id, true );

		Honk_Log::record(
			$job_id,
			array(
				'event' => $event_id,
				'title' => isset( $payload['title'] ) ? $payload['title'] : '',
				'pii'   => $job['pii'],
			)
		);

		self::$pushed[] = $job_id;
		if ( self::$sync ) {
			return $job_id;
		}
		if ( ! empty( $options['now'] ) ) {
			self::$kick[] = $job_id;
		}
		self::schedule( $job_id, 0 );
		return $job_id;
	}

	/**
	 * Delivers the jobs of this request at shutdown, waiting for the server (used only while Honk
	 * is being deactivated, an administrator's action).
	 *
	 * @return void
	 */
	public static function deliver_before_exit() {
		self::$sync = true;
	}

	/**
	 * Whether Action Scheduler is loaded and initialized.
	 *
	 * @return bool
	 */
	public static function action_scheduler_ready() {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! class_exists( 'ActionScheduler' ) ) {
			return false;
		}
		if ( method_exists( 'ActionScheduler', 'is_initialized' ) ) {
			return ActionScheduler::is_initialized();
		}
		return (bool) did_action( 'action_scheduler_init' );
	}

	/**
	 * Schedules a delivery attempt.
	 *
	 * @param string $job_id Job id.
	 * @param int    $delay  Seconds from now.
	 * @return void
	 */
	public static function schedule( $job_id, $delay ) {
		$delay = max( 0, (int) $delay );
		if ( self::action_scheduler_ready() ) {
			if ( 0 === $delay ) {
				as_enqueue_async_action( self::HOOK, array( $job_id ), self::GROUP );
				self::$spawn = true; // Action Scheduler's queue runs on WP-Cron: start it now.
			} else {
				as_schedule_single_action( time() + $delay, self::HOOK, array( $job_id ), self::GROUP );
			}
			return;
		}
		wp_schedule_single_event( time() + $delay, self::HOOK, array( $job_id ) );
		if ( 0 === $delay ) {
			self::$spawn = true;
		}
	}

	/**
	 * Delivers one job (the scheduled action's callback).
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public static function deliver( $job_id ) {
		$job_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $job_id ) );
		$job    = get_option( self::JOB_PREFIX . $job_id );
		if ( '' === $job_id || ! is_array( $job ) || empty( $job['body'] ) ) {
			return;
		}
		if ( ! Honk_Settings::is_configured() ) {
			self::finish( $job_id, $job, 'failed', array( 'code' => 'not_configured' ) );
			return;
		}

		++$job['attempts'];
		$result = Honk_Client::send( Honk_Settings::server_url(), Honk_Settings::api_key(), $job['body'], $job['key'] );
		$fields = array(
			'http'     => $result['status'],
			'code'     => $result['code'],
			'detail'   => $result['message'],
			'attempts' => $job['attempts'],
		);

		if ( $result['ok'] ) {
			$fields['message_id'] = $result['id'];
			self::finish( $job_id, $job, $result['duplicate'] ? 'duplicate' : 'sent', $fields );
			return;
		}

		$age = time() - (int) $job['created'];
		if ( $result['retryable'] && $job['attempts'] < self::MAX_ATTEMPTS && $result['retry_after'] <= self::MAX_RETRY_AFTER ) {
			$delay = self::backoff( $job['attempts'], $result['retry_after'] );
			if ( $age + $delay <= self::MAX_AGE ) {
				$job['next'] = time() + $delay;
				update_option( self::JOB_PREFIX . $job_id, $job, false );
				self::schedule( $job_id, $delay );
				$fields['status'] = 'retry';
				$fields['next']   = $job['next'];
				Honk_Log::record( $job_id, $fields );
				return;
			}
		}
		self::finish( $job_id, $job, 'failed', $fields );
	}

	/**
	 * Seconds before the next attempt: exponential backoff with jitter (15–30 s, 1–2 min, 4–8 min,
	 * 16–32 min, 30–60 min), never sooner than Retry-After.
	 *
	 * @param int $attempt     Attempts made so far (1-based).
	 * @param int $retry_after Server's Retry-After in seconds (0 if none).
	 * @return int
	 */
	public static function backoff( $attempt, $retry_after = 0 ) {
		$cap   = (int) min( HOUR_IN_SECONDS, 30 * pow( 4, max( 0, $attempt - 1 ) ) );
		$delay = wp_rand( (int) ( $cap / 2 ), $cap );
		return max( $delay, (int) $retry_after );
	}

	/**
	 * Ends a job: logs the outcome and deletes it.
	 *
	 * @param string $job_id Job id.
	 * @param array  $job    Job.
	 * @param string $status sent, duplicate or failed.
	 * @param array  $fields Log fields.
	 * @return void
	 */
	private static function finish( $job_id, array $job, $status, array $fields ) {
		$fields['status'] = $status;
		$fields['next']   = 0;
		Honk_Log::record( $job_id, $fields );
		delete_option( self::JOB_PREFIX . $job_id );
		self::index( $job_id, false );
	}

	/**
	 * Adds a job to, or removes it from, the index (used by maintenance and uninstall).
	 *
	 * @param string $job_id Job id.
	 * @param bool   $add    Add (true) or remove (false).
	 * @return void
	 */
	private static function index( $job_id, $add ) {
		$index = get_option( self::INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		if ( $add ) {
			$index[ $job_id ] = time();
		} else {
			unset( $index[ $job_id ] );
		}
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Daily: reschedules jobs whose scheduled action was lost (e.g. WooCommerce, and with it
	 * Action Scheduler, was deactivated) and drops jobs that are too old to send.
	 *
	 * @return void
	 */
	public static function maintenance() {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			delete_option( self::INDEX_OPTION );
			return;
		}
		foreach ( array_keys( $index ) as $job_id ) {
			$job = get_option( self::JOB_PREFIX . $job_id );
			if ( ! is_array( $job ) ) {
				self::index( $job_id, false );
				continue;
			}
			if ( time() - (int) $job['created'] > self::MAX_AGE ) {
				self::finish( $job_id, $job, 'failed', array( 'code' => 'expired' ) );
				continue;
			}
			if ( (int) $job['next'] < time() - 15 * MINUTE_IN_SECONDS && ! self::is_scheduled( $job_id ) ) {
				self::schedule( $job_id, 0 );
			}
		}
	}

	/**
	 * Whether a delivery of the job is scheduled.
	 *
	 * @param string $job_id Job id.
	 * @return bool
	 */
	private static function is_scheduled( $job_id ) {
		if ( wp_next_scheduled( self::HOOK, array( $job_id ) ) ) {
			return true;
		}
		return self::action_scheduler_ready() && function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::HOOK, array( $job_id ), self::GROUP );
	}

	/**
	 * At the end of the request: start WP-Cron (non-blocking loopback) for what was just queued,
	 * and the loopback runner for jobs that must not wait for WP-Cron.
	 *
	 * @return void
	 */
	public static function shutdown() {
		if ( self::$sync ) {
			foreach ( self::$pushed as $job_id ) {
				self::deliver( $job_id );
			}
			self::$pushed = array();
			return;
		}
		foreach ( self::$kick as $job_id ) {
			wp_remote_post(
				admin_url( 'admin-ajax.php' ),
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					/** This filter is documented in wp-includes/class-wp-http-streams.php */
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter for loopback requests, as spawn_cron() uses it.
					'body'      => array(
						'action' => self::RUN_ACTION,
						'job'    => $job_id,
						'token'  => self::token( $job_id ),
					),
				)
			);
		}
		self::$kick = array();

		if ( self::$spawn && ! wp_doing_cron() && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		self::$spawn = false;
	}

	/**
	 * Signed token for the loopback runner.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	private static function token( $job_id ) {
		return wp_hash( 'honk-run|' . $job_id, 'nonce' );
	}

	/**
	 * Loopback runner: delivers one job in its own request (used when WP-Cron is not running).
	 *
	 * @return void
	 */
	public static function run_from_loopback() {
		// Authenticated by an HMAC of the job id (wp_hash with the site's secret salts), since the
		// loopback request carries no user session and therefore no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$job_id = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' !== $job_id && '' !== $token && hash_equals( self::token( $job_id ), $token ) ) {
			self::deliver( $job_id );
		}
		wp_die( '', '', array( 'response' => 204 ) );
	}
}
