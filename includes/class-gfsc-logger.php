<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Activity log stored as a capped ring buffer in a non-autoloaded option.
 */
class GFSC_Logger {

	const OPTION     = 'gf_smart_cleaner_log';
	const MAX_EVENTS = 200;

	/**
	 * Record one event.
	 *
	 * @param int      $form_id
	 * @param int      $deleted        Number of entries trashed/deleted.
	 * @param string[] $blocked_emails Emails newly added to the blocklist.
	 * @param array    $entries        [{id, email, reason}] per affected entry.
	 * @param string   $source         'manual', 'cron' or 'submission'.
	 * @param string   $mode           'trash', 'delete' or 'spam' (submission-time flag).
	 */
	public static function log( $form_id, $deleted, array $blocked_emails, array $entries, $source = 'manual', $mode = 'trash' ) {
		$events = self::get_events();
		array_unshift( $events, array(
			'time'           => time(),
			'user_id'        => get_current_user_id(),
			'form_id'        => (int) $form_id,
			'deleted'        => (int) $deleted,
			'blocked_emails' => $blocked_emails,
			'entries'        => $entries,
			'source'         => $source,
			'mode'           => $mode,
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

	/**
	 * Flag an entry as restored across all log events that reference it.
	 */
	public static function mark_restored( $entry_id ) {
		$entry_id = (int) $entry_id;
		$events   = self::get_events();
		$changed  = false;

		foreach ( $events as &$event ) {
			if ( empty( $event['entries'] ) || ! is_array( $event['entries'] ) ) {
				continue;
			}
			foreach ( $event['entries'] as &$row ) {
				if ( (int) rgar( $row, 'id' ) === $entry_id && empty( $row['restored'] ) ) {
					$row['restored'] = true;
					$changed         = true;
				}
			}
			unset( $row );
		}
		unset( $event );

		if ( $changed ) {
			update_option( self::OPTION, $events, false );
		}
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
