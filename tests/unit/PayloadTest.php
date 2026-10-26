<?php
/**
 * Message building: WordPress text to plain text and the 16 KiB body. Field limits, links and
 * levels are the server's to correct (contracts/API.md §5.1), so they pass through unchanged.
 *
 * @package Honk
 */

// phpcs:disable

class PayloadTest extends Honk_Test_Case {

	public function test_levels_and_priorities_are_sent_as_given() {
		$this->assertSame( 'loud', Honk_Payload::normalize( array( 'message' => 'x', 'severity' => 'loud' ) )['severity'] );
		$this->assertSame( 'warning', Honk_Payload::normalize( array( 'message' => 'x', 'severity' => 'warning' ) )['severity'] );
		$this->assertSame( 'whenever', Honk_Payload::normalize( array( 'message' => 'x', 'priority' => 'whenever' ) )['priority'] );
		$this->assertArrayNotHasKey( 'severity', Honk_Payload::normalize( array( 'message' => 'x', 'severity' => '' ) ) );
	}

	public function test_long_text_is_left_for_the_server_to_shorten() {
		$out = Honk_Payload::normalize(
			array(
				'title'       => str_repeat( 'ă', 200 ),
				'message'     => str_repeat( '€', 4000 ), // 12 000 bytes, but the body fits in 16 KiB.
				'source'      => str_repeat( 's', 100 ),
				'environment' => str_repeat( 'e', 40 ),
				'group_key'   => str_repeat( 'g', 200 ),
			)
		);
		$this->assertSame( 200, mb_strlen( $out['title'], 'UTF-8' ) );
		$this->assertSame( 12000, strlen( $out['message'] ) );
		$this->assertSame( 100, strlen( $out['source'] ) );
		$this->assertSame( 40, strlen( $out['environment'] ) );
		$this->assertSame( 200, strlen( $out['group_key'] ) );
	}

	public function test_short_fields_are_made_one_line() {
		$out = Honk_Payload::normalize( array( 'message' => 'x', 'title' => "Two\nlines", 'group_key' => " wp/a\tb " ) );
		$this->assertSame( 'Two lines', $out['title'] );
		$this->assertSame( 'wp/a b', $out['group_key'] );
	}

	public function test_empty_message_repeats_the_title() {
		$out = Honk_Payload::normalize( array( 'title' => 'Hello', 'message' => '   ' ) );
		$this->assertSame( 'Hello', $out['message'] );
		$this->assertSame( array(), Honk_Payload::normalize( array( 'title' => ' ', 'message' => '<p></p>' ) ), 'nothing to send' );
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
		$this->assertStringEndsWith( '…', $out['message'], 'the text is shortened first' );
	}

	public function test_metadata_becomes_json_values() {
		$meta = Honk_Payload::metadata(
			array(
				'order id' => 12,
				'ok'       => true,
				'total'    => 84.5,
				'nan'      => NAN,
				'html'     => '<b>Paid</b> &amp; shipped',
				'long'     => str_repeat( 'a', 600 ),
				'nested'   => array( 1, 2 ),
				'null'     => null,
			)
		);
		$this->assertSame( 12, $meta['order_id'] );
		$this->assertTrue( $meta['ok'] );
		$this->assertSame( 84.5, $meta['total'] );
		$this->assertSame( 'NAN', $meta['nan'] );
		$this->assertSame( 'Paid & shipped', $meta['html'] );
		$this->assertSame( 600, strlen( $meta['long'] ), 'the server shortens long values' );
		$this->assertArrayNotHasKey( 'nested', $meta );
		$this->assertArrayNotHasKey( 'null', $meta );
		$many = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$many[ 'k' . $i ] = $i;
		}
		$this->assertCount( 20, Honk_Payload::metadata( $many ), 'the server keeps the first 16' );
	}

	public function test_links_are_sent_as_given() {
		$this->assertSame( 'https://shop.example.com/wp-admin/', Honk_Payload::https_url( 'https://shop.example.com/wp-admin/' ) );
		$this->assertSame( '', Honk_Payload::https_url( 'http://localhost/wp-admin/' ) );
		$this->assertSame( '', Honk_Payload::https_url( 'https://user:pass@example.com/' ) );
		// with_link() puts an http link into the metadata; one set directly goes to the server, which leaves it out with a warning.
		$this->assertSame( 'http://insecure.example.com', Honk_Payload::normalize( array( 'message' => 'x', 'url' => ' http://insecure.example.com ' ) )['url'] );
	}

	public function test_event_types_and_categories_are_sent_as_given() {
		$out = Honk_Payload::normalize( array( 'message' => 'x', 'event_type' => 'recovery' ) );
		$this->assertSame( 'recovery', $out['event_type'], 'a recovery no longer needs a group key' );
		$this->assertArrayNotHasKey( 'event_type', Honk_Payload::normalize( array( 'message' => 'x', 'event_type' => 'event', 'group_key' => 'g' ) ), 'the default' );
		$this->assertSame( 'not-a-category', Honk_Payload::normalize( array( 'message' => 'x', 'category' => 'not-a-category' ) )['category'] );
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
