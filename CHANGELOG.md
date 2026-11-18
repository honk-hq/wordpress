# Changelog

All notable changes to the Honk! Honk! WordPress plugin (slug `honk-me`) are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The WordPress.org changelog in `readme.txt` repeats
each entry in short.

## [Unreleased]

## [0.3.0] - 2026-11-19

The first release from this repository on WordPress.org, where the listing `honk-me` already had
0.1.0 and 0.2.0 under the earlier name "Honk Me". It follows 0.2.0, so sites on it are offered
the update; the [0.1.0] below is this repository's own first version, never published.

### Security
- The server URL setting and the test button can no longer reach the site's own network: every
  request to the Honk! Honk! server goes through `wp_safe_remote_*`, and a local or private
  address (localhost, `.local`/`.internal` names, private and reserved IPs) is accepted only with
  `define( 'HONK_ALLOW_LOCAL_SERVER', true )` in wp-config.php or the `honk_allow_local_server`
  filter. On a multisite network only a network administrator can change the server URL.

### Added
- The delivery log shows what Honk! Honk! corrected in an accepted message (the `warnings` of
  its answer, e.g. `title: truncated, actions[1].url: ignored`), and the test button does too.
- A refused message's log row names the server's error code and message, every field and the
  request id (`(request req_…)`).

### Fixed
- The heartbeat stopped for good after a deactivation and a reactivation (the "scheduled" flag
  outlived the unscheduled check-in); retries that were waiting resume at their own time on
  activation, not at the next daily clean-up.
- A fatal error is delivered during the failing request (at most 2 s), not queued behind a
  loopback and WP-Cron that fatal too.
- Contact Form 7 sends only the form's own fields; captcha tokens (`g-recaptcha-response`,
  `h-captcha-response`, `cf-turnstile-response` and the like) are never sent, from any form plugin.
- A restock closes the problem that is open, even when the level went through a switched-off one
  (out of stock, then low with low stock off, then back in stock).

### Changed
- Privacy: an administrator's username is personal data. Without "Include customer names and
  emails", the new-device sign-in title says "An administrator signed in from a new device", the
  "who did it" detail of plugin, theme, file and update events is left out, and new
  administrator, role and site address messages say "by an administrator".
- The message builder no longer cuts or checks what the server now corrects itself ("deliver
  first", contracts/API.md §5.1): title, message, source, environment, channel and group key
  lengths, the number of metadata keys and their value lengths, button titles, links and count, level and priority
  values, and the rule that a problem or recovery needs a group key. It still turns WordPress
  text into plain text, leaves out empty fields, repeats the title when a message has no text,
  keeps only https links as the message link and keeps the body within 16 KiB.
- `Honk_Payload::action_url()` is removed; the server leaves out an invalid button with a
  warning and keeps the others. The plugin's own buttons (`mailto_url()`, `tel_url()`) are
  unchanged.
- Level names: the level menus and the preview show the standard name with the Honk name in
  parentheses, from Info (Light) to Critical (Blast), in every language (RO „Eroare (Tuuut)”,
  ES "Error (Piii)", FR « Erreur (Tûûût) », DE „Fehler (Tüüüt)“). They replace the earlier
  localized names (RO Discret … Maxim and the like). The values sent are unchanged.

## [0.1.0] - 2026-10-18

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
  reads in Honk! Honk!. Personal details count only while the privacy switch is on. Contact Form 7,
  WPForms and Gravity Forms fields can be left out one by one. With the defaults, messages are
  the same as without the details.
- **Buttons** on notifications (message actions, `contracts/API.md` §13), chosen per event
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
- Every button is off by default.
- Background delivery with Action Scheduler or WP-Cron, retries with backoff and `Retry-After`,
  idempotency keys, the 16 KiB body limit, a delivery log of the last 50 sends.
- "Send test notification" in Settings → Honk! Honk!; a Honk! Honk! tab in WooCommerce → Settings.
- Heartbeat: a check-in every 5 minutes (`POST /v1/heartbeats/check-in`) under a slug made from
  the site's address; the first one creates the heartbeat in the key's project, later ones send
  only the slug. Shown once the server reports `heartbeats: true` in `GET /v1/config`.
- Name "Honk! Honk! – Notifications for Sites, Shops and Forms", slug and text domain `honk-me`.
- No bundled translation files: translations come from translate.wordpress.org; the notification
  language works with any language installed on the site. The Romanian, Spanish, French and
  German translations are ready to import (`languages-src/`).
