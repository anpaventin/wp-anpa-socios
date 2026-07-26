<?php
/**
 * Provenance as a type, not a flag (fase36, PR-36s3).
 *
 * The repository has one path that writes content without sanitising it, because `wp_kses` rewrites
 * CSS and a shipped default must reach storage byte-identical to the file the golden oracle pins.
 * I17 asked the obvious question: what stops a request body from taking that path?
 *
 * The answer must be structural, not a promise. `Packaged_Default` cannot be constructed from content
 * at all — private constructor, single factory that takes an event KEY and reads the versioned file
 * itself. So no controller, REST handler, `$_POST`, Ajax payload, stored row or archived version can
 * produce one, because none of them can produce something the class would accept as content: it does
 * not accept content.
 *
 * These tests assert that shape. If somebody adds a `from_array()` or makes the constructor public,
 * they fail — which is the point. "36s4 will not use it" is a hope; "36s4 cannot use it without
 * changing an interface and breaking a test" is a design.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Provenance extends TestCase {

	private const KEY = 'auth_access_code';

	// ── The packaged default cannot be forged ────────────────────────────

	public function test_the_packaged_default_cannot_be_constructed_directly(): void {
		$constructor = ( new ReflectionClass( ANPA_Socios_Email_Template_Packaged_Default::class ) )->getConstructor();

		$this->assertNotNull( $constructor );
		$this->assertTrue( $constructor->isPrivate(), 'a public constructor would accept content from anywhere' );
	}

	public function test_no_public_factory_accepts_content(): void {
		// THE CENTRAL ASSERTION. Every public entry point must take a key or nothing — never a body,
		// never an array of channels. An array parameter here is a door a request can walk through.
		$class = new ReflectionClass( ANPA_Socios_Email_Template_Packaged_Default::class );

		foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( ! $method->isStatic() ) {
				continue;
			}

			foreach ( $method->getParameters() as $parameter ) {
				$type = $parameter->getType();
				$name = null !== $type && $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

				$this->assertNotSame(
					'array',
					$name,
					"{$method->getName()}() takes an array; a packaged default must never accept content"
				);
			}
		}
	}

	public function test_the_only_public_static_factories_take_an_event_key(): void {
		$class    = new ReflectionClass( ANPA_Socios_Email_Template_Packaged_Default::class );
		$builders = array();

		// getMethods() treats its filter as OR, not AND, so the static check has to be explicit —
		// otherwise this lists every public method and the assertion tests nothing useful.
		foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $method->isStatic() ) {
				$builders[] = $method->getName();
			}
		}

		sort( $builders );
		$this->assertSame( array( 'available', 'for_event' ), $builders, 'the surface that can produce a packaged default grew' );
	}

	public function test_it_has_no_setter_and_no_derivation_from_content(): void {
		$class = new ReflectionClass( ANPA_Socios_Email_Template_Packaged_Default::class );

		foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$name = $method->getName();

			$this->assertStringStartsNotWith( 'set_', $name, "{$name} would let content be replaced after construction" );
			$this->assertStringStartsNotWith( 'with_', $name, "{$name} would let content be replaced after construction" );
			$this->assertNotSame( 'from_array', $name );
			$this->assertNotSame( 'from_request', $name );
			$this->assertNotSame( 'from_row', $name );
		}
	}

	public function test_it_refuses_a_stem_that_is_not_a_registered_event(): void {
		// A file on disk is not membership of the contract.
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'not a registered event' );

		ANPA_Socios_Email_Template_Packaged_Default::for_event( 'evento_inventado' );
	}

	public function test_it_carries_the_shipped_bytes_and_their_digest(): void {
		$packaged = ANPA_Socios_Email_Template_Packaged_Default::for_event( self::KEY );
		$default  = ANPA_Socios_Email_Template_Defaults::load( self::KEY );

		$this->assertSame( $default['subject'], $packaged->subject() );
		$this->assertSame( $default['body_html'], $packaged->body_html() );
		$this->assertSame( $default['body_text'], $packaged->body_text() );
		$this->assertSame(
			ANPA_Socios_Email_Template_Defaults::content_hash( $default['subject'], $default['body_html'], $default['body_text'] ),
			$packaged->hash()
		);
	}

	public function test_every_registered_event_can_produce_a_packaged_default(): void {
		foreach ( ANPA_Socios_Email_Template_Events::set()->keys() as $key ) {
			$this->assertTrue( ANPA_Socios_Email_Template_Packaged_Default::available( $key ), "{$key}" );
			$this->assertSame( $key, ANPA_Socios_Email_Template_Packaged_Default::for_event( $key )->event_key() );
		}
	}

	// ── The untrusted type names where content came from ─────────────────

	public function test_the_custom_type_declares_a_provenance_and_none_of_them_is_trusted(): void {
		$class  = new ReflectionClass( ANPA_Socios_Email_Template_Stored_Custom_Template::class );
		$origins = array();

		foreach ( $class->getConstants() as $name => $value ) {
			if ( 0 === strpos( $name, 'ORIGIN_' ) ) {
				$origins[] = (string) $value;
			}
		}

		sort( $origins );
		$this->assertSame( array( 'archived_version', 'request', 'stored_row' ), $origins );

		// Named after where it came from, never after what may be done with it.
		foreach ( $origins as $origin ) {
			$this->assertStringNotContainsString( 'trust', $origin );
			$this->assertStringNotContainsString( 'safe', $origin );
		}
	}

	public function test_every_factory_of_the_custom_type_records_its_origin(): void {
		$request  = ANPA_Socios_Email_Template_Stored_Custom_Template::from_request( array( 'subject' => 'a' ) );
		$stored   = ANPA_Socios_Email_Template_Stored_Custom_Template::from_stored_row( array( 'subject' => 'a' ) );
		$archived = ANPA_Socios_Email_Template_Stored_Custom_Template::from_archived_version( array( 'subject' => 'a' ) );

		$this->assertSame( ANPA_Socios_Email_Template_Stored_Custom_Template::ORIGIN_REQUEST, $request->origin() );
		$this->assertSame( ANPA_Socios_Email_Template_Stored_Custom_Template::ORIGIN_STORED_ROW, $stored->origin() );
		$this->assertSame( ANPA_Socios_Email_Template_Stored_Custom_Template::ORIGIN_ARCHIVED_VERSION, $archived->origin() );
	}

	public function test_the_custom_type_normalises_line_endings_but_changes_nothing_else(): void {
		// A textarea round trip returns CRLF; that is not an edit. Sanitisation is not done here
		// because it needs wp_kses, and this type must stay usable without WordPress.
		$custom = ANPA_Socios_Email_Template_Stored_Custom_Template::from_request(
			array( 'subject' => 'Asunto', 'body_html' => "<p>a</p>\r\n<p>b</p>", 'body_text' => "a\r\nb" )
		);

		$this->assertSame( "<p>a</p>\n<p>b</p>", $custom->body_html() );
		$this->assertSame( "a\nb", $custom->body_text() );
		$this->assertStringContainsString( '<p>', $custom->body_html(), 'the pure type must not sanitise' );
	}

	public function test_a_request_body_that_equals_the_shipped_default_is_recognised(): void {
		$packaged = ANPA_Socios_Email_Template_Packaged_Default::for_event( self::KEY );
		$same     = ANPA_Socios_Email_Template_Stored_Custom_Template::from_request( $packaged->content() );
		$edited   = ANPA_Socios_Email_Template_Stored_Custom_Template::from_request(
			array( 'subject' => 'Outro', 'body_html' => $packaged->body_html(), 'body_text' => $packaged->body_text() )
		);

		$this->assertTrue( $packaged->matches( $same ) );
		$this->assertFalse( $packaged->matches( $edited ) );
	}

	public function test_matching_takes_the_typed_value_not_an_array(): void {
		// So "did this equal the default?" cannot be asked with a raw request array, which is the shape
		// that would tempt a caller into reusing the answer as permission.
		$parameter = ( new ReflectionMethod( ANPA_Socios_Email_Template_Packaged_Default::class, 'matches' ) )
			->getParameters()[0];

		$type = $parameter->getType();
		$this->assertInstanceOf( ReflectionNamedType::class, $type );
		$this->assertSame( ANPA_Socios_Email_Template_Stored_Custom_Template::class, $type->getName() );
	}

	// ── The repository uses the types, and nothing else ──────────────────

	public function test_the_repository_writes_verbatim_only_from_a_packaged_default(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-email-template-repo.php' );

		// Seeding and restore_default write from the packaged object.
		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Packaged_Default::for_event(', $src );
		$this->assertStringContainsString( '$packaged->body_html()', $src );

		// And they never write the loader's raw array, which would be the same bytes with no type to
		// justify them.
		$this->assertStringNotContainsString( "\$default['body_html']", $src );
	}

	public function test_the_repository_never_accepts_a_trust_flag(): void {
		// The failure mode a boolean invites: the layer that receives untrusted input is the layer that
		// would set it.
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-email-template-repo.php' );

		foreach ( array( '$trusted', 'bool $trust', '$skip_sanitize', '$raw = true', '$verbatim' ) as $flag ) {
			$this->assertStringNotContainsString( $flag, $src, "the repository takes a {$flag} parameter" );
		}
	}

	public function test_every_untrusted_entry_point_is_sanitised(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-email-template-repo.php' );

		// A request body and an archived version both go through the sanitiser.
		$this->assertStringContainsString( 'Stored_Custom_Template::from_request(', $src );
		$this->assertStringContainsString( 'Stored_Custom_Template::from_archived_version(', $src );

		foreach ( array( 'sanitize_subject(', 'sanitize_html(', 'sanitize_text(' ) as $call ) {
			$this->assertGreaterThanOrEqual(
				2,
				substr_count( $src, $call ),
				"{$call} is used once; the archived-version path must sanitise too"
			);
		}
	}

	public function test_the_provenance_types_do_not_touch_wordpress(): void {
		foreach (
			array(
				'class-anpa-socios-email-template-packaged-default.php',
				'class-anpa-socios-email-template-stored-custom-template.php',
			) as $file
		) {
			$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/' . $file );

			// Usages, not mentions: the docblocks name $_POST precisely to explain what cannot reach
			// this type, and a test that banned the word would push that explanation out of the file.
			foreach ( array( 'wp_kses(', 'esc_html(', 'get_option(', '$wpdb', '$_POST[', '$_REQUEST[' ) as $wp ) {
				$this->assertStringNotContainsString( $wp, $src, "{$file} reaches for {$wp}" );
			}
		}
	}
}
