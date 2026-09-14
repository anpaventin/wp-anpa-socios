<?php
/**
 * Admin REST — «Lista Gmail»: export of the active members as a Google Contacts CSV
 * plus the comparison against the previous export.
 *
 * There is no supported way to push contacts into a Gmail account from a web page
 * without Google Cloud credentials, so this is deliberately a manual, three-click
 * procedure: download the CSV → open Google Contacts → import. The plugin only
 * remembers WHAT it exported last time so it can tell the junta how many members
 * joined or left since then and whether a new import is needed.
 *
 * @since   1.58.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Contacts CSV export + snapshot comparison.
 *
 * @since 1.58.0
 */
final class ANPA_Socios_Admin_Contactos_Google_Handler {

	/**
	 * Name of the label (group) in Google Contacts. Written into the CSV so the
	 * import assigns it; the instructions on screen use the same literal.
	 *
	 * @var string
	 */
	const LABEL = 'Socios Web ANPA';

	/**
	 * Option holding the last export: exported_en, por, total, socios[] (email, nome, apelidos).
	 *
	 * @var string
	 */
	const OPTION_SNAPSHOT = 'anpa_socios_contactos_google_snapshot';

	/**
	 * Header row of Google's own CSV format (what "Export → Google CSV" produces
	 * in Google Contacts, 2024+ layout). Import maps columns by these names, so
	 * the row is emitted verbatim and unused columns stay empty.
	 *
	 * @var string[]
	 */
	const GOOGLE_HEADERS = array(
		'First Name', 'Middle Name', 'Last Name', 'Phonetic First Name', 'Phonetic Middle Name', 'Phonetic Last Name',
		'Name Prefix', 'Name Suffix', 'Nickname', 'File As', 'Organization Name', 'Organization Title',
		'Organization Department', 'Birthday', 'Notes', 'Photo', 'Labels', 'E-mail 1 - Label', 'E-mail 1 - Value',
		'Phone 1 - Label', 'Phone 1 - Value',
	);

	/**
	 * Registers the routes (master only).
	 *
	 * @since  1.58.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/contactos-google/estado', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'estado' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/contactos-google/export', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'export' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * Active members that belong in the list: one row per parent with an email
	 * (each parent is its own socio row), excluding the protected admin account.
	 * A member with a PENDING baixa request is still active and is still listed:
	 * only a confirmed baixa (Baixas solicitadas → Confirmar) removes them.
	 *
	 * @since  1.58.0
	 * @return array<int,array{email:string,nome:string,apelidos:string}>
	 */
	private static function socios_activos(): array {
		global $wpdb;

		$soc_t = ANPA_Socios_DB::tabela_socios();
		$rows  = $wpdb->get_results(
			"SELECT email, nome, apelidos FROM {$soc_t}
			 WHERE estado = 'activo' AND rol <> 'master' AND email <> ''
			 ORDER BY apelidos ASC, nome ASC, email ASC",
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[] = array(
				'email'    => strtolower( trim( (string) $r['email'] ) ),
				'nome'     => trim( (string) $r['nome'] ),
				'apelidos' => trim( (string) $r['apelidos'] ),
			);
		}

		return $out;
	}

	/**
	 * Google Contacts URL, pinned to the junta's account when configured so a
	 * browser with several Google sessions opens the right one.
	 *
	 * @since  1.58.0
	 * @return string
	 */
	public static function google_url(): string {
		$account = ANPA_Socios_Config::google_contacts_email();

		return 'https://contacts.google.com/' . ( '' !== $account ? '?authuser=' . rawurlencode( $account ) : '' );
	}

	/**
	 * GET /admin/contactos-google/estado — last export vs. current members.
	 *
	 * @since  1.58.0
	 * @return WP_REST_Response
	 */
	public static function estado(): WP_REST_Response {
		global $wpdb;

		$actuais  = self::socios_activos();
		$snapshot = get_option( self::OPTION_SNAPSHOT, null );
		$snapshot = is_array( $snapshot ) ? $snapshot : null;
		$previos  = array();
		if ( null !== $snapshot && isset( $snapshot['socios'] ) && is_array( $snapshot['socios'] ) ) {
			foreach ( $snapshot['socios'] as $s ) {
				if ( is_array( $s ) && ! empty( $s['email'] ) ) {
					$previos[ strtolower( (string) $s['email'] ) ] = array(
						'email'    => strtolower( (string) $s['email'] ),
						'nome'     => (string) ( $s['nome'] ?? '' ),
						'apelidos' => (string) ( $s['apelidos'] ?? '' ),
					);
				}
			}
		}
		$actuais_map = array();
		foreach ( $actuais as $s ) {
			$actuais_map[ $s['email'] ] = $s;
		}
		$altas  = array_values( array_diff_key( $actuais_map, $previos ) );
		$baixas = array_values( array_diff_key( $previos, $actuais_map ) );

		$soc_t     = ANPA_Socios_DB::tabela_socios();
		$pendentes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$soc_t} WHERE estado = 'activo' AND baixa_estado = 'solicitada' AND rol <> 'master'" );

		return new WP_REST_Response(
			array(
				'etiqueta'          => self::LABEL,
				'conta_google'      => ANPA_Socios_Config::google_contacts_email(),
				'google_url'        => self::google_url(),
				'total_actuais'     => count( $actuais ),
				'ultima_exportacion' => null === $snapshot ? null : array(
					'exportado_en' => (string) ( $snapshot['exportado_en'] ?? '' ),
					'por'          => (string) ( $snapshot['por'] ?? '' ),
					'total'        => (int) ( $snapshot['total'] ?? count( $previos ) ),
				),
				'altas'             => $altas,
				'baixas'            => $baixas,
				'baixas_sen_confirmar' => $pendentes,
				'precisa_exportar'  => null === $snapshot || array() !== $altas || array() !== $baixas,
			),
			200
		);
	}

	/**
	 * Builds the CSV body. Public and pure so it can be unit-tested.
	 *
	 * UTF-8 WITHOUT BOM (a BOM in front of "First Name" breaks Google's header
	 * matching), CRLF rows, every cell quoted. The Labels column carries the
	 * list label plus "* myContacts" (Google's marker for the main Contacts
	 * list, present in its own exports) separated by ":::".
	 *
	 * @since  1.58.0
	 * @param  array<int,array{email:string,nome:string,apelidos:string}> $socios Members.
	 * @return string
	 */
	public static function build_csv( array $socios ): string {
		$csv = ANPA_Socios_Csv::row( self::GOOGLE_HEADERS );
		foreach ( $socios as $s ) {
			$row = array_fill( 0, count( self::GOOGLE_HEADERS ), '' );
			$row[0]  = (string) $s['nome'];
			$row[2]  = (string) $s['apelidos'];
			$row[16] = self::LABEL . ' ::: * myContacts';
			$row[18] = (string) $s['email'];
			$csv    .= ANPA_Socios_Csv::row( $row );
		}

		return $csv;
	}

	/**
	 * GET /admin/contactos-google/export — downloads the CSV and records the snapshot.
	 *
	 * @since  1.58.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function export( WP_REST_Request $request ): WP_REST_Response {
		$socios = self::socios_activos();
		$csv    = self::build_csv( $socios );

		$user = wp_get_current_user();
		update_option(
			self::OPTION_SNAPSHOT,
			array(
				'exportado_en' => current_time( 'mysql' ),
				'por'          => $user instanceof WP_User ? (string) $user->user_email : '',
				'total'        => count( $socios ),
				'socios'       => $socios,
			),
			false
		);
		ANPA_Socios_Admin_Shared::write_audit( $request, 'export', 'contactos-google', 'export_csv' );

		$filename = 'socios-web-anpa-google-' . gmdate( 'Y-m-d' ) . '.csv';
		$response = new WP_REST_Response( null, 200 );
		$response->set_headers( array(
			'Content-Type'        => 'text/csv; charset=utf-8',
			'Content-Disposition' => 'attachment; filename="' . $filename . '"',
			'Content-Length'      => (string) strlen( $csv ),
			'Cache-Control'       => 'no-store',
		) );
		// Same raw-CSV serving trick as the other exports (WP REST only speaks JSON natively).
		add_filter( 'rest_pre_serve_request', function ( $served, $result ) use ( $csv ) {
			if ( $result instanceof WP_HTTP_Response ) {
				$headers = $result->get_headers();
				if ( isset( $headers['Content-Type'] ) && 0 === strpos( $headers['Content-Type'], 'text/csv' ) ) {
					foreach ( $headers as $key => $value ) {
						header( "$key: $value" );
					}
					echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV download.
					return true;
				}
			}
			return $served;
		}, 10, 2 );

		return $response;
	}
}
