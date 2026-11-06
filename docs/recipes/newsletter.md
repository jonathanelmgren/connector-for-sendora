# The Newsletter Plugin

Written against The Newsletter Plugin 9.5. Put the code in a small plugin of
your own (for instance `wp-content/mu-plugins/sendora-newsletter.php`), not in
Connector for Sendora.

The Newsletter Plugin sends through `wp_mail()` with its default mailer, so
Connector for Sendora sends its messages. A delivery add-on that registers its
own mailer (SMTP, Amazon SES and others) skips `wp_mail()`; turn it off.

Each message goes to one subscriber, so each gets their own unsubscribe link.

## Newsletters on the broadcast stream

Every message The Newsletter Plugin builds for a subscriber carries the same
headers (`Precedence: bulk`, `List-Unsubscribe`, `X-Newsletter-Email-Id`),
welcome and confirmation emails included, so the headers cannot tell a
newsletter from a confirmation. The email's type can: `welcome` and
`confirmation` are transactional, everything else is a newsletter. The filter
`newsletter_message_headers` gets the email, so it can add the header that asks
for the broadcast stream:

```php
/** The Newsletter Plugin's email types that are not newsletters. */
const MY_SITE_TNP_TRANSACTIONAL_TYPES = array( 'welcome', 'confirmation' );

add_filter(
	'newsletter_message_headers',
	static function ( array $headers, $email ): array {
		if ( ! in_array( $email->type ?? '', MY_SITE_TNP_TRANSACTIONAL_TYPES, true ) ) {
			$headers['X-Sendora-Stream'] = 'broadcast';
		}
		return $headers;
	},
	10,
	2
);
```

Connector for Sendora removes the header before the message is sent, and
removes The Newsletter Plugin's `List-Unsubscribe` headers too: Sendora sets
its own.

## Sendora's unsubscribe link

A broadcast must contain `{{ unsubscribe_url }}`, where Sendora puts each
recipient's own unsubscribe link. The Newsletter Plugin's templates use its own
tags, `{unsubscription_url}`, `{unsubscribe_url}` and
`{unsubscription_confirm_url}`. Swap them for Sendora's placeholder while a
newsletter is built, before The Newsletter Plugin replaces them (it does at
priority 10, after which its click tracking has rewritten the link):

```php
/** Whether The Newsletter Plugin is building a message for a subscriber. */
function my_site_tnp_building( ?bool $set = null ): bool {
	static $building = false;
	if ( null !== $set ) {
		$building = $set;
	}
	return $building;
}

add_filter( 'newsletter_send_user', static function ( $user ) { my_site_tnp_building( true ); return $user; } );
add_filter( 'newsletter_message', static function ( $message ) { my_site_tnp_building( false ); return $message; }, PHP_INT_MAX );

add_filter(
	'newsletter_replace',
	static function ( $text, $user, $email ) {
		if ( ! my_site_tnp_building() || ! $email || in_array( $email->type ?? '', MY_SITE_TNP_TRANSACTIONAL_TYPES, true ) ) {
			return $text;
		}
		foreach ( array( 'unsubscription_confirm_url', 'unsubscription_url', 'unsubscribe_url' ) as $tag ) {
			$text = str_replace(
				array( 'http://{' . $tag . '}', 'https://{' . $tag . '}', trailingslashit( home_url() ) . '{' . $tag . '}', '%7B' . $tag . '%7D', '{' . $tag . '}' ),
				'{{ unsubscribe_url }}',
				$text
			);
		}
		return $text;
	},
	9,
	3
);
```

Write the placeholder with its spaces if you put it in a template yourself:
The Newsletter Plugin replaces `{unsubscribe_url}` inside `{{unsubscribe_url}}`,
but leaves `{{ unsubscribe_url }}` alone.

A newsletter without an unsubscribe link is refused, and The Newsletter
Plugin's log shows `wp_mail()` failing for it.

## Keeping subscribers in step

When Sendora reports a hard bounce, a spam complaint or an unsubscribe, mark the
subscriber so The Newsletter Plugin stops sending to them too. Use
`set_user_status()`: The Newsletter Plugin's own unsubscribe functions also
email the subscriber and the site's admin.

```php
function my_site_tnp_set_status( string $status ): callable {
	return static function ( string $email ) use ( $status ): void {
		if ( ! class_exists( 'Newsletter' ) ) {
			return;
		}
		$newsletter = Newsletter::instance();
		$user       = $newsletter->get_user( $email );
		if ( $user && $user->status !== $status ) {
			$newsletter->set_user_status( $user, $status );
			$newsletter->add_user_log( $user, 'sendora' );
		}
	};
}

add_action( 'connector_for_sendora_unsubscribed', my_site_tnp_set_status( TNP_User::STATUS_UNSUBSCRIBED ) );
add_action( 'connector_for_sendora_bounced', my_site_tnp_set_status( TNP_User::STATUS_BOUNCED ) );
add_action( 'connector_for_sendora_complained', my_site_tnp_set_status( TNP_User::STATUS_COMPLAINED ) );
```
