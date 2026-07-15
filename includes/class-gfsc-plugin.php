<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main plugin class: checks dependencies, migrates legacy options,
 * exposes settings/list accessors and wires up components.
 */
class GFSC_Plugin {

	const OPTION_BLOCKED_EMAILS  = 'gf_smart_blocked_emails';
	const OPTION_FORM_IDS        = 'gf_smart_cleaner_form_ids';
	const OPTION_EMAIL_FIELD_MAP = 'gf_smart_cleaner_email_field_map';
	const OPTION_BLOCKED_DOMAINS = 'gf_smart_cleaner_blocked_domains';
	const OPTION_WHITELIST       = 'gf_smart_cleaner_whitelist';
	const OPTION_SETTINGS        = 'gf_smart_cleaner_settings';

	// Pre-2.1 options, converted by maybe_migrate().
	const OPTION_LEGACY_FORM_ID        = 'gf_smart_cleaner_form_id';
	const OPTION_LEGACY_EMAIL_FIELD_ID = 'gf_smart_cleaner_email_field_id';

	const CAPABILITY = 'manage_options';
	const AJAX_NONCE = 'gfsc_ajax';

	const DEFAULT_BLOCKED_DOMAINS = array( 'email-temp.com', 'tempmail', 'sharklasers.com', '10minutemail' );

	public static function init() {
		load_plugin_textdomain( 'gf-smart-cleaner', false, dirname( plugin_basename( GFSC_PLUGIN_DIR . 'gf-smart-cleaner.php' ) ) . '/languages' );

		if ( ! class_exists( 'GFAPI' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_gravity_forms_notice' ) );
			return;
		}

		self::maybe_migrate();

		$engine = new GFSC_Spam_Engine();

		$admin = new GFSC_Admin( $engine );
		$admin->register_hooks();

		$ajax = new GFSC_Ajax( $engine );
		$ajax->register_hooks();

		$cron = new GFSC_Cron( $engine );
		$cron->register_hooks();

		$submission = new GFSC_Submission( $engine );
		$submission->register_hooks();
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
	 * Convert the pre-2.1 single-form options into the multi-form model.
	 */
	private static function maybe_migrate() {
		if ( false !== get_option( self::OPTION_FORM_IDS, false ) ) {
			return;
		}

		$legacy_form_id = (int) get_option( self::OPTION_LEGACY_FORM_ID, 0 );
		update_option( self::OPTION_FORM_IDS, $legacy_form_id ? array( $legacy_form_id ) : array(), false );

		$legacy_field = get_option( self::OPTION_LEGACY_EMAIL_FIELD_ID, '' );
		if ( $legacy_form_id && '' !== $legacy_field && null !== $legacy_field ) {
			update_option( self::OPTION_EMAIL_FIELD_MAP, array( $legacy_form_id => (string) absint( $legacy_field ) ), false );
		}

		delete_option( self::OPTION_LEGACY_FORM_ID );
		delete_option( self::OPTION_LEGACY_EMAIL_FIELD_ID );
	}

	/* ----- Settings ----- */

	public static function get_default_settings() {
		return array(
			'mode'                => 'trash',
			'cron_enabled'        => false,
			'cron_frequency'      => 'daily',
			'email_summary'       => false,
			'email_recipient'     => get_option( 'admin_email' ),
			'block_at_submission' => false,
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_default_settings() );
	}

	public static function update_settings( array $settings ) {
		$merged = wp_parse_args( $settings, self::get_settings() );
		update_option( self::OPTION_SETTINGS, $merged, false );
		return $merged;
	}

	/* ----- Forms ----- */

	/**
	 * @return int[] IDs of the forms selected for cleanup.
	 */
	public static function get_form_ids() {
		$ids = get_option( self::OPTION_FORM_IDS, array() );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}

	public static function set_form_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		update_option( self::OPTION_FORM_IDS, $ids, false );
		return $ids;
	}

	/**
	 * @return array [form_id => email field id] overrides.
	 */
	public static function get_email_field_map() {
		$map = get_option( self::OPTION_EMAIL_FIELD_MAP, array() );
		return is_array( $map ) ? $map : array();
	}

	public static function set_email_field_map( array $map ) {
		update_option( self::OPTION_EMAIL_FIELD_MAP, $map, false );
		return $map;
	}

	/* ----- Blocked emails ----- */

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
		$merged = array_values( array_unique( array_merge( self::get_blocked_emails(), self::sanitize_email_list( $emails ) ) ) );
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
		$clean = array_values( array_unique( self::sanitize_email_list( $emails ) ) );
		update_option( self::OPTION_BLOCKED_EMAILS, $clean, false );
		return $clean;
	}

	public static function remove_blocked_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$list  = array_values( array_diff( self::get_blocked_emails(), array( $email ) ) );
		update_option( self::OPTION_BLOCKED_EMAILS, $list, false );
		return $list;
	}

	/* ----- Blocked domains ----- */

	/**
	 * @return string[] Lowercased domain fragments matched as substrings of the email.
	 */
	public static function get_blocked_domains() {
		$domains = get_option( self::OPTION_BLOCKED_DOMAINS, false );
		if ( false === $domains ) {
			return self::DEFAULT_BLOCKED_DOMAINS;
		}
		return is_array( $domains ) ? $domains : array();
	}

	public static function set_blocked_domains( array $domains ) {
		$clean = array();
		foreach ( $domains as $domain ) {
			$domain = self::sanitize_domain( $domain );
			if ( '' !== $domain ) {
				$clean[] = $domain;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		update_option( self::OPTION_BLOCKED_DOMAINS, $clean, false );
		return $clean;
	}

	/* ----- Whitelist ----- */

	/**
	 * @return string[] Whitelisted emails and domains (lowercased).
	 */
	public static function get_whitelist() {
		$list = get_option( self::OPTION_WHITELIST, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function set_whitelist( array $entries ) {
		$clean = array();
		foreach ( $entries as $entry ) {
			$entry = strtolower( trim( (string) $entry ) );
			if ( '' === $entry ) {
				continue;
			}
			if ( is_email( $entry ) ) {
				$clean[] = $entry;
				continue;
			}
			$domain = self::sanitize_domain( $entry );
			if ( '' !== $domain ) {
				$clean[] = $domain;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		update_option( self::OPTION_WHITELIST, $clean, false );
		return $clean;
	}

	public static function add_whitelist_entries( array $entries ) {
		return self::set_whitelist( array_merge( self::get_whitelist(), $entries ) );
	}

	/* ----- Helpers ----- */

	/**
	 * @param string[] $emails
	 * @return string[] Lowercased valid emails; invalid lines dropped.
	 */
	private static function sanitize_email_list( array $emails ) {
		$clean = array();
		foreach ( $emails as $email ) {
			$email = strtolower( sanitize_email( trim( (string) $email ) ) );
			if ( $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}
		return $clean;
	}

	/**
	 * Normalize a domain (or domain fragment) entry: lowercase, strip
	 * scheme/@/path, reject anything with whitespace or odd characters.
	 */
	private static function sanitize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^[a-z]+://#', '', $domain );
		$domain = ltrim( $domain, '@' );
		$domain = preg_replace( '#[/?].*$#', '', $domain );
		if ( '' === $domain || ! preg_match( '/^[a-z0-9._-]+$/', $domain ) ) {
			return '';
		}
		return $domain;
	}
}
