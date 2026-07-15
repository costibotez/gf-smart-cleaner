<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'gf_smart_blocked_emails' );
delete_option( 'gf_smart_cleaner_form_id' );
delete_option( 'gf_smart_cleaner_email_field_id' );
delete_option( 'gf_smart_cleaner_log' );
