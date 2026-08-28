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
					__( 'Your verification code for %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Hello %s,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Your verification code is:', 'anpa-socios' ) . '</p>' .
					'<p><strong>%s</strong></p>' .
					'<p>' . __( 'This code expires in 15 minutes.', 'anpa-socios' ) . '</p>',
					array( 'nome', 'codigo' ),
				),
				'text' => array(
					__( 'Hello %s,', 'anpa-socios' ) . "\n\n" .
					__( 'Your verification code is: %s', 'anpa-socios' ) . "\n\n" .
					__( 'This code expires in 15 minutes.', 'anpa-socios' ),
					array( 'nome', 'codigo' ),
				),
			),
			'baixa_socio' => array(
				'subject' => array(
					__( 'Membership cancellation request — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'A member has requested cancellation:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Name:', 'anpa-socios' ) . '</strong> %s %s</li>' .
					'<li><strong>' . __( 'Email:', 'anpa-socios' ) . '</strong> %s</li>' .
					'</ul>',
					array( 'nome', 'apelidos', 'email_socio' ),
				),
				'text' => array(
					__( 'A member has requested cancellation:', 'anpa-socios' ) . "\n\n" .
					__( 'Name:', 'anpa-socios' ) . ' %s %s' . "\n" .
					__( 'Email:', 'anpa-socios' ) . ' %s',
					array( 'nome', 'apelidos', 'email_socio' ),
				),
			),
			'reactivacion' => array(
				'subject' => array(
					__( 'Reactivation request — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . sprintf( __( 'A member (%%s) has requested reactivation.', 'anpa-socios' ), '%s' ) . '</p>',
					array( 'email_socio' ),
				),
				'text' => array(
					__( 'A member (%s) has requested reactivation.', 'anpa-socios' ),
					array( 'email_socio' ),
				),
			),
			'baixa_extraescolar' => array(
				'subject' => array(
					__( 'Activity cancellation — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'An activity cancellation has been requested:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Student:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Activity:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Requested by:', 'anpa-socios' ) . '</strong> %s</li>' .
					'</ul>',
					array( 'alumno', 'actividade', 'email_socio' ),
				),
				'text' => array(
					__( 'An activity cancellation has been requested:', 'anpa-socios' ) . "\n\n" .
					'%s - %s' . "\n" .
					__( 'Requested by:', 'anpa-socios' ) . ' %s',
					array( 'alumno', 'actividade', 'email_socio' ),
				),
			),
			'oferta_extraescolar' => array(
				'subject' => array(
					__( 'Waitlist offer — %s', 'anpa-socios' ),
					array( 'actividade' ),
				),
				'html' => array(
					'<p>' . sprintf( __( 'Hello, a place is available for %%s.', 'anpa-socios' ), '<strong>%s</strong>' ) . '</p>' .
					'<p>' . sprintf( __( 'You have %%d days to accept this offer.', 'anpa-socios' ), '%s' ) . '</p>',
					array( 'actividade', 'dias_prazo' ),
				),
				'text' => array(
					__( 'Hello, a place is available for %s.', 'anpa-socios' ) . "\n\n" .
					__( 'You have %d days to accept this offer.', 'anpa-socios' ),
					array( 'actividade', 'dias_prazo' ),
				),
			),
			'pendente_aprobacion' => array(
				'subject' => array(
					__( 'Pending approval — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'A new member is pending approval:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Name:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Email:', 'anpa-socios' ) . '</strong> %s</li>' .
					'</ul>' .
					'<p><a href="%s">' . __( 'Review in the admin panel', 'anpa-socios' ) . '</a></p>',
					array( 'nome', 'email_socio', 'login_url' ),
				),
				'text' => array(
					__( 'A new member is pending approval:', 'anpa-socios' ) . "\n\n" .
					'%s (%s)' . "\n\n" .
					__( 'Review in the admin panel:', 'anpa-socios' ) . "\n%s",
					array( 'nome', 'email_socio', 'login_url' ),
				),
			),
			'aprobacion' => array(
				'subject' => array(
					__( 'Membership approved — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Congratulations! Your membership has been approved.', 'anpa-socios' ) . '</p>' .
					'<p>' . sprintf( __( 'You can access your area here: %%s', 'anpa-socios' ), '<a href="%s">%s</a>' ) . '</p>',
					array( 'login_url', 'login_url' ),
				),
				'text' => array(
					__( 'Congratulations! Your membership has been approved.', 'anpa-socios' ) . "\n\n" .
					__( 'You can access your area here:', 'anpa-socios' ) . "\n%s",
					array( 'login_url' ),
				),
			),
			'benvida_alta' => array(
				'subject' => array(
					__( 'Welcome to %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . sprintf( __( 'Welcome to %%s! Your registration is complete.', 'anpa-socios' ), '<strong>%s</strong>' ) . '</p>' .
					'<p>' . sprintf( __( 'Access your member area: %%s', 'anpa-socios' ), '<a href="%s">%s</a>' ) . '</p>',
					array( 'association_name', 'login_url', 'login_url' ),
				),
				'text' => array(
					__( 'Welcome to %s! Your registration is complete.', 'anpa-socios' ) . "\n\n" .
					__( 'Access your member area:', 'anpa-socios' ) . "\n%s",
					array( 'association_name', 'login_url' ),
				),
			),
			'rexeitamento' => array(
				'subject' => array(
					__( 'Membership application update — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . sprintf( __( 'We regret to inform you that your membership application has not been accepted at this time. Please contact %%s if you have questions.', 'anpa-socios' ), '%s' ) . '</p>',
					array( 'contact_email' ),
				),
				'text' => array(
					__( 'We regret to inform you that your membership application has not been accepted at this time. Please contact %s if you have questions.', 'anpa-socios' ),
					array( 'contact_email' ),
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
