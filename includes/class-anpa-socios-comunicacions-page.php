<?php
/**
 * Admin screen for the email queue: campaign list + per-recipient detail
 * (fase35, PR-35s5).
 *
 * Server rendered on purpose: everything on this screen is an audit surface, so
 * it must be readable without JavaScript and inspectable in review. Writes never
 * happen here — every button POSTs to ANPA_Socios_Email_Admin_Actions, which
 * checks the capability and the nonce.
 *
 * Terminology is deliberate: a message is "aceptada" (accepted by the mail
 * system), NEVER "entregada" (delivered). wp_mail() cannot report delivery.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Comunicacions_Page {

	/** Submenu slug. */
	const SLUG = 'anpa-socios-comunicacions';

	/** Capability required to see or act on communications. */
	const CAP = 'manage_options';

	/** Campaigns per page. */
	const PER_PAGE = 20;

	/**
	 * Registers the submenu entry.
	 *
	 * @since  1.39.0
	 * @param  string $parent_slug Parent menu slug.
	 * @param  string $capability  Capability required by wp-admin.
	 * @return void
	 */
	public static function register_menu( string $parent_slug, string $capability ): void {
		add_submenu_page(
			$parent_slug,
			esc_html__( 'Comunicacións', 'anpa-socios' ),
			esc_html__( 'Comunicacións', 'anpa-socios' ),
			$capability,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Renders the list or, when a campaign is selected, its detail.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation/state display.
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap anpa-comms-wrap">';
		echo '<h1>' . esc_html__( 'Comunicacións', 'anpa-socios' ) . '</h1>';

		self::render_result_notice();
		self::render_cron_notice();
		self::render_accepted_disclaimer();

		if ( $campaign_id > 0 && null !== ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id ) ) {
			self::render_detail( $campaign_id );
		} else {
			self::render_list( $paged );
		}

		echo '</div>';
	}

	/**
	 * Shows the outcome of the last admin action, if any.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	private static function render_result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; the action itself was nonce-checked.
		$msg  = isset( $_GET['anpa_msg'] ) ? sanitize_key( wp_unslash( $_GET['anpa_msg'] ) ) : '';
		$code = isset( $_GET['anpa_code'] ) ? sanitize_key( wp_unslash( $_GET['anpa_code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $msg ) {
			return;
		}

		$labels = array(
			'processed' => __( 'Procesouse un lote.', 'anpa-socios' ),
			'paused'    => __( 'Campaña pausada.', 'anpa-socios' ),
			'resumed'   => __( 'Campaña retomada.', 'anpa-socios' ),
			'cancelled' => __( 'Campaña cancelada.', 'anpa-socios' ),
			'retried'   => __( 'Volvéronse encolar os envíos fallidos.', 'anpa-socios' ),
		);
		$codes  = array(
			'blocked'                => __( 'Non se procesou: hai unha migración pendente ou o sitio está a instalarse.', 'anpa-socios' ),
			'locked'                 => __( 'Non se procesou: xa había outra execución en marcha.', 'anpa-socios' ),
			'time_budget'            => __( 'Parouse ao esgotar o tempo do lote; o resto queda pendente para a seguinte execución.', 'anpa-socios' ),
			'campaign_terminal'      => __( 'A campaña está pechada: para reenviar hai que crear unha campaña nova.', 'anpa-socios' ),
			'transition_not_allowed' => __( 'Esa acción non é posible no estado actual da campaña.', 'anpa-socios' ),
			'not_found'              => __( 'Non se atopou a campaña.', 'anpa-socios' ),
		);

		$text  = $labels[ $msg ] ?? __( 'Acción realizada.', 'anpa-socios' );
		$extra = $codes[ $code ] ?? '';
		$class = in_array( $code, array( 'blocked', 'campaign_terminal', 'transition_not_allowed', 'not_found' ), true )
			? 'notice notice-warning'
			: 'notice notice-success';

		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $text );
		if ( '' !== $extra ) {
			echo ' ' . esc_html( $extra );
		}
		echo '</p></div>';
	}

	/**
	 * Warns when the scheduled tick cannot advance the queue, and offers a manual
	 * run. Each problem gets its own concrete instruction.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	private static function render_cron_notice(): void {
		$health = ANPA_Socios_Email_Cron::health();
		if ( ! empty( $health['ok'] ) ) {
			return;
		}

		$messages = array(
			'disabled'    => __( 'A tarefa programada de WordPress está desactivada (DISABLE_WP_CRON). O servidor debe chamar wp-cron.php periodicamente (por exemplo, cada 5 minutos) ou os correos non sairán.', 'anpa-socios' ),
			'unscheduled' => __( 'A tarefa da cola de correo non está programada. Volve gardar os axustes ou reactiva o plugin para reprogramala.', 'anpa-socios' ),
			'stalled'     => __( 'A cola non se procesou dende hai bastante tempo. WP-Cron só se dispara con visitas: nun sitio con pouco tráfico é necesario un cron real do servidor.', 'anpa-socios' ),
		);

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'A cola de correo pode estar detida', 'anpa-socios' ) . '</strong></p>';
		echo '<ul style="list-style:disc;margin-left:20px">';
		foreach ( (array) $health['problems'] as $problem ) {
			if ( isset( $messages[ $problem ] ) ) {
				echo '<li>' . esc_html( $messages[ $problem ] ) . '</li>';
			}
		}
		echo '</ul>';

		if ( '' !== (string) $health['last_run_utc'] ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: UTC datetime. */
					__( 'Última execución rexistrada: %s (UTC).', 'anpa-socios' ),
					(string) $health['last_run_utc']
				)
			) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Aínda non se rexistrou ningunha execución.', 'anpa-socios' ) . '</p>';
		}

		self::render_action_button( ANPA_Socios_Email_Admin_Actions::ACTION_PROCESS, 0, __( 'Procesar agora', 'anpa-socios' ), 'button button-primary' );
		echo '</div>';
	}

	/**
	 * States the accepted/delivered distinction once, where operators read it.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	private static function render_accepted_disclaimer(): void {
		echo '<p class="description">' . esc_html__( '“Aceptado” significa que o sistema de correo aceptou a mensaxe, non que chegase á persoa destinataria. Se unha execución se interrompe despois de enviar, a mensaxe pode repetirse: eses casos márcanse como “incerto”.', 'anpa-socios' ) . '</p>';
	}

	/**
	 * Renders the campaign list.
	 *
	 * @since  1.39.0
	 * @param  int $paged Current page (1-based).
	 * @return void
	 */
	private static function render_list( int $paged ): void {
		$total     = ANPA_Socios_Email_Queue_Repo::count_campaigns();
		$campaigns = ANPA_Socios_Email_Queue_Repo::list_campaigns( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		if ( array() === $campaigns ) {
			echo '<p>' . esc_html__( 'Aínda non hai campañas de correo.', 'anpa-socios' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Campaña', 'anpa-socios' ),
			__( 'Estado', 'anpa-socios' ),
			__( 'Total', 'anpa-socios' ),
			__( 'Pendentes', 'anpa-socios' ),
			__( 'Aceptados', 'anpa-socios' ),
			__( 'Fallidos', 'anpa-socios' ),
			__( 'Cancelados', 'anpa-socios' ),
			__( 'Creada (UTC)', 'anpa-socios' ),
			__( 'Accións', 'anpa-socios' ),
		) as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $campaigns as $campaign ) {
			$id  = (int) $campaign['id'];
			$url = add_query_arg(
				array( 'page' => self::SLUG, 'campaign_id' => $id ),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $campaign['event_type'] ) . '</a><br /><span class="description">#' . esc_html( (string) $id ) . '</span></td>';
			echo '<td>' . self::state_cell( self::campaign_state_label( (string) $campaign['state'] ), (string) $campaign['state'] ) . '</td>';
			foreach ( array( 'total', 'pending_count', 'accepted_count', 'failed_count', 'cancelled_count' ) as $col ) {
				echo '<td>' . esc_html( (string) (int) $campaign[ $col ] ) . '</td>';
			}
			echo '<td>' . esc_html( (string) $campaign['created_at_utc'] ) . '</td>';
			echo '<td>';
			self::render_campaign_actions( $campaign );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		self::render_pagination( $total, $paged );
	}

	/**
	 * Renders one campaign's detail: its recipients and their attempt history.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return void
	 */
	private static function render_detail( int $campaign_id ): void {
		$campaign = ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id );
		$back     = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );

		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Volver ás campañas', 'anpa-socios' ) . '</a></p>';

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: 1: event type, 2: campaign id. */
				__( '%1$s (campaña #%2$d)', 'anpa-socios' ),
				(string) $campaign['event_type'],
				$campaign_id
			)
		) . '</h2>';

		echo '<p>' . esc_html__( 'Estado:', 'anpa-socios' ) . ' ' . self::state_cell( self::campaign_state_label( (string) $campaign['state'] ), (string) $campaign['state'] ) . '</p>';

		if ( ANPA_Socios_Email_Queue_Repo::has_uncertain_attempts( $campaign_id ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Esta campaña ten envíos cun resultado incerto: unha execución interrompeuse despois de enviar, así que algunha persoa pode ter recibido a mensaxe dúas veces.', 'anpa-socios' ) . '</p></div>';
		}

		echo '<p>';
		self::render_campaign_actions( $campaign );
		echo '</p>';

		$recipients = ANPA_Socios_Email_Queue_Repo::list_recipients( $campaign_id, 200 );
		if ( array() === $recipients ) {
			echo '<p>' . esc_html__( 'Esta campaña non ten destinatarios.', 'anpa-socios' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Destinatario', 'anpa-socios' ),
			__( 'Estado', 'anpa-socios' ),
			__( 'Intentos', 'anpa-socios' ),
			__( 'Seguinte intento (UTC)', 'anpa-socios' ),
			__( 'Último erro', 'anpa-socios' ),
			__( 'Historial', 'anpa-socios' ),
		) as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $recipients as $recipient ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $recipient['email'] ) . '<br /><span class="description">' . esc_html( (string) $recipient['message_key'] ) . '</span></td>';
			echo '<td>' . self::state_cell( self::recipient_state_label( (string) $recipient['state'] ), (string) $recipient['state'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $recipient['attempts'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $recipient['next_attempt_at_utc'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $recipient['last_error'] ) . '</td>';
			echo '<td>' . self::attempts_summary( (int) $recipient['id'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Compact attempt history for one recipient.
	 *
	 * @since  1.39.0
	 * @param  int $recipient_id Recipient id.
	 * @return string Escaped markup.
	 */
	private static function attempts_summary( int $recipient_id ): string {
		$attempts = ANPA_Socios_Email_Queue_Repo::list_attempts( $recipient_id );
		if ( array() === $attempts ) {
			return esc_html__( 'sen intentos', 'anpa-socios' );
		}

		$results = array(
			'accepted'  => __( 'aceptado', 'anpa-socios' ),
			'failed'    => __( 'fallo', 'anpa-socios' ),
			'uncertain' => __( 'incerto', 'anpa-socios' ),
		);

		$items = '';
		foreach ( $attempts as $attempt ) {
			$result = (string) $attempt['result'];
			$label  = $results[ $result ] ?? $result;
			$line   = sprintf(
				/* translators: 1: attempt number, 2: result label, 3: UTC datetime. */
				__( '#%1$d %2$s — %3$s', 'anpa-socios' ),
				(int) $attempt['attempt_no'],
				$label,
				(string) $attempt['started_at_utc']
			);
			if ( '' !== (string) $attempt['error_category'] ) {
				$line .= ' (' . (string) $attempt['error_category'] . ')';
			}
			$items .= '<li>' . esc_html( $line ) . '</li>';
		}

		return '<ul style="margin:0">' . $items . '</ul>';
	}

	/**
	 * Renders the action buttons available in the campaign's current state. Only
	 * legal transitions are offered, so the UI never invites a refused action.
	 *
	 * @since  1.39.0
	 * @param  array<string,mixed> $campaign Campaign row.
	 * @return void
	 */
	private static function render_campaign_actions( array $campaign ): void {
		$id    = (int) $campaign['id'];
		$state = (string) $campaign['state'];

		if ( ANPA_Socios_Email_Campaign_State::terminal( $state ) ) {
			echo '<span class="description">' . esc_html__( 'Campaña pechada.', 'anpa-socios' ) . '</span>';
			return;
		}

		if ( ANPA_Socios_Email_Campaign_State::RUNNING === $state || ANPA_Socios_Email_Campaign_State::PENDING === $state ) {
			self::render_action_button( ANPA_Socios_Email_Admin_Actions::ACTION_PROCESS, $id, __( 'Procesar agora', 'anpa-socios' ) );
		}
		if ( ANPA_Socios_Email_Campaign_State::RUNNING === $state ) {
			self::render_action_button( ANPA_Socios_Email_Admin_Actions::ACTION_PAUSE, $id, __( 'Pausar', 'anpa-socios' ) );
		}
		if ( ANPA_Socios_Email_Campaign_State::PAUSED === $state ) {
			self::render_action_button( ANPA_Socios_Email_Admin_Actions::ACTION_RESUME, $id, __( 'Continuar', 'anpa-socios' ) );
		}
		if ( (int) $campaign['failed_count'] > 0 || (int) $campaign['pending_count'] > 0 ) {
			self::render_action_button( ANPA_Socios_Email_Admin_Actions::ACTION_RETRY, $id, __( 'Reintentar fallos', 'anpa-socios' ) );
		}
		self::render_action_button( ANPA_Socios_Email_Admin_Actions::ACTION_CANCEL, $id, __( 'Cancelar', 'anpa-socios' ), 'button button-link-delete' );
	}

	/**
	 * Renders one POST form with the action, the nonce and the campaign id.
	 *
	 * @since  1.39.0
	 * @param  string $action      Action name.
	 * @param  int    $campaign_id Campaign id (0 for the global run).
	 * @param  string $label       Button label.
	 * @param  string $class       Button classes.
	 * @return void
	 */
	private static function render_action_button( string $action, int $campaign_id, string $label, string $class = 'button' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 4px 4px 0">';
		ANPA_Socios_Email_Admin_Actions::form_fields( $action, $campaign_id );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Renders pagination links.
	 *
	 * @since  1.39.0
	 * @param  int $total Total campaigns.
	 * @param  int $paged Current page.
	 * @return void
	 */
	private static function render_pagination( int $total, int $paged ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}

		echo '<p class="anpa-comms-pagination">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'paged' => $i ), admin_url( 'admin.php' ) );
			if ( $i === $paged ) {
				echo '<strong>' . esc_html( (string) $i ) . '</strong> ';
			} else {
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
			}
		}
		echo '</p>';
	}

	/**
	 * A state cell always carries TEXT (never colour alone) plus a machine
	 * readable hook for styling.
	 *
	 * @since  1.39.0
	 * @param  string $label Human label.
	 * @param  string $state Raw state value.
	 * @return string Escaped markup.
	 */
	private static function state_cell( string $label, string $state ): string {
		return '<span class="anpa-comms-state anpa-comms-state--' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Galician label for a campaign state.
	 *
	 * @since  1.39.0
	 * @param  string $state Raw state.
	 * @return string
	 */
	public static function campaign_state_label( string $state ): string {
		$labels = array(
			ANPA_Socios_Email_Campaign_State::PENDING   => __( 'Pendente', 'anpa-socios' ),
			ANPA_Socios_Email_Campaign_State::RUNNING   => __( 'En curso', 'anpa-socios' ),
			ANPA_Socios_Email_Campaign_State::PAUSED    => __( 'Pausada', 'anpa-socios' ),
			ANPA_Socios_Email_Campaign_State::FINISHED  => __( 'Rematada', 'anpa-socios' ),
			ANPA_Socios_Email_Campaign_State::CANCELLED => __( 'Cancelada', 'anpa-socios' ),
		);

		return $labels[ $state ] ?? $state;
	}

	/**
	 * Galician label for a recipient state. "Aceptado" is never "entregado".
	 *
	 * @since  1.39.0
	 * @param  string $state Raw state.
	 * @return string
	 */
	public static function recipient_state_label( string $state ): string {
		$labels = array(
			ANPA_Socios_Email_Recipient_State::PENDING          => __( 'Pendente', 'anpa-socios' ),
			ANPA_Socios_Email_Recipient_State::PROCESSING       => __( 'A procesar', 'anpa-socios' ),
			ANPA_Socios_Email_Recipient_State::ACCEPTED         => __( 'Aceptado', 'anpa-socios' ),
			ANPA_Socios_Email_Recipient_State::FAILED           => __( 'Fallo (reintentarase)', 'anpa-socios' ),
			ANPA_Socios_Email_Recipient_State::FAILED_PERMANENT => __( 'Fallo definitivo', 'anpa-socios' ),
			ANPA_Socios_Email_Recipient_State::CANCELLED        => __( 'Cancelado', 'anpa-socios' ),
		);

		return $labels[ $state ] ?? $state;
	}
}
