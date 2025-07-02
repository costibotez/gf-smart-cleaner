# Gravity Forms Smart Spam Cleaner

A WordPress plugin that automatically detects and deletes spam entries in [Gravity Forms](https://www.gravityforms.com/) based on gibberish input, disposable emails, Cyrillic Telegram spam, and more. The plugin learns over time by building a smart blocked email list.

---

## 🚀 Features

- Detects spam based on:
  - Gibberish text (e.g. no vowels, long consonant chains)
  - Suspicious patterns (e.g. random casing, Cyrillic with “Telegram”)
  - Disposable or temporary email domains
  - Custom blocked email list
- Automatically builds and updates a blocked list
- Manual textarea to add/remove blocked emails
- Live cleanup with AJAX + progress tracking
- Auto-runs cleanup in batches (no more button mashing)
- Works with any form, dynamically loads field structure

---

## 🧠 How it Works

1. Go to `Forms → Smart Spam Cleaner`
2. Select a form to scan
3. Click **Run Full Cleanup**
4. It will:
   - Loop through entries in batches
   - Detect spammy patterns
   - Delete matching entries
   - Update your blocklist automatically

You can also **manually edit the blocked email list**.

---

## 📦 Installation

1. Clone this repo into `/wp-content/plugins/`:

```bash
git clone https://github.com/your-username/gravityforms-smart-spam-cleaner.git
```

2. Activate the plugin in your WordPress dashboard.
3. Navigate to **Forms → Smart Spam Cleaner** to configure and run it.

---

## ✅ Requirements

- WordPress 5.0+
- Gravity Forms 2.5+
- Admin access to your site

---

## 📂 Folder Structure

```
gravityforms-smart-spam-cleaner/
├── gravityforms-smart-spam-cleaner.php
├── README.md
└── (optional) LICENSE
```

---

## 📥 Roadmap Ideas

- Schedule cleanup via WP-Cron
- CSV export of blocked or deleted entries
- Undo last cleanup (soft-delete mode)
- Add IP/domain blocking logic

---

## 🙌 Contributing

Feel free to fork, clone, and submit PRs. Let’s clean up Gravity Forms together!

---

## 🧑‍💻 Author

Made with 💡 by [Costin Botez](https://nomad-developer.co.uk)
