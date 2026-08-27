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

// Ensure we have access to basic WordPress stubs for pure-logic tests.
define( 'ABSPATH', '/tmp/' );
define( 'WP_CONTENT_DIR', '/tmp/' );
define( 'WP_PLUGIN_DIR', '/tmp/' );

// Minimal WP stubs required by pure-logic tests.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters() {
		return null;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter() {}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_VALIDATE_URL ) ?: '';
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) {
		$allowed = array(
			'p'      => array( 'class' => true ),
			'br'     => array(),
			'strong' => array(),
			'em'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'a'      => array( 'href' => true, 'title' => true ),
			'h1'     => array(),
			'h2'     => array(),
			'h3'     => array(),
			'h4'     => array(),
			'table'  => array(),
			'tr'     => array(),
			'td'     => array(),
			'th'     => array(),
			'img'    => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true ),
			'div'    => array( 'class' => true ),
			'span'   => array( 'class' => true ),
		);
		return wp_kses( $html, $allowed );
	}
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $html, $allowed_html ) {
		$html = strip_tags( $html, '<' . implode( '><', array_keys( $allowed_html ) ) . '>' );
		// Remove any disallowed attributes.
		return preg_replace( '/<([a-z]+)[^>]*?(on\w+)=[^>]*?/i', '<$1', $html );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $html ) {
		return strip_tags( $html );
	}
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $string ) {
		return htmlspecialchars_decode( (string) $string, ENT_QUOTES );
	}
}
if ( ! function_exists( 'ent2ncr' ) ) {
	function ent2ncr( $text ) {
		return $text;
	}
}

// Plugin constants.
if ( ! defined( 'ANPA_SOCIOS_VERSION' ) ) {
	define( 'ANPA_SOCIOS_VERSION', '1.49.0' );
}
if ( ! defined( 'ANPA_SOCIOS_PLUGIN_DIR' ) ) {
	define( 'ANPA_SOCIOS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ANPA_SOCIOS_PLUGIN_URL' ) ) {
	define( 'ANPA_SOCIOS_PLUGIN_URL', 'http://example.org/wp-content/plugins/anpa-socios/' );
}

// Autoloader classes and lib.
require_once __DIR__ . '/includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/includes/lib/class-anpa-socios-email-template-renderer.php';
