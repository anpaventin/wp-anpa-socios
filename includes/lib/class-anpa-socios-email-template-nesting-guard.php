<?php
/**
 * Detects nested optional blocks in template content.
 *
 * The renderer resolves `{{#token}}…{{/}}` blocks in ONE non-greedy pass, so an outer
 * opener pairs with the first closer and the orphan `{{/}}` reaches the reader literally.
 * The renderer's own unclosed-block check catches orphan OPENERS but not nested structures.
 *
 * This guard covers the admin write path — the only human-editable surface. A programmatic
 * `Repo::save()` bypass could still store a nested block because the repository is frozen
 * and this check cannot be added inside it. That residual scope is acceptable: programmatic
 * callers are the plugin's own code and are covered by the Content suite; a board member's
 * textarea is not.
 *
 * PURE CLASS: no WordPress functions, no `esc_html`, no `get_option`, no `$wpdb`,
 * no `apply_filters`, no `__()`. Testable without any WP bootstrap.
 *
 * @since  1.48.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Template_Nesting_Guard {

	/**
	 * Pattern for an optional-block opener: {{#token_name}}.
	 *
	 * Matches the same syntax the renderer recognises. Named capture `token` extracts the
	 * token name for the error message.
	 */
	const OPENER_PATTERN = '/\{\{#([a-z][a-z0-9_]*)\}\}/';

	/**
	 * Pattern for an optional-block closer: {{/}} or {{/token_name}}.
	 */
	const CLOSER_PATTERN = '/\{\{\/(?:[a-z][a-z0-9_]*)?\}\}/';

	/**
	 * Checks a single channel body for nested optional blocks.
	 *
	 * A nested block is defined as: an opener appears between an already-open opener and its
	 * closer. Since the renderer resolves non-greedily, the inner opener would never function
	 * as a conditional — it would be treated as literal text BETWEEN the outer opener and the
	 * first closer, and a second closer would appear as literal `{{/}}` in the output.
	 *
	 * @param  string $body Template body (subject, HTML or text channel).
	 * @return array{nested: bool, error: string} `nested` is true when nesting is found;
	 *         `error` carries a human-readable explanation naming the tokens involved.
	 */
	public static function check( string $body ): array {
		// Split the body into a token stream of openers and closers in order of appearance.
		// Each token carries its type (open/close), the token name (for openers) and its offset.
		$tokens = self::tokenize( $body );

		if ( empty( $tokens ) ) {
			return array( 'nested' => false, 'error' => '' );
		}

		// Walk the token stream tracking nesting depth.
		$depth    = 0;
		$stack    = array();

		foreach ( $tokens as $token ) {
			if ( 'open' === $token['type'] ) {
				if ( $depth > 0 ) {
					// This opener is INSIDE another open block — nesting detected.
					$outer = end( $stack );
					return array(
						'nested' => true,
						'error'  => sprintf(
							'Nested optional block: {{#%s}} appears inside {{#%s}}. '
							. 'The renderer does not support nesting; the inner block would not work as a conditional.',
							$token['name'],
							$outer
						),
					);
				}
				$stack[] = $token['name'];
				$depth++;
			} else {
				// Closer — pop one level if open.
				if ( $depth > 0 ) {
					array_pop( $stack );
					$depth--;
				}
				// An unmatched closer at depth 0 is NOT nesting — the renderer's own check
				// catches orphan closers. We only detect nesting here.
			}
		}

		return array( 'nested' => false, 'error' => '' );
	}

	/**
	 * Checks all three channels of a template content array.
	 *
	 * @param  array<string,string> $content Keys: 'subject', 'html', 'text'.
	 * @return array{nested: bool, error: string, channel: string} Additionally reports which
	 *         channel triggered the detection (empty when no nesting).
	 */
	public static function check_content( array $content ): array {
		foreach ( array( 'subject', 'html', 'text' ) as $channel ) {
			if ( ! isset( $content[ $channel ] ) || '' === $content[ $channel ] ) {
				continue;
			}
			$result = self::check( $content[ $channel ] );
			if ( $result['nested'] ) {
				return array(
					'nested'  => true,
					'error'   => $result['error'],
					'channel' => $channel,
				);
			}
		}

		return array( 'nested' => false, 'error' => '', 'channel' => '' );
	}

	/**
	 * Builds an ordered list of openers and closers from a body string.
	 *
	 * @param  string $body Raw template body.
	 * @return array<int,array{type:string,name:string,offset:int}>
	 */
	private static function tokenize( string $body ): array {
		$tokens = array();

		if ( preg_match_all( self::OPENER_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $i => $match ) {
				$tokens[] = array(
					'type'   => 'open',
					'name'   => $matches[1][ $i ][0],
					'offset' => $match[1],
				);
			}
		}

		if ( preg_match_all( self::CLOSER_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$tokens[] = array(
					'type'   => 'close',
					'name'   => '',
					'offset' => $match[1],
				);
			}
		}

		// Sort by offset so the walk follows the textual order.
		usort( $tokens, static function ( array $a, array $b ): int {
			return $a['offset'] - $b['offset'];
		} );

		return $tokens;
	}
}
