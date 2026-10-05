<?php
/**
 * Message normalization against the contract's limits (contracts/openapi.yaml).
 *
 * @package Honk
 */

// phpcs:disable

class PayloadTest extends Honk_Test_Case {

	public function test_horn_aliases_become_canonical_severities() {
		$this->assertSame( 'warning', Honk_Payload::normalize( array( 'message' => 'x', 'severity' => 'loud' ) )['severity'] );
		$this->assertSame( 'critical', Honk_Payload::normalize( array( 'message' => 'x', 'severity' => 'BLAST' ) )['severity'] );
		$this->assertSame( 'info', Honk_Payload::normalize( array( 'message' => 'x', 'severity' => 'nonsense' ) )['severity'] );
		$this->assertSame( 'normal', Honk_Payload::normalize( array( 'message' => 'x', 'priority' => 'whenever' ) )['priority'] );
	}

	public function test_title_is_cut_to_160_code_points_with_an_ellipsis() {
		$title = str_repeat( 'ă', 200 );
		$out   = Honk_Payload::normalize( array( 'title' => $title, 'message' => 'm' ) );
		$this->assertSame( 160, mb_strlen( $out['title'], 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $out['title'] );
	}

	public function test_message_is_cut_to_8192_bytes_on_a_character_boundary() {
		$message = str_repeat( '€', 4000 ); // 3 bytes each: 12 000 bytes.
		$out     = Honk_Payload::normalize( array( 'message' => $message ) );
		$this->assertLessThanOrEqual( 8192, strlen( $out['message'] ) );
		$this->assertTrue( mb_check_encoding( $out['message'], 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $out['message'] );
	}

	public function test_empty_message_falls_back_to_the_title() {
		$out = Honk_Payload::normalize( array( 'title' => 'Hello', 'message' => '   ' ) );
		$this->assertSame( 'Hello', $out['message'] );
	}

	public function test_html_is_stripped_and_entities_decoded() {
		$out = Honk_Payload::normalize( array( 'title' => '<b>Order</b> &amp; more', 'message' => '<span>&euro;84.00</span>' ) );
		$this->assertSame( 'Order & more', $out['title'] );
		$this->assertSame( '€84.00', $out['message'] );
	}

	public function test_the_body_always_fits_in_16_kib() {
		$meta = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$meta[ 'key' . $i ] = str_repeat( 'x', 600 );
		}
		$out = Honk_Payload::normalize(
			array(
				'title'    => str_repeat( 'T', 300 ),
				'message'  => str_repeat( "\"quoted\" \\ line\n", 2000 ),
				'metadata' => $meta,
			)
		);
		$this->assertLessThanOrEqual( 16384, strlen( Honk_Payload::encode( $out ) ) );
		$this->assertLessThanOrEqual( 16, count( $out['metadata'] ) );
	}

	public function test_metadata_follows_the_contract() {
		$meta = Honk_Payload::metadata(
			array(
				'order id' => 12,
				'ok'       => true,
				'total'    => 84.5,
				'long'     => str_repeat( 'a', 600 ),
				'nested'   => array( 1, 2 ),
				'null'     => null,
			)
		);
		$this->assertSame( 12, $meta['order_id'] );
		$this->assertTrue( $meta['ok'] );
		$this->assertSame( 84.5, $meta['total'] );
		$this->assertSame( 512, mb_strlen( $meta['long'], 'UTF-8' ) );
		$this->assertArrayNotHasKey( 'nested', $meta );
		$this->assertArrayNotHasKey( 'null', $meta );
	}

	public function test_only_https_urls_are_kept() {
		$this->assertSame( 'https://shop.example.com/wp-admin/', Honk_Payload::https_url( 'https://shop.example.com/wp-admin/' ) );
		$this->assertSame( '', Honk_Payload::https_url( 'http://localhost/wp-admin/' ) );
		$this->assertSame( '', Honk_Payload::https_url( 'https://user:pass@example.com/' ) );
		$out = Honk_Payload::normalize( array( 'message' => 'x', 'url' => 'http://insecure.example.com' ) );
		$this->assertArrayNotHasKey( 'url', $out );
	}

	public function test_problem_and_recovery_need_a_group_key() {
		$this->assertArrayNotHasKey( 'event_type', Honk_Payload::normalize( array( 'message' => 'x', 'event_type' => 'problem' ) ) );
		$out = Honk_Payload::normalize( array( 'message' => 'x', 'event_type' => 'recovery', 'group_key' => 'wp/health/cron' ) );
		$this->assertSame( 'recovery', $out['event_type'] );
		$this->assertArrayNotHasKey( 'event_type', Honk_Payload::normalize( array( 'message' => 'x', 'event_type' => 'event', 'group_key' => 'g' ) ) );
	}

	public function test_short_fields_are_limited() {
		$out = Honk_Payload::normalize(
			array(
				'message'     => 'x',
				'source'      => str_repeat( 's', 100 ),
				'environment' => str_repeat( 'e', 40 ),
				'group_key'   => str_repeat( 'g', 200 ),
				'category'    => 'not-a-category',
			)
		);
		$this->assertSame( 64, strlen( $out['source'] ) );
		$this->assertSame( 32, strlen( $out['environment'] ) );
		$this->assertSame( 128, strlen( $out['group_key'] ) );
		$this->assertArrayNotHasKey( 'category', $out );
	}

	public function test_occurred_at_is_rfc3339_utc() {
		$out = Honk_Payload::normalize( array( 'message' => 'x', 'occurred_at' => 1791193398 ) );
		$this->assertSame( '2026-10-05T09:43:18Z', $out['occurred_at'] );
	}

	public function test_idempotency_keys_are_printable_ascii_and_at_most_128_characters() {
		$this->assertSame( 'wp-abcd1234-order-12-new', Honk_Payload::idempotency_key( 'order-12-new', 'abcd1234' ) );
		$this->assertMatchesRegularExpression( '/^[\x21-\x7E]+$/', Honk_Payload::idempotency_key( "form ă\n", 'abcd1234' ) );
		$long = Honk_Payload::idempotency_key( str_repeat( 'k', 300 ), 'abcd1234' );
		$this->assertLessThanOrEqual( 128, strlen( $long ) );
		$this->assertSame( $long, Honk_Payload::idempotency_key( str_repeat( 'k', 300 ), 'abcd1234' ) );
	}
}
