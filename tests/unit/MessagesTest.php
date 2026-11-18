<?php
/**
 * The messages of every event, with the default details, are exactly what 0.1.0 sent: choosing
 * details changes nothing until someone changes a choice.
 *
 * tests/fixtures/messages-0.1.0.json was recorded from the 0.1.0 modules (before the details
 * existed). Never re-record it to make this test pass.
 *
 * @package Honk
 */

// phpcs:disable

if ( ! class_exists( 'WPCF7_Submission' ) ) {
	class WPCF7_Submission {
		public static $posted = array();
		public static function get_instance() { return new self(); }
		public function get_posted_data() { return self::$posted; }
		public function get_meta( $key ) { return 'timestamp' === $key ? '1700000000' : ''; }
	}
}

class MessagesTest extends Honk_Test_Case {

	use Honk_Scenarios;

	const FIXTURE = __DIR__ . '/../fixtures/messages-0.1.0.json';

	protected function setUp(): void {
		parent::setUp();
		$this->stub_wordpress();
	}

	public function names() {
		return array_map(
			function ( $name ) {
				return array( $name );
			},
			array_keys( $this->scenarios() )
		);
	}

	/**
	 * @dataProvider names
	 */
	public function test_defaults_send_the_0_1_0_messages( $name ) {
		$off = $this->run_scenario( $name, false );
		$on  = $this->run_scenario( $name, true );
		$this->assertNotEmpty( $off, $name . ': nothing was queued' );
		$golden = $this->golden();
		$this->assertArrayHasKey( $name, $golden );
		$this->assertSame( $golden[ $name ]['off'], $off, $name . ', personal data off' );
		$this->assertSame( $golden[ $name ]['on'], $on, $name . ', personal data on' );
	}

	public function test_a_fatal_error_is_sent_during_the_failing_request() {
		$this->configure();
		Honk_Module_Health::report_fatal(
			array( 'message' => 'Uncaught Error: boom', 'file' => '/tmp/wordpress/wp-content/plugins/acme/acme.php', 'line' => 3 ),
			false
		);
		$this->assertCount( 1, $this->requests, 'sent right away: the loopback and WP-Cron may fatal too' );
		$this->assertSame( 2.0, $this->requests[0]['args']['timeout'] );
		$this->assertCount( 0, $this->jobs(), 'delivered, nothing left in the queue' );
	}

	public function test_a_captcha_token_is_never_a_form_field() {
		$this->configure( array( 'include_pii' => true ) );
		WPCF7_Submission::$posted = array( 'your-name' => 'Ana Pop', 'your-message' => 'Hello', 'g-recaptcha-response' => 'SECRETTOKEN123', 'cf-turnstile-response' => 'TURNSTILE456' );
		$form = new class() {
			public function id() { return 7; }
			public function title() { return 'Contact'; }
			public function scan_form_tags() {
				return array( (object) array( 'name' => 'your-name', 'basetype' => 'text' ), (object) array( 'name' => 'your-message', 'basetype' => 'textarea' ) );
			}
		};
		Honk_Module_Forms::on_cf7( $form, array( 'status' => 'mail_sent' ) );
		// Fluent Forms posts every field it has; the token is left out by its name.
		Honk_Module_Forms::on_fluentforms( 3, array( 'names' => 'Ion', 'g-recaptcha-response' => 'SECRETTOKEN789' ), (object) array( 'id' => 2, 'title' => 'Quote' ) );

		$bodies = implode( "\n", array_column( $this->jobs(), 'body' ) );
		$this->assertCount( 2, $this->jobs() );
		$this->assertStringContainsString( 'Ana Pop', $bodies );
		$this->assertStringNotContainsString( 'SECRETTOKEN', $bodies );
		$this->assertStringNotContainsString( 'TURNSTILE', $bodies );
	}
}
