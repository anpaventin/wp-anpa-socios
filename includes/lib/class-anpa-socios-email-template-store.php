<?php
/**
 * ANPA_Socios_Email_Template_Store
 *
 * Manages email template storage using WordPress options API.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Store {

	const OPTION = 'anpa_socios_email_templates';

	/**
	 * Template definitions with variables in order.
	 */
	private static function get_definitions(): array {
		return array(
			'verification_code' => array(
				'subject' => array(
					__( 'O teu código de verificación para %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola %s,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O teu código de verificación é:', 'anpa-socios' ) . '</p>' .
					'<p><strong>%s</strong></p>' .
					'<p>' . __( 'Este código caduca en 15 minutos.', 'anpa-socios' ) . '</p>',
					array( 'nome', 'codigo' ),
				),
				'text' => array(
					__( 'Ola %s,', 'anpa-socios' ) . "\n\n" .
					__( 'O teu código de verificación é: %s', 'anpa-socios' ) . "\n\n" .
					__( 'Este código caduca en 15 minutos.', 'anpa-socios' ),
					array( 'nome', 'codigo' ),
				),
			),
			'baixa_socio' => array(
				'subject' => array(
					__( 'Solicitude de baixa de socio — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Un socio solicitou a baixa:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Nome:', 'anpa-socios' ) . '</strong> %s %s</li>' .
					'<li><strong>' . __( 'Email:', 'anpa-socios' ) . '</strong> %s</li>' .
					'</ul>',
					array( 'nome', 'apelidos', 'email_socio' ),
				),
				'text' => array(
					__( 'Un socio solicitou a baixa:', 'anpa-socios' ) . "\n\n" .
					__( 'Nome:', 'anpa-socios' ) . ' %s %s' . "\n" .
					__( 'Email:', 'anpa-socios' ) . ' %s',
					array( 'nome', 'apelidos', 'email_socio' ),
				),
			),
			'reactivacion' => array(
				'subject' => array(
					__( 'Solicitude de reactivación — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . sprintf( __( 'Un socio (%%s) solicitou a reactivación.', 'anpa-socios' ), '%s' ) . '</p>',
					array( 'email_socio' ),
				),
				'text' => array(
					__( 'Un socio (%s) solicitou a reactivación.', 'anpa-socios' ),
					array( 'email_socio' ),
				),
			),
			'baixa_extraescolar' => array(
				'subject' => array(
					__( 'Solicitude de baixa de actividade — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Solicitouse a baixa dunha actividade:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Alumno/a:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Actividade:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Solicitado por:', 'anpa-socios' ) . '</strong> %s</li>' .
					'</ul>',
					array( 'alumno', 'actividade', 'email_socio' ),
				),
				'text' => array(
					__( 'Solicitouse a baixa dunha actividade:', 'anpa-socios' ) . "\n\n" .
					'%s - %s' . "\n" .
					__( 'Solicitado por:', 'anpa-socios' ) . ' %s',
					array( 'alumno', 'actividade', 'email_socio' ),
				),
			),
			'oferta_extraescolar' => array(
				'subject' => array(
					__( 'Offerta de praza — %s', 'anpa-socios' ),
					array( 'actividade' ),
				),
				'html' => array(
					'<p>' . sprintf( __( 'Ola, hai unha praza dispoñible para %%s.', 'anpa-socios' ), '<strong>%s</strong>' ) . '</p>' .
					'<p>' . sprintf( __( 'Tes %%d días para aceptar esta oferta.', 'anpa-socios' ), '%s' ) . '</p>',
					array( 'actividade', 'dias_prazo' ),
				),
				'text' => array(
					__( 'Ola, hai unha praza dispoñible para %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Tes %d días para aceptar esta oferta.', 'anpa-socios' ),
					array( 'actividade', 'dias_prazo' ),
				),
			),
			'pendente_aprobacion' => array(
				'subject' => array(
					__( 'Pendente de aprobación — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Un novo socio está pendente de aprobación:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Nome:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Email:', 'anpa-socios' ) . '</strong> %s</li>' .
					'</ul>' .
					'<p><a href="%s">' . __( 'Revisar no panel de administración', 'anpa-socios' ) . '</a></p>',
					array( 'nome', 'email_socio', 'login_url' ),
				),
				'text' => array(
					__( 'Un novo socio está pendente de aprobación:', 'anpa-socios' ) . "\n\n" .
					'%s (%s)' . "\n\n" .
					__( 'Revisar no panel de administración:', 'anpa-socios' ) . "\n%s",
					array( 'nome', 'email_socio', 'login_url' ),
				),
			),
			'aprobacion' => array(
				'subject' => array(
					__( 'Solicitude aprobada — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Parabéns! A túa solicitude de socio foi aprobada.', 'anpa-socios' ) . '</p>' .
					'<p>' . sprintf( __( 'Podes acceder á túa área aquí: %%s', 'anpa-socios' ), '<a href="%s">%s</a>' ) . '</p>',
					array( 'login_url', 'login_url' ),
				),
				'text' => array(
					__( 'Parabéns! A túa solicitude de socio foi aprobada.', 'anpa-socios' ) . "\n\n" .
					__( 'Podes acceder á tua área aquí:', 'anpa-socios' ) . "\n%s",
					array( 'login_url' ),
				),
			),
			'benvida_alta' => array(
				'subject' => array(
					__( 'Benvido/a a %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . sprintf( __( 'Benvido/a a %%s! O teu rexistro está completo.', 'anpa-socios' ), '<strong>%s</strong>' ) . '</p>' .
					'<p>' . sprintf( __( 'Accede á túa área de socio: %%s', 'anpa-socios' ), '<a href="%s">%s</a>' ) . '</p>',
					array( 'association_name', 'login_url', 'login_url' ),
				),
				'text' => array(
					__( 'Benvido/a a %s! O teu rexistro está completo.', 'anpa-socios' ) . "\n\n" .
					__( 'Accede á túa área de socio:', 'anpa-socios' ) . "\n%s",
					array( 'association_name', 'login_url' ),
				),
			),
			'rexeitamento' => array(
				'subject' => array(
					__( 'Actualización da solicitude de socio — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . sprintf( __( 'Sentimos informar que a túa solicitude de socio non foi aceptada neste momento. Por favor, contacta con %%s se tes preguntas.', 'anpa-socios' ), '%s' ) . '</p>',
					array( 'contact_email' ),
				),
				'text' => array(
					__( 'Sentimos informar que a túa solicitude de socio non foi aceptada neste momento. Por favor, contacta con %s se tes preguntas.', 'anpa-socios' ),
					array( 'contact_email' ),
				),
			),
			'send_from_master' => array(
				'subject' => array(
					'%s',
					array( 'association_name' ),
				),
				'html' => array(
					'<p>%s</p>',
					array( 'custom_body' ),
				),
				'text' => array(
					'%s',
					array( 'custom_body' ),
				),
			),
		);
	}

	public static function get( string $id ): array {
		$custom = get_option( self::OPTION, array() );
		if ( isset( $custom[ $id ] ) && is_array( $custom[ $id ] ) ) {
			return wp_parse_args( $custom[ $id ], array(
				'subject'  => '',
				'html'     => '',
				'text'     => '',
				'modified' => '',
			) );
		}
		return self::get_default( $id );
	}

	public static function get_all(): array {
		$custom  = get_option( self::OPTION, array() );
		$defaults = self::get_all_defaults();
		$merged  = array();
		foreach ( $defaults as $id => $default ) {
			if ( isset( $custom[ $id ] ) && is_array( $custom[ $id ] ) ) {
				$merged[ $id ] = wp_parse_args( $custom[ $id ], $default );
			} else {
				$merged[ $id ] = $default;
			}
		}
		return $merged;
	}

	public static function save( string $id, string $subject, string $html, string $text ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$templates = get_option( self::OPTION, array() );
		$templates[ $id ] = array(
			'subject'  => sanitize_text_field( $subject ),
			'html'     => wp_kses_post( $html ),
			'text'     => sanitize_textarea_field( $text ),
			'modified' => current_time( 'mysql', true ),
		);
		return (bool) update_option( self::OPTION, $templates );
	}

	public static function delete( string $id ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$templates = get_option( self::OPTION, array() );
		unset( $templates[ $id ] );
		if ( empty( $templates ) ) {
			return delete_option( self::OPTION );
		}
		return (bool) update_option( self::OPTION, $templates );
	}

	public static function restore_all(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return delete_option( self::OPTION );
	}

	public static function get_default( string $id ): array {
		$defaults = self::get_all_defaults();
		return isset( $defaults[ $id ] ) ? $defaults[ $id ] : array(
			'subject'  => '',
			'html'     => '',
			'text'     => '',
			'modified' => '',
		);
	}

	public static function get_all_defaults(): array {
		$definitions = self::get_definitions();
		$result = array();
		foreach ( $definitions as $id => $fields ) {
			$result[ $id ] = array(
				'subject' => $fields['subject'][0],
				'html'    => $fields['html'][0],
				'text'    => $fields['text'][0],
			);
		}
		return $result;
	}

	public static function get_variables( string $id ): array {
		$definitions = self::get_definitions();
		if ( ! isset( $definitions[ $id ] ) ) {
			return array();
		}
		return array(
			'subject' => $definitions[ $id ]['subject'][1],
			'html'    => $definitions[ $id ]['html'][1],
			'text'    => $definitions[ $id ]['text'][1],
		);
	}
}
