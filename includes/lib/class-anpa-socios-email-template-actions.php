<?php
/**
 * Catalogue of pending-action types (fase36, PR-36s1c).
 *
 * `pending_action_reminder` is generic by design. This is what keeps generic from becoming
 * arbitrary: the emitter names one of a controlled set of actions, and the wording comes from
 * here. A reminder whose body is emitter-supplied prose is a reminder nobody can review,
 * translate or keep consistent.
 *
 * It is a **declared contract, not a private map**. The set changes what a family reads, so it
 * carries its own fingerprint: adding, removing or rewording an action type moves a digest and
 * cannot happen silently.
 *
 * Each entry says more than a label, because the reminder has to answer five questions and the
 * declaration is what guarantees it can: what must be done, about which entity, by when, where,
 * and what happens otherwise.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Actions {

	/** Identifier of the action-catalogue hashing scheme. */
	const FINGERPRINT_SCHEME = 'actions-v1';

	/** Entities a pending action can be about. */
	const ENTITY_ENROLLMENT = 'enrollment';
	const ENTITY_MEMBER     = 'member';
	const ENTITY_WITHDRAWAL = 'withdrawal';
	const ENTITY_COMPANY    = 'company';

	/**
	 * Action type => declaration.
	 *
	 * `requires_deadline` and `requires_link` are enforced, not advisory: an action that only
	 * makes sense with a date must not be renderable without one, and an action the family has to
	 * perform somewhere must not be renderable without saying where.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	const TYPES = array(
		'confirmar_praza'          => array(
			'label'             => 'Confirmar unha praza',
			'description'       => 'Confirmar unha praza ofrecida nunha actividade extraescolar.',
			'entity'            => self::ENTITY_ENROLLMENT,
			'requires_deadline' => true,
			'requires_link'     => true,
			'consequence'       => 'Se non respondes antes desa data, a praza ofrecerase á seguinte familia da lista de agarda.',
			'phase'             => 'fase39',
		),
		'responder_oferta'         => array(
			'label'             => 'Responder a unha oferta de praza',
			'description'       => 'Responder a unha oferta de praza: aceptala ou renunciar a ela.',
			'entity'            => self::ENTITY_ENROLLMENT,
			'requires_deadline' => true,
			'requires_link'     => true,
			'consequence'       => 'Se non recibimos resposta antes desa data, a oferta caduca e a praza pasa á seguinte familia.',
			'phase'             => 'fase39',
		),
		'corrixir_solicitude_alta' => array(
			'label'             => 'Corrixir a solicitude de alta',
			'description'       => 'Corrixir ou completar os datos da solicitude de alta como socio/a.',
			'entity'            => self::ENTITY_MEMBER,
			'requires_deadline' => false,
			'requires_link'     => true,
			'consequence'       => 'Mentres non se corrixan os datos, a solicitude segue pendente e non se pode aprobar.',
			'phase'             => 'fase39',
		),
		'confirmar_datos_familia'  => array(
			'label'             => 'Confirmar os datos da familia',
			'description'       => 'Revisar e confirmar os datos da unidade familiar.',
			'entity'            => self::ENTITY_MEMBER,
			'requires_deadline' => false,
			'requires_link'     => true,
			'consequence'       => 'Se os datos non están ao día, é posible que non poidamos contactar contigo cando faga falta.',
			'phase'             => 'fase39',
		),
		'completar_matricula'      => array(
			'label'             => 'Completar unha solicitude de matrícula',
			'description'       => 'Completar os datos que faltan nunha solicitude de matrícula.',
			'entity'            => self::ENTITY_ENROLLMENT,
			'requires_deadline' => false,
			'requires_link'     => true,
			'consequence'       => 'Unha solicitude incompleta non entra na orde de asignación de prazas.',
			'phase'             => 'fase39',
		),
		'revisar_baixa'            => array(
			'label'             => 'Revisar unha solicitude de baixa',
			'description'       => 'Revisar unha solicitude de baixa pendente de confirmación.',
			'entity'            => self::ENTITY_WITHDRAWAL,
			'requires_deadline' => false,
			'requires_link'     => true,
			'consequence'       => 'Mentres non se revise, a baixa non queda tramitada.',
			'phase'             => 'fase39',
		),
	);

	/**
	 * @since  1.40.0
	 * @return string[] Supported action types, a fresh copy on every call.
	 */
	public static function supported_types(): array {
		return array_keys( self::TYPES );
	}

	/**
	 * @since  1.40.0
	 * @param  string $type Action type.
	 * @return bool
	 */
	public static function supports( string $type ): bool {
		$types = self::TYPES;

		return isset( $types[ $type ] );
	}

	/**
	 * Full declaration of one action type.
	 *
	 * @since  1.40.0
	 * @param  string $type Action type.
	 * @return array<string,mixed> A copy; the caller cannot mutate the catalogue.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown type.
	 */
	public static function declaration( string $type ): array {
		$types = self::TYPES;

		if ( ! isset( $types[ $type ] ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"unknown pending-action type '{$type}'; an action description may never be free text"
			);
		}

		return $types[ $type ];
	}

	/**
	 * @since  1.40.0
	 * @param  string $type Action type.
	 * @return string What the family has to do.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown type.
	 */
	public static function description( string $type ): string {
		return (string) self::declaration( $type )['description'];
	}

	/**
	 * @since  1.40.0
	 * @param  string $type Action type.
	 * @return string What happens if nothing is done.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown type.
	 */
	public static function consequence( string $type ): string {
		return (string) self::declaration( $type )['consequence'];
	}

	/**
	 * @since  1.40.0
	 * @param  string $type Action type.
	 * @return bool Whether the reminder is meaningless without a deadline.
	 */
	public static function requires_deadline( string $type ): bool {
		return (bool) self::declaration( $type )['requires_deadline'];
	}

	/**
	 * @since  1.40.0
	 * @param  string $type Action type.
	 * @return bool Whether the reminder must say where to act.
	 */
	public static function requires_link( string $type ): bool {
		return (bool) self::declaration( $type )['requires_link'];
	}

	/**
	 * Fingerprint of the action catalogue.
	 *
	 * Its own digest, because this set changes the text a family reads but is not part of the
	 * template registry: folding it into the registry fingerprint would make "we reworded a
	 * reminder" indistinguishable from "the template contract changed". Keys are sorted; the
	 * order is displayed nowhere.
	 *
	 * @since  1.40.0
	 * @return string `<scheme>:<sha256>`.
	 */
	public static function fingerprint(): string {
		$types = self::TYPES;
		ksort( $types, SORT_STRING );

		$canonical = (string) json_encode(
			array( self::FINGERPRINT_SCHEME, $types ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return self::FINGERPRINT_SCHEME . ':' . hash( 'sha256', $canonical );
	}
}
