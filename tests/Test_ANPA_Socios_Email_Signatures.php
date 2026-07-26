<?php
/**
 * Reflection contract: pins the public signatures of ANPA_Socios_Email::enviar_* methods.
 *
 * "Signatures unchanged" is an automatic assertion, not a claim. If a parameter name,
 * default, type or nullability changes, this test fails before the 13 call sites discover
 * it in production.
 *
 * @group unit
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Signatures extends TestCase {

	/**
	 * The nine public enviar_* methods with their exact signatures.
	 *
	 * Each entry: method name => array of expectations.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function expected_signatures(): array {
		return array(
			'enviar_codigo' => array(
				'visibility'  => 'public',
				'static'      => true,
				'return_type' => 'bool',
				'parameters'  => array(
					array( 'name' => 'email',   'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'codigo',  'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'context', 'type' => 'string', 'optional' => true,  'nullable' => false, 'default' => 'alta' ),
				),
			),
			'enviar_aviso_baixa_socio' => array(
				'visibility'  => 'public',
				'static'      => true,
				'return_type' => 'bool',
				'parameters'  => array(
					array( 'name' => 'email_socio', 'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'nome',        'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'apelidos',    'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
				),
			),
			'enviar_aviso_reactivacion' => array(
				'visibility'  => 'public',
				'static'      => true,
				'return_type' => 'bool',
				'parameters'  => array(
					array( 'name' => 'email_socio', 'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
				),
			),
			'enviar_aviso_baixa_extraescolar' => array(
				'visibility'  => 'public',
				'static'      => true,
				'return_type' => 'bool',
				'parameters'  => array(
					array( 'name' => 'email_socio', 'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'alumno',      'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'actividade',  'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
				),
			),
			'enviar_oferta_extraescolar' => array(
				'visibility'  => 'public',
				'static'      => true,
				'return_type' => 'bool',
				'parameters'  => array(
					array( 'name' => 'email_socio', 'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'actividade',  'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'dias_prazo',  'type' => 'int',    'optional' => false, 'nullable' => false, 'default' => null ),
				),
			),
			'enviar_aviso_pendente_aprobacion' => array(
				'visibility'  => 'public',
				'static'      => true,
				'return_type' => 'bool',
				'parameters'  => array(
					array( 'name' => 'email_socio', 'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'nome',        'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
				),
			),
			'enviar_aprobacion' => array(
				'visibility'  => 'public',
				'static'      => true,
				'return_type' => 'bool',
				'parameters'  => array(
					array( 'name' => 'email_socio', 'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'login_url',   'type' => 'string', 'optional' => true,  'nullable' => false, 'default' => '' ),
				),
			),
			'enviar_benvida_alta' => array(
				'visibility'  => 'public',
				'static'      => true,
				'return_type' => 'bool',
				'parameters'  => array(
					array( 'name' => 'email_socio', 'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
					array( 'name' => 'login_url',   'type' => 'string', 'optional' => true,  'nullable' => false, 'default' => '' ),
				),
			),
			'enviar_rexeitamento' => array(
				'visibility'  => 'public',
				'static'      => true,
				'return_type' => 'bool',
				'parameters'  => array(
					array( 'name' => 'email_socio', 'type' => 'string', 'optional' => false, 'nullable' => false, 'default' => null ),
				),
			),
		);
	}

	public function test_all_nine_public_enviar_methods_exist(): void {
		$class = new ReflectionClass( 'ANPA_Socios_Email' );
		$expected = $this->expected_signatures();

		foreach ( array_keys( $expected ) as $method_name ) {
			$this->assertTrue(
				$class->hasMethod( $method_name ),
				"ANPA_Socios_Email is missing public method {$method_name}"
			);
		}
	}

	public function test_no_extra_public_enviar_methods(): void {
		$class    = new ReflectionClass( 'ANPA_Socios_Email' );
		$expected = array_keys( $this->expected_signatures() );

		$actual = array();
		foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( 0 === strpos( $method->getName(), 'enviar_' ) ) {
				$actual[] = $method->getName();
			}
		}

		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'unexpected public enviar_* methods found' );
	}

	/**
	 * @dataProvider signature_provider
	 */
	public function test_method_signature(
		string $method_name,
		bool $expected_static,
		string $expected_visibility,
		string $expected_return,
		array $expected_params
	): void {
		$ref = new ReflectionMethod( 'ANPA_Socios_Email', $method_name );

		$this->assertSame( $expected_static, $ref->isStatic(), "{$method_name}: static mismatch" );
		$this->assertTrue( $ref->isPublic(), "{$method_name}: must be public" );

		$return = $ref->getReturnType();
		$this->assertNotNull( $return, "{$method_name}: missing return type" );
		$this->assertSame( $expected_return, $return->getName(), "{$method_name}: return type mismatch" );

		$params = $ref->getParameters();
		$this->assertCount(
			count( $expected_params ),
			$params,
			"{$method_name}: parameter count mismatch"
		);

		foreach ( $expected_params as $i => $exp ) {
			$param = $params[ $i ];
			$this->assertSame( $exp['name'], $param->getName(), "{$method_name}: param {$i} name" );

			$type = $param->getType();
			$this->assertNotNull( $type, "{$method_name}: param {$exp['name']} has no type" );
			$this->assertSame( $exp['type'], $type->getName(), "{$method_name}: param {$exp['name']} type" );
			$this->assertSame( $exp['nullable'], $type->allowsNull(), "{$method_name}: param {$exp['name']} nullable" );
			$this->assertSame( $exp['optional'], $param->isOptional(), "{$method_name}: param {$exp['name']} optional" );

			if ( null !== $exp['default'] ) {
				$this->assertTrue( $param->isDefaultValueAvailable(), "{$method_name}: param {$exp['name']} default missing" );
				$this->assertSame( $exp['default'], $param->getDefaultValue(), "{$method_name}: param {$exp['name']} default value" );
			}
		}
	}

	public function signature_provider(): array {
		$cases = array();
		foreach ( $this->expected_signatures() as $method => $sig ) {
			$cases[ $method ] = array(
				$method,
				$sig['static'],
				$sig['visibility'],
				$sig['return_type'],
				$sig['parameters'],
			);
		}
		return $cases;
	}
}
