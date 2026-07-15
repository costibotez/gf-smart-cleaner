<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

wp_clear_scheduled_hook( 'gfsc_scheduled_cleanup' );

delete_option( 'gf_smart_blocked_emails' );
delete_option( 'gf_smart_cleaner_form_id' );
delete_option( 'gf_smart_cleaner_email_field_id' );
delete_option( 'gf_smart_cleaner_form_ids' );
delete_option( 'gf_smart_cleaner_email_field_map' );
delete_option( 'gf_smart_cleaner_blocked_domains' );
delete_option( 'gf_smart_cleaner_whitelist' );
delete_option( 'gf_smart_cleaner_settings' );
delete_option( 'gf_smart_cleaner_log' );
