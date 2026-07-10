<?php
/**
 * Pure-logic validation for the transactional /alta payload.
 *
 * No WordPress dependency, no I/O, no global state. Composes the
 * existing pure validators (Payload, Sepa, Admin_Payload) and returns a
 * canonical structure or null. The REST handler turns null into a
 * single generic 400 and performs the DB transaction.
 *
 * @since  1.7.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Validates the full membership signup payload (parents + fillos + RGPD).
 *
 * Banking (SEPA) data is intentionally NOT handled here; it is collected
 * and stored by a later unit (PR-D) into a dedicated, encrypted table.
 *
 * @since 1.7.0
 */
final class ANPA_Socios_Alta_Payload {

	/**
	 * Maximum number of fillos accepted in a single alta.
	 *
	 * Bounds the transactional insert loop; a family never has more.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const MAX_FILLOS = 10;

	/**
	 * Per-request field error messages.
	 *
	 * Populated during validar() when validation fails; read by the REST
	 * handler to return field-level errors to the frontend.
	 *
	 * @since  1.8.0
	 * @var array<string,string>
	 */
	public static $errors = array();

	/**
	 * Validates the full alta payload.
	 *
	 * Rules:
	 * - `rgpd` must be truthy (consent is mandatory).
	 * - `parent1` requires nome + apelidos + nif + telefono.
	 * - `parent2` is optional. If present (non-empty), it requires a valid
	 *   email, nome + apelidos + nif; telefono optional (validated if present).
	 * - `fillos` is an optional list; each item must pass validar_fillo
	 *   (curso 1-6, grupo A-H, nome/apelidos/data) and carries image_consent.
	 *
	 * @since  1.7.0
	 * @param  array<string,mixed> $body Raw decoded JSON body.
	 * @return array<string,mixed>|null Canonical structure or null on any error.
	 */
	public static function validar( array $body ): ?array {
		self::$errors = array();

		// RGPD consent is mandatory.
		if ( ! filter_var( $body['rgpd'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			self::$errors['rgpd'] = 'Debes aceptar a política de protección de datos.';
			return null;
		}

		$parent1 = self::validar_proxenitor( $body['parent1'] ?? null, true, 'p1_' );
		if ( null === $parent1 ) {
			return null; // errors already populated by validar_proxenitor
		}

		$parent2 = null;
		if ( isset( $body['parent2'] ) && is_array( $body['parent2'] ) && self::ten_datos( $body['parent2'] ) ) {
			$parent2 = self::validar_proxenitor( $body['parent2'], false, 'p2_' );
			if ( null === $parent2 ) {
				return null;
			}

			// Normalize email before validation (Fase 18).
			$raw_email2 = (string) ( $body['parent2']['email'] ?? '' );
			$normalized_email2 = ANPA_Socios_Normalize::email( $raw_email2 );
			$email2 = null !== $normalized_email2 ? $normalized_email2 : self::validar_email( $raw_email2 );
			if ( null === $email2 ) {
				self::$errors['p2_email'] = 'O correo do 2º proxenitor non é válido.';
				return null;
			}
			$parent2['email'] = $email2;
		}

		$fillos     = array();
		$raw_fillos = $body['fillos'] ?? array();
		if ( ! is_array( $raw_fillos ) ) {
			return null;
		}
		// Upper bound: a family will never have more than a handful of
		// children. Caps the transactional insert loop (DoS guard).
		if ( count( $raw_fillos ) > self::MAX_FILLOS ) {
			return null;
		}
		foreach ( $raw_fillos as $raw_fillo ) {
			if ( ! is_array( $raw_fillo ) ) {
				return null;
			}
			$fillo = ANPA_Socios_Admin_Payload::validar_fillo( $raw_fillo );
			if ( null === $fillo ) {
				return null;
			}
			$fillo['image_consent'] = filter_var( $raw_fillo['image_consent'] ?? false, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
			$fillos[]               = $fillo;
		}

		$sepa = self::validar_sepa_opcional( $body['sepa'] ?? null );
		if ( 'invalid' === $sepa ) {
			return null;
		}

		return array(
			'rgpd'    => true,
			'parent1' => $parent1,
			'parent2' => $parent2,
			'fillos'  => $fillos,
			'sepa'    => $sepa,
		);
	}

	/**
	 * Validates the optional SEPA banking block.
	 *
	 * Returns null when no banking block is supplied (banking is optional),
	 * the canonical structure when valid, or the string 'invalid' when a
	 * block is supplied but fails validation (so the caller rejects the
	 * whole alta with a 400 instead of silently dropping banking data).
	 *
	 * IBAN and titular NIF are returned in plaintext here; the REST layer
	 * encrypts them before storage (encryption needs the WP-resolved key).
	 *
	 * @since  1.7.0
	 * @param  mixed $raw Raw sepa block.
	 * @return array<string,mixed>|string|null
	 */
	public static function validar_sepa_opcional( $raw ) {
		if ( ! is_array( $raw ) || ! self::sepa_ten_datos( $raw ) ) {
			return null;
		}

		if ( ! filter_var( $raw['autorizacion'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			self::$errors['sepa_autorizacion'] = 'Debes autorizar a domiciliación bancaria.';
			return 'invalid';
		}

		$iban = ANPA_Socios_Sepa::validar_iban( ANPA_Socios_Normalize::iban( (string) ( $raw['iban'] ?? '' ) ) );
		$nif  = ANPA_Socios_Sepa::validar_nif_nie( (string) ( $raw['titular_nif'] ?? '' ) );
		$raw_tit_nome = (string) ( $raw['titular_nome'] ?? '' );
		$raw_tit_apel = (string) ( $raw['titular_apelidos'] ?? '' );
		$nome = ANPA_Socios_Payload::validar_nome( '' !== trim( $raw_tit_nome ) ? ANPA_Socios_Normalize::title_case( $raw_tit_nome ) : $raw_tit_nome );
		$apel = ANPA_Socios_Payload::validar_apelidos( '' !== trim( $raw_tit_apel ) ? ANPA_Socios_Normalize::title_case( $raw_tit_apel ) : $raw_tit_apel );
		if ( null === $iban || null === $nif || null === $nome || null === $apel ) {
			if ( null === $iban ) { self::$errors['sepa_iban'] = 'O IBAN non é válido.'; }
			if ( null === $nif ) { self::$errors['sepa_titular_nif'] = 'O NIF/NIE do titular non é válido.'; }
			if ( null === $nome ) { self::$errors['sepa_titular_nome'] = 'O nome do titular só pode conter letras.'; }
			if ( null === $apel ) { self::$errors['sepa_titular_apelidos'] = 'Os apelidos do titular só poden conter letras.'; }
			return 'invalid';
		}

		$cp = trim( (string) ( $raw['codigo_postal'] ?? '' ) );
		if ( 1 !== preg_match( '/^\d{5}$/', $cp ) ) {
			self::$errors['sepa_cp'] = 'O código postal debe ter 5 díxitos.';
			return 'invalid';
		}

		$enderezo  = self::sepa_str( $raw['enderezo'] ?? '', 190 );
		$poboacion = self::sepa_str( $raw['poboacion'] ?? '', 100 );
		$entidade  = self::sepa_str( $raw['entidade_bancaria'] ?? '', 120 );
		if ( '' === $enderezo || '' === $poboacion || '' === $entidade ) {
			if ( '' === $enderezo ) { self::$errors['sepa_enderezo'] = 'O enderezo é obrigatorio.'; }
			if ( '' === $poboacion ) { self::$errors['sepa_poboacion'] = 'A poboación é obrigatoria.'; }
			if ( '' === $entidade ) { self::$errors['sepa_entidade'] = 'A entidade bancaria é obrigatoria.'; }
			return 'invalid';
		}

		return array(
			'iban'              => $iban, // plaintext; REST encrypts.
			'titular_nif'       => $nif,  // plaintext; REST encrypts.
			'titular_nome'      => $nome,
			'titular_apelidos'  => $apel,
			'enderezo'          => $enderezo,
			'poboacion'         => $poboacion,
			'codigo_postal'     => $cp,
			'entidade_bancaria' => $entidade,
			'lugar_data'        => self::sepa_str( $raw['lugar_data'] ?? '', 120 ),
			'autorizacion'      => 1,
		);
	}

	/**
	 * Returns whether a sepa block carries any meaningful data.
	 *
	 * @since  1.7.0
	 * @param  array<string,mixed> $raw Sepa block.
	 * @return bool
	 */
	private static function sepa_ten_datos( array $raw ): bool {
		foreach ( array( 'iban', 'titular_nif', 'titular_nome', 'titular_apelidos', 'entidade_bancaria' ) as $key ) {
			if ( '' !== trim( (string) ( $raw[ $key ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Trims, strips control chars, and caps a free-text sepa field.
	 *
	 * @since  1.7.0
	 * @param  mixed $value   Raw value.
	 * @param  int   $max_len Maximum length.
	 * @return string
	 */
	private static function sepa_str( $value, int $max_len ): string {
		$v = trim( (string) $value );
		$v = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $v );

		return substr( $v, 0, $max_len );
	}

	/**
	 * Validates a single parent block.
	 *
	 * @since  1.7.0
	 * @param  mixed  $raw             Raw parent block.
	 * @param  bool   $require_contact When true, a valid telefono is mandatory.
	 * @param  string $prefix          Error key prefix ('p1_' or 'p2_').
	 * @return array<string,string|null>|null
	 */
	private static function validar_proxenitor( $raw, bool $require_contact, string $prefix ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		// Normalize inputs before validation (Fase 18 — RF-7 consistency).
		$raw_nome     = (string) ( $raw['nome'] ?? '' );
		$raw_apelidos = (string) ( $raw['apelidos'] ?? '' );
		$raw_nome     = '' !== trim( $raw_nome ) ? ANPA_Socios_Normalize::title_case( $raw_nome ) : $raw_nome;
		$raw_apelidos = '' !== trim( $raw_apelidos ) ? ANPA_Socios_Normalize::title_case( $raw_apelidos ) : $raw_apelidos;

		$nome     = ANPA_Socios_Payload::validar_nome( $raw_nome );
		$apelidos = ANPA_Socios_Payload::validar_apelidos( $raw_apelidos );
		if ( null === $nome ) {
			self::$errors[ $prefix . 'nome' ] = 'O nome só pode conter letras e espazos.';
		}
		if ( null === $apelidos ) {
			self::$errors[ $prefix . 'apelidos' ] = 'Os apelidos só poden conter letras e espazos.';
		}
		if ( null === $nome || null === $apelidos ) {
			return null;
		}

		$telefono   = null;
		$tel_raw    = trim( (string) ( $raw['telefono'] ?? '' ) );
		if ( '' !== $tel_raw ) {
			// Normalize before validation (Fase 18).
			$tel_normalized = ANPA_Socios_Normalize::telefono( $tel_raw );
			if ( null !== $tel_normalized ) {
				$telefono = $tel_normalized;
			} else {
				// Normalize returned null (invalid) — let existing validator produce error.
				$telefono = ANPA_Socios_Payload::validar_telefono( $tel_raw );
				if ( null === $telefono ) {
					self::$errors[ $prefix . 'telefono' ] = 'O teléfono debe ter 9 díxitos.';
					return null;
				}
			}
		} elseif ( $require_contact ) {
			self::$errors[ $prefix . 'telefono' ] = 'O teléfono é obrigatorio.';
			return null;
		}

		// NIF is always mandatory. Fase 8b: DNI obligatorio para ambos proxenitores.
		$nif_raw = trim( (string) ( $raw['nif'] ?? '' ) );
		// Normalize before validation (Fase 18).
		$nif     = ANPA_Socios_Normalize::nif( $nif_raw );
		if ( null === $nif ) {
			self::$errors[ $prefix . 'nif' ] = '' === $nif_raw ? 'O NIF/NIE é obrigatorio.' : 'O NIF/NIE non superou a validación.';
			return null;
		}

		return array(
			'nome'     => $nome,
			'apelidos' => $apelidos,
			'telefono' => $telefono,
			'nif'      => $nif,
		);
	}

	/**
	 * Returns whether a parent block carries any meaningful data.
	 *
	 * @since  1.7.0
	 * @param  array<string,mixed> $block Parent block.
	 * @return bool
	 */
	private static function ten_datos( array $block ): bool {
		foreach ( array( 'nome', 'apelidos', 'nif', 'telefono', 'email' ) as $key ) {
			if ( '' !== trim( (string) ( $block[ $key ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pure email validation (no WordPress dependency).
	 *
	 * @since  1.7.0
	 * @param  string $email Raw email.
	 * @return string|null Lower-cased email if valid, null otherwise.
	 */
	private static function validar_email( string $email ): ?string {
		$value = strtolower( trim( $email ) );
		if ( '' === $value || strlen( $value ) > 190 ) {
			return null;
		}

		return ( false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ) ? $value : null;
	}
}
