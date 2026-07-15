<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings page under Gravity Forms: form selector, blocklist editor,
 * preview/cleanup buttons and the activity log.
 */
class GFSC_Admin {

	const PAGE_SLUG          = 'gf_smart_cleaner';
	const NONCE_SETTINGS     = 'gfsc_save_settings';
	const NONCE_BLOCKLIST    = 'gfsc_save_blocklist';
	const NONCE_CLEAR_LOG    = 'gfsc_clear_log';

	/** @var GFSC_Spam_Engine */
	private $engine;

	public function __construct( GFSC_Spam_Engine $engine ) {
		$this->engine = $engine;
	}

	public function register_hooks() {
		add_filter( 'gform_addon_navigation', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu( $menu ) {
		$menu[] = array(
			'name'       => self::PAGE_SLUG,
			'label'      => __( 'Smart Spam Cleaner', 'gf-smart-cleaner' ),
			'callback'   => array( $this, 'render_page' ),
			'permission' => GFSC_Plugin::CAPABILITY,
		);
		return $menu;
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_script(
			'gfsc-admin',
			GFSC_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			GFSC_VERSION,
			true
		);

		wp_localize_script( 'gfsc-admin', 'gfscData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( GFSC_Plugin::AJAX_NONCE ),
			'formId'  => (int) get_option( GFSC_Plugin::OPTION_FORM_ID, 0 ),
			'i18n'    => array(
				'cleaning'        => __( 'Cleaning in progress…', 'gf-smart-cleaner' ),
				'previewing'      => __( 'Scanning entries…', 'gf-smart-cleaner' ),
				/* translators: 1: pass number, 2: deleted count, 3: blocked count */
				'passResult'      => __( 'Pass #%1$s: deleted %2$s entries, blocked %3$s new emails.', 'gf-smart-cleaner' ),
				/* translators: %s: total deleted count */
				'cleanupDone'     => __( 'Cleanup complete. Total deleted: %s', 'gf-smart-cleaner' ),
				/* translators: 1: candidate count, 2: scanned count */
				'previewDone'     => __( 'Found %1$s spam candidates out of %2$s scanned entries.', 'gf-smart-cleaner' ),
				'noCandidates'    => __( 'No spam candidates found.', 'gf-smart-cleaner' ),
				'error'           => __( 'Error:', 'gf-smart-cleaner' ),
				'colEntry'        => __( 'Entry ID', 'gf-smart-cleaner' ),
				'colEmail'        => __( 'Email', 'gf-smart-cleaner' ),
				'colDate'         => __( 'Date', 'gf-smart-cleaner' ),
				'colReason'       => __( 'Reason', 'gf-smart-cleaner' ),
			),
		) );
	}

	public function render_page() {
		if ( ! current_user_can( GFSC_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gf-smart-cleaner' ) );
		}

		$notices = $this->handle_post();

		$selected_form_id = (int) get_option( GFSC_Plugin::OPTION_FORM_ID, 0 );
		$email_field_id   = get_option( GFSC_Plugin::OPTION_EMAIL_FIELD_ID, '' );
		$blocked_emails   = GFSC_Plugin::get_blocked_emails();
		$forms            = GFAPI::get_forms();

		echo '<div class="wrap"><h1>' . esc_html__( 'Gravity Forms Smart Spam Cleaner', 'gf-smart-cleaner' ) . '</h1>';

		foreach ( $notices as $notice ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
		}

		$this->render_settings_form( $forms, $selected_form_id, $email_field_id );
		$this->render_cleanup_section( $selected_form_id );
		$this->render_blocklist_form( $blocked_emails );
		$this->render_activity_log();

		echo '</div>';
	}

	/**
	 * Process the page's POST actions. Each form carries its own nonce.
	 *
	 * @return string[] Success notices to display.
	 */
	private function handle_post() {
		$notices = array();

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( GFSC_Plugin::CAPABILITY ) ) {
			return $notices;
		}

		if ( isset( $_POST['gfsc_settings_submit'] ) && check_admin_referer( self::NONCE_SETTINGS, 'gfsc_settings_nonce' ) ) {
			update_option( GFSC_Plugin::OPTION_FORM_ID, absint( $_POST['gfsc_form_id'] ?? 0 ) );

			$email_field = isset( $_POST['gfsc_email_field_id'] ) && is_string( $_POST['gfsc_email_field_id'] )
				? trim( wp_unslash( $_POST['gfsc_email_field_id'] ) )
				: '';
			$email_field = ( '' === $email_field ) ? '' : (string) absint( $email_field );
			update_option( GFSC_Plugin::OPTION_EMAIL_FIELD_ID, $email_field );

			$notices[] = __( 'Settings saved.', 'gf-smart-cleaner' );
		}

		if ( isset( $_POST['gfsc_blocklist_submit'] ) && check_admin_referer( self::NONCE_BLOCKLIST, 'gfsc_blocklist_nonce' ) ) {
			$raw   = isset( $_POST['gfsc_blocked_manual'] ) && is_string( $_POST['gfsc_blocked_manual'] )
				? wp_unslash( $_POST['gfsc_blocked_manual'] )
				: '';
			$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
			$saved = GFSC_Plugin::set_blocked_emails( $lines );

			/* translators: %d: number of emails on the blocklist */
			$notices[] = sprintf( __( 'Blocked email list updated (%d valid addresses saved).', 'gf-smart-cleaner' ), count( $saved ) );
		}

		if ( isset( $_POST['gfsc_clear_log_submit'] ) && check_admin_referer( self::NONCE_CLEAR_LOG, 'gfsc_clear_log_nonce' ) ) {
			GFSC_Logger::clear();
			$notices[] = __( 'Activity log cleared.', 'gf-smart-cleaner' );
		}

		return $notices;
	}

	private function render_settings_form( $forms, $selected_form_id, $email_field_id ) {
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_SETTINGS, 'gfsc_settings_nonce' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="gfsc_form_id">' . esc_html__( 'Select Form', 'gf-smart-cleaner' ) . '</label></th><td>';
		echo '<select name="gfsc_form_id" id="gfsc_form_id">';
		foreach ( $forms as $form ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $form['id'] ),
				selected( (int) $form['id'], $selected_form_id, false ),
				esc_html( $form['title'] )
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="gfsc_email_field_id">' . esc_html__( 'Email Field ID (optional)', 'gf-smart-cleaner' ) . '</label></th><td>';
		printf(
			'<input type="number" min="1" name="gfsc_email_field_id" id="gfsc_email_field_id" value="%s" class="small-text">',
			esc_attr( $email_field_id )
		);
		echo '<p class="description">' . esc_html__( 'Leave empty to auto-detect the first email field on the form.', 'gf-smart-cleaner' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Save Settings', 'gf-smart-cleaner' ), 'primary', 'gfsc_settings_submit' );
		echo '</form>';
	}

	private function render_cleanup_section( $selected_form_id ) {
		if ( ! $selected_form_id ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Cleanup', 'gf-smart-cleaner' ) . '</h2>';
		echo '<p>';
		echo '<button type="button" id="gfsc-preview" class="button">' . esc_html__( 'Run Preview', 'gf-smart-cleaner' ) . '</button> ';
		echo '<button type="button" id="gfsc-run" class="button button-primary">' . esc_html__( 'Run Full Cleanup', 'gf-smart-cleaner' ) . '</button>';
		echo '</p>';
		echo '<div id="gfsc-progress" aria-live="polite"></div>';
		echo '<div id="gfsc-preview-results"></div>';
	}

	private function render_blocklist_form( $blocked_emails ) {
		echo '<form method="post" style="margin-top:30px;">';
		wp_nonce_field( self::NONCE_BLOCKLIST, 'gfsc_blocklist_nonce' );
		echo '<h2>' . esc_html__( 'Blocked Emails', 'gf-smart-cleaner' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'One email per line. Invalid addresses are discarded on save.', 'gf-smart-cleaner' ) . '</p>';
		echo '<textarea name="gfsc_blocked_manual" rows="6" cols="60">' . esc_textarea( implode( "\n", $blocked_emails ) ) . '</textarea><br>';
		submit_button( __( 'Update Blocked List', 'gf-smart-cleaner' ), 'secondary', 'gfsc_blocklist_submit' );
		echo '</form>';
	}

	private function render_activity_log() {
		$events = GFSC_Logger::get_events();

		echo '<h2 style="margin-top:30px;">' . esc_html__( 'Recent Activity', 'gf-smart-cleaner' ) . '</h2>';

		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'No cleanup activity recorded yet.', 'gf-smart-cleaner' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:900px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'Form', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'Deleted', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'Newly Blocked', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'gf-smart-cleaner' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			$user      = get_userdata( (int) rgar( $event, 'user_id' ) );
			$user_name = $user ? $user->user_login : __( 'Unknown', 'gf-smart-cleaner' );
			$details   = array();
			foreach ( (array) rgar( $event, 'entries' ) as $row ) {
				$details[] = sprintf( '#%s %s — %s', rgar( $row, 'id' ), rgar( $row, 'email' ), rgar( $row, 'reason' ) );
			}

			echo '<tr>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) rgar( $event, 'time' ) ) ) . '</td>';
			echo '<td>' . esc_html( $user_name ) . '</td>';
			echo '<td>' . esc_html( rgar( $event, 'form_id' ) ) . '</td>';
			echo '<td>' . esc_html( rgar( $event, 'deleted' ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', (array) rgar( $event, 'blocked_emails' ) ) ) . '</td>';
			echo '<td>' . esc_html( implode( ' | ', $details ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" style="margin-top:10px;">';
		wp_nonce_field( self::NONCE_CLEAR_LOG, 'gfsc_clear_log_nonce' );
		submit_button( __( 'Clear Log', 'gf-smart-cleaner' ), 'delete', 'gfsc_clear_log_submit', false );
		echo '</form>';
	}
}
