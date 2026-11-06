<?php
/**
 * Tests for the delivery report webhook.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Integration;

use ConnectorForSendora\Settings\Settings;
use ConnectorForSendora\Suppression\SuppressionList;
use ConnectorForSendora\Tests\Support\WebhookDeliveries;
use ConnectorForSendora\Webhook\DeliveryLog;
use ConnectorForSendora\Webhook\Webhook;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Sends signed deliveries through the REST server, as Sendora would.
 */
final class WebhookTest extends TestCase {

	private const STATUS_OK = 200;

	private const STATUS_BAD_REQUEST = 400;

	private const STATUS_UNAVAILABLE = 503;

	/** Older than the SDK's five minutes of tolerance. */
	private const STALE_SECONDS = 600;

	/** How the plugin's tables write a time. */
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * The filter that turns writes to a table into SQL the database
	 * refuses; null when none is set.
	 *
	 * @var \Closure(string): string|null
	 */
	private ?\Closure $break_writes = null;

	/**
	 * Whether the database's errors were suppressed before writes were
	 * broken.
	 *
	 * @var bool
	 */
	private bool $suppressed_errors = false;

	private SuppressionList $list;

	public function set_up(): void {
		parent::set_up();
		$this->configure( array( Settings::WEBHOOK_SECRET => WebhookDeliveries::SECRET ) );
		$this->list = new SuppressionList();
	}

	public function tear_down(): void {
		$this->restore_writes();
		parent::tear_down();
	}

	public function test_without_a_secret_the_webhook_answers_503(): void {
		$this->configure();

		$response = $this->dispatch( WebhookDeliveries::request( WebhookDeliveries::bounced( 'anna@example.com' ) ) );

		$this->assertSame( self::STATUS_UNAVAILABLE, $response->get_status() );
		$this->assertNull( $this->list->reason( 'anna@example.com', 'transactional' ) );
	}

	public function test_the_secret_can_come_from_a_constant(): void {
		$this->configure();
		$webhook = new Webhook( self::with_constants( array( 'CONNECTOR_FOR_SENDORA_WEBHOOK_SECRET' => WebhookDeliveries::SECRET ) ), $this->list, new DeliveryLog() );

		$response = self::answer( $webhook, WebhookDeliveries::request( WebhookDeliveries::bounced( 'anna@example.com' ) ) );

		$this->assertSame( self::STATUS_OK, $response->get_status() );
	}

	public function test_a_wrong_signature_answers_400_and_changes_nothing(): void {
		$response = $this->dispatch( WebhookDeliveries::request( WebhookDeliveries::bounced( 'anna@example.com' ), 'whsec_another_secret' ) );

		$this->assertSame( self::STATUS_BAD_REQUEST, $response->get_status() );
		$this->assertNull( $this->list->reason( 'anna@example.com', 'transactional' ) );
	}

	public function test_a_missing_signature_answers_400(): void {
		$response = $this->dispatch( WebhookDeliveries::unsigned( (string) wp_json_encode( WebhookDeliveries::bounced( 'anna@example.com' ) ) ) );

		$this->assertSame( self::STATUS_BAD_REQUEST, $response->get_status() );
	}

	public function test_a_stale_signature_answers_400(): void {
		$response = $this->dispatch( WebhookDeliveries::request( WebhookDeliveries::bounced( 'anna@example.com' ), WebhookDeliveries::SECRET, time() - self::STALE_SECONDS ) );

		$this->assertSame( self::STATUS_BAD_REQUEST, $response->get_status() );
		$this->assertNull( $this->list->reason( 'anna@example.com', 'transactional' ) );
	}

	public function test_a_hard_bounce_suppresses_the_address_on_both_streams(): void {
		$response = $this->dispatch( WebhookDeliveries::request( WebhookDeliveries::bounced( 'Anna@Example.com' ) ) );

		$this->assertSame( self::STATUS_OK, $response->get_status() );
		$this->assertSame( SuppressionList::BOUNCED, $this->list->reason( 'anna@example.com', 'transactional' ) );
		$this->assertSame( SuppressionList::BOUNCED, $this->list->reason( 'anna@example.com', 'broadcast' ) );

		$row = $this->list->find( 'anna@example.com', 1, 0 )[0];
		$this->assertSame( 'anna@example.com', $row['email'] );
		$this->assertSame( SuppressionList::SOURCE_WEBHOOK, $row['source'] );
		$this->assertSame( WebhookDeliveries::STREAM_ID, $row['stream_id'] );
		$this->assertSame( '2026-11-01 10:00:00', $row['suppressed_at'] );
	}

	public function test_a_soft_bounce_suppresses_nothing(): void {
		$bounced = array();
		add_action(
			Webhook::BOUNCED_ACTION,
			static function ( string $email ) use ( &$bounced ): void {
				$bounced[] = $email;
			}
		);

		$response = $this->dispatch( WebhookDeliveries::request( WebhookDeliveries::bounced( 'anna@example.com', false ) ) );

		$this->assertSame( self::STATUS_OK, $response->get_status() );
		$this->assertNull( $this->list->reason( 'anna@example.com', 'broadcast' ) );
		$this->assertSame( array(), $bounced );
	}

	public function test_a_complaint_suppresses_broadcasts_only(): void {
		$this->dispatch( WebhookDeliveries::request( WebhookDeliveries::complained( 'anna@example.com' ) ) );

		$this->assertNull( $this->list->reason( 'anna@example.com', 'transactional' ) );
		$this->assertSame( SuppressionList::COMPLAINED, $this->list->reason( 'anna@example.com', 'broadcast' ) );
	}

	public function test_an_unsubscribe_suppresses_broadcasts_only(): void {
		$this->dispatch( WebhookDeliveries::request( WebhookDeliveries::unsubscribed( 'anna@example.com' ) ) );

		$this->assertNull( $this->list->reason( 'anna@example.com', 'transactional' ) );
		$this->assertSame( SuppressionList::UNSUBSCRIBED, $this->list->reason( 'anna@example.com', 'broadcast' ) );
	}

	public function test_a_duplicate_delivery_answers_200_and_changes_nothing(): void {
		$events  = 0;
		$payload = WebhookDeliveries::bounced( 'anna@example.com', true, 'whd_same' );
		add_action(
			Webhook::EVENT_ACTION,
			static function () use ( &$events ): void {
				++$events;
			}
		);

		$first = $this->dispatch( WebhookDeliveries::request( $payload ) );
		$this->list->remove( $this->list->find( 'anna@example.com', 1, 0 )[0]['id'] );
		$again = $this->dispatch( WebhookDeliveries::request( $payload ) );

		$this->assertSame( self::STATUS_OK, $first->get_status() );
		$this->assertSame( self::STATUS_OK, $again->get_status() );
		$this->assertSame( array( 'duplicate' => true ), $again->get_data() );
		$this->assertSame( 1, $events );
		$this->assertNull( $this->list->reason( 'anna@example.com', 'transactional' ) );
	}

	public function test_a_delivery_whose_handling_fails_answers_503_and_is_handled_on_its_retry(): void {
		$payload = WebhookDeliveries::complained( 'anna@example.com' );
		$fail    = static function (): void {
			throw new \RuntimeException( 'A hook failed.' );
		};
		add_action( Webhook::EVENT_ACTION, $fail );

		$failed = $this->dispatch( WebhookDeliveries::request( $payload ) );
		remove_action( Webhook::EVENT_ACTION, $fail );
		$retry = $this->dispatch( WebhookDeliveries::request( $payload ) );

		$this->assertSame( self::STATUS_UNAVAILABLE, $failed->get_status() );
		$this->assertSame( 'connector_for_sendora_delivery_failed', $failed->get_data()['code'] );
		$this->assertSame( self::STATUS_OK, $retry->get_status() );
		$this->assertSame( array( 'duplicate' => false ), $retry->get_data() );
	}

	public function test_a_claim_the_database_refuses_answers_503_and_suppresses_nothing(): void {
		$this->break_writes_to( DeliveryLog::table() );

		$response = $this->dispatch( WebhookDeliveries::request( WebhookDeliveries::bounced( 'anna@example.com' ) ) );
		$this->restore_writes();

		$this->assertSame( self::STATUS_UNAVAILABLE, $response->get_status() );
		$this->assertSame( 'connector_for_sendora_delivery_failed', $response->get_data()['code'] );
		$this->assertNull( $this->list->reason( 'anna@example.com', 'transactional' ) );
	}

	public function test_a_suppression_the_database_refuses_answers_503_and_is_stored_on_the_retry(): void {
		$payload    = WebhookDeliveries::complained( 'anna@example.com' );
		$complained = 0;
		add_action(
			Webhook::COMPLAINED_ACTION,
			static function () use ( &$complained ): void {
				++$complained;
			}
		);
		$this->break_writes_to( SuppressionList::table() );

		$failed = $this->dispatch( WebhookDeliveries::request( $payload ) );
		$this->restore_writes();
		$this->assertSame( 0, $complained );
		$retry = $this->dispatch( WebhookDeliveries::request( $payload ) );

		$this->assertSame( self::STATUS_UNAVAILABLE, $failed->get_status() );
		$this->assertSame( array( 'duplicate' => false ), $retry->get_data() );
		$this->assertSame( 1, $complained );
		$this->assertSame( SuppressionList::COMPLAINED, $this->list->reason( 'anna@example.com', 'broadcast' ) );
	}

	public function test_a_delivery_another_request_is_handling_answers_503(): void {
		$payload = WebhookDeliveries::bounced( 'anna@example.com' );
		( new DeliveryLog() )->claim( $payload['id'] );

		$response = $this->dispatch( WebhookDeliveries::request( $payload ) );

		$this->assertSame( self::STATUS_UNAVAILABLE, $response->get_status() );
		$this->assertSame( 'connector_for_sendora_delivery_in_progress', $response->get_data()['code'] );
		$this->assertNull( $this->list->reason( 'anna@example.com', 'transactional' ) );
	}

	public function test_a_claim_whose_handling_never_finished_is_taken_over_once_its_lease_is_up(): void {
		global $wpdb;
		$payload = WebhookDeliveries::bounced( 'anna@example.com' );
		( new DeliveryLog() )->claim( $payload['id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- backdates the claim in the plugin's own table.
		$wpdb->update(
			DeliveryLog::table(),
			array( 'received_at' => gmdate( self::DATE_FORMAT, time() - DeliveryLog::PROCESSING_LEASE_SECONDS - 1 ) ),
			array( 'delivery_hash' => DeliveryLog::key( $payload['id'] ) )
		);

		$response = $this->dispatch( WebhookDeliveries::request( $payload ) );
		$again    = $this->dispatch( WebhookDeliveries::request( $payload ) );

		$this->assertSame( array( 'duplicate' => false ), $response->get_data() );
		$this->assertSame( SuppressionList::BOUNCED, $this->list->reason( 'anna@example.com', 'transactional' ) );
		$this->assertSame( array( 'duplicate' => true ), $again->get_data() );
	}

	public function test_every_verified_event_fires_the_event_hook(): void {
		$seen = array();
		add_action(
			Webhook::EVENT_ACTION,
			static function ( string $type, array $payload ) use ( &$seen ): void {
				$seen[] = array( $type, $payload['recipient'] ?? null );
			},
			10,
			2
		);

		$this->dispatch( WebhookDeliveries::request( WebhookDeliveries::delivered( 'anna@example.com' ) ) );
		$this->dispatch( WebhookDeliveries::request( WebhookDeliveries::payload( 'something_new', 'bo@example.com', array() ) ) );

		$this->assertSame(
			array(
				array( 'delivered', 'anna@example.com' ),
				array( 'something_new', 'bo@example.com' ),
			),
			$seen
		);
	}

	/**
	 * The hook each event fires, with its payload.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function suppressing_events(): array {
		return array(
			'hard bounce' => array( WebhookDeliveries::bounced( 'Anna@Example.com' ), Webhook::BOUNCED_ACTION ),
			'complaint'   => array( WebhookDeliveries::complained( 'Anna@Example.com' ), Webhook::COMPLAINED_ACTION ),
			'unsubscribe' => array( WebhookDeliveries::unsubscribed( 'Anna@Example.com' ), Webhook::UNSUBSCRIBED_ACTION ),
		);
	}

	/**
	 * The event's own hook gets the address and the event.
	 *
	 * @dataProvider suppressing_events
	 *
	 * @param array<string, mixed> $payload The event.
	 * @param string               $hook    The hook it fires.
	 */
	public function test_a_suppressing_event_fires_its_hook_with_the_address_and_the_event( array $payload, string $hook ): void {
		$fired = array();
		add_action(
			$hook,
			static function ( string $email, array $event ) use ( &$fired ): void {
				$fired[] = array( $email, $event['id'] );
			},
			10,
			2
		);

		$this->dispatch( WebhookDeliveries::request( $payload ) );

		$this->assertSame( array( array( 'anna@example.com', $payload['id'] ) ), $fired );
	}

	/**
	 * Makes the database refuse every insert into and update of the table,
	 * quietly, until {@see self::restore_writes()}.
	 *
	 * @param string $table The table.
	 */
	private function break_writes_to( string $table ): void {
		global $wpdb;
		$this->break_writes      = static fn ( string $query ): string => preg_match( '/^(INSERT|UPDATE)\b.*`' . preg_quote( $table, '/' ) . '`/s', $query ) ? 'NOT SQL' : $query;
		$this->suppressed_errors = $wpdb->suppress_errors();
		add_filter( 'query', $this->break_writes );
	}

	/** Lets writes through again. */
	private function restore_writes(): void {
		global $wpdb;
		if ( null !== $this->break_writes ) {
			remove_filter( 'query', $this->break_writes );
			$this->break_writes = null;
		}
		$wpdb->suppress_errors( $this->suppressed_errors );
	}

	/**
	 * The REST server's answer to the request.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	private function dispatch( WP_REST_Request $request ): WP_REST_Response {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The webhook's answer, called directly.
	 *
	 * @param Webhook         $webhook The webhook.
	 * @param WP_REST_Request $request The request.
	 */
	private static function answer( Webhook $webhook, WP_REST_Request $request ): WP_REST_Response {
		$response = $webhook->receive( $request );
		self::assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}
}
