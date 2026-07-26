<?php
/**
 * Loader for the shipped default templates (fase36, PR-36s1c).
 *
 * Defaults live as FILES, not as PHP strings:
 *
 *   templates/<stem>.subject.txt
 *   templates/<stem>.html
 *   templates/<stem>.text
 *
 * Three reasons, all of them about the next five years rather than about today. A reworded
 * paragraph produces a readable diff instead of a wall of concatenated PHP. Translating a
 * template later means adding files, not editing code. And a reviewer can read what a
 * family will receive without parsing string concatenation.
 *
 * Each default carries a `default_version` (declared, bumped by hand when the wording
 * changes) and a `default_sha256` (computed from the files). That makes two questions hash
 * comparisons instead of full-text diffs:
 *
 *   - "has the board customised this template?" → stored hash ≠ shipped hash
 *   - "is a newer default available?"           → stored version < shipped version
 *
 * Pure: no WordPress, no database, no clock. The base directory is injectable so the tests
 * can point it at a fixture set.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Defaults {

	/**
	 * Identifier of the content-hashing scheme, for the same reason the registry
	 * fingerprint carries one: the digest identifies the content *and* how it was reduced.
	 */
	const CONTENT_SCHEME = 'template-sha256-v1';

	/** File suffixes that make up one default. */
	const SUFFIX_SUBJECT = '.subject.txt';
	const SUFFIX_HTML    = '.html';
	const SUFFIX_TEXT    = '.text';

	/**
	 * Declared version per shipped default.
	 *
	 * Hand-maintained and deliberately so: a version is a statement that the wording
	 * changed in a way installations should be told about, which is a judgement, not
	 * something a file hash can decide. The hash answers "is it different"; the version
	 * answers "should we offer an update".
	 *
	 * Bump the entry in the same commit that edits the files.
	 *
	 * @var array<string,int>
	 */
	const VERSIONS = array(
		// The ten transcribed from the golden oracle. Version 1 is the wording production
		// already sends; a bump here would mean deciding to change what families read.
		'auth_access_code'                    => 1,
		'auth_access_code_signup'             => 1,
		'member_application_admin_pending'    => 1,
		'member_application_approved'         => 1,
		'member_application_completed'        => 1,
		'member_application_changes_required' => 1,
		'member_cancellation_admin_notice'    => 1,
		'member_reactivation_admin_notice'    => 1,
		'activity_cancellation_admin_notice'  => 1,
		'waitlist_place_offer'                => 1,

		// NEW CONTENT, not transcriptions. These events have no emitter in production, so
		// there is nothing to be byte-identical to: the wording comes from the §15 source
		// brief and every channel starts as `pending_manual_review`. Version 1 means "first
		// wording shipped", not "approved".
		// Socios e acceso.
		'member_application_received'         => 1,
		'member_cancellation_requested'       => 1,
		'member_cancellation_end_of_year'     => 1,
		// Matrículas.
		'school_year_enrollment_open'         => 1,
		'enrollment_received_pending_group'   => 1,
		'enrollment_waitlist_capacity'        => 1,
		'enrollment_waitlist_next_term'       => 1,
		// Grupos.
		'group_created_enrollment_confirmed'  => 1,
		'group_created_enrollment_waitlisted' => 1,
		'group_not_created'                   => 1,
		'group_created_below_minimum'         => 1,
		// Baixas e cambios.
		'activity_cancellation_confirmed'     => 1,
		'activity_change_notice'              => 1,
		// Lista de agarda.
		'waitlist_place_offer_reminder'       => 1,
		'waitlist_place_accepted'             => 1,
		'waitlist_place_declined'             => 1,
		'waitlist_place_expired'              => 1,
		// Empresas.
		'company_group_confirmed'             => 1,
		'company_notification_accepted_admin_notice' => 1,
		'company_notification_failed_admin_notice'   => 1,
		// Trimestres e campañas.
		'term_end_admin_notice'               => 1,
		'next_term_enrollment_open'           => 1,
		'extracurricular_year_thanks'         => 1,
		// Sistema e administración.
		'pending_action_reminder'             => 1,
		'email_campaign_summary_admin'        => 1,
	);

	/**
	 * @since  1.40.0
	 * @param  string $base_dir Optional override, for tests.
	 * @return string Directory holding the shipped defaults, without a trailing slash.
	 */
	public static function dir( string $base_dir = '' ): string {
		if ( '' !== $base_dir ) {
			return rtrim( $base_dir, '/\\' );
		}

		return dirname( __DIR__, 2 ) . '/templates';
	}

	/**
	 * @since  1.40.0
	 * @param  string $stem     Template stem, e.g. `member_application_approved`.
	 * @param  string $base_dir Optional override, for tests.
	 * @return bool Whether all three files of a default are present.
	 */
	public static function exists( string $stem, string $base_dir = '' ): bool {
		foreach ( self::paths( $stem, $base_dir ) as $path ) {
			if ( ! is_readable( $path ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Stems for which a complete default is shipped, sorted.
	 *
	 * Sorted so the listing is stable and diffs of anything derived from it stay readable.
	 *
	 * @since  1.40.0
	 * @param  string $base_dir Optional override, for tests.
	 * @return string[]
	 */
	public static function stems( string $base_dir = '' ): array {
		$dir   = self::dir( $base_dir );
		$stems = array();

		foreach ( (array) glob( $dir . '/*' . self::SUFFIX_HTML ) as $file ) {
			$stem = basename( (string) $file, self::SUFFIX_HTML );
			if ( self::exists( $stem, $base_dir ) ) {
				$stems[] = $stem;
			}
		}

		sort( $stems, SORT_STRING );

		return $stems;
	}

	/**
	 * Loads one default.
	 *
	 * @since  1.40.0
	 * @param  string $stem     Template stem.
	 * @param  string $base_dir Optional override, for tests.
	 * @return array{subject:string,body_html:string,body_text:string,default_version:int,default_sha256:string}
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the default is missing or malformed.
	 */
	public static function load( string $stem, string $base_dir = '' ): array {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $stem ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "invalid template stem '{$stem}'" );
		}

		$paths = self::paths( $stem, $base_dir );
		$parts = array();

		foreach ( $paths as $part => $path ) {
			if ( ! is_readable( $path ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"default template '{$stem}' is missing its {$part} file"
				);
			}

			$parts[ $part ] = self::read( $stem, $part, $path );
		}

		// A default with an empty body would render an empty email, and a template that
		// refuses to render is better than one that arrives blank.
		if ( '' === trim( $parts['subject'] ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "default template '{$stem}' has an empty subject" );
		}
		if ( '' === trim( $parts['html'] ) || '' === trim( $parts['text'] ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "default template '{$stem}' has an empty body" );
		}

		// The subject is collapsed on load, not merely checked: a newline in a subject can
		// forge headers, so it must be impossible for a shipped file to introduce one.
		$subject = ANPA_Socios_Email_Template_Renderer::collapse_subject( $parts['subject'] );

		return array(
			'subject'         => $subject,
			'body_html'       => $parts['html'],
			'body_text'       => $parts['text'],
			'default_version' => self::version( $stem ),
			'default_sha256'  => self::content_hash( $subject, $parts['html'], $parts['text'] ),
		);
	}

	/**
	 * Content hash of each channel separately.
	 *
	 * Per channel, not combined, because the editorial state is per channel: a reviewer can
	 * approve an HTML body while its plain-text alternative is still a draft, and one shared
	 * hash could not express that one of them changed.
	 *
	 * @since  1.40.0
	 * @param  string $stem     Template stem.
	 * @param  string $base_dir Optional override, for tests.
	 * @return array<string,string> Channel => scheme-qualified hash, empty strings when not shipped.
	 */
	public static function part_hashes( string $stem, string $base_dir = '' ): array {
		$empty = array(
			'subject'    => '',
			'html'       => '',
			'plain_text' => '',
		);

		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $stem ) || ! self::exists( $stem, $base_dir ) ) {
			return $empty;
		}

		$default = self::load( $stem, $base_dir );

		return array(
			'subject'    => self::CONTENT_SCHEME . ':' . hash( 'sha256', self::CONTENT_SCHEME . "\x1f" . $default['subject'] ),
			'html'       => self::CONTENT_SCHEME . ':' . hash( 'sha256', self::CONTENT_SCHEME . "\x1f" . $default['body_html'] ),
			'plain_text' => self::CONTENT_SCHEME . ':' . hash( 'sha256', self::CONTENT_SCHEME . "\x1f" . $default['body_text'] ),
		);
	}

	/**
	 * @since  1.40.0
	 * @param  string $stem Template stem.
	 * @return int Declared version, 1 when not declared.
	 */
	public static function version( string $stem ): int {
		$versions = self::VERSIONS;

		return isset( $versions[ $stem ] ) ? (int) $versions[ $stem ] : 1;
	}

	/**
	 * Scheme-qualified content hash of one template.
	 *
	 * The three parts are joined with a separator that cannot occur in the content, so
	 * moving a sentence from the HTML body to the text body changes the digest instead of
	 * cancelling out.
	 *
	 * @since  1.40.0
	 * @param  string $subject   Collapsed subject.
	 * @param  string $body_html HTML body.
	 * @param  string $body_text Plain-text body.
	 * @return string `<scheme>:<sha256>`.
	 */
	public static function content_hash( string $subject, string $body_html, string $body_text ): string {
		$canonical = implode(
			"\x1f",
			array( self::CONTENT_SCHEME, $subject, $body_html, $body_text )
		);

		return self::CONTENT_SCHEME . ':' . hash( 'sha256', $canonical );
	}

	/**
	 * @since  1.40.0
	 * @param  string $stem     Template stem.
	 * @param  string $base_dir Optional override.
	 * @return array<string,string> Part name => absolute path.
	 */
	private static function paths( string $stem, string $base_dir = '' ): array {
		$dir = self::dir( $base_dir );

		return array(
			'subject' => $dir . '/' . $stem . self::SUFFIX_SUBJECT,
			'html'    => $dir . '/' . $stem . self::SUFFIX_HTML,
			'text'    => $dir . '/' . $stem . self::SUFFIX_TEXT,
		);
	}

	/**
	 * Reads one part, refusing anything that would corrupt the content silently.
	 *
	 * A BOM and invalid UTF-8 are both rejected rather than tolerated. This project has
	 * already had one real accent-corruption incident, and a mangled Galician accent in a
	 * shipped default reaches families before anybody notices.
	 *
	 * @since  1.40.0
	 * @param  string $stem Template stem, for the message.
	 * @param  string $part Part name, for the message.
	 * @param  string $path Absolute path.
	 * @return string
	 * @throws ANPA_Socios_Email_Template_Registry_Error On a BOM or invalid UTF-8.
	 */
	private static function read( string $stem, string $part, string $path ): string {
		$raw = (string) file_get_contents( $path );

		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"default template '{$stem}' {$part} file starts with a UTF-8 BOM"
			);
		}
		if ( ! self::is_valid_utf8( $raw ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"default template '{$stem}' {$part} file is not valid UTF-8"
			);
		}

		// Line endings are normalised so a checkout on Windows cannot change the digest.
		return str_replace( array( "\r\n", "\r" ), "\n", $raw );
	}

	/**
	 * @since  1.40.0
	 * @param  string $value Raw bytes.
	 * @return bool
	 */
	private static function is_valid_utf8( string $value ): bool {
		// preg with /u fails on invalid sequences, which is exactly the check needed and
		// keeps the class free of the mbstring dependency.
		return 1 === preg_match( '//u', $value );
	}
}
