<?php
/**
 * The heartbeat: POST /v1/heartbeats/check-in as the server takes it (contracts/API.md §11),
 * and a schedule that outlives the cached GET /v1/config.
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey\Functions;

class HeartbeatTest extends Honk_Test_Case {

	/**
	 * The fields POST /v1/heartbeats/check-in accepts with an ingestion key (the server refuses
	 * unknown fields, and severity and paused).
	 */
	const ACCEPTED = array( 'slug', 'name', 'interval_seconds', 'grace_seconds', 'status', 'message' );

	/** @var array<int, string> URLs asked with wp_remote_get. */
	private $gets = array();

	/** @var array<int, string> Hooks unscheduled. */
	private $unscheduled = array();

	protected function setUp(): void {
		parent::setUp();
		$this->configure( array( 'heartbeat' => true ) );
		$this->supported( true );
		$self = $this;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) use ( $self ) {
				$self->gets[] = $url;
				return $self->response( 200, array( 'name' => 'Honk', 'heartbeats' => true ) );
			}
		);
		Functions\when( 'wp_unschedule_hook' )->alias(
			function ( $hook ) use ( $self ) {
				$self->unscheduled[] = $hook;
				$self->cron          = array_values(
					array_filter(
						$self->cron,
						function ( $event ) use ( $hook ) {
							return $event['hook'] !== $hook;
						}
					)
				);
				return 0;
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $time, $recurrence, $hook ) use ( $self ) {
				$self->cron[] = array(
					'time'       => $time,
					'hook'       => $hook,
					'args'       => array(),
					'recurrence' => $recurrence,
				);
				return true;
			}
		);
	}

	/**
	 * Caches GET /v1/config: heartbeats true or false, or nothing (expired) for null.
	 *
	 * @param bool|null $heartbeats
	 */
	private function supported( $heartbeats ) {
		$name = Honk_Client::CONFIG_TRANSIENT . md5( 'https://honk.example.com' );
		if ( null === $heartbeats ) {
			unset( $this->transients[ $name ] );
			return;
		}
		$this->transients[ $name ] = $heartbeats ? array( 'name' => 'Honk', 'heartbeats' => true ) : array( 'name' => 'Honk' );
	}

	private function body( $i ) {
		return json_decode( $this->requests[ $i ]['args']['body'], true );
	}

	private function accepted( array $body ) {
		$this->assertSame( array(), array_diff( array_keys( $body ), self::ACCEPTED ), 'only fields the server accepts' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]{1,64}$/', $body['slug'] );
	}

	public function test_the_first_check_in_creates_the_heartbeat() {
		$this->responses[] = $this->response( 404, array( 'error' => array( 'code' => 'not_found', 'message' => 'Not found' ) ) );
		$this->responses[] = $this->response( 202, array( 'heartbeat_id' => 'hbt_1', 'status' => 'up' ) );

		$result = Honk_Heartbeat::check_in();

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 2, $this->requests );
		foreach ( $this->requests as $i => $request ) {
			$this->assertSame( 'https://honk.example.com/v1/heartbeats/check-in', $request['url'] );
			$this->assertSame( 'Bearer honk_ab12cd34_secretsecretsecret', $request['args']['headers']['Authorization'] );
			$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
			$this->assertSame( 0, $request['args']['redirection'] );
			$this->accepted( $this->body( $i ) );
		}
		$this->assertSame( array( 'slug' => 'wp-shop-example-com', 'status' => 'ok' ), $this->body( 0 ) );
		$this->assertSame(
			array(
				'slug'             => 'wp-shop-example-com',
				'name'             => 'shop.example.com',
				'interval_seconds' => 300,
				'grace_seconds'    => 600,
				'status'           => 'ok',
			),
			$this->body( 1 )
		);
		$this->assertTrue( $this->options['honk_heartbeat_last']['ok'] );
		$this->assertSame( array(), $this->gets, 'the cached config is enough' );
	}

	public function test_later_check_ins_send_only_the_slug_so_changes_made_in_honk_stay() {
		$this->responses[] = $this->response( 202, array( 'heartbeat_id' => 'hbt_1', 'status' => 'up' ) );

		$this->assertTrue( Honk_Heartbeat::check_in()['ok'] );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( array( 'slug' => 'wp-shop-example-com', 'status' => 'ok' ), $this->body( 0 ) );
	}

	public function test_a_refused_creation_is_noted_and_not_retried() {
		$this->responses[] = $this->response( 404, array( 'error' => array( 'code' => 'not_found', 'message' => 'Not found' ) ) );
		$this->responses[] = $this->response( 403, array( 'error' => array( 'code' => 'plan_limit', 'message' => 'Plan limit', 'limit' => 'max_heartbeats' ) ) );

		$result = Honk_Heartbeat::check_in();

		$this->assertFalse( $result['ok'] );
		$this->assertCount( 2, $this->requests );
		$this->assertFalse( $this->options['honk_heartbeat_last']['ok'] );
		$this->assertSame( 'plan_limit', $this->options['honk_heartbeat_last']['code'] );
	}

	public function test_the_slug_is_the_sites_address_and_always_valid() {
		$this->assertSame( 'wp-shop-example-com', Honk_Heartbeat::slug() );
		$this->assertSame( Honk_Heartbeat::slug(), Honk_Heartbeat::slug() );

		Functions\when( 'home_url' )->justReturn( 'http://Shop.Example.COM:8080/blog/' );
		$this->assertSame( 'wp-shop-example-com-8080-blog', Honk_Heartbeat::slug() );
		$this->assertSame( 'Shop.Example.COM:8080/blog', Honk_Heartbeat::name() );

		$long = 'https://shop.example.com/' . str_repeat( 'very-long-subdirectory/', 6 );
		Functions\when( 'home_url' )->justReturn( $long . 'a' );
		$a = Honk_Heartbeat::slug();
		Functions\when( 'home_url' )->justReturn( $long . 'b' );
		$b = Honk_Heartbeat::slug();
		foreach ( array( $a, $b ) as $slug ) {
			$this->assertMatchesRegularExpression( '/^wp-shop-example-com-very-long-[a-z0-9-]*-[0-9a-f]{8}$/', $slug );
			$this->assertLessThanOrEqual( 64, strlen( $slug ) );
		}
		$this->assertNotSame( $a, $b, 'a hash of the whole address keeps long addresses apart' );
		$this->assertLessThanOrEqual( 100, mb_strlen( Honk_Heartbeat::name() ) );
	}

	public function test_nothing_is_sent_while_off_without_a_key_or_when_the_server_lacks_heartbeats() {
		$this->configure( array( 'heartbeat' => false ) );
		$this->assertNull( Honk_Heartbeat::check_in() );

		$this->configure( array( 'heartbeat' => true, 'api_key' => '' ) );
		$this->assertNull( Honk_Heartbeat::check_in() );

		$this->configure( array( 'heartbeat' => true ) );
		$this->supported( false );
		$this->assertNull( Honk_Heartbeat::check_in() );

		$this->assertCount( 0, $this->requests );
	}

	public function test_a_check_in_asks_the_server_again_once_the_cached_config_expired() {
		$this->supported( null );
		$this->responses[] = $this->response( 202, array( 'heartbeat_id' => 'hbt_1', 'status' => 'up' ) );

		$this->assertTrue( Honk_Heartbeat::check_in()['ok'] );

		$this->assertSame( array( 'https://honk.example.com/v1/config' ), $this->gets );
		$this->assertTrue( Honk_Heartbeat::server_supports( false ), 'cached again' );
	}

	public function test_a_check_in_still_goes_out_when_the_config_cannot_be_read() {
		$this->supported( null );
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'timeout' ) );
		$this->responses[] = $this->response( 202, array( 'heartbeat_id' => 'hbt_1', 'status' => 'up' ) );

		$this->assertTrue( Honk_Heartbeat::check_in()['ok'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_schedule_follows_the_setting_and_outlives_the_cached_config() {
		Honk_Heartbeat::sync_schedule();
		$this->assertCount( 1, $this->cron );
		$this->assertSame( 'honk_heartbeat', $this->cron[0]['hook'] );
		$this->assertSame( 'honk_five_minutes', $this->cron[0]['recurrence'] );
		$this->assertTrue( $this->options['honk_heartbeat_scheduled'] );

		// 12 hours later the cached GET /v1/config is gone; a page load never asks the server.
		$this->unscheduled = array();
		$this->supported( null );
		Honk_Heartbeat::sync_schedule();
		$this->assertCount( 1, $this->cron, 'still scheduled' );
		$this->assertSame( array(), $this->unscheduled );
		$this->assertSame( array(), $this->gets );

		// A server without heartbeats (e.g. another server URL) stops it.
		$this->supported( false );
		Honk_Heartbeat::sync_schedule();
		$this->assertCount( 0, $this->cron );
		$this->assertFalse( $this->options['honk_heartbeat_scheduled'] );

		// So does switching it off.
		$this->supported( true );
		Honk_Heartbeat::sync_schedule();
		$this->assertCount( 1, $this->cron );
		$this->configure( array( 'heartbeat' => false ) );
		Honk_Heartbeat::sync_schedule();
		$this->assertCount( 0, $this->cron );
		$this->assertFalse( $this->options['honk_heartbeat_scheduled'] );
	}
}
