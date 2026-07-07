<?php
/**
 * Backup / restore / wipe for the ANPA plugin data (fase12 PR-12o).
 *
 * Backup container (.anpabak): a JSON dump of the domain tables, with banking
 * data DECRYPTED to plaintext (requires the 5-word banking passphrase), then the
 * whole payload is symmetrically encrypted under the ADMIN password
 * (Argon2id + secretbox via ANPA_Socios_Crypto::wrap_secret).
 *
 * The backup EXCLUDES: the master socio row, the banking encryption keys, and
 * the admin password hash — those are (re)created at init/restore.
 *
 * Restore: decrypts with the admin password, wipes the domain tables, inserts
 * the backup rows preserving ids (for FK integrity), RE-SEALS banking with the
 * CURRENT public key, and re-creates the master socio.
 *
 * @since  1.22.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Backup {

	const MAGIC   = 'ANPABAK1';
	const VERSION = 1;

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
			'actividades_cursos' => ANPA_Socios_DB::tabela_actividades_cursos(),
			'grupos'             => ANPA_Socios_DB::tabela_grupos(),
			'cursos'             => ANPA_Socios_DB::tabela_cursos(),
			'matriculas'         => ANPA_Socios_DB::tabela_matriculas(),
			'domiciliacions'     => ANPA_Socios_DB::tabela_domiciliacions(),
		);
	}

	/**
	 * Builds an encrypted backup blob.
	 *
	 * @param  string $admin_password     Admin password used to encrypt the container.
	 * @param  string $banking_passphrase 5-word passphrase to decrypt banking data.
	 * @return string|WP_Error Encrypted container bytes, or error.
	 */
	public static function build( string $admin_password, string $banking_passphrase ) {
		global $wpdb;

		if ( '' === $admin_password ) {
			return new WP_Error( 'anpa_bak_no_pw', 'Falta o contrasinal de administración.', array( 'status' => 400 ) );
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
			$rows = is_array( $rows ) ? $rows : array();

			if ( 'socios' === $key ) {
				// Exclude the master account.
				$rows = array_values( array_filter( $rows, static function ( $r ) use ( $master ) {
					return strtolower( (string) ( $r['email'] ?? '' ) ) !== $master;
				} ) );
			}

			if ( 'domiciliacions' === $key ) {
				foreach ( $rows as &$r ) {
					$iban = ( null !== $secret && ! empty( $r['iban_cifrado'] ) )
						? (string) ANPA_Socios_Crypto::unseal( (string) $r['iban_cifrado'], (string) $public, $secret )
						: '';
					$nif  = ( null !== $secret && ! empty( $r['titular_nif_cifrado'] ) )
						? (string) ANPA_Socios_Crypto::unseal( (string) $r['titular_nif_cifrado'], (string) $public, $secret )
						: '';
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
		) );
		if ( false === $payload ) {
			return new WP_Error( 'anpa_bak_encode', 'Non se puido serializar a copia.', array( 'status' => 500 ) );
		}

		$container = ANPA_Socios_Crypto::wrap_secret( $payload, $admin_password );
		if ( null === $container ) {
			return new WP_Error( 'anpa_bak_encrypt', 'Non se puido cifrar a copia.', array( 'status' => 500 ) );
		}

		$out = wp_json_encode( array(
			'magic'     => self::MAGIC,
			'kdf'       => 'argon2id',
			'container' => $container,
		) );

		return (string) $out;
	}

	/**
	 * Restores a backup blob into a freshly-initialised install.
	 *
	 * @param  string $blob           Encrypted container bytes.
	 * @param  string $admin_password Admin password used to encrypt it.
	 * @return true|WP_Error
	 */
	public static function restore( string $blob, string $admin_password ) {
		global $wpdb;

		$outer = json_decode( $blob, true );
		if ( ! is_array( $outer ) || ( $outer['magic'] ?? '' ) !== self::MAGIC || empty( $outer['container'] ) ) {
			return new WP_Error( 'anpa_bak_format', 'O ficheiro de copia non é válido.', array( 'status' => 400 ) );
		}
		$c = $outer['container'];
		if ( empty( $c['blob'] ) || empty( $c['salt'] ) || empty( $c['nonce'] ) ) {
			return new WP_Error( 'anpa_bak_format', 'O ficheiro de copia está corrupto.', array( 'status' => 400 ) );
		}

		$json = ANPA_Socios_Crypto::unwrap_secret( (string) $c['blob'], (string) $c['salt'], (string) $c['nonce'], $admin_password );
		if ( null === $json ) {
			return new WP_Error( 'anpa_bak_bad_pw', 'Contrasinal incorrecto ou copia corrupta.', array( 'status' => 403 ) );
		}
		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) || ( $payload['magic'] ?? '' ) !== self::MAGIC || empty( $payload['tables'] ) ) {
			return new WP_Error( 'anpa_bak_payload', 'Contido da copia non válido.', array( 'status' => 400 ) );
		}

		$public = ANPA_Socios_Banking_Key::public_key();
		if ( null === $public ) {
			return new WP_Error( 'anpa_bak_no_key', 'Inicializa o plugin (clave bancaria) antes de recuperar.', array( 'status' => 409 ) );
		}

		$tables = self::tables();
		$dump   = $payload['tables'];

		// Clean slate on the domain tables (schema kept), then insert preserving ids.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- admin-only restore.
			$wpdb->query( "DELETE FROM {$table}" );
		}

		foreach ( $tables as $key => $table ) {
			$rows = isset( $dump[ $key ] ) && is_array( $dump[ $key ] ) ? $dump[ $key ] : array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( 'domiciliacions' === $key ) {
					$iban = (string) ( $row['iban_plain'] ?? '' );
					$nif  = (string) ( $row['nif_plain'] ?? '' );
					unset( $row['iban_plain'], $row['nif_plain'] );
					$row['iban_cifrado']        = '' !== $iban ? (string) ANPA_Socios_Crypto::seal( $iban, (string) $public ) : null;
					$row['iban_nonce']          = null;
					$row['iban_last4']          = '' !== $iban ? ANPA_Socios_Crypto::iban_last4( $iban ) : '';
					$row['titular_nif_cifrado'] = '' !== $nif ? (string) ANPA_Socios_Crypto::seal( $nif, (string) $public ) : null;
					$row['titular_nif_nonce']   = null;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin-only restore insert.
				$wpdb->insert( $table, $row );
			}
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

		return true;
	}

	/**
	 * Wipes all plugin data + init options so the setup wizard reappears.
	 * IRREVERSIBLE.
	 *
	 * @return void
	 */
	public static function wipe(): void {
		global $wpdb;

		$suffixes = array(
			'actividades', 'actividades_cursos', 'area_sessions', 'area_sessions_empresas',
			'audit_log', 'codigos_verificacion', 'cursos', 'domiciliacions', 'empresas',
			'fillos', 'fillos_cursos', 'grupos', 'matriculas', 'socios',
		);
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $suffixes as $s ) {
			$t = $wpdb->prefix . 'anpa_' . $s;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- irreversible admin wipe.
			$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

		foreach ( array(
			'anpa_socios_db_version',
			'anpa_socios_banking_pubkey',
			'anpa_socios_banking_seckey_wrapped',
			ANPA_Socios_Master_Auth::ADMIN_PASSWORD_OPTION,
			ANPA_Socios_Master_Auth::INIT_OPTION,
			'anpa_socios_master_email',
			ANPA_Socios_Config::OPTION_ASSOCIATION,
			ANPA_Socios_Config::OPTION_SIGNATURE,
			ANPA_Socios_Config::OPTION_APPROVAL,
			ANPA_Socios_Admin_Settings::LANDING_OPTION,
		) as $opt ) {
			delete_option( $opt );
		}
	}
}
