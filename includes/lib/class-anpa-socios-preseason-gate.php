<?php
/**
 * Pure pre-season access gate decision (fase12).
 *
 * While the current course is in the pre-season (`pendente`) state, only
 * admin/master users may obtain a login/verification code. Everyone else is
 * shown the pre-season notice and receives no code.
 *
 * This is a pre-check placed BEFORE code issuance. It does NOT replace or weaken
 * the session validation invariant in ANPA_Socios_Area_REST::authenticate_area_session.
 *
 * @since  1.18.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Preseason_Gate {

	/**
	 * May this requester be issued a login code right now?
	 *
	 * @param string $estado   Current course lifecycle state.
	 * @param bool   $is_admin Whether the requester is admin/master.
	 * @return bool            True if a code may be sent.
	 */
	public static function code_allowed( string $estado, bool $is_admin ): bool {
		if ( $is_admin ) {
			return true;
		}

		return ANPA_Socios_Season::ESTADO_PENDENTE !== $estado;
	}
}
