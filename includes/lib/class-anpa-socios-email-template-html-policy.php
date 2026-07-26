<?php
/**
 * What an email template is allowed to contain (fase36, PR-36s2).
 *
 * The sanitiser needs `wp_kses`, so it is glue. The POLICY it enforces does not, so it lives here
 * and is testable without WordPress and without a database. That split matters: "which tags may an
 * operator store in an email?" is a decision worth reading and asserting on its own, not a constant
 * buried inside a function that also talks to the database.
 *
 * The policy is an ALLOWLIST. A blocklist of dangerous tags is a list of the attacks somebody
 * thought of; an allowlist is a list of what the product actually needs.
 *
 * TWO CONSTRAINTS PULL AGAINST EACH OTHER, and both are non-negotiable:
 *
 *   1. Nothing dangerous may be storable — no `script`, no `iframe`, no event handler, no
 *      `javascript:` URL, no `style` *element*.
 *   2. Sanitising a SHIPPED DEFAULT must return it byte-identically. The ten live templates are
 *      pinned byte-exact against the golden oracle, which is the only proof that migrating to
 *      templates changed nothing for families. A sanitiser that quietly reformatted a default
 *      would break that proof at the storage layer, where nobody is looking.
 *
 * Constraint 2 is why the document-level tags are allowed at all: the stored body is a COMPLETE
 * email document, not a fragment. That is what the historical emails were, and reproducing them is
 * the point of fase36. The wrapper becomes a shared partial in 36s4, deliberately, as a step with
 * its own golden migration.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Html_Policy {

	/**
	 * The ONE legal document prefix.
	 *
	 * A doctype is not a tag and `wp_kses` removes it, so it is detached before sanitising and
	 * re-attached afterwards. Exactly one string is accepted: this is not a parser for arbitrary
	 * `<!...>` constructs, which is precisely what must not be storable.
	 */
	const DOCTYPE = '<!DOCTYPE html>';

	/**
	 * Tags an operator may store, with their permitted attributes.
	 *
	 * DELIBERATE OMISSIONS, each for a reason rather than for tidiness:
	 *
	 *   - `img`: an image in a transactional email is a privacy question (remote loads are
	 *     read-tracking) and a deliverability question, not a formatting one. If the association
	 *     ever wants a logo it is a product decision with a configuration behind it, not something
	 *     an operator pastes into a body at 23:00.
	 *   - `style` element and `link`: a stylesheet that applies to a whole document is how one edit
	 *     silently changes every paragraph. Inline `style` attributes are allowed instead, filtered
	 *     property by property.
	 *   - `table`: real email layout tables belong to the 36s4 wrapper, not to a body an operator
	 *     edits by hand.
	 *   - `script`, `iframe`, `object`, `embed`, `form`, `input`: never.
	 *
	 * `target` and `rel` are allowed on links because an email client opening a link in place is a
	 * worse experience than an extra attribute.
	 *
	 * @since  1.40.0
	 * @return array<string,array<string,bool>> kses-shaped allowlist; a fresh copy per call.
	 */
	public static function allowed_tags(): array {
		return array(
			// Document level. Present because the stored body is a whole email document.
			'html'       => array( 'lang' => true ),
			'head'       => array(),
			'meta'       => array( 'charset' => true, 'name' => true, 'content' => true ),
			'title'      => array(),
			'body'       => array( 'style' => true ),

			// Structure.
			'div'        => array( 'style' => true ),
			'p'          => array( 'style' => true ),
			'br'         => array(),
			'hr'         => array( 'style' => true ),
			'h2'         => array( 'style' => true ),
			'h3'         => array( 'style' => true ),
			'h4'         => array( 'style' => true ),
			'ul'         => array( 'style' => true ),
			'ol'         => array( 'style' => true ),
			'li'         => array( 'style' => true ),
			'blockquote' => array( 'style' => true ),

			// Inline.
			'a'          => array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true, 'style' => true ),
			'strong'     => array( 'style' => true ),
			'b'          => array( 'style' => true ),
			'em'         => array( 'style' => true ),
			'i'          => array( 'style' => true ),
			'span'       => array( 'style' => true ),
			'small'      => array( 'style' => true ),
		);
	}

	/**
	 * @since  1.40.0
	 * @return string[] Tag names, sorted, for readable assertions and admin help text.
	 */
	public static function allowed_tag_names(): array {
		$names = array_keys( self::allowed_tags() );
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * URL schemes a stored link may use.
	 *
	 * `mailto` is here because the contact address is a legitimate link in these emails. `data:`
	 * and `javascript:` are absent, which is the entire point.
	 *
	 * @since  1.40.0
	 * @return string[]
	 */
	public static function allowed_protocols(): array {
		return array( 'https', 'mailto' );
	}

	/**
	 * CSS properties a stored `style` attribute may use.
	 *
	 * WordPress filters style attributes through its own CSS allowlist, which does not cover every
	 * property these emails already use; the sanitiser widens it with exactly this list and no
	 * more. A unit test derives the properties actually present in the shipped defaults and fails
	 * if any of them is missing here, so the list cannot drift away from the templates it exists
	 * to permit.
	 *
	 * Nothing positional (`position`, `top`, `z-index`) and nothing that can load a resource
	 * (`background-image`, `behavior`, `-moz-binding`): a style attribute must be able to change
	 * how text looks, not what the client fetches.
	 *
	 * @since  1.40.0
	 * @return string[]
	 */
	public static function allowed_css_properties(): array {
		return array(
			'background',
			'background-color',
			'border',
			'border-bottom',
			'border-radius',
			'border-top',
			'color',
			'font-family',
			'font-size',
			'font-style',
			'font-weight',
			'letter-spacing',
			'line-height',
			'margin',
			'margin-bottom',
			'margin-left',
			'margin-right',
			'margin-top',
			'max-width',
			'padding',
			'padding-bottom',
			'padding-left',
			'padding-right',
			'padding-top',
			'text-align',
			'text-decoration',
			'white-space',
		);
	}

	/**
	 * Detaches the document prefix so the rest can go through the tag allowlist.
	 *
	 * Returns the doctype separately rather than keeping it inline, because the sanitiser must be
	 * able to say "this prefix is a known constant I will restore verbatim" instead of "this
	 * `<!...>` thing looked harmless".
	 *
	 * @since  1.40.0
	 * @param  string $html Stored or submitted body.
	 * @return array{doctype:string,rest:string} `doctype` is either the legal constant or empty.
	 */
	public static function split_doctype( string $html ): array {
		$trimmed = ltrim( $html );

		if ( 0 === stripos( $trimmed, self::DOCTYPE ) ) {
			return array(
				'doctype' => self::DOCTYPE,
				'rest'    => substr( $trimmed, strlen( self::DOCTYPE ) ),
			);
		}

		return array( 'doctype' => '', 'rest' => $html );
	}

	/**
	 * Reassembles a document after sanitisation.
	 *
	 * @since  1.40.0
	 * @param  string $doctype Result of `split_doctype()`, already validated.
	 * @param  string $rest    Sanitised remainder.
	 * @return string
	 */
	public static function join_doctype( string $doctype, string $rest ): string {
		return self::DOCTYPE === $doctype ? self::DOCTYPE . $rest : $rest;
	}

	/**
	 * Constructs that must never survive sanitisation, for use as executable assertions.
	 *
	 * Declared here so the negative controls are a list somebody can read and extend, rather than
	 * a handful of ad-hoc strings scattered through a test file.
	 *
	 * @since  1.40.0
	 * @return array<string,string> Label => payload.
	 */
	public static function forbidden_examples(): array {
		return array(
			'script element'     => '<script>alert(1)</script>',
			'iframe'             => '<iframe src="https://example.org/"></iframe>',
			'object'             => '<object data="https://example.org/x.swf"></object>',
			'inline handler'     => '<p onclick="alert(1)">texto</p>',
			'error handler'      => '<p onerror="alert(1)">texto</p>',
			'javascript url'     => '<a href="javascript:alert(1)">ligazón</a>',
			'data url'           => '<a href="data:text/html;base64,PHNjcmlwdD4=">ligazón</a>',
			'style element'      => '<style>p{display:none}</style>',
			'stylesheet link'    => '<link rel="stylesheet" href="https://example.org/a.css">',
			'form'               => '<form action="https://example.org/"><input name="x"></form>',
			'remote image'       => '<img src="https://example.org/pixel.gif">',
			'foreign doctype'    => '<!DOCTYPE html SYSTEM "about:legacy-compat">',
			'processing instr'   => '<?php echo 1; ?>',
			'css resource load'  => '<p style="background-image:url(https://example.org/p.gif)">t</p>',
		);
	}
}
