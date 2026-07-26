<?php
/**
 * Template content whose provenance is NOT the plugin package (fase36, PR-36s3).
 *
 * The complement of `ANPA_Socios_Email_Template_Packaged_Default`. Anything that came from a request,
 * from a customised row, or from an archived version is one of these — and everything that is one of
 * these gets sanitised before it is stored. There is no path that writes an instance of this class
 * without sanitising it.
 *
 * The factories are named after where the content came from rather than what should be done with it:
 * `from_request()`, `from_stored_row()`, `from_archived_version()`. That is the whole design. A caller
 * has to state a provenance it can actually justify, and none of the available provenances is
 * "trusted". Compare with a `$trusted` boolean, which the layer receiving untrusted input is the one
 * setting.
 *
 * Line endings are normalised on construction, because a body that made a round trip through a
 * textarea comes back with CRLF and that is not an edit. Nothing else is changed here: sanitisation
 * needs `wp_kses` and therefore belongs to the glue, not to a pure value object.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Stored_Custom_Template {

	/** Content arrived in an HTTP request (editor form, REST, Ajax). */
	const ORIGIN_REQUEST = 'request';

	/** Content was read back from the live template row. */
	const ORIGIN_STORED_ROW = 'stored_row';

	/** Content was read back from the version history. */
	const ORIGIN_ARCHIVED_VERSION = 'archived_version';

	/** @var string One of the ORIGIN_* constants. */
	private $origin;

	/** @var string */
	private $subject;

	/** @var string */
	private $body_html;

	/** @var string */
	private $body_text;

	/**
	 * @param string $origin    Declared provenance.
	 * @param string $subject   Subject.
	 * @param string $body_html HTML body.
	 * @param string $body_text Plain-text body.
	 */
	private function __construct( string $origin, string $subject, string $body_html, string $body_text ) {
		$this->origin    = $origin;
		$this->subject   = $subject;
		$this->body_html = self::normalise( $body_html );
		$this->body_text = self::normalise( $body_text );
	}

	/**
	 * Content submitted through an HTTP request.
	 *
	 * @since  1.40.0
	 * @param  array<string,mixed> $content Keys: subject, body_html, body_text.
	 * @return self
	 */
	public static function from_request( array $content ): self {
		return self::from( self::ORIGIN_REQUEST, $content );
	}

	/**
	 * Content read back from the live row.
	 *
	 * Still untrusted, and deliberately so: what is in the row today may have been written before a
	 * sanitisation rule existed. Treating stored content as safe because it is stored is how an old
	 * hole survives every future hardening.
	 *
	 * @since  1.40.0
	 * @param  array<string,mixed> $row Row from the templates table.
	 * @return self
	 */
	public static function from_stored_row( array $row ): self {
		return self::from( self::ORIGIN_STORED_ROW, $row );
	}

	/**
	 * Content read back from the version history.
	 *
	 * @since  1.40.0
	 * @param  array<string,mixed> $row Row from the versions table.
	 * @return self
	 */
	public static function from_archived_version( array $row ): self {
		return self::from( self::ORIGIN_ARCHIVED_VERSION, $row );
	}

	/**
	 * @param  string              $origin  Provenance.
	 * @param  array<string,mixed> $content Raw values.
	 * @return self
	 */
	private static function from( string $origin, array $content ): self {
		return new self(
			$origin,
			(string) ( $content['subject'] ?? '' ),
			(string) ( $content['body_html'] ?? '' ),
			(string) ( $content['body_text'] ?? '' )
		);
	}

	/** @since 1.40.0 @return string One of the ORIGIN_* constants. */
	public function origin(): string {
		return $this->origin;
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

	/**
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
	 * @param  string $value Raw value.
	 * @return string
	 */
	private static function normalise( string $value ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}
}
