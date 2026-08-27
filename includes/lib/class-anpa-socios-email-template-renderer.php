<?php
/**
 * ANPA_Socios_Email_Template_Renderer
 *
 * Renders email templates with safe variable replacement.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Renderer {

	/**
	 * Render a template with context variables.
	 *
	 * @param string $template_id Template identifier.
	 * @param array  $context     Variable replacements.
	 * @param array  $template    Optional template override (uses store if omitted).
	 * @return array              Array with subject, html, text keys.
	 */
	public static function render( string $template_id, array $context = array(), array $template = array() ): array {
		if ( empty( $template ) ) {
			$template = ANPA_Socios_Email_Template_Store::get( $template_id );
		}

		$template = wp_parse_args( $template, array(
			'subject' => '',
			'html'    => '',
			'text'    => '',
		) );

		return array(
			'subject' => self::render_subject( $template['subject'], $context ),
			'html'    => self::render_html( $template['html'], $context ),
			'text'    => self::render_text( $template['text'], $template['html'], $context ),
		);
	}

	/**
	 * Render subject line with variable replacement.
	 *
	 * @param string $subject Subject template.
	 * @param array  $context Variables.
	 * @return string          Rendered subject.
	 */
	public static function render_subject( string $subject, array $context ): string {
		return self::replace_variables( $subject, $context, 'subject' );
	}

	/**
	 * Render HTML body with variable replacement and sanitization.
	 *
	 * @param string $html    HTML template.
	 * @param array  $context Variables.
	 * @return string          Rendered and sanitized HTML.
	 */
	public static function render_html( string $html, array $context ): string {
		$replaced = self::replace_variables( $html, $context, 'html' );
		return wp_kses_post( $replaced );
	}

	/**
	 * Render plain text body.
	 *
	 * @param string $text       Text template (may be empty).
	 * @param string $html       HTML template (used to derive text if text is empty).
	 * @param array  $context    Variables.
	 * @return string            Rendered plain text.
	 */
	public static function render_text( string $text, string $html, array $context ): string {
		if ( empty( $text ) ) {
			$text = $html;
		}
		$replaced = self::replace_variables( $text, $context, 'text' );
		return self::html_to_text( $replaced );
	}

	/**
	 * Replace variables in content with escaping.
	 *
	 * @param string $content Content with placeholders.
	 * @param array  $context Variables.
	 * @param string $context_type Type: subject, html, text.
	 * @return string           Content with replacements.
	 */
	public static function replace_variables( string $content, array $context, string $context_type = 'html' ): string {
		if ( empty( $content ) ) {
			return '';
		}
		return preg_replace_callback( '/\{\{([a-z_]+)\}\}/', function( $matches ) use ( $context, $context_type ) {
			$var = $matches[1];
			if ( ! isset( $context[ $var ] ) ) {
				return ''; // Fail-safe: unknown variables become empty.
			}
			$value = $context[ $var ];
			if ( 'subject' === $context_type || 'text' === $context_type ) {
				return sanitize_text_field( (string) $value );
			}
			if ( in_array( $var, array( 'login_url', 'contact_email', 'master_email', 'email_socio' ), true ) ) {
				return esc_url( (string) $value );
			}
			return esc_html( (string) $value );
		}, $content );
	}

	/**
	 * Convert HTML to plain text.
	 *
	 * @param string $html HTML content.
	 * @return string      Plain text.
	 */
	public static function html_to_text( string $html ): string {
		$text = wp_kses_post( $html );
		$text = preg_replace( '/<br\s*\/?>/i', "\n", $text );
		$text = preg_replace( '/<\/(p|div|h[1-6]|li)>/i', "\n", $text );
		$text = preg_replace( '/<\/tr>/i', "\n", $text );
		$text = preg_replace( '/<\/td>/i', "\t", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/\n\n+/", "\n\n", $text );
		return trim( $text );
	}
}
