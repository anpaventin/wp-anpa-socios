<?php
/**
 * 1.63.1: the update checker's «Check for updates» link and result notice are
 * labelled in the plugin's language (the library has Spanish but no Galician).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Updater_Ui extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_updater_hooks_the_two_library_filters_with_the_slug(): void {
		$src = $this->src( 'includes/class-anpa-socios-updater.php' );
		$this->assertStringContainsString( "add_filter( 'puc_manual_check_link-' . self::SLUG, array( __CLASS__, 'manual_check_link_text' ) );", $src );
		$this->assertStringContainsString( "add_filter( 'puc_manual_check_message-' . self::SLUG, array( __CLASS__, 'manual_check_message' ), 10, 2 );", $src );
		$this->assertStringContainsString( "const SLUG = 'anpa-socios'", $src );
		// The library really applies both filters with these names.
		$ui = $this->src( 'includes/lib/plugin-update-checker/Puc/v5p7/Plugin/Ui.php' );
		$this->assertStringContainsString( "getUniqueName('manual_check_link')", $ui );
		$this->assertStringContainsString( "getUniqueName('manual_check_message')", $ui );
		$this->assertStringContainsString( "__('Check for updates', 'plugin-update-checker')", $ui );
	}

	public function test_texts_are_galician_and_cover_every_library_status(): void {
		if ( ! class_exists( 'ANPA_Socios_Updater' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-updater.php';
		}
		$this->assertSame( 'Comprobar actualizacións', ANPA_Socios_Updater::manual_check_link_text() );
		$this->assertStringContainsString( 'está actualizado', ANPA_Socios_Updater::manual_check_message( 'x', 'no_update' ) );
		$this->assertStringContainsString( 'nova versión', ANPA_Socios_Updater::manual_check_message( 'x', 'update_available' ) );
		$this->assertStringContainsString( 'Non se puido comprobar', ANPA_Socios_Updater::manual_check_message( 'x', 'error' ) );
		$this->assertSame( 'orixinal', ANPA_Socios_Updater::manual_check_message( 'orixinal', 'algo_novo' ), 'unknown statuses keep the library message' );
	}

	public function test_spanish_catalogue_translates_the_new_strings(): void {
		$po = $this->src( 'languages/anpa-socios-es_ES.po' );
		$this->assertStringContainsString( 'msgid "Comprobar actualizacións"', $po );
		$this->assertStringContainsString( 'msgstr "Comprobar actualizaciones"', $po );
		$this->assertStringContainsString( 'msgid "ANPA Socios está actualizado: non hai ningunha versión nova."', $po );
		$this->assertFileExists( dirname( __DIR__ ) . '/languages/anpa-socios-es_ES.mo' );
		// The two entries mangled by an old escaping bug ("e\nvíos") are gone.
		$this->assertStringNotContainsString( 'e\nvíos', $po );
	}
}
