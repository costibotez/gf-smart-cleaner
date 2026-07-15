<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Submission-time blocking: flags spam entries via Gravity Forms'
 * gform_entry_is_spam filter so they land in the Spam status instead of
 * being stored as active entries.
 */
class GFSC_Submission {

	/** @var GFSC_Spam_Engine */
	private $engine;

	public function __construct( GFSC_Spam_Engine $engine ) {
		$this->engine = $engine;
	}

	public function register_hooks() {
		add_filter( 'gform_entry_is_spam', array( $this, 'filter_is_spam' ), 10, 3 );
	}

	/**
	 * @param bool  $is_spam Whether another check already flagged the entry.
	 * @param array $form
	 * @param array $entry   The not-yet-saved entry.
	 * @return bool
	 */
	public function filter_is_spam( $is_spam, $form, $entry ) {
		if ( $is_spam ) {
			return $is_spam;
		}

		$settings = GFSC_Plugin::get_settings();
		if ( empty( $settings['block_at_submission'] ) ) {
			return $is_spam;
		}

		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;
		if ( ! in_array( $form_id, GFSC_Plugin::get_form_ids(), true ) ) {
			return $is_spam;
		}

		$check = $this->engine->entry_spam_check( $form, $entry );
		if ( null === $check['reason'] ) {
			return $is_spam;
		}

		// Entry ID may not exist yet at this point in the save; log what we have.
		GFSC_Logger::log(
			$form_id,
			0,
			array(),
			array(
				array(
					'id'     => rgar( $entry, 'id' ),
					'email'  => $check['email'],
					'reason' => $check['reason'],
				),
			),
			'submission',
			'spam'
		);

		return true;
	}
}
