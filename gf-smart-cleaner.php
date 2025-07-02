<?php
/*
Plugin Name: Gravity Forms Smart Spam Cleaner
Description: Automatically detects and deletes spam entries from Gravity Forms based on gibberish content and blocked emails. Learns over time by saving blocked emails to a list.
Version: 1.7.1
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

        foreach ($form['fields'] as $field) {
            $field_id = $field->id;
            $value = rgar($entry, (string)$field_id);

            // Skip empty or short labels to avoid deleting short but valid content
            if (!$value || strlen(trim($value)) < 3) continue;

            // Strong spam heuristics only
            if (!preg_match('/[aeiou]/iu', $value)) $delete = true;
            if (preg_match('/[bcdfghjklmnpqrstvwxyz]{5,}/iu', $value)) $delete = true;
            if (preg_match('/([a-z]+[A-Z]+[a-z]+)/', $value)) $delete = true;
            if (preg_match('/[\p{Cyrillic}]/u', $value) && preg_match('/телеграм|telegram/i', $value)) $delete = true;
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

add_filter('gform_addon_navigation', function($menu) {
    $menu[] = array(
        'name' => 'gf_smart_cleaner',
        'label' => __('Smart Spam Cleaner', 'gravityforms'),
        'callback' => 'gf_smart_cleaner_settings_page',
        'permission' => 'manage_options'
    );
    return $menu;
});

function gf_smart_cleaner_settings_page() {
    $selected_form_id = get_option('gf_smart_cleaner_form_id', 0);
    $forms = GFAPI::get_forms();

    if (isset($_POST['gf_smart_blocked_manual'])) {
        $manual_emails = array_filter(array_map('trim', explode("\n", $_POST['gf_smart_blocked_manual'])));
        $existing = get_option('gf_smart_blocked_emails', array());
        $merged = array_unique(array_merge($existing, array_map('strtolower', $manual_emails)));
        update_option('gf_smart_blocked_emails', $merged);
        echo '<div class="updated"><p>Manual blocked emails updated.</p></div>';
    }

    if (isset($_POST['gf_smart_cleaner_form_id'])) {
        update_option('gf_smart_cleaner_form_id', intval($_POST['gf_smart_cleaner_form_id']));
        $selected_form_id = intval($_POST['gf_smart_cleaner_form_id']);
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $existing_blocked = implode("\n", get_option('gf_smart_blocked_emails', array()));

    echo '<div class="wrap"><h2>Gravity Forms Smart Spam Cleaner</h2>';
    echo '<form method="post">';
    echo '<table class="form-table"><tr><th>Select Form</th><td><select name="gf_smart_cleaner_form_id">';
    foreach ($forms as $form) {
        $selected = $form['id'] == $selected_form_id ? 'selected' : '';
        echo '<option value="' . esc_attr($form['id']) . '" ' . $selected . '>' . esc_html($form['title']) . '</option>';
    }
    echo '</select></td></tr></table>';
    submit_button('Save Settings');
    echo '</form>';

    if ($selected_form_id) {
        echo '<div id="gf-smart-cleaner-progress"></div>';
        echo '<button id="gf-smart-cleaner-start" class="button button-primary">Run Full Cleanup</button>';
    }

    echo '<form method="post" style="margin-top:30px;">';
    echo '<h3>Manually Add Blocked Emails</h3>';
    echo '<textarea name="gf_smart_blocked_manual" rows="6" cols="60">' . esc_textarea($existing_blocked) . '</textarea><br>';
    echo '<input type="submit" class="button" value="Update Blocked List">';
    echo '</form>';
    echo '</div>';

    echo '<script>
    document.getElementById("gf-smart-cleaner-start")?.addEventListener("click", async function(e) {
        e.preventDefault();
        const progress = document.getElementById("gf-smart-cleaner-progress");
        const formId = ' . intval($selected_form_id) . ';
        progress.innerHTML = "<p>Cleaning in progress...</p>";

        let totalDeleted = 0;
        let round = 0;

        while (true) {
            const response = await fetch(ajaxurl + "?action=gf_smart_cleaner_run&form_id=" + formId);
            const data = await response.json();
            totalDeleted += data.deleted;
            round++;
            progress.innerHTML = `<p>Pass #${round}: Deleted ${data.deleted} entries, Blocked ${data.blocked} new emails.</p>` + progress.innerHTML;
            if (data.deleted === 0) break;
        }

        progress.innerHTML = `<h4>✅ Cleanup complete. Total deleted: ${totalDeleted}</h4>` + progress.innerHTML;
    });
    </script>';
}

add_action('wp_ajax_gf_smart_cleaner_run', function() {
    $form_id = intval($_GET['form_id'] ?? 0);
    if (!$form_id) wp_send_json_error('Missing form ID');
    $result = gf_smart_spam_cleaner_run($form_id);
    wp_send_json($result);
});
