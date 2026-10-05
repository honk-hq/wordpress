<?php
/**
 * The HTTP client: request shape and how responses are read (contracts/API.md §0.1, §5).
 *
 * @package Honk
 */

// phpcs:disable

class ClientTest extends Honk_Test_Case {

	public function test_send_posts_json_with_bearer_key_idempotency_key_and_user_agent() {
		$this->responses[] = $this->response( 202, array( 'id' => 'msg_01', 'status' => 'accepted', 'duplicate' => false ) );
		$result            = Honk_Client::send( 'https://honk.example.com', 'honk_ab_secretsecret', '{"message":"hi"}', 'wp-abcd1234-test' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'msg_01', $result['id'] );
		$this->assertFalse( $result['duplicate'] );
		$request = $this->requests[0];
		$this->assertSame( 'https://honk.example.com/v1/messages', $request['url'] );
		$this->assertSame( 'Bearer honk_ab_secretsecret', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'wp-abcd1234-test', $request['args']['headers']['Idempotency-Key'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertSame( 'honk-wordpress/0.1.0 (+https://honk-me.app)', $request['args']['user-agent'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( 5.0, (float) $request['args']['timeout'] );
	}

	public function test_a_replay_is_accepted_as_duplicate() {
		$result = Honk_Client::interpret( $this->response( 202, array( 'id' => 'msg_01', 'duplicate' => true ) ) );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['duplicate'] );
	}

	public function test_idempotency_conflict_counts_as_already_sent() {
		$result = Honk_Client::interpret( $this->response( 409, array( 'error' => array( 'code' => 'idempotency_conflict', 'message' => 'x' ) ) ) );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['duplicate'] );
	}

	public function test_429_and_5xx_are_retryable_with_retry_after() {
		$r429 = Honk_Client::interpret( $this->response( 429, array( 'error' => array( 'code' => 'overloaded', 'message' => 'busy' ) ), array( 'retry-after' => '7' ) ) );
		$this->assertFalse( $r429['ok'] );
		$this->assertTrue( $r429['retryable'] );
		$this->assertSame( 7, $r429['retry_after'] );
		$this->assertSame( 'overloaded', $r429['code'] );

		$r503 = Honk_Client::interpret( $this->response( 503, array( 'error' => array( 'code' => 'unavailable', 'message' => 'later' ) ), array( 'retry-after' => '2' ) ) );
		$this->assertTrue( $r503['retryable'] );
		$this->assertSame( 2, $r503['retry_after'] );

		$r500 = Honk_Client::interpret( $this->response( 500, array() ) );
		$this->assertTrue( $r500['retryable'] );
		$this->assertSame( 'http_500', $r500['code'] );
	}

	public function test_4xx_is_not_retried_and_reports_field_errors() {
		$r = Honk_Client::interpret(
			$this->response(
				422,
				array(
					'error' => array(
						'code'    => 'validation_failed',
						'message' => 'Invalid message',
						'fields'  => array( array( 'field' => 'severity', 'code' => 'invalid_enum' ) ),
					),
				)
			)
		);
		$this->assertFalse( $r['ok'] );
		$this->assertFalse( $r['retryable'] );
		$this->assertSame( 'validation_failed', $r['code'] );
		$this->assertStringContainsString( 'severity: invalid_enum', $r['message'] );

		$r401 = Honk_Client::interpret( $this->response( 401, array( 'error' => array( 'code' => 'invalid_key', 'message' => 'Invalid key' ) ) ) );
		$this->assertFalse( $r401['retryable'] );
	}

	public function test_network_errors_are_retryable() {
		$r = Honk_Client::interpret( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
		$this->assertFalse( $r['ok'] );
		$this->assertTrue( $r['retryable'] );
		$this->assertSame( 0, $r['status'] );
		$this->assertSame( 'network_error', $r['code'] );
	}

	public function test_redirects_are_reported_not_followed() {
		$r = Honk_Client::interpret( $this->response( 301, array() ) );
		$this->assertFalse( $r['ok'] );
		$this->assertFalse( $r['retryable'] );
		$this->assertSame( 'redirect', $r['code'] );
	}

	public function test_retry_after_accepts_seconds_and_http_dates() {
		$this->assertSame( 0, Honk_Client::retry_after( '' ) );
		$this->assertSame( 30, Honk_Client::retry_after( '30' ) );
		$this->assertSame( 30, Honk_Client::retry_after( array( '30' ) ) );
		$in_a_minute = gmdate( 'D, d M Y H:i:s \G\M\T', time() + 60 );
		$this->assertEqualsWithDelta( 60, Honk_Client::retry_after( $in_a_minute ), 2 );
	}

	public function test_server_capabilities_come_from_the_cached_config_only_on_page_loads() {
		$this->assertFalse( Honk_Client::server_supports( 'https://honk.example.com', 'heartbeats', false ) );
		$this->assertCount( 0, $this->requests );
		$this->transients[ Honk_Client::CONFIG_TRANSIENT . md5( 'https://honk.example.com' ) ] = array( 'heartbeats' => true );
		$this->assertTrue( Honk_Client::server_supports( 'https://honk.example.com', 'heartbeats', false ) );
	}
}
