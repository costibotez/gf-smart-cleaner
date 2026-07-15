# Gravity Forms Smart Spam Cleaner

**Author:** Costin Botez
**Version:** 2.0.0
**Requires:** WordPress + Gravity Forms

## 🚀 What It Does

This plugin detects and removes spam entries from Gravity Forms based on:

- Gibberish input detection (e.g. `AjqERvytZ`) — no vowels, long consonant runs, random mixed-case text
- Cyrillic & Telegram message spam
- Dot-trick email abuse (`j.85.2.4.7@gmail.com`)
- Disposable email domains
- A learned email blocklist that grows over time

Gibberish heuristics only run on free-text fields (text, textarea, name); emails, URLs, numbers and choice fields are skipped to avoid false positives.

### Features

✅ Preview mode — review spam candidates (with the matched rule) before deleting
✅ Live pass-by-pass progress while cleaning
✅ Activity log — every cleanup run is recorded (who, when, what was deleted and why)
✅ Admin form selector with optional email-field override
✅ Editable blocked email list (invalid addresses are discarded on save)
✅ Batched, paginated cleanup that scans all entries, not just the first page

---

## ⚙️ How to Use

1. Upload and activate the plugin (Gravity Forms must be active).
2. Go to **Forms → Smart Spam Cleaner**.
3. Select the form you want to clean and save.
4. Click **Run Preview** to see spam candidates and the rule each one matched.
5. Click **Run Full Cleanup** to remove them.

Blocked emails are saved and reused for future checks.

---

## 🧠 Learning Mode

Every time a spam entry with a valid email address is deleted, that address is added to a local blocklist stored in `wp_options` (`gf_smart_blocked_emails`). Entries whose email is already on the blocklist are deleted automatically on the next run.

You can view and edit the blocklist on the settings page.

---

## 🔒 Security

- All cleanup/preview AJAX endpoints require the `manage_options` capability and a nonce, and only accept POST requests.
- All settings forms are nonce-protected (CSRF-safe) and inputs are sanitized before saving.
- Uninstalling the plugin removes all of its options.

---

## 📁 Files

- `gf-smart-cleaner.php` – bootstrap (constants, dependency check, wiring)
- `includes/class-gfsc-plugin.php` – main class and blocklist option helpers
- `includes/class-gfsc-spam-engine.php` – detection heuristics + cleanup/preview logic
- `includes/class-gfsc-admin.php` – settings page and activity log UI
- `includes/class-gfsc-ajax.php` – nonce/capability-checked AJAX endpoints
- `includes/class-gfsc-logger.php` – activity log (capped ring buffer in an option)
- `assets/js/admin.js` – admin UI script (progress, preview table)
- `uninstall.php` – cleanup on uninstall

---

## 💬 Want to Contribute?

This plugin was built for internal use at [Inception Group](https://inception-group.com) — but contributions and ideas are welcome! Fork it, test it, improve it.

---

## 📝 License

MIT – use freely and responsibly.
