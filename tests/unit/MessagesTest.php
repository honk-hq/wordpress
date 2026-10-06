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
		$golden = json_decode( file_get_contents( self::FIXTURE ), true );
		$this->assertArrayHasKey( $name, $golden );
		$this->assertSame( $golden[ $name ]['off'], $off, $name . ', personal data off' );
		$this->assertSame( $golden[ $name ]['on'], $on, $name . ', personal data on' );
	}
}
