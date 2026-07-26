<?php
/**
 * Persistence for email templates (fase36, PR-36s2).
 *
 * Thin `wpdb` glue over `wp_anpa_email_templates` and its version history. Every RULE it enforces
 * comes from somewhere pure: the token vocabulary from the registry, the HTML allowlist from the
 * policy, the content digest and the shipped text from the defaults loader, the subject rule from
 * the frozen renderer. This class decides nothing about wording; it decides when to write.
 *
 * FOUR INVARIANTS, each of which protects something a site would otherwise lose:
 *
 *   1. **Seeding never overwrites.** `seed_missing()` inserts only rows that do not exist. A plugin
 *      update that reseeded would silently replace a board's wording with the shipped text, and the
 *      board would find out from a family.
 *   2. **`is_customised` is COMPUTED, not claimed.** It is the answer to "does the stored content
 *      differ from the shipped default?", derived by comparing digests. A flag set by whoever
 *      happened to call `save()` drifts; an edit that restores the default text by hand honestly
 *      clears it.
 *   3. **Every write archives what it replaces first.** Including a restore, because reverting the
 *      board's wording is itself a change somebody may want back.
 *   4. **A newer shipped default never overwrites a customised row.** It is recorded as available,
 *      so the admin screen can offer it. Informing is not the same as deciding.
 *
 * Migration creates the tables and seeds nothing: a schema step that failed halfway must never
 * leave half a catalogue behind. Seeding is a separate, idempotent call.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Template_Repo {

	/**
	 * Versions kept per template.
	 *
	 * A TECHNICAL GUARD, not an archival policy. It bounds a table that grows with every save while
	 * still covering the realistic "we broke it this afternoon, put this morning's back". The plugin
	 * is not a document management system, and pretending otherwise would promise a retention
	 * guarantee nobody maintains.
	 */
	const HISTORY_LIMIT = 10;

	/** Column width of `subject`. Over this the save is refused, never truncated. */
	const SUBJECT_MAX = 255;

	/** Reasons a version row exists. */
	const REASON_SAVE            = 'save';
	const REASON_RESTORE_DEFAULT = 'restore_default';
	const REASON_RESTORE_VERSION = 'restore_version';

	/**
	 * @internal TESTABILITY SEAM. When true, the write is abandoned after the archive insert so a test
	 * can prove the transaction rolls back instead of leaving an archived version with no matching
	 * change. Production never sets it; a contract test asserts no caller outside the test suite does.
	 *
	 * @var bool
	 */
	public static $fail_after_archive = false;

	/**
	 * Single source of "now" for PHP-side writes.
	 *
	 * @since  1.40.0
	 * @return string 'Y-m-d H:i:s' in UTC.
	 */
	public static function now_utc(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	// ── Reads ───────────────────────────────────────────────────────────

	/**
	 * @since  1.40.0
	 * @param  string $key Template key.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $key ): ?array {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_email_templates();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE template_key = %s", $key ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every stored template, keyed by template key.
	 *
	 * @since  1.40.0
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_email_templates();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only listing.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY template_key", ARRAY_A );

		$by_key = array();
		foreach ( (array) $rows as $row ) {
			$by_key[ (string) $row['template_key'] ] = $row;
		}

		return $by_key;
	}

	/**
	 * @since  1.40.0
	 * @param  string $key Template key.
	 * @return bool
	 */
	public static function exists( string $key ): bool {
		return null !== self::get( $key );
	}

	/**
	 * Version history of one template, newest first.
	 *
	 * @since  1.40.0
	 * @param  string $key   Template key.
	 * @param  int    $limit Rows to return, clamped to the history guard.
	 * @return array<int,array<string,mixed>>
	 */
	public static function versions( string $key, int $limit = self::HISTORY_LIMIT ): array {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_email_template_versions();
		$limit = max( 1, min( self::HISTORY_LIMIT, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- read-only; key bound, limit clamped.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE template_key = %s ORDER BY id DESC LIMIT {$limit}",
				$key
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @since  1.40.0
	 * @param  int $version_id Version row id.
	 * @return array<string,mixed>|null
	 */
	public static function get_version( int $version_id ): ?array {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_email_template_versions();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $version_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Templates whose shipped default is newer than the version they were seeded from.
	 *
	 * Reported, never applied. A customised row is the board's decision; the screen offers the new
	 * default and the board chooses.
	 *
	 * @since  1.40.0
	 * @return array<string,array{stored:int,shipped:int,customised:bool}>
	 */
	public static function outdated(): array {
		$outdated = array();

		foreach ( self::all() as $key => $row ) {
			$shipped = ANPA_Socios_Email_Template_Defaults::version( $key );
			$stored  = (int) $row['default_version'];

			if ( $shipped > $stored ) {
				$outdated[ $key ] = array(
					'stored'     => $stored,
					'shipped'    => $shipped,
					'customised' => 1 === (int) $row['is_customised'],
				);
			}
		}

		return $outdated;
	}

	// ── Seeding ─────────────────────────────────────────────────────────

	/**
	 * Inserts a row for every registered event that has no row yet.
	 *
	 * Idempotent by construction: it writes only what is missing. It NEVER updates, which is what
	 * makes it safe to call on every activation and every upgrade.
	 *
	 * @since  1.40.0
	 * @param  string $actor Who triggered the seeding, for the audit column.
	 * @return array{ok:bool,code:string,inserted:int,skipped:int,missing_defaults:string[]}
	 */
	public static function seed_missing( string $actor = '' ): array {
		$inserted = 0;
		$skipped  = 0;
		$missing  = array();
		$failed   = array();

		// One read for the whole catalogue, not one per event: this runs on an admin request, and 35
		// existence checks to discover that nothing is missing is 35 queries too many.
		$stored = self::all();

		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			$key = (string) $key;

			if ( isset( $stored[ $key ] ) ) {
				++$skipped;
				continue;
			}

			if ( ! ANPA_Socios_Email_Template_Defaults::exists( $key ) ) {
				// A declared event with no shipped default is a packaging bug, not a reason to write
				// an empty template that would render a blank email.
				$missing[] = $key;
				continue;
			}

			$error = self::insert_default( $key, $definition, $actor );
			if ( '' === $error ) {
				++$inserted;
				continue;
			}

			// A REFUSED INSERT MUST NOT BE SILENT. `$wpdb->insert()` returns false and moves on, so a
			// column that is one character too narrow looks exactly like "there was nothing to seed"
			// — which is how a catalogue ends up empty and the first symptom is an email that never
			// arrives.
			$failed[ $key ] = $error;
		}

		$ok = array() === $missing && array() === $failed;

		return array(
			'ok'               => $ok,
			'code'             => $ok ? 'ok' : ( array() !== $missing ? 'missing_defaults' : 'insert_failed' ),
			'inserted'         => $inserted,
			'skipped'          => $skipped,
			'missing_defaults' => $missing,
			'failed'           => $failed,
		);
	}

	/**
	 * @param  string                                $key        Template key.
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @param  string                                $actor      Audit actor.
	 * @return string Empty on success, the engine's error otherwise.
	 */
	private static function insert_default(
		string $key,
		ANPA_Socios_Email_Template_Definition $definition,
		string $actor
	): string {
		global $wpdb;

		// The provenance is the TYPE. This is content that came from a versioned file, loaded by an
		// object that cannot be constructed from a body, so writing it verbatim is justified by
		// construction rather than by a flag somebody set.
		$packaged = ANPA_Socios_Email_Template_Packaged_Default::for_event( $key );

		$wpdb->last_error = '';
		$inserted         = $wpdb->insert(
			ANPA_Socios_DB::tabela_email_templates(),
			array(
				'template_key'    => $key,
				'event_type'      => $key,
				'subject'         => $packaged->subject(),
				'body_html'       => $packaged->body_html(),
				'body_text'       => $packaged->body_text(),
				'is_active'       => 1,
				'is_customised'   => 0,
				'default_version' => $packaged->version(),
				'default_hash'    => $packaged->hash(),
				'content_hash'    => $packaged->hash(),
				'created_at_utc'  => self::now_utc(),
				'updated_by'      => $actor,
			)
		);

		if ( false !== $inserted ) {
			return '';
		}

		$error = trim( (string) $wpdb->last_error );

		return '' === $error ? 'the insert was refused without an engine message' : $error;
	}

	// ── Validation ──────────────────────────────────────────────────────

	/**
	 * The shipped default, when the submitted content IS the shipped default.
	 *
	 * WHY THIS EXISTS. `wp_kses` normalises style attributes — it drops the trailing semicolon and
	 * the spaces after separators — so passing a shipped default through the sanitiser returns
	 * equivalent but not identical bytes. That matters more than it looks: the ten live templates are
	 * pinned byte-exact against the golden oracle, and an operator who opened the editor and pressed
	 * save without typing anything would otherwise mark the row customised and move it off that pin,
	 * for no reason a human could see.
	 *
	 * So content that arrives byte-identical to the repository's own file is stored verbatim: it is
	 * not untrusted input, it is the text that ships with the plugin and is reviewed in git. Anything
	 * else — including a single edited character — goes through the sanitiser.
	 *
	 * @param  string               $key     Template key.
	 * @param  array<string,string> $content Submitted content.
	 * @return array<string,string> The default when it matches, an empty array otherwise.
	 */
	private static function shipped_default(
		string $key,
		ANPA_Socios_Email_Template_Stored_Custom_Template $submitted
	): ?ANPA_Socios_Email_Template_Packaged_Default {
		if ( ! ANPA_Socios_Email_Template_Packaged_Default::available( $key ) ) {
			return null;
		}

		$packaged = ANPA_Socios_Email_Template_Packaged_Default::for_event( $key );

		// The comparison decides nothing about trust: the bytes that get written come from the
		// packaged object either way. The submitted content is only ever used to answer "did anything
		// actually change?", never as a source for the verbatim path.
		return $packaged->matches( $submitted ) ? $packaged : null;
	}

	/**
	 * Checks submitted content before anything is written.
	 *
	 * The vocabulary check is the one that matters: a token the event does not declare renders as
	 * literal braces in somebody's inbox, and the renderer is frozen so it will not rescue the
	 * mistake. Refusing on save is the last point at which a human is still watching.
	 *
	 * @since  1.40.0
	 * @param  string               $key     Template key.
	 * @param  array<string,string> $content Keys: subject, body_html, body_text.
	 * @return array{ok:bool,code:string,undeclared:string[],message:string}
	 */
	public static function validate( string $key, array $content ): array {
		$fail = static function ( string $code, string $message, array $undeclared = array() ): array {
			return array( 'ok' => false, 'code' => $code, 'undeclared' => $undeclared, 'message' => $message );
		};

		try {
			$definition = ANPA_Socios_Email_Template_Events::set()->get( $key );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			return $fail( 'unknown_event', $e->getMessage() );
		}

		$subject = trim( (string) ( $content['subject'] ?? '' ) );
		$html    = (string) ( $content['body_html'] ?? '' );
		$text    = (string) ( $content['body_text'] ?? '' );

		if ( '' === $subject ) {
			return $fail( 'empty_subject', 'a template without a subject would send a blank subject line' );
		}
		if ( mb_strlen( $subject ) > self::SUBJECT_MAX ) {
			// Refused, not truncated: a subject silently cut is a subject nobody proof-read.
			return $fail(
				'subject_too_long',
				sprintf( 'the subject is %d characters and the maximum is %d', mb_strlen( $subject ), self::SUBJECT_MAX )
			);
		}
		if ( '' === trim( $html ) ) {
			return $fail( 'empty_html', 'a template without an HTML body would send an empty email' );
		}
		if ( '' === trim( $text ) ) {
			// The plain-text channel is a channel, not a by-product. An empty one means clients that
			// prefer text get nothing.
			return $fail( 'empty_text', 'a template without a plain-text body leaves text-only clients with nothing' );
		}

		$declared   = $definition->declared_tokens();
		$undeclared = array();
		foreach ( array( $subject, $html, $text ) as $source ) {
			foreach ( ANPA_Socios_Email_Template_Renderer::undeclared_tokens( $source, $declared ) as $token ) {
				$undeclared[ $token ] = true;
			}
		}

		if ( array() !== $undeclared ) {
			return $fail(
				'undeclared_tokens',
				sprintf( "event '%s' does not declare: %s", $key, implode( ', ', array_keys( $undeclared ) ) ),
				array_keys( $undeclared )
			);
		}

		return array( 'ok' => true, 'code' => 'ok', 'undeclared' => array(), 'message' => '' );
	}

	// ── Writes ──────────────────────────────────────────────────────────

	/**
	 * Stores an edited template.
	 *
	 * Order matters and is deliberate: sanitise, validate the SANITISED content, archive the row
	 * being replaced, write, prune. Validating before sanitising would let a token survive
	 * validation and then be removed by the sanitiser, which is the same class of bug as validating
	 * a URL before normalising it.
	 *
	 * @since  1.40.0
	 * @param  string               $key     Template key.
	 * @param  array<string,string> $content Keys: subject, body_html, body_text.
	 * @param  string               $actor   Who saved, for the audit column.
	 * @return array{ok:bool,code:string,message:string,changed:bool,customised:bool,undeclared:string[]}
	 */
	public static function save( string $key, array $content, string $actor = '', string $expected_digest = '' ): array {
		$submitted = ANPA_Socios_Email_Template_Stored_Custom_Template::from_request( $content );
		$packaged  = self::shipped_default( $key, $submitted );

		if ( null !== $packaged ) {
			// The operator saved exactly the shipped text. The bytes written come from the packaged
			// object, not from the request, so the verbatim path is reachable only with content the
			// package itself produced.
			$clean = $packaged->content();
		} else {
			$clean = array(
				'subject'   => ANPA_Socios_Email_Template_Sanitizer::sanitize_subject( $submitted->subject() ),
				'body_html' => ANPA_Socios_Email_Template_Sanitizer::sanitize_html( $submitted->body_html() ),
				'body_text' => ANPA_Socios_Email_Template_Sanitizer::sanitize_text( $submitted->body_text() ),
			);
		}

		$check = self::validate( $key, $clean );
		if ( ! $check['ok'] ) {
			return array(
				'ok'         => false,
				'code'       => $check['code'],
				'message'    => $check['message'],
				'changed'    => false,
				'customised' => false,
				'undeclared' => $check['undeclared'],
			);
		}

		$row = self::get( $key );
		if ( null === $row ) {
			return self::failure( 'not_found', "no stored template for '{$key}'; seed before saving" );
		}

		// OPTIMISTIC CONCURRENCY. Two board members can have the same template open. Last-writer-wins
		// would discard the other's work with no trace and no warning; a lock would leave a template
		// stuck the first time somebody closes a tab. So the caller may state the digest it was editing,
		// and a save whose base has moved is refused with a conflict instead of overwriting silently.
		// The parameter is optional so an unattended caller (seeding, adopting a default) is unaffected,
		// and 36s4 will always pass it.
		if ( '' !== $expected_digest && $expected_digest !== (string) $row['content_hash'] ) {
			return array(
				'ok'         => false,
				'code'       => 'conflict',
				'message'    => 'this template changed since it was opened; reload it and reapply the edit',
				'changed'    => false,
				'customised' => 1 === (int) $row['is_customised'],
				'undeclared' => array(),
			);
		}

		$hash = ANPA_Socios_Email_Template_Defaults::content_hash(
			$clean['subject'],
			$clean['body_html'],
			$clean['body_text']
		);

		if ( $hash === (string) $row['content_hash'] ) {
			// Nothing changed. Archiving here would fill the history with identical rows and push the
			// version somebody wants out of the last ten.
			return array(
				'ok'         => true,
				'code'       => 'unchanged',
				'message'    => '',
				'changed'    => false,
				'customised' => 1 === (int) $row['is_customised'],
				'undeclared' => array(),
			);
		}

		$customised = $hash !== (string) $row['default_hash'];

		$error = self::transactional_write(
			$row,
			self::REASON_SAVE,
			$actor,
			array(
				'subject'        => $clean['subject'],
				'body_html'      => $clean['body_html'],
				'body_text'      => $clean['body_text'],
				'content_hash'   => $hash,
				'is_customised'  => $customised ? 1 : 0,
				'updated_at_utc' => self::now_utc(),
				'updated_by'     => $actor,
			)
		);

		if ( '' !== $error ) {
			return self::failure( 'db_error', $error );
		}

		self::prune( (int) $row['id'] );

		return array(
			'ok'         => true,
			'code'       => 'saved',
			'message'    => '',
			'changed'    => true,
			'customised' => $customised,
			'undeclared' => array(),
		);
	}

	/**
	 * Replaces a template with the shipped default.
	 *
	 * Archived first, with its own reason. Reverting somebody's wording is a change, and a history
	 * that only recorded manual edits would silently lose the moment it happened.
	 *
	 * @since  1.40.0
	 * @param  string $key   Template key.
	 * @param  string $actor Who restored.
	 * @return array{ok:bool,code:string,message:string,changed:bool,customised:bool,undeclared:string[]}
	 */
	public static function restore_default( string $key, string $actor = '' ): array {
		global $wpdb;

		$row = self::get( $key );
		if ( null === $row ) {
			return self::failure( 'not_found', "no stored template for '{$key}'" );
		}
		if ( ! ANPA_Socios_Email_Template_Defaults::exists( $key ) ) {
			return self::failure( 'missing_default', "no shipped default for '{$key}'" );
		}

		// Provenance by type again: the restored bytes come from the package, not from anything a
		// request could have supplied, so they are written without sanitisation by construction.
		$packaged = ANPA_Socios_Email_Template_Packaged_Default::for_event( $key );

		$error = self::transactional_write(
			$row,
			self::REASON_RESTORE_DEFAULT,
			$actor,
			array(
				'subject'         => $packaged->subject(),
				'body_html'       => $packaged->body_html(),
				'body_text'       => $packaged->body_text(),
				'content_hash'    => $packaged->hash(),
				'default_hash'    => $packaged->hash(),
				'default_version' => $packaged->version(),
				'is_customised'   => 0,
				'updated_at_utc'  => self::now_utc(),
				'updated_by'      => $actor,
			)
		);

		if ( '' !== $error ) {
			return self::failure( 'db_error', $error );
		}

		self::prune( (int) $row['id'] );

		return array(
			'ok'         => true,
			'code'       => 'restored_default',
			'message'    => '',
			'changed'    => true,
			'customised' => false,
			'undeclared' => array(),
		);
	}

	/**
	 * Restores an archived version.
	 *
	 * The restore is itself versioned, so a mistaken restore is recoverable. Anything else would
	 * make the history a trap: one wrong click and the content you wanted is the content you just
	 * overwrote without archiving.
	 *
	 * @since  1.40.0
	 * @param  int    $version_id Version row id.
	 * @param  string $actor      Who restored.
	 * @return array{ok:bool,code:string,message:string,changed:bool,customised:bool,undeclared:string[]}
	 */
	public static function restore_version( int $version_id, string $actor = '' ): array {
		global $wpdb;

		$version = self::get_version( $version_id );
		if ( null === $version ) {
			return self::failure( 'version_not_found', 'that version no longer exists' );
		}

		$key = (string) $version['template_key'];
		$row = self::get( $key );
		if ( null === $row ) {
			return self::failure( 'not_found', "no stored template for '{$key}'" );
		}

		// AN ARCHIVED VERSION IS NOT TRUSTED CONTENT. It was stored before today's rules existed, and
		// treating stored bytes as safe because they are stored is how an old hole survives every
		// future hardening. So it re-enters through the untrusted type and is sanitised again.
		$archived = ANPA_Socios_Email_Template_Stored_Custom_Template::from_archived_version( $version );
		$content  = array(
			'subject'   => ANPA_Socios_Email_Template_Sanitizer::sanitize_subject( $archived->subject() ),
			'body_html' => ANPA_Socios_Email_Template_Sanitizer::sanitize_html( $archived->body_html() ),
			'body_text' => ANPA_Socios_Email_Template_Sanitizer::sanitize_text( $archived->body_text() ),
		);

		// It was valid when it was stored, but the event's vocabulary may have changed since.
		// Restoring blindly would put an unrenderable template live.
		$check = self::validate( $key, $content );
		if ( ! $check['ok'] ) {
			return array(
				'ok'         => false,
				'code'       => 'version_no_longer_valid',
				'message'    => $check['message'],
				'changed'    => false,
				'customised' => 1 === (int) $row['is_customised'],
				'undeclared' => $check['undeclared'],
			);
		}

		$hash       = ANPA_Socios_Email_Template_Defaults::content_hash( $content['subject'], $content['body_html'], $content['body_text'] );
		$customised = $hash !== (string) $row['default_hash'];

		$error = self::transactional_write(
			$row,
			self::REASON_RESTORE_VERSION,
			$actor,
			array(
				'subject'        => $content['subject'],
				'body_html'      => $content['body_html'],
				'body_text'      => $content['body_text'],
				'content_hash'   => $hash,
				'is_customised'  => $customised ? 1 : 0,
				'updated_at_utc' => self::now_utc(),
				'updated_by'     => $actor,
			)
		);

		if ( '' !== $error ) {
			return self::failure( 'db_error', $error );
		}

		self::prune( (int) $row['id'] );

		return array(
			'ok'         => true,
			'code'       => 'restored_version',
			'message'    => '',
			'changed'    => true,
			'customised' => $customised,
			'undeclared' => array(),
		);
	}

	/**
	 * Records a newer shipped default WITHOUT touching the stored content.
	 *
	 * Only for rows that are not customised: there the shipped text is what the site wanted, so
	 * adopting the new default is the honest behaviour. A customised row is reported by `outdated()`
	 * and left alone.
	 *
	 * @since  1.40.0
	 * @param  string $actor Who ran the update.
	 * @return array{ok:bool,adopted:string[],reported:string[]}
	 */
	public static function adopt_newer_defaults( string $actor = '' ): array {
		$adopted  = array();
		$reported = array();

		foreach ( self::outdated() as $key => $state ) {
			if ( $state['customised'] ) {
				$reported[] = $key;
				continue;
			}

			$result = self::restore_default( $key, $actor );
			if ( $result['ok'] ) {
				$adopted[] = $key;
			}
		}

		return array( 'ok' => true, 'adopted' => $adopted, 'reported' => $reported );
	}

	// ── History ─────────────────────────────────────────────────────────

	/**
	 * Copies the row being replaced into the history table.
	 *
	 * It stores the OLD state. Storing the new one would make the history a duplicate of the live
	 * row and leave the version somebody wants back unrecorded.
	 *
	 * @param  array<string,mixed> $row    Row about to be overwritten.
	 * @param  string              $reason One of the REASON_* constants.
	 * @param  string              $actor  Who caused the change.
	 * @return bool
	 */
	private static function transactional_write( array $row, string $reason, string $actor, array $columns ): string {
		global $wpdb;

		// ARCHIVE AND WRITE ARE ONE OPERATION. Running the two queries next to each other is not
		// atomicity: a failure between them leaves either an archived version of a change that never
		// happened, or a change with no way back. Both tables are InnoDB, so an explicit transaction
		// is available and is used.
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- literal.
		$wpdb->query( 'START TRANSACTION' );

		try {
			if ( ! self::archive( $row, $reason, $actor ) ) {
				throw new RuntimeException( 'the previous version could not be archived: ' . $wpdb->last_error );
			}

			// @internal Testability seam: proves the rollback, which is otherwise unobservable.
			if ( self::$fail_after_archive ) {
				throw new RuntimeException( 'simulated failure between archive and write' );
			}

			$updated = $wpdb->update(
				ANPA_Socios_DB::tabela_email_templates(),
				$columns,
				array( 'id' => (int) $row['id'] )
			);

			if ( false === $updated ) {
				throw new RuntimeException( 'the template could not be stored: ' . $wpdb->last_error );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- literal.
			$wpdb->query( 'COMMIT' );

			return '';
		} catch ( RuntimeException $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- literal.
			$wpdb->query( 'ROLLBACK' );

			return $e->getMessage();
		}
	}

	/**
	 * Copies the row being replaced into the history table.
	 *
	 * @param  array<string,mixed> $row    Row about to be overwritten.
	 * @param  string              $reason One of the REASON_* constants.
	 * @param  string              $actor  Who caused the change.
	 * @return bool
	 */
	private static function archive( array $row, string $reason, string $actor ): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			ANPA_Socios_DB::tabela_email_template_versions(),
			array(
				'template_id'     => (int) $row['id'],
				'template_key'    => (string) $row['template_key'],
				'subject'         => (string) $row['subject'],
				'body_html'       => (string) $row['body_html'],
				'body_text'       => (string) $row['body_text'],
				'content_hash'    => (string) $row['content_hash'],
				'default_version' => (int) $row['default_version'],
				'was_customised'  => (int) $row['is_customised'],
				'archived_at_utc' => self::now_utc(),
				'archived_by'     => $actor,
				'archived_reason' => $reason,
			)
		);

		return false !== $inserted;
	}

	/**
	 * Keeps the newest HISTORY_LIMIT versions of one template.
	 *
	 * Deletes by explicit id list rather than with a subquery on the same table, because MySQL
	 * refuses `DELETE ... WHERE id NOT IN (SELECT ... FROM the_same_table)` (error 1093).
	 *
	 * @param  int $template_id Live row id.
	 * @return int Rows deleted.
	 */
	private static function prune( int $template_id ): int {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_email_template_versions();

		// "Everything after the newest N" needs an OFFSET, and MySQL has no OFFSET without a LIMIT.
		// The maximum unsigned bigint is the documented idiom for "no upper bound"; it is a literal
		// here rather than a magic constant elsewhere so the intent stays next to the query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- read-only; id bound, offset is a class constant.
		$stale = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE template_id = %d ORDER BY id DESC LIMIT 18446744073709551615 OFFSET " . self::HISTORY_LIMIT,
				$template_id
			)
		);

		if ( ! is_array( $stale ) || array() === $stale ) {
			return 0;
		}

		$ids = implode( ',', array_map( 'intval', $stale ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- ids are cast to int above.
		$deleted = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * @param  string $code    Failure code.
	 * @param  string $message Human message.
	 * @return array{ok:bool,code:string,message:string,changed:bool,customised:bool,undeclared:string[]}
	 */
	private static function failure( string $code, string $message ): array {
		return array(
			'ok'         => false,
			'code'       => $code,
			'message'    => $message,
			'changed'    => false,
			'customised' => false,
			'undeclared' => array(),
		);
	}
}
