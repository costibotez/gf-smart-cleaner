<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main plugin class: checks dependencies and wires up components.
 */
class GFSC_Plugin {

	const OPTION_BLOCKED_EMAILS = 'gf_smart_blocked_emails';
	const OPTION_FORM_ID        = 'gf_smart_cleaner_form_id';
	const OPTION_EMAIL_FIELD_ID = 'gf_smart_cleaner_email_field_id';

	const CAPABILITY  = 'manage_options';
	const AJAX_NONCE  = 'gfsc_ajax';

	public static function init() {
		load_plugin_textdomain( 'gf-smart-cleaner', false, dirname( plugin_basename( GFSC_PLUGIN_DIR . 'gf-smart-cleaner.php' ) ) . '/languages' );

		if ( ! class_exists( 'GFAPI' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_gravity_forms_notice' ) );
			return;
		}

		$engine = new GFSC_Spam_Engine();

		$admin = new GFSC_Admin( $engine );
		$admin->register_hooks();

		$ajax = new GFSC_Ajax( $engine );
		$ajax->register_hooks();
	}

	public static function missing_gravity_forms_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Gravity Forms Smart Spam Cleaner requires Gravity Forms to be installed and active.', 'gf-smart-cleaner' )
		);
	}

	/**
	 * @return string[] Lowercased blocklisted email addresses.
	 */
	public static function get_blocked_emails() {
		$emails = get_option( self::OPTION_BLOCKED_EMAILS, array() );
		return is_array( $emails ) ? $emails : array();
	}

	/**
	 * Merge new addresses into the blocklist, keeping only valid emails.
	 *
	 * @param string[] $emails
	 * @return string[] The saved blocklist.
	 */
	public static function add_blocked_emails( array $emails ) {
		$clean = array();
		foreach ( $emails as $email ) {
			$email = strtolower( sanitize_email( trim( $email ) ) );
			if ( $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}
		$merged = array_values( array_unique( array_merge( self::get_blocked_emails(), $clean ) ) );
		update_option( self::OPTION_BLOCKED_EMAILS, $merged, false );
		return $merged;
	}

	/**
	 * Replace the blocklist wholesale (used by the manual editor).
	 *
	 * @param string[] $emails
	 * @return string[] The saved blocklist.
	 */
	public static function set_blocked_emails( array $emails ) {
		$clean = array();
		foreach ( $emails as $email ) {
			$email = strtolower( sanitize_email( trim( $email ) ) );
			if ( $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		update_option( self::OPTION_BLOCKED_EMAILS, $clean, false );
		return $clean;
	}
}
