<?php
/**
 * Tests for the pre-render context gate (fase36, PR-36s1c).
 *
 * The gap this closes: "this variable is mandatory" used to live in whichever helper the emitter
 * happened to call. Checked against the event DECLARATION instead, the requirement survives an
 * emitter that builds its context by hand.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Validator extends TestCase {

	/** @return ANPA_Socios_Email_Template_Set */
	private function set(): ANPA_Socios_Email_Template_Set {
		return ANPA_Socios_Email_Template_Events::set();
	}

	/**
	 * @param  string $fragment Expected message fragment.
	 * @param  callable $act    Operation that must throw.
	 */
	private function assert_rejected( string $fragment, callable $act ): void {
		try {
			$act();
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			$this->assertStringContainsString( $fragment, $e->getMessage() );
			return;
		}

		$this->fail( "expected rejection for: {$fragment}" );
	}

	public function test_a_complete_context_passes(): void {
		$definition = $this->set()->get( 'activity_cancellation_admin_notice' );

		ANPA_Socios_Email_Template_Validator::assert_context(
			$definition,
			array(
				'nome_alumno'     => 'Iria Exemplo',
				'nome_actividade' => 'Robótica',
				'correo_socio'    => 'nai@example.com',
			)
		);

		$this->assertTrue( true, 'no exception means the context satisfied the declaration' );
	}

	public function test_a_missing_required_token_is_refused(): void {
		$definition = $this->set()->get( 'activity_cancellation_admin_notice' );

		$this->assert_rejected(
			"requires 'nome_actividade' and the context does not supply it",
			static function () use ( $definition ) {
				ANPA_Socios_Email_Template_Validator::assert_context(
					$definition,
					array( 'nome_alumno' => 'Iria Exemplo', 'correo_socio' => 'nai@example.com' )
				);
			}
		);
	}

	public function test_an_empty_required_token_is_refused_because_the_paragraph_would_vanish(): void {
		// The dangerous case: an optional block would remove the paragraph and the email would
		// look finished. Absent at least leaves a trace; empty does not.
		$definition = $this->set()->get( 'activity_cancellation_admin_notice' );

		$this->assert_rejected(
			'the email would look complete',
			static function () use ( $definition ) {
				ANPA_Socios_Email_Template_Validator::assert_context(
					$definition,
					array(
						'nome_alumno'     => 'Iria Exemplo',
						'nome_actividade' => '   ',
						'correo_socio'    => 'nai@example.com',
					)
				);
			}
		);
	}

	public function test_an_undeclared_context_key_is_refused(): void {
		$definition = $this->set()->get( 'activity_cancellation_admin_notice' );

		$this->assert_rejected(
			'a typo or a leftover',
			static function () use ( $definition ) {
				ANPA_Socios_Email_Template_Validator::assert_context(
					$definition,
					array(
						'nome_alumno'     => 'Iria Exemplo',
						'nome_actividade' => 'Robótica',
						'correo_socio'    => 'nai@example.com',
						'nome_empreza'    => 'Empresa Exemplo',
					)
				);
			}
		);
	}

	public function test_the_exclusive_pair_is_checked_against_the_declaration(): void {
		$definition = $this->set()->get( 'member_application_approved' );

		// Neither side active: the email would give no access instructions at all.
		$this->assert_rejected(
			'no access instructions',
			static function () use ( $definition ) {
				ANPA_Socios_Email_Template_Validator::assert_context( $definition, array() );
			}
		);

		// Both active: the email would contradict itself.
		$this->assert_rejected(
			'contradict itself',
			static function () use ( $definition ) {
				ANPA_Socios_Email_Template_Validator::assert_context(
					$definition,
					array(
						'ligazon_area_socios'     => 'https://example.org/area/',
						'sen_ligazon_area_socios' => '1',
					)
				);
			}
		);
	}

	public function test_the_error_names_the_event(): void {
		$definition = $this->set()->get( 'member_application_completed' );

		$this->assert_rejected(
			"event 'member_application_completed'",
			static function () use ( $definition ) {
				ANPA_Socios_Email_Template_Validator::assert_context( $definition, array() );
			}
		);
	}

	public function test_render_cannot_skip_the_gate(): void {
		$definition = $this->set()->get( 'activity_cancellation_admin_notice' );

		$this->assert_rejected(
			'does not supply it',
			static function () use ( $definition ) {
				ANPA_Socios_Email_Template_Validator::render(
					$definition,
					array( 'subject' => 'Proba', 'body_html' => '<p>x</p>', 'body_text' => 'x' ),
					array()
				);
			}
		);
	}

	public function test_a_validated_context_still_renders(): void {
		$definition = $this->set()->get( 'waitlist_place_offer' );

		$result = ANPA_Socios_Email_Template_Validator::render(
			$definition,
			array(
				'subject'   => 'Praza en {{nome_actividade}}',
				'body_html' => '<p>{{nome_actividade}} en {{dias_prazo}} días</p>',
				'body_text' => '{{nome_actividade}} en {{dias_prazo}} días',
			),
			array( 'nome_actividade' => 'Robótica', 'dias_prazo' => '3' )
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Praza en Robótica', $result['subject'] );
	}

	/**
	 * Every declared required token of every event must be satisfiable from its own sample data,
	 * or the declaration promises something the registry cannot demonstrate.
	 */
	public function test_every_event_sample_satisfies_its_own_required_tokens(): void {
		foreach ( $this->set()->all() as $key => $definition ) {
			$sample = $definition->sample_data();

			foreach ( $definition->required_tokens() as $token ) {
				$this->assertArrayHasKey( $token, $sample, "{$key}: no sample for required '{$token}'" );
				$this->assertNotSame( '', trim( $sample[ $token ] ), "{$key}: empty sample for required '{$token}'" );
			}
		}
	}

	public function test_the_validator_does_not_touch_wordpress(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-validator.php' );

		foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src );
		}
	}
}
