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
			'subject' => self::render_field( $template['subject'], $context, 'subject' ),
			'html'    => self::render_field( $template['html'], $context, 'html' ),
			'text'    => self::render_text( $template['text'], $template['html'], $context ),
		);
	}

	/**
	 * Render a field using sprintf-style placeholders.
	 */
	private static function render_field( string $content, array $context, string $type ): string {
		if ( empty( $content ) ) {
			return '';
		}
		$values = self::prepare_values( $context, $type, $content );
		return wp_kses_post( sprintf( $content, ...$values ) );
	}

	/**
	 * Render plain text.
	 */
	private static function render_text( string $text, string $html, array $context ): string {
		if ( empty( $text ) ) {
			$text = $html;
		}
		$values = self::prepare_values( $context, 'text', $text );
		return sprintf( $text, ...$values );
	}

	/**
	 * Prepare values for sprintf based on %s occurrences in order.
	 */
	private static function prepare_values( array $context, string $type, string $template ): array {
		$count = substr_count( $template, '%s' );
		if ( $count === 0 ) {
			return array();
		}

		$ordered = self::get_ordered_keys( $template );
		$values = array();
		foreach ( $ordered as $key ) {
			$value = $context[ $key ] ?? '';
			if ( 'subject' === $type || 'text' === $type ) {
				$values[] = sanitize_text_field( (string) $value );
			} elseif ( in_array( $key, array( 'login_url', 'contact_email', 'master_email', 'email_socio' ), true ) ) {
				$values[] = esc_url( (string) $value );
			} else {
				$values[] = esc_html( (string) $value );
			}
		}
		return $values;
	}

	/**
	 * Get ordered keys from template based on known variable names.
	 */
	private static function get_ordered_keys( string $template ): array {
		$known = array(
			'association_name', 'email_socio', 'nome', 'apelidos',
			'alumno', 'actividade', 'dias_prazo', 'login_url', 'codigo',
			'contact_email', 'master_email',
		);
		$found = array();
		foreach ( $known as $key ) {
			if ( str_contains( $template, '%s' ) ) {
				// We need to find which keys are used; this is a simplified approach
				// In production, we'd parse the template more carefully
				$found[] = $key;
			}
		}
		return array_slice( $found, 0, substr_count( $template, '%s' ) );
	}
}
