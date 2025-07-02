<?php
/*
Plugin Name: Gravity Forms Smart Spam Cleaner
Description: Automatically detects and deletes spam entries from Gravity Forms based on gibberish content and blocked emails. Learns over time by saving blocked emails to a list.
Version: 1.8.1
Author: Costin Botez
*/

if ( ! defined( 'ABSPATH' ) ) exit;

function gf_smart_spam_cleaner_run($form_id, $preview = false, $batch_limit = 100) {
    $blocked_emails = get_option('gf_smart_blocked_emails', array());
    $blocked_domains = array('email-temp.com', 'tempmail', 'sharklasers.com', '10minutemail');
    $form = GFAPI::get_form($form_id);
    $entries = GFAPI::get_entries($form_id, null, null, array('page_size' => $batch_limit));

    $deleted = 0;
    $newly_blocked_emails = array();
    $preview_entries = array();

    foreach ($entries as $entry) {
        $delete = false;
        $email = rgar($entry, '2');

        $email_is_spam = false;
        foreach ($blocked_domains as $domain) {
            if (strpos($email, $domain) !== false) $email_is_spam = true;
        }
        if (preg_match('/\d{2,}\./', $email)) $email_is_spam = true;
        if (in_array(strtolower($email), $blocked_emails)) $email_is_spam = true;

        if ($email_is_spam) $delete = true;

        $field_matches = 0;
        $field_total = 0;

        foreach ($form['fields'] as $field) {
            $field_id = $field->id;
            $value = rgar($entry, (string)$field_id);

            if (!$value || strlen(trim($value)) < 3) continue;
            $field_total++;

            $value = trim($value);
            if (
                !preg_match('/[aeiou]/iu', $value) ||
                preg_match('/[bcdfghjklmnpqrstvwxyz]{5,}/iu', $value) ||
                preg_match('/([a-z]+[A-Z]+[a-z]+)/', $value) ||
                (preg_match('/[\p{Cyrillic}]/u', $value) && preg_match('/телеграм|telegram/i', $value))
            ) {
                $field_matches++;
            }
        }

        if ($field_total > 0 && $field_matches > 0 && ($field_matches / $field_total >= 0.8)) {
            $delete = true;
        } else {
            $delete = false;
        }

        if ($delete) {
            $newly_blocked_emails[] = strtolower($email);
            if ($preview) {
                $preview_entries[] = array('id' => $entry['id'], 'email' => $email, 'date_created' => $entry['date_created']);
            } else {
                GFAPI::delete_entry($entry['id']);
                $deleted++;
            }
        }
    }

    if (!$preview && !empty($newly_blocked_emails)) {
        $merged = array_unique(array_merge($blocked_emails, $newly_blocked_emails));
        update_option('gf_smart_blocked_emails', $merged);
    }

    return $preview ? $preview_entries : array('deleted' => $deleted, 'blocked' => count($newly_blocked_emails));
}
