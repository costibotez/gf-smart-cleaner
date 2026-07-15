<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Recurring automated cleanup via WP-Cron, plus the optional email summary.
 */
class GFSC_Cron {

	const HOOK               = 'gfsc_scheduled_cleanup';
	const MAX_PASSES_PER_FORM = 20;

	const FREQUENCIES = array( 'hourly', 'twicedaily', 'daily', 'weekly' );

	/** @var GFSC_Spam_Engine */
	private $engine;

	public function __construct( GFSC_Spam_Engine $engine ) {
		$this->engine = $engine;
	}

	public function register_hooks() {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * (Re)apply the schedule from settings. Called after settings are saved.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );

		$settings = GFSC_Plugin::get_settings();
		if ( empty( $settings['cron_enabled'] ) ) {
			return;
		}

		$frequency = in_array( $settings['cron_frequency'], self::FREQUENCIES, true ) ? $settings['cron_frequency'] : 'daily';
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, self::HOOK );
	}

	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Scheduled cleanup: process every selected form to completion
	 * (bounded by MAX_PASSES_PER_FORM), then email a summary if enabled.
	 */
	public function run() {
		$settings = GFSC_Plugin::get_settings();
		$report   = array();
		$total    = 0;

		foreach ( GFSC_Plugin::get_form_ids() as $form_id ) {
			$offset  = 0;
			$deleted = 0;
			$reasons = array();
			$error   = '';

			for ( $pass = 0; $pass < self::MAX_PASSES_PER_FORM; $pass++ ) {
				$result = $this->engine->run_cleanup( $form_id, $offset, 100, 'cron' );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					break;
				}

				$deleted += $result['deleted'];
				$offset   = $result['next_offset'];
				foreach ( $result['details'] as $row ) {
					$reason             = (string) rgar( $row, 'reason' );
					$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
				}

				if ( 0 === $result['scanned'] || $offset >= $result['total'] ) {
					break;
				}
			}

			$total   += $deleted;
			$report[] = array(
				'form_id' => $form_id,
				'deleted' => $deleted,
				'reasons' => $reasons,
				'error'   => $error,
			);
		}

		if ( $total > 0 && ! empty( $settings['email_summary'] ) ) {
			$this->send_summary( $report, $total, $settings );
		}
	}

	/**
	 * Plain-text summary email to the configured recipient.
	 */
	private function send_summary( array $report, $total, array $settings ) {
		$recipient = is_email( $settings['email_recipient'] ) ? $settings['email_recipient'] : get_option( 'admin_email' );
		if ( ! $recipient ) {
			return;
		}

		$mode  = GFSC_Plugin::get_settings()['mode'];
		$lines = array();

		/* translators: %s: site name */
		$lines[] = sprintf( __( 'Scheduled spam cleanup finished on %s.', 'gf-smart-cleaner' ), get_bloginfo( 'name' ) );
		$lines[] = '';
		/* translators: %d: total removed entries */
		$lines[] = sprintf( __( 'Total entries removed: %d', 'gf-smart-cleaner' ), $total );
		$lines[] = '';

		foreach ( $report as $row ) {
			$form       = GFAPI::get_form( $row['form_id'] );
			$form_title = $form ? $form['title'] : ( '#' . $row['form_id'] );
			/* translators: 1: form title, 2: deleted count */
			$lines[] = sprintf( __( 'Form "%1$s": %2$d removed', 'gf-smart-cleaner' ), $form_title, $row['deleted'] );
			foreach ( $row['reasons'] as $reason => $count ) {
				$lines[] = '  - ' . $reason . ': ' . $count;
			}
			if ( '' !== $row['error'] ) {
				/* translators: %s: error message */
				$lines[] = '  ' . sprintf( __( 'Stopped early with error: %s', 'gf-smart-cleaner' ), $row['error'] );
			}
		}

		$lines[] = '';
		if ( 'trash' === $mode ) {
			$lines[] = __( 'Removed entries were moved to the Gravity Forms Trash and can be restored for around 30 days before Gravity Forms purges them.', 'gf-smart-cleaner' );
		} else {
			$lines[] = __( 'Removed entries were deleted permanently.', 'gf-smart-cleaner' );
		}
		$lines[] = admin_url( 'admin.php?page=gf_smart_cleaner' );

		wp_mail(
			$recipient,
			/* translators: %d: total removed entries */
			sprintf( __( '[Smart Spam Cleaner] %d spam entries removed', 'gf-smart-cleaner' ), $total ),
			implode( "\n", $lines )
		);
	}
}
