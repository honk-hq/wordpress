<?php
/**
 * Background delivery: queueing, retries with backoff, Retry-After, no retry on 4xx, dedupe.
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey\Functions;

class QueueTest extends Honk_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		$this->configure();
		Functions\when( 'wp_doing_cron' )->justReturn( false );
	}

	private function push( $key = 'k-1' ) {
		return Honk_Queue::push( 'woo_new_order', Honk_Payload::normalize( array( 'title' => 'New order #1', 'message' => 'Order #1' ) ), $key );
	}

	public function test_push_stores_a_job_schedules_it_and_logs_it() {
		$job = $this->push();
		$this->assertIsString( $job );
		$this->assertArrayHasKey( 'honk_job_' . $job, $this->options );
		$this->assertSame( 'honk_deliver', $this->cron[0]['hook'] );
		$this->assertSame( array( $job ), $this->cron[0]['args'] );
		$this->assertSame( 'queued', $this->options['honk_log'][0]['status'] );
		$this->assertSame( 'New order #1', $this->options['honk_log'][0]['title'] );
		$this->assertCount( 0, $this->requests, 'nothing is sent during the request that queued it' );
	}

	public function test_the_same_key_is_never_queued_twice() {
		$this->assertNotFalse( $this->push( 'order-1-new' ) );
		$this->assertFalse( $this->push( 'order-1-new' ) );
		Honk_Dedupe::reset_request(); // A later request.
		$this->assertFalse( $this->push( 'order-1-new' ) );
		$this->assertCount( 1, $this->jobs() );
	}

	public function test_delivery_success_logs_the_message_id_and_deletes_the_job() {
		$job               = $this->push();
		$this->responses[] = $this->response( 202, array( 'id' => 'msg_42', 'duplicate' => false ) );
		Honk_Queue::deliver( $job );
		$this->assertArrayNotHasKey( 'honk_job_' . $job, $this->options );
		$this->assertSame( 'sent', $this->options['honk_log'][0]['status'] );
		$this->assertSame( 'msg_42', $this->options['honk_log'][0]['message_id'] );
		$this->assertSame( 'k-1', $this->requests[0]['args']['headers']['Idempotency-Key'] );
	}

	public function test_retries_reuse_the_same_body_and_key_and_respect_retry_after() {
		$job               = $this->push();
		$this->responses[] = $this->response( 429, array( 'error' => array( 'code' => 'overloaded' ) ), array( 'retry-after' => '900' ) );
		$this->responses[] = new WP_Error( 'http_request_failed', 'timeout' );
		$this->responses[] = $this->response( 202, array( 'id' => 'msg_7', 'duplicate' => true ) );

		Honk_Queue::deliver( $job );
		$this->assertSame( 'retry', $this->options['honk_log'][0]['status'] );
		$retry = end( $this->cron );
		$this->assertGreaterThanOrEqual( time() + 900, $retry['time'], 'never sooner than Retry-After' );

		Honk_Queue::deliver( $job );
		$this->assertSame( 2, $this->options[ 'honk_job_' . $job ]['attempts'] );

		Honk_Queue::deliver( $job );
		$this->assertSame( 'duplicate', $this->options['honk_log'][0]['status'] );
		$this->assertSame( $this->requests[0]['args']['body'], $this->requests[2]['args']['body'] );
		$this->assertSame( $this->requests[0]['args']['headers']['Idempotency-Key'], $this->requests[2]['args']['headers']['Idempotency-Key'] );
	}

	public function test_client_errors_are_not_retried() {
		$job               = $this->push();
		$this->responses[] = $this->response( 401, array( 'error' => array( 'code' => 'invalid_key', 'message' => 'Invalid key' ) ) );
		Honk_Queue::deliver( $job );
		$this->assertSame( 'failed', $this->options['honk_log'][0]['status'] );
		$this->assertSame( 'invalid_key', $this->options['honk_log'][0]['code'] );
		$this->assertArrayNotHasKey( 'honk_job_' . $job, $this->options );
		$this->assertCount( 1, $this->cron, 'no retry was scheduled' );
	}

	public function test_gives_up_after_six_attempts() {
		$job = $this->push();
		for ( $i = 0; $i < 6; $i++ ) {
			$this->responses[] = $this->response( 503, array( 'error' => array( 'code' => 'unavailable' ) ), array( 'retry-after' => '1' ) );
			Honk_Queue::deliver( $job );
		}
		$this->assertSame( 'failed', $this->options['honk_log'][0]['status'] );
		$this->assertSame( 6, $this->options['honk_log'][0]['attempts'] );
		$this->assertCount( 6, $this->requests );
	}

	public function test_a_daily_quota_is_not_waited_for() {
		$job               = $this->push();
		$this->responses[] = $this->response( 429, array( 'error' => array( 'code' => 'quota_exceeded' ) ), array( 'retry-after' => '40000' ) );
		Honk_Queue::deliver( $job );
		$this->assertSame( 'failed', $this->options['honk_log'][0]['status'] );
	}

	public function test_backoff_grows_with_jitter_and_caps_at_an_hour() {
		for ( $i = 0; $i < 20; $i++ ) {
			$first = Honk_Queue::backoff( 1 );
			$this->assertGreaterThanOrEqual( 15, $first );
			$this->assertLessThanOrEqual( 30, $first );
			$fifth = Honk_Queue::backoff( 5 );
			$this->assertGreaterThanOrEqual( 1800, $fifth );
			$this->assertLessThanOrEqual( 3600, $fifth );
		}
		$this->assertSame( 120, Honk_Queue::backoff( 1, 120 ) );
	}

	public function test_nothing_is_sent_without_a_key() {
		$job                            = $this->push();
		$this->options['honk_settings'] = array( 'api_key' => '' );
		Honk_Settings::flush();
		Honk_Queue::deliver( $job );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'not_configured', $this->options['honk_log'][0]['code'] );
	}

	public function test_maintenance_reschedules_lost_jobs_and_drops_expired_ones() {
		$lost    = $this->push( 'lost' );
		$expired = $this->push( 'old' );
		$this->options[ 'honk_job_' . $lost ]['next']       = time() - 3600;
		$this->options[ 'honk_job_' . $expired ]['created'] = time() - 2 * 86400;
		$this->cron = array();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );

		Honk_Queue::maintenance();

		$this->assertSame( array( $lost ), $this->cron[0]['args'] );
		$this->assertArrayNotHasKey( 'honk_job_' . $expired, $this->options );
	}
}
