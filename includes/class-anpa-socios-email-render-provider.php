<?php
/**
 * Stable render contract between the message generator and the transport
 * (fase35, PR-35s3).
 *
 * The queue NEVER knows the template syntax. It asks a provider to turn an event
 * plus a per-recipient context into a concrete subject/body, and then FREEZES
 * that result as an immutable snapshot on the recipient row. Editing a template
 * afterwards therefore cannot change what a still-pending recipient receives.
 *
 * fase36 will register its template engine as a provider via the
 * `anpa_socios_email_render_provider` filter WITHOUT touching the queue.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ANPA_Socios_Email_Render_Provider_Interface {

	/**
	 * Renders one message for one recipient.
	 *
	 * @param string               $event_type Logical event (neutral to the queue).
	 * @param string               $template_ref Template id, or '' when none.
	 * @param array<string,mixed>  $context    Minimal per-recipient context.
	 * @return array{subject:string,body_html:string,body_text:string}
	 */
	public function render( string $event_type, string $template_ref, array $context ): array;
}

/**
 * Default provider used until fase36 lands.
 *
 * It performs NO template parsing: it simply takes an already-rendered
 * subject/body from the context (the caller may pass the current hardcoded
 * ANPA_Socios_Email texts). This keeps PR-35s3 independent from fase36 while
 * fixing the extension point.
 *
 * @since 1.39.0
 */
final class ANPA_Socios_Email_Passthrough_Render_Provider implements ANPA_Socios_Email_Render_Provider_Interface {

	/**
	 * @param string              $event_type   Logical event.
	 * @param string              $template_ref Unused here (fase36 will use it).
	 * @param array<string,mixed> $context      Expects subject/body_html/body_text.
	 * @return array{subject:string,body_html:string,body_text:string}
	 */
	public function render( string $event_type, string $template_ref, array $context ): array {
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
}

/**
 * Resolves the active render provider. fase36 overrides it with a filter.
 *
 * @since 1.39.0
 */
final class ANPA_Socios_Email_Render {

	/** Snapshot format version — bump when the frozen structure changes. */
	const PAYLOAD_VERSION = 1;

	/**
	 * Returns the provider to use.
	 *
	 * @since  1.39.0
	 * @return ANPA_Socios_Email_Render_Provider_Interface
	 */
	public static function provider(): ANPA_Socios_Email_Render_Provider_Interface {
		$provider = apply_filters( 'anpa_socios_email_render_provider', null );
		if ( $provider instanceof ANPA_Socios_Email_Render_Provider_Interface ) {
			return $provider;
		}
		return new ANPA_Socios_Email_Passthrough_Render_Provider();
	}

	/**
	 * Renders and FREEZES a message for one recipient.
	 *
	 * The returned snapshot is what will actually be sent, regardless of later
	 * template edits. `payload_hash` lets us prove afterwards that the message
	 * sent matches the message frozen, even once the body has been purged by the
	 * retention policy.
	 *
	 * @since  1.39.0
	 * @param  string              $event_type   Logical event.
	 * @param  string              $template_ref Template id or ''.
	 * @param  array<string,mixed> $context      Minimal per-recipient context.
	 * @return array{subject:string,snapshot:string,payload_hash:string,payload_version:int}
	 */
	public static function freeze( string $event_type, string $template_ref, array $context ): array {
		$rendered = self::provider()->render( $event_type, $template_ref, $context );

		$snapshot = array(
			'v'         => self::PAYLOAD_VERSION,
			'event'     => $event_type,
			'template'  => $template_ref,
			'subject'   => (string) ( $rendered['subject'] ?? '' ),
			'body_html' => (string) ( $rendered['body_html'] ?? '' ),
			'body_text' => (string) ( $rendered['body_text'] ?? '' ),
		);

		$json = (string) wp_json_encode( $snapshot );

		return array(
			'subject'         => $snapshot['subject'],
			'snapshot'        => $json,
			'payload_hash'    => hash( 'sha256', $json ),
			'payload_version' => self::PAYLOAD_VERSION,
		);
	}

	/**
	 * Reads a frozen snapshot back into subject/body. Returns null when the body
	 * has already been purged by the retention policy (metadata-only rows).
	 *
	 * @since  1.39.0
	 * @param  string|null $json Stored snapshot.
	 * @return array{subject:string,body_html:string,body_text:string}|null
	 */
	public static function thaw( ?string $json ): ?array {
		if ( null === $json || '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return array(
			'subject'   => (string) ( $data['subject'] ?? '' ),
			'body_html' => (string) ( $data['body_html'] ?? '' ),
			'body_text' => (string) ( $data['body_text'] ?? '' ),
		);
	}
}
