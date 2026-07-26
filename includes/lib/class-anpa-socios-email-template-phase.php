<?php
/**
 * The delivery phase that owns a template's emitter (fase36, PR-36s1b).
 *
 * A typed value instead of a bare string. Phase names appear on every one of the
 * catalogued events, so with strings a rename means finding and editing dozens of
 * scattered literals and hoping none was missed; here it is one constant.
 *
 * Not a PHP enum: the plugin declares `Requires PHP: 7.4`, so enums are unavailable.
 * A final class with a private constructor and an interning factory gives the same
 * closed set and the same "unknown value cannot exist" guarantee.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Phase {

	/** Shipped code already sends it. */
	const LIVE = 'live';

	/** Trimester calendar. */
	const FASE34 = 'fase34';

	/** The communications queue itself. */
	const FASE35 = 'fase35';

	/** The template engine (a template about templates would live here). */
	const FASE36 = 'fase36';

	/** Company portal. */
	const FASE37 = 'fase37';

	/** Lifecycle history, export registry, GDPR audit. */
	const FASE38 = 'fase38';

	/** Enrollment, groups, waiting lists, workflows. */
	const FASE39 = 'fase39';

	/** Dashboard, statistics, incidents. */
	const FASE41 = 'fase41';

	/** @var array<string,self> Interned instances, so identity comparison is safe. */
	private static array $instances = array();

	/** @var string */
	private string $id;

	/**
	 * @param string $id Phase identifier.
	 */
	private function __construct( string $id ) {
		$this->id = $id;
	}

	/**
	 * @since  1.40.0
	 * @param  string $id Phase identifier.
	 * @return self
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the phase is not a known one.
	 */
	public static function from( string $id ): self {
		$id = trim( $id );
		if ( ! in_array( $id, self::known(), true ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"unknown delivery phase '{$id}' (add a constant instead of inventing a string)"
			);
		}

		if ( ! isset( self::$instances[ $id ] ) ) {
			self::$instances[ $id ] = new self( $id );
		}

		return self::$instances[ $id ];
	}

	/**
	 * @since  1.40.0
	 * @return string[] Every known phase.
	 */
	public static function known(): array {
		return array(
			self::LIVE,
			self::FASE34,
			self::FASE35,
			self::FASE36,
			self::FASE37,
			self::FASE38,
			self::FASE39,
			self::FASE41,
		);
	}

	/** @since 1.40.0 @return string */
	public function id(): string {
		return $this->id;
	}

	/**
	 * @since  1.40.0
	 * @return bool Whether shipped code already emits events of this phase.
	 */
	public function is_live(): bool {
		return self::LIVE === $this->id;
	}

	/**
	 * @since  1.40.0
	 * @param  self $other Phase to compare with.
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->id === $other->id;
	}
}
