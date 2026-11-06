<?php
/**
 * Receives Sendora's delivery reports.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Webhook;

use SendoraWP\Database\QueryFailed;
use SendoraWP\Settings\Settings;
use SendoraWP\Suppression\SuppressionList;
use SendoraWP\Vendor\Sendora\Events\BouncedEvent;
use SendoraWP\Vendor\Sendora\Events\SpamComplaintEvent;
use SendoraWP\Vendor\Sendora\Events\UnsubscribedEvent;
use SendoraWP\Vendor\Sendora\Events\WebhookEvent;
use SendoraWP\Vendor\Sendora\Exception\WebhookVerificationException;
use SendoraWP\Vendor\Sendora\WebhookVerifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /wp-json/sendora-wp/v1/webhook`. Sendora's SDK checks
 * the signature and its age against the signing secret; without a secret
 * the route answers 503, so Sendora retries until one is set.
 *
 * A verified event fires `sendora_wp_event`. A hard bounce, a
 * complaint and an unsubscribe also put the address on the suppression
 * list and fire `sendora_wp_bounced`,
 * `sendora_wp_complained` or `sendora_wp_unsubscribed`.
 * A delivery already handled answers 200 and changes nothing. A delivery
 * another request is handling, or whose handling fails, answers 503 and is
 * handled on Sendora's retry.
 */
final class Webhook {

	/** The REST namespace. */
	public const NAMESPACE = 'sendora-wp/v1';

	/** The route, under the namespace. */
	public const ROUTE = '/webhook';

	/** The header Sendora signs a delivery in. */
	public const SIGNATURE_HEADER = 'Sendora-Signature';

	/** The events whose subscription the suppression list needs. */
	public const EVENTS = array( 'bounced', 'spam_complaint', 'unsubscribed' );

	/** Fired for every verified event. */
	public const EVENT_ACTION = 'sendora_wp_event';

	/** Fired for a hard bounce. */
	public const BOUNCED_ACTION = 'sendora_wp_bounced';

	/** Fired for a spam complaint. */
	public const COMPLAINED_ACTION = 'sendora_wp_complained';

	/** Fired for an unsubscribe. */
	public const UNSUBSCRIBED_ACTION = 'sendora_wp_unsubscribed';

	/** The answer without a signing secret, or while a delivery cannot be handled yet: Sendora retries. */
	private const STATUS_UNAVAILABLE = 503;

	/** The answer to a delivery whose signature does not hold. */
	private const STATUS_BAD_REQUEST = 400;

	/**
	 * A webhook.
	 *
	 * @param Settings        $settings    The signing secret.
	 * @param SuppressionList $suppression The list the events go into.
	 * @param DeliveryLog     $deliveries  The deliveries already handled.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SuppressionList $suppression,
		private readonly DeliveryLog $deliveries,
	) {
	}

	/** Hooks the route in. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/** Registers the route. */
	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive' ),
				// Sendora's signature is the authentication, checked in the callback.
				'permission_callback' => '__return_true',
			)
		);
	}

	/** The address to paste into Sendora. */
	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * Answers one delivery.
	 *
	 * @param WP_REST_Request $request The delivery.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( WP_REST_Request $request ) {
		$secret = $this->settings->get( Settings::WEBHOOK_SECRET );
		if ( '' === $secret ) {
			return new WP_Error(
				'sendora_wp_webhook_secret_missing',
				__( 'The webhook has no signing secret set.', 'sendora-for-wp' ),
				array( 'status' => self::STATUS_UNAVAILABLE )
			);
		}

		try {
			$event = WebhookVerifier::verify( $request->get_body(), $request->get_header( self::SIGNATURE_HEADER ), $secret );
		} catch ( WebhookVerificationException $e ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the SDK's property.
			return new WP_Error( 'sendora_wp_' . $e->errorCode, $e->getMessage(), array( 'status' => self::STATUS_BAD_REQUEST ) );
		}

		try {
			$claim = $this->deliveries->claim( $event->id );
		} catch ( QueryFailed ) {
			return self::unhandled();
		}
		if ( DeliveryLog::DONE === $claim ) {
			return new WP_REST_Response( array( 'duplicate' => true ) );
		}
		if ( DeliveryLog::IN_PROGRESS === $claim ) {
			return new WP_Error(
				'sendora_wp_delivery_in_progress',
				__( 'This delivery is being handled already.', 'sendora-for-wp' ),
				array( 'status' => self::STATUS_UNAVAILABLE )
			);
		}

		try {
			$this->handle( $event );
			$this->deliveries->complete( $event->id );
		} catch ( \Throwable ) {
			// Not handled after all: Sendora's retry gets another go.
			$this->deliveries->release( $event->id );
			return self::unhandled();
		}
		return new WP_REST_Response( array( 'duplicate' => false ) );
	}

	/** The answer to a delivery that could not be handled, which Sendora retries. */
	private static function unhandled(): WP_Error {
		return new WP_Error(
			'sendora_wp_delivery_failed',
			__( 'The delivery could not be handled.', 'sendora-for-wp' ),
			array( 'status' => self::STATUS_UNAVAILABLE )
		);
	}

	/**
	 * Puts what the event says into the suppression list, and fires the
	 * hooks.
	 *
	 * @param WebhookEvent $event A verified event.
	 */
	public function handle( WebhookEvent $event ): void {
		$payload = $event->toArray();

		// The address and the hook for it, once it is on the list; null for an event that suppresses nothing.
		$suppressed = null;
		if ( $event instanceof BouncedEvent && $event->details->hard ) {
			$suppressed = array( $this->suppress( $event, SuppressionList::BOUNCED ), self::BOUNCED_ACTION );
		} elseif ( $event instanceof SpamComplaintEvent ) {
			$suppressed = array( $this->suppress( $event, SuppressionList::COMPLAINED ), self::COMPLAINED_ACTION );
		} elseif ( $event instanceof UnsubscribedEvent ) {
			$suppressed = array( $this->suppress( $event, SuppressionList::UNSUBSCRIBED ), self::UNSUBSCRIBED_ACTION );
		}

		/**
		 * Fires for every verified event Sendora delivers, after the
		 * suppression list has taken it in.
		 *
		 * @param string               $type    The event's name, such as `'delivered'` or `'bounced'`.
		 * @param array<string, mixed> $payload The event as Sendora sent it.
		 */
		do_action( self::EVENT_ACTION, $event->event, $payload ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is sendora_wp_event.

		if ( null !== $suppressed ) {
			[ $email, $action ] = $suppressed;
			/**
			 * Fires for a hard bounce (`sendora_wp_bounced`), a spam
			 * complaint (`sendora_wp_complained`) or an unsubscribe
			 * (`sendora_wp_unsubscribed`), once the address is on
			 * the suppression list.
			 *
			 * @param string               $email   The address, in lower case.
			 * @param array<string, mixed> $payload The event as Sendora sent it.
			 */
			do_action( $action, $email, $payload ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constants are prefixed sendora_wp_.
		}
	}

	/**
	 * Puts the event's recipient on the suppression list.
	 *
	 * @param BouncedEvent|SpamComplaintEvent|UnsubscribedEvent $event  The event.
	 * @param string                                            $reason Why.
	 * @return string The address, as the list keeps it.
	 */
	private function suppress( BouncedEvent|SpamComplaintEvent|UnsubscribedEvent $event, string $reason ): string {
		$email = SuppressionList::normalise( $event->recipient );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the SDK's properties.
		$this->suppression->add( $email, $reason, SuppressionList::SOURCE_WEBHOOK, $event->streamId, $event->occurredAt );
		return $email;
	}
}
