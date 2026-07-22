<?php
/**
 * Backup / restore / wipe for the ANPA plugin data (fase12 PR-12o).
 *
 * Backup container (.anpabak): a JSON dump of the domain tables, with banking
 * data DECRYPTED to plaintext (requires the 5-word banking passphrase), then the
 * whole payload is symmetrically encrypted under the SAME banking passphrase
 * (Argon2id + secretbox via ANPA_Socios_Crypto::wrap_secret).
 *
 * The backup EXCLUDES: the banking encryption keys — those are (re)created at
 * init/restore.
 *
 * Restore: decrypts with the banking passphrase, wipes the domain tables,
 * inserts the backup rows preserving ids (for FK integrity), and RE-SEALS
 * banking data with the CURRENT public key.
 *
 * @since  1.22.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Backup {

	const MAGIC   = 'ANPABAK1';
	// v7 (fase31): adds the wp_anpa_niveis_curso per-course comedor pivot.
	// v8 (fase31): niveis loses its legacy comedor columns (moved to the pivot).
	const VERSION = 8;

	/**
	 * Domain tables included in a backup, in FK-safe insert order.
	 * `domiciliacions` is handled specially (banking decrypt/re-encrypt).
	 *
	 * @return array<string,string> map key => full table name
	 */
	private static function tables(): array {
		return array(
			'socios'             => ANPA_Socios_DB::tabela_socios(),
			'fillos'             => ANPA_Socios_DB::tabela_fillos(),
			'fillos_cursos'      => ANPA_Socios_DB::tabela_fillos_cursos(),
			'empresas'           => ANPA_Socios_DB::tabela_empresas(),
			'actividades'        => ANPA_Socios_DB::tabela_actividades(),

			'cursos'             => ANPA_Socios_DB::tabela_cursos(),
			'horarios_comedor'   => ANPA_Socios_DB::tabela_horarios_comedor(),
			'niveis'             => ANPA_Socios_DB::tabela_niveis(),
			'aulas'              => ANPA_Socios_DB::tabela_aulas(),
			'niveis_curso'       => ANPA_Socios_DB::tabela_niveis_curso(),
			'grupos'             => ANPA_Socios_DB::tabela_grupos(),
			'grupos_niveis'      => ANPA_Socios_DB::tabela_grupos_niveis(),
			'matriculas'         => ANPA_Socios_DB::tabela_matriculas(),
			'domiciliacions'     => ANPA_Socios_DB::tabela_domiciliacions(),
		);
	}

	/**
	 * Non-secret settings transported with the domain backup.
	 *
	 * @return array<string,string>
	 */
	private static function backup_options(): array {
		return array(
			'menu_name' => (string) get_option( ANPA_Socios_Config::OPTION_MENU_NAME, '' ),
		);
	}

	/**
	 * Normalizes optional settings across backup versions.
	 *
	 * Backups before v5 did not carry the menu label, so restore the neutral
	 * default by deleting the override instead of inventing a custom value.
	 *
	 * @param  array<string,mixed> $options        Options from the payload.
	 * @param  int                 $backup_version Backup payload version.
	 * @return array{menu_name:string}
	 */
	private static function normalize_restore_options( array $options, int $backup_version ): array {
		if ( $backup_version < 5 ) {
			return array( 'menu_name' => '' );
		}

		$value = $options['menu_name'] ?? '';
		$value = is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, ANPA_Socios_Config::MENU_NAME_MAX_LENGTH );
		} else {
			$value = substr( $value, 0, ANPA_Socios_Config::MENU_NAME_MAX_LENGTH );
		}

		return array( 'menu_name' => trim( $value ) );
	}

	/**
	 * Writes restored settings in the caller-owned transaction.
	 *
	 * @param  array{menu_name:string} $options Normalized options.
	 * @return bool
	 */
	private static function restore_options( array $options ): bool {
		global $wpdb;

		$key   = ANPA_Socios_Config::OPTION_MENU_NAME;
		$value = (string) ( $options['menu_name'] ?? '' );
		if ( '' === $value ) {
			$written = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		} else {
			$written = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes')
				 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				$key,
				maybe_serialize( $value )
			) );
		}
		if ( false === $written ) {
			return false;
		}
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return true;
	}

	/**
	 * Removes columns that existed in backup v2 before schema 1.31.0 retired them.
	 *
	 * @param  string              $key            Backup table key.
	 * @param  array<string,mixed> $row            Row from the decrypted backup.
	 * @param  int                 $backup_version Backup payload version.
	 * @return array<string,mixed>
	 */
	private static function normalize_restore_row( string $key, array $row, int $backup_version ): array {
		if ( $backup_version > self::VERSION ) {
			return $row;
		}

		$retired = array(
			'actividades'        => array( 'min_pupilos', 'max_pupilos', 'curso_min', 'curso_max' ),
			'actividades_cursos' => array( 'horario' ),
			'grupos'             => array( 'grupo_curricular_id' ),
			// v8 (fase31): niveis lost its per-level comedor columns; the
			// per-course comedor now lives in the niveis_curso pivot. Old backups
			// carried these on niveis rows — strip them so the insert matches the
			// current schema. (The comedor assignment from a pre-v8 backup is not
			// re-derived; re-assign it in Estrutura escolar if needed.)
			'niveis'             => array( 'horario_comedor_id', 'comedor_inicio', 'comedor_fin' ),
		);

		foreach ( $retired[ $key ] ?? array() as $column ) {
			unset( $row[ $column ] );
		}

		return $row;
	}

	/**
	 * Rejects malformed values for every known backup table before deletion.
	 * Missing keys remain valid for backwards compatibility with older formats.
	 *
	 * @param  array<string,mixed> $dump Decrypted backup table map.
	 * @return string|null Conflict category, or null when all present tables are arrays.
	 */
	private static function validate_restore_dump_shape( array $dump ): ?string {
		$known = array_merge( array_keys( self::tables() ), array( 'actividades_cursos' ) );
		foreach ( $known as $key ) {
			if ( array_key_exists( $key, $dump ) && ! is_array( $dump[ $key ] ) ) {
				return 'invalid_table_shape';
			}
		}

		return null;
	}

	/**
	 * Validates the retired annual-offer projection carried by pre-v6 backups.
	 *
	 * The projection itself is never restored. Empty rows are safely discarded;
	 * material rows are accepted only when the same activity/year is represented
	 * by a restored annual group with at least one level. Cost/state divergence
	 * and orphan activities fail closed before the destructive restore starts.
	 *
	 * @param  array<string,mixed> $dump Decrypted backup table map.
	 * @return string|null Conflict category, or null when adaptation is lossless.
	 */
	private static function validate_legacy_activity_course_dump( array $dump ): ?string {
		if ( ! array_key_exists( 'actividades_cursos', $dump ) ) {
			return null;
		}
		if ( ! is_array( $dump['actividades_cursos'] ) ) {
			return 'invalid_legacy_offer_shape';
		}

		$activities = array();
		foreach ( is_array( $dump['actividades'] ?? null ) ? $dump['actividades'] : array() as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$activities[ (int) $row['id'] ] = $row;
			}
		}

		$levelled_groups = array();
		$group_scopes    = array();
		foreach ( is_array( $dump['grupos'] ?? null ) ? $dump['grupos'] : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$group_scopes[ (int) $row['id'] ] = (int) ( $row['actividad_id'] ?? 0 ) . '|' . trim( (string) ( $row['curso_escolar'] ?? '' ) );
		}
		foreach ( is_array( $dump['grupos_niveis'] ?? null ) ? $dump['grupos_niveis'] : array() as $row ) {
			if ( is_array( $row ) && ! empty( $row['grupo_id'] ) && ! empty( $row['nivel_id'] ) ) {
				$group_id = (int) $row['grupo_id'];
				if ( isset( $group_scopes[ $group_id ] ) ) {
					$levelled_groups[ $group_scopes[ $group_id ] ] = true;
				}
			}
		}

		foreach ( $dump['actividades_cursos'] as $offer ) {
			if ( ! is_array( $offer ) ) {
				return 'invalid_legacy_offer_shape';
			}
			$activity_id = (int) ( $offer['actividad_id'] ?? 0 );
			if ( $activity_id <= 0 || ! isset( $activities[ $activity_id ] ) ) {
				return 'orphan_activity';
			}
			$activity = $activities[ $activity_id ];
			if (
				round( (float) ( $offer['custo'] ?? 0 ), 2 ) !== round( (float) ( $activity['custo'] ?? 0 ), 2 )
				|| (string) ( $offer['estado'] ?? '' ) !== (string) ( $activity['estado'] ?? '' )
			) {
				return 'divergent_cost_or_state';
			}

			$material = false;
			foreach ( array( 'franxa', 'horario', 'horarios', 'grupos', 'dias' ) as $field ) {
				$val = $offer[ $field ] ?? '';
				if ( ! is_scalar( $val ) ) {
					return 'invalid_legacy_offer_shape';
				}
				$material = $material || '' !== trim( (string) $val );
			}
			$material = $material
				|| 0.0 !== (float) ( $offer['min_pupilos'] ?? 0 )
				|| 0.0 !== (float) ( $offer['max_pupilos'] ?? 0 );
			foreach ( array( 'nivel_min_id', 'nivel_max_id' ) as $field ) {
				$material = $material || ( array_key_exists( $field, $offer ) && null !== $offer[ $field ] );
			}

			$curso_val = $offer['curso_escolar'] ?? '';
			if ( ! is_scalar( $curso_val ) ) {
				return 'invalid_legacy_offer_shape';
			}
			$scope = $activity_id . '|' . trim( (string) $curso_val );
			if ( $material && empty( $levelled_groups[ $scope ] ) ) {
				return 'material_offer_without_group';
			}
		}

		return null;
	}

	/**
	 * Builds an encrypted backup blob.
	 *
	 * The 5-word banking passphrase serves as the SINGLE secret: it unlocks
	 * the sealed banking data AND encrypts the resulting backup container.
	 *
	 * @param  string $banking_passphrase 5-word passphrase (decrypts banking data + encrypts container).
	 * @return string|WP_Error Encrypted container bytes, or error.
	 */
	public static function build( string $banking_passphrase ) {
		global $wpdb;

		if ( '' === $banking_passphrase ) {
			return new WP_Error( 'anpa_bak_no_pw', 'Falta a frase da clave bancaria.', array( 'status' => 400 ) );
		}

		$public  = ANPA_Socios_Banking_Key::public_key();
		$wrapped = ANPA_Socios_Banking_Key::wrapped_secret();
		$secret  = null;
		if ( null !== $public && null !== $wrapped ) {
			$secret = ANPA_Socios_Crypto::unwrap_secret( $wrapped['blob'], $wrapped['salt'], $wrapped['nonce'], $banking_passphrase );
			if ( null === $secret ) {
				return new WP_Error( 'anpa_bak_bad_passphrase', 'A frase da clave bancaria é incorrecta.', array( 'status' => 403 ) );
			}
		}

		$master = ANPA_Socios_Config::master_email();
		$dump   = array();

		foreach ( self::tables() as $key => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- admin-only backup; table from DB helper.
			$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
			if ( ! is_array( $rows ) ) {
				if ( null !== $secret ) {
					sodium_memzero( $secret );
				}
				return new WP_Error( 'anpa_bak_read_failed', 'Non se puido ler toda a información da copia.', array( 'status' => 500 ) );
			}

			if ( 'socios' === $key ) {
				// Exclude the master account.
				$rows = array_values( array_filter( $rows, static function ( $r ) use ( $master ) {
					return strtolower( (string) ( $r['email'] ?? '' ) ) !== $master;
				} ) );
			}

			if ( 'domiciliacions' === $key ) {
				foreach ( $rows as &$r ) {
					$iban = '';
					if ( ! empty( $r['iban_cifrado'] ) ) {
						$iban = null !== $secret
							? ANPA_Socios_Crypto::unseal( (string) $r['iban_cifrado'], (string) $public, $secret )
							: null;
						if ( null === $iban ) {
							if ( null !== $secret ) {
								sodium_memzero( $secret );
							}
							return new WP_Error( 'anpa_bak_decrypt_failed', 'Non se puideron descifrar todos os datos bancarios.', array( 'status' => 500 ) );
						}
					}
					$nif = '';
					if ( ! empty( $r['titular_nif_cifrado'] ) ) {
						$nif = null !== $secret
							? ANPA_Socios_Crypto::unseal( (string) $r['titular_nif_cifrado'], (string) $public, $secret )
							: null;
						if ( null === $nif ) {
							if ( null !== $secret ) {
								sodium_memzero( $secret );
							}
							return new WP_Error( 'anpa_bak_decrypt_failed', 'Non se puideron descifrar todos os datos bancarios.', array( 'status' => 500 ) );
						}
					}
					$r['iban_plain'] = $iban;
					$r['nif_plain']  = $nif;
					// Drop the old-key ciphertext; it will be re-sealed on restore.
					unset( $r['iban_cifrado'], $r['iban_nonce'], $r['titular_nif_cifrado'], $r['titular_nif_nonce'] );
				}
				unset( $r );
			}

			$dump[ $key ] = $rows;
		}

		if ( null !== $secret ) {
			sodium_memzero( $secret );
		}

		$payload = wp_json_encode( array(
			'magic'   => self::MAGIC,
			'version' => self::VERSION,
			'created' => gmdate( 'c' ),
			'tables'  => $dump,
			'options' => self::backup_options(),
		) );
		if ( false === $payload ) {
			return new WP_Error( 'anpa_bak_encode', 'Non se puido serializar a copia.', array( 'status' => 500 ) );
		}

		$container = ANPA_Socios_Crypto::wrap_secret( $payload, $banking_passphrase );
		if ( null === $container ) {
			return new WP_Error( 'anpa_bak_encrypt', 'Non se puido cifrar a copia.', array( 'status' => 500 ) );
		}

		$out = wp_json_encode( array(
			'magic'     => self::MAGIC,
			'kdf'       => 'argon2id',
			'container' => $container,
		) );
		if ( false === $out ) {
			return new WP_Error( 'anpa_bak_container_encode', 'Non se puido serializar o contedor cifrado.', array( 'status' => 500 ) );
		}

		return $out;
	}

	/**
	 * Restores a backup blob into a freshly-initialised install.
	 *
	 * @param  string $blob               Encrypted container bytes.
	 * @param  string $banking_passphrase  5-word passphrase used to encrypt the container.
	 * @return true|WP_Error
	 */
	public static function restore( string $blob, string $banking_passphrase ) {
		global $wpdb;

		$outer = json_decode( $blob, true );
		if ( ! is_array( $outer ) || ( $outer['magic'] ?? '' ) !== self::MAGIC || empty( $outer['container'] ) ) {
			return new WP_Error( 'anpa_bak_format', 'O ficheiro de copia non é válido.', array( 'status' => 400 ) );
		}
		$c = $outer['container'];
		if ( empty( $c['blob'] ) || empty( $c['salt'] ) || empty( $c['nonce'] ) ) {
			return new WP_Error( 'anpa_bak_format', 'O ficheiro de copia está corrupto.', array( 'status' => 400 ) );
		}

		$json = ANPA_Socios_Crypto::unwrap_secret( (string) $c['blob'], (string) $c['salt'], (string) $c['nonce'], $banking_passphrase );
		if ( null === $json ) {
			return new WP_Error( 'anpa_bak_bad_pw', 'Frase da clave bancaria incorrecta ou copia corrupta.', array( 'status' => 403 ) );
		}
		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) || ( $payload['magic'] ?? '' ) !== self::MAGIC || ! is_array( $payload['tables'] ?? null ) || empty( $payload['tables'] ) ) {
			return new WP_Error( 'anpa_bak_payload', 'Contido da copia non válido.', array( 'status' => 400 ) );
		}
		$backup_version = (int) ( $payload['version'] ?? 1 );
		if ( $backup_version < 1 || $backup_version > self::VERSION ) {
			return new WP_Error( 'anpa_bak_version', 'A versión desta copia non é compatible.', array( 'status' => 400 ) );
		}
		$dump_shape = self::validate_restore_dump_shape( $payload['tables'] );
		if ( null !== $dump_shape ) {
			return new WP_Error(
				'anpa_bak_payload',
				'O contido dunha táboa da copia non é válido.',
				array( 'status' => 400, 'reason' => $dump_shape )
			);
		}
		$legacy_conflict = self::validate_legacy_activity_course_dump( $payload['tables'] );
		if ( null !== $legacy_conflict ) {
			return new WP_Error(
				'anpa_bak_legacy_offer_conflict',
				'A copia antiga contén unha oferta anual que non se pode adaptar sen perder información.',
				array( 'status' => 400, 'reason' => $legacy_conflict )
			);
		}

		$public = ANPA_Socios_Banking_Key::public_key();
		if ( null === $public ) {
			return new WP_Error( 'anpa_bak_no_key', 'Inicializa o plugin (clave bancaria) antes de recuperar.', array( 'status' => 409 ) );
		}

		$tables         = self::tables();
		$dump           = $payload['tables'];
		$restore_options = self::normalize_restore_options(
			isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : array(),
			$backup_version
		);
		$restore_failed = static function () use ( $wpdb ): WP_Error {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
			return new WP_Error( 'anpa_bak_restore_failed', 'Non se puido completar a recuperación. Non se gardou ningún cambio.', array( 'status' => 500 ) );
		};

		// Clean slate on the domain tables (schema kept), then insert preserving ids.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $restore_failed();
		}
		if ( false === $wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' ) ) {
			return $restore_failed();
		}
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- admin-only restore.
			if ( false === $wpdb->query( "DELETE FROM {$table}" ) ) {
				return $restore_failed();
			}
		}

		foreach ( $tables as $key => $table ) {
			$rows = isset( $dump[ $key ] ) && is_array( $dump[ $key ] ) ? $dump[ $key ] : array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row = self::normalize_restore_row( $key, $row, $backup_version );
				if ( 'domiciliacions' === $key ) {
					$iban = (string) ( $row['iban_plain'] ?? '' );
					$nif  = (string) ( $row['nif_plain'] ?? '' );
					unset( $row['iban_plain'], $row['nif_plain'] );
					$iban_sealed = '' !== $iban ? ANPA_Socios_Crypto::seal( $iban, (string) $public ) : null;
					$nif_sealed  = '' !== $nif ? ANPA_Socios_Crypto::seal( $nif, (string) $public ) : null;
					if ( ( '' !== $iban && null === $iban_sealed ) || ( '' !== $nif && null === $nif_sealed ) ) {
						return $restore_failed();
					}
					$row['iban_cifrado']        = $iban_sealed;
					$row['iban_nonce']          = null;
					$row['iban_last4']          = '' !== $iban ? ANPA_Socios_Crypto::iban_last4( $iban ) : '';
					$row['titular_nif_cifrado'] = $nif_sealed;
					$row['titular_nif_nonce']   = null;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin-only restore insert.
				if ( false === $wpdb->insert( $table, $row ) ) {
					return $restore_failed();
				}
			}
		}

		// ── v1 restore compatibility: backfill niveis/aulas for old backups ──
		$ambiguous = array();
		if ( $backup_version < 2 ) {
			// A v1 backup predates the parametrizable structure tables.
			// Create default niveis 1..6 + aulas A..aula_max for each
			// curso_escolar found in the restored cursos table, mirroring
			// the same backfill logic from migrate_to_1_27_0.
			$niveis_t  = ANPA_Socios_DB::tabela_niveis();
			$aulas_t   = ANPA_Socios_DB::tabela_aulas();
			$cursos_t  = ANPA_Socios_DB::tabela_cursos();
			$fillos_cursos_t = ANPA_Socios_DB::tabela_fillos_cursos();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- backup restore backfill.
			$restored_cursos = $wpdb->get_col( "SELECT DISTINCT curso_escolar FROM {$cursos_t} ORDER BY curso_escolar" );
			if ( ! is_array( $restored_cursos ) ) {
				return $restore_failed();
			}
			foreach ( $restored_cursos as $curso_escolar ) {
					// v1 did not include options. Derive the observed classroom range
					// from its own restored assignments; use the historical neutral D
					// only when the backup contains no valid evidence.
					$aula_max = 'D';
					$observed = $wpdb->get_col( $wpdb->prepare(
						"SELECT DISTINCT UPPER(aula) FROM {$fillos_cursos_t} WHERE curso_escolar = %s AND UPPER(aula) REGEXP '^[A-H]$'",
						$curso_escolar
					) );
					if ( ! is_array( $observed ) ) {
						return $restore_failed();
					}
					foreach ( $observed as $observed_aula ) {
						if ( is_string( $observed_aula ) && $observed_aula > $aula_max ) {
							$aula_max = $observed_aula;
						}
					}
					// Check if the curso already has niveis (e.g. from a partial v2 state).
					$existing_result = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$niveis_t} WHERE curso_escolar = %s",
						$curso_escolar
					) );
					if ( null === $existing_result ) {
						return $restore_failed();
					}
					$existing_count = (int) $existing_result;
					if ( $existing_count > 0 ) {
						continue;
					}

					// Levels 1..6.
					for ( $n = 1; $n <= 6; $n++ ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent restore backfill.
						$written = $wpdb->query( $wpdb->prepare(
							"INSERT IGNORE INTO {$niveis_t} (curso_escolar, codigo, etiqueta, orde, estado, creado_en, actualizado_en)
							 VALUES (%s, %s, %s, %d, 'activo', NOW(), NOW())",
							$curso_escolar,
							(string) $n,
							$n . 'º',
							$n * 10
						) );
						if ( false === $written ) {
							return $restore_failed();
						}
					}

					// Classrooms A..aula_max for each level.
					for ( $n = 1; $n <= 6; $n++ ) {
						$nivel_id = $wpdb->get_var( $wpdb->prepare(
							"SELECT id FROM {$niveis_t} WHERE curso_escolar = %s AND codigo = %s",
							$curso_escolar,
							(string) $n
						) );
						if ( null === $nivel_id || ! $nivel_id ) {
							$ambiguous[] = sprintf( 'nivel %d for %s not created', $n, $curso_escolar );
							continue;
						}
						$letters = range( 'A', $aula_max );
						foreach ( $letters as $letter ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent restore backfill.
							$written = $wpdb->query( $wpdb->prepare(
								"INSERT IGNORE INTO {$aulas_t} (nivel_id, codigo, etiqueta, orde, estado, creado_en, actualizado_en)
								 VALUES (%d, %s, %s, %d, 'activo', NOW(), NOW())",
								(int) $nivel_id,
								$letter,
								$letter,
								( ord( $letter ) - 64 ) * 10
							) );
							if ( false === $written ) {
								return $restore_failed();
							}
						}
					}
				}
		}

		if ( $backup_version < 4 && ! ANPA_Socios_DB::backfill_legacy_horarios_comedor() ) {
			return $restore_failed();
		}
		if ( ! self::restore_options( $restore_options ) ) {
			return $restore_failed();
		}

		if ( false === $wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ) ) {
			return $restore_failed();
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return $restore_failed();
		}

		if ( ! empty( $ambiguous ) ) {
			return new WP_Error( 'anpa_bak_ambiguous', implode( '; ', $ambiguous ), array( 'status' => 200, 'partial' => true ) );
		}

		return true;
	}

	/**
	 * Wipes all plugin data + init options so the setup wizard reappears.
	 * IRREVERSIBLE.
	 *
	 * @return true|WP_Error
	 */
	public static function wipe() {
		global $wpdb;

		$suffixes = array(
			'actividades', 'actividades_cursos', 'actividades_cursos_grupos_curriculares', 'area_sessions', 'area_sessions_empresas',
			'audit_log', 'aulas', 'codigos_verificacion', 'cursos', 'domiciliacions', 'empresas', 'horarios_comedor',
			'fillos', 'fillos_cursos', 'grupos', 'grupos_curriculares', 'grupos_curriculares_niveis',
			'grupos_niveis', 'matriculas', 'niveis', 'socios',
		);
		$wipe_failed = static function () use ( $wpdb ): WP_Error {
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
			return new WP_Error( 'anpa_bak_wipe_failed', 'Non se puido completar o borrado. Revisa a base de datos antes de continuar.', array( 'status' => 500 ) );
		};

		if ( false === $wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' ) ) {
			return $wipe_failed();
		}
		foreach ( $suffixes as $s ) {
			$t = $wpdb->prefix . 'anpa_' . $s;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- irreversible admin wipe.
			if ( false === $wpdb->query( "DROP TABLE IF EXISTS `{$t}`" ) ) {
				return $wipe_failed();
			}
		}
		if ( false === $wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ) ) {
			return $wipe_failed();
		}

		foreach ( array(
			'anpa_socios_db_version',
			'anpa_socios_banking_pubkey',
			'anpa_socios_banking_seckey_wrapped',
			'anpa_socios_admin_password_hash',
			'anpa_socios_master_initialized',
			'anpa_socios_master_email',
			'anpa_socios_aula_max',
			ANPA_Socios_Config::OPTION_ASSOCIATION,
			ANPA_Socios_Config::OPTION_SIGNATURE,
			ANPA_Socios_Config::OPTION_APPROVAL,
			ANPA_Socios_Config::OPTION_CONTACT_EMAIL,
			ANPA_Socios_Config::OPTION_ADDRESS,
			ANPA_Socios_Config::OPTION_FEE,
			ANPA_Socios_Config::OPTION_COUNTRY,
			ANPA_Socios_Config::OPTION_PROVINCE,
			ANPA_Socios_Config::OPTION_TOWN,
			ANPA_Socios_Config::OPTION_MENU_NAME,
			ANPA_Socios_Admin_Settings::LANDING_OPTION,
		) as $opt ) {
			delete_option( $opt );
			if ( false !== get_option( $opt, false ) ) {
				return $wipe_failed();
			}
		}

		return true;
	}
}
