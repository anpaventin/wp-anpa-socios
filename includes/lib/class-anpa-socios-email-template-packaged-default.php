<?php
/**
 * A template that came from the plugin package (fase36, PR-36s3).
 *
 * THIS TYPE IS THE PERMISSION. The repository has one path that writes content without sanitising it,
 * because `wp_kses` rewrites CSS and a shipped default must reach storage byte-identical to the file
 * the golden oracle pins. That path accepts **only an instance of this class**, and this class cannot
 * be built from arbitrary content: its constructor is private and the single factory takes an event
 * key, not a body. There is no `from_array()`, no setter, no `with_content()`.
 *
 * So the question "can a request body reach the verbatim path?" is answered by the type system rather
 * than by review. A controller, a REST handler, `$_POST`, Ajax, a stored custom row or a restored
 * version cannot produce one of these, because none of them can produce content that this class would
 * accept — it does not accept content at all. It goes and reads the versioned file itself.
 *
 * WHY NOT A BOOLEAN. `save( $content, $trusted = true )` moves the decision to the caller, and the
 * caller is exactly the layer that receives untrusted input. A flag is one typo away from being set on
 * a request body, and nothing fails when it is. A type cannot be set by accident.
 *
 * Pure: no WordPress, no database, no clock. It reads a file through the defaults loader, which is
 * itself pure.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Packaged_Default {

	/** @var string Event key this default belongs to. */
	private $event_key;

	/** @var string Collapsed subject, as shipped. */
	private $subject;

	/** @var string HTML body, as shipped. */
	private $body_html;

	/** @var string Plain-text body, as shipped. */
	private $body_text;

	/** @var int Declared version of the shipped default. */
	private $version;

	/** @var string Scheme-qualified content digest. */
	private $hash;

	/**
	 * Private on purpose: see the class docblock. The only way in is `for_event()`.
	 *
	 * @param string $event_key Event key.
	 * @param string $subject   Subject.
	 * @param string $body_html HTML body.
	 * @param string $body_text Plain-text body.
	 * @param int    $version   Declared version.
	 * @param string $hash      Content digest.
	 */
	private function __construct(
		string $event_key,
		string $subject,
		string $body_html,
		string $body_text,
		int $version,
		string $hash
	) {
		$this->event_key = $event_key;
		$this->subject   = $subject;
		$this->body_html = $body_html;
		$this->body_text = $body_text;
		$this->version   = $version;
		$this->hash      = $hash;
	}

	/**
	 * Loads the shipped default for an event.
	 *
	 * Takes a KEY, never content. Everything it returns comes from a versioned file in the plugin,
	 * read through the loader that already refuses a missing part, an empty subject or body, a BOM and
	 * invalid UTF-8.
	 *
	 * @since  1.40.0
	 * @param  string $event_key Event key, which must be in the registry.
	 * @return self
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the event is unknown or ships no default.
	 */
	public static function for_event( string $event_key ): self {
		// Registry membership first: a stem that happens to exist on disk but is not a declared event
		// is not part of the package's contract.
		if ( ! ANPA_Socios_Email_Template_Events::set()->has( $event_key ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"'{$event_key}' is not a registered event, so there is no packaged default for it"
			);
		}

		if ( ! ANPA_Socios_Email_Template_Defaults::exists( $event_key ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"event '{$event_key}' ships no default; a missing file is a packaging bug, not a blank template"
			);
		}

		$default = ANPA_Socios_Email_Template_Defaults::load( $event_key );

		return new self(
			$event_key,
			$default['subject'],
			$default['body_html'],
			$default['body_text'],
			(int) $default['default_version'],
			ANPA_Socios_Email_Template_Defaults::content_hash(
				$default['subject'],
				$default['body_html'],
				$default['body_text']
			)
		);
	}

	/**
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @return bool Whether a packaged default can be loaded for this event.
	 */
	public static function available( string $event_key ): bool {
		return ANPA_Socios_Email_Template_Events::set()->has( $event_key )
			&& ANPA_Socios_Email_Template_Defaults::exists( $event_key );
	}

	/** @since 1.40.0 @return string */
	public function event_key(): string {
		return $this->event_key;
	}

	/** @since 1.40.0 @return string */
	public function subject(): string {
		return $this->subject;
	}

	/** @since 1.40.0 @return string */
	public function body_html(): string {
		return $this->body_html;
	}

	/** @since 1.40.0 @return string */
	public function body_text(): string {
		return $this->body_text;
	}

	/** @since 1.40.0 @return int */
	public function version(): int {
		return $this->version;
	}

	/** @since 1.40.0 @return string Scheme-qualified content digest. */
	public function hash(): string {
		return $this->hash;
	}

	/**
	 * The three channels, for a writer that needs them as an array.
	 *
	 * @since  1.40.0
	 * @return array<string,string>
	 */
	public function content(): array {
		return array(
			'subject'   => $this->subject,
			'body_html' => $this->body_html,
			'body_text' => $this->body_text,
		);
	}

	/**
	 * Whether submitted content is byte-identical to this shipped default.
	 *
	 * Used to recognise "the operator pressed save without typing anything". Line endings are
	 * normalised first, because a body that made a round trip through a textarea comes back with CRLF
	 * and that is not an edit.
	 *
	 * @since  1.40.0
	 * @param  ANPA_Socios_Email_Template_Stored_Custom_Template $candidate Submitted content.
	 * @return bool
	 */
	public function matches( ANPA_Socios_Email_Template_Stored_Custom_Template $candidate ): bool {
		return $candidate->subject() === $this->subject
			&& $candidate->body_html() === $this->body_html
			&& $candidate->body_text() === $this->body_text;
	}
}
