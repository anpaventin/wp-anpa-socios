<?php
/**
 * FASE36 Template Render Provider.
 *
 * Implements the FASE35 render contract so the queue can render template-based
 * emails without knowing the template syntax. Falls back to passthrough when
 * no template_ref is supplied, preserving backward compatibility.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Template_Render_Provider implements ANPA_Socios_Email_Render_Provider_Interface {

	/**
	 * Renders a message using the FASE36 template engine.
	 *
	 * When $template_ref is empty, falls back to the already-rendered content
	 * in $context (subject/body_html/body_text), matching the passthrough
	 * behavior so callers that do not use templates are unaffected.
	 *
	 * @param string $event_type   Logical event.
	 * @param string $template_ref Template id or ''.
	 * @param array  $context      Variables for replacement (when templated) or
	 *                             pre-rendered subject/body_html/body_text.
	 * @return array{subject:string,body_html:string,body_text:string}
	 */
	public function render( string $event_type, string $template_ref, array $context ): array {
		if ( '' === $template_ref ) {
			$subject = (string) ( $context['subject'] ?? '' );
			$html    = (string) ( $context['body_html'] ?? '' );
			$text    = (string) ( $context['body_text'] ?? '' );

			if ( '' === $text && '' !== $html ) {
				$text = wp_strip_all_tags( $html );
			}

			return array(
				'subject'   => $subject,
				'body_html' => $html,
				'body_text' => $text,
			);
		}

		$rendered = ANPA_Socios_Email_Template_Renderer::render( $template_ref, $context );

		return array(
			'subject'   => $rendered['subject'],
			'body_html' => $rendered['html'],
			'body_text' => $rendered['text'],
		);
	}
}
