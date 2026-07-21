<?php
/**
 * Pure helpers for the annual school structure snapshot.
 *
 * The class normalises course-level/classroom snapshots and validates annual
 * assignments without touching persistence.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Estrutura_Escolar {
	private const MAX_HORARIOS_COMEDOR = 30;
	private const MAX_CODIGO_LEN = 30;
	private const MAX_AULA_CODIGO_LEN = 20;
	private const MAX_ETIQUETA_LEN = 60;
	private const MAX_HORARIO_NOME_LEN = 80;

	/**
	 * Validates collection-level bulk limits without imposing a level cap.
	 *
	 * @param mixed $niveis   Level rows.
	 * @param mixed $horarios Reusable meal schedules.
	 */
	public static function has_supported_collection_sizes( $niveis, $horarios ): bool {
		return is_array( $niveis )
			&& is_array( $horarios )
			&& count( $horarios ) <= self::MAX_HORARIOS_COMEDOR;
	}

	public static function normalize_snapshot( array $snapshot ): array {
		$curso_escolar = self::normalize_curso_escolar( $snapshot['curso_escolar'] ?? null );
		if ( null === $curso_escolar ) {
			return array();
		}

		$raw_niveis   = $snapshot['niveis'] ?? array();
		$uses_catalog = array_key_exists( 'horarios_comedor', $snapshot );
		$horarios     = array();
		$horarios_by_key = array();
		if ( $uses_catalog ) {
			$normalized_horarios = self::normalize_horarios_comedor( $snapshot['horarios_comedor'] );
			if ( null === $normalized_horarios ) {
				return array();
			}
			$horarios = $normalized_horarios;
			foreach ( $horarios as $horario ) {
				$horarios_by_key[ $horario['key'] ] = $horario;
			}
		}

		if ( is_array( $raw_niveis ) ) {
			foreach ( $raw_niveis as $raw_nivel ) {
				if ( ! is_array( $raw_nivel ) ) {
					continue;
				}
				if ( $uses_catalog ) {
					$key = self::normalize_horario_key( $raw_nivel['horario_comedor_key'] ?? '' );
					if ( null === $key || ( '' !== $key && ! isset( $horarios_by_key[ $key ] ) ) ) {
						return array();
					}
				} elseif ( null === ANPA_Socios_Disponibilidade_Horaria::normalize_interval(
					$raw_nivel['comedor_inicio'] ?? null,
					$raw_nivel['comedor_fin'] ?? null
				) ) {
					return array();
				}
			}
		}

		$niveis = self::normalize_niveis( $raw_niveis, $horarios_by_key, $uses_catalog );

		$out = array(
			'curso_escolar' => $curso_escolar,
			'niveis'        => $niveis,
		);
		if ( $uses_catalog ) {
			$out['horarios_comedor'] = $horarios;
		}

		return $out;
	}

	public static function niveis( array $snapshot ): array {
		$normalized = self::normalize_snapshot( $snapshot );

		return $normalized['niveis'] ?? array();
	}

	public static function aulas( array $snapshot, string $nivel_codigo ): array {
		$nivel = self::find_nivel( self::normalize_snapshot( $snapshot ), $nivel_codigo );

		return $nivel['aulas'] ?? array();
	}

	public static function is_valid_nivel( array $snapshot, string $nivel_codigo ): bool {
		return null !== self::find_nivel( self::normalize_snapshot( $snapshot ), $nivel_codigo );
	}

	public static function is_valid_assignment( array $snapshot, string $curso_escolar, string $nivel_codigo, string $aula_codigo ): bool {
		$normalized = self::normalize_snapshot( $snapshot );
		if ( array() === $normalized ) {
			return false;
		}

		if ( self::normalize_curso_escolar( $curso_escolar ) !== ( $normalized['curso_escolar'] ?? '' ) ) {
			return false;
		}

		$nivel = self::find_nivel( $normalized, $nivel_codigo );
		if ( null === $nivel ) {
			return false;
		}

		return null !== self::find_aula( $nivel, $aula_codigo );
	}

	private static function normalize_curso_escolar( $value ): ?string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}

		$curso = ANPA_Socios_Normalize::curso_escolar( (string) $value );
		if ( null === $curso || '' === $curso ) {
			return null;
		}

		return $curso;
	}

	private static function normalize_horarios_comedor( $value ): ?array {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_HORARIOS_COMEDOR ) {
			return null;
		}

		$out = array();
		$seen_keys = array();
		$seen_windows = array();
		foreach ( $value as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}
			$key = self::normalize_horario_key( $row['key'] ?? ( 'novo-' . $index ) );
			$nome = self::normalize_label( $row['nome'] ?? null, self::MAX_HORARIO_NOME_LEN );
			$orde = self::normalize_order( $row['orde'] ?? null );
			$interval = ANPA_Socios_Disponibilidade_Horaria::normalize_interval( $row['inicio'] ?? null, $row['fin'] ?? null );
			if ( null === $key || '' === $key || null === $nome || null === $orde || null === $interval || array() === $interval ) {
				return null;
			}
			$window = $interval['inicio'] . '-' . $interval['fin'];
			if ( isset( $seen_keys[ $key ] ) || isset( $seen_windows[ $window ] ) ) {
				return null;
			}
			$seen_keys[ $key ] = true;
			$seen_windows[ $window ] = true;
			$out[] = array(
				'key'    => $key,
				'id'     => max( 0, (int) ( $row['id'] ?? 0 ) ),
				'nome'   => $nome,
				'inicio' => $interval['inicio'],
				'fin'    => $interval['fin'],
				'orde'   => $orde,
			);
		}

		usort( $out, static function ( array $left, array $right ): int {
			if ( $left['orde'] === $right['orde'] ) {
				return strcmp( $left['key'], $right['key'] );
			}
			return $left['orde'] <=> $right['orde'];
		} );

		return $out;
	}

	private static function normalize_horario_key( $value ): ?string {
		$key = trim( (string) $value );
		if ( '' === $key ) {
			return '';
		}

		return preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $key ) ? strtolower( $key ) : null;
	}

	private static function normalize_niveis( $value, array $horarios_by_key = array(), bool $uses_catalog = false ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();
		foreach ( $value as $row ) {
			$normalized = self::normalize_nivel_row( $row, $horarios_by_key, $uses_catalog );
			if ( null === $normalized ) {
				continue;
			}
			$rows[] = $normalized;
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				if ( $left['orde'] === $right['orde'] ) {
					return strcmp( $left['codigo'], $right['codigo'] );
				}

				return $left['orde'] <=> $right['orde'];
			}
		);

		$seen = array();
		$out  = array();
		foreach ( $rows as $row ) {
			if ( isset( $seen[ $row['codigo'] ] ) ) {
				continue;
			}
			$seen[ $row['codigo'] ] = true;
			$out[] = $row;
		}

		return $out;
	}

	private static function normalize_nivel_row( $row, array $horarios_by_key = array(), bool $uses_catalog = false ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$codigo = self::normalize_code( $row['codigo'] ?? ( $row['nivel_codigo'] ?? null ), self::MAX_CODIGO_LEN );
		$etiqueta = self::normalize_label( $row['etiqueta'] ?? ( $row['label'] ?? null ), self::MAX_ETIQUETA_LEN );
		$orde = self::normalize_order( $row['orde'] ?? ( $row['order'] ?? null ) );
		if ( null === $codigo || null === $etiqueta || null === $orde ) {
			return null;
		}

		$horario_key = '';
		if ( $uses_catalog ) {
			$horario_key = self::normalize_horario_key( $row['horario_comedor_key'] ?? '' );
			if ( null === $horario_key || ( '' !== $horario_key && ! isset( $horarios_by_key[ $horario_key ] ) ) ) {
				return null;
			}
			$comedor = '' !== $horario_key
				? array( 'inicio' => $horarios_by_key[ $horario_key ]['inicio'], 'fin' => $horarios_by_key[ $horario_key ]['fin'] )
				: array();
		} else {
			$comedor = ANPA_Socios_Disponibilidade_Horaria::normalize_interval(
				$row['comedor_inicio'] ?? null,
				$row['comedor_fin'] ?? null
			);
			if ( null === $comedor ) {
				return null;
			}
		}

		return array(
			'codigo'         => $codigo,
			'etiqueta'       => $etiqueta,
			'orde'           => $orde,
			'comedor_inicio' => $comedor['inicio'] ?? null,
			'comedor_fin'    => $comedor['fin'] ?? null,
			'horario_comedor_key' => $horario_key,
			'aulas'          => self::normalize_aulas( $row['aulas'] ?? array() ),
		);
	}

	private static function normalize_aulas( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();
		foreach ( $value as $row ) {
			$normalized = self::normalize_aula_row( $row );
			if ( null === $normalized ) {
				continue;
			}
			$rows[] = $normalized;
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				if ( $left['orde'] === $right['orde'] ) {
					return strcmp( $left['codigo'], $right['codigo'] );
				}

				return $left['orde'] <=> $right['orde'];
			}
		);

		$seen = array();
		$out  = array();
		foreach ( $rows as $row ) {
			if ( isset( $seen[ $row['codigo'] ] ) ) {
				continue;
			}
			$seen[ $row['codigo'] ] = true;
			$out[] = $row;
		}

		return $out;
	}

	private static function normalize_aula_row( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$codigo = self::normalize_code( $row['codigo'] ?? ( $row['aula_codigo'] ?? null ), self::MAX_AULA_CODIGO_LEN );
		$etiqueta = self::normalize_label( $row['etiqueta'] ?? ( $row['label'] ?? null ), self::MAX_ETIQUETA_LEN );
		$orde = self::normalize_order( $row['orde'] ?? ( $row['order'] ?? null ) );
		if ( null === $codigo || null === $etiqueta || null === $orde ) {
			return null;
		}

		return array(
			'codigo'   => $codigo,
			'etiqueta' => $etiqueta,
			'orde'     => $orde,
		);
	}

	private static function normalize_code( $value, int $max_len ): ?string {
		if ( null === $value ) {
			return null;
		}

		$str = preg_replace( '/[\x00-\x1F\x7F]/u', '', trim( (string) $value ) );
		if ( null === $str ) {
			return null;
		}

		$str = strtoupper( $str );
		if ( '' === $str || strlen( $str ) > $max_len ) {
			return null;
		}

		return $str;
	}

	private static function normalize_label( $value, int $max_len ): ?string {
		if ( null === $value ) {
			return null;
		}

		$str = preg_replace( '/[\x00-\x1F\x7F]/u', '', trim( (string) $value ) );
		if ( null === $str || '' === $str || strlen( $str ) > $max_len ) {
			return null;
		}

		return $str;
	}

	private static function normalize_order( $value ): ?int {
		if ( is_int( $value ) ) {
			$orde = $value;
		} elseif ( is_string( $value ) && '' !== trim( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) {
			$orde = (int) trim( $value );
		} else {
			return null;
		}

		return ( $orde > 0 ) ? $orde : null;
	}

	private static function find_nivel( array $snapshot, string $nivel_codigo ): ?array {
		$codigo = self::normalize_code( $nivel_codigo, self::MAX_CODIGO_LEN );
		if ( null === $codigo ) {
			return null;
		}

		foreach ( $snapshot['niveis'] ?? array() as $nivel ) {
			if ( isset( $nivel['codigo'] ) && $nivel['codigo'] === $codigo ) {
				return $nivel;
			}
		}

		return null;
	}

	private static function find_aula( array $nivel, string $aula_codigo ): ?array {
		$codigo = self::normalize_code( $aula_codigo, self::MAX_AULA_CODIGO_LEN );
		if ( null === $codigo ) {
			return null;
		}

		foreach ( $nivel['aulas'] ?? array() as $aula ) {
			if ( isset( $aula['codigo'] ) && $aula['codigo'] === $codigo ) {
				return $aula;
			}
		}

		return null;
	}
}
