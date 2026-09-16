<?php
/**
 * 1.65.5: CSV downloads (Xestión «Exportar CSV», Lista Gmail, empresa/comedor panel) build a blob URL and
 * click a temporary <a download>. The browser starts the download after the click returns, so the blob
 * URL must not be revoked synchronously: Firefox and recent Chromium cancelled the download silently and
 * the UI still said «descargado». Contract: both helpers revoke inside a deferred callback.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Csv_Download_Blob extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	/** @return array<string,array{string}> */
	public function scripts(): array {
		return array(
			'admin-management.js' => array( 'assets/js/admin-management.js' ),
			'area.js'             => array( 'assets/js/area.js' ),
		);
	}

	/** @dataProvider scripts */
	public function test_blob_url_is_not_revoked_synchronously_after_click( string $rel ): void {
		$js = $this->src( $rel );
		$this->assertStringContainsString( 'URL.createObjectURL(blob)', $js );
		$this->assertDoesNotMatchRegularExpression(
			'/a\.click\(\);\s*document\.body\.removeChild\(a\);\s*URL\.revokeObjectURL\(url\);/',
			$js,
			"$rel revokes the blob URL right after the click: the download is cancelled in Firefox/Chromium"
		);
	}

	/** @dataProvider scripts */
	public function test_blob_url_is_revoked_in_a_deferred_callback( string $rel ): void {
		$js = $this->src( $rel );
		$this->assertMatchesRegularExpression(
			'/setTimeout\((?:function \(\)|\(\) =>) \{\s*document\.body\.removeChild\(a\);\s*URL\.revokeObjectURL\(url\);\s*\}, \d{4,}\);/',
			$js,
			"$rel must revoke the blob URL in a setTimeout of at least a second"
		);
	}
}
