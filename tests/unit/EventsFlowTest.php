<?php
/**
 * Events end to end, without WordPress: the module builds the message, the notifier applies the
 * settings, the queue stores it.
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey\Functions;

// The plugins these events depend on, as far as Honk_Events::is_available() can tell.
if ( ! defined( 'WPCF7_VERSION' ) ) {
	define( 'WPCF7_VERSION', '6.1' );
}
if ( ! defined( 'WPFORMS_VERSION' ) ) {
	define( 'WPFORMS_VERSION', '1.9' );
}
if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}

class EventsFlowTest extends Honk_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		$this->configure();
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				$user               = new WP_User( (int) $id, 'user' . $id, array( 'customer' ) );
				$user->user_email   = 'user' . $id . '@example.com';
				$user->display_name = 'User ' . $id;
				return $user;
			}
		);
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'is_super_admin' )->justReturn( false );
	}

	public function test_nothing_is_queued_before_a_key_is_saved() {
		$this->options['honk_settings'] = array();
		Honk_Settings::flush();
		$this->assertFalse( Honk_Notifier::emit( 'woo_new_order', 'k', function () { return array( 'message' => 'x' ); } ) );
		$this->assertCount( 0, $this->jobs() );
	}

	public function test_a_switched_off_event_is_not_queued() {
		$this->configure( array( 'events' => array( 'fatal_error' => array( 'enabled' => false, 'severity' => 'error', 'priority' => 'high' ) ) ) );
		$this->assertFalse( Honk_Notifier::emit( 'fatal_error', 'k', function () { return array( 'message' => 'x' ); } ) );
		$this->assertFalse( Honk_Notifier::emit( 'post_published', 'k2', function () { return array( 'message' => 'x' ); } ), 'off by default' );
	}

	public function test_the_event_settings_shape_the_message() {
		$this->configure( array( 'events' => array( 'fatal_error' => array( 'enabled' => true, 'severity' => 'critical', 'priority' => 'urgent' ) ) ) );
		Honk_Notifier::emit(
			'fatal_error',
			'fatal-1',
			function () {
				return array( 'title' => 'Fatal error', 'message' => 'boom', 'group_key' => 'wp/health/fatal' );
			}
		);
		$p = $this->queued_payloads()[0];
		$this->assertSame( 'critical', $p['severity'] );
		$this->assertSame( 'urgent', $p['priority'] );
		$this->assertSame( 'health', $p['channel'] );
		$this->assertSame( 'infrastructure', $p['category'] );
		$this->assertSame( 'shop.example.com', $p['source'] );
		$this->assertSame( 'production', $p['environment'] );
		$this->assertSame( 'wp/health/fatal', $p['group_key'] );
		$job = array_values( $this->jobs() )[0];
		$this->assertSame( 'wp-abcd1234-fatal-1', $job['key'] );
	}

	public function test_recoveries_are_never_louder_than_normal() {
		Honk_Notifier::emit(
			'login_failures_burst',
			'burst-1-recovered',
			function () {
				return array( 'message' => 'calm', 'group_key' => 'wp/security/login-burst', 'event_type' => 'recovery' );
			},
			array( 'severity' => 'success' )
		);
		$p = $this->queued_payloads()[0];
		$this->assertSame( 'success', $p['severity'] );
		$this->assertSame( 'normal', $p['priority'] );
		$this->assertSame( 'recovery', $p['event_type'] );
	}

	public function test_the_honk_message_filter_can_drop_a_message() {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'honk_message' === $hook ? array() : $value;
			}
		);
		$this->assertFalse( Honk_Notifier::emit( 'woo_new_order', 'k', function () { return array( 'message' => 'x' ); } ) );
	}

	public function test_links_are_https_urls_or_metadata() {
		$fields = Honk_Notifier::with_link( array( 'message' => 'x' ), 'https://shop.example.com/wp-admin/' );
		$this->assertSame( 'https://shop.example.com/wp-admin/', $fields['url'] );
		$fields = Honk_Notifier::with_link( array( 'message' => 'x' ), 'http://localhost:8080/wp-admin/' );
		$this->assertArrayNotHasKey( 'url', $fields );
		$this->assertSame( 'http://localhost:8080/wp-admin/', $fields['metadata']['link'] );
	}

	public function test_ips_are_masked_to_their_network() {
		$this->assertSame( '203.0.113.0/24', Honk_Notifier::mask_ip( '203.0.113.77' ) );
		$this->assertSame( '2001:db8:abcd::/48', Honk_Notifier::mask_ip( '2001:db8:abcd:12::1' ) );
		$this->assertSame( '', Honk_Notifier::mask_ip( 'nonsense' ) );
	}

	public function test_users_are_named_without_personal_data_unless_allowed() {
		$this->assertSame( 'user #7', Honk_Notifier::user_label( 7, false ) );
		$this->assertSame( 'User 7 · user7 · user7@example.com', Honk_Notifier::user_label( 7, true ) );
	}

	public function test_form_submissions_say_only_the_form_name_without_personal_data() {
		Honk_Module_Forms::report( 'form_cf7', 'cf7', '10', 'Quote request', array( array( 'label' => 'Email', 'value' => 'ana@example.com', 'type' => 'email' ) ), 'entry-1' );
		$p = $this->queued_payloads()[0];
		$this->assertSame( 'Quote request', $p['title'] );
		$this->assertSame( 'Someone sent the “Quote request” form.', $p['message'] );
		$this->assertStringNotContainsString( 'ana@example.com', json_encode( $p ) );
		$this->assertSame( 'wp/forms/cf7/10', $p['group_key'] );
	}

	public function test_form_fields_are_included_when_personal_data_is_allowed() {
		$this->configure( array( 'include_pii' => true ) );
		Honk_Module_Forms::report(
			'form_wpforms',
			'wpforms',
			'3',
			'Contact',
			array(
				array( 'label' => 'Name', 'value' => array( 'first' => 'Ana', 'last' => 'Ionescu' ), 'type' => 'name' ),
				array( 'label' => 'Password', 'value' => 'hunter2', 'type' => 'password' ),
				array( 'label' => 'Message', 'value' => "Line one\nLine two", 'type' => 'textarea' ),
				array( 'label' => 'CV', 'value' => 'https://x/cv.pdf', 'type' => 'file-upload' ),
				array( 'label' => 'hp', 'value' => 'bot', 'type' => 'honeypot' ),
			),
			''
		);
		$p = $this->queued_payloads()[0];
		$this->assertStringContainsString( 'Name: Ana, Ionescu', $p['message'] );
		$this->assertStringContainsString( "Message: \nLine one\nLine two", $p['message'] );
		$this->assertStringContainsString( 'CV: (file attached)', $p['message'] );
		$this->assertStringNotContainsString( 'hunter2', $p['message'] );
		$this->assertStringNotContainsString( 'bot', $p['message'] );
	}

	public function test_humanize_field_names() {
		$this->assertSame( 'Email', Honk_Module_Forms::humanize( 'your-email' ) );
		$this->assertSame( 'Names first name', Honk_Module_Forms::humanize( 'names[first_name]' ) );
	}

	public function test_failed_sign_ins_open_a_burst_once_at_the_threshold() {
		$now     = 1791193000;
		$started = 0;
		for ( $i = 1; $i <= 15; $i++ ) {
			$state    = Honk_Module_Security::record_failure( $now + $i, 'admin', '203.0.113.' . ( $i % 3 ), 10, 5 );
			$started += $state['started_now'] ? 1 : 0;
			if ( 10 === $i ) {
				$this->assertTrue( $state['started_now'] );
			}
		}
		$this->assertSame( 1, $started );
		$this->assertSame( 15, $this->options['honk_login_failures']['burst']['total'] );
		$this->assertCount( 3, $this->options['honk_login_failures']['burst']['ips'] );
	}

	public function test_failures_outside_the_window_do_not_count() {
		for ( $i = 0; $i < 9; $i++ ) {
			Honk_Module_Security::record_failure( 1791193000 + $i * 120, 'admin', '203.0.113.1', 10, 5 );
		}
		$this->assertNull( $this->options['honk_login_failures']['burst'] );
	}

	public function test_the_first_sign_in_only_learns_then_new_devices_are_reported() {
		$first = Honk_Module_Security::remember_device( 5, 'dev-a', 'net-a' );
		$this->assertFalse( $first['new'] );
		$this->assertFalse( Honk_Module_Security::remember_device( 5, 'dev-a', 'net-a' )['new'] );
		$new_device = Honk_Module_Security::remember_device( 5, 'dev-b', 'net-a' );
		$this->assertTrue( $new_device['new'] );
		$this->assertTrue( $new_device['new_device'] );
		$new_network = Honk_Module_Security::remember_device( 5, 'dev-a', 'net-b' );
		$this->assertTrue( $new_network['new'] );
		$this->assertFalse( $new_network['new_device'] );
	}

	public function test_user_agents_are_described_without_versions() {
		$this->assertSame( 'Edge on Windows', Honk_Module_Security::describe_user_agent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0' ) );
		$this->assertSame( 'Safari on iOS', Honk_Module_Security::describe_user_agent( 'Mozilla/5.0 (iPhone; CPU iPhone OS 19_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Mobile/15E148 Safari/604.1' ) );
		$this->assertSame( 'Firefox on Linux', Honk_Module_Security::describe_user_agent( 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0' ) );
		$this->assertSame( 'unknown device', Honk_Module_Security::describe_user_agent( '' ) );
	}

	public function test_overdue_cron_counts_tasks_more_than_30_minutes_late() {
		$now  = 1791193000;
		$cron = array(
			$now - 7200 => array( 'stuck_hook' => array( 'k' => array() ) ),
			$now - 600  => array( 'recent_hook' => array( 'k' => array() ) ),
			$now + 60   => array( 'future_hook' => array( 'k' => array() ) ),
			'version'   => 2,
		);
		$this->assertSame( array( 'count' => 1, 'oldest' => $now - 7200, 'hook' => 'stuck_hook' ), Honk_Module_Health::overdue_cron( $now, $cron ) );
	}

	public function test_wp_cron_is_a_problem_only_after_two_checks_and_recovers() {
		$late = time() - 7200;
		Functions\when( '_get_cron_array' )->alias(
			function () use ( &$late ) {
				return $late ? array( $late => array( 'stuck' => array( 'k' => array() ) ) ) : array();
			}
		);
		Honk_Module_Health::check_cron( time() );
		$this->assertCount( 0, $this->jobs(), 'one observation is not enough (a quiet site is late until its next visit)' );
		Honk_Module_Health::check_cron( time() + 16 * 60 );
		$this->assertSame( 'problem', $this->queued_payloads()[0]['event_type'] );
		$this->assertSame( 'Scheduled tasks are running late', $this->queued_payloads()[0]['title'] );
		$late = 0;
		Honk_Module_Health::check_cron( time() + 20 * 60 );
		$payloads = $this->queued_payloads();
		$this->assertSame( 'recovery', $payloads[1]['event_type'] );
		$this->assertSame( 'wp/health/cron', $payloads[1]['group_key'] );
	}

	public function test_stock_levels() {
		$this->assertSame( 'out', Honk_Module_Woocommerce::stock_level( 0, 2, 0 ) );
		$this->assertSame( 'low', Honk_Module_Woocommerce::stock_level( 2, 2, 0 ) );
		$this->assertSame( 'ok', Honk_Module_Woocommerce::stock_level( 3, 2, 0 ) );
	}

	public function test_stock_changes_are_problems_and_restocking_a_recovery_per_product() {
		Functions\when( 'wc_get_low_stock_amount' )->justReturn( 3 );
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'managing_stock' )->andReturn( true );
		$product->shouldReceive( 'get_id' )->andReturn( 12 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Linen Apron' );
		$product->shouldReceive( 'get_sku' )->andReturn( 'APR-02' );
		$product->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$qty = 8;
		$product->shouldReceive( 'get_stock_quantity' )->andReturnUsing( function () use ( &$qty ) { return $qty; } );

		foreach ( array( 8, 2, 2, 0, 10 ) as $value ) {
			$qty = $value;
			Honk_Module_Woocommerce::evaluate_stock( $product );
		}
		$payloads = $this->queued_payloads();
		$this->assertCount( 3, $payloads, 'ok→low, low→out, out→ok; the repeated 2 is not sent again' );
		$this->assertSame( array( 'Low stock: Linen Apron', 'Out of stock: Linen Apron', 'Back in stock: Linen Apron' ), array_column( $payloads, 'title' ) );
		$this->assertSame( array( 'problem', 'problem', 'recovery' ), array_column( $payloads, 'event_type' ) );
		$this->assertSame( array( 'warning', 'error', 'success' ), array_column( $payloads, 'severity' ) );
		$this->assertSame( array( 'woo/stock/12' ), array_values( array_unique( array_column( $payloads, 'group_key' ) ) ) );
	}

	public function test_order_summary_has_no_personal_data() {
		Functions\when( 'wc_price' )->alias(
			function ( $amount ) {
				return '<span class="amount"><bdi><span>&euro;</span>' . number_format( $amount, 2 ) . '</bdi></span>';
			}
		);
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_item_count' )->andReturn( 2 );
		$order->shouldReceive( 'get_order_number' )->andReturn( '1234' );
		$order->shouldReceive( 'get_total' )->andReturn( '84.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'EUR' );
		$this->assertSame( 'Order #1234 · €84.00 · 2 items', Honk_Module_Woocommerce::order_summary( $order ) );
	}
}
