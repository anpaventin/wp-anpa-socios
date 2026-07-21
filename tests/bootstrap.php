<?php
/**
 * PHPUnit bootstrap for anpa-socios tests.
 *
 * Loads the pure-logic library class only. No WordPress bootstrap.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

// Define ABSPATH so classes that guard against direct access (e.g. Config)
// can be loaded in the test bootstrap without WordPress.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/../includes/lib/class-anpa-socios-payload.php';
require_once __DIR__ . '/../includes/class-anpa-socios-config.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-sepa.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-crypto.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-codigo-generator.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-rate-limiter.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-area-session.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-roles.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-flow.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-admin-payload.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-actividade-options.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-curso-fit.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-grupo-niveis.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-grupo-serie.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-prazas.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-disponibilidade-horaria.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-estrutura-escolar.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-curso-escolar.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-grupos-horarios.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-curso-lifecycle.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-course-settings.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-season.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-preseason-gate.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-horario-builder.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-waitlist.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-trimestre.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-alta-payload.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-actividades-collapse.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-empresa-view.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-csv.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-antibot.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-alumnos-export.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-admin-nav.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-verificacion-guard.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-admin-auth.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-normalize.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-familia.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-csv-import.php';
require_once __DIR__ . '/../includes/class-anpa-socios-db.php';

// Minimal $wpdb stub for table-name helpers; tests never hit a real DB.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class {
		public string $prefix = 'wp_';
	};
}
