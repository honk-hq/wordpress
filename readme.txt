=== Honk – Notifications for Sites, Shops and Forms ===
Contributors: honkhonk
Tags: notifications, woocommerce, security, contact form, monitoring
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get orders, failed payments, form entries, suspicious sign-ins and site errors in Honk, on your iPhone, Apple Watch and the web.

== Description ==

Honk is a calm notification inbox. This plugin tells Honk what happens on your WordPress site or WooCommerce store, so you hear about it on your iPhone, your Apple Watch and in the web app.

Honk groups repeats instead of sending each one: the first failed payment is a push, the tenth in a row just adds to a counter.

You decide what you hear about. Every event has its own on/off switch, a priority, and a level on the Honk scale: Light honk, Beep-beep, Loud honk, Long honk or Blast.

= WooCommerce =

* New orders, once they're paid or on hold (classic and block checkout, plus orders created in the admin or through the REST API)
* Order status changes (Completed, Cancelled, On hold…)
* Failed payments
* Refunds, full or partial
* Low stock and out of stock, with an all-clear when the product is back in stock
* New customer accounts
* New product reviews
* Failed subscription renewals (WooCommerce Subscriptions)
* A daily sales summary (optional)

Works with High-Performance Order Storage (HPOS) and the block checkout.

= Security =

* An administrator signs in from a new device or network
* Many failed sign-ins in a short time, with an all-clear when they stop
* A new administrator, or a changed user role
* The administration email address or the site address changed
* Plugins and themes installed, activated, deactivated, deleted or updated
* Theme or plugin files edited in the dashboard

= Site health =

* Critical errors that crash a page or put WordPress into recovery mode (WordPress's own email is still sent)
* Site Health critical issues, checked daily
* Failed automatic updates
* Available updates, in one daily message
* Scheduled tasks (WP-Cron) running late
* Disk almost full, if your host lets WordPress check

= Content and users =

* New user registrations
* Comments awaiting moderation
* Posts pending review
* Published posts (off by default)

= Forms =

Contact Form 7, WPForms, Gravity Forms, Elementor Pro Forms, Fluent Forms and Ninja Forms are found automatically. Each notification is titled with the form's name, and what people entered is included only if you allow personal data.

= Private by default =

Out of the box, notifications contain no personal data: a new order reads "Order #1234 · €84.00 · 2 items". Turn on "Include customer names and emails" to also see names, email addresses, comments and form entries.

= Never slows your site down =

Notifications go out in the background (through Action Scheduler when WooCommerce is installed, otherwise WP-Cron) and are retried automatically. A slow or unreachable Honk server never holds up a page or a checkout, and a retry never notifies you twice.

= Languages =

English, Romanian, Spanish, French and German. Notifications use your site's language, or another language you pick for them.

== External services ==

This plugin connects to the Honk API to deliver your notifications. Honk is a notification service at [honk-me.app](https://honk-me.app); you can also use your own Honk server. **Nothing is sent until you add an API key** in Settings → Honk.

What is sent, and when:

* **When an event you turned on happens** (for example a new order, a failed payment, many failed sign-ins or a form entry), one message is sent to `POST /v1/messages` on the Honk server. It contains a title and a text, the Honk-scale level and the priority, a group key, the event type, your site's address as the source, the environment (production, staging or development), a link to the related screen in your dashboard, and a few technical details (for example the order number, total and status, or the file name of a plugin that changed).
* **Personal data is not sent by default.** If you turn on "Include customer names and emails," messages can also contain the name and email address of a customer, user or commenter, the text of a comment or review, the fields of a submitted form, the usernames tried during failed sign-ins, and the full IP address of a new administrator sign-in (otherwise only its network, such as 203.0.113.0/24).
* **When you click "Send test notification"**, one test message is sent.
* **The settings screen** reads the server's public configuration (`GET /v1/config`) at most every 12 hours, and again when you save the settings or send a test, to know which features the server supports. This request contains no data about your site.
* **The heartbeat** (once Honk supports it, and only if you turn it on) checks in every 5 minutes with your site's address and environment.

Honk's privacy policy: [https://honk-me.app/privacy](https://honk-me.app/privacy). Terms and support: [https://honk-me.app/support](https://honk-me.app/support).

The plugin doesn't track you or your visitors, and it loads no external scripts, styles or fonts.

== Installation ==

1. In your dashboard, go to Plugins → Add New Plugin, search for "Honk" and click Install Now. Or upload the `honk` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. In Honk (https://honk-me.app), open your project, go to Keys and create a new key. Copy it.
4. In WordPress, go to Settings → Honk, paste the key into "API key" and click "Send test notification". The test should reach your Honk inbox within seconds.
5. Choose your events, their levels and priorities, and click Save Changes.

WooCommerce stores also get a Honk tab under WooCommerce → Settings that links to the same page.

== Frequently Asked Questions ==

= Do I need a Honk account? =

Yes. Honk is where your notifications arrive. Create a project in Honk, add a key under Keys, and paste it into Settings → Honk.

= Is anything sent before I add a key? =

No. Until a key is saved, the plugin doesn't listen to any events and sends nothing.

= Will it slow down my checkout? =

No. Notifications are queued and sent in the background, with Action Scheduler when WooCommerce is installed and with WP-Cron otherwise. At checkout, the plugin only saves one small queue entry.

= What if Honk is down or slow? =

The plugin tries again with longer and longer pauses, for up to about two hours, and waits longer whenever the server asks it to. Each notification has its own ID, so a retry never notifies you twice. Errors that trying again can't fix, such as a revoked key, aren't retried; you'll find them under Settings → Honk → Delivery log.

= What personal data is sent? =

None by default. Orders are described by their number, total and number of items, and form entries only by the form's name. If you turn on "Include customer names and emails," names, email addresses, comments, reviews and form entries are included. While it's off, the delivery log hides titles that might contain personal data.

= Which form plugins are supported? =

Contact Form 7, WPForms, Gravity Forms, Elementor Pro Forms, Fluent Forms and Ninja Forms. Active ones are found automatically and get their own switch.

= Can I run my own Honk server? =

Yes. Enter its address as the server URL. It must use https; http works only for local and private addresses, for development.

= Why do some events come with an all-clear? =

Things that start and stop, such as many failed sign-ins, a product out of stock, scheduled tasks running late or a Site Health issue, are sent twice: once as a problem when they start, and once as an all-clear (a recovery, in Honk's words) when they're over. Honk shows both as one episode that closes when the all-clear arrives.

= How does it know a sign-in is from a new device? =

For administrators, the plugin remembers the browser, operating system and IP network of recent sign-ins (up to 20, for 180 days), stored only as hashes, never as readable details. A sign-in that matches none of them is reported. The very first sign-in after you install the plugin is only remembered.

= Can I change a message before it's sent? =

Yes, if you're a developer: use the `honk_message` filter. It receives the message fields and the event ID; return an empty array to drop the message.

= Where's the heartbeat? =

The heartbeat ("tell me when my site stops checking in") needs support on the Honk server, which is on its way. The option shows up in Settings → Honk as soon as your server supports it.

== Screenshots ==

1. Settings → Honk: connection, test button, privacy and language.
2. Events grouped into Security, Site health, Content & users, Forms and WooCommerce, each with its own level and priority.
3. The result of "Send test notification".
4. The delivery log: the last 50 notifications and their status.
5. WooCommerce orders and a failed payment in the Honk inbox.
6. Many failed sign-ins as one episode in Honk, closed by its all-clear.

== Changelog ==

= 0.1.0 =
* First release: WooCommerce, security, site health, content and form notifications, plus a test button; background sending with automatic retries; English, Romanian, Spanish, French and German.

== Upgrade Notice ==

= 0.1.0 =
First release.
