<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Activity log stored as a capped ring buffer in a non-autoloaded option.
 */
class GFSC_Logger {

	const OPTION     = 'gf_smart_cleaner_log';
	const MAX_EVENTS = 200;

	/**
	 * Record one cleanup pass that deleted entries.
	 *
	 * @param int      $form_id
	 * @param int      $deleted        Number of entries deleted.
	 * @param string[] $blocked_emails Emails newly added to the blocklist.
	 * @param array    $entries        [{id, email, reason}] for each deleted entry.
	 */
	public static function log( $form_id, $deleted, array $blocked_emails, array $entries ) {
		$events = self::get_events();
		array_unshift( $events, array(
			'time'           => time(),
			'user_id'        => get_current_user_id(),
			'form_id'        => (int) $form_id,
			'deleted'        => (int) $deleted,
			'blocked_emails' => $blocked_emails,
			'entries'        => $entries,
		) );
		update_option( self::OPTION, array_slice( $events, 0, self::MAX_EVENTS ), false );
	}

	/**
	 * @return array Events, newest first.
	 */
	public static function get_events() {
		$events = get_option( self::OPTION, array() );
		return is_array( $events ) ? $events : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
