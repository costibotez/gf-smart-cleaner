# Gravity Forms Smart Spam Cleaner

**Author:** Costin Botez  
**Version:** 1.8.0  
**Requires:** WordPress + Gravity Forms

## 🚀 What It Does

This plugin detects and removes spam entries from Gravity Forms based on:

- Gibberish input detection (e.g. `AjqERvytZ`)
- Cyrillic & Telegram message spam
- Dot-trick email abuse (`j.85.2.4.7@gmail.com`)
- Disposable email domains
- Repeated patterns learned over time

### Bonus Features:
✅ Live progress bar  
✅ Activity log viewer  
✅ Admin form selector  
✅ Editable blocked email list  
✅ Preview mode (review before deleting)

---

## ⚙️ How to Use

1. Upload and activate the plugin.
2. Go to **Gravity Forms → Spam Cleaner**
3. Select the form you want to clean.
4. Click **Run Preview** to see spam candidates.
5. Click **Run Full Cleanup** to remove them.

Blocked emails are saved and reused for future checks.

---

## 🧠 Learning Mode

Every time spam is deleted, the plugin adds the associated email address to a local blocklist stored in `wp_options`.

You can manage this blocklist in the admin interface.

---

## 💬 Want to Contribute?

This plugin was built for internal use at [Inception Group](https://inception-group.com) — but contributions and ideas are welcome! Fork it, test it, improve it.

---

## 📁 Files

- `gravityforms-smart-spam-cleaner.php` – core logic
- `admin-ui.php` – settings page & logs
- `assets/` – styles and icons

---

## 📝 License

MIT – use freely and responsibly.
