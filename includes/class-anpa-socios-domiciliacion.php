<?php
/**
 * Shared write/mask helpers for the SEPA banking row (fase6).
 *
 * Seals IBAN + titular NIF to the banking public key and upserts one row per
 * family. Used by both the public /alta endpoint and the user banking PUT so
 * the sealing + upsert logic lives in exactly one place.
 *
 * @since  1.8.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domiciliacion persistence (seal + upsert) and masked view helper.
 *
 * @since 1.8.0
 */
final class ANPA_Socios_Domiciliacion {

	/**
	 * Seals and upserts the banking row for a family.
	 *
	 * Does NOT manage transactions — the caller controls the transaction (the
	 * /alta handler wraps this inside its multi-row transaction; the user PUT
	 * calls it standalone). Returns true on success or a WP_Error to bubble up.
	 *
	 * @since  1.8.0
	 * @param  int                  $familia_id Family id (unique key).
	 * @param  array<string,mixed>  $sepa       Canonical sepa block (validated).
	 * @return true|WP_Error
	 */
	public static function save_sealed( int $familia_id, array $sepa ) {
		global $wpdb;

		$public = ANPA_Socios_Banking_Key::public_key();
		if ( null === $public ) {
			return new WP_Error(
				'anpa_socios_banking_unavailable',
				'A recollida de datos bancarios non está dispoñible neste momento. Contacta coa ANPA.',
				array( 'status' => 400 )
			);
		}

		$iban_sealed = ANPA_Socios_Crypto::seal( (string) $sepa['iban'], $public );
		$nif_sealed  = ANPA_Socios_Crypto::seal( (string) $sepa['titular_nif'], $public );
		if ( null === $iban_sealed || null === $nif_sealed ) {
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		$dom = ANPA_Socios_DB::tabela_domiciliacions();
		// Sealed boxes are self-contained, so the legacy *_nonce columns are unused.
		// titular_nif_mask is the masked NIF (e.g. "****5678Z") so the user
		// can see/confirm the NIF in the area without decrypting.
		$nif_canonical = ANPA_Socios_Normalize::nif( (string) ( $sepa['titular_nif'] ?? '' ) );
		$nif_mask      = $nif_canonical ? self::mask_nif( $nif_canonical ) : '';

		$sql = "INSERT INTO {$dom}
			(familia_id, titular_nome, titular_apelidos, titular_nif_cifrado, titular_nif_nonce, titular_nif_mask, enderezo, poboacion, codigo_postal, entidade_bancaria, iban_cifrado, iban_nonce, iban_last4, autorizacion, lugar_data, creado_en, actualizado_en)
			VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, NOW(), NOW())
			ON DUPLICATE KEY UPDATE titular_nome = VALUES(titular_nome), titular_apelidos = VALUES(titular_apelidos),
			titular_nif_cifrado = VALUES(titular_nif_cifrado), titular_nif_nonce = VALUES(titular_nif_nonce),
			titular_nif_mask = VALUES(titular_nif_mask),
			enderezo = VALUES(enderezo), poboacion = VALUES(poboacion), codigo_postal = VALUES(codigo_postal), entidade_bancaria = VALUES(entidade_bancaria),
			iban_cifrado = VALUES(iban_cifrado), iban_nonce = VALUES(iban_nonce), iban_last4 = VALUES(iban_last4),
			autorizacion = VALUES(autorizacion), lugar_data = VALUES(lugar_data), actualizado_en = NOW()";

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- upsert is the contract; wpdb::insert has no ON DUPLICATE KEY UPDATE.
		$wpdb->query(
			$wpdb->prepare(
				$sql,
				$familia_id,
				$sepa['titular_nome'],
				$sepa['titular_apelidos'],
				$nif_sealed,
				'',
				$nif_mask,
				$sepa['enderezo'],
				$sepa['poboacion'],
				$sepa['codigo_postal'],
				$sepa['entidade_bancaria'],
				$iban_sealed,
				'',
				ANPA_Socios_Crypto::iban_last4( (string) $sepa['iban'] ),
				(int) $sepa['autorizacion'],
				$sepa['lugar_data']
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Builds a masked IBAN display from the stored last4 (never the full IBAN).
	 *
	 * @since  1.8.0
	 * @param  string $last4 Stored iban_last4.
	 * @return string e.g. "**** **** **** 1234" (or "" when no banking).
	 */
	public static function mask_from_last4( string $last4 ): string {
		$last4 = trim( $last4 );
		if ( '' === $last4 ) {
			return '';
		}

		return '**** **** **** ' . $last4;
	}

	/**
	 * Builds a masked NIF display (e.g. "****5678Z") from a canonical NIF.
	 *
	 * The full NIF is never returned to the area or to non-admin UIs:
	 * the user can see the last 4 digits + control letter to confirm
	 * which NIF was registered, without decrypting the sealed box.
	 *
	 * @since  1.20.0
	 * @param  string $nif Canonical NIF/NIE.
	 * @return string Masked view, or empty string if input is empty.
	 */
	public static function mask_nif( string $nif ): string {
		$nif = strtoupper( trim( $nif ) );
		if ( '' === $nif ) {
			return '';
		}
		$tail = substr( $nif, -5 ); // 4 díxitos + letra
		return '****' . $tail;
	}
}
