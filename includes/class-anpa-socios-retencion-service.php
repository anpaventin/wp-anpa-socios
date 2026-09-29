<?php
/**
 * Data retention job (1.83.0), once a day:
 *
 * 1. Keeps socios.baixa_en in step with estado (a baixa set from any screen gets
 *    its date; a member who came back loses it).
 * 2. Deletes the data of members whose baixa is older than the configured months
 *    (Axustes → Xeral → Mantemento, default 9): the whole family when everyone
 *    in it is past the deadline (same deletion as «Eliminar definitivamente»),
 *    otherwise only that person. Their email is anonymised in the audit log.
 * 3. Deletes audit log rows older than the configured months (default 12).
 *
 * Also used by the alta: a 2nd parent whose email belongs to a member in baixa
 * is deleted first and created again from scratch (purgar_baixa_para_alta).
 *
 * @since   1.83.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retention service.
 *
 * @since 1.83.0
 */
final class ANPA_Socios_Retencion_Service {

	/** WP-Cron hook (daily). */
	const CRON_HOOK = 'anpa_socios_retencion_diaria';

	/** Text left in the audit log instead of a deleted member's email. */
	const ANONIMO = 'eliminado';

	/** Schedules the daily job (idempotent). */
	public static function programar(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Removes the daily job. */
	public static function desprogramar(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * The daily job.
	 *
	 * @return array{socios:int,familias:int,auditoria:int}
	 */
	public static function executar(): array {
		$out = array( 'socios' => 0, 'familias' => 0, 'auditoria' => 0 );
		try {
			self::sincronizar_datas();
			$out = array_merge( $out, self::purgar_baixas() );
			$out['auditoria'] = self::purgar_auditoria();
			if ( $out['socios'] > 0 || $out['auditoria'] > 0 ) {
				ANPA_Socios_Admin_Shared::write_audit_actor( 'system', 'system', 'retencion', sprintf( 'socios:%d/familias:%d/auditoria:%d', $out['socios'], $out['familias'], $out['auditoria'] ), 'retencion_purga' );
			}
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] retention job failed: ' . $e->getMessage() );
		}
		return $out;
	}

	/**
	 * When the data of a member given baixa now will be deleted («20 de xuño de 2027»).
	 *
	 * @return string
	 */
	public static function data_eliminacion_texto(): string {
		$data = ANPA_Socios_Retencion::sumar_meses( current_time( 'mysql' ), ANPA_Socios_Config::meses_retencion_baixa() );
		return ANPA_Socios_Baixa_Socio::remate_texto( substr( $data, 0, 10 ) );
	}

	/**
	 * Alta: the 2nd parent's email belongs to a member in baixa → deleted so the
	 * alta creates them again from scratch. The caller owns the transaction.
	 *
	 * - Member of THIS family (the one coming back): only that person's own data
	 *   (row, sessions, codes; email anonymised in the audit). Children and
	 *   banking stay with the family.
	 * - Member of ANOTHER family where everyone is in baixa: that whole family
	 *   is deleted (nothing is left orphaned).
	 * - Member of another family with someone still active: nothing is touched
	 *   (their family keeps them; the alta leaves the row as it was).
	 *
	 * @param  string $email      2nd parent's email.
	 * @param  int    $familia_id Family of the alta.
	 * @return bool False on a database error.
	 */
	public static function purgar_baixa_para_alta( string $email, int $familia_id = 0 ): bool {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ANPA_Socios_Roles::is_protected_admin( $email, ANPA_Socios_Config::master_email() ) ) {
			return true;
		}
		$soc_t = ANPA_Socios_DB::tabela_socios();
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- locked lookup inside the alta transaction.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, email, familia_id, estado, rol FROM {$soc_t} WHERE email = %s AND estado = 'baixa' AND rol <> 'master' FOR UPDATE", $email ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		if ( ! is_array( $row ) ) {
			return true;
		}
		$familia = (int) ( ! empty( $row['familia_id'] ) ? $row['familia_id'] : $row['id'] );
		if ( $familia_id > 0 && $familia === $familia_id ) {
			return self::borrar_persoa( (int) $row['id'], $email );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- is anyone of that family still a member?
		$activos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$soc_t} WHERE COALESCE(NULLIF(familia_id, 0), id) = %d AND estado <> 'baixa'", $familia ) );
		if ( $activos > 0 ) {
			return true;
		}
		$r = self::borrar_familia( $row, false );
		return $r > 0;
	}

	/** Gives baixa_en to baixas without it; clears it for whoever is no longer in baixa. */
	private static function sincronizar_datas(): void {
		global $wpdb;
		$soc_t = ANPA_Socios_DB::tabela_socios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent bookkeeping, ASCII SQL.
		$wpdb->query( $wpdb->prepare( "UPDATE {$soc_t} SET baixa_en = %s WHERE estado = 'baixa' AND baixa_en IS NULL", current_time( 'mysql' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent bookkeeping, ASCII SQL.
		$wpdb->query( "UPDATE {$soc_t} SET baixa_en = NULL WHERE estado <> 'baixa' AND baixa_en IS NOT NULL" );
	}

	/** @return array{socios:int,familias:int} */
	private static function purgar_baixas(): array {
		global $wpdb;
		$soc_t = ANPA_Socios_DB::tabela_socios();
		$corte = ANPA_Socios_Retencion::sumar_meses( current_time( 'mysql' ), -ANPA_Socios_Config::meses_retencion_baixa() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only candidate list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, familia_id, estado, rol FROM {$soc_t} WHERE estado = 'baixa' AND rol <> 'master' AND baixa_en IS NOT NULL AND baixa_en <= %s ORDER BY id ASC LIMIT 200", $corte ), ARRAY_A );
		$out  = array( 'socios' => 0, 'familias' => 0 );
		$root = ANPA_Socios_Config::master_email();
		foreach ( is_array( $rows ) ? $rows : array() as $socio ) {
			if ( '' !== (string) $socio['email'] && ANPA_Socios_Roles::is_protected_admin( (string) $socio['email'], $root ) ) {
				continue;
			}
			// A member of a family deleted earlier in this loop is already gone.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- per-row recheck.
			if ( 'baixa' !== $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$soc_t} WHERE id = %d", (int) $socio['id'] ) ) ) {
				continue;
			}
			$familia = (int) ( ! empty( $socio['familia_id'] ) ? $socio['familia_id'] : $socio['id'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- family members, ASCII SQL.
			$membros = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, estado, rol, baixa_en FROM {$soc_t} WHERE COALESCE(NULLIF(familia_id, 0), id) = %d", $familia ), ARRAY_A );
			$membros = is_array( $membros ) ? $membros : array();
			$n = 'familia' === ANPA_Socios_Retencion::alcance( $membros, $corte ) ? self::borrar_familia( $socio ) : 0;
			if ( $n > 0 ) {
				$out['socios'] += $n;
				++$out['familias'];
			} elseif ( self::borrar_persoa( (int) $socio['id'], (string) $socio['email'], true ) ) {
				// Only this person (someone in the family is still a member, or the
				// family cannot be deleted as a whole, e.g. it includes an admin).
				++$out['socios'];
			}
		}
		return $out;
	}

	/**
	 * Whole family: same deletion as «Eliminar definitivamente».
	 *
	 * @param  array<string,mixed> $socio       Row (id, email, familia_id, estado, rol).
	 * @param  bool                $transaccion True = own transaction (cron); false = the caller's (alta).
	 * @return int Members deleted (0 when nothing was deleted).
	 */
	private static function borrar_familia( array $socio, bool $transaccion = true ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction around the family deletion.
		if ( $transaccion && false === $wpdb->query( 'START TRANSACTION' ) ) {
			return 0;
		}
		$fin = static function ( bool $ok ) use ( $wpdb, $transaccion ) {
			if ( $transaccion ) {
				$wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
			}
		};
		$ctx = ANPA_Socios_Admin_Eliminar_Handler::load_family_context( $socio, true );
		if ( is_wp_error( $ctx ) || null !== ANPA_Socios_Admin_Eliminar_Handler::validate_family_members( $ctx['members'] ) ) {
			$fin( false );
			return 0;
		}
		foreach ( $ctx['members'] as $m ) {
			if ( 'baixa' !== (string) $m['estado'] ) {
				$fin( false );
				return 0; // someone came back meanwhile.
			}
		}
		if ( ! ANPA_Socios_Admin_Eliminar_Handler::delete_family_context( $ctx ) || ! self::anonimizar( $ctx['emails'] ) ) {
			$fin( false );
			return 0;
		}
		$fin( true );
		return count( $ctx['member_ids'] );
	}

	/**
	 * One person's own data: socio row, area sessions, verification codes; the
	 * email is anonymised in the audit log.
	 *
	 * @param  int    $id          Socio id.
	 * @param  string $email       Socio email.
	 * @param  bool   $transaccion True = open/close its own transaction.
	 * @return bool
	 */
	private static function borrar_persoa( int $id, string $email, bool $transaccion = false ): bool {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction around the deletion.
		if ( $transaccion && false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$ok = true;
		if ( '' !== $email ) {
			$ok = false !== $wpdb->delete( ANPA_Socios_DB::tabela_sesions(), array( 'email' => $email ), array( '%s' ) )
				&& false !== $wpdb->delete( $wpdb->prefix . 'anpa_codigos_verificacion', array( 'email' => $email ), array( '%s' ) )
				&& self::anonimizar( array( $email ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only a member in baixa, never an admin.
		$ok = $ok && 1 === $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . ANPA_Socios_DB::tabela_socios() . " WHERE id = %d AND estado = 'baixa' AND rol <> 'master'", $id ) );
		if ( $transaccion ) {
			$wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
		}
		return $ok;
	}

	/**
	 * Replaces deleted members' emails in the audit log.
	 *
	 * @param  array<int,string> $emails Emails.
	 * @return bool
	 */
	private static function anonimizar( array $emails ): bool {
		global $wpdb;
		$emails = array_values( array_filter( array_map( static function ( $e ) { return strtolower( trim( (string) $e ) ); }, $emails ) ) );
		if ( array() === $emails ) {
			return true;
		}
		$t  = ANPA_Socios_DB::tabela_audit_log();
		$ph = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$a = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET actor_email = %s WHERE LOWER(actor_email) IN ({$ph})", array_merge( array( self::ANONIMO ), $emails ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$b = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET target_id = %s WHERE LOWER(target_id) IN ({$ph})", array_merge( array( self::ANONIMO ), $emails ) ) );
		return false !== $a && false !== $b;
	}

	/**
	 * Audit rows older than the configured months (timestamps are UTC).
	 *
	 * @return int Rows deleted.
	 */
	private static function purgar_auditoria(): int {
		global $wpdb;
		$t     = ANPA_Socios_DB::tabela_audit_log();
		$corte = ANPA_Socios_Retencion::sumar_meses( gmdate( 'Y-m-d H:i:s' ), -ANPA_Socios_Config::meses_retencion_auditoria() );
		$total = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- batched retention delete.
			$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE `timestamp` < %s LIMIT 5000", $corte ) );
			if ( ! is_int( $n ) || $n <= 0 ) {
				break;
			}
			$total += $n;
		}
		return $total;
	}
}
