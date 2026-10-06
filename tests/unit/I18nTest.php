<?php
/**
 * The notification language: WordPress's own translations (language packs), switched with
 * switch_to_locale(); the plugin ships no translation files.
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey\Functions;

class I18nTest extends Honk_Test_Case {

	private $switched = array();

	private $restored = 0;

	protected function setUp(): void {
		parent::setUp();
		$self = $this;
		Functions\when( 'switch_to_locale' )->alias(
			function ( $locale ) use ( $self ) {
				$self->switched[] = $locale;
				return in_array( $locale, array( 'en_US', 'ro_RO', 'de_DE' ), true ); // the installed languages
			}
		);
		Functions\when( 'restore_previous_locale' )->alias(
			function () use ( $self ) {
				++$self->restored;
				return 'en_US';
			}
		);
		Functions\when( 'add_settings_error' )->justReturn( null );
	}

	private function language( $language ) {
		$this->options['honk_settings'] = array( 'language' => $language );
		Honk_Settings::flush();
	}

	public function test_the_notification_language_is_switched_to_and_back() {
		$this->language( 'ro_RO' );
		$result = Honk_I18n::with_locale(
			function () {
				return 'written';
			}
		);
		$this->assertSame( 'written', $result );
		$this->assertSame( array( 'ro_RO' ), $this->switched );
		$this->assertSame( 1, $this->restored );
	}

	public function test_a_language_that_is_not_installed_falls_back_to_the_current_one() {
		$this->language( 'fr_FR' );
		$this->assertSame( 'written', Honk_I18n::with_locale( function () { return 'written'; } ) );
		$this->assertSame( array( 'fr_FR' ), $this->switched );
		$this->assertSame( 0, $this->restored, 'nothing to restore when the switch failed' );
	}

	public function test_no_switch_when_the_language_is_already_active_or_too_early() {
		$this->language( '' ); // the site language, en_US in the tests
		Honk_I18n::with_locale( function () { return 1; } );
		$this->language( 'de_DE' );
		Functions\when( 'did_action' )->justReturn( 0 ); // before after_setup_theme
		Honk_I18n::with_locale( function () { return 1; } );
		$this->assertSame( array(), $this->switched );
	}

	public function test_the_locale_is_restored_when_the_text_fails() {
		$this->language( 'de_DE' );
		try {
			Honk_I18n::with_locale(
				function () {
					throw new RuntimeException( 'boom' );
				}
			);
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( 1, $this->restored );
	}

	public function test_the_languages_are_english_and_every_installed_language() {
		Functions\when( 'get_available_languages' )->justReturn( array( 'ro_RO', 'pt_BR', 'de_DE_formal' ) );
		Functions\when( 'get_site_transient' )->justReturn( array( 'pt_BR' => array( 'native_name' => 'Português do Brasil' ) ) );
		$this->assertSame(
			array(
				'en_US'        => 'English (United States)',
				'ro_RO'        => 'Română',
				'pt_BR'        => 'Português do Brasil',
				'de_DE_formal' => 'de_DE_formal',
			),
			Honk_I18n::languages()
		);
	}

	public function test_any_well_formed_locale_can_be_saved() {
		$this->assertSame( 'pt_BR', Honk_Settings::sanitize( array( 'language' => 'pt_BR' ) )['language'] );
		$this->assertSame( 'de_DE_formal', Honk_Settings::sanitize( array( 'language' => 'de_DE_formal' ) )['language'] );
		$this->assertSame( '', Honk_Settings::sanitize( array( 'language' => '../../evil' ) )['language'] );
		$this->assertSame( '', Honk_Settings::sanitize( array( 'language' => '' ) )['language'] );
		$this->language( 'not a locale' );
		$this->assertSame( 'en_US', Honk_Settings::notification_locale(), 'an invalid saved value means the site language' );
	}

	public function test_the_plugin_is_honk_me_and_ships_no_translation_files() {
		$header = file_get_contents( HONK_DIR . 'honk-me.php' );
		$this->assertStringContainsString( 'Plugin Name:          Honk Me – Notifications for Sites, Shops and Forms', $header );
		$this->assertStringContainsString( 'Text Domain:          honk-me', $header );
		$this->assertStringNotContainsString( 'Domain Path', $header );
		$this->assertSame( 'honk-me', Honk_I18n::DOMAIN );
		$this->assertDirectoryDoesNotExist( HONK_DIR . 'languages' );
		$this->assertStringContainsString( '/languages-src', file_get_contents( HONK_DIR . '.distignore' ) );
		$readme = file_get_contents( HONK_DIR . 'readme.txt' );
		$this->assertStringStartsWith( '=== Honk Me – Notifications for Sites, Shops and Forms ===', $readme );
		$this->assertMatchesRegularExpression( '/^Contributors: honkme$/m', $readme );

		// Every translatable string uses the honk-me text domain.
		$files = array_merge( array( HONK_DIR . 'honk-me.php' ), glob( HONK_DIR . 'includes/*.php' ), glob( HONK_DIR . 'includes/modules/*.php' ) );
		foreach ( $files as $file ) {
			$code = file_get_contents( $file );
			$this->assertSame( 0, preg_match_all( "/\\b(?:__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\([^;]*?,\\s*'honk'\\s*\\)/", $code ), basename( $file ) );
			$this->assertStringNotContainsString( 'load_plugin_textdomain', $code, basename( $file ) );
		}
	}
}
