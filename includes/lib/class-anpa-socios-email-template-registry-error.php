<?php
/**
 * Raised when the template registry is internally inconsistent (fase36, PR-36s1b).
 *
 * This is deliberately NOT a soft warning. A registry with a duplicated event key, an
 * alias pointing nowhere or a variable with no example is a programming error in the
 * plugin itself, discovered at build time by CI, not a runtime condition an operator
 * can fix. Degrading gracefully here would mean shipping an editor that silently
 * offers a token nothing will ever fill.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Registry_Error extends RuntimeException {
}
