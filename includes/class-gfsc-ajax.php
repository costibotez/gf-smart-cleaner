<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX endpoints for cleanup and preview. Both are POST-only, nonce-checked
 * and restricted to users with the plugin capability.
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
	}

	public function handle_run() {
		$this->authorize();

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing form ID.', 'gf-smart-cleaner' ) ) );
		}

		$result = $this->engine->run_cleanup( $form_id, $offset );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	public function handle_preview() {
		$this->authorize();

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing form ID.', 'gf-smart-cleaner' ) ) );
		}

		$result = $this->engine->run_preview( $form_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	private function authorize() {
		check_ajax_referer( GFSC_Plugin::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( GFSC_Plugin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'gf-smart-cleaner' ) ), 403 );
		}
	}
}
