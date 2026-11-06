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

Settings saved on the page are kept in the option `connector_for_sendora_settings`. A saved API key is never shown again. A test server's key (`sk_test_…`) puts the plugin in test mode: Sendora accepts the mail and delivers none of it.

A failed send makes `wp_mail()` return false and fires `wp_mail_failed` with a `WP_Error` whose data adds `sendora_error_code` and `sendora_status` to the message's data.

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
