<?php
/**
 * Pure recipient normalization + deduplication for the queue (fase35).
 *
 * Turns a raw list of recipients (each with an email, a type and optional entity
 * refs) into a deduplicated list of valid recipients plus a list of skipped ones
 * (invalid or duplicate). Deduplication is by NORMALIZED email (lowercase+trim),
 * so a parent listed as both principal and secundario with the same account is
 * sent to only once.
 *
 * WordPress-independent (uses a simple RFC-ish check); the glue layer may pass
 * is_email() results in, but the pure logic here is self-contained and testable.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Destinatarios {

	/**
	 * Normalizes an email address (trim + lowercase). Does not validate.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	public static function normalizar( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Minimal email validity check (single @, non-empty local and domain with a
	 * dot). Intentionally conservative; the glue layer can additionally gate with
	 * WordPress is_email().
	 *
	 * @param string $email Normalized or raw email.
	 * @return bool
	 */
	public static function valido( string $email ): bool {
		$email = self::normalizar( $email );
		if ( '' === $email || strlen( $email ) > 190 ) {
			return false;
		}
		return 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email );
	}

	/**
	 * Deduplicates and validates a raw recipient list.
	 *
	 * Each input entry is an array with at least 'email'; optional keys
	 * 'tipo' (principal|secundario|empresa|outro), 'entidade_tipo', 'entidade_id'.
	 * The first occurrence of a normalized email wins (its type/entity kept).
	 *
	 * @param array<int,array<string,mixed>> $raw Raw recipients.
	 * @return array{validos:array<int,array<string,mixed>>,omitidos:array<int,array<string,string>>}
	 *   validos: deduplicated valid recipients with a normalized 'email';
	 *   omitidos: skipped entries with 'email' + 'motivo' (invalido|duplicado).
	 */
	public static function preparar( array $raw ): array {
		$validos  = array();
		$omitidos = array();
		$vistos   = array();

		foreach ( $raw as $entry ) {
			$email_raw = is_array( $entry ) ? (string) ( $entry['email'] ?? '' ) : '';
			$email     = self::normalizar( $email_raw );

			if ( ! self::valido( $email ) ) {
				$omitidos[] = array( 'email' => $email_raw, 'motivo' => 'invalido' );
				continue;
			}
			if ( isset( $vistos[ $email ] ) ) {
				$omitidos[] = array( 'email' => $email, 'motivo' => 'duplicado' );
				continue;
			}
			$vistos[ $email ] = true;

			$validos[] = array(
				'email'         => $email,
				'tipo'          => isset( $entry['tipo'] ) ? (string) $entry['tipo'] : 'outro',
				'entidade_tipo' => isset( $entry['entidade_tipo'] ) ? (string) $entry['entidade_tipo'] : 'xeral',
				'entidade_id'   => isset( $entry['entidade_id'] ) ? (int) $entry['entidade_id'] : 0,
			);
		}

		return array( 'validos' => $validos, 'omitidos' => $omitidos );
	}
}
