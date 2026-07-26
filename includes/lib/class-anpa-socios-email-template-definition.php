<?php
/**
 * One template event, as a typed contract (fase36, PR-36s1b).
 *
 * The registry is a public contract, not a loose array of rows: the admin screen, the
 * preview, the validator, the queue provider and every later phase that emits an event
 * all read it. A typed object is what lets a change to the shape fail in CI instead of
 * producing a subtly wrong editor three phases later.
 *
 * THERE IS NO `emitter_status` FIELD. It was declared once and has been removed as
 * redundant: `implemented` versus `planned` is exactly "is the owning phase live",
 * `deprecated` is exactly "a retirement phase is set", and `internal` was already
 * fully expressed by `category = system` plus `audience = board`. A stored copy of a
 * derivable fact is a copy that will eventually disagree with the fact.
 *
 * Pure: no WordPress, no database, no clock. Immutable once built.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Definition {

	/**
	 * Categories, used to group the editor's list.
	 *
	 * There is deliberately NO payments category. This plugin handles no charges and no
	 * shipped template may claim anything about one, so a category that nothing may
	 * legitimately use would only be an invitation to write such a template later.
	 */
	const CATEGORY_MEMBERSHIP     = 'membership';
	const CATEGORY_ACTIVITIES     = 'activities';
	const CATEGORY_COMPANIES      = 'companies';
	const CATEGORY_COMMUNICATIONS = 'communications';
	const CATEGORY_SYSTEM         = 'system';

	/**
	 * Who receives it. Kept separate from the owning phase on purpose: they are
	 * orthogonal, and conflating them is how a board-only notice ends up in a family's
	 * inbox.
	 */
	const AUDIENCE_FAMILY  = 'family';
	const AUDIENCE_BOARD   = 'board';
	const AUDIENCE_COMPANY = 'company';

	/** @var string Immutable technical key, English, e.g. `member_application_approved`. */
	private string $event_key;

	/** @var string Galician name shown in the editor's list. */
	private string $display_name;

	/** @var string What this email is for, in one or two sentences. */
	private string $description;

	/** @var string One of the CATEGORY_* constants. */
	private string $category;

	/** @var string One of the AUDIENCE_* constants. */
	private string $audience;

	/** @var ANPA_Socios_Email_Template_Phase Phase that owns the emitter. */
	private ANPA_Socios_Email_Template_Phase $phase;

	/** @var ANPA_Socios_Email_Template_Phase|null Phase that retired it, if any. */
	private ?ANPA_Socios_Email_Template_Phase $retired_in;

	/** @var string Stem of the default template files shipped for this event. */
	private string $default_template;

	/**
	 * The pre-fase36 emitter this event replaces, e.g. `enviar_aprobacion` or
	 * `enviar_codigo_alta`. Also the stem of its golden file, which is what lets a test
	 * prove the oracle and the registry describe the same set of live emails. Empty for
	 * events whose emitter is not live yet.
	 *
	 * @internal MIGRATION FIELD, NOT DOMAIN. It exists only while the hardcoded engine
	 *           and the template engine coexist. Once every send goes through the
	 *           registry and `ANPA_Socios_Email::enviar_*` no longer exists, this field,
	 *           its validation, its uniqueness check and `Set::legacy_emitters()` must be
	 *           removed in a model migration. It is recorded here so that in three years
	 *           nobody reads it as a permanent part of the domain and starts populating
	 *           it for events that never had a legacy emitter.
	 *
	 * @var string
	 */
	private string $legacy_emitter;

	/** @var array<string,ANPA_Socios_Email_Template_Variable> Canonical token => variable. */
	private array $variables;

	/** @var array<string,string> Alternative spelling => canonical token. */
	private array $aliases;

	/**
	 * Built exclusively by the registry, which is what validates the whole set.
	 *
	 * @param array<string,mixed>                               $fields    Scalar fields.
	 * @param array<string,ANPA_Socios_Email_Template_Variable> $variables Canonical token => variable.
	 * @param array<string,string>                              $aliases   Alias => canonical token.
	 */
	public function __construct( array $fields, array $variables, array $aliases ) {
		$this->event_key        = (string) $fields['event_key'];
		$this->display_name     = (string) $fields['display_name'];
		$this->description      = (string) $fields['description'];
		$this->category         = (string) $fields['category'];
		$this->audience         = (string) $fields['audience'];
		$this->phase            = $fields['phase'];
		$this->retired_in       = $fields['retired_in'];
		$this->default_template = (string) $fields['default_template'];
		$this->legacy_emitter   = (string) $fields['legacy_emitter'];
		$this->variables        = $variables;
		$this->aliases          = $aliases;
	}

	/** @since 1.40.0 @return string[] Every valid category. */
	public static function categories(): array {
		return array(
			self::CATEGORY_MEMBERSHIP,
			self::CATEGORY_ACTIVITIES,
			self::CATEGORY_COMPANIES,
			self::CATEGORY_COMMUNICATIONS,
			self::CATEGORY_SYSTEM,
		);
	}

	/** @since 1.40.0 @return string[] Every valid audience. */
	public static function audiences(): array {
		return array(
			self::AUDIENCE_FAMILY,
			self::AUDIENCE_BOARD,
			self::AUDIENCE_COMPANY,
		);
	}

	/** @since 1.40.0 @return string */
	public function event_key(): string {
		return $this->event_key;
	}

	/** @since 1.40.0 @return string */
	public function display_name(): string {
		return $this->display_name;
	}

	/** @since 1.40.0 @return string */
	public function description(): string {
		return $this->description;
	}

	/** @since 1.40.0 @return string */
	public function category(): string {
		return $this->category;
	}

	/** @since 1.40.0 @return string */
	public function audience(): string {
		return $this->audience;
	}

	/** @since 1.40.0 @return ANPA_Socios_Email_Template_Phase */
	public function phase(): ANPA_Socios_Email_Template_Phase {
		return $this->phase;
	}

	/** @since 1.40.0 @return ANPA_Socios_Email_Template_Phase|null */
	public function retired_in(): ?ANPA_Socios_Email_Template_Phase {
		return $this->retired_in;
	}

	/** @since 1.40.0 @return string */
	public function default_template(): string {
		return $this->default_template;
	}

	/**
	 * @internal Migration-scoped; see the property docblock. Removed once
	 *           `ANPA_Socios_Email::enviar_*` no longer exists.
	 * @since    1.40.0
	 * @return   string Empty when the emitter is not live yet.
	 */
	public function legacy_emitter(): string {
		return $this->legacy_emitter;
	}

	/**
	 * @since  1.40.0
	 * @return array<string,ANPA_Socios_Email_Template_Variable> Canonical token => variable.
	 */
	public function variables(): array {
		return $this->variables;
	}

	/**
	 * @since  1.40.0
	 * @return array<string,string> Alternative spelling => canonical token.
	 */
	public function aliases(): array {
		return $this->aliases;
	}

	/**
	 * Tokens in the shape the renderer expects for its declared-token check.
	 *
	 * @since  1.40.0
	 * @return array<string,array<string,mixed>>
	 */
	public function declared_tokens(): array {
		$declared = array();
		foreach ( $this->variables as $token => $variable ) {
			$declared[ $token ] = $variable->descriptor();
		}

		return $declared;
	}

	/**
	 * @since  1.40.0
	 * @return string[] Tokens a template for this event must use.
	 */
	public function required_tokens(): array {
		$required = array();
		foreach ( $this->variables as $token => $variable ) {
			if ( $variable->is_required() ) {
				$required[] = $token;
			}
		}

		return $required;
	}

	/**
	 * Preview context, derived from the declared examples.
	 *
	 * Derived, never declared separately: a second hand-written sample set is a second
	 * thing to keep in sync, and the day it drifts the preview stops showing what the
	 * editor's variable panel promises.
	 *
	 * @since  1.40.0
	 * @return array<string,string> Canonical token => fictitious sample.
	 */
	public function sample_data(): array {
		$sample = array();
		foreach ( $this->variables as $token => $variable ) {
			$sample[ $token ] = $variable->example();
		}

		return $sample;
	}

	/**
	 * @since  1.40.0
	 * @return bool Whether shipped code emits this event today.
	 */
	public function is_live(): bool {
		return $this->phase->is_live() && ! $this->is_retired();
	}

	/**
	 * @since  1.40.0
	 * @return bool Whether it has been retired.
	 */
	public function is_retired(): bool {
		return null !== $this->retired_in;
	}

	/**
	 * @since  1.40.0
	 * @return bool Whether anything may emit it at all.
	 */
	public function is_emittable(): bool {
		return ! $this->is_retired();
	}

	/**
	 * Flat representation for the admin screen, for assertions and for the registry
	 * fingerprint. Declaration order is preserved, because the order the variables are
	 * declared in is the order the editor shows them in.
	 *
	 * @since  1.40.0
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'event_key'        => $this->event_key,
			'display_name'     => $this->display_name,
			'description'      => $this->description,
			'category'         => $this->category,
			'audience'         => $this->audience,
			'phase'            => $this->phase->id(),
			'retired_in'       => null === $this->retired_in ? '' : $this->retired_in->id(),
			'default_template' => $this->default_template,
			'legacy_emitter'   => $this->legacy_emitter,
			'variables'        => $this->declared_tokens(),
			'aliases'          => $this->aliases,
		);
	}
}
