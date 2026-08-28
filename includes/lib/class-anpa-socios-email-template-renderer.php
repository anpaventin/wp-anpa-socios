<?php
/**
 * ANPA_Socios_Email_Template_Renderer
 *
 * Renders email templates with safe variable replacement.
 * Uses variable metadata from the store to ensure correct substitution.
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

		$vars = ANPA_Socios_Email_Template_Store::get_variables( $template_id );

		return array(
			'subject' => self::render_field( $template['subject'], $vars['subject'] ?? array(), $context, 'subject' ),
			'html'    => self::render_field( $template['html'], $vars['html'] ?? array(), $context, 'html' ),
			'text'    => self::render_text( $template['text'], $vars['text'] ?? array(), $context ),
		);
	}

	/**
	 * Render a field using sprintf with ordered values from store metadata.
	 */
	private static function render_field( string $content, array $var_keys, array $context, string $type ): string {
		if ( empty( $content ) || empty( $var_keys ) ) {
			return $content;
		}
		$values = self::prepare_values( $var_keys, $context, $type );
		$result = @sprintf( $content, ...$values );
		return false !== $result ? ( 'html' === $type ? wp_kses_post( $result ) : $result ) : $content;
	}

	/**
	 * Render plain text body.
	 */
	private static function render_text( string $text, array $var_keys, array $context ): string {
		if ( empty( $text ) || empty( $var_keys ) ) {
			return $text;
		}
		$values = self::prepare_values( $var_keys, $context, 'text' );
		$result = @sprintf( $text, ...$values );
		return false !== $result ? $result : $text;
	}

	/**
	 * Prepare escaped values in the order specified by the store.
	 */
	private static function prepare_values( array $var_keys, array $context, string $type ): array {
		$values = array();
		foreach ( $var_keys as $key ) {
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
}
