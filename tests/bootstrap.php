<?php
/**
 * PHPUnit bootstrap for anpa-socios tests.
 *
 * Loads the pure-logic library class with full WordPress function stubs.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

define( 'ABSPATH', '/tmp/' );
define( 'WP_CONTENT_DIR', '/tmp/' );
define( 'WP_PLUGIN_DIR', '/tmp/' );

// ============ WordPress Stubs ============

// Shared options storage (single instance).
global $wp_options;
$wp_options = array();

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
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
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( (string) $str );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string ) {
		return strip_tags( (string) $string );
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$parsed = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed = $args;
		} else {
			parse_str( (string) $args, $parsed );
		}
		return is_array( $defaults ) ? array_merge( $defaults, $parsed ) : $parsed;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		global $wp_options;
		return isset( $wp_options[ $name ] ) ? $wp_options[ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		global $wp_options;
		$wp_options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		global $wp_options;
		if ( isset( $wp_options[ $name ] ) ) {
			unset( $wp_options[ $name ] );
			return true;
		}
		return false;
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
			'a'      => array( 'href' => true, 'title' => true, 'class' => true ),
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
		return preg_replace( '/<([a-z]+)[^>]*?(on\w+)=[^>]*?/i', '<$1', $html );
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
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string ) {
		return strip_tags( (string) $string );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

// NOTE: current_user_can() is intentionally NOT stubbed here.
// Tests that verify capability checks must define their own version.
// This allows tests to return false to test failure paths.

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
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-templates-page.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-template-actions.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-template-migration.php';
require_once __DIR__ . '/trait-anpa-socios-inspection.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email.php';

// ANPA_Socios_Config stub removed — tests that need it use require_once explicitly.
