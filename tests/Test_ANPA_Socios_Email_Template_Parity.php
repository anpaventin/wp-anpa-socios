<?php
/**
 * Literal parity between the shipped live defaults and the golden oracle (fase36, PR-36s1c-2b).
 *
 * The ten live defaults are transcriptions, not rewrites. This test renders each one with the
 * **same context the capture used** and compares the result byte for byte against the golden
 * subject and body. If a space, an accent, an entity or a tag differs, it fails.
 *
 * Scope, stated because it is easy to overclaim: **subject and HTML body are transcriptions**.
 * The plain-text body is NOT — the live emails have no plain-text alternative at all, so every
 * `.text` file is new content and is reviewed as such. Presenting it as a transcription would be
 * a false claim.
 *
 * The test covers whichever defaults are shipped, so it tightens as files land instead of
 * needing a list kept in sync.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Parity extends TestCase {

	/** The fixed fictitious values the capture used. Must match Test_ANPA_Socios_Email_Golden. */
	private const ASSOCIATION = 'ANPA Exemplo';
	private const CONTACT     = 'contacto@example.org';
	private const SIGNATURE   = "A Xunta Directiva\nANPA Exemplo";
	private const AREA_URL    = 'https://example.org/area-socios/';
	private const ADMIN_URL   = 'http://example.org/wp-admin/admin.php?page=anpa-socios-settings';

	/**
	 * The context each capture was produced with, per event and variant.
	 *
	 * Declared here rather than reconstructed from the golden text: reverse-engineering the
	 * inputs from the output is exactly the circular reasoning this oracle exists to avoid.
	 *
	 * @return array<string,array<string,array<string,string>>> event => variant => context
	 */
	private function contexts(): array {
		$globals = array(
			'nome_anpa'   => self::ASSOCIATION,
			'correo_anpa' => self::CONTACT,
			'sinatura'    => self::SIGNATURE,
		);

		$with_url    = ANPA_Socios_Email_Template_Context::area_link( self::AREA_URL );
		$without_url = ANPA_Socios_Email_Template_Context::area_link( '' );

		return array(
			'auth_access_code'                    => array(
				'default' => $globals + array( 'codigo' => '123456' ),
			),
			'auth_access_code_signup'             => array(
				'default' => $globals + array( 'codigo' => '123456' ),
			),
			'member_application_admin_pending'    => array(
				'default' => $globals + array(
					'nome_solicitante'   => 'Uxía Exemplo Ficticio',
					'correo_solicitante' => 'nai@example.com',
					'ligazon_axustes'    => self::ADMIN_URL,
				),
			),
			'member_application_approved'         => array(
				'with-url'    => $globals + $with_url,
				'without-url' => $globals + $without_url,
			),
			'member_application_completed'        => array(
				'with-url'    => $globals + $with_url,
				'without-url' => $globals + $without_url,
			),
			'member_application_changes_required'  => array(
				'default' => $globals,
			),
			'member_cancellation_admin_notice'    => array(
				'default' => $globals + array(
					'nome_socio'   => 'Uxía Exemplo Ficticio',
					'correo_socio' => 'nai@example.com',
				),
			),
			'member_reactivation_admin_notice'    => array(
				'default' => $globals + array( 'correo_socio' => 'nai@example.com' ),
			),
			'activity_cancellation_admin_notice'  => array(
				'default' => $globals + array(
					'nome_alumno'     => 'Antía Exemplo',
					'nome_actividade' => 'Robótica',
					'correo_socio'    => 'nai@example.com',
				),
			),
			'waitlist_place_offer'                => array(
				'default' => $globals + array(
					'nome_actividade' => 'Robótica',
					'dias_prazo'      => '3',
				),
			),
		);
	}

	/** @return string */
	private function golden_dir(): string {
		return __DIR__ . '/golden';
	}

	/**
	 * Splits a golden capture into its subject and its body.
	 *
	 * @param  string $stem Golden stem.
	 * @return array{subject:string,body:string}|null Null when the capture is absent.
	 */
	private function golden( string $stem ): ?array {
		$path = $this->golden_dir() . '/' . $stem . '.txt';
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw   = (string) file_get_contents( $path );
		$parts = explode( "\n---BODY---\n", $raw, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$subject = '';
		foreach ( explode( "\n", $parts[0] ) as $line ) {
			if ( 0 === strpos( $line, 'SUBJECT: ' ) ) {
				$subject = substr( $line, strlen( 'SUBJECT: ' ) );
			}
		}

		return array(
			'subject' => $subject,
			'body'    => $parts[1],
		);
	}

	public function test_every_shipped_live_default_reproduces_its_golden_exactly(): void {
		$set      = ANPA_Socios_Email_Template_Events::set();
		$contexts = $this->contexts();
		$checked  = 0;

		foreach ( $contexts as $event_key => $variants ) {
			if ( ! ANPA_Socios_Email_Template_Defaults::exists( $event_key ) ) {
				continue; // Not transcribed yet; other tests catch an orphan or missing default.
			}

			$definition = $set->get( $event_key );
			$default    = ANPA_Socios_Email_Template_Defaults::load( $event_key );

			foreach ( $variants as $variant => $context ) {
				$golden = $this->golden( ANPA_Socios_Golden_Manifest::stem( $event_key, $variant ) );
				$this->assertNotNull( $golden, "unreadable golden for {$event_key}.{$variant}" );

				$rendered = ANPA_Socios_Email_Template_Renderer::render(
					$default,
					$context,
					$definition->declared_tokens()
				);

				$this->assertTrue(
					$rendered['ok'],
					"{$event_key}.{$variant} failed to render: " . $rendered['code']
					. ' ' . implode( ', ', $rendered['undeclared'] )
				);

				$this->assertSame(
					$golden['subject'],
					$rendered['subject'],
					"{$event_key}.{$variant}: the subject is not a literal transcription"
				);
				$this->assertSame(
					$golden['body'],
					$rendered['body_html'],
					"{$event_key}.{$variant}: the HTML body is not a literal transcription"
				);

				++$checked;
			}
		}

		$this->assertGreaterThan( 0, $checked, 'no live default was checked; the transcription has not started' );
	}

	public function test_the_context_map_covers_exactly_the_live_events(): void {
		$declared = array_keys( $this->contexts() );
		sort( $declared, SORT_STRING );

		$live = ANPA_Socios_Email_Template_Events::set()->live_keys();
		sort( $live, SORT_STRING );

		$this->assertSame( $live, $declared, 'the parity contexts and the live events must match' );
	}

	public function test_the_context_map_covers_exactly_the_declared_variants(): void {
		foreach ( $this->contexts() as $event_key => $variants ) {
			$declared = ANPA_Socios_Golden_Manifest::variants( $event_key );
			sort( $declared, SORT_STRING );

			$present = array_keys( $variants );
			sort( $present, SORT_STRING );

			$this->assertSame( $declared, $present, "{$event_key}: variant contexts do not match the manifest" );
		}
	}

	public function test_no_live_default_uses_a_token_outside_its_declared_context(): void {
		// A token the capture context does not supply would render empty and still match the
		// golden by accident if the golden happens not to show it. Catching it here keeps the
		// transcription honest.
		$set = ANPA_Socios_Email_Template_Events::set();

		foreach ( $this->contexts() as $event_key => $variants ) {
			if ( ! ANPA_Socios_Email_Template_Defaults::exists( $event_key ) ) {
				continue;
			}

			$default = ANPA_Socios_Email_Template_Defaults::load( $event_key );
			$used    = ANPA_Socios_Email_Template_Renderer::tokens_in(
				$default['subject'] . "\n" . $default['body_html'] . "\n" . $default['body_text']
			);

			foreach ( $variants as $variant => $context ) {
				foreach ( $used as $token ) {
					$this->assertArrayHasKey(
						$token,
						$context,
						"{$event_key}.{$variant}: template uses '{$token}', which the capture context never supplied"
					);
				}
			}

			$this->assertNotSame( array(), $set->get( $event_key )->variables() );
		}
	}
}
