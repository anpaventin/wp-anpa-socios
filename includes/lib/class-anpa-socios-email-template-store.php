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

	/**
	 * Option name for template storage.
	 *
	 * @var string
	 */
	const OPTION = 'anpa_socios_email_templates';

	/**
	 * Get a template by ID. Returns custom template or hardcoded default.
	 *
	 * @param  string $id Template identifier.
	 * @return array      Template array with subject, html, text keys.
	 */
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

	/**
	 * Get all templates (custom + defaults merged).
	 *
	 * @return array Associative array of all templates.
	 */
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

	/**
	 * Save a template (requires manage_options).
	 *
	 * @param  string $id      Template identifier.
	 * @param  string $subject Email subject.
	 * @param  string $html    HTML body.
	 * @param  string $text    Plain text body.
	 * @return bool            True on success.
	 */
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

	/**
	 * Delete a custom template (restore to default).
	 *
	 * @param  string $id Template identifier.
	 * @return bool       True on success.
	 */
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

	/**
	 * Restore all templates to defaults (delete option).
	 *
	 * @return bool True on success.
	 */
	public static function restore_all(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return delete_option( self::OPTION );
	}

	/**
	 * Get a single hardcoded default template.
	 *
	 * @param  string $id Template identifier.
	 * @return array      Default template.
	 */
	public static function get_default( string $id ): array {
		$defaults = self::get_all_defaults();
		return isset( $defaults[ $id ] ) ? $defaults[ $id ] : array(
			'subject'  => '',
			'html'     => '',
			'text'     => '',
			'modified' => '',
		);
	}

	/**
	 * Get all hardcoded default templates.
	 *
	 * @return array All default templates.
	 */
	public static function get_all_defaults(): array {
		return array(
			'verification_code' => array(
				'subject' => sprintf(
					/* translators: %s: Association name. */
					__( 'Your verification code for %s', 'anpa-socios' ),
					'{{association_name}}'
				),
				'html'    => '<p>' . sprintf(
					/* translators: %s: Recipient name. */
					__( 'Hello %s,', 'anpa-socios' ),
					'{{nome}}'
				) . '</p>' .
					'<p>' . __( 'Your verification code is:', 'anpa-socios' ) . '</p>' .
					'<p><strong>{{codigo}}</strong></p>' .
					'<p>' . __( 'This code expires in 15 minutes.', 'anpa-socios' ) . '</p>',
				'text'    => sprintf( __( 'Hello %s,', 'anpa-socios' ), '{{nome}}' ) . "\n\n" .
					__( 'Your verification code is: {{codigo}}', 'anpa-socios' ) . "\n\n" .
					__( 'This code expires in 15 minutes.', 'anpa-socios' ),
			),
			'baixa_socio' => array(
				'subject' => sprintf(
					__( 'Membership cancellation request — %s', 'anpa-socios' ),
					'{{association_name}}'
				),
				'html'    => '<p>' . __( 'A member has requested cancellation:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Name:', 'anpa-socios' ) . '</strong> {{nome}} {{apelidos}}</li>' .
					'<li><strong>' . __( 'Email:', 'anpa-socios' ) . '</strong> {{email_socio}}</li>' .
					'</ul>',
				'text'    => __( 'A member has requested cancellation:', 'anpa-socios' ) . "\n\n" .
					__( 'Name:', 'anpa-socios' ) . ' {{nome}} {{apelidos}}' . "\n" .
					__( 'Email:', 'anpa-socios' ) . ' {{email_socio}}',
			),
			'reactivacion' => array(
				'subject' => sprintf(
					__( 'Reactivation request — %s', 'anpa-socios' ),
					'{{association_name}}'
				),
				'html'    => '<p>' . sprintf(
					__( 'A member (%s) has requested reactivation.', 'anpa-socios' ),
					'{{email_socio}}'
				) . '</p>',
				'text'    => sprintf(
					__( 'A member (%s) has requested reactivation.', 'anpa-socios' ),
					'{{email_socio}}'
				),
			),
			'baixa_extraescolar' => array(
				'subject' => sprintf(
					__( 'Activity cancellation — %s', 'anpa-socios' ),
					'{{association_name}}'
				),
				'html'    => '<p>' . __( 'An activity cancellation has been requested:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Student:', 'anpa-socios' ) . '</strong> {{alumno}}</li>' .
					'<li><strong>' . __( 'Activity:', 'anpa-socios' ) . '</strong> {{actividade}}</li>' .
					'<li><strong>' . __( 'Requested by:', 'anpa-socios' ) . '</strong> {{email_socio}}</li>' .
					'</ul>',
				'text'    => __( 'An activity cancellation has been requested:', 'anpa-socios' ) . "\n\n" .
					'{{alumno}} - {{actividade}}' . "\n" .
					__( 'Requested by:', 'anpa-socios' ) . ' {{email_socio}}',
			),
			'oferta_extraescolar' => array(
				'subject' => sprintf(
					__( 'Waitlist offer — %s', 'anpa-socios' ),
					'{{actividade}}'
				),
				'html'    => '<p>' . sprintf(
					__( 'Hello, a place is available for %s.', 'anpa-socios' ),
					'<strong>{{actividade}}</strong>'
				) . '</p>' .
					'<p>' . sprintf(
						__( 'You have %d days to accept this offer.', 'anpa-socios' ),
						'{{dias_prazo}}'
					) . '</p>',
				'text'    => sprintf(
					__( 'Hello, a place is available for %s.', 'anpa-socios' ),
					'{{actividade}}'
				) . "\n\n" .
					sprintf(
						__( 'You have %d days to accept this offer.', 'anpa-socios' ),
						'{{dias_prazo}}'
					),
			),
			'pendente_aprobacion' => array(
				'subject' => sprintf(
					__( 'Pending approval — %s', 'anpa-socios' ),
					'{{association_name}}'
				),
				'html'    => '<p>' . __( 'A new member is pending approval:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Name:', 'anpa-socios' ) . '</strong> {{nome}}</li>' .
					'<li><strong>' . __( 'Email:', 'anpa-socios' ) . '</strong> {{email_socio}}</li>' .
					'</ul>' .
					'<p><a href="{{login_url}}">' . __( 'Review in the admin panel', 'anpa-socios' ) . '</a></p>',
				'text'    => __( 'A new member is pending approval:', 'anpa-socios' ) . "\n\n" .
					'{{nome}} ({{email_socio}})' . "\n\n" .
					__( 'Review in the admin panel:', 'anpa-socios' ) . "\n{{login_url}}',
			),
			'aprobacion' => array(
				'subject' => sprintf(
					__( 'Membership approved — %s', 'anpa-socios' ),
					'{{association_name}}'
				),
				'html'    => '<p>' . sprintf(
					__( 'Congratulations! Your membership has been approved.', 'anpa-socios' )
				) . '</p>' .
					'<p>' . sprintf(
						__( 'You can access your area here: %s', 'anpa-socios' ),
						'<a href="{{login_url}}">{{login_url}}</a>'
					) . '</p>',
				'text'    => __( 'Congratulations! Your membership has been approved.', 'anpa-socios' ) . "\n\n" .
					__( 'You can access your area here:', 'anpa-socios' ) . "\n{{login_url}}',
			),
			'benvida_alta' => array(
				'subject' => sprintf(
					__( 'Welcome to %s', 'anpa-socios' ),
					'{{association_name}}'
				),
				'html'    => '<p>' . sprintf(
					__( 'Welcome to %s! Your registration is complete.', 'anpa-socios' ),
					'<strong>{{association_name}}</strong>'
				) . '</p>' .
					'<p>' . sprintf(
						__( 'Access your member area: %s', 'anpa-socios' ),
						'<a href="{{login_url}}">{{login_url}}</a>'
					) . '</p>',
				'text'    => sprintf(
					__( 'Welcome to %s! Your registration is complete.', 'anpa-socios' ),
					'{{association_name}}'
				) . "\n\n" .
					__( 'Access your member area:', 'anpa-socios' ) . "\n{{login_url}}',
			),
			'rexeitamento' => array(
				'subject' => sprintf(
					__( 'Membership application update — %s', 'anpa-socios' ),
					'{{association_name}}'
				),
				'html'    => '<p>' . sprintf(
					__( 'We regret to inform you that your membership application has not been accepted at this time. Please contact %s if you have questions.', 'anpa-socios' ),
					'{{contact_email}}'
				) . '</p>',
				'text'    => sprintf(
					__( 'We regret to inform you that your membership application has not been accepted at this time. Please contact %s if you have questions.', 'anpa-socios' ),
					'{{contact_email}}'
				),
			),
			'send_from_master' => array(
				'subject' => '',
				'html'    => '',
				'text'    => '',
			),
		);
	}
}
