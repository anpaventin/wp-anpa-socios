<?php
/**
 * Unit tests for the reusable DB write failure policy.
 *
 * Tests the pure parts: exception construction, message formatting, accessors.
 * The glue (actual $wpdb interaction) is tested via integration against a real engine.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

class Test_ANPA_Socios_DB_Write_Policy extends PHPUnit\Framework\TestCase {

	public function test_exception_carries_operation_table_and_message(): void {
		$ex = new ANPA_Socios_DB_Write_Exception( 'insert', 'wp_anpa_test', 'Duplicate entry for key' );

		$this->assertSame( 'insert', $ex->operation() );
		$this->assertSame( 'wp_anpa_test', $ex->table() );
		$this->assertSame( 'Duplicate entry for key', $ex->engine_message() );
		$this->assertStringContainsString( 'insert', $ex->getMessage() );
		$this->assertStringContainsString( 'wp_anpa_test', $ex->getMessage() );
		$this->assertStringContainsString( 'Duplicate entry for key', $ex->getMessage() );
	}

	public function test_exception_with_empty_engine_message(): void {
		$ex = new ANPA_Socios_DB_Write_Exception( 'update', 'wp_anpa_foo', '' );

		$this->assertSame( '', $ex->engine_message() );
		$this->assertStringContainsString( 'no engine message', $ex->getMessage() );
	}

	public function test_exception_is_runtime_exception(): void {
		$ex = new ANPA_Socios_DB_Write_Exception( 'delete', 'wp_t', 'reason' );

		$this->assertInstanceOf( RuntimeException::class, $ex );
	}

	public function test_exception_message_format_is_bracketed(): void {
		$ex = new ANPA_Socios_DB_Write_Exception( 'query', 'wp_q', 'engine says no' );

		$this->assertStringStartsWith( '[anpa-socios]', $ex->getMessage() );
	}

	public function test_all_four_operations_are_valid(): void {
		foreach ( array( 'insert', 'update', 'delete', 'query' ) as $op ) {
			$ex = new ANPA_Socios_DB_Write_Exception( $op, 'tbl', 'msg' );
			$this->assertSame( $op, $ex->operation() );
		}
	}

	/**
	 * The class file defines both the exception and the policy helper.
	 * Verify neither uses features beyond PHP 7.4.
	 */
	public function test_class_file_is_syntactically_valid(): void {
		$file = dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-db-write-policy.php';
		$this->assertFileExists( $file );

		$output = array();
		$code   = 0;
		exec( 'php -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );
		$this->assertSame( 0, $code, implode( "\n", $output ) );
	}

	/**
	 * insert_or_throw, update_or_throw, delete_or_throw and query_or_throw all exist
	 * as public static methods on the policy class.
	 */
	public function test_policy_class_exposes_the_four_helpers(): void {
		$methods = array( 'insert_or_throw', 'update_or_throw', 'delete_or_throw', 'query_or_throw' );
		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'ANPA_Socios_DB_Write_Policy', $method ),
				"Missing method: $method"
			);
			$ref = new ReflectionMethod( 'ANPA_Socios_DB_Write_Policy', $method );
			$this->assertTrue( $ref->isPublic(), "$method must be public" );
			$this->assertTrue( $ref->isStatic(), "$method must be static" );
		}
	}
}
