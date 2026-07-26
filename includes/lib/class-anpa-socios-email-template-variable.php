<?php
/**
 * A declared template variable (fase36, PR-36s1b).
 *
 * A variable is NOT a bare string. The admin screen has to show the operator what
 * `{{nome_actividade}}` means and what it will look like, the preview has to fill it
 * with something realistic, and the validator has to know whether a template may
 * leave it out. All of that is one object, declared once, so the label shown in the
 * editor and the value shown in the preview can never drift apart.
 *
 * Pure: no WordPress, no database, no clock. Immutable once built.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Variable {

	/**
	 * Value types. They describe what the emitter supplies, so the editor can warn
	 * before a send instead of after: a `url` rendered into an `href` that is empty
	 * produces a dead link, and a `number` is never escaped the same way as prose.
	 */
	const TYPE_TEXT      = 'text';
	const TYPE_MULTILINE = 'multiline';
	const TYPE_URL       = 'url';
	const TYPE_EMAIL     = 'email';
	const TYPE_NUMBER    = 'number';
	const TYPE_DATE      = 'date';

	/** @var string Canonical token, ASCII, e.g. `nome_actividade`. */
	private string $key;

	/** @var string Short Galician label for the editor's variable panel. */
	private string $label;

	/** @var string One sentence telling the operator what the emitter puts here. */
	private string $description;

	/** @var string Fictitious sample used by the preview. Never real data. */
	private string $example;

	/** @var string One of the TYPE_* constants. */
	private string $type;

	/** @var bool Whether this event's templates must use the variable. */
	private bool $required;

	/** @var bool Whether the variable is available to every event. */
	private bool $global;

	/**
	 * @param string $key         Canonical token.
	 * @param string $label       Editor label.
	 * @param string $description What the emitter supplies.
	 * @param string $example     Fictitious sample.
	 * @param string $type        One of TYPE_*.
	 * @param bool   $required    Whether templates must use it.
	 * @param bool   $global      Whether every event has it.
	 */
	private function __construct(
		string $key,
		string $label,
		string $description,
		string $example,
		string $type,
		bool $required,
		bool $global
	) {
		$this->key         = $key;
		$this->label       = $label;
		$this->description = $description;
		$this->example     = $example;
		$this->type        = $type;
		$this->required    = $required;
		$this->global      = $global;
	}

	/**
	 * Builds a variable from its dictionary entry.
	 *
	 * Every problem is raised here, not tolerated: a variable with no example would
	 * silently produce an empty preview, and an operator who cannot see what a token
	 * looks like will use the wrong one.
	 *
	 * @since  1.40.0
	 * @param  string              $key      Canonical token.
	 * @param  array<string,mixed> $spec     Dictionary entry: label, description, example, type.
	 * @param  bool                $required Whether this event requires it.
	 * @param  bool                $global   Whether it is available to every event.
	 * @return self
	 * @throws ANPA_Socios_Email_Template_Registry_Error On any invalid field.
	 */
	public static function from_spec( string $key, array $spec, bool $required, bool $global = false ): self {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"variable key '{$key}' must be lowercase ASCII (accented spellings belong in the alias table)"
			);
		}

		$label       = trim( (string) ( $spec['label'] ?? '' ) );
		$description = trim( (string) ( $spec['description'] ?? '' ) );
		$example     = trim( (string) ( $spec['example'] ?? '' ) );
		$type        = (string) ( $spec['type'] ?? self::TYPE_TEXT );

		if ( '' === $label ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "variable '{$key}' has no label" );
		}
		if ( '' === $description ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "variable '{$key}' has no description" );
		}
		if ( '' === $example ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"variable '{$key}' has no example; the preview would render it empty"
			);
		}
		if ( ! in_array( $type, self::types(), true ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "variable '{$key}' has unknown type '{$type}'" );
		}

		return new self( $key, $label, $description, $example, $type, $required, $global );
	}

	/**
	 * @since  1.40.0
	 * @return string[] Every valid type.
	 */
	public static function types(): array {
		return array(
			self::TYPE_TEXT,
			self::TYPE_MULTILINE,
			self::TYPE_URL,
			self::TYPE_EMAIL,
			self::TYPE_NUMBER,
			self::TYPE_DATE,
		);
	}

	/**
	 * The same variable bound to one event's scope.
	 *
	 * Required-ness and globality are properties of the pairing, not of the variable:
	 * `{{nome_actividade}}` is mandatory in an activity notice and merely available
	 * elsewhere. The dictionary therefore stores the wording once and the registry
	 * binds the scope per event.
	 *
	 * @since  1.40.0
	 * @param  bool $required Whether the event requires it.
	 * @param  bool $global   Whether it reaches the event as a global.
	 * @return self
	 */
	public function with_scope( bool $required, bool $global ): self {
		return new self(
			$this->key,
			$this->label,
			$this->description,
			$this->example,
			$this->type,
			$required,
			$global
		);
	}

	/** @since 1.40.0 @return string */
	public function key(): string {
		return $this->key;
	}

	/** @since 1.40.0 @return string */
	public function label(): string {
		return $this->label;
	}

	/** @since 1.40.0 @return string */
	public function description(): string {
		return $this->description;
	}

	/** @since 1.40.0 @return string */
	public function example(): string {
		return $this->example;
	}

	/** @since 1.40.0 @return string */
	public function type(): string {
		return $this->type;
	}

	/** @since 1.40.0 @return bool */
	public function is_required(): bool {
		return $this->required;
	}

	/** @since 1.40.0 @return bool */
	public function is_global(): bool {
		return $this->global;
	}

	/**
	 * The token as the renderer expects to be told about it.
	 *
	 * @since  1.40.0
	 * @return array<string,mixed>
	 */
	public function descriptor(): array {
		return array(
			'label'       => $this->label,
			'description' => $this->description,
			'example'     => $this->example,
			'type'        => $this->type,
			'required'    => $this->required,
			'global'      => $this->global,
		);
	}
}
