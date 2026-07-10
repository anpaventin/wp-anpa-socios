<?php
/**
 * Verifies that the legacy Master_Auth class and its methods are fully removed.
 *
 * @since  1.32.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Master_Auth_Removed extends TestCase {

	/**
	 * The legacy ANPA_Socios_Master_Auth class must no longer exist.
	 */
	public function test_master_auth_class_does_not_exist(): void {
		$this->assertFalse(
			class_exists( 'ANPA_Socios_Master_Auth', false ),
			'ANPA_Socios_Master_Auth must be removed entirely.'
		);
	}

	/**
	 * Calling set_admin_password must raise a fatal (class not found).
	 * We verify indirectly by asserting the class is gone.
	 */
	public function test_set_admin_password_not_callable(): void {
		$this->assertFalse(
			is_callable( array( 'ANPA_Socios_Master_Auth', 'set_admin_password' ) ),
			'set_admin_password must not be callable after class removal.'
		);
	}

	/**
	 * verify_admin_password must not be callable.
	 */
	public function test_verify_admin_password_not_callable(): void {
		$this->assertFalse(
			is_callable( array( 'ANPA_Socios_Master_Auth', 'verify_admin_password' ) ),
			'verify_admin_password must not be callable after class removal.'
		);
	}

	/**
	 * admin_password_exists must not be callable.
	 */
	public function test_admin_password_exists_not_callable(): void {
		$this->assertFalse(
			is_callable( array( 'ANPA_Socios_Master_Auth', 'admin_password_exists' ) ),
			'admin_password_exists must not be callable after class removal.'
		);
	}

	/**
	 * is_admin_session_authorized must not be callable.
	 */
	public function test_is_admin_session_authorized_not_callable(): void {
		$this->assertFalse(
			is_callable( array( 'ANPA_Socios_Master_Auth', 'is_admin_session_authorized' ) ),
			'is_admin_session_authorized must not be callable after class removal.'
		);
	}
}
