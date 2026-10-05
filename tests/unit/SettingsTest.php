<?php
/**
 * Settings: defaults, sanitizing, the key never echoed, environments, server URLs.
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey\Functions;

class SettingsTest extends Honk_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'add_settings_error' )->justReturn( null );
	}

	public function test_defaults_send_nothing() {
		$this->assertFalse( Honk_Settings::is_configured() );
		$this->assertSame( 'https://honk-me.app', Honk_Settings::server_url() );
		$this->assertFalse( Honk_Settings::include_pii() );
	}

	public function test_a_valid_key_is_saved_and_an_empty_field_keeps_it() {
		$out = Honk_Settings::sanitize( array( 'api_key' => ' honk_ab12cd34_secretsecretsecret ' ) );
		$this->assertSame( 'honk_ab12cd34_secretsecretsecret', $out['api_key'] );
		$this->options['honk_settings'] = $out;
		Honk_Settings::flush();
		$again = Honk_Settings::sanitize( array( 'api_key' => '' ) );
		$this->assertSame( 'honk_ab12cd34_secretsecretsecret', $again['api_key'] );
		$removed = Honk_Settings::sanitize( array( 'api_key' => '', 'remove_key' => '1' ) );
		$this->assertSame( '', $removed['api_key'] );
	}

	public function test_an_invalid_key_is_rejected() {
		$this->configure();
		$out = Honk_Settings::sanitize( array( 'api_key' => 'not-a-key' ) );
		$this->assertSame( 'honk_ab12cd34_secretsecretsecret', $out['api_key'] );
	}

	public function test_the_key_is_masked_for_display() {
		$this->assertSame( 'honk_ab12cd34_••••••••', Honk_Settings::mask_key( 'honk_ab12cd34_secretsecretsecret' ) );
		$this->assertStringNotContainsString( 'secret', Honk_Settings::mask_key( 'honk_ab12cd34_secretsecretsecret' ) );
	}

	public function test_server_urls_must_be_https_except_local_hosts() {
		$this->assertTrue( Honk_Settings::is_valid_server_url( 'https://honk-me.app' ) );
		$this->assertTrue( Honk_Settings::is_valid_server_url( 'http://localhost:8090' ) );
		$this->assertTrue( Honk_Settings::is_valid_server_url( 'http://host.docker.internal:28437' ) );
		$this->assertTrue( Honk_Settings::is_valid_server_url( 'http://192.168.1.20' ) );
		$this->assertFalse( Honk_Settings::is_valid_server_url( 'http://honk-me.app' ) );
		$this->assertFalse( Honk_Settings::is_valid_server_url( 'http://8.8.8.8' ) );
		$this->assertFalse( Honk_Settings::is_valid_server_url( 'https://user:pass@honk-me.app' ) );
		$this->assertFalse( Honk_Settings::is_valid_server_url( 'ftp://honk-me.app' ) );

		$out = Honk_Settings::sanitize( array( 'server_url' => 'http://example.com' ) );
		$this->assertSame( 'https://honk-me.app', $out['server_url'] );
		$out = Honk_Settings::sanitize( array( 'server_url' => 'https://honk.example.com/' ) );
		$this->assertSame( 'https://honk.example.com', $out['server_url'] );
	}

	public function test_event_rows_are_sanitized_and_unposted_rows_kept() {
		$this->options['honk_settings'] = array( 'events' => array( 'form_cf7' => array( 'enabled' => false, 'severity' => 'error', 'priority' => 'high' ) ) );
		Honk_Settings::flush();
		$out = Honk_Settings::sanitize(
			array(
				'_form'  => '1',
				'events' => array(
					'woo_new_order' => array( 'enabled' => '1', 'severity' => 'critical', 'priority' => 'urgent' ),
					'new_administrator' => array( 'severity' => 'bogus', 'priority' => 'bogus' ),
					'not_an_event'  => array( 'enabled' => '1', 'severity' => 'info' ),
				),
			)
		);
		$this->assertSame( array( 'enabled' => true, 'severity' => 'critical', 'priority' => 'urgent' ), $out['events']['woo_new_order'] );
		$this->assertSame( array( 'enabled' => false, 'severity' => 'critical', 'priority' => 'high' ), $out['events']['new_administrator'] );
		$this->assertArrayNotHasKey( 'not_an_event', $out['events'] );
		$this->assertSame( 'error', $out['events']['form_cf7']['severity'], 'a row not on the form keeps its stored value' );
		$this->assertFalse( $out['include_pii'] );
	}

	public function test_sanitizing_an_already_sanitized_array_keeps_everything() {
		$first  = Honk_Settings::sanitize( array( '_form' => '1', 'include_pii' => '1', 'api_key' => 'honk_ab12cd34_secretsecretsecret', 'events' => array( 'woo_new_order' => array( 'enabled' => '', 'severity' => 'info', 'priority' => 'low' ) ) ) );
		$second = Honk_Settings::sanitize( $first );
		$this->assertTrue( $second['include_pii'] );
		$this->assertFalse( $second['events']['woo_new_order']['enabled'] );
	}

	public function test_environment_follows_wordpress_unless_set() {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		$this->assertSame( 'development', Honk_Settings::environment() );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );
		$this->assertSame( 'staging', Honk_Settings::environment() );
		$this->options['honk_settings'] = array( 'environment' => 'production' );
		Honk_Settings::flush();
		$this->assertSame( 'production', Honk_Settings::environment() );
	}

	public function test_notification_language_defaults_to_the_site() {
		Functions\when( 'get_locale' )->justReturn( 'ro_RO' );
		$this->assertSame( 'ro_RO', Honk_Settings::notification_locale() );
		$this->options['honk_settings'] = array( 'language' => 'de_DE' );
		Honk_Settings::flush();
		$this->assertSame( 'de_DE', Honk_Settings::notification_locale() );
	}

	public function test_events_have_valid_defaults_and_labels() {
		foreach ( Honk_Events::DEFAULTS as $id => $row ) {
			$this->assertContains( $row[0], array( 'security', 'health', 'content', 'forms', 'woocommerce' ), $id );
			$this->assertContains( $row[2], Honk_Settings::SEVERITIES, $id );
			$this->assertContains( $row[3], Honk_Settings::PRIORITIES, $id );
			$this->assertContains( $row[4], Honk_Payload::CATEGORIES, $id );
			$this->assertNotSame( $id, Honk_Events::label( $id )[0], $id );
		}
		// The plan's defaults.
		$this->assertSame( 'success', Honk_Events::defaults( 'woo_new_order' )['severity'] );
		$this->assertSame( 'warning', Honk_Events::defaults( 'woo_payment_failed' )['severity'] );
		$this->assertSame( 'error', Honk_Events::defaults( 'fatal_error' )['severity'] );
		$this->assertSame( 'critical', Honk_Events::defaults( 'new_administrator' )['severity'] );
		$this->assertFalse( Honk_Events::defaults( 'post_published' )['enabled'] );
		$this->assertFalse( Honk_Events::defaults( 'woo_daily_summary' )['enabled'] );
	}

	public function test_source_is_the_site_host() {
		$this->assertSame( 'shop.example.com', Honk_Settings::source() );
	}
}
