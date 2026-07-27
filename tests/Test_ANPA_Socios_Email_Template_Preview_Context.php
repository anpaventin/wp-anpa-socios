<?php
/**
 * Tests for ANPA_Socios_Email_Template_Preview_Context.
 *
 * Proves I7 resolution: branching events produce BOTH variants, the area-link pair is
 * exclusive in each, and non-branching events produce a single default context.
 *
 * @since  1.48.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Preview_Context extends TestCase {

	// ─── Branching detection ─────────────────────────────────────────────────

	public function test_branching_events_match_context_canonical_list(): void {
		$this->assertSame(
			ANPA_Socios_Email_Template_Context::events_requiring_area_link(),
			ANPA_Socios_Email_Template_Preview_Context::branching_events()
		);
	}

	public function test_requires_branching_preview_for_known_branching_events(): void {
		$set = ANPA_Socios_Email_Template_Events::set();

		foreach ( ANPA_Socios_Email_Template_Preview_Context::branching_events() as $key ) {
			$definition = $set->get( $key );
			$this->assertTrue(
				ANPA_Socios_Email_Template_Preview_Context::requires_branching_preview( $definition ),
				"Event {$key} should require branching preview"
			);
		}
	}

	public function test_non_branching_event_does_not_require_branching(): void {
		$set = ANPA_Socios_Email_Template_Events::set();
		// auth_access_code never branches on area link.
		$definition = $set->get( 'auth_access_code' );
		$this->assertFalse(
			ANPA_Socios_Email_Template_Preview_Context::requires_branching_preview( $definition )
		);
	}

	// ─── Variant building ────────────────────────────────────────────────────

	public function test_build_variants_produces_both_keys(): void {
		$set        = ANPA_Socios_Email_Template_Events::set();
		$definition = $set->get( 'member_application_approved' );

		$variants = ANPA_Socios_Email_Template_Preview_Context::build_variants( $definition );

		$this->assertArrayHasKey( 'with_area_link', $variants );
		$this->assertArrayHasKey( 'without_area_link', $variants );
	}

	public function test_with_area_link_variant_has_url_and_no_flag(): void {
		$set        = ANPA_Socios_Email_Template_Events::set();
		$definition = $set->get( 'member_application_approved' );

		$variants = ANPA_Socios_Email_Template_Preview_Context::build_variants( $definition );
		$ctx      = $variants['with_area_link'];

		$this->assertNotEmpty( $ctx[ ANPA_Socios_Email_Template_Context::TOKEN_AREA_LINK ] );
		$this->assertSame( '', $ctx[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] );
	}

	public function test_without_area_link_variant_has_flag_and_no_url(): void {
		$set        = ANPA_Socios_Email_Template_Events::set();
		$definition = $set->get( 'member_application_approved' );

		$variants = ANPA_Socios_Email_Template_Preview_Context::build_variants( $definition );
		$ctx      = $variants['without_area_link'];

		$this->assertSame( '', $ctx[ ANPA_Socios_Email_Template_Context::TOKEN_AREA_LINK ] );
		$this->assertNotEmpty( $ctx[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] );
	}

	public function test_area_link_exclusivity_in_both_variants(): void {
		$set = ANPA_Socios_Email_Template_Events::set();

		foreach ( ANPA_Socios_Email_Template_Preview_Context::branching_events() as $key ) {
			$definition = $set->get( $key );
			$variants   = ANPA_Socios_Email_Template_Preview_Context::build_variants( $definition );

			foreach ( $variants as $variant_id => $ctx ) {
				// The invariant check must not throw for either variant.
				ANPA_Socios_Email_Template_Context::assert_area_link_exclusive( $ctx );

				// Additionally verify the complementary nature:
				$has_url  = '' !== ( $ctx[ ANPA_Socios_Email_Template_Context::TOKEN_AREA_LINK ] ?? '' );
				$has_flag = '' !== ( $ctx[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] ?? '' );
				$this->assertNotSame(
					$has_url,
					$has_flag,
					"Variant {$variant_id} of {$key}: URL and flag must be mutually exclusive"
				);
			}
		}
	}

	public function test_build_variants_throws_for_non_branching_event(): void {
		$set        = ANPA_Socios_Email_Template_Events::set();
		$definition = $set->get( 'auth_access_code' );

		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		ANPA_Socios_Email_Template_Preview_Context::build_variants( $definition );
	}

	// ─── Default context for non-branching events ────────────────────────────

	public function test_build_default_returns_a_renderable_context(): void {
		$set        = ANPA_Socios_Email_Template_Events::set();
		$definition = $set->get( 'auth_access_code' );

		$ctx = ANPA_Socios_Email_Template_Preview_Context::build_default( $definition );
		$this->assertIsArray( $ctx );
		$this->assertNotEmpty( $ctx );
	}

	// ─── all_variants ────────────────────────────────────────────────────────

	public function test_all_variants_for_branching_event_returns_two(): void {
		$set        = ANPA_Socios_Email_Template_Events::set();
		$definition = $set->get( 'member_application_completed' );

		$all = ANPA_Socios_Email_Template_Preview_Context::all_variants( $definition );
		$this->assertArrayHasKey( 'with_area_link', $all );
		$this->assertArrayHasKey( 'without_area_link', $all );
		$this->assertCount( 2, $all );
	}

	public function test_all_variants_for_non_branching_event_returns_default(): void {
		$set        = ANPA_Socios_Email_Template_Events::set();
		$definition = $set->get( 'auth_access_code' );

		$all = ANPA_Socios_Email_Template_Preview_Context::all_variants( $definition );
		$this->assertArrayHasKey( 'default', $all );
		$this->assertCount( 1, $all );
	}

	// ─── Labels ──────────────────────────────────────────────────────────────

	public function test_variant_labels_cover_all_possible_keys(): void {
		$labels = ANPA_Socios_Email_Template_Preview_Context::variant_labels();
		$this->assertArrayHasKey( 'with_area_link', $labels );
		$this->assertArrayHasKey( 'without_area_link', $labels );
		$this->assertArrayHasKey( 'default', $labels );

		foreach ( $labels as $label ) {
			$this->assertNotEmpty( $label );
		}
	}

	// ─── Purity: does not touch WordPress ────────────────────────────────────

	public function test_class_does_not_call_wordpress_functions(): void {
		$source = file_get_contents(
			dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-preview-context.php'
		);
		$tokens  = token_get_all( $source );
		$code    = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		$wp_functions = array(
			'esc_html', 'esc_attr', 'esc_url', 'wp_kses', 'sanitize_text_field',
			'get_option', 'update_option', 'delete_option',
			'apply_filters', 'do_action', 'add_filter', 'add_action',
			'wp_die', 'wp_mail', 'wp_redirect', 'wp_safe_redirect',
			'current_user_can', 'check_admin_referer', 'wp_nonce_field',
			'__\\(', '_e\\(', 'esc_html__', 'esc_attr__',
		);
		foreach ( $wp_functions as $fn ) {
			$pattern = '/\b' . $fn . '\s*\(/';
			$this->assertDoesNotMatchRegularExpression(
				$pattern,
				$code,
				"Pure class must not call WordPress function matching: {$fn}"
			);
		}

		$this->assertStringNotContainsString( '$wpdb', $code );
	}
}
