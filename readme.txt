=== Sendora ===
Contributors: jonathanelmgren
Tags: email, smtp, wp_mail, transactional email, newsletter
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends your site's email through Sendora's API instead of PHP mail() or SMTP, and keeps bounced, complaining and unsubscribed addresses off your sends.

== Description ==

Sendora's own plugin for WordPress sends every email WordPress sends with `wp_mail()` through [Sendora](https://sendora.se), our email delivery service, instead of PHP's `mail()` or an SMTP server. Password resets, order confirmations, form notifications and newsletters all go out through Sendora once an API key is set. Without a key, WordPress sends email as it would without the plugin.

You need a Sendora account and a server key from your Sendora dashboard.

= Features =

* **Transactional and broadcast streams.** Every message goes on Sendora's transactional stream unless a filter or a header asks for the broadcast stream. Broadcasts follow Sendora's rules before they are sent: each carries Sendora's unsubscribe link and goes to each recipient alone.
* **Delivery reports.** A webhook receives Sendora's reports of bounces, spam complaints and unsubscribes, signed with a secret you save on the settings page.
* **Suppression list.** A hard bounce stops all mail to the address; a complaint or an unsubscribe stops broadcasts only, so a password reset still arrives. **Tools → Sendora suppressions** lists the addresses, finds one, and removes one.
* **List cleaning.** Import an address verifier's results (Bouncer, ZeroBounce, NeverBounce or any CSV) before your first send, on the Tools screen or with `wp sendora import`, so an old list does not bounce.
* **Test mode.** A test server's key (`sk_test_…`) makes Sendora accept the mail and deliver none of it. The settings page sends a test email.
* **Settings in wp-config.php.** Every setting can be a constant, which wins over the settings page.
* **Hooks for other plugins.** Filters choose the stream and rewrite a broadcast; actions fire for every delivery report, so a newsletter plugin can keep its subscribers in step.

= Newsletter plugins =

The plugin ships no code for any newsletter plugin. Its documentation has recipes for The Newsletter Plugin and FluentCRM: how to send their newsletters on the broadcast stream, where to put Sendora's unsubscribe link, and how to mark a subscriber who bounced, complained or unsubscribed. MailPoet sends with its own mailer, never through WordPress, so its newsletters cannot go through Sendora; its recipe keeps its subscribers in step. See the FAQ.

== External services ==

This plugin connects to our API at Sendora ([sendora.se](https://sendora.se)) to send your site's email. It sends nothing until an API key is saved on **Settings → Sendora** or set in `wp-config.php`.

**What is sent, and when.** Each time WordPress sends an email with `wp_mail()` (for instance a password reset, a comment notification, a newsletter, or the test email on the settings page), the plugin sends that message to `https://api.sendora.se`: its recipients (To, Cc and Bcc addresses and names), its sender and Reply-To addresses, its subject, its text and HTML bodies, its other headers, its attachments, and the stream it goes on. The API key is sent with each request to identify your Sendora server. Recipients on the suppression list are left out and not sent.

**What is received.** We send delivery reports (bounces, spam complaints, unsubscribes and other delivery events) to the site's webhook, `/wp-json/sendora/v1/webhook`, once you create a webhook in your Sendora dashboard. A report holds the event's type, the recipient's address, the message's id, the stream's id and the time. The plugin checks each report's signature with your signing secret, and keeps only the address, the reason, the stream and the time on the suppression list.

The plugin sends no other data, to us or anyone else: no usage statistics and no tracking.

* Sendora's terms of service: [https://sendora.se/terms](https://sendora.se/terms)
* Sendora's privacy policy: [https://sendora.se/privacy](https://sendora.se/privacy)
* Sendora's data processing agreement: [https://sendora.se/dpa](https://sendora.se/dpa)

== Installation ==

1. Install and activate the plugin from **Plugins → Add New**, or upload the `sendora` folder to `/wp-content/plugins/`.
2. In your Sendora dashboard, create a server key, and verify the domain your site sends from.
3. Go to **Settings → Sendora**, paste the key, and set the From address and name the site uses when a message names no sender. Save.
4. Send a test email from the same page.
5. For delivery reports, create a webhook in Sendora's dashboard with the address the settings page shows, subscribe it to `bounced`, `spam_complaint` and `unsubscribed`, and paste its signing secret on the settings page.
6. To send newsletters, set the broadcast stream's id and follow the recipe for your newsletter plugin (see the FAQ).

Every setting can be set in `wp-config.php` instead, which keeps the key out of the database:

`define( 'SENDORA_API_KEY', 'sk_live_…' );`

The other constants are `SENDORA_TRANSACTIONAL_STREAM`, `SENDORA_BROADCAST_STREAM`, `SENDORA_FROM_EMAIL`, `SENDORA_FROM_NAME` and `SENDORA_WEBHOOK_SECRET`.

== Frequently Asked Questions ==

= Do I need a Sendora account? =

Yes. The plugin sends through your Sendora server, with its key. Without a key it does nothing, and WordPress sends email as before.

= What happens when Sendora refuses a message? =

`wp_mail()` returns false and fires `wp_mail_failed` with a `WP_Error` that says why, as it does when any other mailer fails. The message is not sent another way.

= How do I send a newsletter on the broadcast stream? =

Return `'broadcast'` from the filter `sendora_stream`, which receives the current choice and the `wp_mail()` arguments, or add the header `X-Sendora-Stream: broadcast` to the message. A broadcast must have `{{ unsubscribe_url }}` in its text and HTML parts: Sendora puts each recipient's own unsubscribe link there. One without it is refused, never sent on the transactional stream.

= Can I use it with The Newsletter Plugin, MailPoet or FluentCRM? =

The Newsletter Plugin and FluentCRM send through WordPress, so their newsletters go through Sendora once a few lines of code, in a small plugin of your own, put them on the broadcast stream and add Sendora's unsubscribe link. MailPoet sends with its own mailer, so its newsletters do not go through Sendora; its subscribers can still be kept in step. The recipes are in the plugin's documentation: [The Newsletter Plugin](https://github.com/jonathanelmgren/sendora-for-wp/blob/main/docs/recipes/newsletter.md), [FluentCRM](https://github.com/jonathanelmgren/sendora-for-wp/blob/main/docs/recipes/fluentcrm.md) and [MailPoet](https://github.com/jonathanelmgren/sendora-for-wp/blob/main/docs/recipes/mailpoet.md).

= How do I keep my newsletter's subscribers in step with bounces and unsubscribes? =

The actions `sendora_bounced`, `sendora_complained` and `sendora_unsubscribed` fire with the address, in lower case, and Sendora's report, once the address is on the suppression list. `sendora_event` fires for every verified report. The recipes show how to mark the subscriber in each newsletter plugin.

= Why does the webhook answer 503? =

Until a signing secret is saved, the webhook answers 503 and Sendora keeps retrying, so no report is lost while you set it up. It also answers 503 while a report cannot be stored yet; Sendora retries.

= Does it remove its data when I delete it? =

Yes. Deleting the plugin (not deactivating it) drops its suppression list and webhook tables and deletes its settings, on every site of a network. Sendora's own suppression list is not changed.

== Screenshots ==

1. Settings → Sendora: the API key, the streams, the From address and name, the webhook's signing secret, and the test email.
2. Tools → Sendora suppressions: the suppressed addresses with their reason and source, and the import of an address verifier's results.

== Changelog ==

= 0.2.0 =
* Renamed from Connector for Sendora to Sendora: the slug, text domain and folder are `sendora`, the main file `sendora.php`, the PHP namespace `SendoraWP`. Hooks, options and database tables now start with `sendora_`, the wp-config constants with `SENDORA_`, the REST route is `sendora/v1`, and the WP-CLI command is `wp sendora`.

= 0.1.0 =
* First release: sends `wp_mail()` through Sendora's API on a transactional or a broadcast stream, receives delivery reports on a signed webhook, keeps a suppression list, and imports an address verifier's results.

== Upgrade Notice ==

= 0.2.0 =
The plugin is now called Sendora. Hooks, options, constants, tables, the webhook address and the WP-CLI command are renamed; settings saved under the old names are not carried over.

= 0.1.0 =
First release.
