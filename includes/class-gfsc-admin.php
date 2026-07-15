<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings page under Gravity Forms: form selection, cleanup mode,
 * automation, list editors, preview/cleanup buttons and the activity log.
 */
class GFSC_Admin {

	const PAGE_SLUG       = 'gf_smart_cleaner';
	const NONCE_SETTINGS  = 'gfsc_save_settings';
	const NONCE_LISTS     = 'gfsc_save_lists';
	const NONCE_CLEAR_LOG = 'gfsc_clear_log';

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

		$selected = array();
		foreach ( GFAPI::get_forms() as $form ) {
			if ( in_array( (int) $form['id'], GFSC_Plugin::get_form_ids(), true ) ) {
				$selected[] = array( 'id' => (int) $form['id'], 'title' => $form['title'] );
			}
		}

		wp_localize_script( 'gfsc-admin', 'gfscData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( GFSC_Plugin::AJAX_NONCE ),
			'forms'   => $selected,
			'i18n'    => array(
				'cleaning'     => __( 'Cleaning in progress…', 'gf-smart-cleaner' ),
				'previewing'   => __( 'Scanning entries…', 'gf-smart-cleaner' ),
				/* translators: 1: form title, 2: pass number, 3: deleted count, 4: blocked count */
				'passResult'   => __( '%1$s — pass #%2$s: removed %3$s entries, blocked %4$s new emails.', 'gf-smart-cleaner' ),
				/* translators: %s: total removed count */
				'cleanupDone'  => __( 'Cleanup complete. Total removed: %s', 'gf-smart-cleaner' ),
				/* translators: 1: candidate count, 2: scanned count */
				'previewDone'  => __( 'Found %1$s spam candidates out of %2$s scanned entries.', 'gf-smart-cleaner' ),
				'noCandidates' => __( 'No spam candidates found.', 'gf-smart-cleaner' ),
				'error'        => __( 'Error:', 'gf-smart-cleaner' ),
				'colForm'      => __( 'Form', 'gf-smart-cleaner' ),
				'colEntry'     => __( 'Entry ID', 'gf-smart-cleaner' ),
				'colEmail'     => __( 'Email', 'gf-smart-cleaner' ),
				'colDate'      => __( 'Date', 'gf-smart-cleaner' ),
				'colReason'    => __( 'Reason', 'gf-smart-cleaner' ),
				'restored'     => __( 'Restored', 'gf-smart-cleaner' ),
				'restoring'    => __( 'Restoring…', 'gf-smart-cleaner' ),
			),
		) );
	}

	public function render_page() {
		if ( ! current_user_can( GFSC_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gf-smart-cleaner' ) );
		}

		$notices = $this->handle_post();

		echo '<div class="wrap"><h1>' . esc_html__( 'Gravity Forms Smart Spam Cleaner', 'gf-smart-cleaner' ) . '</h1>';

		foreach ( $notices as $notice ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
		}

		$this->render_settings_form();
		$this->render_cleanup_section();
		$this->render_lists_form();
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
			$form_ids = GFSC_Plugin::set_form_ids( (array) ( $_POST['gfsc_form_ids'] ?? array() ) );

			$map = array();
			foreach ( (array) ( $_POST['gfsc_email_field_map'] ?? array() ) as $form_id => $field_id ) {
				$form_id  = absint( $form_id );
				$field_id = absint( $field_id );
				if ( $form_id && $field_id && in_array( $form_id, $form_ids, true ) ) {
					$map[ $form_id ] = (string) $field_id;
				}
			}
			GFSC_Plugin::set_email_field_map( $map );

			$frequency = isset( $_POST['gfsc_cron_frequency'] ) && is_string( $_POST['gfsc_cron_frequency'] ) ? $_POST['gfsc_cron_frequency'] : '';
			$recipient = isset( $_POST['gfsc_email_recipient'] ) && is_string( $_POST['gfsc_email_recipient'] )
				? sanitize_email( wp_unslash( $_POST['gfsc_email_recipient'] ) )
				: '';

			GFSC_Plugin::update_settings( array(
				'mode'                => ( isset( $_POST['gfsc_mode'] ) && 'delete' === $_POST['gfsc_mode'] ) ? 'delete' : 'trash',
				'cron_enabled'        => ! empty( $_POST['gfsc_cron_enabled'] ),
				'cron_frequency'      => in_array( $frequency, GFSC_Cron::FREQUENCIES, true ) ? $frequency : 'daily',
				'email_summary'       => ! empty( $_POST['gfsc_email_summary'] ),
				'email_recipient'     => $recipient ?: get_option( 'admin_email' ),
				'block_at_submission' => ! empty( $_POST['gfsc_block_at_submission'] ),
			) );

			GFSC_Cron::reschedule();
			$notices[] = __( 'Settings saved.', 'gf-smart-cleaner' );
		}

		if ( isset( $_POST['gfsc_lists_submit'] ) && check_admin_referer( self::NONCE_LISTS, 'gfsc_lists_nonce' ) ) {
			$emails    = GFSC_Plugin::set_blocked_emails( $this->textarea_lines( 'gfsc_blocked_manual' ) );
			$domains   = GFSC_Plugin::set_blocked_domains( $this->textarea_lines( 'gfsc_blocked_domains' ) );
			$whitelist = GFSC_Plugin::set_whitelist( $this->textarea_lines( 'gfsc_whitelist' ) );

			/* translators: 1: blocked emails count, 2: blocked domains count, 3: whitelist count */
			$notices[] = sprintf(
				__( 'Lists updated: %1$d blocked emails, %2$d blocked domains, %3$d whitelisted.', 'gf-smart-cleaner' ),
				count( $emails ),
				count( $domains ),
				count( $whitelist )
			);
		}

		if ( isset( $_POST['gfsc_clear_log_submit'] ) && check_admin_referer( self::NONCE_CLEAR_LOG, 'gfsc_clear_log_nonce' ) ) {
			GFSC_Logger::clear();
			$notices[] = __( 'Activity log cleared.', 'gf-smart-cleaner' );
		}

		return $notices;
	}

	/**
	 * @return string[] Trimmed non-empty lines of a POSTed textarea.
	 */
	private function textarea_lines( $key ) {
		$raw = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		return array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
	}

	private function render_settings_form() {
		$form_ids  = GFSC_Plugin::get_form_ids();
		$field_map = GFSC_Plugin::get_email_field_map();
		$settings  = GFSC_Plugin::get_settings();
		$forms     = GFAPI::get_forms();

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_SETTINGS, 'gfsc_settings_nonce' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Forms to Clean', 'gf-smart-cleaner' ) . '</th><td>';
		echo '<fieldset>';
		foreach ( $forms as $form ) {
			$id = (int) $form['id'];
			printf(
				'<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="gfsc_form_ids[]" value="%1$d" %2$s> %3$s <input type="number" min="1" name="gfsc_email_field_map[%1$d]" value="%4$s" class="small-text" placeholder="%5$s" title="%6$s"></label>',
				$id,
				checked( in_array( $id, $form_ids, true ), true, false ),
				esc_html( $form['title'] ),
				esc_attr( $field_map[ $id ] ?? '' ),
				esc_attr__( 'auto', 'gf-smart-cleaner' ),
				esc_attr__( 'Email field ID (leave empty to auto-detect)', 'gf-smart-cleaner' )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'The number box next to each form optionally overrides the email field ID; leave empty to auto-detect the first email field.', 'gf-smart-cleaner' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Removal Mode', 'gf-smart-cleaner' ) . '</th><td><fieldset>';
		printf(
			'<label style="display:block;"><input type="radio" name="gfsc_mode" value="trash" %s> %s</label>',
			checked( 'trash', $settings['mode'], false ),
			esc_html__( 'Move to Trash (recoverable for ~30 days before Gravity Forms purges it)', 'gf-smart-cleaner' )
		);
		printf(
			'<label style="display:block;"><input type="radio" name="gfsc_mode" value="delete" %s> %s</label>',
			checked( 'delete', $settings['mode'], false ),
			esc_html__( 'Delete permanently (cannot be undone)', 'gf-smart-cleaner' )
		);
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Block at Submission', 'gf-smart-cleaner' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="gfsc_block_at_submission" value="1" %s> %s</label>',
			checked( true, (bool) $settings['block_at_submission'], false ),
			esc_html__( 'Mark spam submissions on the selected forms as spam the moment they arrive', 'gf-smart-cleaner' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Automated Cleanup', 'gf-smart-cleaner' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="gfsc_cron_enabled" value="1" %s> %s</label> ',
			checked( true, (bool) $settings['cron_enabled'], false ),
			esc_html__( 'Run cleanup automatically', 'gf-smart-cleaner' )
		);
		echo '<select name="gfsc_cron_frequency">';
		$frequencies = array(
			'hourly'     => __( 'Hourly', 'gf-smart-cleaner' ),
			'twicedaily' => __( 'Twice Daily', 'gf-smart-cleaner' ),
			'daily'      => __( 'Daily', 'gf-smart-cleaner' ),
			'weekly'     => __( 'Weekly', 'gf-smart-cleaner' ),
		);
		foreach ( $frequencies as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $value, $settings['cron_frequency'], false ), esc_html( $label ) );
		}
		echo '</select>';
		$next = wp_next_scheduled( GFSC_Cron::HOOK );
		if ( $next ) {
			echo '<p class="description">' . esc_html( sprintf(
				/* translators: %s: date/time of the next scheduled run */
				__( 'Next scheduled run: %s', 'gf-smart-cleaner' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
			) ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Email Summary', 'gf-smart-cleaner' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="gfsc_email_summary" value="1" %s> %s</label> ',
			checked( true, (bool) $settings['email_summary'], false ),
			esc_html__( 'Email a report after scheduled runs that remove entries', 'gf-smart-cleaner' )
		);
		printf(
			'<input type="email" name="gfsc_email_recipient" value="%s" class="regular-text" placeholder="%s">',
			esc_attr( $settings['email_recipient'] ),
			esc_attr( get_option( 'admin_email' ) )
		);
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Save Settings', 'gf-smart-cleaner' ), 'primary', 'gfsc_settings_submit' );
		echo '</form>';
	}

	private function render_cleanup_section() {
		if ( empty( GFSC_Plugin::get_form_ids() ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Manual Cleanup', 'gf-smart-cleaner' ) . '</h2>';
		echo '<p>';
		echo '<button type="button" id="gfsc-preview" class="button">' . esc_html__( 'Run Preview', 'gf-smart-cleaner' ) . '</button> ';
		echo '<button type="button" id="gfsc-run" class="button button-primary">' . esc_html__( 'Run Full Cleanup', 'gf-smart-cleaner' ) . '</button>';
		echo '</p>';
		echo '<div id="gfsc-progress" aria-live="polite"></div>';
		echo '<div id="gfsc-preview-results"></div>';
	}

	private function render_lists_form() {
		echo '<form method="post" style="margin-top:30px;">';
		wp_nonce_field( self::NONCE_LISTS, 'gfsc_lists_nonce' );
		echo '<h2>' . esc_html__( 'Lists', 'gf-smart-cleaner' ) . '</h2>';

		echo '<h3>' . esc_html__( 'Blocked Emails', 'gf-smart-cleaner' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'One email per line. Invalid addresses are discarded on save.', 'gf-smart-cleaner' ) . '</p>';
		echo '<textarea name="gfsc_blocked_manual" rows="6" cols="60">' . esc_textarea( implode( "\n", GFSC_Plugin::get_blocked_emails() ) ) . '</textarea>';

		echo '<h3>' . esc_html__( 'Blocked Domains', 'gf-smart-cleaner' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'One domain (or domain fragment) per line, e.g. sharklasers.com or tempmail. Matched as a substring of the email address.', 'gf-smart-cleaner' ) . '</p>';
		echo '<textarea name="gfsc_blocked_domains" rows="4" cols="60">' . esc_textarea( implode( "\n", GFSC_Plugin::get_blocked_domains() ) ) . '</textarea>';

		echo '<h3>' . esc_html__( 'Whitelist (never flag)', 'gf-smart-cleaner' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Emails or domains, one per line. Matching entries are never flagged, removed, or blocked at submission. Restoring an entry adds its email here automatically.', 'gf-smart-cleaner' ) . '</p>';
		echo '<textarea name="gfsc_whitelist" rows="4" cols="60">' . esc_textarea( implode( "\n", GFSC_Plugin::get_whitelist() ) ) . '</textarea><br>';

		submit_button( __( 'Update Lists', 'gf-smart-cleaner' ), 'secondary', 'gfsc_lists_submit' );
		echo '</form>';
	}

	private function render_activity_log() {
		$events = GFSC_Logger::get_events();

		echo '<h2 style="margin-top:30px;">' . esc_html__( 'Recent Activity', 'gf-smart-cleaner' ) . '</h2>';

		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'No activity recorded yet.', 'gf-smart-cleaner' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" id="gfsc-log">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'Trigger', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'Form', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'Removed', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'Newly Blocked', 'gf-smart-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'gf-smart-cleaner' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			echo '<tr>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) rgar( $event, 'time' ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->event_trigger_label( $event ) ) . '</td>';
			echo '<td>' . esc_html( rgar( $event, 'form_id' ) ) . '</td>';
			echo '<td>' . esc_html( rgar( $event, 'deleted' ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', (array) rgar( $event, 'blocked_emails' ) ) ) . '</td>';
			echo '<td>' . $this->event_details_html( $event ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" style="margin-top:10px;">';
		wp_nonce_field( self::NONCE_CLEAR_LOG, 'gfsc_clear_log_nonce' );
		submit_button( __( 'Clear Log', 'gf-smart-cleaner' ), 'delete', 'gfsc_clear_log_submit', false );
		echo '</form>';
	}

	private function event_trigger_label( $event ) {
		$source = rgar( $event, 'source' );
		if ( 'cron' === $source ) {
			return __( 'Scheduled', 'gf-smart-cleaner' );
		}
		if ( 'submission' === $source ) {
			return __( 'Blocked at submission', 'gf-smart-cleaner' );
		}
		$user = get_userdata( (int) rgar( $event, 'user_id' ) );
		return $user ? $user->user_login : __( 'Unknown', 'gf-smart-cleaner' );
	}

	/**
	 * Escaped HTML for the per-entry detail rows, with Restore buttons
	 * for recoverable (trash-mode) entries.
	 */
	private function event_details_html( $event ) {
		$rows = (array) rgar( $event, 'entries' );
		if ( empty( $rows ) ) {
			return '';
		}

		$restorable = ( 'trash' === rgar( $event, 'mode' ) );
		$html       = '<ul style="margin:0;">';

		foreach ( $rows as $row ) {
			$entry_id = (int) rgar( $row, 'id' );
			$html    .= '<li>' . esc_html( sprintf( '#%s %s — %s', $entry_id ?: '?', rgar( $row, 'email' ), rgar( $row, 'reason' ) ) );

			if ( ! empty( $row['restored'] ) ) {
				$html .= ' <em>(' . esc_html__( 'restored', 'gf-smart-cleaner' ) . ')</em>';
			} elseif ( $restorable && $entry_id ) {
				$html .= sprintf(
					' <button type="button" class="button-link gfsc-restore" data-entry-id="%d">%s</button>',
					$entry_id,
					esc_html__( 'Restore', 'gf-smart-cleaner' )
				);
			}

			$html .= '</li>';
		}

		return $html . '</ul>';
	}
}
