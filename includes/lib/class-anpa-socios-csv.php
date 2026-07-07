<?php
/**
 * CSV formatting helpers with formula-injection defense.
 *
 * All cell values are sanitized before encoding to prevent formula
 * injection in spreadsheet applications (Excel, LibreOffice Calc).
 *
 * @since  1.4.0
 * @package ANPA_Socios
 */

// Guard: in production WordPress context, ABSPATH is always defined.
// In CLI/test context, this helper works fine without WordPress.
if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Pure CSV helper — no WordPress dependencies.
 *
 * @since 1.4.0
 */
final class ANPA_Socios_Csv {

	/**
	 * Characters that trigger formula interpretation in spreadsheet apps.
	 *
	 * @since 1.4.0
	 * @var string[]
	 */
	private const FORMULA_CHARS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * UTF-8 byte-order mark.
	 *
	 * Prepended to downloadable documents so spreadsheet apps (notably
	 * Excel on Windows) detect UTF-8 instead of the system locale and
	 * render accented characters (á, í, ñ, …) correctly.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	public const UTF8_BOM = "\xEF\xBB\xBF";

	/**
	 * Sanitizes and encodes a single cell value for CSV output.
	 *
	 * Formula injection defense: if the value starts with any of the
	 * dangerous characters (=, +, -, @, TAB, CR), a single-quote prefix
	 * is added to neutralize formula execution.
	 *
	 * The value is always wrapped in double quotes with embedded quotes
	 * doubled (RFC 4180 + injection defense).
	 *
	 * @since  1.4.0
	 * @param  string $value Raw cell value.
	 * @return string Encoded cell ready for CSV output.
	 */
	public static function cell( string $value ): string {
		// Formula injection defense: prefix dangerous leading chars. Also inspect
		// the first NON-whitespace char, since spreadsheet importers may trim
		// leading spaces/tabs and then interpret e.g. " =cmd" as a formula.
		if ( '' !== $value ) {
			$first_nonspace = ltrim( $value, " \t" );
			$first_nonspace = '' !== $first_nonspace ? $first_nonspace[0] : '';
			if ( in_array( $value[0], self::FORMULA_CHARS, true )
				|| ( '' !== $first_nonspace && in_array( $first_nonspace, self::FORMULA_CHARS, true ) ) ) {
				$value = "'" . $value;
			}
		}

		// Double embedded quotes and wrap in double quotes.
		return '"' . str_replace( '"', '""', $value ) . '"';
	}

	/**
	 * Encodes an array of values as a single CSV row (CRLF terminated).
	 *
	 * @since  1.4.0
	 * @param  array<int,string> $values Row values.
	 * @return string CSV row ending with CRLF.
	 */
	public static function row( array $values ): string {
		$cells = array_map( array( __CLASS__, 'cell' ), $values );

		return implode( ',', $cells ) . "\r\n";
	}

	/**
	 * Builds a complete CSV document from a header row and data rows.
	 *
	 * @since  1.4.0
	 * @param  string[]   $headers Column headers.
	 * @param  array[]    $rows    Array of associative arrays (DB rows).
	 * @return string Complete CSV content (UTF-8 with BOM, CRLF line endings).
	 */
	public static function document( array $headers, array $rows ): string {
		$output = self::UTF8_BOM . self::row( $headers );

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $headers as $col ) {
				$values[] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
			}
			$output .= self::row( $values );
		}

		return $output;
	}
}
