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
 * WHAT IT DOES NOT PROMISE, learned from a real engine rather than assumed: sanitising a shipped
 * default does NOT return identical bytes. `wp_kses` rewrites `style` attributes — it drops the
 * trailing semicolon and the spaces after separators — so `margin: 0 auto; padding: 20px;` comes back
 * as `margin: 0 auto;padding: 20px`. Equivalent CSS, different bytes.
 *
 * That is why the boundary is TRUST, not content: the repository stores a shipped default verbatim,
 * because it is the text that ships with the plugin and is reviewed in git, and it sanitises what an
 * operator submits, because that arrives from a request. The ten live templates stay pinned
 * byte-exact against the golden oracle, which is the only evidence that moving to templates changed
 * nothing for families, and no editor round trip can silently move them off that pin.
 *
 * What IS promised, and asserted against a real engine: sanitisation is idempotent, it removes every
 * construct the policy forbids, and it never eats the renderer's block markers.
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

		// NOT `wp_strip_all_tags()`: it trims, and a trailing newline in a written plain-text body is
		// content, not slack. The two steps are the ones that function performs before trimming —
		// remove any script/style block whole, then remove the remaining tags — so a `<script>` never
		// leaves its contents behind as visible text.
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = strip_tags( $text );

		return self::strip_control_characters( $text, true );
	}

	/**
	 * Runs a callback with the template CSS allowlist in force.
	 *
	 * WordPress filters `style` attributes through its own CSS property allowlist. That list is
	 * REPLACED here, not extended: it is broader than an email needs and it permits
	 * `background-image`, which turns a style attribute into a remote fetch — read-tracking by
	 * accident. Merging would have made the policy's list a floor with no ceiling, so a property the
	 * project deliberately excluded would still be storable because WordPress happens to allow it.
	 *
	 * The replacement lasts for the duration of the call and is undone in a `finally`: a failed save
	 * must not leave the site's CSS policy altered for every other consumer.
	 *
	 * @param  callable $callback Operation to run.
	 * @return string
	 */
	private static function with_css_policy( callable $callback ): string {
		$filter = static function ( $properties ) {
			unset( $properties );

			return ANPA_Socios_Email_Template_Html_Policy::allowed_css_properties();
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
