<?php
/**
 * Contract tests for the NEW default content (fase36, PR-36s1c-3).
 *
 * The ten live templates are pinned byte-for-byte by the parity suite: their wording is whatever
 * production already sends, and a test that judged it would be judging history. Everything else is
 * new content written from the §15 source brief, and it needs the opposite kind of test — not
 * "does it match" but "is it safe to send".
 *
 * The set under test is DERIVED, not listed: a shipped default whose subject is not
 * `approved_by_parity` is new content. So each group of templates falls under these invariants the
 * moment it lands, and no list has to be kept in sync.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Content extends TestCase {

	/** @return string[] Shipped stems that are new content rather than transcriptions. */
	private function new_stems(): array {
		$stems = array();

		foreach ( ANPA_Socios_Email_Template_Defaults::stems() as $stem ) {
			$status = ANPA_Socios_Email_Template_Editorial::status(
				$stem,
				ANPA_Socios_Email_Template_Editorial::CHANNEL_SUBJECT
			);

			if ( ANPA_Socios_Email_Template_Editorial::STATUS_APPROVED_BY_PARITY !== $status ) {
				$stems[] = $stem;
			}
		}

		return $stems;
	}

	/**
	 * @param  string $stem Template stem.
	 * @return ANPA_Socios_Email_Template_Definition
	 */
	private function definition( string $stem ): ANPA_Socios_Email_Template_Definition {
		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $definition ) {
			if ( $definition->default_template() === $stem ) {
				return $definition;
			}
		}

		$this->fail( "no registered event owns '{$stem}'" );
	}

	public function test_there_is_new_content_to_check(): void {
		// Guards the derivation itself: if this ever returns nothing, every invariant below would
		// pass vacuously and the suite would be decoration.
		$this->assertNotSame( array(), $this->new_stems() );
	}

	// ── Every channel is a channel ───────────────────────────────────────

	public function test_no_new_default_starts_out_approved(): void {
		// Shipping wording is not approving it. Nobody has read these in a meeting yet, and the
		// editorial gate is what stops a future emitter going live on unreviewed text.
		foreach ( $this->new_stems() as $stem ) {
			foreach ( ANPA_Socios_Email_Template_Editorial::channels() as $channel ) {
				$this->assertSame(
					ANPA_Socios_Email_Template_Editorial::STATUS_PENDING_REVIEW,
					ANPA_Socios_Email_Template_Editorial::status( $stem, $channel ),
					"{$stem}.{$channel} claims a review that did not happen"
				);
			}

			$this->assertFalse(
				ANPA_Socios_Email_Template_Editorial::may_activate_emitter( $stem ),
				"{$stem} would let an emitter go live on unreviewed wording"
			);
		}
	}

	public function test_the_plain_text_channel_carries_no_markup(): void {
		// Written as its own channel, never produced by stripping tags off the HTML. Residue is
		// what gives a mechanical derivation away: a tag, an entity, an attribute.
		foreach ( $this->new_stems() as $stem ) {
			$text = ANPA_Socios_Email_Template_Defaults::load( $stem )['body_text'];

			$this->assertDoesNotMatchRegularExpression( '/<[a-z\/!]/i', $text, "{$stem}: markup in the text body" );
			$this->assertStringNotContainsString( '&nbsp;', $text, "{$stem}: entity in the text body" );
			$this->assertStringNotContainsString( '&amp;', $text, "{$stem}: entity in the text body" );
			$this->assertStringNotContainsString( 'style=', $text, "{$stem}: attribute in the text body" );
			$this->assertStringNotContainsString( 'href=', $text, "{$stem}: attribute in the text body" );
		}
	}

	public function test_the_plain_text_channel_prints_its_urls_in_full(): void {
		// An anchor's text can differ from its target in HTML. In plain text there is no anchor, so
		// a link the reader cannot copy is a link that does not exist.
		foreach ( $this->new_stems() as $stem ) {
			$definition = $this->definition( $stem );
			$default    = ANPA_Socios_Email_Template_Defaults::load( $stem );

			foreach ( ANPA_Socios_Email_Template_Renderer::tokens_in( $default['body_text'] ) as $token ) {
				if ( 0 !== strpos( $token, 'ligazon' ) ) {
					continue;
				}

				$this->assertStringContainsString(
					'{{' . $token . '}}',
					$default['body_text'],
					"{$stem}: '{$token}' appears only as a block marker in the text body"
				);
				$this->assertArrayHasKey(
					$token,
					$definition->declared_tokens(),
					"{$stem}: '{$token}' is not declared"
				);
			}
		}
	}

	public function test_every_new_html_body_declares_its_encoding_and_language(): void {
		foreach ( $this->new_stems() as $stem ) {
			$html = ANPA_Socios_Email_Template_Defaults::load( $stem )['body_html'];

			$this->assertStringContainsString( '<!DOCTYPE html>', $html, "{$stem}: no doctype" );
			$this->assertStringContainsString( 'charset="UTF-8"', $html, "{$stem}: no charset" );
			$this->assertStringContainsString( 'lang="gl"', $html, "{$stem}: no language" );
		}
	}

	// ── The failure this whole slice exists to prevent ───────────────────

	public function test_no_required_token_is_wrapped_in_an_optional_block(): void {
		// THE bug class of this slice: an optional block around a mandatory value produces an email
		// that looks finished and is missing a paragraph. The validator refuses an empty required
		// token at render time; this refuses the template that would have hidden it.
		foreach ( $this->new_stems() as $stem ) {
			$definition = $this->definition( $stem );
			$default    = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$source     = $default['subject'] . "\n" . $default['body_html'] . "\n" . $default['body_text'];

			foreach ( $definition->required_tokens() as $token ) {
				$this->assertDoesNotMatchRegularExpression(
					'/\{\{\s*#\s*' . preg_quote( $token, '/' ) . '\s*\}\}/u',
					$source,
					"{$stem}: required '{$token}' sits inside an optional block, so its absence would be silent"
				);
			}
		}
	}

	public function test_every_required_token_is_actually_shown(): void {
		// A token the emitter must supply and the template never prints is either a declaration
		// nobody kept or a paragraph somebody deleted.
		foreach ( $this->new_stems() as $stem ) {
			$definition = $this->definition( $stem );
			$default    = ANPA_Socios_Email_Template_Defaults::load( $stem );

			foreach ( $definition->required_tokens() as $token ) {
				$this->assertStringContainsString(
					'{{' . $token . '}}',
					$default['subject'] . "\n" . $default['body_html'],
					"{$stem}: required '{$token}' is never shown in the subject or the HTML body"
				);
				$this->assertStringContainsString(
					'{{' . $token . '}}',
					$default['body_text'],
					"{$stem}: required '{$token}' is never shown in the text body"
				);
			}
		}
	}

	public function test_every_new_subject_is_one_line_and_carries_no_link(): void {
		foreach ( $this->new_stems() as $stem ) {
			$subject = ANPA_Socios_Email_Template_Defaults::load( $stem )['subject'];

			$this->assertSame( trim( $subject ), $subject, "{$stem}: subject has surrounding space" );
			$this->assertStringNotContainsString( "\n", $subject, "{$stem}: multi-line subject" );
			$this->assertDoesNotMatchRegularExpression( '/https?:/i', $subject, "{$stem}: URL in the subject" );
			$this->assertLessThanOrEqual( 120, mb_strlen( $subject ), "{$stem}: subject too long to read in a list" );
		}
	}

	public function test_no_new_default_leaks_an_internal_identifier(): void {
		foreach ( $this->new_stems() as $stem ) {
			$default  = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$rendered = $default['subject'] . "\n" . $default['body_html'] . "\n" . $default['body_text'];

			foreach ( array( 'pending_manual_review', 'wp-admin', 'fase3', 'TODO', 'FIXME' ) as $leak ) {
				$this->assertStringNotContainsString( $leak, $rendered, "{$stem}: leaks '{$leak}'" );
			}
		}
	}

	public function test_no_new_default_claims_a_delivery_it_cannot_prove(): void {
		// `wp_mail()` returning true means accepted by the local mail system. Nothing in this
		// plugin knows whether a message was delivered, opened or read.
		foreach ( $this->new_stems() as $stem ) {
			$default  = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$haystack = mb_strtolower( $default['subject'] . ' ' . $default['body_html'] . ' ' . $default['body_text'] );

			foreach ( array( 'correo entregado', 'entregouse o correo', 'foi lido', 'lido pola empresa' ) as $claim ) {
				$this->assertStringNotContainsString( $claim, $haystack, "{$stem}: claims '{$claim}'" );
			}
		}
	}

	public function test_every_new_default_renders_with_its_own_sample_data(): void {
		// End to end on the real files: declaration, template and sample data have to agree, and a
		// template that cannot render its own example would never have rendered anything.
		foreach ( $this->new_stems() as $stem ) {
			$definition = $this->definition( $stem );
			$default    = ANPA_Socios_Email_Template_Defaults::load( $stem );

			$result = ANPA_Socios_Email_Template_Renderer::render(
				array(
					'subject'   => $default['subject'],
					'body_html' => $default['body_html'],
					'body_text' => $default['body_text'],
				),
				$definition->sample_data(),
				$definition->declared_tokens()
			);

			$this->assertTrue( $result['ok'], "{$stem}: {$result['code']} " . implode( ',', $result['undeclared'] ) );
			$this->assertStringNotContainsString( '{{', $result['body_html'], "{$stem}: unresolved token in HTML" );
			$this->assertStringNotContainsString( '{{', $result['body_text'], "{$stem}: unresolved token in text" );
			$this->assertStringNotContainsString( '{{', $result['subject'], "{$stem}: unresolved token in subject" );
			$this->assertNotSame( '', trim( $result['body_text'] ), "{$stem}: empty text body" );
		}
	}

	public function test_no_new_default_nests_optional_blocks(): void {
		// Nesting is NOT part of the syntax. The renderer resolves blocks in one non-greedy pass, so
		// an outer opener pairs with the FIRST closer it finds: the inner block survives as text and
		// the leftover `{{/}}` is not a token, so nothing substitutes it and it reaches the family
		// literally. The renderer's unclosed-block check only sees orphan OPENERS, not orphan
		// closers, so this is the one malformed shape it cannot report.
		foreach ( $this->new_stems() as $stem ) {
			$default = ANPA_Socios_Email_Template_Defaults::load( $stem );

			foreach ( array( 'body_html', 'body_text' ) as $channel ) {
				preg_match_all( '/\{\{\s*(#|\/)[^}]*\}\}/u', $default[ $channel ], $matches, PREG_OFFSET_CAPTURE );

				$depth = 0;
				foreach ( $matches[1] as $marker ) {
					$depth += ( '#' === $marker[0] ) ? 1 : -1;

					$this->assertLessThanOrEqual( 1, $depth, "{$stem}.{$channel}: nested optional block" );
					$this->assertGreaterThanOrEqual( 0, $depth, "{$stem}.{$channel}: closer without opener" );
				}

				$this->assertSame( 0, $depth, "{$stem}.{$channel}: unbalanced optional blocks" );
			}
		}
	}

	public function test_every_new_default_renders_cleanly_with_no_optional_value(): void {
		// The «without-*» scenario, applied to the whole set: configuration is present, every
		// optional value is absent. This is what an installation that has filled in nothing beyond
		// the basics receives, and it is where a removed block leaves a hole, an empty list item or
		// a heading with nothing under it.
		$globals = ANPA_Socios_Email_Template_Events::globals();

		foreach ( $this->new_stems() as $stem ) {
			$definition = $this->definition( $stem );
			$sample     = $definition->sample_data();
			$default    = ANPA_Socios_Email_Template_Defaults::load( $stem );

			$context = array();
			foreach ( $definition->required_tokens() as $token ) {
				$context[ $token ] = $sample[ $token ] ?? 'Exemplo';
			}
			foreach ( $globals as $token ) {
				if ( ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK === $token ) {
					continue; // An internal flag, not configuration.
				}
				if ( isset( $sample[ $token ] ) ) {
					$context[ $token ] = $sample[ $token ];
				}
			}

			$result = ANPA_Socios_Email_Template_Renderer::render(
				array(
					'subject'   => $default['subject'],
					'body_html' => $default['body_html'],
					'body_text' => $default['body_text'],
				),
				$context,
				$definition->declared_tokens()
			);

			$this->assertTrue( $result['ok'], "{$stem}: {$result['code']}" );
			$this->assertStringNotContainsString( '{{', $result['body_html'], "{$stem}: unresolved token" );
			$this->assertStringNotContainsString( '{{', $result['body_text'], "{$stem}: unresolved token" );

			// A hole where a paragraph used to be.
			$this->assertDoesNotMatchRegularExpression( '/\n{3,}/', $result['body_text'], "{$stem}: gap in the text body" );
			$this->assertStringNotContainsString( '<p></p>', $result['body_html'], "{$stem}: empty paragraph" );
			$this->assertStringNotContainsString( '<li></li>', $result['body_html'], "{$stem}: empty list item" );
			$this->assertDoesNotMatchRegularExpression(
				'/<ul>\s*<\/ul>/',
				$result['body_html'],
				"{$stem}: a list with every item removed"
			);
		}
	}

	public function test_no_new_default_shows_the_internal_area_flag(): void {
		// `sen_ligazon_area_socios` only enables a paragraph; printing it would show a bare «1».
		foreach ( $this->new_stems() as $stem ) {
			$default = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$source  = $default['subject'] . "\n" . $default['body_html'] . "\n" . $default['body_text'];

			$this->assertStringNotContainsString(
				'{{' . ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK . '}}',
				$source,
				"{$stem}: prints the internal flag instead of using it as a block"
			);
		}
	}

	// ── Grupo: socios e acceso ───────────────────────────────────────────

	public function test_the_membership_group_is_complete(): void {
		foreach (
			array(
				'member_application_received',
				'member_cancellation_requested',
				'member_cancellation_end_of_year',
			) as $stem
		) {
			$this->assertTrue( ANPA_Socios_Email_Template_Defaults::exists( $stem ), "{$stem} not shipped" );
			$this->assertContains( $stem, $this->new_stems(), "{$stem} is not treated as new content" );
		}
	}

	public function test_the_withdrawal_emails_never_mention_money_or_promise_a_refund(): void {
		// The plugin handles no charges. The generic payment guard already greps the whole set; this
		// adds the promise a withdrawal email is most tempted to make.
		foreach ( array( 'member_cancellation_requested', 'member_cancellation_end_of_year' ) as $stem ) {
			$default  = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$haystack = mb_strtolower( $default['subject'] . ' ' . $default['body_html'] . ' ' . $default['body_text'] );

			foreach ( array( 'devolución', 'devolver', 'reembolso', 'importe' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $haystack, "{$stem}: mentions '{$forbidden}'" );
			}
		}
	}

	public function test_the_end_of_year_withdrawal_says_when_access_ends(): void {
		// The whole point of this email: the family keeps its access until the year ends. Losing
		// that sentence would turn an explanation into a bare notification.
		$default = ANPA_Socios_Email_Template_Defaults::load( 'member_cancellation_end_of_year' );

		foreach ( array( $default['body_html'], $default['body_text'] ) as $body ) {
			$this->assertStringContainsString( 'curso escolar', $body );
			$this->assertStringContainsString( 'mantés o acceso', $body );
		}
	}

	// ── Grupo: matrículas ────────────────────────────────────────────────

	public function test_the_enrollment_group_is_complete(): void {
		foreach (
			array(
				'school_year_enrollment_open',
				'enrollment_received_pending_group',
				'enrollment_waitlist_capacity',
				'enrollment_waitlist_next_term',
			) as $stem
		) {
			$this->assertTrue( ANPA_Socios_Email_Template_Defaults::exists( $stem ), "{$stem} not shipped" );
			$this->assertContains( $stem, $this->new_stems(), "{$stem} is not treated as new content" );
		}
	}

	public function test_no_enrollment_email_confirms_a_place_it_has_not_got(): void {
		// The single most expensive mistake this group can make: a family that reads "matriculado"
		// in a receipt stops watching for the real confirmation, and finds out in October.
		foreach (
			array(
				'school_year_enrollment_open',
				'enrollment_received_pending_group',
				'enrollment_waitlist_capacity',
				'enrollment_waitlist_next_term',
			) as $stem
		) {
			$default  = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$haystack = mb_strtolower( $default['subject'] . ' ' . $default['body_html'] . ' ' . $default['body_text'] );

			foreach ( array( 'praza confirmada', 'matrícula confirmada', 'queda matriculado', 'xa está matriculado' ) as $claim ) {
				$this->assertStringNotContainsString( $claim, $haystack, "{$stem}: claims '{$claim}'" );
			}
		}
	}

	public function test_the_pending_enrollment_emails_say_the_request_is_not_a_place_yet(): void {
		// Stated as a property over accepted wordings rather than one exact sentence: the point is
		// that the disclaimer EXISTS in both channels, not that both emails phrase it identically.
		$accepted = array(
			'non confirma a praza',
			'non supón a confirmación da praza',
			'non supón a confirmación definitiva da praza',
		);

		foreach ( array( 'school_year_enrollment_open', 'enrollment_received_pending_group' ) as $stem ) {
			$default = ANPA_Socios_Email_Template_Defaults::load( $stem );

			foreach ( array( 'body_html', 'body_text' ) as $channel ) {
				$haystack = mb_strtolower( $default[ $channel ] );
				$found    = false;

				foreach ( $accepted as $phrase ) {
					if ( false !== mb_strpos( $haystack, $phrase ) ) {
						$found = true;
						break;
					}
				}

				$this->assertTrue( $found, "{$stem}.{$channel}: no sentence says a request is not yet a place" );
			}
		}
	}

	public function test_the_waitlist_emails_use_the_normalised_term(): void {
		// «lista de agarda» is the project's term for new content. «lista de espera» survives only
		// in the historical parity text and in stored state identifiers, never in new wording.
		foreach ( array( 'enrollment_waitlist_capacity', 'enrollment_waitlist_next_term' ) as $stem ) {
			$default  = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$haystack = mb_strtolower( $default['subject'] . ' ' . $default['body_html'] . ' ' . $default['body_text'] );

			$this->assertStringNotContainsString( 'lista de espera', $haystack, "{$stem}: uses the old term" );
		}
	}

	public function test_the_capacity_waitlist_email_does_not_promise_automatic_entry(): void {
		$default  = ANPA_Socios_Email_Template_Defaults::load( 'enrollment_waitlist_capacity' );
		$haystack = mb_strtolower( $default['body_html'] . ' ' . $default['body_text'] );

		$this->assertStringContainsString( 'non é automática', $haystack );
		$this->assertStringContainsString( 'lista de agarda', $haystack );
	}

	public function test_the_activity_list_is_rendered_as_preformatted_text(): void {
		// The renderer escapes every value, so the list arrives as plain lines. Without
		// `white-space: pre-line` in the template, the HTML channel would run them together into
		// one paragraph and the reader would see a single sentence of activity names.
		$default = ANPA_Socios_Email_Template_Defaults::load( 'school_year_enrollment_open' );

		$this->assertMatchesRegularExpression(
			'/white-space:\s*pre-line[^>]*>\{\{listado_actividades\}\}/',
			$default['body_html'],
			'the activity list is not wrapped in a pre-line block'
		);
		$this->assertStringNotContainsString( '<ul>{{listado_actividades}}', $default['body_html'] );
	}

	public function test_the_next_term_email_does_not_ask_the_family_to_apply_again(): void {
		// A pending request that looks lost gets resubmitted, and a duplicate request is a real
		// administrative cost for the board.
		$default  = ANPA_Socios_Email_Template_Defaults::load( 'enrollment_waitlist_next_term' );
		$haystack = mb_strtolower( $default['body_html'] . ' ' . $default['body_text'] );

		$this->assertStringContainsString( 'non tes que volver presentala', $haystack );
	}

	// ── Grupo: grupos ────────────────────────────────────────────────────

	public function test_the_group_resolution_group_is_complete(): void {
		foreach (
			array(
				'group_created_enrollment_confirmed',
				'group_created_enrollment_waitlisted',
				'group_not_created',
				'group_created_below_minimum',
			) as $stem
		) {
			$this->assertTrue( ANPA_Socios_Email_Template_Defaults::exists( $stem ), "{$stem} not shipped" );
			$this->assertContains( $stem, $this->new_stems(), "{$stem} is not treated as new content" );
		}
	}

	public function test_the_failed_group_email_makes_no_claim_about_charges(): void {
		// The §15 brief contained a sentence about no charge being made. The plugin does not handle
		// charges, so it cannot promise anything about one — not even good news. The recorded
		// correction replaces it with what the plugin does know: the request is closed.
		$default  = ANPA_Socios_Email_Template_Defaults::load( 'group_not_created' );
		$haystack = mb_strtolower( $default['subject'] . ' ' . $default['body_html'] . ' ' . $default['body_text'] );

		$this->assertStringContainsString( 'queda pechada sen matrícula efectiva', $haystack );

		foreach ( array( 'cobro', 'cobrar', 'cargo', 'ningún importe', 'gratuíto' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $haystack, "group_not_created: mentions '{$forbidden}'" );
		}
	}

	public function test_the_two_confirmation_emails_are_distinguishable_in_their_bodies(): void {
		// Same subject on purpose — from the family's point of view the outcome is identical — but
		// the below-minimum body has to explain why the group runs anyway, or the exception looks
		// like an error the next time the minimum is enforced.
		$normal    = ANPA_Socios_Email_Template_Defaults::load( 'group_created_enrollment_confirmed' );
		$exception = ANPA_Socios_Email_Template_Defaults::load( 'group_created_below_minimum' );

		$this->assertSame( $normal['subject'], $exception['subject'], 'the outcome is the same for the reader' );
		$this->assertNotSame( $normal['body_html'], $exception['body_html'] );

		$haystack = mb_strtolower( $exception['body_html'] . ' ' . $exception['body_text'] );
		$this->assertStringContainsString( 'non se acadou o número mínimo', $haystack );
		$this->assertStringContainsString( 'empresa responsable', $haystack );
	}

	public function test_the_confirmation_emails_invite_a_correction(): void {
		// The board finds out about a wrong schedule when a family says so. An email that does not
		// ask is an email that gets no answer.
		foreach ( array( 'group_created_enrollment_confirmed', 'group_created_below_minimum' ) as $stem ) {
			$default  = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$haystack = mb_strtolower( $default['body_html'] . ' ' . $default['body_text'] );

			$this->assertStringContainsString( 'non é correcto', $haystack, "{$stem}: no correction path" );
		}
	}

	public function test_the_group_emails_never_name_another_family(): void {
		// One student per email. A group notice that listed classmates would be a data breach with
		// a friendly tone.
		foreach (
			array(
				'group_created_enrollment_confirmed',
				'group_created_enrollment_waitlisted',
				'group_not_created',
				'group_created_below_minimum',
			) as $stem
		) {
			$default = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$source  = $default['subject'] . "\n" . $default['body_html'] . "\n" . $default['body_text'];

			foreach ( array( 'listado_participantes', 'listado_alumnos', 'nomes dos alumnos' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $source, "{$stem}: would expose other families" );
			}
		}
	}

	// ── Grupo: baixas e cambios ──────────────────────────────────────────

	public function test_the_withdrawal_and_change_group_is_complete(): void {
		foreach ( array( 'activity_cancellation_confirmed', 'activity_change_notice' ) as $stem ) {
			$this->assertTrue( ANPA_Socios_Email_Template_Defaults::exists( $stem ), "{$stem} not shipped" );
			$this->assertContains( $stem, $this->new_stems(), "{$stem} is not treated as new content" );
		}
	}

	public function test_the_confirmed_withdrawal_says_attendance_continues_until_the_effective_date(): void {
		// The question a family actually has: does my child go on Tuesday? A withdrawal notice that
		// only says "approved" gets answered by an email to the board.
		$default  = ANPA_Socios_Email_Template_Defaults::load( 'activity_cancellation_confirmed' );
		$haystack = mb_strtolower( $default['body_html'] . ' ' . $default['body_text'] );

		$this->assertStringContainsString( 'pode seguir asistindo', $haystack );
	}

	public function test_the_change_notice_names_the_activity_in_the_subject(): void {
		// It lands in an inbox next to every other notice. «Cambio importante» alone tells the reader
		// to open it to find out whether it concerns them.
		$default = ANPA_Socios_Email_Template_Defaults::load( 'activity_change_notice' );

		$this->assertStringContainsString( '{{nome_actividade}}', $default['subject'] );
	}

	public function test_the_change_notice_never_leaves_a_label_without_its_value(): void {
		// Each optional fact carries its own sentence inside its own block, so an absent group or an
		// absent reason removes the whole line instead of leaving «Grupo afectado:» hanging.
		$default = ANPA_Socios_Email_Template_Defaults::load( 'activity_change_notice' );

		foreach ( array( 'nome_grupo', 'motivo_cambio', 'data_efectiva', 'nome_alumno' ) as $token ) {
			$this->assertMatchesRegularExpression(
				'/\{\{#' . $token . '\}\}[^{]*\{\{' . $token . '\}\}/u',
				$default['body_text'],
				"activity_change_notice: '{$token}' is printed outside its own block"
			);
		}
	}

	public function test_the_change_reason_is_rendered_as_preformatted_text(): void {
		// Multiline and board-written: without pre-line the paragraphs collapse into one.
		$default = ANPA_Socios_Email_Template_Defaults::load( 'activity_change_notice' );

		$this->assertMatchesRegularExpression(
			'/white-space:\s*pre-line[^>]*>\{\{motivo_cambio\}\}/',
			$default['body_html']
		);
	}

	public function test_the_application_receipt_does_not_promise_approval(): void {
		// It confirms arrival, not acceptance. «Pendente de revisión» is the whole message.
		$default  = ANPA_Socios_Email_Template_Defaults::load( 'member_application_received' );
		$haystack = mb_strtolower( $default['body_html'] . ' ' . $default['body_text'] );

		$this->assertStringContainsString( 'pendente de revisión', $haystack );
		$this->assertStringNotContainsString( 'benvido', $haystack );
		$this->assertStringNotContainsString( 'aprobada correctamente', $haystack );
	}
}
