<?php
/**
 * Pure batching for mass emails to the families (1.68.0).
 *
 * Every mass email of the plugin follows the same shape: the visible
 * recipient is the junta's own address and the families go in Bcc, in
 * batches of TAMANO_LOTE so no single message exceeds the recipient limit of
 * Gmail (100 via SMTP/API) or of the usual SMTP relays. This class only
 * prepares the batches and summarises the result; the sending lives in
 * ANPA_Socios_Email::enviar_masivo().
 *
 * @since  1.68.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Envio_Masivo {

	/** Recipients per message (Bcc). */
	const TAMANO_LOTE = 50;

	/**
	 * Normalises, validates, dedupes and chunks a list of addresses.
	 *
	 * @param  array<int,mixed> $emails Raw addresses (any scalar; non-strings are dropped).
	 * @param  int              $tamano Batch size; non-positive → TAMANO_LOTE.
	 * @return array<int,array<int,string>> Batches in input order.
	 */
	public static function lotes( array $emails, int $tamano = self::TAMANO_LOTE ): array {
		$tamano = $tamano > 0 ? $tamano : self::TAMANO_LOTE;
		$vistos = array();
		$limpos = array();
		foreach ( $emails as $raw ) {
			if ( ! is_string( $raw ) ) {
				continue;
			}
			$email = strtolower( trim( $raw ) );
			if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) || isset( $vistos[ $email ] ) ) {
				continue;
			}
			$vistos[ $email ] = true;
			$limpos[]         = $email;
		}
		if ( array() === $limpos ) {
			return array();
		}
		return array_chunk( $limpos, $tamano );
	}

	/**
	 * Bcc header for one batch.
	 *
	 * @param  array<int,string> $lote Addresses.
	 * @return string
	 */
	public static function cabeceira_bcc( array $lote ): string {
		return 'Bcc: ' . implode( ', ', $lote );
	}

	/**
	 * Summarises per-batch results.
	 *
	 * @param  array<int,array{ok:bool,n:int}> $resultados One entry per batch.
	 * @return array{lotes:int,enviados:int,fallidos:int,lotes_fallidos:int}
	 */
	public static function resumo( array $resultados ): array {
		$out = array( 'lotes' => 0, 'enviados' => 0, 'fallidos' => 0, 'lotes_fallidos' => 0 );
		foreach ( $resultados as $r ) {
			$n = (int) ( $r['n'] ?? 0 );
			++$out['lotes'];
			if ( ! empty( $r['ok'] ) ) {
				$out['enviados'] += $n;
			} else {
				$out['fallidos'] += $n;
				++$out['lotes_fallidos'];
			}
		}
		return $out;
	}

	/**
	 * Compact ASCII tag for the audit log target: «plantilla:lotes/enviados/fallidos».
	 *
	 * @param  string              $template_id Template id.
	 * @param  array<string,int>   $resumo      Result of resumo().
	 * @return string
	 */
	public static function etiqueta_auditoria( string $template_id, array $resumo ): string {
		return sprintf( '%s:%d/%d/%d', $template_id, (int) ( $resumo['lotes'] ?? 0 ), (int) ( $resumo['enviados'] ?? 0 ), (int) ( $resumo['fallidos'] ?? 0 ) );
	}
}
