# FluentCRM

Written against FluentCRM 3.3. Put the code in a small plugin of your own (for
instance `wp-content/mu-plugins/sendora-fluentcrm.php`), not in Sendora for
WordPress.

FluentCRM sends every email through `wp_mail()`, one per contact, so
Sendora sends its campaigns, automations and sequences, and each
contact gets their own unsubscribe link.

## Campaigns on the broadcast stream

The filter `fluent_crm/email_headers` gets the queued campaign email, if there
is one. Double opt-in emails, test sends and the manage-subscription emails have
none, and stay transactional; so does a campaign marked transactional in
FluentCRM.

```php
add_filter(
	'fluent_crm/email_headers',
	static function ( array $headers, $data, $subscriber, $email_model ): array {
		if ( ! $email_model ) {
			return $headers;
		}
		$campaign = $email_model->campaign;
		if ( $campaign && 'yes' === \FluentCrm\Framework\Support\Arr::get( $campaign->settings, 'is_transactional' ) ) {
			return $headers;
		}
		$headers[] = 'X-Sendora-Stream: broadcast';
		return $headers;
	},
	10,
	4
);
```

This sends automation and sequence emails on the broadcast stream as well, so
each needs an unsubscribe link.

## Sendora's unsubscribe link

A broadcast must contain `{{ unsubscribe_url }}`, where Sendora puts each
recipient's own unsubscribe link. FluentCRM leaves that placeholder alone, so
it can go straight into a template, for instance
`<a href="{{ unsubscribe_url }}">Unsubscribe</a>` in a raw HTML template.

To keep FluentCRM's own footer and its `{{crm.unsubscribe_url}}` smartcode
instead, swap the link FluentCRM renders for Sendora's placeholder before the
broadcast is sent:

```php
/** FluentCRM's unsubscribe link: `?fluentcrm=1&route=unsubscribe&…` on the site's address. */
const MY_SITE_FLUENTCRM_UNSUBSCRIBE_URL = '#https?://[^\s"\'<>]*[?&](?:amp;)?fluentcrm=1[^\s"\'<>]*[?&;]route=unsubscribe[^\s"\'<>]*#';

add_filter(
	'sendora_wp_broadcast_message',
	static function ( $message ) {
		if ( null !== $message->html ) {
			$message = $message->with_html( preg_replace( MY_SITE_FLUENTCRM_UNSUBSCRIBE_URL, '{{ unsubscribe_url }}', $message->html ) );
		}
		if ( null !== $message->text ) {
			$message = $message->with_text( preg_replace( MY_SITE_FLUENTCRM_UNSUBSCRIBE_URL, '{{ unsubscribe_url }}', $message->text ) );
		}
		return $message;
	}
);
```

FluentCRM sends HTML only; Connector for Sendora writes the text part from it,
link included.

## Keeping contacts in step

When Sendora reports a hard bounce, a spam complaint or an unsubscribe, set the
contact's status so FluentCRM stops sending to them too. A contact already
unsubscribed, complained or marked as spam keeps that status.

```php
/** The statuses a report from Sendora does not overwrite. */
const MY_SITE_FLUENTCRM_FINAL_STATUSES = array( 'unsubscribed', 'complained', 'spammed' );

function my_site_fluentcrm_set_status( string $status ): callable {
	return static function ( string $email ) use ( $status ): void {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return;
		}
		$contact = FluentCrmApi( 'contacts' )->getContact( $email );
		if ( $contact && ! in_array( $contact->status, MY_SITE_FLUENTCRM_FINAL_STATUSES, true ) ) {
			$contact->updateStatus( $status );
		}
	};
}

add_action( 'sendora_wp_unsubscribed', my_site_fluentcrm_set_status( 'unsubscribed' ) );
add_action( 'sendora_wp_bounced', my_site_fluentcrm_set_status( 'bounced' ) );
add_action( 'sendora_wp_complained', my_site_fluentcrm_set_status( 'complained' ) );
```

The address arrives in lower case; FluentCRM looks a contact up by the address
exactly as stored.
