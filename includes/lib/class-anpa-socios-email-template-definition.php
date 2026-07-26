<?php
/**
 * One template event, as a typed contract (fase36, PR-36s1b).
 *
 * The registry is a public contract, not a loose array of 28 rows: the admin screen,
 * the preview, the validator, the queue provider and the later phases that emit these
 * events all read it. A typed object is what lets a change to the shape fail in CI
 * instead of producing a subtly wrong editor three phases later.
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
	 * There is deliberately NO payments category. This plugin handles no charges and
	 * no shipped template may claim anything about one, so a category that nothing may
	 * legitimately use would only be an invitation to write such a template later.
	 */
	const CATEGORY_MEMBERSHIP     = 'membership';
	const CATEGORY_ACTIVITIES     = 'activities';
	const CATEGORY_COMPANIES      = 'companies';
	const CATEGORY_COMMUNICATIONS = 'communications';
	const CATEGORY_SYSTEM         = 'system';

	/**
	 * Emitter lifecycle.
	 *
	 * `implemented` — shipped code sends it today.
	 * `planned`     — no emitter yet; `introduced_in` names the phase that adds one.
	 *                 Seeded and editable so the board can review the wording early.
	 * `deprecated`  — an emitter existed and no longer should; kept so stored history
	 *                 and any customised wording survive. `deprecated_in` says when.
	 * `internal`    — the plugin reporting on its own operation, never about
	 *                 association business. Never enqueued to families.
	 */
	const EMITTER_IMPLEMENTED = 'implemented';
	const EMITTER_PLANNED     = 'planned';
	const EMITTER_DEPRECATED  = 'deprecated';
	const EMITTER_INTERNAL    = 'internal';

	/**
	 * Who receives it. Kept separate from the emitter lifecycle on purpose: they are
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

	/** @var string One of the EMITTER_* constants. */
	private string $emitter_status;

	/** @var string Phase that owns the emitter, e.g. `live`, `fase37`, `fase39`. */
	private string $introduced_in;

	/** @var string Phase that retired it; empty unless deprecated. */
	private string $deprecated_in;

	/** @var string Stem of the default template files shipped for this event. */
	private string $default_template;

	/** @var array<string,ANPA_Socios_Email_Template_Variable> Canonical token => variable. */
	private array $variables;

	/** @var array<string,string> Alternative spelling => canonical token. */
	private array $aliases;

	/**
	 * Built exclusively by the registry, which is what validates the whole set.
	 *
	 * @param array<string,mixed>                                 $fields    Scalar fields.
	 * @param array<string,ANPA_Socios_Email_Template_Variable>   $variables Canonical token => variable.
	 * @param array<string,string>                                $aliases   Alias => canonical token.
	 */
	public function __construct( array $fields, array $variables, array $aliases ) {
		$this->event_key        = (string) $fields['event_key'];
		$this->display_name     = (string) $fields['display_name'];
		$this->description      = (string) $fields['description'];
		$this->category         = (string) $fields['category'];
		$this->audience         = (string) $fields['audience'];
		$this->emitter_status   = (string) $fields['emitter_status'];
		$this->introduced_in    = (string) $fields['introduced_in'];
		$this->deprecated_in    = (string) $fields['deprecated_in'];
		$this->default_template = (string) $fields['default_template'];
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

	/** @since 1.40.0 @return string[] Every valid emitter status. */
	public static function emitter_statuses(): array {
		return array(
			self::EMITTER_IMPLEMENTED,
			self::EMITTER_PLANNED,
			self::EMITTER_DEPRECATED,
			self::EMITTER_INTERNAL,
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

	/** @since 1.40.0 @return string */
	public function emitter_status(): string {
		return $this->emitter_status;
	}

	/** @since 1.40.0 @return string */
	public function introduced_in(): string {
		return $this->introduced_in;
	}

	/** @since 1.40.0 @return string */
	public function deprecated_in(): string {
		return $this->deprecated_in;
	}

	/** @since 1.40.0 @return string */
	public function default_template(): string {
		return $this->default_template;
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
		return self::EMITTER_IMPLEMENTED === $this->emitter_status;
	}

	/**
	 * @since  1.40.0
	 * @return bool Whether anything may emit it at all.
	 */
	public function is_emittable(): bool {
		return self::EMITTER_DEPRECATED !== $this->emitter_status;
	}

	/**
	 * Flat representation for the admin screen and for assertions.
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
			'emitter_status'   => $this->emitter_status,
			'introduced_in'    => $this->introduced_in,
			'deprecated_in'    => $this->deprecated_in,
			'default_template' => $this->default_template,
			'variables'        => $this->declared_tokens(),
			'aliases'          => $this->aliases,
		);
	}
}
