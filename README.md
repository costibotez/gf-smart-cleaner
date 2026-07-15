<div align="center">

# 🧹 Gravity Forms Smart Spam Cleaner

**Set-and-forget spam removal for Gravity Forms — with a safety net.**

![Version](https://img.shields.io/badge/version-2.1.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-21759b?logo=wordpress&logoColor=white)
![Gravity Forms](https://img.shields.io/badge/Gravity%20Forms-required-orange)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

Detects gibberish, disposable emails, and Telegram spam in your form entries —
then trashes them **recoverably**, on a schedule, or blocks them before they're even saved.

</div>

---

## ✨ Why this plugin?

Spam bots love Gravity Forms. Manually deleting hundreds of `AjqERvytZ` entries doesn't scale, and most cleanup scripts delete first and ask questions never. Smart Spam Cleaner takes a different approach:

- 🛟 **Nothing is lost by default.** Spam goes to the Gravity Forms Trash — restorable for ~30 days with one click.
- 🧠 **It learns.** Every confirmed spam email joins a blocklist; every restored entry teaches the whitelist.
- 🔍 **It shows its work.** Preview mode and the activity log tell you exactly *which rule* flagged *which entry*.
- 🤖 **It runs itself.** Schedule cleanups hourly to weekly, get an email report, and block spam at the door.

---

## 🚀 Features

| | Feature | What it does |
|---|---|---|
| 🛟 | **Recoverable removals** | Spam moves to GF Trash (default) with per-entry **Restore** buttons in the log. Permanent deletion is opt-in. |
| ⏰ | **Automated cleanup** | WP-Cron schedule: hourly, twice daily, daily, or weekly. Processes every selected form to completion. |
| 🚪 | **Block at submission** | Hooks `gform_entry_is_spam` so junk is flagged the moment it arrives — it never becomes an active entry. |
| 📧 | **Email summaries** | After a scheduled run that removes entries: per-form counts + reason breakdown in your inbox. |
| 🔍 | **Preview mode** | Dry-run scan showing every candidate and the exact rule it matched. Nothing is touched. |
| 📋 | **Activity log** | Every run recorded: trigger (user / scheduled / submission), form, counts, and per-entry reasons. |
| 🗂️ | **Multi-form support** | Clean any number of forms, each with an optional email-field override (auto-detected otherwise). |
| ✅ | **Whitelist** | Emails or domains (subdomains included) that are *never* flagged — it always wins over every other rule. |
| ✏️ | **Editable lists** | Blocked emails, blocked domains, and the whitelist are all managed right on the settings page. |

---

## 🧠 How detection works

An entry is flagged when **any** rule matches — unless the whitelist says otherwise (the whitelist always wins).

### Email rules

| Rule | Example caught |
|---|---|
| Disposable / blocked domain (editable list) | `user@sharklasers.com`, `x@tempmail.org` |
| Dot-trick abuse (digits + dots) | `j.85.2.4.7@gmail.com` |
| Learned blocklist | Any address that was spam before |

### Content rules — free-text fields only

Gibberish heuristics run **only** on text, textarea, and name fields. Emails, URLs, numbers, and choice fields are skipped, so field values like `https://example.com/abc` can't trigger false positives.

| Rule | Example caught | Survives the rule |
|---|---|---|
| No vowels | `xKqWpZtRv` | — |
| 5+ consonant run | `AjqERvytZ` | *"strengths"* has vowels around it |
| Random mixed case (2+ transitions) | `aXeYoZuQa` | `McDonald`, `JavaScript`, `iPhone` |
| Cyrillic + Telegram | `пишите в телеграм @…` | Cyrillic text alone is fine |

### Learning loop

```
spam removed  ──►  email joins the blocklist  ──►  future entries auto-flagged
entry restored ──► email leaves the blocklist ──►  joins the whitelist, never flagged again
```

---

## ⚙️ Getting started

1. **Install & activate** (Gravity Forms must be active — you'll get a notice, not a fatal, if it isn't).
2. Go to **Forms → Smart Spam Cleaner**.
3. ✔️ Tick the forms to clean, pick a **removal mode**, and save.
4. 🔍 **Run Preview** — review the candidates and their matched rules.
5. 🧹 **Run Full Cleanup** — or enable **Automated Cleanup** and walk away.

### Settings at a glance

| Setting | Options | Default |
|---|---|---|
| Forms to clean | Any of your GF forms + optional email-field ID override each | none |
| Removal mode | 🛟 Move to Trash / ⚠️ Delete permanently | Trash |
| Block at submission | on / off | off |
| Automated cleanup | off / hourly / twice daily / daily / weekly | off |
| Email summary | on / off + recipient | off → admin email |

---

## 🛟 Recovering entries

With the default **Move to Trash** mode:

- Entries sit in **Forms → Entries → Trash** for ~30 days before Gravity Forms purges them.
- The activity log shows a **Restore** button next to each removed entry. Restoring:
  1. returns the entry to Active,
  2. removes its email from the blocklist,
  3. whitelists it — so it's never flagged again.

> ⚠️ Entries removed in **Delete permanently** mode cannot be recovered. That's why it isn't the default.

---

## 🔒 Security

- All AJAX endpoints (run / preview / restore) require the `manage_options` capability **and** a nonce, POST-only.
- Every settings form is nonce-protected (CSRF-safe); all input is sanitized and validated on save.
- Invalid emails never enter the blocklist — including the empty string (a v1.x bug that could nuke legitimate entries).
- Uninstalling removes every plugin option and clears the schedule. No leftovers.

---

## 📁 Architecture

```
gf-smart-cleaner/
├── gf-smart-cleaner.php               ← bootstrap: constants, wiring, deactivation hook
├── uninstall.php                      ← full cleanup on uninstall
├── assets/
│   └── js/admin.js                    ← progress UI, preview table, restore buttons
└── includes/
    ├── class-gfsc-plugin.php          ← wiring, settings & list accessors, legacy migration
    ├── class-gfsc-spam-engine.php     ← detection heuristics + cleanup/preview batching
    ├── class-gfsc-admin.php           ← settings page & activity log UI
    ├── class-gfsc-ajax.php            ← nonce/capability-gated endpoints
    ├── class-gfsc-cron.php            ← scheduled cleanup + email summary
    ├── class-gfsc-submission.php      ← gform_entry_is_spam integration
    └── class-gfsc-logger.php          ← activity log (capped ring buffer)
```

---

## 📜 Changelog

### 2.1.0
- 🛟 Recoverable removals (GF Trash) with one-click Restore — new default
- ⏰ Scheduled automated cleanup via WP-Cron
- 🚪 Submission-time blocking (`gform_entry_is_spam`)
- 📧 Email summaries after scheduled runs
- ✅ Whitelist + editable blocked-domain list
- 🗂️ Multi-form support with per-form email-field overrides (legacy settings migrate automatically)

### 2.0.0
- 🏗️ Full OOP restructure from a single file
- 🔒 Capability + nonce checks on all endpoints and forms (previously any logged-in user could trigger deletion)
- 🐛 Fixed blocklist poisoning by empty emails, hardcoded email field, first-page-only pagination, and false positives on names like *McDonald*
- 🔍 Preview mode and activity log

### 1.x
- Original single-file cleaner

---

## 💬 Contributing

Built for internal use at [Inception Group](https://inception-group.com) — but ideas, issues, and PRs are welcome. Fork it, test it, improve it.

## 📝 License

[MIT](https://opensource.org/licenses/MIT) — use freely and responsibly.
