<?php
/**
 * 1.92.0: catalogue of the notices the web raises for the junta and the
 * channels each one goes through (email to the junta's inbox, Telegram or
 * both), chosen in Axustes → Xeral → Avisos á xunta.
 *
 * Pure (no WordPress beyond __()). The channels are stored as
 * id => array( 'correo' => bool, 'telegram' => bool ); a notice the option
 * does not mention takes its default. Notices whose email IS the delivery
 * (the start-of-year email to forward, the mass emails in Bcc) always keep
 * the email: only their Telegram line can be switched.
 *
 * @since   1.92.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Junta notices and their channels.
 *
 * @since 1.92.0
 */
final class ANPA_Socios_Avisos_Xunta {

	/** A family signed up and waits for approval. */
	const ALTA_SOCIO = 'alta_socio';

	/** A former member asked to reactivate the account. */
	const REACTIVACION_SOCIO = 'reactivacion_socio';

	/** A member asked to leave the association. */
	const BAIXA_SOCIO = 'baixa_socio';

	/** A family enrolled a pupil in an activity and the request waits for approval. */
	const MATRICULA_PENDENTE = 'matricula_pendente';

	/** A family accepted a waiting-list offer; the junta confirms the place. */
	const OFERTA_ACEPTADA = 'oferta_aceptada';

	/** A family asked to leave an activity. */
	const BAIXA_EXTRAESCOLAR = 'baixa_extraescolar';

	/** Baixa reported by the company: registered, effective, cancelled. */
	const BAIXA_EMPRESA = 'baixa_empresa';

	/** Monday 10:00 summary of what waits in Aprobacións. */
	const RESUMO_SEMANAL = 'resumo_semanal';

	/** Start-of-year email sent to the junta's inbox to forward from Gmail. */
	const INICIO_CURSO = 'inicio_curso';

	/** Automatic notices to the company and the canteen (alta, baixa, change of group). */
	const AVISOS_EMPRESA = 'avisos_empresa';

	/** Mass emails to the families (junta in To:, families in Bcc). */
	const CORREOS_MASIVOS = 'correos_masivos';

	/** Channel: email to the junta's inbox. */
	const CORREO = 'correo';

	/** Channel: Telegram chat of Axustes. */
	const TELEGRAM = 'telegram';

	/** Group of the notices that wait for a junta decision. */
	const GRUPO_APROBACIONS = 'aprobacions';

	/** Group of the notices that only inform. */
	const GRUPO_INFORMACION = 'informacion';

	/** 1.90.0 mode that also copied the start-of-year and mass emails to Telegram. */
	const MODO_ANTIGO_TODO = 'todo';

	/**
	 * Every notice, in the order Axustes shows them.
	 *
	 * 'correo_fixo': the email is the send itself and cannot be switched off.
	 * 'por_defecto': channels before the junta saves the table.
	 *
	 * @return array<string,array{grupo:string,titulo:string,descricion:string,correo_fixo:bool,por_defecto:array{correo:bool,telegram:bool}}>
	 */
	public static function catalogo(): array {
		$ambos = array( self::CORREO => true, self::TELEGRAM => true );
		$so_correo = array( self::CORREO => true, self::TELEGRAM => false );
		return array(
			self::ALTA_SOCIO         => array(
				'grupo'       => self::GRUPO_APROBACIONS,
				'titulo'      => __( 'Novo socio/a pendente de aprobación', 'anpa-socios' ),
				'descricion'  => __( 'Unha familia nova pediu a alta (só se está activada a aprobación de altas).', 'anpa-socios' ),
				'correo_fixo' => false,
				'por_defecto' => $ambos,
			),
			self::REACTIVACION_SOCIO => array(
				'grupo'       => self::GRUPO_APROBACIONS,
				'titulo'      => __( 'Solicitude de reactivación de socio/a', 'anpa-socios' ),
				'descricion'  => __( 'Unha antiga familia socia pediu reactivar a conta.', 'anpa-socios' ),
				'correo_fixo' => false,
				'por_defecto' => $ambos,
			),
			self::BAIXA_SOCIO        => array(
				'grupo'       => self::GRUPO_APROBACIONS,
				'titulo'      => __( 'Solicitude de baixa de socio/a', 'anpa-socios' ),
				'descricion'  => __( 'Unha familia pediu a baixa como socia.', 'anpa-socios' ),
				'correo_fixo' => false,
				'por_defecto' => $ambos,
			),
			self::MATRICULA_PENDENTE => array(
				'grupo'       => self::GRUPO_APROBACIONS,
				'titulo'      => __( 'Nova inscrición nunha extraescolar pendente de aprobación', 'anpa-socios' ),
				'descricion'  => __( 'Unha familia inscribiu a un alumno/a fóra do prazo libre ou nun grupo xa en marcha.', 'anpa-socios' ),
				'correo_fixo' => false,
				'por_defecto' => $ambos,
			),
			self::OFERTA_ACEPTADA    => array(
				'grupo'       => self::GRUPO_APROBACIONS,
				'titulo'      => __( 'Oferta de praza aceptada pendente de confirmar', 'anpa-socios' ),
				'descricion'  => __( 'Unha familia aceptou a praza que se lle ofreceu dende a lista de espera.', 'anpa-socios' ),
				'correo_fixo' => false,
				'por_defecto' => $ambos,
			),
			self::BAIXA_EXTRAESCOLAR => array(
				'grupo'       => self::GRUPO_APROBACIONS,
				'titulo'      => __( 'Solicitude de baixa nunha extraescolar', 'anpa-socios' ),
				'descricion'  => __( 'Unha familia pediu a baixa dunha actividade.', 'anpa-socios' ),
				'correo_fixo' => false,
				'por_defecto' => $ambos,
			),
			self::BAIXA_EMPRESA      => array(
				'grupo'       => self::GRUPO_INFORMACION,
				'titulo'      => __( 'Baixa comunicada pola empresa', 'anpa-socios' ),
				'descricion'  => __( 'Cando se rexistra, cando é efectiva ás 24 horas e cando se anula. O correo é a copia oculta á xunta do que recibe a familia.', 'anpa-socios' ),
				'correo_fixo' => false,
				'por_defecto' => $ambos,
			),
			self::RESUMO_SEMANAL     => array(
				'grupo'       => self::GRUPO_INFORMACION,
				'titulo'      => __( 'Revisión semanal de Xestión (luns ás 10:00)', 'anpa-socios' ),
				'descricion'  => __( 'Só se hai algo pendente en Aprobacións ou a revisión de correos atopa un problema.', 'anpa-socios' ),
				'correo_fixo' => false,
				'por_defecto' => $ambos,
			),
			self::INICIO_CURSO       => array(
				'grupo'       => self::GRUPO_INFORMACION,
				'titulo'      => __( 'Correo de inicio de curso (para reenviar)', 'anpa-socios' ),
				'descricion'  => __( 'O correo chega sempre á conta da xunta para reenvialo dende Gmail.', 'anpa-socios' ),
				'correo_fixo' => true,
				'por_defecto' => $so_correo,
			),
			self::AVISOS_EMPRESA     => array(
				'grupo'       => self::GRUPO_INFORMACION,
				'titulo'      => __( 'Avisos á empresa e ao comedor', 'anpa-socios' ),
				'descricion'  => __( 'Altas, baixas e cambios de grupo cos grupos xa creados. O correo vai sempre á conta da xunta coa empresa en copia oculta.', 'anpa-socios' ),
				'correo_fixo' => true,
				'por_defecto' => $so_correo,
			),
			self::CORREOS_MASIVOS    => array(
				'grupo'       => self::GRUPO_INFORMACION,
				'titulo'      => __( 'Correos masivos ás familias', 'anpa-socios' ),
				'descricion'  => __( 'Prazo de matrículas, comezo e fin de curso, avisos dos grupos… O correo vai sempre á conta da xunta coas familias en copia oculta; en Telegram é unha liña de resumo.', 'anpa-socios' ),
				'correo_fixo' => true,
				'por_defecto' => $so_correo,
			),
		);
	}

	/**
	 * Group labels for Axustes.
	 *
	 * @return array<string,string>
	 */
	public static function grupos(): array {
		return array(
			self::GRUPO_APROBACIONS => __( 'Aprobacións (a xunta ten que decidir)', 'anpa-socios' ),
			self::GRUPO_INFORMACION => __( 'Información', 'anpa-socios' ),
		);
	}

	/**
	 * Whether the id is a known notice.
	 *
	 * @param  string $id Notice id.
	 * @return bool
	 */
	public static function existe( string $id ): bool {
		return array_key_exists( $id, self::catalogo() );
	}

	/**
	 * Channels of every notice: the saved ones, the default for the rest.
	 *
	 * Before 1.92.0 the start-of-year and mass emails went to Telegram only
	 * in mode «todo»: that choice is kept as their default.
	 *
	 * @param  mixed  $gardado     Stored option (id => {correo, telegram}).
	 * @param  string $modo_antigo 1.90.0 Telegram mode ('avisos' | 'todo').
	 * @return array<string,array{correo:bool,telegram:bool}>
	 */
	public static function canles( $gardado, string $modo_antigo = '' ): array {
		$gardado = is_array( $gardado ) ? $gardado : array();
		$out     = array();
		foreach ( self::catalogo() as $id => $def ) {
			$canles = $def['por_defecto'];
			if ( $def['correo_fixo'] && self::MODO_ANTIGO_TODO === trim( $modo_antigo ) ) {
				$canles[ self::TELEGRAM ] = true;
			}
			if ( isset( $gardado[ $id ] ) && is_array( $gardado[ $id ] ) ) {
				foreach ( array( self::CORREO, self::TELEGRAM ) as $canle ) {
					if ( array_key_exists( $canle, $gardado[ $id ] ) ) {
						$canles[ $canle ] = (bool) $gardado[ $id ][ $canle ];
					}
				}
			}
			if ( $def['correo_fixo'] ) {
				$canles[ self::CORREO ] = true;
			}
			$out[ $id ] = array(
				self::CORREO   => (bool) $canles[ self::CORREO ],
				self::TELEGRAM => (bool) $canles[ self::TELEGRAM ],
			);
		}
		return $out;
	}

	/**
	 * Whether a notice goes through a channel.
	 *
	 * @param  array<string,array{correo:bool,telegram:bool}> $canles canles().
	 * @param  string                                         $id     Notice id.
	 * @param  string                                         $canle  CORREO or TELEGRAM.
	 * @return bool
	 */
	public static function activo( array $canles, string $id, string $canle ): bool {
		return ! empty( $canles[ $id ][ $canle ] );
	}

	/**
	 * Option value from the Axustes form: avisos_xunta[id][correo|telegram] = '1'.
	 * Unchecked boxes are absent from the POST, so every known notice is
	 * written with explicit booleans (the fixed emails stay on).
	 *
	 * @param  mixed $post $_POST['avisos_xunta'].
	 * @return array<string,array{correo:bool,telegram:bool}>
	 */
	public static function desde_formulario( $post ): array {
		$post = is_array( $post ) ? $post : array();
		$out  = array();
		foreach ( self::catalogo() as $id => $def ) {
			$fila       = isset( $post[ $id ] ) && is_array( $post[ $id ] ) ? $post[ $id ] : array();
			$out[ $id ] = array(
				self::CORREO   => $def['correo_fixo'] || ! empty( $fila[ self::CORREO ] ),
				self::TELEGRAM => ! empty( $fila[ self::TELEGRAM ] ),
			);
		}
		return $out;
	}

	/**
	 * Notice a mass email belongs to: the automatic notices to the company and
	 * the canteen (templates matricula_*_aviso) or the mass emails to families.
	 *
	 * @param  string $template_id Template id.
	 * @return string
	 */
	public static function id_masivo( string $template_id ): string {
		return 1 === preg_match( '/^matricula_[a-z_]+_aviso$/', $template_id ) ? self::AVISOS_EMPRESA : self::CORREOS_MASIVOS;
	}
}
