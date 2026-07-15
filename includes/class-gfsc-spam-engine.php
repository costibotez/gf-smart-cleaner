<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Spam detection heuristics plus cleanup/preview batch logic. No output here.
 */
class GFSC_Spam_Engine {

	/**
	 * Field types whose values are free text and safe to run gibberish
	 * heuristics on. Emails, URLs, numbers etc. routinely trip them.
	 */
	private $text_field_types = array( 'text', 'textarea', 'name', 'post_title', 'post_content', 'post_excerpt' );

	private $blocked_domains = array( 'email-temp.com', 'tempmail', 'sharklasers.com', '10minutemail' );

	/**
	 * Scan one batch of entries and delete the spam ones.
	 *
	 * Entries are fetched at $offset (default sort: newest first). Deleting
	 * shifts later entries down, so the caller should advance the offset by
	 * (scanned - deleted) between passes — the returned next_offset.
	 *
	 * @return array|WP_Error {scanned, deleted, blocked, total, next_offset}
	 */
	public function run_cleanup( $form_id, $offset = 0, $batch_limit = 100 ) {
		$form = GFAPI::get_form( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'gfsc_no_form', __( 'Form not found.', 'gf-smart-cleaner' ) );
		}

		$email_field_id = $this->resolve_email_field_id( $form );
		$blocked_emails = GFSC_Plugin::get_blocked_emails();

		$total_count = 0;
		$entries     = GFAPI::get_entries( $form_id, array(), null, array( 'offset' => $offset, 'page_size' => $batch_limit ), $total_count );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$deleted       = 0;
		$newly_blocked = array();
		$details       = array();

		foreach ( $entries as $entry ) {
			$check = $this->get_spam_reason( $form, $entry, $blocked_emails, $email_field_id );
			if ( null === $check['reason'] ) {
				continue;
			}

			$result = GFAPI::delete_entry( $entry['id'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$deleted++;
			if ( is_email( $check['email'] ) ) {
				$newly_blocked[] = $check['email'];
			}
			$details[] = array(
				'id'     => $entry['id'],
				'email'  => $check['email'],
				'reason' => $check['reason'],
			);
		}

		$newly_blocked = array_values( array_unique( $newly_blocked ) );
		if ( ! empty( $newly_blocked ) ) {
			GFSC_Plugin::add_blocked_emails( $newly_blocked );
		}

		if ( $deleted > 0 ) {
			GFSC_Logger::log( $form_id, $deleted, $newly_blocked, $details );
		}

		return array(
			'scanned'     => count( $entries ),
			'deleted'     => $deleted,
			'blocked'     => count( $newly_blocked ),
			'total'       => (int) $total_count,
			'next_offset' => $offset + ( count( $entries ) - $deleted ),
		);
	}

	/**
	 * Scan all entries (up to $max_entries) without deleting anything.
	 *
	 * @return array|WP_Error {scanned, candidates: [{id, email, date_created, reason}]}
	 */
	public function run_preview( $form_id, $batch_limit = 100, $max_entries = 5000 ) {
		$form = GFAPI::get_form( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'gfsc_no_form', __( 'Form not found.', 'gf-smart-cleaner' ) );
		}

		$email_field_id = $this->resolve_email_field_id( $form );
		$blocked_emails = GFSC_Plugin::get_blocked_emails();

		$offset     = 0;
		$scanned    = 0;
		$candidates = array();

		while ( $offset < $max_entries ) {
			$entries = GFAPI::get_entries( $form_id, array(), null, array( 'offset' => $offset, 'page_size' => $batch_limit ) );
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}
			if ( empty( $entries ) ) {
				break;
			}

			foreach ( $entries as $entry ) {
				$scanned++;
				$check = $this->get_spam_reason( $form, $entry, $blocked_emails, $email_field_id );
				if ( null !== $check['reason'] ) {
					$candidates[] = array(
						'id'           => $entry['id'],
						'email'        => $check['email'],
						'date_created' => $entry['date_created'],
						'reason'       => $check['reason'],
					);
				}
			}

			if ( count( $entries ) < $batch_limit ) {
				break;
			}
			$offset += count( $entries );
		}

		return array(
			'scanned'    => $scanned,
			'candidates' => $candidates,
		);
	}

	/**
	 * Decide whether an entry is spam.
	 *
	 * @return array {email: string, reason: string|null}
	 */
	public function get_spam_reason( $form, $entry, array $blocked_emails, $email_field_id ) {
		$email  = strtolower( trim( (string) rgar( $entry, (string) $email_field_id ) ) );
		$result = array( 'email' => $email, 'reason' => null );

		if ( '' !== $email ) {
			foreach ( $this->blocked_domains as $domain ) {
				if ( false !== strpos( $email, $domain ) ) {
					$result['reason'] = sprintf( __( 'Disposable email domain (%s)', 'gf-smart-cleaner' ), $domain );
					return $result;
				}
			}
			if ( preg_match( '/\d{2,}\./', $email ) ) {
				$result['reason'] = __( 'Suspicious email pattern (dot-trick abuse)', 'gf-smart-cleaner' );
				return $result;
			}
			if ( in_array( $email, $blocked_emails, true ) ) {
				$result['reason'] = __( 'Email on blocklist', 'gf-smart-cleaner' );
				return $result;
			}
		}

		foreach ( $form['fields'] as $field ) {
			if ( ! in_array( $field->type, $this->text_field_types, true ) ) {
				continue;
			}
			foreach ( $this->get_field_values( $field, $entry ) as $value ) {
				$reason = $this->get_gibberish_reason( $value );
				if ( null !== $reason ) {
					$result['reason'] = $reason;
					return $result;
				}
			}
		}

		return $result;
	}

	/**
	 * @return string|null Human-readable reason, or null when the value looks legitimate.
	 */
	private function get_gibberish_reason( $value ) {
		$value = trim( (string) $value );
		if ( strlen( $value ) < 3 ) {
			return null;
		}

		if ( ! preg_match( '/[aeiou]/iu', $value ) ) {
			return __( 'No vowels (gibberish)', 'gf-smart-cleaner' );
		}
		if ( preg_match( '/[bcdfghjklmnpqrstvwxyz]{5,}/iu', $value ) ) {
			return __( 'Long consonant run (gibberish)', 'gf-smart-cleaner' );
		}
		// Two case transitions required, so "McDonald" or "JavaScript" don't match.
		if ( preg_match( '/[a-z]+[A-Z]+[a-z]+[A-Z]+/', $value ) ) {
			return __( 'Random mixed-case text (gibberish)', 'gf-smart-cleaner' );
		}
		if ( preg_match( '/[\p{Cyrillic}]/u', $value ) && preg_match( '/телеграм|telegram/iu', $value ) ) {
			return __( 'Cyrillic Telegram spam', 'gf-smart-cleaner' );
		}

		return null;
	}

	/**
	 * Collect the entry values for a field, including multi-input fields
	 * such as Name whose values live under sub-keys like "1.3".
	 *
	 * @return string[]
	 */
	private function get_field_values( $field, $entry ) {
		$values = array();
		$inputs = is_callable( array( $field, 'get_entry_inputs' ) ) ? $field->get_entry_inputs() : null;

		if ( is_array( $inputs ) ) {
			foreach ( $inputs as $input ) {
				$values[] = rgar( $entry, (string) $input['id'] );
			}
		} else {
			$values[] = rgar( $entry, (string) $field->id );
		}

		return array_filter( $values, 'is_string' );
	}

	/**
	 * The email field to use for blocklist checks: the saved override if set,
	 * otherwise the form's first email-type field.
	 *
	 * @return string Field ID, or '' when the form has no email field.
	 */
	public function resolve_email_field_id( $form ) {
		$override = get_option( GFSC_Plugin::OPTION_EMAIL_FIELD_ID, '' );
		if ( '' !== $override && null !== $override ) {
			return (string) $override;
		}
		foreach ( $form['fields'] as $field ) {
			if ( 'email' === $field->type ) {
				return (string) $field->id;
			}
		}
		return '';
	}
}
