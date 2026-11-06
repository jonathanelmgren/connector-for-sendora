# Connector for Sendora

A WordPress plugin that sends the site's email through [Sendora](https://sendora.se)'s API instead of PHP `mail()` or SMTP.

Once an API key is set under **Settings → Sendora**, `wp_mail()` sends every message through Sendora on the transactional stream. Without a key, WordPress sends email as it would without the plugin.

## Configuration

Each setting can be saved on the settings page, or set in `wp-config.php`, which wins and shows the field read-only:

| Setting | Constant |
|---|---|
| API key | `CONNECTOR_FOR_SENDORA_API_KEY` |
| Transactional stream id (empty: the server's default) | `CONNECTOR_FOR_SENDORA_TRANSACTIONAL_STREAM` |
| Broadcast stream id | `CONNECTOR_FOR_SENDORA_BROADCAST_STREAM` |
| Default From address | `CONNECTOR_FOR_SENDORA_FROM_EMAIL` |
| Default From name | `CONNECTOR_FOR_SENDORA_FROM_NAME` |
| Webhook signing secret | `CONNECTOR_FOR_SENDORA_WEBHOOK_SECRET` |

Settings saved on the page are kept in the option `connector_for_sendora_settings`. A saved API key or signing secret is never shown again. A test server's key (`sk_test_…`) puts the plugin in test mode: Sendora accepts the mail and delivers none of it.

A sent message fires `wp_mail_succeeded` with the message's data and `sendora_message_ids`, the ids Sendora gave it: one, or one per recipient for a broadcast or a message with only Bcc recipients, which go to each recipient alone.

A failed send makes `wp_mail()` return false and fires `wp_mail_failed` with a `WP_Error` whose data adds `sendora_error_code` and `sendora_status` to the message's data. A message sent as several that fails part way also adds `sendora_message_ids` and `sendora_sent_to`: the ids and addresses of the sends that went out before the failure.

### Transactional mail and broadcasts

Every message goes on the transactional stream unless something asks for the broadcast stream:

- the filter `connector_for_sendora_stream` returns `'broadcast'`. It receives the current choice, `'transactional'` or `'broadcast'`, and the full `wp_mail()` arguments, so a newsletter plugin can recognise its own messages;
- the message carries the header `X-Sendora-Stream: broadcast`, for plugins that can only add headers. The header is never sent.

Any other value means transactional. Sendora's rules for a broadcast are enforced before it is sent:

- The filter `connector_for_sendora_broadcast_message` gets the `ConnectorForSendora\Mail\Message` and the `wp_mail()` arguments first, and returns the message to send, for instance with `{{ unsubscribe_url }}` in place of its own unsubscribe link (`$message->with_text()`, `$message->with_html()`). The message stays on the broadcast stream whatever the returned one says.
- An HTML-only message gets a text part written from its HTML, in which a link keeps its address: `<a href="{{ unsubscribe_url }}">Unsubscribe</a>` becomes `Unsubscribe ({{ unsubscribe_url }})`.
- A text or HTML part without `{{ unsubscribe_url }}` is refused. Sendora puts each recipient's own unsubscribe link there.
- `List-Unsubscribe` and `List-Unsubscribe-Post` headers are removed: Sendora sets them.
- The message goes to each of its To, Cc and Bcc recipients alone, so each gets their own link.

A refused broadcast, or one on a site with no broadcast stream set, is never sent on the transactional stream instead: `wp_mail()` returns false and fires `wp_mail_failed` with a `WP_Error` that says which rule failed, and whose `sendora_error_code` is `unsubscribe_url_missing` or `broadcast_stream_missing`.

### Delivery reports and the suppression list

Sendora reports bounces, complaints and unsubscribes to the webhook `POST /wp-json/connector-for-sendora/v1/webhook`. Create a webhook with that address in Sendora's dashboard, subscribe it to `bounced`, `spam_complaint` and `unsubscribed`, and save its signing secret on the settings page, which shows the address too. Without a secret the webhook answers 503, so Sendora keeps retrying. A delivery whose signature is wrong, missing or older than five minutes is answered 400. Sendora delivers at least once: a delivery already handled is answered 200 and changes nothing. A delivery another request is handling is answered 503 (`connector_for_sendora_delivery_in_progress`), and one whose handling fails, or that the database refuses, is answered 503 (`connector_for_sendora_delivery_failed`) and left unhandled; Sendora retries both. A handling that never finished, because PHP stopped on the way, is taken over by a retry after five minutes.

Each address that bounced for good, complained or unsubscribed goes on a suppression list in its own table (`{prefix}connector_for_sendora_suppressions`), in lower case, with its reason (`bounced`, `complained`, `unsubscribed`), its source (`webhook`, `import`, `manual`), the Sendora stream the event came from, and when.

- A hard bounce stops all mail to the address. A complaint or an unsubscribe stops broadcasts only, so a password reset still arrives. A soft bounce stops nothing.
- A send leaves suppressed recipients out. The data of `wp_mail_succeeded` and `wp_mail_failed` lists them in `sendora_suppressed`, address to reason. When every recipient is left out, `wp_mail()` returns false and fires `wp_mail_failed` with a `WP_Error` that names them and whose `sendora_error_code` is `recipient_suppressed`.
- The filter `connector_for_sendora_is_suppressed` has the last word. It receives whether the list skips the address, the address in lower case, the stream (`'transactional'` or `'broadcast'`) and the reason or null, and returns true to skip it.
- **Tools → Sendora suppressions** lists the addresses, with a search, and removes one. Sendora keeps a suppression list of its own, which this does not change: only Sendora's support can take an address off it after a complaint or an unsubscribe.

For other plugins, such as a newsletter keeping its subscribers in step:

- `connector_for_sendora_event` fires for every verified event, with its type (`'delivered'`, `'bounced'`, …) and its payload as Sendora sent it;
- `connector_for_sendora_bounced` (hard bounces only), `connector_for_sendora_complained` and `connector_for_sendora_unsubscribed` fire with the address in lower case and the payload, once the address is on the list.

### Other filters

The filter `connector_for_sendora_transport` can return a `ConnectorForSendora\Mail\Transport` to send through something other than Sendora's API.

## Development

The host needs Node.js and Docker only: Composer, PHPCS and PHPStan run in a throwaway `composer:2` container, and PHPUnit and Plugin Check in [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env)'s containers.

```sh
npm install
npm run composer -- install   # PHP dependencies; prefixes Sendora's SDK under ConnectorForSendora\Vendor
npm run env:start             # WordPress at WP_ENV_PORT (8888), its tests site at WP_ENV_TESTS_PORT (8889)
npm run check                 # the gate: lint, stan, test, plugin-check
npm run env:stop
```

Single steps: `npm run lint` (PHPCS with WordPress Coding Standards; `npm run format` fixes what it can), `npm run stan` (PHPStan), `npm test` (PHPUnit; `npm test -- --filter MailerTest` for one test class), `npm run plugin-check`.

Set `WP_ENV_PORT` and `WP_ENV_TESTS_PORT` to run several checkouts at once.

### No test reaches Sendora

Tests and local sites never use a Sendora key or Sendora's API:

- Every script in `bin/` refuses to run with `CONNECTOR_FOR_SENDORA_API_KEY`, `SENDORA_API_TOKEN`, `SENDORA_BASE_URL`, `SENDORA_API_KEY` or `SENDORA_API_URL` set, and so does the PHPUnit bootstrap inside the container, which also refuses a defined `CONNECTOR_FOR_SENDORA_API_KEY` constant.
- The SDK sends through WordPress's HTTP API, and the PHPUnit bootstrap refuses every request to a host other than this machine before it leaves. Tests send through an in-memory fake, or answer the SDK's requests themselves.
- On the wp-env sites, a must-use plugin (`tests/mu-plugins/`) swaps in a fake that keeps the last messages in the option `connector_for_sendora_dev_outbox` (`npx wp-env run cli wp option get connector_for_sendora_dev_outbox --format=json`), and refuses every request to Sendora's hosts.
