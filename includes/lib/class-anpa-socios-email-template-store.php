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
					__( 'Comeza o curso: socios/as e actividades extraescolares — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				// 1.68.1: modelled on the junta's own start-of-year mail (2026-09): short, links first,
				// relative calendar (the only date is the start of the activities).
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p><strong>' . __( 'Xa está aberta a inscrición nas actividades extraescolares!', 'anpa-socios' ) . '</strong> ' . __( 'E con ela o prazo para facerse socio/a de %s.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Toda a información sobre como darse de alta como socios/as e o proceso paso a paso para a inscrición nas actividades está neste enlace:', 'anpa-socios' ) . ' <a href="%s">%s</a></p>' .
					'<ul>' .
					'<li><strong>' . __( 'Oferta de actividades e horarios semanais:', 'anpa-socios' ) . '</strong> <a href="%s">%s</a></li>' .
					'<li><strong>' . __( 'Área de socios/as', 'anpa-socios' ) . '</strong> ' . __( '(entrar co teu correo, darse de alta, revisar os datos e matricular):', 'anpa-socios' ) . ' <a href="%s">%s</a></li>' .
					'</ul>' .
					'<p><strong>' . __( 'Calendario:', 'anpa-socios' ) . '</strong> ' . __( 'as actividades comezan o 1 de outubro. Uns días antes péchase o prazo de inscrición na web e envíase o listado ás empresas que imparten as actividades; avisaremos por correo da data exacta de peche, con marxe para revisar as inscricións.', 'anpa-socios' ) . '</p>' .
					'<p><strong>' . __( 'Aviso importante sobre os pagos:', 'anpa-socios' ) . '</strong> ' . __( 'a ANPA encárgase unicamente de xestionar as inscricións e a organización. O cobro das actividades faino directamente cada empresa coas familias inscritas.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Grazas pola vosa colaboración. Un saúdo.', 'anpa-socios' ) . '</p>',
					array( 'association_name', 'instrucions_url', 'instrucions_url', 'extraescolares_url', 'extraescolares_url', 'login_url', 'login_url', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'Xa está aberta a inscrición nas actividades extraescolares!', 'anpa-socios' ) . ' ' . __( 'E con ela o prazo para facerse socio/a de %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Toda a información sobre como darse de alta como socios/as e o proceso paso a paso para a inscrición nas actividades está neste enlace:', 'anpa-socios' ) . ' %s' . "\n\n" .
					'- ' . __( 'Oferta de actividades e horarios semanais:', 'anpa-socios' ) . ' %s' . "\n" .
					'- ' . __( 'Área de socios/as', 'anpa-socios' ) . ' ' . __( '(entrar co teu correo, darse de alta, revisar os datos e matricular):', 'anpa-socios' ) . ' %s' . "\n\n" .
					__( 'Calendario:', 'anpa-socios' ) . ' ' . __( 'as actividades comezan o 1 de outubro. Uns días antes péchase o prazo de inscrición na web e envíase o listado ás empresas que imparten as actividades; avisaremos por correo da data exacta de peche, con marxe para revisar as inscricións.', 'anpa-socios' ) . "\n\n" .
					__( 'Aviso importante sobre os pagos:', 'anpa-socios' ) . ' ' . __( 'a ANPA encárgase unicamente de xestionar as inscricións e a organización. O cobro das actividades faino directamente cada empresa coas familias inscritas.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Grazas pola vosa colaboración. Un saúdo.', 'anpa-socios' ),
					array( 'association_name', 'instrucions_url', 'extraescolares_url', 'login_url', 'contact_email' ),
				),
			),
			// ── 1.68.0: ciclo do curso e control das extraescolares dende Xestión → Matrículas ──
			// Correos masivos ás familias (destinatario visible: a xunta; familias en CCO por lotes).
			'prazo_matriculas' => array(
				// 1.68.1: modelled on the junta's «últimos días de revisión» mail (2026-09).
				'subject' => array(
					__( 'Últimos días para revisar as inscricións nas extraescolares: o prazo remata o %s — %s', 'anpa-socios' ),
					array( 'data_peche', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Estamos na fase final do proceso de inscrición e revisión das actividades extraescolares. O prazo de revisión e axustes remata o <strong>%s</strong>: ese día péchanse as inscricións na web e envíase a lista definitiva ás empresas que imparten as actividades, para que organicen os grupos e a xestión dos pagos antes do comezo das clases, que comezan o <strong>%s</strong>.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Neste momento estamos a avaliar a viabilidade de cada grupo: para que unha actividade saia adiante debe acadar o número mínimo de participantes que esixe a empresa organizadora, e as que non o acaden terán que descartarse. Por iso pedímosvos que teñades en conta estes puntos:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Inscricións en grupos activos:', 'anpa-socios' ) . '</strong> ' . __( 'animámosvos a inscribir aos vosos fillos e fillas nas actividades que aínda precisan duns poucos alumnos/as para chegar ao mínimo, ou nas que xa teñen grupo creado e prazas dispoñibles.', 'anpa-socios' ) . '</li>' .
					'<li><strong>' . __( 'Listas de espera:', 'anpa-socios' ) . '</strong> ' . __( 'se o voso fillo ou filla quedou en lista de espera polo límite de prazas, podedes mantervos nesa lista ou inscribilo/a noutra actividade que si teña prazas libres.', 'anpa-socios' ) . '</li>' .
					'<li><strong>' . __( 'Revisión dos datos:', 'anpa-socios' ) . '</strong> ' . __( 'comprobade con atención que a actividade e o horario escollidos son os correctos. Un erro na selección pode facer que un grupo non se forme ou que outra familia quede sen praza.', 'anpa-socios' ) . '</li>' .
					'</ul>' .
					'<p>' . __( 'Consultade a oferta e xestionade as inscricións na web:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Oferta de actividades e horarios:', 'anpa-socios' ) . '</strong> <a href="%s">%s</a></li>' .
					'<li><strong>' . __( 'Área de socios/as (matricular, cambiar ou dar de baixa):', 'anpa-socios' ) . '</strong> <a href="%s">%s</a></li>' .
					'</ul>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Moitas grazas pola vosa axuda e colaboración para que todo saia o mellor posible. Un saúdo.', 'anpa-socios' ) . '</p>',
					array( 'data_peche', 'data_inicio_actividades', 'extraescolares_url', 'extraescolares_url', 'login_url', 'login_url', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'Estamos na fase final do proceso de inscrición e revisión das actividades extraescolares. O prazo de revisión e axustes remata o %s: ese día péchanse as inscricións na web e envíase a lista definitiva ás empresas que imparten as actividades, para que organicen os grupos e a xestión dos pagos antes do comezo das clases, que comezan o %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Neste momento estamos a avaliar a viabilidade de cada grupo: para que unha actividade saia adiante debe acadar o número mínimo de participantes que esixe a empresa organizadora, e as que non o acaden terán que descartarse. Por iso pedímosvos que teñades en conta estes puntos:', 'anpa-socios' ) . "\n\n" .
					'- ' . __( 'Inscricións en grupos activos:', 'anpa-socios' ) . ' ' . __( 'animámosvos a inscribir aos vosos fillos e fillas nas actividades que aínda precisan duns poucos alumnos/as para chegar ao mínimo, ou nas que xa teñen grupo creado e prazas dispoñibles.', 'anpa-socios' ) . "\n" .
					'- ' . __( 'Listas de espera:', 'anpa-socios' ) . ' ' . __( 'se o voso fillo ou filla quedou en lista de espera polo límite de prazas, podedes mantervos nesa lista ou inscribilo/a noutra actividade que si teña prazas libres.', 'anpa-socios' ) . "\n" .
					'- ' . __( 'Revisión dos datos:', 'anpa-socios' ) . ' ' . __( 'comprobade con atención que a actividade e o horario escollidos son os correctos. Un erro na selección pode facer que un grupo non se forme ou que outra familia quede sen praza.', 'anpa-socios' ) . "\n\n" .
					__( 'Consultade a oferta e xestionade as inscricións na web:', 'anpa-socios' ) . "\n" .
					'- ' . __( 'Oferta de actividades e horarios:', 'anpa-socios' ) . ' %s' . "\n" .
					'- ' . __( 'Área de socios/as (matricular, cambiar ou dar de baixa):', 'anpa-socios' ) . ' %s' . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Moitas grazas pola vosa axuda e colaboración para que todo saia o mellor posible. Un saúdo.', 'anpa-socios' ),
					array( 'data_peche', 'data_inicio_actividades', 'extraescolares_url', 'login_url', 'contact_email' ),
				),
			),
			'fin_curso' => array(
				'subject' => array(
					__( 'Remata o curso %s: grazas pola vosa participación — %s', 'anpa-socios' ),
					array( 'curso_escolar', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Remata o curso escolar %s e con el as actividades extraescolares. Dende a directiva de %s queremos agradecervos a participación e a colaboración durante todo o ano: sen as familias non habería ANPA.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'As matrículas deste curso quedan pechadas. A inscrición para o curso que vén abrirase en setembro e avisarémosvos por correo.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Bo verán e ata setembro. Un saúdo.', 'anpa-socios' ) . '</p>',
					array( 'curso_escolar', 'association_name', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'Remata o curso escolar %s e con el as actividades extraescolares. Dende a directiva de %s queremos agradecervos a participación e a colaboración durante todo o ano: sen as familias non habería ANPA.', 'anpa-socios' ) . "\n\n" .
					__( 'As matrículas deste curso quedan pechadas. A inscrición para o curso que vén abrirase en setembro e avisarémosvos por correo.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Bo verán e ata setembro. Un saúdo.', 'anpa-socios' ),
					array( 'curso_escolar', 'association_name', 'contact_email' ),
				),
			),
			'matriculas_abertas' => array(
				'subject' => array(
					__( 'Abertas as matrículas das extraescolares para o %s trimestre — %s', 'anpa-socios' ),
					array( 'trimestre', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Xa están abertas as matrículas (altas e baixas) nas actividades extraescolares para o <strong>%s trimestre</strong>.', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Oferta de actividades e horarios:', 'anpa-socios' ) . '</strong> <a href="%s">%s</a></li>' .
					'<li><strong>' . __( 'Área de socios/as (matricular ou dar de baixa):', 'anpa-socios' ) . '</strong> <a href="%s">%s</a></li>' .
					'</ul>' .
					'<p>' . __( 'Avisaremos por correo cando o prazo estea a punto de rematar.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'trimestre', 'extraescolares_url', 'extraescolares_url', 'login_url', 'login_url', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'Xa están abertas as matrículas (altas e baixas) nas actividades extraescolares para o %s trimestre.', 'anpa-socios' ) . "\n\n" .
					'- ' . __( 'Oferta de actividades e horarios:', 'anpa-socios' ) . ' %s' . "\n" .
					'- ' . __( 'Área de socios/as (matricular ou dar de baixa):', 'anpa-socios' ) . ' %s' . "\n\n" .
					__( 'Avisaremos por correo cando o prazo estea a punto de rematar.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'trimestre', 'extraescolares_url', 'login_url', 'contact_email' ),
				),
			),
			'matriculas_pechadas' => array(
				'subject' => array(
					__( 'Pechado o prazo de matrícula das extraescolares (%s trimestre) — %s', 'anpa-socios' ),
					array( 'trimestre', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O prazo de altas e baixas nas actividades extraescolares do <strong>%s trimestre</strong> quedou pechado. Os listados envíanse agora ás empresas que imparten as actividades.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Se precisades matricular fóra de prazo, podedes deixar unha solicitude na área de socios/as: quedará pendente de aprobación pola directiva e atenderase se hai prazas.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'trimestre', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'O prazo de altas e baixas nas actividades extraescolares do %s trimestre quedou pechado. Os listados envíanse agora ás empresas que imparten as actividades.', 'anpa-socios' ) . "\n\n" .
					__( 'Se precisades matricular fóra de prazo, podedes deixar unha solicitude na área de socios/as: quedará pendente de aprobación pola directiva e atenderase se hai prazas.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'trimestre', 'contact_email' ),
				),
			),
			// Matrículas pendentes de aprobación (solicitadas co prazo pechado).
			'matricula_pendente' => array(
				'subject' => array(
					__( 'Solicitude de matrícula recibida: %s en %s — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Recibimos a solicitude de matrícula de <strong>%s</strong> na actividade <strong>%s</strong> (%s).', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O prazo de matrícula está pechado, así que a solicitude queda <strong>pendente de aprobación</strong> pola directiva. Cando a revisemos recibiredes outro correo dicindo se hai praza ou se queda en lista de espera.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Se xa non a queredes manter, podedes retirala dende a área de socios/as, no apartado «Extraescolares».', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'actividade', 'grupo', 'contact_email' ),
				),
				'text' => array(
					__( 'Recibimos a solicitude de matrícula de %s na actividade %s (%s).', 'anpa-socios' ) . "\n\n" .
					__( 'O prazo de matrícula está pechado, así que a solicitude queda pendente de aprobación pola directiva. Cando a revisemos recibiredes outro correo dicindo se hai praza ou se queda en lista de espera.', 'anpa-socios' ) . "\n\n" .
					__( 'Se xa non a queredes manter, podedes retirala dende a área de socios/as, no apartado «Extraescolares».', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'contact_email' ),
				),
			),
			'matricula_aprobada_praza' => array(
				'subject' => array(
					__( 'Matrícula confirmada: %s en %s — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'A directiva aprobou a solicitude: <strong>%s</strong> ten praza na actividade <strong>%s</strong> (%s).', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Podedes ver a matrícula na área de socios/as: <a href="%s">%s</a>. O cobro da actividade faino directamente a empresa que a imparte.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'actividade', 'grupo', 'login_url', 'login_url', 'contact_email' ),
				),
				'text' => array(
					__( 'A directiva aprobou a solicitude: %s ten praza na actividade %s (%s).', 'anpa-socios' ) . "\n\n" .
					__( 'Podedes ver a matrícula na área de socios/as: %s. O cobro da actividade faino directamente a empresa que a imparte.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'login_url', 'contact_email' ),
				),
			),
			'matricula_aprobada_espera' => array(
				'subject' => array(
					__( 'Solicitude aprobada, en lista de espera: %s en %s — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'A directiva aprobou a solicitude de <strong>%s</strong> na actividade <strong>%s</strong> (%s), pero neste momento non hai praza libre: queda en <strong>lista de espera</strong> (posición %s).', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Cando quede unha praza libre recibiredes un correo cunha oferta que teredes que aceptar dende a área de socios/as nun prazo de tres días.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'actividade', 'grupo', 'posicion', 'contact_email' ),
				),
				'text' => array(
					__( 'A directiva aprobou a solicitude de %s na actividade %s (%s), pero neste momento non hai praza libre: queda en lista de espera (posición %s).', 'anpa-socios' ) . "\n\n" .
					__( 'Cando quede unha praza libre recibiredes un correo cunha oferta que teredes que aceptar dende a área de socios/as nun prazo de tres días.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'posicion', 'contact_email' ),
				),
			),
			'matricula_rexeitada' => array(
				'subject' => array(
					__( 'Solicitude de matrícula non aceptada: %s en %s — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'A directiva non puido aceptar a solicitude de matrícula de <strong>%s</strong> na actividade <strong>%s</strong>.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Se credes que houbo un erro ou queredes máis información, ponte en contacto coa directiva en %s para solucionalo.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'actividade', 'contact_email' ),
				),
				'text' => array(
					__( 'A directiva non puido aceptar a solicitude de matrícula de %s na actividade %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Se credes que houbo un erro ou queredes máis información, ponte en contacto coa directiva en %s para solucionalo.', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'contact_email' ),
				),
			),
			// Avisos por grupo (Grupos e horarios), só coas matrículas pechadas.
			'grupo_comezo_trimestre' => array(
				'subject' => array(
					__( 'Comeza o %s trimestre: %s (%s) — %s', 'anpa-socios' ),
					array( 'trimestre', 'actividade', 'grupo', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Prezadas familias e empresa,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O grupo <strong>%s</strong> da actividade <strong>%s</strong> queda confirmado e comeza o <strong>%s trimestre</strong>. Horario: %s.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Este correo vai ás familias inscritas e á empresa que imparte a actividade. Lembrade que o cobro da actividade faino directamente a empresa e que as baixas se solicitan dende a área de socios/as.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'grupo', 'actividade', 'trimestre', 'horario', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias e empresa,', 'anpa-socios' ) . "\n\n" .
					__( 'O grupo %s da actividade %s queda confirmado e comeza o %s trimestre. Horario: %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Este correo vai ás familias inscritas e á empresa que imparte a actividade. Lembrade que o cobro da actividade faino directamente a empresa e que as baixas se solicitan dende a área de socios/as.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'trimestre', 'horario', 'contact_email' ),
				),
			),
			'grupo_comezo_espera' => array(
				'subject' => array(
					__( 'Lista de espera: %s (%s), %s trimestre — %s', 'anpa-socios' ),
					array( 'actividade', 'grupo', 'trimestre', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O grupo <strong>%s</strong> da actividade <strong>%s</strong> queda confirmado e comeza o <strong>%s trimestre</strong>, pero está completo: o voso fillo/a segue en <strong>lista de espera</strong>.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'No momento en que quede un posto libre recibiredes un correo cunha oferta de praza que teredes que aceptar dende a área de socios/as nun prazo de tres días.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'grupo', 'actividade', 'trimestre', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'O grupo %s da actividade %s queda confirmado e comeza o %s trimestre, pero está completo: o voso fillo/a segue en lista de espera.', 'anpa-socios' ) . "\n\n" .
					__( 'No momento en que quede un posto libre recibiredes un correo cunha oferta de praza que teredes que aceptar dende a área de socios/as nun prazo de tres días.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'trimestre', 'contact_email' ),
				),
			),
			'grupo_pechado_minimo' => array(
				'subject' => array(
					__( 'Non se forma o grupo %s de %s — %s', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Prezadas familias e empresa,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Sentímolo: o grupo <strong>%s</strong> da actividade <strong>%s</strong> non acadou o mínimo de alumnado necesario e non se vai formar.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'As matrículas e as solicitudes de lista de espera deste grupo quedan anuladas, sen ningún cobro. Se hai outros grupos ou actividades con prazas, podedes matricular dende a área de socios/as.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'grupo', 'actividade', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias e empresa,', 'anpa-socios' ) . "\n\n" .
					__( 'Sentímolo: o grupo %s da actividade %s non acadou o mínimo de alumnado necesario e non se vai formar.', 'anpa-socios' ) . "\n\n" .
					__( 'As matrículas e as solicitudes de lista de espera deste grupo quedan anuladas, sen ningún cobro. Se hai outros grupos ou actividades con prazas, podedes matricular dende a área de socios/as.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'contact_email' ),
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
