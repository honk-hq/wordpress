<?php
/**
 * Details: each choice changes what a notification says, the privacy switch always wins,
 * choices are sanitized, and the preview follows the same rules as the real messages.
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey\Functions;

class DetailsTest extends Honk_Test_Case {

	use Honk_Scenarios;

	/**
	 * Personal data in the scenarios' fixtures.
	 */
	const PERSONAL = array( 'Ana Pop', 'ana@example.com', '+40 721', 'Cluj-Napoca', 'neighbours', 'ana.pop', 'Mara Ionescu', 'mara@example.com', 'sam@example.com', 'Dan', 'dan@example.com', 'Lovely fabric', 'Austria', 'Anonymous question', '203.0.113.77', 'root', 'Ops Team', 'ops@example.com', 'Eve Martin', 'eve@example.com', 'Li Wang', 'li@example.com', 'owner@example.com', 'old@example.com', 'hunter2', 'Line one', '40721000000' );

	protected function setUp(): void {
		parent::setUp();
		$this->stub_wordpress();
		Honk_Preview::flush();
		Honk_Module_Forms::flush_forms();
	}

	/**
	 * Every detail and button of every event chosen (or not).
	 */
	private function all_details( $on ) {
		$details = array();
		foreach ( Honk_Details::FACTS as $event => $facts ) {
			foreach ( $facts as $fact => $meta ) {
				$details[ $event ][ $fact ] = $on;
			}
			foreach ( Honk_Details::actions( $event ) as $action => $need ) {
				$details[ $event ][ $action ] = $on;
			}
		}
		return $details;
	}

	/**
	 * The messages of a scenario with some details changed: [ title, message ] per payload.
	 */
	private function messages( $scenario, $pii, array $details ) {
		return array_map(
			function ( $job ) {
				return array( isset( $job['payload']['title'] ) ? $job['payload']['title'] : '', $job['payload']['message'] );
			},
			$this->run_scenario( $scenario, $pii, array( 'details' => $details ) )
		);
	}

	public function test_new_order_follows_each_choice() {
		$m = $this->messages( 'woo_new_order', true, array( 'woo_new_order' => array( 'total' => false, 'status' => false, 'products' => true, 'shipping' => true, 'phone' => true, 'city' => true, 'note' => true ) ) );
		$this->assertSame(
			array(
				'New order #1234',
				"Order #1234 · 2 items\nCard\nLinen Apron, Ceramic Mug\nShipping: Flat rate\nAna Pop · ana@example.com · +40 721 000 000\nCluj-Napoca, RO\nNote: Please leave it with the neighbours.",
			),
			$m[0]
		);

		$m = $this->messages( 'woo_new_order', true, array( 'woo_new_order' => array( 'count' => false, 'payment' => false, 'name' => false ) ) );
		$this->assertSame( "Order #1234 · €84.00\nProcessing\nana@example.com", $m[0][1] );

		$m = $this->messages( 'woo_new_order', true, array( 'woo_new_order' => array( 'total' => false, 'count' => false, 'status' => false, 'payment' => false, 'name' => false, 'email' => false ) ) );
		$this->assertSame( 'Order #1234', $m[0][1], 'the order number is always there' );
	}

	public function test_products_list_the_first_three_and_count_the_rest() {
		$this->assertSame( 'Linen Apron, Ceramic Mug × 2, Wool Throw and 2 more', Honk_Module_Woocommerce::products_text( array( array( 'Linen Apron', 1 ), array( 'Ceramic Mug', 2 ), array( 'Wool Throw', 1 ), array( 'Tea Towel', 3 ), array( 'Candle', 1 ) ) ) );
		$this->assertSame( 'Candle', Honk_Module_Woocommerce::products_text( array( array( 'Candle', 1 ) ) ) );
	}

	public function test_order_events_follow_their_choices() {
		$m = $this->messages( 'woo_order_status', true, array( 'woo_order_status' => array( 'total' => false, 'payment' => true, 'email' => false ) ) );
		$this->assertSame( "Processing → Completed\nOrder #1234 · 2 items\nPayment method: Card\nAna Pop", $m[0][1] );

		$m = $this->messages( 'woo_payment_failed', true, array( 'woo_payment_failed' => array( 'payment' => false, 'products' => true, 'phone' => true ) ) );
		$this->assertSame( "Order #1234 · €84.00 · 2 items\nLinen Apron, Ceramic Mug\nAna Pop · ana@example.com · +40 721 000 000", $m[0][1] );

		$m = $this->messages( 'woo_refund', true, array( 'woo_refund' => array( 'reason' => false, 'count' => false, 'name' => true, 'email' => true ) ) );
		$this->assertSame( "Partial refund of €20.00 on order #1234.\nOrder #1234 · €84.00\nAna Pop · ana@example.com", $m[0][1] );
		$this->assertSame( 'Refund of €20.00 for order #1234', $m[0][0], 'the refunded amount is always there' );

		$m = $this->messages( 'woo_subscription_failed', true, array( 'woo_subscription_failed' => array( 'renewal' => false, 'name' => false ) ) );
		$this->assertSame( "The €19.00 renewal couldn’t be charged.\nana@example.com", $m[0][1] );

		$m = $this->messages( 'woo_daily_summary', false, array( 'woo_daily_summary' => array( 'items' => false, 'refunds' => false ) ) );
		$this->assertSame( "2 orders\nRevenue: €100.00", $m[0][1] );
	}

	public function test_stock_customer_and_review_follow_their_choices() {
		$m = $this->messages( 'woo_stock', false, array( 'woo_low_stock' => array( 'sku' => false ), 'woo_out_of_stock' => array( 'stock' => false ) ) );
		$this->assertSame( array( 'Low stock: Linen Apron', '2 units left.' ), $m[0] );
		$this->assertSame( array( 'Out of stock: Linen Apron', 'SKU: APR-02' ), $m[1] );
		$this->assertSame( array( 'Back in stock: Linen Apron', 'SKU: APR-02' ), $m[2], 'the all-clear follows the event it closes' );

		$m = $this->messages( 'woo_new_customer', true, array( 'woo_new_customer' => array( 'username' => false ) ) );
		$this->assertSame( 'Ana Pop · ana@example.com', $m[0][1] );
		$m = $this->messages( 'woo_new_customer', true, array( 'woo_new_customer' => array( 'name' => false, 'username' => false, 'email' => false ) ) );
		$this->assertSame( 'A customer account was created.', $m[0][1] );

		$m = $this->messages( 'woo_new_review', true, array( 'woo_new_review' => array( 'rating' => false, 'email' => false, 'text' => false ) ) );
		$this->assertSame( "Awaiting moderation.\nAna Pop", $m[0][1] );
		$this->assertSame( 'Tom', $m[1][1] );
	}

	public function test_content_events_follow_their_choices() {
		$m = $this->messages( 'user_registered', true, array( 'user_registered' => array( 'name' => false, 'role' => false ) ) );
		$this->assertSame( 'mara · mara@example.com', $m[0][1] );
		$m = $this->messages( 'user_registered', false, array( 'user_registered' => array( 'role' => false ) ) );
		$this->assertSame( 'A new user account was created.', $m[0][1] );

		$m = $this->messages( 'comment_pending', true, array( 'comment_pending' => array( 'post' => false, 'text' => false ) ) );
		$this->assertSame( 'Dan · dan@example.com', $m[0][1] );
		$this->assertSame( 'Anonymous', $m[1][1] );
		$m = $this->messages( 'comment_pending', true, array( 'comment_pending' => array( 'name' => false, 'email' => false ) ) );
		$this->assertSame( "On “Hello world”\nGreat post. Do you ship to Austria as well?", $m[0][1] );

		$m = $this->messages( 'post_pending', false, array( 'post_pending' => array( 'author' => false, 'excerpt' => true ) ) );
		$this->assertSame( array( 'Pending review: Autumn sale', "Post is waiting for review.\nEverything linen is 20% off until Sunday." ), $m[0] );
		$m = $this->messages( 'post_published', false, array( 'post_published' => array( 'author' => false, 'excerpt' => true ) ) );
		$this->assertSame( "Post\nWe are open on Sundays from now on.", $m[0][1] );
	}

	public function test_security_events_follow_their_choices() {
		$m = $this->messages( 'admin_login_new_device', true, array( 'admin_login_new_device' => array( 'username' => false, 'ip' => false ) ) );
		$this->assertSame( 'An administrator signed in from a new device', $m[0][0] );
		$this->assertStringContainsString( 'Network: 203.0.113.0/24', $m[0][1], 'the network, not the address' );
		$m = $this->messages( 'admin_login_new_device', true, array( 'admin_login_new_device' => array( 'device' => false, 'network' => false, 'ip' => false ) ) );
		$this->assertStringNotContainsString( 'Device:', $m[0][1] );
		$this->assertStringNotContainsString( 'Network:', $m[0][1] );

		$m = $this->messages( 'login_failures_burst', true, array( 'login_failures_burst' => array( 'ip_count' => false ) ) );
		$this->assertSame( "10 failed sign-ins within 5 mins.\nUsernames tried: root\nYou’ll get an all-clear once they stop.", $m[0][1] );

		$m = $this->messages( 'new_administrator', true, array( 'new_administrator' => array( 'email' => false ) ) );
		$this->assertStringStartsWith( 'Ops Team · ops was created as an administrator by admin.', $m[0][1] );

		$m = $this->messages( 'role_changed', true, array( 'role_changed' => array( 'name' => false ) ) );
		$this->assertSame( "Author → Editor, by admin.\nli · li@example.com", $m[0][1] );

		$m = $this->messages( 'site_identity_changed', true, array( 'site_identity_changed' => array( 'emails' => false ) ) );
		$this->assertStringStartsWith( 'Changed by admin.', $m[0][1] );

		$m = $this->messages( 'plugin_changed', false, array( 'plugin_changed' => array( 'actor' => false, 'file' => false ) ) );
		$this->assertSame( 'Plugin activated: acme', $m[0][1], 'nothing left: Honk shows the title' );
		$this->assertSame( 'Network-wide.', $m[1][1] );

		$m = $this->messages( 'theme_changed', false, array( 'theme_changed' => array( 'actor' => false ) ) );
		$this->assertSame( 'Previous theme: Twenty Twenty-Four', $m[0][1] );

		$m = $this->messages( 'file_edited', false, array( 'file_edited' => array( 'actor' => false, 'tip' => false ) ) );
		$this->assertSame( 'acme/acme.php was edited in the file editor.', $m[0][1] );

		$m = $this->messages( 'updates_installed', false, array( 'updates_installed' => array( 'actor' => false ) ) );
		$this->assertSame( "WooCommerce 11.2.0\nAcme Tools 2.0.1", $m[0][1] );
		$this->assertSame( "WordPress 7.1\nUpdated automatically.", $m[1][1] );
	}

	public function test_health_events_follow_their_choices() {
		$m = $this->messages( 'fatal_error', false, array( 'fatal_error' => array( 'error' => false ) ) );
		$this->assertSame( "wp-content/plugins/acme/acme.php, line 12\nWordPress emailed the site administrator a link to fix it in recovery mode.", $m[0][1] );
		$m = $this->messages( 'cron_overdue', false, array( 'cron_overdue' => array( 'hook' => false, 'advice' => false ) ) );
		$this->assertSame( '1 scheduled task is late, the oldest by 136 mins.', $m[0][1] );
	}

	public function test_form_answers_can_be_left_out() {
		$m = $this->messages( 'forms', true, array( 'form_wpforms' => array( 'values' => false ) ) );
		$this->assertSame( 'Someone sent the “Contact” form.', $m[0][1] );
		$this->assertFalse( $this->run_scenario( 'forms', true, array( 'details' => array( 'form_wpforms' => array( 'values' => false ) ) ) )[0]['pii'] );
	}

	public function test_form_fields_can_be_left_out_one_by_one() {
		$details = array( 'form_wpforms' => array( 'values' => true, 'skip' => array( '3' => array( '2' ), '99' => array( '1' ) ) ) );
		$m       = $this->messages( 'forms', true, $details );
		$this->assertSame( "Name: Ana, Pop\nMessage: \nLine one\nLine two", $m[0][1], 'field 2 (email) of form 3 is left out' );
	}

	public function test_the_privacy_switch_wins_over_every_choice() {
		foreach ( array_keys( $this->scenarios() ) as $scenario ) {
			$jobs = $this->run_scenario( $scenario, false, array( 'details' => $this->all_details( true ) ) );
			$json = json_encode( $jobs, JSON_UNESCAPED_UNICODE );
			foreach ( self::PERSONAL as $personal ) {
				$this->assertStringNotContainsString( $personal, $json, $scenario . ': personal data with the switch off' );
			}
			foreach ( $jobs as $job ) {
				$this->assertFalse( $job['pii'], $scenario . ': flagged as personal data' );
			}
		}
	}

	public function test_personal_data_can_be_left_out_with_the_switch_on() {
		$details = $this->all_details( true );
		foreach ( Honk_Details::FACTS as $event => $facts ) {
			foreach ( $facts as $fact => $meta ) {
				if ( $meta[0] ) {
					$details[ $event ][ $fact ] = false;
				}
			}
		}
		foreach ( array_keys( $this->scenarios() ) as $scenario ) {
			$json = json_encode( $this->run_scenario( $scenario, true, array( 'details' => $details ) ), JSON_UNESCAPED_UNICODE );
			foreach ( self::PERSONAL as $personal ) {
				if ( 'Anonymous question' === $personal || 'Dan' === $personal ) {
					continue;
				}
				$this->assertStringNotContainsString( $personal, $json, $scenario );
			}
		}
	}

	public function test_saving_the_untouched_form_keeps_the_0_1_0_messages() {
		// What the settings form posts when nothing is changed: every choice as rendered.
		$posted = array();
		foreach ( Honk_Details::FACTS as $event => $facts ) {
			foreach ( $facts as $fact => $meta ) {
				$posted[ $event ][ $fact ] = $meta[1] ? '1' : '0';
			}
			foreach ( Honk_Details::actions( $event ) as $action => $need ) {
				$posted[ $event ][ $action ] = '0';
			}
		}
		$golden = json_decode( file_get_contents( __DIR__ . '/../fixtures/messages-0.1.0.json' ), true );
		foreach ( array( 'off' => false, 'on' => true ) as $k => $pii ) {
			$this->options['honk_settings'] = array( 'include_pii' => $pii );
			Honk_Settings::flush();
			$saved = Honk_Settings::sanitize( array( '_form' => '1', 'include_pii' => $pii ? '1' : '', 'details' => $posted ) );
			foreach ( array_keys( $this->scenarios() ) as $scenario ) {
				$this->assertSame( $golden[ $scenario ][ $k ], $this->run_scenario( $scenario, $pii, array( 'details' => $saved['details'] ) ), $scenario . ', personal data ' . $k );
			}
		}
	}

	public function test_details_are_sanitized() {
		$this->options['honk_settings'] = array(
			'details' => array(
				'woo_refund'   => array( 'reason' => false ),
				'form_cf7'     => array( 'values' => true, 'skip' => array( '10' => array( 'your-email' ) ) ),
				'form_wpforms' => array( 'values' => true, 'skip' => array( '3' => array( '2' ) ) ),
			),
		);
		Honk_Settings::flush();
		$out = Honk_Settings::sanitize(
			array(
				'_form'   => '1',
				'details' => array(
					'woo_new_order'     => array( 'total' => '0', 'phone' => '1', 'bogus' => '1', 'email' => array( 'x' ), 'action_call' => '1' ),
					'not_an_event'      => array( 'total' => '1' ),
					'form_cf7'          => array(
						'values' => '1',
						'fields' => array(
							'10'         => array( 'your-name' => '1', 'your-email' => '1', 'your-subject' => '0' ),
							'11<script>' => array( 'a"b' => '0' ),
						),
					),
					'form_gravityforms' => array( 'values' => '1', 'fields' => 'nope' ),
				),
			)
		);
		$d = $out['details'];
		$this->assertSame( array( 'total' => false, 'count' => true, 'status' => true, 'payment' => true, 'products' => false, 'shipping' => false, 'name' => true, 'email' => true, 'phone' => true, 'city' => false, 'note' => false, 'action_email' => false, 'action_call' => true ), $d['woo_new_order'], 'unknown keys dropped, missing ones default (buttons off), arrays ignored' );
		$this->assertArrayNotHasKey( 'not_an_event', $d );
		$this->assertSame( array( 'total' => true, 'count' => true, 'reason' => false, 'name' => false, 'email' => false, 'action_email' => false ), $d['woo_refund'], 'not on the form: kept' );
		$this->assertSame( array( '10' => array( 'your-subject' ), '11script' => array( 'ab' ) ), $d['form_cf7']['skip'] );
		$this->assertSame( array( '3' => array( '2' ) ), $d['form_wpforms']['skip'], 'not on the form: kept' );
		$this->assertArrayNotHasKey( 'skip', $d['form_gravityforms'] );
		$this->assertArrayNotHasKey( 'skip', $d['woo_new_order'] );

		// WordPress sanitizes again when it first adds the option: nothing changes.
		$again = Honk_Settings::sanitize( $out );
		$this->assertSame( $d, $again['details'] );
	}

	public function test_left_out_fields_are_limited() {
		$fields = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$fields[ 'f' . $i ] = '0';
		}
		$posted = array( 'form_cf7' => array( 'values' => '1', 'fields' => array( '1' => $fields, '2' => array( str_repeat( 'k', 100 ) => '0' ) ) ) );
		$out    = Honk_Details::sanitize( $posted, array() );
		$this->assertCount( Honk_Details::MAX_FIELDS, $out['form_cf7']['skip']['1'] );
		$this->assertSame( 64, strlen( $out['form_cf7']['skip']['2'][0] ) );
		$this->assertSame( array(), Honk_Details::sanitize( array(), array() ), 'nothing posted, nothing stored' );
	}

	public function test_a_missing_or_broken_details_setting_means_the_defaults() {
		$this->options['honk_settings'] = array( 'details' => 'broken', 'include_pii' => true );
		Honk_Settings::flush();
		$this->assertTrue( Honk_Details::on( 'woo_new_order', 'name' ) );
		$this->assertFalse( Honk_Details::on( 'woo_new_order', 'phone' ) );
		$this->assertFalse( Honk_Details::on( 'woo_new_order', 'not_a_detail' ) );
		$this->assertSame( array(), Honk_Details::skipped_fields( 'form_cf7', '10' ) );
	}

	public function test_compose_rules() {
		$spec = array(
			'title'    => array( Honk_Details::part( 'A', 'x' ), Honk_Details::part( 'B', array(), 'x' ) ),
			'lines'    => array(
				Honk_Details::line( array( Honk_Details::part( 'one', 'x' ), Honk_Details::part( '' ), Honk_Details::part( 'two' ) ) ),
				Honk_Details::line( array( Honk_Details::part( '', 'p' ) ), ' · ', array( 'any' => array( 'p' ), 'empty' => 'nobody' ) ),
				Honk_Details::line( array( Honk_Details::part( 'who', 'p' ) ), ' · ', array( 'wrap' => "[\x01] did it" ) ),
			),
			'fallback' => 'nothing',
		);
		$on       = Honk_Details::compose( 'woo_new_order', $spec, array( 'x' => true, 'p' => true ) );
		$this->assertSame( array( 'title' => 'A', 'message' => "one · two\nnobody\n[who] did it", 'pii' => false, 'actions' => array() ), $on, 'p is not a detail of woo_new_order, so not personal' );
		$off = Honk_Details::compose( 'woo_new_order', $spec, array() );
		$this->assertSame( 'B', $off['title'] );
		$this->assertSame( 'two', $off['message'] );
		$this->assertSame( 'nothing', Honk_Details::compose( 'x', array( 'lines' => array( Honk_Details::text( 'a', 'z' ) ), 'fallback' => 'nothing' ), array() )['message'] );

		// A personal detail that is on marks the message as personal data, even when its text is empty.
		$pii = Honk_Details::compose( 'woo_new_order', array( 'lines' => array( Honk_Details::text( '', 'email' ) ) ), array( 'email' => true ) );
		$this->assertTrue( $pii['pii'] );
	}

	public function test_every_detail_has_a_label_and_every_event_says_what_is_always_included() {
		$this->assertSame( array_keys( Honk_Events::DEFAULTS ), array_keys( Honk_Details::FACTS ), 'one entry per event, in the same order' );
		foreach ( Honk_Details::FACTS as $event => $facts ) {
			$this->assertNotEmpty( Honk_Details::always( $event ), $event );
			foreach ( $facts as $fact => $meta ) {
				$this->assertNotSame( $fact, Honk_Details::label( $event, $fact ), $event . ' ' . $fact );
				$this->assertIsBool( $meta[0] );
				$this->assertIsBool( $meta[1] );
			}
		}
	}

	public function test_every_preview_uses_every_choice() {
		// Every form plugin "active".
		foreach ( array( 'ELEMENTOR_PRO_VERSION', 'FLUENTFORM_VERSION' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				define( $constant, '1.0' );
			}
		}
		foreach ( array( 'GFForms', 'Ninja_Forms' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				eval( 'class ' . $class . ' {}' );
			}
		}
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return 'date_format' === $name ? 'F j, Y' : ( 'honk_settings' === $name ? array( 'burst_minutes' => 5 ) : $default );
			}
		);
		Honk_Settings::flush();
		$specs = Honk_Preview::specs();
		$this->assertSame( array_keys( Honk_Events::DEFAULTS ), array_keys( $specs ), 'every event has a preview (WooCommerce and the form plugins are "active" in the tests)' );
		foreach ( $specs as $event => $spec ) {
			$none = Honk_Details::compose( $event, $spec, array() );
			$this->assertNotSame( '', $none['title'], $event . ': a title' );
			$all = array();
			foreach ( array_merge( array_keys( Honk_Details::facts( $event ) ), array_keys( Honk_Details::actions( $event ) ) ) as $fact ) {
				$all[ $fact ] = true;
			}
			$every = Honk_Details::compose( $event, $spec, $all );
			foreach ( array_keys( $all ) as $fact ) {
				$alone   = Honk_Details::compose( $event, $spec, array( $fact => true ) );
				$without = Honk_Details::compose( $event, $spec, array_merge( $all, array( $fact => false ) ) );
				$this->assertTrue( $alone !== $none || $without !== $every, $event . ': "' . $fact . '" changes nothing in the preview' );
			}
		}
	}

	public function test_the_preview_reads_like_the_message() {
		$specs = Honk_Preview::specs();
		$order = Honk_Details::compose( 'woo_new_order', $specs['woo_new_order'], Honk_Details::states( 'woo_new_order' ) );
		$this->assertSame( array( 'title' => 'New order #1234', 'message' => "Order #1234 · €84.00 · 3 items\nProcessing · Credit card", 'pii' => false, 'actions' => array() ), $order );
		$this->options['honk_settings']['include_pii'] = true;
		Honk_Settings::flush();
		$order = Honk_Details::compose( 'woo_new_order', $specs['woo_new_order'], Honk_Details::states( 'woo_new_order' ) );
		$this->assertSame( "Order #1234 · €84.00 · 3 items\nProcessing · Credit card\nJane Doe · jane@example.com", $order['message'] );
	}

	public function test_form_plugins_list_their_fields() {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			eval(
				'class WPCF7_ContactForm {
					public static $args;
					public static function find( $args ) { self::$args = $args; return array( new self() ); }
					public function id() { return 10; }
					public function title() { return "Quote request"; }
					public function scan_form_tags() {
						return array(
							(object) array( "name" => "your-name", "basetype" => "text" ),
							(object) array( "name" => "your-email", "basetype" => "email" ),
							(object) array( "name" => "", "basetype" => "submit" ),
							(object) array( "name" => "quiz-1", "basetype" => "quiz" ),
						);
					}
				}'
			);
		}
		if ( ! class_exists( 'GFAPI' ) ) {
			eval(
				'class GFAPI {
					public static function get_forms( $active = true, $trash = false, $sort = "id", $dir = "ASC" ) {
						return array( array( "id" => 2, "title" => "Booking", "fields" => array(
							(object) array( "id" => 1, "label" => "Your name", "type" => "name" ),
							(object) array( "id" => 4, "label" => "Notes", "type" => "textarea" ),
							(object) array( "id" => 5, "label" => "", "type" => "page" ),
						) ) );
					}
				}'
			);
		}
		$this->assertSame(
			array( '10' => array( 'title' => 'Quote request', 'fields' => array( 'your-name' => array( 'label' => 'Name', 'type' => 'text' ), 'your-email' => array( 'label' => 'Email', 'type' => 'email' ) ) ) ),
			Honk_Module_Forms::forms( 'form_cf7' )
		);
		$this->assertSame( Honk_Module_Forms::MAX_LISTED_FORMS, WPCF7_ContactForm::$args['posts_per_page'] );
		$this->assertSame(
			array( '2' => array( 'title' => 'Booking', 'fields' => array( '1' => array( 'label' => 'Your name', 'type' => 'name' ), '4' => array( 'label' => 'Notes', 'type' => 'textarea' ) ) ) ),
			Honk_Module_Forms::forms( 'form_gravityforms' )
		);

		$handler = new class() {
			public $args;
			public function get( $id, $args ) {
				$this->args = $args;
				return array( new WP_Post( array( 'ID' => 3, 'post_title' => 'Contact', 'post_content' => json_encode( array( 'fields' => array( '1' => array( 'id' => '1', 'type' => 'name', 'label' => 'Name' ), '2' => array( 'id' => '2', 'type' => 'email', 'label' => 'Email' ), '5' => array( 'id' => '5', 'type' => 'hidden', 'label' => 'Ref' ) ) ) ) ) ) );
			}
		};
		$wpforms = new class( $handler ) {
			private $handler;
			public function __construct( $handler ) {
				$this->handler = $handler;
			}
			public function obj( $name ) {
				return 'form' === $name ? $this->handler : null;
			}
		};
		Functions\when( 'wpforms' )->justReturn( $wpforms );
		$this->assertSame(
			array( '3' => array( 'title' => 'Contact', 'fields' => array( '1' => array( 'label' => 'Name', 'type' => 'name' ), '2' => array( 'label' => 'Email', 'type' => 'email' ) ) ) ),
			Honk_Module_Forms::forms( 'form_wpforms' )
		);
		$this->assertSame( Honk_Module_Forms::MAX_LISTED_FORMS, $handler->args['posts_per_page'] );
		$this->assertSame( array(), Honk_Module_Forms::forms( 'form_fluentforms' ), 'Fluent Forms lists nothing' );

		// The preview of a listed form uses its fields, each with its own choice.
		$spec = Honk_Preview::spec( 'form_cf7' );
		$this->assertSame( 'Quote request', $spec['title'][0]['t'] );
		$composed = Honk_Details::compose( 'form_cf7', $spec, array( 'values' => true, 'field:10:your-name' => true, 'field:10:your-email' => false ) );
		$this->assertSame( 'Name: Jane Doe', $composed['message'] );
	}

	public function test_a_form_plugin_whose_api_breaks_lists_nothing() {
		Functions\when( 'wpforms' )->alias(
			function () {
				throw new RuntimeException( 'changed' );
			}
		);
		$this->assertSame( array(), Honk_Module_Forms::forms( 'form_wpforms' ) );
	}
}
