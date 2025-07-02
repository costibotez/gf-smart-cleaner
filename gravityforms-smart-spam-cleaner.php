<?php
/*
Plugin Name: Gravity Forms Smart Spam Cleaner
Description: Automatically detects and deletes spam entries from Gravity Forms based on gibberish content and blocked emails. Learns over time by saving blocked emails to a list.
Version: 1.8.0
Author: Costin Botez
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Include admin UI
require_once plugin_dir_path( __FILE__ ) . 'admin-ui.php';

/**
 * Core spam cleaner logic
 */
function gf_smart_spam_cleaner_run( $form_id, $preview = false, $batch_limit = 100, $offset = 0 ) {
    $blocked_emails   = get_option( 'gf_smart_blocked_emails', array() );
    $blocked_domains  = array( 'email-temp.com', 'tempmail', 'sharklasers.com', '10minutemail' );

    // Fetch form and entries batch
    $form    = GFAPI::get_form( $form_id );
    $paging  = array( 'offset' => $offset, 'page_size' => $batch_limit );
    $entries = GFAPI::get_entries( $form_id, null, null, $paging );
    $processed = count( $entries );

    // Locate email field dynamically
    $email_field_id = null;
    foreach ( $form['fields'] as $f ) {
        if ( property_exists( $f, 'type' ) && $f->type === 'email' ) {
            $email_field_id = $f->id;
            break;
        }
    }

    $deleted               = 0;
    $newly_blocked_emails  = array();
    $preview_entries       = array();

    foreach ( $entries as $entry ) {
        $delete = false;
        $email  = $email_field_id ? trim( strtolower( rgar( $entry, (string) $email_field_id ) ) ) : '';

        // 1) Email-based checks
        $email_is_spam = false;
        if ( $email ) {
            // disposable domains
            foreach ( $blocked_domains as $dom ) {
                if ( strpos( $email, '@' . $dom ) !== false ) {
                    $email_is_spam = true;
                    break;
                }
            }
            // dot-trick abuse
            if ( preg_match( '/\d{2,}\./', $email ) ) {
                $email_is_spam = true;
            }
            // learned blocklist
            if ( in_array( $email, $blocked_emails, true ) ) {
                $email_is_spam = true;
            }
        }

        // 2) Gibberish & Cyrillic checks
        $field_total   = 0;
        $field_matches = 0;
        foreach ( $form['fields'] as $field ) {
            $fid   = $field->id;
            $value = rgar( $entry, (string) $fid );
            if ( ! $value || strlen( trim( $value ) ) < 3 ) {
                continue;
            }
            $field_total++;
            $v = trim( $value );

            if (
                ! preg_match( '/[aeiou]/iu', $v )                                       // no vowels
                || preg_match( '/[bcdfghjklmnpqrstvwxyz]{5,}/iu', $v )                  // long consonant runs
                || preg_match( '/[a-z]+[A-Z]+[a-z]+/', $v )                             // weird case mix
                || ( preg_match( '/[\p{Cyrillic}]/u', $v ) && preg_match( '/телеграм|telegram/i', $v ) )
            ) {
                $field_matches++;
            }
        }
        $gibberish_spam = ( $field_total > 0 && ( $field_matches / $field_total ) >= 0.8 );

        // Final decision
        if ( $email_is_spam || $gibberish_spam ) {
            $delete = true;
        }

        if ( $delete ) {
            if ( $email ) {
                $newly_blocked_emails[] = $email;
            }
            $preview_entries[] = array(
                'id'           => $entry['id'],
                'email'        => $email,
                'date_created' => $entry['date_created'],
                'fields'       => array_filter( $entry, function( $val, $key ) use ( $email_field_id ) {
                    return is_numeric( $key ) && $key != $email_field_id;
                }, ARRAY_FILTER_USE_BOTH ),
            );
            if ( ! $preview ) {
                GFAPI::delete_entry( $entry['id'] );
                $deleted++;
            }
        }
    }

    // Merge new blocks only on full cleanup
    if ( ! $preview && ! empty( $newly_blocked_emails ) ) {
        $merged = array_unique( array_merge( $blocked_emails, $newly_blocked_emails ) );
        update_option( 'gf_smart_blocked_emails', $merged );
    }

    return $preview
        ? array( 'preview' => $preview_entries, 'processed' => $processed )
        : array( 'deleted' => $deleted, 'blocked' => count( $newly_blocked_emails ), 'processed' => $processed );
}
