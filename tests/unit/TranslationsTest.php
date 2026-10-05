<?php
/**
 * The bundled translations: complete, same placeholders as English, glossary terms.
 *
 * @package Honk
 */

// phpcs:disable

class TranslationsTest extends Honk_Test_Case {

	/**
	 * Entries of a .po file: msgid => [ msgid_plural, msgstr[] ].
	 */
	private function parse( $file ) {
		$entries = array();
		$blocks  = preg_split( "/\n\n/", trim( file_get_contents( $file ) ) );
		foreach ( $blocks as $block ) {
			if ( ! preg_match( '/^msgid "(.*)"$/m', $block, $id ) || '' === $id[1] ) {
				continue;
			}
			preg_match( '/^msgid_plural "(.*)"$/m', $block, $plural );
			preg_match_all( '/^msgstr(?:\[\d\])? "(.*)"$/m', $block, $strs );
			$entries[ stripcslashes( $id[1] ) ] = array( isset( $plural[1] ) ? stripcslashes( $plural[1] ) : null, array_map( 'stripcslashes', $strs[1] ) );
		}
		return $entries;
	}

	private function placeholders( $text ) {
		preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $m );
		$list = array_map(
			function ( $p ) {
				return preg_replace( '/^%\d+\$/', '%', $p ) === $p ? $p : $p;
			},
			$m[0]
		);
		sort( $list );
		return $list;
	}

	public function locales() {
		return array( array( 'ro_RO', 3 ), array( 'es_ES', 2 ), array( 'fr_FR', 2 ), array( 'de_DE', 2 ) );
	}

	/**
	 * @dataProvider locales
	 */
	public function test_every_string_is_translated_with_the_same_placeholders( $locale, $forms ) {
		$pot = $this->parse( HONK_DIR . 'languages/honk.pot' );
		$po  = $this->parse( HONK_DIR . 'languages/honk-' . $locale . '.po' );
		$this->assertFileExists( HONK_DIR . 'languages/honk-' . $locale . '.mo' );
		foreach ( $pot as $msgid => $entry ) {
			if ( 'https://honk-me.app' === $msgid ) {
				continue;
			}
			$this->assertArrayHasKey( $msgid, $po, $locale . ': missing ' . $msgid );
			$strs = $po[ $msgid ][1];
			if ( null !== $entry[0] ) {
				$this->assertCount( $forms, $strs, $locale . ': plural forms of ' . $msgid );
			}
			foreach ( $strs as $str ) {
				$this->assertNotSame( '', $str, $locale . ': empty translation of ' . $msgid );
				$numbered = (bool) preg_match( '/%\d\$/', $msgid );
				if ( $numbered || null === $entry[0] ) {
					$this->assertSame( $this->placeholders( $msgid ), $this->placeholders( $str ), $locale . ': placeholders of ' . $msgid );
				}
			}
		}
	}

	public function test_the_honk_scale_follows_the_glossary() {
		$scale = array(
			'ro_RO' => array( 'Claxon ușor', 'Bip-bip', 'Claxon puternic', 'Claxon lung', 'Claxon continuu' ),
			'es_ES' => array( 'Bocinazo suave', 'Bip-bip', 'Bocinazo fuerte', 'Bocinazo largo', 'Bocina sin parar' ),
			'fr_FR' => array( 'Petit coup de klaxon', 'Bip-bip', 'Coup de klaxon appuyé', 'Long coup de klaxon', 'Klaxon continu' ),
			'de_DE' => array( 'Leichtes Hupen', 'Tüt-tüt', 'Lautes Hupen', 'Langes Hupen', 'Dauerhupen' ),
		);
		$english = array( 'Light honk', 'Beep-beep', 'Loud honk', 'Long honk', 'Blast' );
		foreach ( $scale as $locale => $names ) {
			$po = $this->parse( HONK_DIR . 'languages/honk-' . $locale . '.po' );
			foreach ( $english as $i => $msgid ) {
				$this->assertSame( $names[ $i ], $po[ $msgid ][1][0], $locale . ' ' . $msgid );
			}
		}
	}

	public function test_french_uses_non_breaking_spaces_before_colons() {
		$po = $this->parse( HONK_DIR . 'languages/honk-fr_FR.po' );
		$this->assertSame( "Rôle\u{00a0}: %s", $po['Role: %s'][1][0] );
		$this->assertStringNotContainsString( ' :', implode( "\n", array_merge( ...array_column( $po, 1 ) ) ) );
	}
}
