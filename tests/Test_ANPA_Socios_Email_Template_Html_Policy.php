<?php
/**
 * Tests for what an email template is allowed to contain (fase36, PR-36s2).
 *
 * Two constraints pull against each other and both are asserted here: nothing dangerous may be
 * storable, and sanitising a shipped default must not change it. The second is the one that fails
 * silently, so it is tested against the real template files rather than against a fixture — the
 * policy is derived from what the 35 shipped defaults actually use, and drifting apart is a test
 * failure rather than a surprise in production.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Html_Policy extends TestCase {

	/** @return string[] Absolute paths of every shipped HTML default. */
	private function shipped_html_files(): array {
		$files = glob( dirname( __DIR__ ) . '/templates/*.html' );

		$this->assertIsArray( $files );
		$this->assertNotSame( array(), $files, 'no shipped HTML defaults found; the derivation below would prove nothing' );

		return $files;
	}

	// ── The allowlist covers what the shipped defaults need ──────────────

	public function test_every_tag_used_by_a_shipped_default_is_allowed(): void {
		$allowed = ANPA_Socios_Email_Template_Html_Policy::allowed_tag_names();

		foreach ( $this->shipped_html_files() as $file ) {
			$html = (string) file_get_contents( $file );
			$name = basename( $file );

			preg_match_all( '#</?([a-zA-Z][a-zA-Z0-9]*)#', $html, $matches );

			foreach ( array_unique( array_map( 'strtolower', $matches[1] ) ) as $tag ) {
				$this->assertContains( $tag, $allowed, "{$name}: uses <{$tag}>, which the policy forbids" );
			}
		}
	}

	public function test_every_attribute_used_by_a_shipped_default_is_allowed_on_its_tag(): void {
		$tags = ANPA_Socios_Email_Template_Html_Policy::allowed_tags();

		foreach ( $this->shipped_html_files() as $file ) {
			$html = (string) file_get_contents( $file );
			$name = basename( $file );

			preg_match_all( '#<([a-zA-Z][a-zA-Z0-9]*)([^>]*)>#', $html, $elements, PREG_SET_ORDER );

			foreach ( $elements as $element ) {
				$tag = strtolower( $element[1] );
				preg_match_all( '#\s([a-zA-Z-]+)\s*=#', $element[2], $attributes );

				foreach ( array_unique( array_map( 'strtolower', $attributes[1] ) ) as $attribute ) {
					$this->assertArrayHasKey(
						$attribute,
						$tags[ $tag ] ?? array(),
						"{$name}: <{$tag}> uses {$attribute}=, which the policy forbids on that tag"
					);
				}
			}
		}
	}

	public function test_every_css_property_used_by_a_shipped_default_is_allowed(): void {
		// The interesting direction. WordPress filters style attributes through its own CSS
		// allowlist, which does not cover everything these emails already use; if the policy fails
		// to widen it for a property a default relies on, the sanitiser silently drops formatting.
		$allowed = ANPA_Socios_Email_Template_Html_Policy::allowed_css_properties();

		foreach ( $this->shipped_html_files() as $file ) {
			$html = (string) file_get_contents( $file );
			$name = basename( $file );

			preg_match_all( '#style="([^"]*)"#', $html, $styles );

			foreach ( $styles[1] as $declarations ) {
				foreach ( explode( ';', $declarations ) as $declaration ) {
					if ( false === strpos( $declaration, ':' ) ) {
						continue;
					}

					$property = strtolower( trim( explode( ':', $declaration, 2 )[0] ) );
					$this->assertContains( $property, $allowed, "{$name}: uses CSS '{$property}', which the policy forbids" );
				}
			}
		}
	}

	public function test_every_shipped_default_declares_the_one_legal_doctype(): void {
		foreach ( $this->shipped_html_files() as $file ) {
			$parts = ANPA_Socios_Email_Template_Html_Policy::split_doctype( (string) file_get_contents( $file ) );

			$this->assertSame(
				ANPA_Socios_Email_Template_Html_Policy::DOCTYPE,
				$parts['doctype'],
				basename( $file ) . ': the document prefix is not the recognised constant'
			);
		}
	}

	// ── The allowlist forbids what it must ──────────────────────────────

	public function test_the_dangerous_tags_are_absent_from_the_allowlist(): void {
		$allowed = ANPA_Socios_Email_Template_Html_Policy::allowed_tag_names();

		foreach ( array( 'script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'style', 'link', 'base', 'svg' ) as $tag ) {
			$this->assertNotContains( $tag, $allowed, "<{$tag}> must never be storable" );
		}
	}

	public function test_images_are_excluded_deliberately(): void {
		// Not an omission: a remote image in a transactional email is read-tracking, which is a
		// product and privacy decision with configuration behind it, not something an operator
		// pastes into a body.
		$this->assertNotContains( 'img', ANPA_Socios_Email_Template_Html_Policy::allowed_tag_names() );
	}

	public function test_no_event_handler_attribute_is_allowed_on_any_tag(): void {
		foreach ( ANPA_Socios_Email_Template_Html_Policy::allowed_tags() as $tag => $attributes ) {
			foreach ( array_keys( $attributes ) as $attribute ) {
				$this->assertStringStartsNotWith( 'on', (string) $attribute, "<{$tag}> allows {$attribute}" );
			}
		}
	}

	public function test_only_safe_protocols_are_allowed(): void {
		$protocols = ANPA_Socios_Email_Template_Html_Policy::allowed_protocols();

		$this->assertContains( 'https', $protocols );
		$this->assertNotContains( 'http', $protocols, 'a family clicking a plain http link is a downgrade nobody chose' );
		$this->assertNotContains( 'javascript', $protocols );
		$this->assertNotContains( 'data', $protocols );
	}

	public function test_no_css_property_can_load_a_resource_or_position_content(): void {
		foreach ( ANPA_Socios_Email_Template_Html_Policy::allowed_css_properties() as $property ) {
			$this->assertStringNotContainsString( 'image', $property, "{$property} can fetch a resource" );
			$this->assertStringNotContainsString( 'behavior', $property );
			$this->assertStringNotContainsString( 'binding', $property );
			$this->assertNotContains( $property, array( 'position', 'top', 'left', 'right', 'bottom', 'z-index' ) );
		}
	}

	// ── The doctype seam ────────────────────────────────────────────────

	public function test_a_foreign_doctype_is_not_recognised_as_the_prefix(): void {
		// The seam exists so the sanitiser can say "this is a known constant I will restore", not
		// "this <!...> thing looked harmless".
		$parts = ANPA_Socios_Email_Template_Html_Policy::split_doctype(
			'<!DOCTYPE html SYSTEM "about:legacy-compat"><html><body><p>t</p></body></html>'
		);

		$this->assertSame( '', $parts['doctype'] );
		$this->assertStringContainsString( 'legacy-compat', $parts['rest'], 'the unrecognised prefix stays in the content, where kses will remove it' );
	}

	public function test_a_body_without_a_doctype_round_trips_unchanged(): void {
		$fragment = '<p>Ola</p>';
		$parts    = ANPA_Socios_Email_Template_Html_Policy::split_doctype( $fragment );

		$this->assertSame( '', $parts['doctype'] );
		$this->assertSame( $fragment, $parts['rest'] );
		$this->assertSame( $fragment, ANPA_Socios_Email_Template_Html_Policy::join_doctype( $parts['doctype'], $parts['rest'] ) );
	}

	public function test_the_doctype_seam_is_lossless_for_every_shipped_default(): void {
		foreach ( $this->shipped_html_files() as $file ) {
			$html  = (string) file_get_contents( $file );
			$parts = ANPA_Socios_Email_Template_Html_Policy::split_doctype( $html );

			$this->assertSame(
				$html,
				ANPA_Socios_Email_Template_Html_Policy::join_doctype( $parts['doctype'], $parts['rest'] ),
				basename( $file ) . ': splitting and rejoining the doctype changed the document'
			);
		}
	}

	public function test_join_refuses_to_restore_anything_but_the_legal_doctype(): void {
		$this->assertSame(
			'<p>t</p>',
			ANPA_Socios_Email_Template_Html_Policy::join_doctype( '<!DOCTYPE inventado>', '<p>t</p>' )
		);
	}

	// ── Negative controls, declared rather than scattered ────────────────

	public function test_the_forbidden_examples_are_all_refused_by_the_declared_policy(): void {
		// A readable catalogue of what must not survive. The sanitiser itself is exercised against
		// these on a real engine (integration); here the assertion is that each example really does
		// use something the policy forbids, so the catalogue cannot rot into a list of harmless
		// strings that pass by accident.
		$tags       = ANPA_Socios_Email_Template_Html_Policy::allowed_tags();
		$properties = ANPA_Socios_Email_Template_Html_Policy::allowed_css_properties();
		$protocols  = ANPA_Socios_Email_Template_Html_Policy::allowed_protocols();

		foreach ( ANPA_Socios_Email_Template_Html_Policy::forbidden_examples() as $label => $payload ) {
			$refused = false;

			preg_match_all( '#<([a-zA-Z][a-zA-Z0-9]*)([^>]*)#', $payload, $elements, PREG_SET_ORDER );
			foreach ( $elements as $element ) {
				$tag = strtolower( $element[1] );

				if ( ! isset( $tags[ $tag ] ) ) {
					$refused = true;
					continue;
				}

				preg_match_all( '#\s([a-zA-Z-]+)\s*=#', $element[2], $attributes );
				foreach ( array_map( 'strtolower', $attributes[1] ) as $attribute ) {
					if ( ! isset( $tags[ $tag ][ $attribute ] ) ) {
						$refused = true;
					}
				}
			}

			// A forbidden scheme.
			if ( preg_match( '#(href|src)="([a-zA-Z][a-zA-Z0-9+.-]*):#', $payload, $scheme ) ) {
				if ( ! in_array( strtolower( $scheme[2] ), $protocols, true ) ) {
					$refused = true;
				}
			}

			// A forbidden CSS property.
			if ( preg_match( '#style="([^"]*)"#', $payload, $style ) ) {
				foreach ( explode( ';', $style[1] ) as $declaration ) {
					if ( false === strpos( $declaration, ':' ) ) {
						continue;
					}
					$property = strtolower( trim( explode( ':', $declaration, 2 )[0] ) );
					if ( ! in_array( $property, $properties, true ) ) {
						$refused = true;
					}
				}
			}

			// A prefix that is not the one legal doctype.
			if ( 0 === stripos( ltrim( $payload ), '<!' ) || 0 === strpos( ltrim( $payload ), '<?' ) ) {
				$parts = ANPA_Socios_Email_Template_Html_Policy::split_doctype( $payload );
				if ( '' === $parts['doctype'] ) {
					$refused = true;
				}
			}

			$this->assertTrue( $refused, "the policy does not actually forbid the '{$label}' example" );
		}
	}

	public function test_the_policy_returns_a_safe_copy(): void {
		// Callers will build admin help text and test fixtures from these. Mutating the result must
		// not widen what may be stored.
		$tags            = ANPA_Socios_Email_Template_Html_Policy::allowed_tags();
		$tags['script']  = array();

		$this->assertArrayNotHasKey( 'script', ANPA_Socios_Email_Template_Html_Policy::allowed_tags() );
		$this->assertNotContains( 'script', ANPA_Socios_Email_Template_Html_Policy::allowed_tag_names() );
	}

	public function test_the_policy_does_not_touch_wordpress(): void {
		// The sanitiser needs wp_kses; the policy must not, or it could not be asserted here.
		// CALLS are what matter: the file names wp_kses in prose to explain the division of labour,
		// and a test that banned the word would push that explanation out of the file.
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-html-policy.php' );

		foreach ( array( 'wp_kses(', 'esc_html(', 'get_option(', 'update_option(', 'apply_filters(', 'add_filter(' ) as $call ) {
			$this->assertStringNotContainsString( $call, $src );
		}
		$this->assertStringNotContainsString( '$wpdb', $src );
	}
}
