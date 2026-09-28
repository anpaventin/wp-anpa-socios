<?php
/**
 * Minimal OpenDocument spreadsheet (.ods) writer (1.74.0). No external library:
 * an .ods is a zip with a few XML files. Every cell is written as text, so no
 * formula can ever run (no CSV/formula injection). content_xml() is pure;
 * documento() needs PHP's ZipArchive.
 *
 * @since   1.74.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Builds .ods files from plain tables.
 *
 * @since 1.74.0
 */
final class ANPA_Socios_Ods {

	const MIME = 'application/vnd.oasis.opendocument.spreadsheet';

	/**
	 * Safe sheet name: no characters spreadsheets reject, at most 31 characters.
	 *
	 * @param  string $nome Wanted name.
	 * @return string
	 */
	public static function nome_folla( string $nome ): string {
		$nome = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( array( '/', '\\', '?', '*', '[', ']', ':' ), array( '-', '-', '', '', '(', ')', '' ), $nome ) ) );
		if ( '' === $nome ) {
			return 'Folla';
		}
		return mb_substr( $nome, 0, 31 );
	}

	/**
	 * content.xml for the given sheets.
	 *
	 * @param  array<int,array{nome:string,cabeceira:array<int,string>,filas:array<int,array<int,string>>}> $follas Sheets.
	 * @return string
	 */
	public static function content_xml( array $follas ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.2">'
			. '<office:automatic-styles>'
			. '<style:style style:name="columna" style:family="table-column"><style:table-column-properties style:column-width="5cm"/></style:style>'
			. '<style:style style:name="cabeceira" style:family="table-cell"><style:table-cell-properties fo:background-color="#e8eaec" style:vertical-align="top"/><style:text-properties fo:font-weight="bold"/></style:style>'
			. '<style:style style:name="celda" style:family="table-cell"><style:table-cell-properties style:vertical-align="top" fo:wrap-option="wrap"/></style:style>'
			. '</office:automatic-styles><office:body><office:spreadsheet>';
		$usados = array();
		foreach ( $follas as $folla ) {
			$nome = self::nome_folla( (string) ( $folla['nome'] ?? '' ) );
			// Sheet names must be unique within the file.
			$base = $nome;
			for ( $i = 2; isset( $usados[ $nome ] ); $i++ ) {
				$nome = mb_substr( $base, 0, 27 ) . ' (' . $i . ')';
			}
			$usados[ $nome ] = true;
			$cab   = array_values( (array) ( $folla['cabeceira'] ?? array() ) );
			$xml  .= '<table:table table:name="' . self::esc( $nome ) . '">';
			$xml  .= '<table:table-column table:style-name="columna" table:number-columns-repeated="' . max( 1, count( $cab ) ) . '"/>';
			$xml  .= self::fila_xml( $cab, 'cabeceira' );
			foreach ( (array) ( $folla['filas'] ?? array() ) as $fila ) {
				$xml .= self::fila_xml( array_values( (array) $fila ), 'celda' );
			}
			$xml .= '</table:table>';
		}
		return $xml . '</office:spreadsheet></office:body></office:document-content>';
	}

	/**
	 * The whole .ods file, or null when ZipArchive is not available.
	 *
	 * @param  array<int,array{nome:string,cabeceira:array<int,string>,filas:array<int,array<int,string>>}> $follas Sheets.
	 * @return string|null
	 */
	public static function documento( array $follas ): ?string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}
		$dir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$tmp = @tempnam( $dir, 'anpa-ods' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a notice would corrupt the binary response.
		if ( false === $tmp || '' === $tmp ) {
			return null;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}
		// ODS rule: «mimetype» first and stored uncompressed.
		$zip->addFromString( 'mimetype', self::MIME );
		$zip->setCompressionName( 'mimetype', ZipArchive::CM_STORE );
		$zip->addFromString( 'META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
			. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
			. '<manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="' . self::MIME . '"/>'
			. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
			. '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
			. '</manifest:manifest>' );
		$zip->addFromString( 'styles.xml', '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" office:version="1.2"/>' );
		$ok = $zip->addFromString( 'content.xml', self::content_xml( $follas ) );
		// Never serve a truncated file: any failure → null (the caller offers the CSV instead).
		if ( ! $ok || true !== $zip->close() ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}
		$bytes = file_get_contents( $tmp );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return ( false === $bytes || '' === $bytes ) ? null : $bytes;
	}

	/**
	 * @param array<int,string> $valores Cell values.
	 * @param string            $estilo  Cell style.
	 */
	private static function fila_xml( array $valores, string $estilo ): string {
		$xml = '<table:table-row>';
		foreach ( $valores as $v ) {
			$xml .= '<table:table-cell table:style-name="' . $estilo . '" office:value-type="string">';
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $v ) as $linha ) {
				$xml .= '<text:p>' . self::esc( $linha ) . '</text:p>';
			}
			$xml .= '</table:table-cell>';
		}
		return $xml . '</table:table-row>';
	}

	private static function esc( string $s ): string {
		// Drop characters XML 1.0 does not allow; an invalid UTF-8 byte becomes U+FFFD
		// instead of wiping the whole cell.
		$s = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s );
		$s = htmlspecialchars( $s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
		return str_replace( array( "\u{FFFE}", "\u{FFFF}" ), '', $s );
	}
}
