<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX endpoints for cleanup, preview and restore. All are POST-only,
 * nonce-checked and restricted to users with the plugin capability.
 */
class GFSC_Ajax {

	/** @var GFSC_Spam_Engine */
	private $engine;

	public function __construct( GFSC_Spam_Engine $engine ) {
		$this->engine = $engine;
	}

	public function register_hooks() {
		add_action( 'wp_ajax_gfsc_run', array( $this, 'handle_run' ) );
		add_action( 'wp_ajax_gfsc_preview', array( $this, 'handle_preview' ) );
		add_action( 'wp_ajax_gfsc_restore', array( $this, 'handle_restore' ) );
	}

	public function handle_run() {
		$this->authorize();

		$form_id = $this->require_selected_form_id();
		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$result = $this->engine->run_cleanup( $form_id, $offset, 100, 'manual' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	public function handle_preview() {
		$this->authorize();

		$form_id = $this->require_selected_form_id();

		$result = $this->engine->run_preview( $form_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Restore a trashed/spam entry and treat it as a false positive:
	 * the email is removed from the blocklist and whitelisted.
	 */
	public function handle_restore() {
		$this->authorize();

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		if ( ! $entry_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing entry ID.', 'gf-smart-cleaner' ) ) );
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			wp_send_json_error( array( 'message' => __( 'Entry no longer exists (it may have been purged).', 'gf-smart-cleaner' ) ) );
		}

		if ( ! in_array( rgar( $entry, 'status' ), array( 'trash', 'spam' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Entry is not in the trash or spam.', 'gf-smart-cleaner' ) ) );
		}

		$result = GFAPI::update_entry_property( $entry_id, 'status', 'active' );
		if ( is_wp_error( $result ) || false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not restore the entry.', 'gf-smart-cleaner' ) ) );
		}

		$form  = GFAPI::get_form( (int) rgar( $entry, 'form_id' ) );
		$email = '';
		if ( $form ) {
			$email = strtolower( trim( (string) rgar( $entry, $this->engine->resolve_email_field_id( $form ) ) ) );
		}
		if ( is_email( $email ) ) {
			GFSC_Plugin::remove_blocked_email( $email );
			GFSC_Plugin::add_whitelist_entries( array( $email ) );
		}

		GFSC_Logger::mark_restored( $entry_id );

		wp_send_json_success( array(
			'entry_id' => $entry_id,
			'email'    => $email,
		) );
	}

	private function authorize() {
		check_ajax_referer( GFSC_Plugin::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( GFSC_Plugin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'gf-smart-cleaner' ) ), 403 );
		}
	}

	/**
	 * @return int A form ID that is part of the configured selection.
	 */
	private function require_selected_form_id() {
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		if ( ! $form_id || ! in_array( $form_id, GFSC_Plugin::get_form_ids(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or unselected form.', 'gf-smart-cleaner' ) ) );
		}
		return $form_id;
	}
}
