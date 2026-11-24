<?php
/**
 * Board roles (1.100.0): list of roles kept in the plugin (Axustes → Contido →
 * Xunta directiva) and the public cards of [anpa_xunta_directiva].
 *
 * Pure helpers: sanitising the rows, choosing the icon and building the HTML.
 *
 * @since  1.100.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Board roles: storage rules and card markup.
 *
 * @since 1.100.0
 */
final class ANPA_Socios_Xunta_Directiva {

	/** Option holding the list of roles. */
	const OPTION = 'anpa_socios_xunta_directiva';

	/** Most rows kept. */
	const MAX = 30;

	/** Suggested role names for the admin form. */
	const SUXESTIONS = array( 'Presidencia', 'Vicepresidencia', 'Secretaría', 'Vicesecretaría', 'Tesourería', 'Vicetesourería', 'Vogal' );

	/**
	 * Lucide 0.460.0 icons (same set as the child theme cards).
	 *
	 * @var array<string,string>
	 */
	const ICONS = array(
		'user-round'     => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
		'clipboard-list' => '<rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
		'notebook-pen'   => '<path d="M13.4 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7.4"/><path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><path d="M21.378 5.626a1 1 0 1 0-3.004-3.004l-5.01 5.012a2 2 0 0 0-.506.854l-.837 2.87a.5.5 0 0 0 .62.62l2.87-.837a2 2 0 0 0 .854-.506z"/>',
		'wallet'         => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
		'credit-card'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
		'users'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'user'           => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
	);

	/**
	 * Cleans the rows sent by the admin form: trims, drops rows without a
	 * role, sorts by the «orde» number (ties keep the form order) and caps
	 * the list.
	 *
	 * @param  array<int,mixed> $rows Raw rows (cargo, nome, orde).
	 * @return array<int,array{cargo:string,nome:string}>
	 */
	public static function sanitizar( array $rows ): array {
		$out = array();
		$i   = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cargo = self::texto( $row['cargo'] ?? '' );
			if ( '' === $cargo ) {
				continue;
			}
			$orde  = isset( $row['orde'] ) && '' !== trim( (string) $row['orde'] ) ? (int) $row['orde'] : 1000 + $i;
			$out[] = array( 'k' => array( $orde, $i ), 'cargo' => $cargo, 'nome' => self::texto( $row['nome'] ?? '' ) );
			$i++;
		}
		usort( $out, static function ( $a, $b ) { return $a['k'] <=> $b['k']; } );
		$out = array_slice( $out, 0, self::MAX );
		return array_map( static function ( $r ) { return array( 'cargo' => $r['cargo'], 'nome' => $r['nome'] ); }, $out );
	}

	/**
	 * Icon for a role name.
	 *
	 * @param  string $cargo Role name.
	 * @return string Key of self::ICONS.
	 */
	public static function icona( string $cargo ): string {
		$c = mb_strtolower( $cargo, 'UTF-8' );
		if ( false !== mb_strpos( $c, 'tesour' ) || false !== mb_strpos( $c, 'tesorer' ) ) {
			return 0 === mb_strpos( $c, 'vice' ) ? 'credit-card' : 'wallet';
		}
		if ( false !== mb_strpos( $c, 'secretar' ) ) {
			return 0 === mb_strpos( $c, 'vice' ) ? 'notebook-pen' : 'clipboard-list';
		}
		if ( false !== mb_strpos( $c, 'presiden' ) ) {
			return 'user-round';
		}
		if ( false !== mb_strpos( $c, 'vogal' ) || false !== mb_strpos( $c, 'vocal' ) ) {
			return 'users';
		}
		return 'user';
	}

	/**
	 * Public cards, same markup and classes as the hand-made cards they replace.
	 *
	 * @param  array<int,array{cargo:string,nome:string}> $rows Stored rows.
	 * @return string Escaped HTML ('' when there are no roles).
	 */
	public static function html( array $rows ): string {
		if ( array() === $rows ) {
			return '';
		}
		$html = '<div class="anpa-card-grid anpa-xunta-directiva">';
		foreach ( $rows as $row ) {
			$cargo = (string) ( $row['cargo'] ?? '' );
			if ( '' === $cargo ) {
				continue;
			}
			$icona = self::icona( $cargo );
			$nome  = (string) ( $row['nome'] ?? '' );
			$html .= '<div class="anpa-card anpa-xunta-cargo">'
				. '<p class="anpa-icon-circle"><svg class="anpa-icon anpa-icon--' . $icona . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::ICONS[ $icona ] . '</svg></p>'
				. '<h4 class="wp-block-heading">' . esc_html( $cargo ) . '</h4>'
				. ( '' !== $nome ? '<p>' . esc_html( $nome ) . '</p>' : '' )
				. '</div>';
		}
		return $html . '</div>';
	}

	/**
	 * One-line plain text, at most 120 characters.
	 *
	 * @param  mixed $v Raw value.
	 * @return string
	 */
	private static function texto( $v ): string {
		$v = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $v ) ) );
		return mb_substr( $v, 0, 120 );
	}
}
