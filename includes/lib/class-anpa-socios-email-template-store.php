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
					__( 'Oferta de praza — %s', 'anpa-socios' ),
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
			// 1.62.0: emails to the family when the junta resolves a baixa request
			// (Xestión → Socios → Baixas solicitadas). The ANPA signature configured
			// in Axustes is appended automatically to every one of them.
			'baixa_socio_confirmada' => array(
				'subject' => array(
					__( 'Baixa de socio/a confirmada — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola %s,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'A directiva de %s confirmou a baixa da vosa unidade familiar como socios/as. A partir de agora xa non tedes acceso á área de socios nin ás actividades extraescolares xestionadas pola asociación.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Correos dados de baixa como socios/as:', 'anpa-socios' ) . ' <strong>%s</strong></p>' .
					'<p>' . __( 'Se cres que se trata dun erro ou queres volver a ser socio/a, escribe á directiva en %s e solucionámolo.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Grazas por formar parte da asociación.', 'anpa-socios' ) . '</p>',
					array( 'nome', 'association_name', 'emails_baixa', 'contact_email' ),
				),
				'text' => array(
					__( 'Ola %s,', 'anpa-socios' ) . "\n\n" .
					__( 'A directiva de %s confirmou a baixa da vosa unidade familiar como socios/as. A partir de agora xa non tedes acceso á área de socios nin ás actividades extraescolares xestionadas pola asociación.', 'anpa-socios' ) . "\n\n" .
					__( 'Correos dados de baixa como socios/as:', 'anpa-socios' ) . ' %s' . "\n\n" .
					__( 'Se cres que se trata dun erro ou queres volver a ser socio/a, escribe á directiva en %s e solucionámolo.', 'anpa-socios' ) . "\n\n" .
					__( 'Grazas por formar parte da asociación.', 'anpa-socios' ),
					array( 'nome', 'association_name', 'emails_baixa', 'contact_email' ),
				),
			),
			'baixa_socio_rexeitada' => array(
				'subject' => array(
					__( 'Solicitude de baixa de socio/a non aceptada — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola %s,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'A directiva de %s revisou a túa solicitude de baixa como socio/a e non a aceptou: segues sendo socio/a activo/a, co acceso á área de socios e ás actividades igual que ata agora.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Se consideras que houbo un erro, ponte en contacto coa directiva en %s para solucionalo.', 'anpa-socios' ) . '</p>',
					array( 'nome', 'association_name', 'contact_email' ),
				),
				'text' => array(
					__( 'Ola %s,', 'anpa-socios' ) . "\n\n" .
					__( 'A directiva de %s revisou a túa solicitude de baixa como socio/a e non a aceptou: segues sendo socio/a activo/a, co acceso á área de socios e ás actividades igual que ata agora.', 'anpa-socios' ) . "\n\n" .
					__( 'Se consideras que houbo un erro, ponte en contacto coa directiva en %s para solucionalo.', 'anpa-socios' ),
					array( 'nome', 'association_name', 'contact_email' ),
				),
			),
			'baixa_extraescolar_confirmada' => array(
				'subject' => array(
					__( 'Baixa da actividade %s confirmada — %s', 'anpa-socios' ),
					array( 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola,', 'anpa-socios' ) . '</p>' .
					'<p>' . sprintf( __( 'A directiva de %%s confirmou a baixa de %s na actividade %s.', 'anpa-socios' ), '<strong>%s</strong>', '<strong>%s</strong>' ) . '</p>' .
					'<p>%s</p>' .
					'<p>' . __( 'Se tes dúbidas, escribe á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'association_name', 'alumno', 'actividade', 'efectos', 'contact_email' ),
				),
				'text' => array(
					__( 'Ola,', 'anpa-socios' ) . "\n\n" .
					__( 'A directiva de %s confirmou a baixa de %s na actividade %s.', 'anpa-socios' ) . "\n\n" .
					'%s' . "\n\n" .
					__( 'Se tes dúbidas, escribe á directiva en %s.', 'anpa-socios' ),
					array( 'association_name', 'alumno', 'actividade', 'efectos', 'contact_email' ),
				),
			),
			'baixa_extraescolar_rexeitada' => array(
				'subject' => array(
					__( 'Solicitude de baixa da actividade %s non aceptada — %s', 'anpa-socios' ),
					array( 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola,', 'anpa-socios' ) . '</p>' .
					'<p>' . sprintf( __( 'A directiva de %%s revisou a solicitude de baixa de %s na actividade %s e non a aceptou: a matrícula segue activa e a actividade continúa como ata agora.', 'anpa-socios' ), '<strong>%s</strong>', '<strong>%s</strong>' ) . '</p>' .
					'<p>' . __( 'Se consideras que houbo un erro, ponte en contacto coa directiva en %s para solucionalo.', 'anpa-socios' ) . '</p>',
					array( 'association_name', 'alumno', 'actividade', 'contact_email' ),
				),
				'text' => array(
					__( 'Ola,', 'anpa-socios' ) . "\n\n" .
					__( 'A directiva de %s revisou a solicitude de baixa de %s na actividade %s e non a aceptou: a matrícula segue activa e a actividade continúa como ata agora.', 'anpa-socios' ) . "\n\n" .
					__( 'Se consideras que houbo un erro, ponte en contacto coa directiva en %s para solucionalo.', 'anpa-socios' ),
					array( 'association_name', 'alumno', 'actividade', 'contact_email' ),
				),
			),
			// 1.62.0: acknowledgement to the family when it REQUESTS a baixa from the
			// area: the process is manual, an administrator confirms it within days,
			// and the ANPA is run by parents in their free time.
			'baixa_socio_solicitada' => array(
				'subject' => array(
					__( 'Recibimos a túa solicitude de baixa — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola %s,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Rexistramos a túa solicitude de baixa como socio/a de %s.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'A baixa non é automática: unha persoa da directiva ten que revisala e confirmala, e pode tardar uns días. Mentres tanto segues sendo socio/a. En canto se confirme, recibirás outro correo.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Lembra que o traballo da ANPA fano nais e pais que dedican o seu tempo libre a estas tarefas; grazas pola paciencia.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Se cambias de idea, podes anular a solicitude desde a túa área de socios ou escribir á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'nome', 'association_name', 'contact_email' ),
				),
				'text' => array(
					__( 'Ola %s,', 'anpa-socios' ) . "\n\n" .
					__( 'Rexistramos a túa solicitude de baixa como socio/a de %s.', 'anpa-socios' ) . "\n\n" .
					__( 'A baixa non é automática: unha persoa da directiva ten que revisala e confirmala, e pode tardar uns días. Mentres tanto segues sendo socio/a. En canto se confirme, recibirás outro correo.', 'anpa-socios' ) . "\n\n" .
					__( 'Lembra que o traballo da ANPA fano nais e pais que dedican o seu tempo libre a estas tarefas; grazas pola paciencia.', 'anpa-socios' ) . "\n\n" .
					__( 'Se cambias de idea, podes anular a solicitude desde a túa área de socios ou escribir á directiva en %s.', 'anpa-socios' ),
					array( 'nome', 'association_name', 'contact_email' ),
				),
			),
			'baixa_extraescolar_solicitada' => array(
				'subject' => array(
					__( 'Recibimos a solicitude de baixa da actividade %s — %s', 'anpa-socios' ),
					array( 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola,', 'anpa-socios' ) . '</p>' .
					'<p>' . sprintf( __( 'Rexistramos a solicitude de baixa de %s na actividade %s de %%s.', 'anpa-socios' ), '<strong>%s</strong>', '<strong>%s</strong>' ) . '</p>' .
					'<p>' . __( 'A baixa non é automática: unha persoa da directiva ten que revisala e confirmala, e pode tardar uns días. Mentres tanto a matrícula segue como estaba. En canto se confirme, recibirás outro correo indicando desde cando é efectiva e se hai algún cobro.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Lembra que o traballo da ANPA fano nais e pais que dedican o seu tempo libre a estas tarefas; grazas pola paciencia.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Se cambias de idea, podes anular a solicitude desde a túa área de socios ou escribir á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'actividade', 'association_name', 'contact_email' ),
				),
				'text' => array(
					__( 'Ola,', 'anpa-socios' ) . "\n\n" .
					__( 'Rexistramos a solicitude de baixa de %s na actividade %s de %s.', 'anpa-socios' ) . "\n\n" .
					__( 'A baixa non é automática: unha persoa da directiva ten que revisala e confirmala, e pode tardar uns días. Mentres tanto a matrícula segue como estaba. En canto se confirme, recibirás outro correo indicando desde cando é efectiva e se hai algún cobro.', 'anpa-socios' ) . "\n\n" .
					__( 'Lembra que o traballo da ANPA fano nais e pais que dedican o seu tempo libre a estas tarefas; grazas pola paciencia.', 'anpa-socios' ) . "\n\n" .
					__( 'Se cambias de idea, podes anular a solicitude desde a túa área de socios ou escribir á directiva en %s.', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'association_name', 'contact_email' ),
				),
			),
			// 1.62.0: start-of-year email. Sent to the junta's own inbox (Xestión →
			// Socios → Lista Gmail) and forwarded from Gmail to the «Socios Web ANPA»
			// label, so WordPress never mails hundreds of families at once.
			'inicio_curso' => array(
				'subject' => array(
					__( 'Comeza o curso — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola, familias!', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Comeza un novo curso e dende %s queremos lembrarvos que toda a xestión de socios/as se fai na nosa web:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Iniciar sesión como socio/a:', 'anpa-socios' ) . '</strong> ' . __( 'entra na área de socios, escribe o teu correo e recibirás un código de acceso; non fai falta contrasinal.', 'anpa-socios' ) . '</li>' .
					'<li><strong>' . __( 'Darse de alta:', 'anpa-socios' ) . '</strong> ' . __( 'se aínda non sodes socios/as, na mesma páxina podedes facer a alta cos datos da familia e dos fillos/as.', 'anpa-socios' ) . '</li>' .
					'<li><strong>' . __( 'Modificar datos:', 'anpa-socios' ) . '</strong> ' . __( 'na túa área podes actualizar o teléfono, o correo, os datos bancarios e os datos e cursos dos fillos/as.', 'anpa-socios' ) . '</li>' .
					'<li><strong>' . __( 'Actividades extraescolares:', 'anpa-socios' ) . '</strong> ' . __( 'dende a área inscríbense os fillos/as nas actividades, vense as prazas e a lista de espera e solicítanse as baixas.', 'anpa-socios' ) . '</li>' .
					'</ul>' .
					'<p>' . __( 'Área de socios:', 'anpa-socios' ) . ' <a href="%s">%s</a></p>' .
					'<p>' . __( 'Web da asociación:', 'anpa-socios' ) . ' <a href="%s">%s</a></p>' .
					'<p>' . __( 'Para calquera dúbida, escribe á directiva en %s.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Bo comezo de curso!', 'anpa-socios' ) . '</p>',
					array( 'association_name', 'login_url', 'login_url', 'web_url', 'web_url', 'contact_email' ),
				),
				'text' => array(
					__( 'Ola, familias!', 'anpa-socios' ) . "\n\n" .
					__( 'Comeza un novo curso e dende %s queremos lembrarvos que toda a xestión de socios/as se fai na nosa web:', 'anpa-socios' ) . "\n\n" .
					'- ' . __( 'Iniciar sesión como socio/a:', 'anpa-socios' ) . ' ' . __( 'entra na área de socios, escribe o teu correo e recibirás un código de acceso; non fai falta contrasinal.', 'anpa-socios' ) . "\n" .
					'- ' . __( 'Darse de alta:', 'anpa-socios' ) . ' ' . __( 'se aínda non sodes socios/as, na mesma páxina podedes facer a alta cos datos da familia e dos fillos/as.', 'anpa-socios' ) . "\n" .
					'- ' . __( 'Modificar datos:', 'anpa-socios' ) . ' ' . __( 'na túa área podes actualizar o teléfono, o correo, os datos bancarios e os datos e cursos dos fillos/as.', 'anpa-socios' ) . "\n" .
					'- ' . __( 'Actividades extraescolares:', 'anpa-socios' ) . ' ' . __( 'dende a área inscríbense os fillos/as nas actividades, vense as prazas e a lista de espera e solicítanse as baixas.', 'anpa-socios' ) . "\n\n" .
					__( 'Área de socios:', 'anpa-socios' ) . ' %s' . "\n" .
					__( 'Web da asociación:', 'anpa-socios' ) . ' %s' . "\n\n" .
					__( 'Para calquera dúbida, escribe á directiva en %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Bo comezo de curso!', 'anpa-socios' ),
					array( 'association_name', 'login_url', 'web_url', 'contact_email' ),
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
