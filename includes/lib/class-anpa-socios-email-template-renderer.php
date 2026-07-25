<?php
/**
 * Pure template renderer for the email templates (fase36, PR-36s1).
 *
 * Substitutes `{{token}}` and resolves `{{#token}}...{{/}}` optional blocks. No
 * WordPress, no database, no clock, no randomness: the same template and context
 * always produce byte-identical output, which is what makes the frozen payload of
 * fase35 verifiable.
 *
 * Deliberately minimal syntax. No expressions, no loops, no filters, no nesting.
 * The people maintaining these templates are a volunteer board editing text in a
 * textarea, not developers, and every extra construct is a way to break a
 * notification to families.
 *
 * Escaping is per channel and is NOT the template author's responsibility:
 *   - HTML body: values are escaped, so a value can never inject markup.
 *   - Text body: values are inserted raw, and no tags are ever added.
 *
 * An undeclared token is an ERROR, never output. A template that reaches a family
 * with a literal `{{nome_socio}}` in it is worse than one that refuses to render.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Renderer {

	/** Channel identifiers. */
	const CHANNEL_HTML = 'html';
	const CHANNEL_TEXT = 'text';

	/** Result codes. */
	const OK                   = 'ok';
	const ERR_UNDECLARED       = 'undeclared_variable';
	const ERR_EMPTY_TEMPLATE   = 'empty_template';
	const ERR_UNCLOSED_BLOCK   = 'unclosed_block';

	/**
	 * Renders a template.
	 *
	 * @since  1.40.0
	 * @param  array<string,string>              $template Keys: subject, body_html, body_text.
	 * @param  array<string,string>              $context  Token => value.
	 * @param  array<string,array<string,mixed>> $declared Declared tokens for this event
	 *                                                     (token => descriptor). Only these
	 *                                                     may appear in the template.
	 * @param  array<string,string>              $aliases  Alternative spelling => canonical token.
	 * @return array{ok:bool,code:string,subject:string,body_html:string,body_text:string,undeclared:string[]}
	 */
	public static function render( array $template, array $context, array $declared, array $aliases = array() ): array {
		$fail = array(
			'ok'         => false,
			'code'       => '',
			'subject'    => '',
			'body_html'  => '',
			'body_text'  => '',
			'undeclared' => array(),
		);

		$subject = (string) ( $template['subject'] ?? '' );
		$html    = (string) ( $template['body_html'] ?? '' );
		$text    = (string) ( $template['body_text'] ?? '' );

		if ( '' === trim( $subject ) || ( '' === trim( $html ) && '' === trim( $text ) ) ) {
			$fail['code'] = self::ERR_EMPTY_TEMPLATE;
			return $fail;
		}

		// Every token used anywhere in the template must be declared for this event.
		$undeclared = self::undeclared_tokens( $subject . "\n" . $html . "\n" . $text, $declared, $aliases );
		if ( array() !== $undeclared ) {
			$fail['code']       = self::ERR_UNDECLARED;
			$fail['undeclared'] = $undeclared;
			return $fail;
		}

		$values = self::canonicalise_context( $context, $aliases );

		// The subject is a single line and carries no markup, so it uses the text rules.
		$rendered_subject = self::render_one( $subject, $values, $aliases, self::CHANNEL_TEXT );
		$rendered_html    = '' === trim( $html ) ? '' : self::render_one( $html, $values, $aliases, self::CHANNEL_HTML );
		$rendered_text    = '' === trim( $text ) ? '' : self::render_one( $text, $values, $aliases, self::CHANNEL_TEXT );

		if ( null === $rendered_subject || null === $rendered_html || null === $rendered_text ) {
			$fail['code'] = self::ERR_UNCLOSED_BLOCK;
			return $fail;
		}

		return array(
			'ok'         => true,
			'code'       => self::OK,
			'subject'    => self::collapse_subject( $rendered_subject ),
			'body_html'  => $rendered_html,
			'body_text'  => $rendered_text,
			'undeclared' => array(),
		);
	}

	/**
	 * Tokens used by a template that are not declared for its event.
	 *
	 * @since  1.40.0
	 * @param  string                            $source   Template text.
	 * @param  array<string,array<string,mixed>> $declared Declared tokens.
	 * @param  array<string,string>              $aliases  Alias => canonical.
	 * @return string[] Undeclared tokens, in order of appearance, without duplicates.
	 */
	public static function undeclared_tokens( string $source, array $declared, array $aliases = array() ): array {
		$found = array();
		foreach ( self::tokens_in( $source ) as $token ) {
			$canonical = $aliases[ $token ] ?? $token;
			if ( ! isset( $declared[ $canonical ] ) && ! in_array( $canonical, $found, true ) ) {
				$found[] = $canonical;
			}
		}

		return $found;
	}

	/**
	 * Every token referenced by a template, substitutions and block markers alike.
	 *
	 * @since  1.40.0
	 * @param  string $source Template text.
	 * @return string[]
	 */
	public static function tokens_in( string $source ): array {
		$tokens = array();

		// Block openers: {{#token}}. Closers are anonymous ({{/}}) or repeat the token.
		if ( preg_match_all( '/\{\{\s*[#\/]\s*([a-zA-Z0-9_\x{00C0}-\x{024F}]+)\s*\}\}/u', $source, $blocks ) ) {
			foreach ( $blocks[1] as $token ) {
				$tokens[] = $token;
			}
		}
		// Plain substitutions: {{token}}, excluding the block syntax above.
		if ( preg_match_all( '/\{\{\s*([a-zA-Z0-9_\x{00C0}-\x{024F}]+)\s*\}\}/u', $source, $plain ) ) {
			foreach ( $plain[1] as $token ) {
				$tokens[] = $token;
			}
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Renders one channel: optional blocks first, then substitutions.
	 *
	 * @since  1.40.0
	 * @param  string                $source  Template text.
	 * @param  array<string,string>  $values  Canonical token => value.
	 * @param  array<string,string>  $aliases Alias => canonical.
	 * @param  string                $channel CHANNEL_HTML or CHANNEL_TEXT.
	 * @return string|null Null when a block is left unclosed.
	 */
	private static function render_one( string $source, array $values, array $aliases, string $channel ): ?string {
		$resolved = self::resolve_blocks( $source, $values, $aliases );
		if ( null === $resolved ) {
			return null;
		}

		return self::substitute( $resolved, $values, $aliases, $channel );
	}

	/**
	 * Resolves `{{#token}}...{{/}}` blocks: kept when the value is non-empty, removed
	 * entirely otherwise. Removing the whole block is the point — a heading or a
	 * label left behind without its value reads like a bug to the family.
	 *
	 * @since  1.40.0
	 * @param  string               $source  Template text.
	 * @param  array<string,string> $values  Canonical token => value.
	 * @param  array<string,string> $aliases Alias => canonical.
	 * @return string|null Null when a block is left unclosed.
	 */
	private static function resolve_blocks( string $source, array $values, array $aliases ): ?string {
		$pattern = '/\{\{\s*#\s*([a-zA-Z0-9_\x{00C0}-\x{024F}]+)\s*\}\}(.*?)\{\{\s*\/\s*(?:\1)?\s*\}\}/us';

		$guard = 0;
		while ( preg_match( $pattern, $source ) ) {
			$source = (string) preg_replace_callback(
				$pattern,
				static function ( array $m ) use ( $values, $aliases ): string {
					$canonical = $aliases[ $m[1] ] ?? $m[1];
					$value     = (string) ( $values[ $canonical ] ?? '' );

					return '' === trim( $value ) ? '' : $m[2];
				},
				$source
			);
			if ( ++$guard > 50 ) {
				break; // Pathological nesting; treat as rendered rather than loop forever.
			}
		}

		// An opener with no closer would otherwise be silently substituted as text.
		if ( preg_match( '/\{\{\s*#/u', $source ) ) {
			return null;
		}

		return $source;
	}

	/**
	 * Replaces `{{token}}` with its escaped value for the channel. A declared token
	 * with no value renders as an empty string (never as a literal token).
	 *
	 * @since  1.40.0
	 * @param  string               $source  Template text.
	 * @param  array<string,string> $values  Canonical token => value.
	 * @param  array<string,string> $aliases Alias => canonical.
	 * @param  string               $channel CHANNEL_HTML or CHANNEL_TEXT.
	 * @return string
	 */
	private static function substitute( string $source, array $values, array $aliases, string $channel ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_\x{00C0}-\x{024F}]+)\s*\}\}/u',
			static function ( array $m ) use ( $values, $aliases, $channel ): string {
				$canonical = $aliases[ $m[1] ] ?? $m[1];
				$value     = (string) ( $values[ $canonical ] ?? '' );

				return self::CHANNEL_HTML === $channel ? self::escape_html( $value ) : $value;
			},
			$source
		);
	}

	/**
	 * Context keyed by canonical token, so a caller may supply either spelling.
	 *
	 * @since  1.40.0
	 * @param  array<string,string> $context Raw context.
	 * @param  array<string,string> $aliases Alias => canonical.
	 * @return array<string,string>
	 */
	private static function canonicalise_context( array $context, array $aliases ): array {
		$values = array();
		foreach ( $context as $token => $value ) {
			$canonical            = $aliases[ (string) $token ] ?? (string) $token;
			$values[ $canonical ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $values;
	}

	/**
	 * Escapes a value for the HTML body. Equivalent to esc_html() but WordPress-free,
	 * because this class must stay unit-testable without WordPress loaded.
	 *
	 * @since  1.40.0
	 * @param  string $value Raw value.
	 * @return string
	 */
	public static function escape_html( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Collapses a subject to a single line. This is header-injection prevention, not
	 * cosmetics: a newline in a subject can forge headers.
	 *
	 * @since  1.40.0
	 * @param  string $subject Rendered subject.
	 * @return string
	 */
	public static function collapse_subject( string $subject ): string {
		$subject = (string) preg_replace( '/\s+/u', ' ', $subject );

		return trim( $subject );
	}
}
