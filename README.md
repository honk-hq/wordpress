# Honk for WordPress

[![CI](https://github.com/honk-me/honk-wordpress/actions/workflows/ci.yml/badge.svg)](https://github.com/honk-me/honk-wordpress/actions/workflows/ci.yml)

The official WordPress plugin for [Honk](https://honk-me.app), the calm notification inbox:
orders, payments, stock, sign-ins, site health and form submissions from WordPress, WooCommerce
and the popular form plugins, in your Honk inbox, on iPhone, Apple Watch and the web.

- WordPress.org slug: **`honk`**, name "Honk – Notifications for Sites, Shops and Forms".
- PHP 7.4+, WordPress 6.4+ (tested up to 7.1), WooCommerce optional (HPOS and block checkout
  compatible). No Composer runtime dependencies: requests go through the WordPress HTTP API.
- GPL-2.0-or-later. The user-facing documentation is [`readme.txt`](readme.txt) (the
  WordPress.org page).

## How it works

```text
WordPress hook ─▶ module (builds the text in the notification language)
               ─▶ Honk_Notifier::emit()  event switch, level, priority, privacy, dedupe
               ─▶ Honk_Queue::push()      option honk_job_<id>, Action Scheduler or WP-Cron
               ─▶ honk_deliver            POST {server}/v1/messages, retries, delivery log
```

- **Nothing listens until a key is saved.** Without an ingestion key the modules do not register
  their hooks.
- **Never on the request path.** A hook only stores a job (one option) and schedules
  `honk_deliver` (Action Scheduler when WooCommerce or another plugin loads it, else a WP-Cron
  single event, plus a non-blocking `spawn_cron()` at shutdown).
- **Retries** on network errors, `429` and `5xx`: 6 attempts with exponential backoff and
  jitter (15–30 s, 1–2 min, 4–8 min, 16–32 min, 30–60 min), never sooner than `Retry-After`,
  never more than 23 h after the first attempt (the server's idempotency window is 24 h), and not
  for a `Retry-After` beyond 6 h (a daily quota). No retry on other `4xx`; `409
  idempotency_conflict` counts as already sent.
- **Idempotency:** every event has a stable key, sent as `Idempotency-Key: wp-<site id>-<key>`
  (e.g. `wp-3f9a1c2e-order-1234-new`), reused on every retry. The same key is also remembered
  locally for 24 h, so a hook that fires twice never queues twice.
- **Limits** (`contracts/openapi.yaml`): title ≤ 160 code points, message ≤ 8 192 bytes,
  metadata ≤ 16 keys, the whole body ≤ 16 KiB (the message is shortened first), `url` only when
  https (otherwise the link goes to `metadata.link`).
- **User-Agent** `honk-wordpress/<version> (+https://honk-me.app)`.

## Events

Defaults follow the plan: new order Beep-beep, failed payment Loud honk, fatal error Long honk,
new administrator Blast. Every event has its own switch, level and priority in Settings → Honk.

| Event | Hooks | `group_key` | Idempotency key | Default |
|---|---|---|---|---|
| **WooCommerce** | | | | |
| New order | `woocommerce_checkout_order_processed`, `woocommerce_store_api_checkout_order_processed`, `woocommerce_new_order`, `woocommerce_order_status_changed` (unpaid → processing/on-hold/completed); reported at shutdown once paid or on hold, flagged with order meta `_honk_new_order_sent` | `woo/orders` | `order-<id>-new` | Beep-beep, normal |
| Order status changed | `woocommerce_order_status_changed` (not placing, failed, refunded, pending → cancelled) | `woo/orders/status` | `order-<id>-<from>-<to>-<modified>` | Light honk, low |
| Failed payment | `woocommerce_order_status_failed` | `woo/payments/failed` | `order-<id>-failed-<modified>` | Loud honk, normal |
| Refund | `woocommerce_order_refunded` | `woo/refunds` | `refund-<refund id>` | Loud honk, normal |
| Low / out of stock, back in stock | `woocommerce_low_stock`, `woocommerce_no_stock`, `woocommerce_product_set_stock`, `woocommerce_variation_set_stock` (state kept per product: problem, problem, recovery) | `woo/stock/<product id>` | `stock-<id>-<state>-<seq>` | Loud honk / Long honk |
| New customer | `woocommerce_created_customer` | `woo/customers` | `customer-<id>` | Beep-beep, low |
| New product review | `wp_insert_comment` (product, type review) | `woo/reviews` | `review-<comment id>` | Light honk, low |
| Subscription renewal failed | `woocommerce_subscription_renewal_payment_failed` (only with WooCommerce Subscriptions) | `woo/subscriptions/renewal-failed` | `subscription-<id>-renewal-<order>-failed` | Long honk, high |
| Daily sales summary (off) | WP-Cron `honk_daily_summary`, 08:00 site time | `woo/summary` | `summary-<Y-m-d>` | Light honk, low |
| **Security** | | | | |
| Admin sign-in from a new device | `wp_login` (users with `manage_options`; browser + OS and IP network, hashed, last 20 for 180 days in user meta `honk_known_devices`; the first sign-in only learns) | `wp/security/admin-login` | `login-<user>-<fingerprint>` | Loud honk, normal |
| Burst of failed sign-ins | `wp_login_failed` (≥ 10 in 5 min by default) → problem; WP-Cron `honk_login_burst_check` → recovery after a calm window | `wp/security/login-burst` | `login-burst-<start>`, `…-recovered` | Long honk, high |
| New administrator | `user_register`, `set_user_role`, `add_user_role` (at shutdown), `granted_super_admin` | `wp/security/admins` | `admin-<user>-<YmdH>` | Blast, high |
| Role changed | `set_user_role`, `add_user_role` / `remove_user_role` (at shutdown, outside `set_role()`) | `wp/security/roles` | `role-<user>-<hash>-<YmdH>` | Loud honk, normal |
| Admin email / site address changed | `update_option_admin_email`, `update_option_siteurl`, `update_option_home` | `wp/security/site-identity` | `identity-<option>-<hash>` | Long honk, high |
| Plugin installed / activated / deactivated / deleted | `upgrader_process_complete`, `activated_plugin`, `deactivated_plugin` (Honk itself: sent before the request ends), `delete_plugin` + `deleted_plugin` | `wp/security/plugins` | `plugin-<action>-<hash>-<YmdHi>` | Loud honk, normal |
| Theme installed / switched / deleted | `upgrader_process_complete`, `switch_theme`, `delete_theme` + `deleted_theme` | `wp/security/themes` | `theme-<action>-<hash>-<YmdHi>` | Loud honk, normal |
| File edited in the dashboard | `wp_ajax_edit-theme-plugin-file` (nonce checked, checksum before and at shutdown) | `wp/security/file-edits` | `edit-<path hash>-<md5>` | Long honk, high |
| Updates installed | `upgrader_process_complete` (update), `_core_updated_successfully`, `automatic_updates_complete` | `wp/security/updates` | `updated-<type>-<hash>` | Light honk, low |
| **Site health** | | | | |
| Fatal error | `recovery_mode_email` (dashboard and sign-in page; the email is returned unchanged) and `wp_php_error_message` (the "critical error" page, front end included) | `wp/health/fatal` | `fatal-<hash>-<Ymd>` | Long honk, high |
| Site Health critical issues | daily `honk_daily`: the direct and `async_direct_test` Site Health tests, as WordPress's own scheduled check; problem when new issues appear, recovery when none are left | `wp/health/site-health` | `site-health-<hash>-<Ymd>` | Long honk, normal |
| Automatic update failed | `automatic_updates_complete` | `wp/health/updates-failed` | `autoupdate-failed-<hash>` | Long honk, high |
| Updates available | daily: one message when the list of core, plugin and theme updates changes | `wp/health/updates-available` | `updates-<hash>` | Light honk, low |
| WP-Cron overdue | hourly `honk_hourly` and every 10 min in the dashboard: the same task > 30 min late in two checks ≥ 15 min apart → problem (delivered through a loopback request, since WP-Cron may be dead); recovery when on time | `wp/health/cron` | `cron-overdue-<ts>`, `cron-recovered-<ts>` | Loud honk, normal |
| Disk almost full | hourly: < 5 % or < 1 GB free → problem, > 10 % and > 2 GB → recovery (only where `disk_free_space()` works) | `wp/health/disk` | `disk-low-<Ymd>`, `disk-ok-<ts>` | Loud honk, normal |
| **Content and users** | | | | |
| New registration | `user_register` (not administrators; not WooCommerce customers while that event is on) | `wp/users/registrations` | `user-<id>-registered` | Light honk, low |
| Comment awaiting moderation | `wp_insert_comment` (approved = 0) | `wp/comments/moderation` | `comment-<id>-pending` | Light honk, low |
| Post pending review | `transition_post_status` → pending | `wp/posts/pending` | `post-<id>-pending-<modified>` | Light honk, normal |
| Post published (off) | `transition_post_status` → publish | `wp/posts/published` | `post-<id>-published` | Beep-beep, low |
| **Forms** (title = form name; fields only with personal data on) | | | | |
| Contact Form 7 | `wpcf7_submit` (mail sent or mail failed) | `wp/forms/cf7/<form>` | `form-cf7-<form>-<hash>` | Light honk, normal |
| WPForms | `wpforms_process_complete` | `wp/forms/wpforms/<form>` | `form-wpforms-<form>-entry-<id>` | 〃 |
| Gravity Forms | `gform_after_submission` | `wp/forms/gravityforms/<form>` | `form-gravityforms-<form>-entry-<id>` | 〃 |
| Elementor Pro Forms | `elementor_pro/forms/new_record` | `wp/forms/elementor/<form>` | `form-elementor-<form>-<request>` | 〃 |
| Fluent Forms | `fluentform/submission_inserted` | `wp/forms/fluentforms/<form>` | `form-fluentforms-<form>-entry-<id>` | 〃 |
| Ninja Forms | `ninja_forms_after_submission` | `wp/forms/ninjaforms/<form>` | `form-ninjaforms-<form>-entry-<id>` | 〃 |

Every message also carries `source` (the site's host), `environment` (Settings, or
`wp_get_environment_type()`: production, staging, development), `channel` (the section:
`woocommerce`, `security`, `health`, `content`, `forms`), a `category` (taxonomy v1) and a
link to the related wp-admin screen. Recoveries are sent as Beep-beep and never louder than
normal priority.

### Heartbeat

`Honk_Heartbeat` checks in every 5 minutes (Action Scheduler recurring action, or a 5-minute
WP-Cron schedule) once the server reports `"heartbeats": true` in `GET /v1/config` and the
option is on. Until then the option shows "Coming soon". `Honk_Heartbeat::check_in()` is the
only function that talks to the (provisional) endpoint `POST /v1/heartbeats/check-in`: change
`Honk_Heartbeat::ENDPOINT` and the body there when the server ships heartbeats.

### Filters

- `honk_message( array $payload, string $event_id )`: change a message before it is queued;
  return an empty array to drop it.
- `honk_client_ip( string $ip )`: the client IP for the security events (default `REMOTE_ADDR`;
  behind a trusted proxy, return the forwarded address).

## Development

```sh
composer install            # PHPUnit 9.6, Brain Monkey, WPCS 3, PHPCompatibilityWP (dev only)
composer test               # unit tests (Brain Monkey + an in-memory WordPress)
composer lint               # PHPCS: WordPress-Extra, WordPress-Docs, PHP 7.4 compatibility
bin/build.sh                # build/honk/ and build/honk-<version>.zip, as published (.distignore)
```

Translations live in `languages/`: `honk.pot` (WP-CLI `wp i18n make-pot`), `.po`, `.mo` and
`.l10n.php` for `ro_RO`, `es_ES`, `fr_FR` and `de_DE`. Terminology follows the Honk glossary
(the Honk scale names…) and, for WordPress and WooCommerce screens, the words WordPress uses in
each language. Copy that site and shop owners see avoids developer jargon: the settings screen
calls the ingestion key an "API key" and points people to **Keys** in the Honk web app, and a
recovery is an "all-clear". `.mo` files are built with `msgfmt` and `.l10n.php` files with
`wp i18n make-php` from the same entries; fuzzy entries are left out of both until a translator
confirms them. The bundled files are used until a language pack from translate.wordpress.org is
installed.

### Integration test (what was run before 0.1.0)

A throwaway WordPress 7.1 + WooCommerce 11.1 (HPOS on) + Contact Form 7 in Docker (official
`wordpress`, `mariadb` and `wordpress:cli` images), a scratch Honk server from `server/` in dev
mode, the plugin pointed at it through `host.docker.internal`. Triggered with WP-CLI, the Store
API (block checkout), `wp-login.php` and the dashboard editor; every message was checked in the
Honk inbox (titles, levels, group keys, problem/recovery episodes) and duplicate hooks did not
duplicate. Plugin Check (`wp plugin check honk`) reports no errors or warnings.

## Releasing

The version lives in `honk.php` (header `Version` and `HONK_VERSION`) and in `readme.txt`
(`Stable tag`); `release.yml` checks that they, `CHANGELOG.md` and the tag agree.

1. Bump the three, add `## [x.y.z] - YYYY-MM-DD` to `CHANGELOG.md` and `= x.y.z =` to the
   `readme.txt` changelog (and an upgrade notice). Commit in the monorepo (`sdk/wordpress`).
2. Tag the monorepo `sdk-wordpress-vX.Y.Z`; `sdk-mirror` pushes `vX.Y.Z` here, and
   `release.yml` runs the tests, deploys to the WordPress.org SVN (trunk, `tags/X.Y.Z`,
   assets from `.wordpress-org/`) with `10up/action-wordpress-plugin-deploy`, and attaches the
   zip to the GitHub release.

Secrets (environment `wordpress-org`): `SVN_USERNAME`, `SVN_PASSWORD` (the WordPress.org
account's SVN password, set in its profile).
