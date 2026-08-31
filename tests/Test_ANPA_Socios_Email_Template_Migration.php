<?php
/**
 * TDD: ANPA_Socios_Email_Template_Migration — activation seed
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-template-migration.php';

final class Test_ANPA_Socios_Email_Template_Migration extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'anpa_socios_email_templates' );
	}

	public function test_class_exists(): void {
		$this->assertTrue(
			class_exists( 'ANPA_Socios_Email_Template_Migration' ),
			'ANPA_Socios_Email_Template_Migration class must exist'
		);
	}

	public function test_seed_on_new_install(): void {
		$this->assertFalse( get_option( 'anpa_socios_email_templates' ), 'Option should not exist initially' );

		$result = ANPA_Socios_Email_Template_Migration::seed_if_needed();

		$this->assertTrue( $result, 'seed_if_needed should return true on new install' );

		$stored = get_option( 'anpa_socios_email_templates' );
		$this->assertIsArray( $stored );
		$this->assertCount( 10, $stored );
		$this->assertArrayHasKey( 'verification_code', $stored );
	}

	public function test_seed_is_idempotent(): void {
		ANPA_Socios_Email_Template_Migration::seed_if_needed();
		$first = get_option( 'anpa_socios_email_templates' );

		$result = ANPA_Socios_Email_Template_Migration::seed_if_needed();

		$this->assertFalse( $result, 'seed_if_needed should return false when option already exists' );
		$second = get_option( 'anpa_socios_email_templates' );
		$this->assertSame( $first, $second, 'Data should not be overwritten' );
	}

	public function test_custom_template_preserved(): void {
		ANPA_Socios_Email_Template_Migration::seed_if_needed();

		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) {
				return true;
			}
		}
		ANPA_Socios_Email_Template_Store::save(
			'verification_code',
			'MI ASUNTO PERSONALIZADO',
			'<p>MI HTML</p>',
			'MI TEXTO'
		);

		ANPA_Socios_Email_Template_Migration::seed_if_needed();

		$stored = get_option( 'anpa_socios_email_templates' );
		$this->assertSame( 'MI ASUNTO PERSONALIZADO', $stored['verification_code']['subject'] );
	}

	public function test_migrate_method(): void {
		$result = ANPA_Socios_Email_Template_Migration::migrate();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'seeded', $result );
		$this->assertArrayHasKey( 'added', $result );
		$this->assertTrue( $result['seeded'] );
	}

	public function test_migrate_is_idempotent(): void {
		$first  = ANPA_Socios_Email_Template_Migration::migrate();
		$second = ANPA_Socios_Email_Template_Migration::migrate();

		$this->assertTrue( $first['seeded'] );
		$this->assertFalse( $second['seeded'] );
	}

	public function test_defaults_are_canonical(): void {
		ANPA_Socios_Email_Template_Migration::seed_if_needed();

		$stored   = get_option( 'anpa_socios_email_templates' );
		$defaults = ANPA_Socios_Email_Template_Store::get_all_defaults();

		$this->assertIsArray( $stored );

		foreach ( $defaults as $id => $default ) {
			$this->assertArrayHasKey( $id, $stored );
			$this->assertSame( $default['subject'], $stored[ $id ]['subject'] );
			$this->assertSame( $default['html'], $stored[ $id ]['html'] );
			$this->assertSame( $default['text'], $stored[ $id ]['text'] );
		}
	}

	public function test_add_new_templates_preserves_custom(): void {
		ANPA_Socios_Email_Template_Migration::seed_if_needed();

		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) {
				return true;
			}
		}
		ANPA_Socios_Email_Template_Store::save( 'verification_code', 'CUSTOM', '<p>C</p>', 'C' );

		$added = ANPA_Socios_Email_Template_Migration::add_new_templates();

		$this->assertEquals( 0, $added, 'No new templates should be added when all exist' );

		$stored = get_option( 'anpa_socios_email_templates' );
		$this->assertSame( 'CUSTOM', $stored['verification_code']['subject'], 'Custom template preserved' );
	}
}
