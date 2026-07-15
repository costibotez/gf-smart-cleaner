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

	/**
	 * Scan one batch of active entries and trash/delete the spam ones.
	 *
	 * Entries are fetched at $offset (default sort: newest first). Removing
	 * an entry from the active set shifts later entries down, so the caller
	 * should advance the offset by (scanned - deleted) between passes — the
	 * returned next_offset.
	 *
	 * @param int    $form_id
	 * @param int    $offset
	 * @param int    $batch_limit
	 * @param string $source Logged as the trigger: 'manual' or 'cron'.
	 * @return array|WP_Error {scanned, deleted, blocked, total, next_offset, details}
	 */
	public function run_cleanup( $form_id, $offset = 0, $batch_limit = 100, $source = 'manual' ) {
		$form = GFAPI::get_form( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'gfsc_no_form', __( 'Form not found.', 'gf-smart-cleaner' ) );
		}

		$context  = $this->build_context( $form );
		$settings = GFSC_Plugin::get_settings();
		$mode     = ( 'delete' === $settings['mode'] ) ? 'delete' : 'trash';

		$total_count = 0;
		$entries     = GFAPI::get_entries(
			$form_id,
			array( 'status' => 'active' ),
			null,
			array( 'offset' => $offset, 'page_size' => $batch_limit ),
			$total_count
		);
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$deleted       = 0;
		$newly_blocked = array();
		$details       = array();

		foreach ( $entries as $entry ) {
			$check = $this->get_spam_reason( $form, $entry, $context );
			if ( null === $check['reason'] ) {
				continue;
			}

			if ( 'trash' === $mode ) {
				$result = GFAPI::update_entry_property( $entry['id'], 'status', 'trash' );
				if ( is_wp_error( $result ) || false === $result ) {
					return is_wp_error( $result ) ? $result : new WP_Error( 'gfsc_trash_failed', __( 'Could not move an entry to trash.', 'gf-smart-cleaner' ) );
				}
			} else {
				$result = GFAPI::delete_entry( $entry['id'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
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
			GFSC_Logger::log( $form_id, $deleted, $newly_blocked, $details, $source, $mode );
		}

		return array(
			'scanned'     => count( $entries ),
			'deleted'     => $deleted,
			'blocked'     => count( $newly_blocked ),
			'total'       => (int) $total_count,
			'next_offset' => $offset + ( count( $entries ) - $deleted ),
			'details'     => $details,
		);
	}

	/**
	 * Scan all active entries (up to $max_entries) without changing anything.
	 *
	 * @return array|WP_Error {scanned, candidates: [{id, email, date_created, reason}]}
	 */
	public function run_preview( $form_id, $batch_limit = 100, $max_entries = 5000 ) {
		$form = GFAPI::get_form( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'gfsc_no_form', __( 'Form not found.', 'gf-smart-cleaner' ) );
		}

		$context = $this->build_context( $form );

		$offset     = 0;
		$scanned    = 0;
		$candidates = array();

		while ( $offset < $max_entries ) {
			$entries = GFAPI::get_entries(
				$form_id,
				array( 'status' => 'active' ),
				null,
				array( 'offset' => $offset, 'page_size' => $batch_limit )
			);
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}
			if ( empty( $entries ) ) {
				break;
			}

			foreach ( $entries as $entry ) {
				$scanned++;
				$check = $this->get_spam_reason( $form, $entry, $context );
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
	 * Convenience wrapper for single-entry checks (submission-time blocking).
	 *
	 * @return array {email: string, reason: string|null}
	 */
	public function entry_spam_check( $form, $entry ) {
		return $this->get_spam_reason( $form, $entry, $this->build_context( $form ) );
	}

	/**
	 * Load the per-form detection context once per batch.
	 */
	public function build_context( $form ) {
		return array(
			'email_field_id'  => $this->resolve_email_field_id( $form ),
			'blocked_emails'  => GFSC_Plugin::get_blocked_emails(),
			'blocked_domains' => GFSC_Plugin::get_blocked_domains(),
			'whitelist'       => GFSC_Plugin::get_whitelist(),
		);
	}

	/**
	 * Decide whether an entry is spam.
	 *
	 * @param array $context See build_context().
	 * @return array {email: string, reason: string|null}
	 */
	public function get_spam_reason( $form, $entry, array $context ) {
		$email  = strtolower( trim( (string) rgar( $entry, (string) $context['email_field_id'] ) ) );
		$result = array( 'email' => $email, 'reason' => null );

		if ( $this->is_whitelisted( $email, $context['whitelist'] ) ) {
			return $result;
		}

		if ( '' !== $email ) {
			foreach ( $context['blocked_domains'] as $domain ) {
				if ( false !== strpos( $email, $domain ) ) {
					$result['reason'] = sprintf( __( 'Disposable email domain (%s)', 'gf-smart-cleaner' ), $domain );
					return $result;
				}
			}
			if ( preg_match( '/\d{2,}\./', $email ) ) {
				$result['reason'] = __( 'Suspicious email pattern (dot-trick abuse)', 'gf-smart-cleaner' );
				return $result;
			}
			if ( in_array( $email, $context['blocked_emails'], true ) ) {
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
	 * Whitelist match: exact email, exact domain, or parent-domain suffix.
	 *
	 * @param string   $email     Lowercased email.
	 * @param string[] $whitelist Lowercased emails and bare domains.
	 */
	public function is_whitelisted( $email, array $whitelist ) {
		if ( '' === $email || empty( $whitelist ) ) {
			return false;
		}
		$at     = strrchr( $email, '@' );
		$domain = $at ? substr( $at, 1 ) : '';

		foreach ( $whitelist as $item ) {
			if ( $item === $email ) {
				return true;
			}
			if ( false === strpos( $item, '@' ) && '' !== $domain ) {
				if ( $domain === $item || str_ends_with( $domain, '.' . $item ) ) {
					return true;
				}
			}
		}
		return false;
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
	 * The email field to use for blocklist checks: the per-form override if
	 * set, otherwise the form's first email-type field.
	 *
	 * @return string Field ID, or '' when the form has no email field.
	 */
	public function resolve_email_field_id( $form ) {
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;
		$map     = GFSC_Plugin::get_email_field_map();
		if ( $form_id && ! empty( $map[ $form_id ] ) ) {
			return (string) $map[ $form_id ];
		}
		foreach ( $form['fields'] as $field ) {
			if ( 'email' === $field->type ) {
				return (string) $field->id;
			}
		}
		return '';
	}
}
