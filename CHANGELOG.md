# Changelog

All notable changes to the Honk Me WordPress plugin (slug `honk-me`) are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The WordPress.org changelog in `readme.txt` repeats
each entry in short.

## [0.2.0] - 2026-10-07

### Added
- **Buttons** on notifications (Honk's message actions, `contracts/API.md` §13), chosen per event
  under Details → Buttons, with the preview showing them: **Email customer** and **Call
  customer** on new orders, status changes and failed payments (Email customer also on refunds,
  failed subscription renewals and new customer accounts), **Email user** on registrations,
  **Approve** and **Reply by email** on comments and product reviews awaiting moderation, and
  **Reply by email** and **Call back** on form entries, to the email address and phone number
  that were entered. At most three per notification.
- A button that emails or calls someone is added only while the notification includes that
  email address or phone number (so only with "Include customer names and emails" on), and only
  when the address or number is valid: phone numbers are reduced to their digits, and numbers
  with letters or extensions get no button. Approve uses WordPress's own moderation link (which
  asks to confirm) and needs an https dashboard. A button never repeats the notification's own
  link.
- Every button is off by default, so with the defaults every notification is exactly what 0.1.0
  sent.

## [0.1.0] - 2026-10-05

### Added
- **WooCommerce:** new order (classic checkout, block checkout through the Store API, admin and
  REST orders, once paid or on hold), order status changes, failed payment, refund (full or
  partial), low and out of stock with a recovery when restocked, new customer account, new
  product review, subscription renewal failed (WooCommerce Subscriptions), optional daily sales
  summary. HPOS (`custom_order_tables`) and block checkout compatible.
- **Security:** administrator sign-in from a new device or network (hashed memory of recent
  devices), failed sign-in bursts as a problem with a recovery, new administrator, role changes,
  admin email and site address changes, plugins and themes installed, activated, deactivated,
  deleted and updated, theme and plugin file edits in the dashboard.
- **Site health:** fatal errors (WordPress's fatal error handler and recovery mode), Site Health
  critical issues (daily), failed automatic updates, updates available (daily, one message),
  WP-Cron overdue, disk almost full.
- **Content and users:** new registration, comment awaiting moderation, post pending review,
  post published (off by default).
- **Forms:** Contact Form 7, WPForms, Gravity Forms, Elementor Pro Forms, Fluent Forms and Ninja
  Forms, detected automatically.
- Per-event switch, Honk-scale level and priority; privacy switch for customer names and emails
  (off by default); environment detected from `wp_get_environment_type()`; notification
  language (site language by default).
- **Details** for every event: what its notification always includes and what can be added or
  left out (for a new order: total, items, status, payment and shipping method, the first three
  products, the customer's name, email, phone, billing city and note), with a preview of how it
  reads in Honk. Personal details count only while the privacy switch is on. Contact Form 7,
  WPForms and Gravity Forms fields can be left out one by one. With the defaults, messages are
  the same as without the details.
- Background delivery with Action Scheduler or WP-Cron, retries with backoff and `Retry-After`,
  idempotency keys, the 16 KiB body limit, a delivery log of the last 50 sends.
- "Send test notification" in Settings → Honk; a Honk tab in WooCommerce → Settings.
- Heartbeat module, shown once the Honk server reports `heartbeats: true` in `GET /v1/config`.
- Name "Honk Me – Notifications for Sites, Shops and Forms", slug and text domain `honk-me`.
- No bundled translation files: translations come from translate.wordpress.org; the notification
  language works with any language installed on the site. The Romanian, Spanish, French and
  German translations are ready to import (`languages-src/`).
