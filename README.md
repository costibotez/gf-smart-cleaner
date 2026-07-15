# Gravity Forms Smart Spam Cleaner

**Author:** Costin Botez
**Version:** 2.1.0
**Requires:** WordPress + Gravity Forms

## 🚀 What It Does

This plugin detects and removes spam entries from Gravity Forms based on:

- Gibberish input detection (e.g. `AjqERvytZ`) — no vowels, long consonant runs, random mixed-case text
- Cyrillic & Telegram message spam
- Dot-trick email abuse (`j.85.2.4.7@gmail.com`)
- Disposable email domains (editable list)
- A learned email blocklist that grows over time

Gibberish heuristics only run on free-text fields (text, textarea, name); emails, URLs, numbers and choice fields are skipped to avoid false positives. Whitelisted emails and domains are never flagged.

### Features

✅ **Recoverable removals** — spam entries go to the Gravity Forms Trash by default (restorable for ~30 days), with one-click Restore from the activity log; permanent deletion is opt-in
✅ **Automated cleanup** — recurring WP-Cron schedule (hourly / twice daily / daily / weekly)
✅ **Block at submission** — flag spam via Gravity Forms' spam filter the moment it's submitted
✅ **Email summaries** — get a report after scheduled runs that remove entries
✅ **Multi-form support** — clean any number of forms, each with an optional email-field override
✅ Preview mode — review spam candidates (with the matched rule) before removing
✅ Activity log — every run is recorded (trigger, who/when, what was removed and why)
✅ Editable blocked email list, blocked domain list, and whitelist

---

## ⚙️ How to Use

1. Upload and activate the plugin (Gravity Forms must be active).
2. Go to **Forms → Smart Spam Cleaner**.
3. Tick the forms you want to clean, pick a removal mode, and save.
4. Click **Run Preview** to see spam candidates and the rule each one matched.
5. Click **Run Full Cleanup** to remove them — or enable **Automated Cleanup** and let the schedule handle it.

### Recovering entries

With the default **Move to Trash** mode, removed entries sit in the Gravity Forms Trash (Forms → Entries → Trash) for around 30 days. The plugin's activity log also shows a **Restore** button per entry: restoring puts the entry back, removes its email from the blocklist, and whitelists it so it won't be flagged again. Entries removed in **Delete permanently** mode cannot be recovered.

---

## 🧠 Learning Mode

Every time a spam entry with a valid email address is removed, that address is added to a local blocklist stored in `wp_options` (`gf_smart_blocked_emails`). Entries whose email is already on the blocklist are removed automatically on the next run. The whitelist always wins over the blocklist.

You can edit the blocklist, blocked domains, and whitelist on the settings page.

---

## 🔒 Security

- All cleanup/preview/restore AJAX endpoints require the `manage_options` capability and a nonce, and only accept POST requests.
- All settings forms are nonce-protected (CSRF-safe) and inputs are sanitized before saving.
- Uninstalling the plugin removes all of its options and clears the schedule.

---

## 📁 Files

- `gf-smart-cleaner.php` – bootstrap (constants, dependency check, wiring, deactivation hook)
- `includes/class-gfsc-plugin.php` – main class, settings/list accessors, legacy option migration
- `includes/class-gfsc-spam-engine.php` – detection heuristics + cleanup/preview logic
- `includes/class-gfsc-admin.php` – settings page and activity log UI
- `includes/class-gfsc-ajax.php` – nonce/capability-checked AJAX endpoints (run, preview, restore)
- `includes/class-gfsc-cron.php` – scheduled cleanup + email summary
- `includes/class-gfsc-submission.php` – submission-time spam blocking (`gform_entry_is_spam`)
- `includes/class-gfsc-logger.php` – activity log (capped ring buffer in an option)
- `assets/js/admin.js` – admin UI script (progress, preview table, restore)
- `uninstall.php` – cleanup on uninstall

---

## 💬 Want to Contribute?

This plugin was built for internal use at [Inception Group](https://inception-group.com) — but contributions and ideas are welcome! Fork it, test it, improve it.

---

## 📝 License

MIT – use freely and responsibly.
