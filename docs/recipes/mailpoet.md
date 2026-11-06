# MailPoet

Written against MailPoet 5.45.

## MailPoet's newsletters do not go through Sendora

MailPoet never calls `wp_mail()`. Each of its sending methods (MailPoet's
Sending Service, your web host, SMTP, Amazon SES, SendGrid) sends with MailPoet's
own mailer, and no filter makes it use `wp_mail()`. The Sendora plugin
therefore cannot send MailPoet's newsletters, or its confirmation and welcome
emails, and there is no unsubscribe link to set up for them.

Under **MailPoet → Settings → Send With…**, turn off sending WordPress's own
emails with MailPoet: with the Sendora plugin active, WordPress's emails go
through Sendora and that setting does nothing.

## Keeping subscribers in step

If the same addresses also get mail from the site through Sendora, an
unsubscribe or a spam complaint Sendora reports can unsubscribe them in
MailPoet too. Put the code in a small plugin of your own (for instance
`wp-content/mu-plugins/sendora-mailpoet.php`). MailPoet has no status for a
complaint, so a complaint unsubscribes.

```php
function my_site_mailpoet_unsubscribe( string $email ): void {
	if ( ! class_exists( \MailPoet\API\API::class ) ) {
		return;
	}
	try {
		\MailPoet\API\API::MP( 'v1' )->unsubscribe( $email );
	} catch ( \MailPoet\API\MP\v1\APIException $e ) {
		// Not a subscriber, or unsubscribed already.
	}
}

add_action( 'sendora_unsubscribed', 'my_site_mailpoet_unsubscribe' );
add_action( 'sendora_complained', 'my_site_mailpoet_unsubscribe' );
```

MailPoet's public API cannot mark a subscriber as bounced, so a hard bounce
Sendora reports stays on the plugin's suppression list only.
