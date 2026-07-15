<?php
/**
 * Pure helper for the native admin navigation map (fase17a).
 *
 * Keeps the planned admin information architecture outside WordPress glue so
 * tabs, subsections and management sections can be unit-tested before the UI
 * is wired to wp-admin.
 *
 * @since  1.32.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Admin navigation maps + active item resolution.
 *
 * @since 1.32.0
 */
final class ANPA_Socios_Admin_Nav {

	/**
	 * Settings top-level tabs. First entry is the default tab.
	 *
	 * @since 1.32.0
	 * @var array<string,string>
	 */
	private const SETTINGS_TABS = array(
		'xeral'        => 'Xeral',
		'cursos'       => 'Cursos',
		'localizacion' => 'Localización e idioma',
	);

	/**
	 * Settings tab sections. First entry per tab is the default section.
	 *
	 * @since 1.32.0
	 * @var array<string,array<string,string>>
	 */
	private const SETTINGS_SECTIONS = array(
		'xeral'        => array(
			'estado'        => 'Estado',
			'mantemento'    => 'Mantemento',
			'configuracion' => 'Configuración',
			'paxinas'       => 'Páxinas e shortcodes',
		),
		'cursos'       => array(
			'estrutura' => 'Estrutura escolar',
		),
		'localizacion' => array(),
	);

	/**
	 * Native management sections. No standalone "listados" section by design.
	 *
	 * @since 1.32.0
	 * @var array<string,string>
	 */
	private const MANAGEMENT_SECTIONS = array(
		'inicio'             => 'Inicio',
		'socios'             => 'Socios/as',
		'aprobacions'        => 'Aprobacións',
		'fillos'             => 'Fillos/as',
		'empresas'           => 'Empresas',
		'actividades'        => 'Actividades',
		'cursos-matriculas'  => 'Cursos e matrículas',
		'importar-listados'  => 'Importar listados',
		'auditoria'          => 'Auditoría',
	);

	/**
	 * Native wp-admin management page metadata.
	 *
	 * @since 1.32.0
	 * @var array<string,string>
	 */
	private const NATIVE_MANAGEMENT_PAGE = array(
		'slug'       => 'anpa-socios-management',
		'menu_label' => 'Xestión ANPA',
		'page_title' => 'Xestión ANPA',
	);

	/**
	 * Plugin submenu metadata in display order.
	 *
	 * @since 1.34.0
	 * @var array<string,array<string,string>>
	 */
	private const PLUGIN_SUBMENUS = array(
		'settings'      => array(
			'slug'       => 'anpa-socios-settings',
			'menu_label' => 'Axustes',
			'page_title' => 'Axustes ANPA Socios',
		),
		'management'    => self::NATIVE_MANAGEMENT_PAGE,
		'documentation' => array(
			'slug'       => 'anpa-socios-docs',
			'menu_label' => 'Documentación',
			'page_title' => 'Documentación ANPA Socios',
		),
	);

	/**
	 * Planned import targets. Kept read-only until real importers land.
	 *
	 * @since 1.32.0
	 * @var array<string,string>
	 */
	private const IMPORT_TARGETS = array(
		'socios'      => 'Socios/as',
		'fillos'      => 'Fillos/as',
		'empresas'    => 'Empresas',
		'actividades' => 'Actividades',
		'matriculas'  => 'Matrículas',
	);

	/**
	 * Export actions attached to their domain sections.
	 *
	 * @since 1.32.0
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private const MANAGEMENT_EXPORT_ACTIONS = array(
		'socios'             => array(
			'csv'            => array(
				'label' => 'Socios/as CSV',
			),
			'sensitive_full' => array(
				'label'               => 'Descargar Socios IBAN',
				'requires_passphrase' => true,
			),
		),
		'fillos'             => array(
			'csv' => array(
				'label' => 'Fillos/as CSV',
			),
		),
		'empresas'           => array(
			'csv' => array(
				'label' => 'Empresas CSV',
			),
		),
		'actividades'        => array(
			'csv' => array(
				'label' => 'Actividades CSV',
			),
		),
		'cursos-matriculas'  => array(
			'csv' => array(
				'label' => 'Matrículas CSV',
			),
		),
		'importar-listados'  => array(
			'csv_preview_only' => array(
				'label'      => 'Validación e vista previa',
				'write_safe' => false,
			),
		),
	);

	/**
	 * Documentation sections for the operator help page.
	 *
	 * @since 1.32.0
	 * @var array<string,string>
	 */
	private const DOCS_SECTIONS = array(
		'posta-en-marcha'        => 'Posta en marcha',
		'ciclo-curso'            => 'Ciclo do curso escolar',
		'paxinas-shortcodes'     => 'Páxinas automáticas e shortcodes',
		'extraescolares'         => 'Extraescolares',
		'exportacions-copias'    => 'Exportacións e copias',
		'privacidade-seguridade' => 'Privacidade e seguridade',
	);

	/**
	 * Returns settings top-level tabs.
	 *
	 * @since  1.32.0
	 * @return array<string,string>
	 */
	public static function settings_tabs(): array {
		return self::SETTINGS_TABS;
	}

	/**
	 * Returns plugin submenu metadata in display order.
	 *
	 * @since  1.34.0
	 * @return array<string,array<string,string>>
	 */
	public static function plugin_submenus(): array {
		return self::PLUGIN_SUBMENUS;
	}

	/**
	 * Whether daily management may be opened.
	 *
	 * @since  1.34.0
	 * @param  bool $setup_done Whether first-run setup is complete.
	 * @return bool
	 */
	public static function can_access_management( bool $setup_done ): bool {
		return $setup_done;
	}

	/**
	 * Whether the slug is a known settings tab.
	 *
	 * @since  1.32.0
	 * @param  mixed $slug Candidate slug.
	 * @return bool
	 */
	public static function is_settings_tab( $slug ): bool {
		return is_string( $slug ) && array_key_exists( $slug, self::SETTINGS_TABS );
	}

	/**
	 * Resolves the active settings tab.
	 *
	 * @since  1.32.0
	 * @param  mixed $requested Raw requested slug.
	 * @return string
	 */
	public static function active_settings_tab( $requested ): string {
		if ( self::is_settings_tab( $requested ) ) {
			return (string) $requested;
		}

		return self::first_key( self::SETTINGS_TABS );
	}

	/**
	 * Returns sections for a tab. Unknown tabs have no sections.
	 *
	 * @since  1.32.0
	 * @param  string $tab Settings tab slug.
	 * @return array<string,string>
	 */
	public static function settings_sections( string $tab ): array {
		return self::SETTINGS_SECTIONS[ $tab ] ?? array();
	}

	/**
	 * Whether the section slug is valid for the tab.
	 *
	 * @since  1.32.0
	 * @param  string $tab     Settings tab slug.
	 * @param  mixed  $section Candidate section slug.
	 * @return bool
	 */
	public static function is_settings_section( string $tab, $section ): bool {
		return is_string( $section ) && array_key_exists( $section, self::settings_sections( $tab ) );
	}

	/**
	 * Resolves the active section for a tab.
	 *
	 * @since  1.32.0
	 * @param  string $tab       Settings tab slug.
	 * @param  mixed  $requested Raw requested section slug.
	 * @return string
	 */
	public static function active_settings_section( string $tab, $requested ): string {
		$sections = self::settings_sections( $tab );
		if ( array() === $sections ) {
			return '';
		}

		if ( self::is_settings_section( $tab, $requested ) ) {
			return (string) $requested;
		}

		return self::first_key( $sections );
	}

	/**
	 * Returns native management sections.
	 *
	 * @since  1.32.0
	 * @return array<string,string>
	 */
	public static function management_sections(): array {
		return self::MANAGEMENT_SECTIONS;
	}

	public static function native_management_page(): array {
		return self::NATIVE_MANAGEMENT_PAGE;
	}

	/**
	 * Returns planned import targets.
	 *
	 * @since  1.32.0
	 * @return array<string,string>
	 */
	public static function import_targets(): array {
		return self::IMPORT_TARGETS;
	}

	/**
	 * Returns export/import actions attached to management domain sections.
	 *
	 * @since  1.32.0
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public static function management_export_actions(): array {
		return self::MANAGEMENT_EXPORT_ACTIONS;
	}

	/**
	 * Returns documentation sections for the Docs admin page.
	 *
	 * @since  1.32.0
	 * @return array<string,string>
	 */
	public static function docs_sections(): array {
		return self::DOCS_SECTIONS;
	}

	/**
	 * First key from a non-empty ordered map.
	 *
	 * @since  1.32.0
	 * @param  array<string,string> $items Ordered map.
	 * @return string
	 */
	private static function first_key( array $items ): string {
		$keys = array_keys( $items );

		return (string) $keys[0];
	}
}
