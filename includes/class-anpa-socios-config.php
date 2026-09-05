<?php
/**
 * Configuration resolver for the ANPA Socios plugin.
 *
 * Resolves the master email with the following precedence (highest first):
 *   1. PHP constant ANPA_SOCIOS_MASTER_EMAIL (set in wp-config.php)
 *   2. WordPress option 'anpa_socios_master_email' (set via admin UI / WP-CLI)
 *   3. Default: ANPA_Socios_Roles::MASTER_EMAIL
 *
 * The returned value is always lowercased and trimmed.
 *
 * This class depends on WordPress (get_option, defined, constant). It is
 * NOT unit-testable without a WP bootstrap; coverage is provided by
 * staging E2E tests.
 *
 * @since  1.4.1
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves plugin configuration values from constant / option / default.
 *
 * @since 1.4.1
 */
final class ANPA_Socios_Config {

	/**
	 * WordPress option key for the master email override.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const OPTION = 'anpa_socios_master_email';

	/**
	 * PHP constant name checked in wp-config.php.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const CONST_NAME = 'ANPA_SOCIOS_MASTER_EMAIL';

	/**
	 * Returns the resolved master email.
	 *
	 * Precedence: wp-config constant > WP option > default constant.
	 * Always lowercased and trimmed.
	 *
	 * @since  1.4.1
	 * @return string
	 */
	public static function master_email(): string {
		// 1. PHP constant (highest priority — set in wp-config.php).
		if ( defined( self::CONST_NAME ) && '' !== trim( (string) constant( self::CONST_NAME ) ) ) {
			return strtolower( trim( (string) constant( self::CONST_NAME ) ) );
		}

		// 2. WordPress option (admin UI or WP-CLI).
		$opt = get_option( self::OPTION, '' );
		if ( is_string( $opt ) && '' !== trim( $opt ) ) {
			return strtolower( trim( $opt ) );
		}

		// 3. Fallback to the hardcoded default.
		return strtolower( ANPA_Socios_Roles::MASTER_EMAIL );
	}

	/**
	 * Option keys for fase12 identity/config.
	 *
	 * @var string
	 */
	const OPTION_ASSOCIATION  = 'anpa_socios_association_name';
	const OPTION_SIGNATURE     = 'anpa_socios_email_signature';
	const OPTION_APPROVAL      = 'anpa_socios_require_approval';
	const OPTION_CONTACT_EMAIL = 'anpa_socios_contact_email';
	const OPTION_ADDRESS       = 'anpa_socios_association_address';
	const OPTION_FEE           = 'anpa_socios_membership_fee';
	const OPTION_MENU_NAME     = 'anpa_socios_menu_name';
	// Opt-in beta channel: when '1', the self-hosted updater reads the
	// prerelease metadata channel (details-prerelease.json) instead of the
	// stable one. Default '0' so a production install NEVER receives a
	// prerelease automatically.
	const OPTION_USE_PRERELEASES = 'anpa_socios_use_prereleases';

	// Localización (multi-tenant location defaults). Language is NOT a plugin
	// setting: the plugin follows the WordPress site language (see i18n in the
	// bootstrap), so there is no custom language option here.
	const OPTION_COUNTRY       = 'anpa_socios_country';
	const OPTION_PROVINCE      = 'anpa_socios_default_province';
	const OPTION_TOWN          = 'anpa_socios_default_town';
	const OPTION_POSTAL_CODE   = 'anpa_socios_default_postal_code';


	/**
	 * Neutral location default. Empty province/town means the socio form ships
	 * no prefill until the deployer sets their area from Axustes. No single
	 * association's data is hardcoded.
	 *
	 * @var string
	 */
	const DEFAULT_COUNTRY = '';

	/**
	 * Generic default association name. Deployers set their real name via the
	 * setup wizard / Axustes (option OPTION_ASSOCIATION); this neutral default
	 * keeps the plugin free of any single association's identity so it can be
	 * published and reused by any ANPA/AMPA.
	 *
	 * @var string
	 */
	const DEFAULT_ASSOCIATION  = 'ANPA';

	/**
	 * Default sidebar menu label shown when no custom menu name is saved.
	 *
	 * @var string
	 */
	const DEFAULT_MENU_NAME = 'Xestión ANPA';

	/**
	 * Maximum length for the sidebar menu label.
	 *
	 * @var int
	 */
	const MENU_NAME_MAX_LENGTH = 30;

	/**
	 * Default membership fee shown in the alta copy (euros, per family/course).
	 *
	 * @var string
	 */
	const DEFAULT_FEE = '15';

	/**
	 * The configurable association name (variable, not hardcoded).
	 *
	 * @return string
	 */
	public static function association_name(): string {
		$value = trim( (string) get_option( self::OPTION_ASSOCIATION, '' ) );

		return '' !== $value ? $value : self::DEFAULT_ASSOCIATION;
	}

	/**
	 * Configurable sidebar menu label for the admin top-level entry.
	 *
	 * The option is trimmed, stripped of tags and limited to a reasonable
	 * length so the wp-admin sidebar stays usable even if an admin pastes a
	 * long title by mistake.
	 *
	 * @return string
	 */
	public static function menu_name(): string {
		$value = trim( (string) get_option( self::OPTION_MENU_NAME, '' ) );
		$value = trim( wp_strip_all_tags( $value ) );

		if ( '' === $value ) {
			return self::DEFAULT_MENU_NAME;
		}

		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, self::MENU_NAME_MAX_LENGTH );
		} else {
			$value = substr( $value, 0, self::MENU_NAME_MAX_LENGTH );
		}

		$value = trim( $value );

		return '' !== $value ? $value : self::DEFAULT_MENU_NAME;
	}

	/**
	 * Public contact email shown to families (error messages, notices).
	 *
	 * Precedence: option anpa_socios_contact_email > the master email.
	 *
	 * @return string
	 */
	public static function contact_email(): string {
		$value = trim( (string) get_option( self::OPTION_CONTACT_EMAIL, '' ) );

		return '' !== $value ? $value : self::master_email();
	}

	/**
	 * The association's postal address for the RGPD/privacy notice.
	 *
	 * Empty by default (generic plugin ships no address); deployers set their
	 * own via Axustes. When empty the RGPD text omits the address clause.
	 *
	 * @return string
	 */
	public static function association_address(): string {
		return trim( (string) get_option( self::OPTION_ADDRESS, '' ) );
	}

	/**
	 * The annual membership fee (euros, per family and course) as a string.
	 *
	 * @return string
	 */
	public static function membership_fee(): string {
		$value = trim( (string) get_option( self::OPTION_FEE, '' ) );

		return '' !== $value ? $value : self::DEFAULT_FEE;
	}

	/**
	 * The configurable signature appended to plugin emails.
	 *
	 * @return string
	 */
	public static function email_signature(): string {
		return (string) get_option( self::OPTION_SIGNATURE, '' );
	}

	/**
	 * Configured country (free text; empty by default).
	 *
	 * @return string
	 */
	public static function country(): string {
		$value = trim( (string) get_option( self::OPTION_COUNTRY, '' ) );

		return '' !== $value ? $value : self::DEFAULT_COUNTRY;
	}

	/**
	 * Default province/state prefilled into the socio's Provincia field.
	 * The socio can always overwrite it.
	 *
	 * @return string
	 */
	public static function default_province(): string {
		return trim( (string) get_option( self::OPTION_PROVINCE, '' ) );
	}

	/**
	 * Default town prefilled into the socio's Poboación field.
	 * The socio can always overwrite it.
	 *
	 * @return string
	 */
	public static function default_town(): string {
		return trim( (string) get_option( self::OPTION_TOWN, '' ) );
	}

	/**
	 * Default postal code prefilled into the socio's Código Postal field.
	 * The socio can always overwrite it. Empty by default (generic plugin).
	 *
	 * @since  1.46.3
	 * @return string
	 */
	public static function default_postal_code(): string {
		return trim( (string) get_option( self::OPTION_POSTAL_CODE, '' ) );
	}

	/**
	 * Whether new socios require master approval before gaining access.
	 *
	 * @return bool
	 */
	public static function require_approval(): bool {
		return '1' === (string) get_option( self::OPTION_APPROVAL, '0' );
	}

	/**
	 * Whether this install opts in to the prerelease (beta) update channel.
	 *
	 * When true, the self-hosted updater reads details-prerelease.json and can
	 * offer prerelease builds. Default false: a production install only ever
	 * sees stable releases unless the admin explicitly enables this in
	 * Axustes → Actualizacións.
	 *
	 * @since  1.46.0
	 * @return bool
	 */
	public static function use_prereleases(): bool {
		return '1' === (string) get_option( self::OPTION_USE_PRERELEASES, '0' );
	}

	// ── FASE37: Contido Administrativo ──────────────────────────────────────────

	/**
	 * WordPress option key for administrative content (Transporte, Libros, Servizos).
	 *
	 * @since  1.49.2
	 * @var string
	 */
	const OPTION_CONTENIDO_ADMIN = 'anpa_socios_contenido_admin';

	/**
	 * Valid content categories.
	 *
	 * @since  1.49.2
	 * @var string[]
	 */
	const CATEGORIAS_VALIDAS = [ 'transporte', 'libros', 'bos-dias', 'comedor', 'tardes-divertidas' ];

	/**
	 * Dashicons per category.
	 *
	 * @since  1.49.2
	 * @var string[]
	 */
	const ICONOS = [
		'transporte'        => 'dashicons-car',
		'libros'            => 'dashicons-book',
		'bos-dias'          => 'dashicons-sun',
		'comedor'           => 'dashicons-carrot',
		'tardes-divertidas' => 'dashicons-palmtree',
	];

	/**
	 * Default content configuration per category.
	 *
	 * @since  1.49.2
	 * @var array
	 */
	const DEFAULTS = [
		'transporte' => [
			'activo' => true, 'titulo' => '', 'contido' => '',
			'icono' => 'dashicons-car', 'documentos' => [], 'enlaces' => [], 'orden' => 1,
		],
		'libros' => [
			'activo' => true, 'titulo' => '', 'contido' => '',
			'icono' => 'dashicons-book', 'documentos' => [], 'enlaces' => [], 'items' => [], 'orden' => 2,
		],
		'bos-dias' => [
			'activo' => true, 'titulo' => '', 'contido' => '',
			'icono' => 'dashicons-sun', 'documentos' => [], 'enlaces' => [], 'orden' => 3,
		],
		'comedor' => [
			'activo' => true, 'titulo' => '', 'contido' => '',
			'icono' => 'dashicons-carrot', 'documentos' => [], 'enlaces' => [], 'items' => [], 'orden' => 4,
		],
		'tardes-divertidas' => [
			'activo' => true, 'titulo' => '', 'contido' => '',
			'icono' => 'dashicons-palmtree', 'documentos' => [], 'enlaces' => [], 'orden' => 5,
		],
	];

	/**
	 * Returns the default content configuration.
	 *
	 * @since  1.49.2
	 * @return array
	 */
	public static function contenido_admin_defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Gets the content configuration.
	 *
	 * @since  1.49.2
	 * @param  string|null $categoria Optional. Specific category to retrieve.
	 * @return array
	 */
	public static function contenido_admin( string $categoria = null ): array {
		$config = get_option( self::OPTION_CONTENIDO_ADMIN, null );

		if ( ! is_array( $config ) || empty( $config ) ) {
			$config = self::contenido_admin_defaults();
		}

		if ( $categoria ) {
			return isset( $config[ $categoria ] ) ? $config[ $categoria ] : [];
		}

		return $config;
	}

	/**
	 * Updates the content configuration for a specific category.
	 *
	 * @since  1.49.2
	 * @param  string $categoria Category key.
	 * @param  array  $data      Data to merge.
	 * @return bool
	 */
	public static function update_contenido_admin( string $categoria, array $data ): bool {
		if ( ! in_array( $categoria, self::CATEGORIAS_VALIDAS, true ) ) {
			return false;
		}

		$config    = self::contenido_admin();
		$current   = isset( $config[ $categoria ] ) ? $config[ $categoria ] : self::DEFAULTS[ $categoria ];
		$defaults  = self::DEFAULTS[ $categoria ];
		$validKeys = array_keys( $defaults );

		// Filter data to only valid keys (fail-closed: ignore unknown fields).
		$filteredData = array_intersect_key( $data, array_flip( $validKeys ) );

		// Sanitize string fields.
		if ( isset( $filteredData['titulo'] ) ) {
			$filteredData['titulo'] = trim( sanitize_text_field( wp_unslash( $filteredData['titulo'] ) ) );
		}
		if ( isset( $filteredData['icono'] ) ) {
			$filteredData['icono'] = sanitize_key( wp_unslash( $filteredData['icono'] ) );
		}

		// Sanitize items array (for libros/comedor).
		if ( isset( $filteredData['items'] ) && is_array( $filteredData['items'] ) ) {
			$filteredData['items'] = self::sanitize_items( $filteredData['items'], $categoria );
		}

		// Sanitize documents array.
		if ( isset( $filteredData['documentos'] ) && is_array( $filteredData['documentos'] ) ) {
			$filteredData['documentos'] = self::sanitize_documentos( $filteredData['documentos'] );
		}

		// Sanitize links array.
		if ( isset( $filteredData['enlaces'] ) && is_array( $filteredData['enlaces'] ) ) {
			$filteredData['enlaces'] = self::sanitize_enlaces( $filteredData['enlaces'] );
		}

		$config[ $categoria ] = array_merge( $current, $filteredData );

		return update_option( self::OPTION_CONTENIDO_ADMIN, $config );
	}

	/**
	 * Sanitizes documents array (Media Library attachments).
	 *
	 * @since  1.49.2
	 * @param  array $documentos Raw documents array.
	 * @return array
	 */
	private static function sanitize_documentos( array $documentos ): array {
		$sanitized = [];
		foreach ( $documentos as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}
			$id = isset( $doc['id'] ) ? absint( wp_unslash( $doc['id'] ) ) : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$sanitized[] = [
				'id'    => $id,
				'url'   => isset( $doc['url'] ) ? esc_url_raw( wp_unslash( $doc['url'] ) ) : '',
				'title' => isset( $doc['title'] ) ? sanitize_text_field( wp_unslash( $doc['title'] ) ) : '',
			];
		}
		return array_values( $sanitized );
	}

	/**
	 * Sanitizes links array.
	 *
	 * @since  1.49.2
	 * @param  array $enlaces Raw links array.
	 * @return array
	 */
	private static function sanitize_enlaces( array $enlaces ): array {
		$sanitized = [];
		foreach ( $enlaces as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$url = isset( $link['url'] ) ? esc_url_raw( wp_unslash( $link['url'] ) ) : '';
			if ( '' === $url ) {
				continue;
			}
			$sanitized[] = [
				'title' => isset( $link['title'] ) ? sanitize_text_field( wp_unslash( $link['title'] ) ) : '',
				'url'   => $url,
			];
		}
		return array_values( $sanitized );
	}

	/**
	 * Sanitizes an array of structured items.
	 *
	 * @since  1.49.2
	 * @param  array  $items     Raw items array.
	 * @param  string $categoria Category key.
	 * @return array
	 */
	private static function sanitize_items( array $items, string $categoria ): array {
		$validKeys = $categoria === 'comedor'
			? [ 'fecha', 'menu', 'alerxenos' ]
			: [ 'curso', 'nivel', 'materia', 'titulo', 'editorial', 'isbn', 'prezo', 'descarga' ];

		$sanitized = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			// Filter to valid keys only.
			$filtered = array_intersect_key( $item, array_flip( $validKeys ) );
			// Sanitize each field.
			foreach ( $filtered as $key => $val ) {
				if ( $key === 'descarga' ) {
					$filtered[ $key ] = esc_url_raw( wp_unslash( $val ) );
				} else {
					$filtered[ $key ] = sanitize_text_field( wp_unslash( $val ) );
				}
			}
			// Merge with defaults to ensure all keys exist.
			$defaults = array_fill_keys( $validKeys, '' );
			$sanitized[] = array_merge( $defaults, $filtered );
		}

		// Re-index array.
		return array_values( $sanitized );
	}

	/**
	 * Deletes the content configuration.
	 *
	 * @since  1.49.2
	 * @return bool
	 */
	public static function delete_contenido_admin(): bool {
		return delete_option( self::OPTION_CONTENIDO_ADMIN );
	}
}
