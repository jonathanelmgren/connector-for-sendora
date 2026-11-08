# Connector for Sendora

A WordPress plugin that sends the site's email through [Sendora](https://sendora.se)'s API instead of PHP `mail()` or SMTP.

Once an API key is set under **Settings → Connector for Sendora**, `wp_mail()` sends every message through Sendora on the transactional stream. Without a key, WordPress sends email as it would without the plugin.

## Configuration

Each setting can be saved on the settings page, or set in `wp-config.php`, which wins and shows the field read-only:

| Setting | Constant |
|---|---|
| API key | `SENDORA_WP_API_KEY` |
| Transactional stream id (empty: the server's default) | `SENDORA_WP_TRANSACTIONAL_STREAM` |
| Broadcast stream id | `SENDORA_WP_BROADCAST_STREAM` |
| Default From address | `SENDORA_WP_FROM_EMAIL` |
| Default From name | `SENDORA_WP_FROM_NAME` |
| Webhook signing secret | `SENDORA_WP_WEBHOOK_SECRET` |

Settings saved on the page are kept in the option `sendora_wp_settings`. A saved API key or signing secret is never shown again. A test server's key (`sk_test_…`) puts the plugin in test mode: Sendora accepts the mail and delivers none of it.

A sent message fires `wp_mail_succeeded` with the message's data and `sendora_wp_message_ids`, the ids Sendora gave it: one, or one per recipient for a broadcast or a message with only Bcc recipients, which go to each recipient alone.

A failed send makes `wp_mail()` return false and fires `wp_mail_failed` with a `WP_Error` whose data adds `sendora_wp_error_code` and `sendora_wp_status` to the message's data. A message sent as several that fails part way also adds `sendora_wp_message_ids` and `sendora_wp_sent_to`: the ids and addresses of the sends that went out before the failure.

### Transactional mail and broadcasts

Every message goes on the transactional stream unless something asks for the broadcast stream:

- the filter `sendora_wp_stream` returns `'broadcast'`. It receives the current choice, `'transactional'` or `'broadcast'`, and the full `wp_mail()` arguments, so a newsletter plugin can recognise its own messages;
- the message carries the header `X-Sendora-Stream: broadcast`, for plugins that can only add headers. The header is never sent.

Any other value means transactional. Sendora's rules for a broadcast are enforced before it is sent:

- The filter `sendora_wp_broadcast_message` gets the `SendoraWP\Mail\Message` and the `wp_mail()` arguments first, and returns the message to send, for instance with `{{ unsubscribe_url }}` in place of its own unsubscribe link (`$message->with_text()`, `$message->with_html()`). The message stays on the broadcast stream whatever the returned one says.
- An HTML-only message gets a text part written from its HTML, in which a link keeps its address: `<a href="{{ unsubscribe_url }}">Unsubscribe</a>` becomes `Unsubscribe ({{ unsubscribe_url }})`.
- A text or HTML part without `{{ unsubscribe_url }}` is refused. Sendora puts each recipient's own unsubscribe link there.
- `List-Unsubscribe` and `List-Unsubscribe-Post` headers are removed: Sendora sets them.
- The message goes to each of its To, Cc and Bcc recipients alone, so each gets their own link.

A refused broadcast, or one on a site with no broadcast stream set, is never sent on the transactional stream instead: `wp_mail()` returns false and fires `wp_mail_failed` with a `WP_Error` that says which rule failed, and whose `sendora_wp_error_code` is `unsubscribe_url_missing` or `broadcast_stream_missing`.

### Delivery reports and the suppression list

Sendora reports bounces, complaints and unsubscribes to the webhook `POST /wp-json/sendora-wp/v1/webhook`. Create a webhook with that address in Sendora's dashboard, subscribe it to `bounced`, `spam_complaint` and `unsubscribed`, and save its signing secret on the settings page, which shows the address too. Without a secret the webhook answers 503, so Sendora keeps retrying. A delivery whose signature is wrong, missing or older than five minutes is answered 400. Sendora delivers at least once: a delivery already handled is answered 200 and changes nothing. A delivery another request is handling is answered 503 (`sendora_wp_delivery_in_progress`), and one whose handling fails, or that the database refuses, is answered 503 (`sendora_wp_delivery_failed`) and left unhandled; Sendora retries both. A handling that never finished, because PHP stopped on the way, is taken over by a retry after five minutes.

Each address that bounced for good, complained or unsubscribed goes on a suppression list in its own table (`{prefix}sendora_wp_suppressions`), in lower case, with its reason (`bounced`, `complained`, `unsubscribed`), its source (`webhook`, `import`, `manual`), the Sendora stream the event came from, and when.

- A hard bounce stops all mail to the address. A complaint or an unsubscribe stops broadcasts only, so a password reset still arrives. A soft bounce stops nothing.
- A send leaves suppressed recipients out. The data of `wp_mail_succeeded` and `wp_mail_failed` lists them in `sendora_wp_suppressed`, address to reason. When every recipient is left out, `wp_mail()` returns false and fires `wp_mail_failed` with a `WP_Error` that names them and whose `sendora_wp_error_code` is `recipient_suppressed`.
- The filter `sendora_wp_is_suppressed` has the last word. It receives whether the list skips the address, the address in lower case, the stream (`'transactional'` or `'broadcast'`) and the reason or null, and returns true to skip it.
- **Tools → Connector for Sendora suppressions** lists the addresses, with a search, removes one, and imports an address verifier's results (below). Sendora keeps a suppression list of its own, which this does not change: only Sendora's support can take an address off it after a complaint or an unsubscribe.

### Cleaning a list before the first send

Sendora requires hard bounces to stay below 5 % of the messages sent, and an old list often fails that on the first send. Run the list through an address verifier, then import its results: each address whose status suppresses goes on the suppression list as `bounced`, source `import`. An address already on the list as a hard bounce is left as it is, so importing the same file again changes nothing.

```sh
wp sendora-wp import results.csv           # dry run: counts only, writes nothing
wp sendora-wp import results.csv --apply   # writes
```

The file is CSV with a header row, separated by commas, semicolons or tabs; a UTF-8 byte order mark is fine. Options:

| Option | Default | |
|---|---|---|
| `--email-column=<name>` | `email` | The column with the address. |
| `--status-column=<name>` | `status` | The column with the verifier's status. |
| `--statuses=<list>` | `undeliverable,invalid` | The statuses that suppress, comma-separated. |
| `--verbose` | | Lists the addresses and the skipped rows as well. |

Column names and statuses are matched whatever their case. The output gives counts only: rows read, addresses suppressed (or that would be), addresses already on the list, and rows skipped with why (a status that does not suppress, no address, an address that is not valid, an address repeated in the file).

The same import is on **Tools → Connector for Sendora suppressions**, for a CSV file of up to 10 MB (or PHP's upload limit, if lower): the upload is read as a dry run and deleted, and nothing is written until the import is confirmed.

Options for known verifiers' exports:

| Verifier | Options |
|---|---|
| [Bouncer](https://www.usebouncer.com/) | none: its `email` and `status` columns and its `undeliverable` status are the defaults. |
| [ZeroBounce](https://www.zerobounce.net/) | `--email-column="<your address column>" --status-column="ZB Status" --statuses=invalid`. ZeroBounce adds its columns to your own file, so the address column keeps the name it had. Add `spamtrap,abuse,do not mail` to suppress those too. |
| [NeverBounce](https://neverbounce.com/) | `--status-column=<its result column> --statuses=invalid`. NeverBounce's result is one of `valid`, `invalid`, `disposable`, `catchall` and `unknown`; name the column it added, as your export's header row shows it. |

For other plugins, such as a newsletter keeping its subscribers in step:

- `sendora_wp_event` fires for every verified event, with its type (`'delivered'`, `'bounced'`, …) and its payload as Sendora sent it;
- `sendora_wp_bounced` (hard bounces only), `sendora_wp_complained` and `sendora_wp_unsubscribed` fire with the address in lower case and the payload, once the address is on the list.

### Newsletter plugins

The plugin ships no code for a newsletter plugin. Recipes show how to send a newsletter plugin's messages on the broadcast stream, put Sendora's unsubscribe link in its template, and keep its subscribers in step through the actions above: [The Newsletter Plugin](docs/recipes/newsletter.md), [FluentCRM](docs/recipes/fluentcrm.md) and [MailPoet](docs/recipes/mailpoet.md) (whose newsletters never go through `wp_mail()`).

### Uninstalling

Deleting the plugin (not deactivating it) drops its two tables and deletes its settings, its schema version and its transients, on every site of a network.

### Other filters

The filter `sendora_wp_transport` can return a `SendoraWP\Mail\Transport` to send through something other than Sendora's API.

## Development

The host needs Node.js and Docker only: Composer, PHPCS and PHPStan run in a throwaway `composer:2` container, and PHPUnit and Plugin Check in [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env)'s containers.

```sh
npm install
npm run composer -- install   # PHP dependencies; prefixes Sendora's SDK under SendoraWP\Vendor
npm run env:start             # WordPress at WP_ENV_PORT (8888), its tests site at WP_ENV_TESTS_PORT (8889)
npm run check                 # the gate: lint, stan, test, build, plugin-check on the zip
npm run env:stop
```

Single steps: `npm run lint` (PHPCS with WordPress Coding Standards; `npm run format` fixes what it can), `npm run stan` (PHPStan), `npm test` (PHPUnit; `npm test -- --filter MailerTest` for one test class), `npm run plugin-check` (Plugin Check on `build/connector-for-sendora.zip`; run `npm run build` first).

Set `WP_ENV_PORT` and `WP_ENV_TESTS_PORT` to run several checkouts at once.

### Building and releasing

```sh
npm run make-pot              # languages/connector-for-sendora.pot from the working tree
npm run build                 # build/connector-for-sendora/ and build/connector-for-sendora.zip
node bin/build.mjs v1.2.3     # the same, refusing unless the plugin's files say 1.2.3
```

The build holds the files git knows less those in `.distignore`, production packages only with Sendora's SDK and its dependencies prefixed under `SendoraWP\Vendor`, and the translation template. It refuses to build when the version in the plugin header, `SENDORA_WP_VERSION`, `readme.txt`'s stable tag and `package.json` differ. CI builds the zip and the template on every pull request.

To release, raise the version in those four places and add a changelog entry to `readme.txt`, merge, then push a tag `v<version>`. The release workflow builds the zip and attaches it to a GitHub release. It also deploys to the wordpress.org plugin directory when the repository variable `WPORG_DEPLOY` is `true` and the secrets `SVN_USERNAME` and `SVN_PASSWORD` are set (in the `wordpress.org` environment or the repository); banners, icons and screenshots for the directory go in `.wordpress-org/`.

### No test reaches Sendora

Tests and local sites never use a Sendora key or Sendora's API:

- Every script in `bin/` refuses to run with `SENDORA_WP_API_KEY`, `SENDORA_API_KEY`, `SENDORA_API_TOKEN`, `SENDORA_BASE_URL` or `SENDORA_API_URL` set, and so does the PHPUnit bootstrap inside the container, which also refuses a defined `SENDORA_WP_API_KEY` constant.
- The SDK sends through WordPress's HTTP API, and the PHPUnit bootstrap refuses every request to a host other than this machine before it leaves. Tests send through an in-memory fake, or answer the SDK's requests themselves.
- On the wp-env sites, a must-use plugin (`tests/mu-plugins/`) swaps in a fake that keeps the last messages in the option `sendora_wp_dev_outbox` (`npx wp-env run cli wp option get sendora_wp_dev_outbox --format=json`), and refuses every request to Sendora's hosts.
