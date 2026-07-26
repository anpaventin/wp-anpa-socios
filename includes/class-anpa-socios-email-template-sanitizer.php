<?php
/**
 * Sanitisation on save for email templates (fase36, PR-36s2).
 *
 * Glue, not domain: it needs `wp_kses`. The POLICY it enforces is pure and lives in
 * `ANPA_Socios_Email_Template_Html_Policy`, so "what may an operator store?" can be read and tested
 * without WordPress.
 *
 * Runs on WRITE, never on read. Sanitising on read would mean the database holds content nobody has
 * checked and every consumer has to remember to clean it; sanitising on write means a stored row is
 * already safe, and the renderer — which is frozen — needs to know nothing about this.
 *
 * The one property that is easy to overlook and expensive to lose: **sanitising a shipped default
 * must return it byte-identically.** The ten live templates are pinned byte-exact against the golden
 * oracle, which is the only evidence that moving to templates changed nothing for families. If this
 * class reformatted a default, that evidence would quietly stop being true at the storage layer.
 * An integration test asserts the round trip for all 35 shipped defaults against a real engine.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Template_Sanitizer {

	/**
	 * Cleans a subject line.
	 *
	 * Header injection is the reason this exists: a newline in a subject can forge headers, so the
	 * subject is collapsed to a single line rather than merely checked. The collapsing rule comes
	 * from the frozen renderer, which already had to solve it — the same rule in two places is the
	 * same rule until somebody edits one of them.
	 *
	 * Length is NOT truncated here. A subject silently cut at 255 characters is a subject nobody
	 * proof-read; the repository refuses it instead, loudly.
	 *
	 * @since  1.40.0
	 * @param  string $subject Submitted subject.
	 * @return string Single-line subject.
	 */
	public static function sanitize_subject( string $subject ): string {
		// Collapse FIRST, strip after. The other order would turn "Alta\naprobada" into
		// "Altaaprobada" instead of "Alta aprobada": a newline in a subject is a separator that got
		// there by accident, not a character to delete.
		$subject = ANPA_Socios_Email_Template_Renderer::collapse_subject( $subject );

		return trim( self::strip_control_characters( $subject, false ) );
	}

	/**
	 * Cleans an HTML body against the allowlist.
	 *
	 * The doctype is detached first: it is not a tag, `wp_kses` removes it, and the stored body is a
	 * complete email document. Exactly one doctype string is recognised and it is restored
	 * verbatim — this is not a parser for arbitrary `<!...>` constructs, which is what must not be
	 * storable.
	 *
	 * @since  1.40.0
	 * @param  string $html Submitted body.
	 * @return string Sanitised body.
	 */
	public static function sanitize_html( string $html ): string {
		$html  = self::normalise_line_endings( $html );
		$html  = self::strip_control_characters( $html, true );
		$parts = ANPA_Socios_Email_Template_Html_Policy::split_doctype( $html );

		$clean = self::with_css_policy(
			static function () use ( $parts ) {
				return wp_kses(
					$parts['rest'],
					ANPA_Socios_Email_Template_Html_Policy::allowed_tags(),
					ANPA_Socios_Email_Template_Html_Policy::allowed_protocols()
				);
			}
		);

		return ANPA_Socios_Email_Template_Html_Policy::join_doctype( $parts['doctype'], $clean );
	}

	/**
	 * Cleans a plain-text body.
	 *
	 * Tags are stripped rather than escaped: a `<p>` pasted into the text channel is a mistake, and
	 * `&lt;p&gt;` reaching a family is a worse outcome than losing the tag. Line structure is
	 * preserved, because the plain-text channel is written as its own channel and its paragraphs
	 * carry meaning.
	 *
	 * @since  1.40.0
	 * @param  string $text Submitted body.
	 * @return string Sanitised body.
	 */
	public static function sanitize_text( string $text ): string {
		$text = self::normalise_line_endings( $text );
		$text = wp_strip_all_tags( $text, false );

		return self::strip_control_characters( $text, true );
	}

	/**
	 * Runs a callback with the template CSS allowlist in force.
	 *
	 * WordPress filters `style` attributes through its own CSS property allowlist, which does not
	 * cover everything these emails already use. Rather than allow arbitrary CSS, the policy's list
	 * is added for the duration of the call and removed afterwards — including when the callback
	 * throws, or a failed save would leave the site's CSS policy widened for every other consumer.
	 *
	 * @param  callable $callback Operation to run.
	 * @return string
	 */
	private static function with_css_policy( callable $callback ): string {
		$filter = static function ( $properties ) {
			return array_values( array_unique( array_merge(
				(array) $properties,
				ANPA_Socios_Email_Template_Html_Policy::allowed_css_properties()
			) ) );
		};

		add_filter( 'safe_style_css', $filter, 10, 1 );

		try {
			return (string) $callback();
		} finally {
			remove_filter( 'safe_style_css', $filter, 10 );
		}
	}

	/**
	 * CRLF and CR become LF, so a body edited on Windows and one edited on Linux are the same body.
	 *
	 * Without this, "has this template been modified?" answers yes because somebody opened it in a
	 * different editor, and the content hash stops meaning anything.
	 *
	 * @param  string $value Raw value.
	 * @return string
	 */
	private static function normalise_line_endings( string $value ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}

	/**
	 * Removes control characters that have no business in an email.
	 *
	 * A NUL byte can truncate a value in code that reaches C, and a stray control byte in a header
	 * is a header-injection vector. Tabs and newlines survive in bodies because they are content;
	 * in a subject they do not, because a subject is one line by definition.
	 *
	 * @param  string $value       Raw value.
	 * @param  bool   $keep_breaks Whether newlines and tabs are content here.
	 * @return string
	 */
	private static function strip_control_characters( string $value, bool $keep_breaks ): string {
		$pattern = $keep_breaks ? '/[^\P{C}\n\t]/u' : '/\p{C}/u';
		$clean   = preg_replace( $pattern, '', $value );

		// A malformed UTF-8 sequence makes preg_replace return null. Refusing to guess is safer
		// than storing whatever the byte string happened to be.
		return null === $clean ? '' : $clean;
	}
}
