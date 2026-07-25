<?php
/**
 * Shared helper for the inspection tests (support-only source assertions).
 *
 * The pattern these tests use is `strpos()` to find a marker and `substr()` to
 * take a window around it. When the marker is renamed, `strpos()` returns false,
 * `(int) false` is 0, and the window silently starts at the top of the file: the
 * test then asserts on the wrong text, or passes while testing nothing.
 *
 * `marker()` turns that silent drift into an explicit failure.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

trait ANPA_Socios_Inspection_Helpers {

	/**
	 * Offset of a required marker in a source string.
	 *
	 * @param  string $source Source code.
	 * @param  string $marker Text that must exist.
	 * @return int Offset.
	 */
	protected function marker( string $source, string $marker ): int {
		$offset = strpos( $source, $marker );
		$this->assertIsInt( $offset, "inspection marker not found: {$marker}" );

		return (int) $offset;
	}
}
