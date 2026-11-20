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
			// 1.93.0: to both parents, with the pupil, the group and its real state. New variables
			// are appended so a template edited before keeps its placeholders in place.
			'oferta_extraescolar' => array(
				'subject' => array(
					__( 'Oferta de praza: %2$s en %1$s — %3$s', 'anpa-socios' ),
					array( 'actividade', 'alumno', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Quedou libre unha praza para <strong>%3$s</strong> na actividade <strong>%1$s</strong> (%4$s) e correspóndevos por ser os seguintes na lista de espera.', 'anpa-socios' ) . '</p>' .
					'<p><strong>%7$s</strong></p>' .
					'<p>' . __( 'Tedes <strong>%2$s días</strong> para aceptala ou rexeitala dende a área de socios/as, no apartado «Extraescolares». Se a aceptades, confirmarase a matrícula e recibiredes un correo de confirmación. Se a rexeitades ou non respondedes a tempo, segue na lista de espera, pero ao final.', 'anpa-socios' ) . '</p>' .
					'<p>%5$s</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %6$s.', 'anpa-socios' ) . '</p>',
					array( 'actividade', 'dias_prazo', 'alumno', 'grupo', 'estado_grupo', 'contact_email', 'comezo' ),
				),
				'text' => array(
					__( 'Ola,', 'anpa-socios' ) . "\n\n" .
					__( 'Quedou libre unha praza para %3$s na actividade %1$s (%4$s) e correspóndevos por ser os seguintes na lista de espera.', 'anpa-socios' ) . "\n\n" .
					'%7$s' . "\n\n" .
					__( 'Tedes %2$s días para aceptala ou rexeitala dende a área de socios/as, no apartado «Extraescolares». Se a aceptades, confirmarase a matrícula e recibiredes un correo de confirmación. Se a rexeitades ou non respondedes a tempo, segue na lista de espera, pero ao final.', 'anpa-socios' ) . "\n\n" .
					'%5$s' . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %6$s.', 'anpa-socios' ),
					array( 'actividade', 'dias_prazo', 'alumno', 'grupo', 'estado_grupo', 'contact_email', 'comezo' ),
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
					'<p>' . __( 'Grazas por formar parte da asociación.', 'anpa-socios' ) . '</p>' .
					// 1.83.0: appended last so a customised template keeps its placeholders in place.
					'<p>' . __( 'Gardaremos os vosos datos ata o %s. Pasado ese prazo eliminaranse por completo da base de datos da asociación, sen posibilidade de recuperalos. Se volvedes antes, recuperades os vosos datos e os dos vosos fillos/as.', 'anpa-socios' ) . '</p>',
					array( 'nome', 'association_name', 'emails_baixa', 'contact_email', 'data_eliminacion' ),
				),
				'text' => array(
					__( 'Ola %s,', 'anpa-socios' ) . "\n\n" .
					__( 'A directiva de %s confirmou a baixa da vosa unidade familiar como socios/as. A partir de agora xa non tedes acceso á área de socios nin ás actividades extraescolares xestionadas pola asociación.', 'anpa-socios' ) . "\n\n" .
					__( 'Correos dados de baixa como socios/as:', 'anpa-socios' ) . ' %s' . "\n\n" .
					__( 'Se cres que se trata dun erro ou queres volver a ser socio/a, escribe á directiva en %s e solucionámolo.', 'anpa-socios' ) . "\n\n" .
					__( 'Grazas por formar parte da asociación.', 'anpa-socios' ) . "\n\n" .
					__( 'Gardaremos os vosos datos ata o %s. Pasado ese prazo eliminaranse por completo da base de datos da asociación, sen posibilidade de recuperalos. Se volvedes antes, recuperades os vosos datos e os dos vosos fillos/as.', 'anpa-socios' ),
					array( 'nome', 'association_name', 'emails_baixa', 'contact_email', 'data_eliminacion' ),
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
			// 1.91.0: the company told the junta the family asked it for the baixa; the
			// family has PRAZO_HORAS to say it is a mistake before it becomes effective.
			'baixa_extraescolar_programada' => array(
				'subject' => array(
					__( 'Baixa da actividade %s comunicada pola empresa — %s', 'anpa-socios' ),
					array( 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola,', 'anpa-socios' ) . '</p>' .
					'<p>' . sprintf( __( 'A empresa %s comunicou á directiva de %%s que solicitastes a baixa de %s na actividade %s.', 'anpa-socios' ), '<strong>%s</strong>', '<strong>%s</strong>', '<strong>%s</strong>' ) . '</p>' .
					// 1.93.1: confirmed at that hour; it takes effect then or at the end of the trimester (%9$s).
					'<p>' . __( 'Rexistramos a baixa e confírmase o <strong>%5$s</strong>, salvo que nos digades antes que é un erro. %9$s Nese momento recibiredes outro correo coa confirmación.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( '<strong>Se credes que é un erro</strong>, confirmádeo coa directiva en %6$s antes desa hora, ou anulade a baixa desde a vosa área de socios (<a href="%7$s">%8$s</a>, botón «Anular baixa»): a matrícula segue entón como ata agora.', 'anpa-socios' ) . '</p>',
					array( 'empresa', 'association_name', 'alumno', 'actividade', 'data_efectiva', 'contact_email', 'login_url', 'login_url', 'efecto_texto' ),
				),
				'text' => array(
					__( 'Ola,', 'anpa-socios' ) . "\n\n" .
					__( 'A empresa %s comunicou á directiva de %s que solicitastes a baixa de %s na actividade %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Rexistramos a baixa e confírmase o %5$s, salvo que nos digades antes que é un erro. %8$s Nese momento recibiredes outro correo coa confirmación.', 'anpa-socios' ) . "\n\n" .
					__( 'Se credes que é un erro, confirmádeo coa directiva en %6$s antes desa hora, ou anulade a baixa desde a vosa área de socios (%7$s, botón «Anular baixa»): a matrícula segue entón como ata agora.', 'anpa-socios' ),
					array( 'empresa', 'association_name', 'alumno', 'actividade', 'data_efectiva', 'contact_email', 'login_url', 'efecto_texto' ),
				),
			),
			// 1.62.0: acknowledgement to the family when it REQUESTS a baixa from the
			// area: the process is manual, an administrator confirms it within days,
			// and the ANPA is run by parents in their free time.
			// 1.79.0: request made with the course running — effective only at its end.
			'baixa_socio_solicitada_curso' => array(
				'subject' => array(
					__( 'Recibimos a túa solicitude de baixa: será efectiva ao remate do curso — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola %s,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Rexistramos a túa solicitude de baixa como socio/a de %s.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Tal e como se indicaba no momento da alta, <strong>unha vez iniciado o curso a baixa non se fai efectiva ata o seu remate</strong> (%s). Mentres tanto segues sendo socio/a e a cota do curso mantense.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Se o que queres é deixar de recibir correos da asociación, podes manter a solicitude: queda rexistrada e a baixa farase efectiva ao rematar o curso.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Podes anular a solicitude en calquera momento: entra na túa área de socios (<a href="%s">%s</a>) e preme «Anular solicitude de baixa».', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribe á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'nome', 'association_name', 'data_remate', 'login_url', 'login_url', 'contact_email' ),
				),
				'text' => array(
					__( 'Ola %s,', 'anpa-socios' ) . "\n\n" .
					__( 'Rexistramos a túa solicitude de baixa como socio/a de %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Tal e como se indicaba no momento da alta, unha vez iniciado o curso a baixa non se fai efectiva ata o seu remate (%s). Mentres tanto segues sendo socio/a e a cota do curso mantense.', 'anpa-socios' ) . "\n\n" .
					__( 'Se o que queres é deixar de recibir correos da asociación, podes manter a solicitude: queda rexistrada e a baixa farase efectiva ao rematar o curso.', 'anpa-socios' ) . "\n\n" .
					__( 'Podes anular a solicitude en calquera momento: entra na túa área de socios (%s) e preme «Anular solicitude de baixa».', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribe á directiva en %s.', 'anpa-socios' ),
					array( 'nome', 'association_name', 'data_remate', 'login_url', 'contact_email' ),
				),
			),
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
			// Operacións → Lista Gmail) and forwarded from Gmail to the «Socios Web ANPA»
			// label, so WordPress never mails hundreds of families at once.
			'inicio_curso' => array(
				'subject' => array(
					__( 'Comeza o curso: socios/as e actividades extraescolares — %s', 'anpa-socios' ),
					array( 'association_name' ),
				),
				// 1.68.1: modelled on the junta's own start-of-year mail (2026-09).
				// 1.93.0: one email for the start of the course — it also carries the end of the
				// initial enrolment period (used to form the groups) and the first day of the
				// activities, which the separate «prazo_matriculas» reminder used to give.
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p><strong>' . __( 'Xa está aberta a inscrición nas actividades extraescolares!', 'anpa-socios' ) . '</strong> ' . __( 'E con ela o prazo para facerse socio/a de %1$s.', 'anpa-socios' ) . '</p>' .
					'<p><strong>' . __( 'Prazo inicial de inscrición: ata o %9$s.', 'anpa-socios' ) . '</strong> ' . __( 'Con estas inscricións formamos os grupos: cada actividade precisa o mínimo de participantes que esixe a empresa, e os grupos que non o acaden non se poderán formar. Despois desa data enviamos os listados ás empresas e avisaremos a cada familia de se o seu grupo se forma.', 'anpa-socios' ) . '</p>' .
					'<p><strong>' . __( 'As actividades comezan o %10$s.', 'anpa-socios' ) . '</strong></p>' .
					'<p>' . __( 'Antes de que remate o prazo, revisade que a actividade e o horario escollidos son os correctos: un erro pode facer que un grupo non se forme ou que outra familia quede sen praza. Se quedades en lista de espera, podedes manter o posto ou inscribirvos noutra actividade con prazas libres.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Despois do prazo inicial podedes seguir inscribíndovos durante todo o curso: as novas inscricións quedan pendentes de aprobación e confirmarémosvolas por correo (praza ou lista de espera).', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Información e proceso paso a paso (alta como socios/as e inscrición):', 'anpa-socios' ) . '</strong> <a href="%2$s">%3$s</a></li>' .
					'<li><strong>' . __( 'Oferta de actividades e horarios semanais:', 'anpa-socios' ) . '</strong> <a href="%4$s">%5$s</a></li>' .
					'<li><strong>' . __( 'Área de socios/as', 'anpa-socios' ) . '</strong> ' . __( '(entrar co teu correo, darse de alta, revisar os datos e matricular):', 'anpa-socios' ) . ' <a href="%6$s">%7$s</a></li>' .
					'</ul>' .
					'<p><strong>' . __( 'Aviso importante sobre os pagos:', 'anpa-socios' ) . '</strong> ' . __( 'a ANPA encárgase unicamente de xestionar as inscricións e a organización. O cobro das actividades faino directamente cada empresa coas familias inscritas.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %8$s.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Grazas pola vosa colaboración. Un saúdo.', 'anpa-socios' ) . '</p>',
					array( 'association_name', 'instrucions_url', 'instrucions_url', 'extraescolares_url', 'extraescolares_url', 'login_url', 'login_url', 'contact_email', 'data_peche', 'data_inicio_actividades' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'Xa está aberta a inscrición nas actividades extraescolares!', 'anpa-socios' ) . ' ' . __( 'E con ela o prazo para facerse socio/a de %1$s.', 'anpa-socios' ) . "\n\n" .
					__( 'Prazo inicial de inscrición: ata o %6$s.', 'anpa-socios' ) . ' ' . __( 'Con estas inscricións formamos os grupos: cada actividade precisa o mínimo de participantes que esixe a empresa, e os grupos que non o acaden non se poderán formar. Despois desa data enviamos os listados ás empresas e avisaremos a cada familia de se o seu grupo se forma.', 'anpa-socios' ) . "\n\n" .
					__( 'As actividades comezan o %7$s.', 'anpa-socios' ) . "\n\n" .
					__( 'Antes de que remate o prazo, revisade que a actividade e o horario escollidos son os correctos: un erro pode facer que un grupo non se forme ou que outra familia quede sen praza. Se quedades en lista de espera, podedes manter o posto ou inscribirvos noutra actividade con prazas libres.', 'anpa-socios' ) . "\n\n" .
					__( 'Despois do prazo inicial podedes seguir inscribíndovos durante todo o curso: as novas inscricións quedan pendentes de aprobación e confirmarémosvolas por correo (praza ou lista de espera).', 'anpa-socios' ) . "\n\n" .
					'- ' . __( 'Información e proceso paso a paso (alta como socios/as e inscrición):', 'anpa-socios' ) . ' %2$s' . "\n" .
					'- ' . __( 'Oferta de actividades e horarios semanais:', 'anpa-socios' ) . ' %3$s' . "\n" .
					'- ' . __( 'Área de socios/as', 'anpa-socios' ) . ' ' . __( '(entrar co teu correo, darse de alta, revisar os datos e matricular):', 'anpa-socios' ) . ' %4$s' . "\n\n" .
					__( 'Aviso importante sobre os pagos:', 'anpa-socios' ) . ' ' . __( 'a ANPA encárgase unicamente de xestionar as inscricións e a organización. O cobro das actividades faino directamente cada empresa coas familias inscritas.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %5$s.', 'anpa-socios' ) . "\n\n" .
					__( 'Grazas pola vosa colaboración. Un saúdo.', 'anpa-socios' ),
					array( 'association_name', 'instrucions_url', 'extraescolares_url', 'login_url', 'contact_email', 'data_peche', 'data_inicio_actividades' ),
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
				// 1.93.0: only used in the per-trimester mode; no «we will remind you» line any more.
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Xa están abertas as matrículas (altas e baixas) nas actividades extraescolares para o <strong>%s trimestre</strong>.', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Oferta de actividades e horarios:', 'anpa-socios' ) . '</strong> <a href="%s">%s</a></li>' .
					'<li><strong>' . __( 'Área de socios/as (matricular ou dar de baixa):', 'anpa-socios' ) . '</strong> <a href="%s">%s</a></li>' .
					'</ul>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'trimestre', 'extraescolares_url', 'extraescolares_url', 'login_url', 'login_url', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'Xa están abertas as matrículas (altas e baixas) nas actividades extraescolares para o %s trimestre.', 'anpa-socios' ) . "\n\n" .
					'- ' . __( 'Oferta de actividades e horarios:', 'anpa-socios' ) . ' %s' . "\n" .
					'- ' . __( 'Área de socios/as (matricular ou dar de baixa):', 'anpa-socios' ) . ' %s' . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'trimestre', 'extraescolares_url', 'login_url', 'contact_email' ),
				),
			),
			'matriculas_pechadas' => array(
				'subject' => array(
					__( 'Pechado o prazo de inscrición das extraescolares (%s trimestre) — %s', 'anpa-socios' ),
					array( 'trimestre', 'association_name' ),
				),
				// 1.93.0: enrolments go on after the free period, pending approval.
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Pechou o prazo de inscrición do <strong>%s trimestre</strong>. Enviamos os listados ás empresas que imparten as actividades e estamos a formar os grupos: avisaremos a cada familia de se o seu grupo se forma.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Podedes seguir inscribíndovos durante o curso dende a área de socios/as: as novas inscricións quedan pendentes de aprobación e confirmarémosvolas por correo (praza ou lista de espera).', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'trimestre', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'Pechou o prazo de inscrición do %s trimestre. Enviamos os listados ás empresas que imparten as actividades e estamos a formar os grupos: avisaremos a cada familia de se o seu grupo se forma.', 'anpa-socios' ) . "\n\n" .
					__( 'Podedes seguir inscribíndovos durante o curso dende a área de socios/as: as novas inscricións quedan pendentes de aprobación e confirmarémosvolas por correo (praza ou lista de espera).', 'anpa-socios' ) . "\n\n" .
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
				// 1.93.0: enrolments are open all year: no «prazo pechado» any more; the group's real
				// state is appended (never a word about the automatic approval).
				'html' => array(
					'<p>' . __( 'Recibimos a solicitude de matrícula de <strong>%1$s</strong> na actividade <strong>%2$s</strong> (%3$s).', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'A solicitude queda <strong>pendente de aprobación</strong> pola directiva. Cando se aprobe recibiredes un correo de confirmación: se hai praza libre no grupo, entrará nel; se o grupo está completo, quedará en lista de espera e avisarémosvos cando quede un posto libre.', 'anpa-socios' ) . '</p>' .
					'<p>%5$s</p>' .
					'<p>' . __( 'Mentres estea pendente, podedes retirala dende a área de socios/as, no apartado «Extraescolares».', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %4$s.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'actividade', 'grupo', 'contact_email', 'estado_grupo' ),
				),
				'text' => array(
					__( 'Recibimos a solicitude de matrícula de %1$s na actividade %2$s (%3$s).', 'anpa-socios' ) . "\n\n" .
					__( 'A solicitude queda pendente de aprobación pola directiva. Cando se aprobe recibiredes un correo de confirmación: se hai praza libre no grupo, entrará nel; se o grupo está completo, quedará en lista de espera e avisarémosvos cando quede un posto libre.', 'anpa-socios' ) . "\n\n" .
					'%5$s' . "\n\n" .
					__( 'Mentres estea pendente, podedes retirala dende a área de socios/as, no apartado «Extraescolares».', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %4$s.', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'contact_email', 'estado_grupo' ),
				),
			),
			'matricula_aprobada_praza' => array(
				'subject' => array(
					__( 'Matrícula confirmada: %s en %s — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'association_name' ),
				),
				// 1.93.0: also for the automatic approval and a direct enrolment; with the group's state.
				'html' => array(
					'<p>' . __( 'Confirmamos a matrícula: <strong>%1$s</strong> ten praza na actividade <strong>%2$s</strong> (%3$s).', 'anpa-socios' ) . '</p>' .
					'<p>%7$s</p>' .
					'<p>' . __( 'Podedes ver a matrícula na área de socios/as: <a href="%4$s">%5$s</a>. O cobro da actividade faino directamente a empresa que a imparte, e só cando o grupo está en marcha.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %6$s.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'actividade', 'grupo', 'login_url', 'login_url', 'contact_email', 'estado_grupo' ),
				),
				'text' => array(
					__( 'Confirmamos a matrícula: %1$s ten praza na actividade %2$s (%3$s).', 'anpa-socios' ) . "\n\n" .
					'%6$s' . "\n\n" .
					__( 'Podedes ver a matrícula na área de socios/as: %4$s. O cobro da actividade faino directamente a empresa que a imparte, e só cando o grupo está en marcha.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %5$s.', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'login_url', 'contact_email', 'estado_grupo' ),
				),
			),
			'matricula_aprobada_espera' => array(
				'subject' => array(
					__( 'En lista de espera: %s en %s — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Confirmamos a solicitude de <strong>%1$s</strong> na actividade <strong>%2$s</strong> (%3$s), pero agora mesmo o grupo está completo: queda en <strong>lista de espera</strong> (posición %4$s).', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Cando quede unha praza libre recibiredes un correo cunha oferta que teredes que aceptar dende a área de socios/as nun prazo de tres días.', 'anpa-socios' ) . '</p>' .
					'<p>%6$s</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %5$s.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'actividade', 'grupo', 'posicion', 'contact_email', 'estado_grupo' ),
				),
				'text' => array(
					__( 'Confirmamos a solicitude de %1$s na actividade %2$s (%3$s), pero agora mesmo o grupo está completo: queda en lista de espera (posición %4$s).', 'anpa-socios' ) . "\n\n" .
					__( 'Cando quede unha praza libre recibiredes un correo cunha oferta que teredes que aceptar dende a área de socios/as nun prazo de tres días.', 'anpa-socios' ) . "\n\n" .
					'%6$s' . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %5$s.', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'posicion', 'contact_email', 'estado_grupo' ),
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
			// 1.71.0: avisos a metade de curso (matrículas pechadas) á empresa e ao comedor
			// (Para: xunta, CCO: empresa e comedor) e á familia que acepta unha praza.
			'matricula_alta_aviso' => array(
				'subject' => array(
					__( 'Alta no grupo: %s — %s (%s) — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Hai unha incorporación nova ao grupo <strong>%s</strong> da actividade <strong>%s</strong> (%sº trimestre):', 'anpa-socios' ) . '</p>' .
					'<ul><li>' . __( 'Alumno/a: <strong>%s</strong> (%s)', 'anpa-socios' ) . '</li>' .
					'<li>' . __( 'Empresa: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( '1º proxenitor: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( '2º proxenitor: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( 'Opcións e autorizacións: %s', 'anpa-socios' ) . '</li></ul>' .
					'<p>' . __( 'O listado actualizado está sempre no panel de empresa e do comedor da web. Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'grupo', 'actividade', 'trimestre', 'alumno', 'curso', 'empresa', 'proxenitor1', 'proxenitor2', 'opcions', 'contact_email' ),
				),
				'text' => array(
					__( 'Hai unha incorporación nova ao grupo %s da actividade %s (%sº trimestre):', 'anpa-socios' ) . "\n\n" .
					__( 'Alumno/a: %s (%s)', 'anpa-socios' ) . "\n" .
					__( 'Empresa: %s', 'anpa-socios' ) . "\n" .
					__( '1º proxenitor: %s', 'anpa-socios' ) . "\n" .
					__( '2º proxenitor: %s', 'anpa-socios' ) . "\n" .
					__( 'Opcións e autorizacións: %s', 'anpa-socios' ) . "\n\n" .
					__( 'O listado actualizado está sempre no panel de empresa e do comedor da web. Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'trimestre', 'alumno', 'curso', 'empresa', 'proxenitor1', 'proxenitor2', 'opcions', 'contact_email' ),
				),
			),
			'matricula_baixa_aviso' => array(
				'subject' => array(
					__( 'Baixa no grupo: %s — %s (%s) — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'A directiva confirmou a baixa dun alumno/a no grupo <strong>%s</strong> da actividade <strong>%s</strong>. %s', 'anpa-socios' ) . '</p>' .
					'<ul><li>' . __( 'Alumno/a: <strong>%s</strong> (%s)', 'anpa-socios' ) . '</li>' .
					'<li>' . __( 'Empresa: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( '1º proxenitor: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( '2º proxenitor: %s', 'anpa-socios' ) . '</li></ul>' .
					'<p>' . __( 'O listado actualizado está sempre no panel de empresa e do comedor da web. Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'grupo', 'actividade', 'efectos', 'alumno', 'curso', 'empresa', 'proxenitor1', 'proxenitor2', 'contact_email' ),
				),
				'text' => array(
					__( 'A directiva confirmou a baixa dun alumno/a no grupo %s da actividade %s. %s', 'anpa-socios' ) . "\n\n" .
					__( 'Alumno/a: %s (%s)', 'anpa-socios' ) . "\n" .
					__( 'Empresa: %s', 'anpa-socios' ) . "\n" .
					__( '1º proxenitor: %s', 'anpa-socios' ) . "\n" .
					__( '2º proxenitor: %s', 'anpa-socios' ) . "\n\n" .
					__( 'O listado actualizado está sempre no panel de empresa e do comedor da web. Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'efectos', 'alumno', 'curso', 'empresa', 'proxenitor1', 'proxenitor2', 'contact_email' ),
				),
			),
			// 1.91.0: baixa reported by the company — registered, effective in PRAZO_HORAS
			// unless the family says it is a mistake (Para: xunta, CCO: empresa).
			'matricula_baixa_programada_aviso' => array(
				'subject' => array(
					__( 'Baixa rexistrada: %s — %s (%s) — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Grazas polo aviso. A directiva rexistrou a baixa de <strong>%s</strong> (%s) no grupo <strong>%s</strong> da actividade <strong>%s</strong>.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Avisamos á familia: se é un erro ten ata o <strong>%5$s</strong> para confirmalo coa directiva. %7$s', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %6$s.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'curso', 'grupo', 'actividade', 'data_efectiva', 'contact_email', 'efecto_texto' ),
				),
				'text' => array(
					__( 'Grazas polo aviso. A directiva rexistrou a baixa de %s (%s) no grupo %s da actividade %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Avisamos á familia: se é un erro ten ata o %5$s para confirmalo coa directiva. %7$s', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %6$s.', 'anpa-socios' ),
					array( 'alumno', 'curso', 'grupo', 'actividade', 'data_efectiva', 'contact_email', 'efecto_texto' ),
				),
			),
			// 1.91.0: the reported baixa was cancelled (the family said it was a mistake).
			'matricula_baixa_anulada_aviso' => array(
				'subject' => array(
					__( 'Baixa anulada: %s — %s (%s) — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'A baixa de <strong>%s</strong> (%s) no grupo <strong>%s</strong> da actividade <strong>%s</strong> quedou <strong>anulada</strong>: a familia indicou que era un erro e a matrícula segue activa.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O listado actualizado está sempre no panel de empresa e do comedor da web. Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'alumno', 'curso', 'grupo', 'actividade', 'contact_email' ),
				),
				'text' => array(
					__( 'A baixa de %s (%s) no grupo %s da actividade %s quedou anulada: a familia indicou que era un erro e a matrícula segue activa.', 'anpa-socios' ) . "\n\n" .
					__( 'O listado actualizado está sempre no panel de empresa e do comedor da web. Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'alumno', 'curso', 'grupo', 'actividade', 'contact_email' ),
				),
			),
			'matricula_cambio_grupo_aviso' => array(
				'subject' => array(
					__( 'Cambio de grupo: %s — %s (%s) — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'grupo', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Un alumno/a cambia de grupo na actividade <strong>%s</strong>: deixa <strong>%s</strong> e pasa a <strong>%s</strong> (%sº trimestre).', 'anpa-socios' ) . '</p>' .
					'<ul><li>' . __( 'Alumno/a: <strong>%s</strong> (%s)', 'anpa-socios' ) . '</li>' .
					'<li>' . __( 'Empresa: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( '1º proxenitor: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( '2º proxenitor: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( 'Opcións e autorizacións: %s', 'anpa-socios' ) . '</li></ul>' .
					'<p>' . __( 'O listado actualizado está sempre no panel de empresa e do comedor da web. Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'actividade', 'grupo_orixe', 'grupo', 'trimestre', 'alumno', 'curso', 'empresa', 'proxenitor1', 'proxenitor2', 'opcions', 'contact_email' ),
				),
				'text' => array(
					__( 'Un alumno/a cambia de grupo na actividade %s: deixa %s e pasa a %s (%sº trimestre).', 'anpa-socios' ) . "\n\n" .
					__( 'Alumno/a: %s (%s)', 'anpa-socios' ) . "\n" .
					__( 'Empresa: %s', 'anpa-socios' ) . "\n" .
					__( '1º proxenitor: %s', 'anpa-socios' ) . "\n" .
					__( '2º proxenitor: %s', 'anpa-socios' ) . "\n" .
					__( 'Opcións e autorizacións: %s', 'anpa-socios' ) . "\n\n" .
					__( 'O listado actualizado está sempre no panel de empresa e do comedor da web. Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'actividade', 'grupo_orixe', 'grupo', 'trimestre', 'alumno', 'curso', 'empresa', 'proxenitor1', 'proxenitor2', 'opcions', 'contact_email' ),
				),
			),
			// 1.74.0: aviso semanal á xunta (luns 10:00) se hai algo en Operacións → Aprobacións.
			'aprobacions_pendentes_semanal' => array(
				'subject' => array(
					__( 'Revisión semanal de Xestión: aprobacións pendentes (%s) — %s', 'anpa-socios' ),
					array( 'total', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Resumo semanal de Xestión → Operacións → Aprobacións:', 'anpa-socios' ) . '</p>' .
					'<ul><li>' . __( 'Altas de socios/as: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( 'Matrículas pendentes de aprobación: %s', 'anpa-socios' ) . '</li>' .
					'<li>' . __( 'Baixas de socios/as: %s', 'anpa-socios' ) . '</li></ul>' .
					'<p><a href="%s">' . __( 'Abrir Aprobacións', 'anpa-socios' ) . '</a></p>' .
					'<p>' . __( 'Este aviso envíase os luns ás 10:00 só cando hai algo pendente ou a revisión atopa un problema. Contacto da directiva: %s.', 'anpa-socios' ) . '</p>' .
					// 1.77.0: appended last so a template customised in 1.74–1.76 keeps its placeholders in place.
					'<p><strong>' . __( 'Revisión de correos:', 'anpa-socios' ) . '</strong> %s</p>',
					array( 'socios', 'matriculas', 'baixas', 'xestion_url', 'contact_email', 'revision_correos' ),
				),
				'text' => array(
					__( 'Resumo semanal de Xestión → Operacións → Aprobacións:', 'anpa-socios' ) . "\n\n" .
					__( 'Altas de socios/as: %s', 'anpa-socios' ) . "\n" .
					__( 'Matrículas pendentes de aprobación: %s', 'anpa-socios' ) . "\n" .
					__( 'Baixas de socios/as: %s', 'anpa-socios' ) . "\n\n" .
					__( 'Abrir Aprobacións: %s', 'anpa-socios' ) . "\n\n" .
					__( 'Este aviso envíase os luns ás 10:00 só cando hai algo pendente ou a revisión atopa un problema. Contacto da directiva: %s.', 'anpa-socios' ) . "\n\n" .
					__( 'Revisión de correos:', 'anpa-socios' ) . ' %s',
					array( 'socios', 'matriculas', 'baixas', 'xestion_url', 'contact_email', 'revision_correos' ),
				),
			),
			// 1.92.0: avisos á xunta das inscricións en extraescolares que agardan aprobación.
			'matricula_pendente_xunta' => array(
				'subject' => array(
					__( 'Nova inscrición pendente de aprobación: %s en %s — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Unha familia inscribiu a un alumno/a nunha actividade extraescolar e a solicitude agarda a vosa aprobación:', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Alumno/a:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Actividade:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Grupo:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Solicitado por:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Estado do grupo:', 'anpa-socios' ) . '</strong> %6$s</li>' .
					'<li><strong>' . __( 'Aprobación automática:', 'anpa-socios' ) . '</strong> %7$s</li>' .
					'</ul>' .
					'<p><a href="%5$s">' . __( 'Revisar en Aprobacións', 'anpa-socios' ) . '</a></p>',
					array( 'alumno', 'actividade', 'grupo', 'email_socio', 'xestion_url', 'estado_grupo', 'aprobacion_automatica' ),
				),
				'text' => array(
					__( 'Unha familia inscribiu a un alumno/a nunha actividade extraescolar e a solicitude agarda a vosa aprobación:', 'anpa-socios' ) . "

" .
					__( 'Alumno/a:', 'anpa-socios' ) . ' %s' . "
" .
					__( 'Actividade:', 'anpa-socios' ) . ' %s' . "
" .
					__( 'Grupo:', 'anpa-socios' ) . ' %s' . "
" .
					__( 'Solicitado por:', 'anpa-socios' ) . ' %s' . "

" .
					__( 'Estado do grupo:', 'anpa-socios' ) . ' %6$s' . "\n" .
					__( 'Aprobación automática:', 'anpa-socios' ) . ' %7$s' . "\n\n" .
					__( 'Revisar en Aprobacións:', 'anpa-socios' ) . ' %5$s',
					array( 'alumno', 'actividade', 'grupo', 'email_socio', 'xestion_url', 'estado_grupo', 'aprobacion_automatica' ),
				),
			),
			'oferta_aceptada_xunta' => array(
				'subject' => array(
					__( 'Oferta de praza aceptada, pendente de confirmar: %s en %s — %s', 'anpa-socios' ),
					array( 'alumno', 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Unha familia aceptou a praza que se lle ofreceu dende a lista de espera. A praza queda reservada ata que a confirmedes en Aprobacións (ao aprobala avísase á empresa e ao comedor):', 'anpa-socios' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Alumno/a:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Actividade:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Grupo:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Aceptada por:', 'anpa-socios' ) . '</strong> %s</li>' .
					'<li><strong>' . __( 'Estado do grupo:', 'anpa-socios' ) . '</strong> %6$s</li>' .
					'<li><strong>' . __( 'Aprobación automática:', 'anpa-socios' ) . '</strong> %7$s</li>' .
					'</ul>' .
					'<p><a href="%5$s">' . __( 'Revisar en Aprobacións', 'anpa-socios' ) . '</a></p>',
					array( 'alumno', 'actividade', 'grupo', 'email_socio', 'xestion_url', 'estado_grupo', 'aprobacion_automatica' ),
				),
				'text' => array(
					__( 'Unha familia aceptou a praza que se lle ofreceu dende a lista de espera. A praza queda reservada ata que a confirmedes en Aprobacións (ao aprobala avísase á empresa e ao comedor):', 'anpa-socios' ) . "

" .
					__( 'Alumno/a:', 'anpa-socios' ) . ' %s' . "
" .
					__( 'Actividade:', 'anpa-socios' ) . ' %s' . "
" .
					__( 'Grupo:', 'anpa-socios' ) . ' %s' . "
" .
					__( 'Aceptada por:', 'anpa-socios' ) . ' %s' . "

" .
					__( 'Estado do grupo:', 'anpa-socios' ) . ' %6$s' . "\n" .
					__( 'Aprobación automática:', 'anpa-socios' ) . ' %7$s' . "\n\n" .
					__( 'Revisar en Aprobacións:', 'anpa-socios' ) . ' %5$s',
					array( 'alumno', 'actividade', 'grupo', 'email_socio', 'xestion_url', 'estado_grupo', 'aprobacion_automatica' ),
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
			// 1.85.0: the group stays «sen mínimo» (not created, still taking requests).
			// Families and the company; never the canteen (the group does not run yet).
			'grupo_sen_minimo' => array(
				'subject' => array(
					__( 'O grupo %s de %s aínda non se forma: seguimos admitindo inscricións — %s', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'association_name' ),
				),
				// 1.93.0: to the families with a place and the company only (the waiting list gets
				// «grupo_sen_minimo_espera»; pending requests hear on approval).
				'html' => array(
					'<p>' . __( 'Prezadas familias e empresa,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O grupo <strong>%s</strong> da actividade <strong>%s</strong> aínda non chega ao mínimo de alumnado, polo que de momento non comeza.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O grupo segue admitindo inscricións. Quen ten praza consérvaa e non se cobra nada mentres o grupo non se forme. Se se chega ao mínimo, avisaremos antes de comezar.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'grupo', 'actividade', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias e empresa,', 'anpa-socios' ) . "\n\n" .
					__( 'O grupo %s da actividade %s aínda non chega ao mínimo de alumnado, polo que de momento non comeza.', 'anpa-socios' ) . "\n\n" .
					__( 'O grupo segue admitindo inscricións. Quen ten praza consérvaa e non se cobra nada mentres o grupo non se forme. Se se chega ao mínimo, avisaremos antes de comezar.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'contact_email' ),
				),
			),
			// 1.93.0: the same moment for the families on the group's waiting list.
			'grupo_sen_minimo_espera' => array(
				'subject' => array(
					__( 'Lista de espera: o grupo %s de %s aínda non se forma — %s', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Prezadas familias,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O grupo <strong>%s</strong> da actividade <strong>%s</strong> aínda non chega ao mínimo de alumnado, polo que de momento non comeza.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'O voso posto na lista de espera mantense e avisarémosvos se o grupo se forma e queda praza.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ) . '</p>',
					array( 'grupo', 'actividade', 'contact_email' ),
				),
				'text' => array(
					__( 'Prezadas familias,', 'anpa-socios' ) . "\n\n" .
					__( 'O grupo %s da actividade %s aínda non chega ao mínimo de alumnado, polo que de momento non comeza.', 'anpa-socios' ) . "\n\n" .
					__( 'O voso posto na lista de espera mantense e avisarémosvos se o grupo se forma e queda praza.', 'anpa-socios' ) . "\n\n" .
					__( 'Para calquera dúbida, escribide á directiva en %s.', 'anpa-socios' ),
					array( 'grupo', 'actividade', 'contact_email' ),
				),
			),
			// 1.85.0: an offer not accepted in time (or turned down) → end of the waiting list.
			'oferta_final_lista' => array(
				'subject' => array(
					__( 'Lista de espera de %s — %s', 'anpa-socios' ),
					array( 'actividade', 'association_name' ),
				),
				'html' => array(
					'<p>' . __( 'Ola,', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Como %s, a praza ofrecida para <strong>%s</strong> en <strong>%s</strong> (grupo %s) ofrecerase á seguinte familia da lista de espera, se a hai.', 'anpa-socios' ) . '</p>' .
					'<p>' . __( 'Segue na lista de espera, agora na posición %s. Se queres saír dela, podes darte de baixa na área de socios/as.', 'anpa-socios' ) . '</p>',
					array( 'motivo', 'alumno', 'actividade', 'grupo', 'posicion' ),
				),
				'text' => array(
					__( 'Ola,', 'anpa-socios' ) . "\n\n" .
					__( 'Como %s, a praza ofrecida para %s en %s (grupo %s) ofrecerase á seguinte familia da lista de espera, se a hai.', 'anpa-socios' ) . "\n\n" .
					__( 'Segue na lista de espera, agora na posición %s. Se queres saír dela, podes darte de baixa na área de socios/as.', 'anpa-socios' ),
					array( 'motivo', 'alumno', 'actividade', 'grupo', 'posicion' ),
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
