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
			'subject' => self::render_field( $template['subject'], $vars['subject'] ?? array(), $context, 'subject', $template_id ),
			'html'    => self::render_field( $template['html'], $vars['html'] ?? array(), $context, 'html', $template_id ),
			'text'    => self::render_text( $template['text'], $vars['text'] ?? array(), $context, $template_id ),
		);
	}

	/**
	 * Render a field using sprintf with ordered values from store metadata.
	 */
	private static function render_field( string $content, array $var_keys, array $context, string $type, string $template_id = '' ): string {
		if ( empty( $content ) || empty( $var_keys ) ) {
			return $content;
		}
		$values = self::prepare_values( $var_keys, $context, $type );
		$result = self::formatar( $content, $values, $template_id, $type );
		return false !== $result ? ( 'html' === $type ? wp_kses_post( $result ) : $result ) : $content;
	}

	/**
	 * Render plain text body.
	 */
	private static function render_text( string $text, array $var_keys, array $context, string $template_id = '' ): string {
		if ( empty( $text ) || empty( $var_keys ) ) {
			return $text;
		}
		$values = self::prepare_values( $var_keys, $context, 'text' );
		$result = self::formatar( $text, $values, $template_id, 'text' );
		return false !== $result ? $result : $text;
	}

	/**
	 * sprintf() that survives an edited template with a stray `%`.
	 *
	 * On PHP 8 a literal `%` (e.g. "50%") throws ValueError or
	 * ArgumentCountError, which `@` does not silence; that would break
	 * sending login codes. Falls back to the built-in text of the same
	 * field and, if that fails too, to the text with its placeholders
	 * left as they are.
	 *
	 * @since  1.103.0
	 * @param  string   $content     Template text.
	 * @param  string[] $values      Ordered, escaped values.
	 * @param  string   $template_id Template identifier ('' when unknown).
	 * @param  string   $field       subject, html or text.
	 * @return string|false
	 */
	public static function formatar( string $content, array $values, string $template_id = '', string $field = 'text' ) {
		try {
			return @sprintf( $content, ...$values );
		} catch ( \Throwable $e ) {
			$default = '' !== $template_id ? ANPA_Socios_Email_Template_Store::get_default( $template_id ) : array();
			$base    = isset( $default[ $field ] ) ? (string) $default[ $field ] : '';
			if ( '' !== $base && $base !== $content ) {
				try {
					return @sprintf( $base, ...$values );
				} catch ( \Throwable $e2 ) {
					return $content;
				}
			}
			return $content;
		}
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
			} elseif ( 'login_url' === $key || '_url' === substr( $key, -4 ) ) {
				$values[] = esc_url( (string) $value );
			} elseif ( in_array( $key, array( 'contact_email', 'master_email', 'email_socio' ), true ) ) {
				// 1.62.0: emails are text, not URLs — esc_url() used to turn
				// "directiva@anpa.gal" into "http://directiva@anpa.gal".
				$values[] = esc_html( sanitize_text_field( (string) $value ) );
			} else {
				$values[] = esc_html( (string) $value );
			}
		}
		return $values;
	}
}
