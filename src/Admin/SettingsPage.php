<?php
/**
 * The settings page under Settings, and the test-mode notice.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Admin;

use SendoraWP\Settings\Settings;
use SendoraWP\Webhook\Webhook;
use WP_Error;

/**
 * Settings → Sendora: the API key, the two stream ids, the default
 * sender and the webhook's signing secret, each read-only when a
 * wp-config.php constant sets it; the webhook's address and the events to
 * subscribe it to; and a button that sends a test email.
 *
 * A saved API key or signing secret is never written back into the page:
 * the field stays empty, and leaving it empty keeps the saved value.
 */
final class SettingsPage {

	/** The page's slug, in its address. */
	public const SLUG = 'sendora-wp';

	/** The page's screen id, from add_options_page(). */
	private const SCREEN = 'settings_page_' . self::SLUG;

	/** The settings group the form saves. */
	private const GROUP = 'sendora_wp';

	/** The capability the page and the test email need. */
	public const CAPABILITY = 'manage_options';

	/** The admin-post action that sends the test email. */
	public const TEST_ACTION = 'sendora_wp_test_email';

	/** The form field that asks for the saved key to be removed. */
	public const REMOVE_KEY_FIELD = 'remove_api_key';

	/** The form field that asks for the saved signing secret to be removed. */
	public const REMOVE_WEBHOOK_SECRET_FIELD = 'remove_webhook_secret';

	/** The fields never written back into the page, and the field that removes each. */
	private const SECRETS = array(
		Settings::API_KEY        => self::REMOVE_KEY_FIELD,
		Settings::WEBHOOK_SECRET => self::REMOVE_WEBHOOK_SECRET_FIELD,
	);

	/** The query argument the page is shown with after a test email. */
	private const TEST_RESULT_ARG = 'sendora_wp_test';

	/** How long a test email's result waits to be shown, in seconds. */
	private const TEST_RESULT_TTL = 60;

	/** The transient holding a user's test result, followed by the user's id. */
	public const TEST_RESULT_PREFIX = 'sendora_wp_test_';

	/**
	 * A page.
	 *
	 * @param Settings $settings The settings it shows and saves.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/** Hooks the page, its form handlers and its notice in. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'send_test_email' ) );
		add_action( 'admin_notices', array( $this, 'test_mode_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SENDORA_WP_FILE ), array( $this, 'action_links' ) );
	}

	/** Adds the page under Settings. */
	public function add_page(): void {
		add_options_page(
			__( 'Sendora', 'sendora-for-wp' ),
			__( 'Sendora', 'sendora-for-wp' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** Registers the option the form saves. */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * A Settings link next to the plugin's name on the Plugins screen.
	 *
	 * @param array<int|string, string> $links The existing links.
	 * @return array<int|string, string>
	 */
	public function action_links( $links ): array {
		$settings = sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'sendora-for-wp' ) );
		return array_merge( array( 'settings' => $settings ), (array) $links );
	}

	/**
	 * The option's new value from the submitted form. A field a constant
	 * sets keeps its saved value; an empty API key or signing secret field
	 * keeps the saved one, unless removing it was asked for.
	 *
	 * @param mixed $input The submitted values.
	 * @return array<string, string>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$saved = Settings::saved();
		$clean = array();

		foreach ( array_keys( Settings::CONSTANTS ) as $field ) {
			$previous = isset( $saved[ $field ] ) && is_string( $saved[ $field ] ) ? $saved[ $field ] : '';
			$value    = isset( $input[ $field ] ) && is_string( $input[ $field ] ) ? $input[ $field ] : '';

			if ( $this->settings->is_constant( $field ) ) {
				$clean[ $field ] = $previous;
			} elseif ( isset( self::SECRETS[ $field ] ) ) {
				$value           = trim( sanitize_text_field( $value ) );
				$clean[ $field ] = ! empty( $input[ self::SECRETS[ $field ] ] ) ? '' : ( '' === $value ? $previous : $value );
			} elseif ( Settings::FROM_EMAIL === $field ) {
				$clean[ $field ] = sanitize_email( $value );
				if ( '' !== trim( $value ) && '' === $clean[ $field ] ) {
					add_settings_error( Settings::OPTION, 'from_email', __( 'The From address is not a valid email address, so it was not saved.', 'sendora-for-wp' ) );
				}
			} else {
				$clean[ $field ] = trim( sanitize_text_field( $value ) );
			}
		}
		return $clean;
	}

	/** Shows the page. */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Sendora', 'sendora-for-wp' ) . '</h1>';

		$this->render_status();
		$this->render_test_result();

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_secret_row(
			Settings::API_KEY,
			__( 'API key', 'sendora-for-wp' ),
			__( "A server key from Sendora's dashboard. A test server's key (sk_test_…) sends nothing to anyone.", 'sendora-for-wp' ),
			__( 'A key is saved; enter a new one to replace it', 'sendora-for-wp' ),
			'sk_live_…',
			__( 'Remove the saved key', 'sendora-for-wp' )
		);
		$this->render_row(
			Settings::TRANSACTIONAL_STREAM,
			__( 'Transactional stream id', 'sendora-for-wp' ),
			__( "The stream this site's email goes on. Empty: the server's default transactional stream.", 'sendora-for-wp' )
		);
		$this->render_row(
			Settings::BROADCAST_STREAM,
			__( 'Broadcast stream id', 'sendora-for-wp' ),
			__( 'The stream for newsletters and other bulk mail.', 'sendora-for-wp' )
		);
		$this->render_row(
			Settings::FROM_EMAIL,
			__( 'From address', 'sendora-for-wp' ),
			__( "Used when a message names no sender. Its domain must be one of your Sendora server's verified sending domains.", 'sendora-for-wp' ),
			'email'
		);
		$this->render_row(
			Settings::FROM_NAME,
			__( 'From name', 'sendora-for-wp' ),
			__( 'Used when a message names no sender.', 'sendora-for-wp' )
		);
		$this->render_secret_row(
			Settings::WEBHOOK_SECRET,
			__( 'Webhook signing secret', 'sendora-for-wp' ),
			__( 'The secret Sendora shows once, when the webhook is created. Without it the webhook answers 503, and Sendora keeps retrying.', 'sendora-for-wp' ),
			__( 'A secret is saved; enter a new one to replace it', 'sendora-for-wp' ),
			'',
			__( 'Remove the saved secret', 'sendora-for-wp' )
		);
		echo '</tbody></table>';
		submit_button();
		echo '</form>';

		$this->render_webhook();

		$this->render_test_form();
		echo '</div>';
	}

	/** Shows the test-mode notice on every admin screen, to whoever manages the settings. */
	public function test_mode_notice(): void {
		if ( ! $this->settings->is_test_mode() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// The page itself says so in its own status.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null !== $screen && self::SCREEN === $screen->id ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Sendora is in test mode: the API key belongs to a test server, which accepts email but delivers none of it.', 'sendora-for-wp' ),
			esc_url( self::url() ),
			esc_html__( 'Sendora settings', 'sendora-for-wp' )
		);
	}

	/** Sends the test email, then returns to the page with its result. */
	public function send_test_email(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to send a test email.', 'sendora-for-wp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::TEST_ACTION );

		$to     = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$result = $this->test_email( $to );

		set_transient( self::test_result_key(), $result, self::TEST_RESULT_TTL );
		wp_safe_redirect( add_query_arg( self::TEST_RESULT_ARG, '1', self::url() ) );
		exit;
	}

	/**
	 * Sends a test email to the address, and answers how it went.
	 *
	 * @param string $to The recipient.
	 * @return array{sent: bool, message: string}
	 */
	public function test_email( string $to ): array {
		if ( '' === $to ) {
			return array(
				'sent'    => false,
				'message' => __( 'Enter a valid email address to send the test email to.', 'sendora-for-wp' ),
			);
		}
		if ( ! $this->settings->is_configured() ) {
			return array(
				'sent'    => false,
				'message' => __( 'Save an API key first: without one, WordPress sends email on its own.', 'sendora-for-wp' ),
			);
		}

		$error  = null;
		$record = static function ( WP_Error $failure ) use ( &$error ): void {
			$error = $failure;
		};
		add_action( 'wp_mail_failed', $record );
		$sent = wp_mail(
			$to,
			__( 'Test email from Sendora', 'sendora-for-wp' ),
			sprintf(
				/* translators: %s: the site's name. */
				__( 'This test email was sent through Sendora by %s.', 'sendora-for-wp' ),
				get_bloginfo( 'name' )
			)
		);
		remove_action( 'wp_mail_failed', $record );

		if ( $sent ) {
			$message = $this->settings->is_test_mode()
				/* translators: %s: the recipient's email address. */
				? __( 'Sendora accepted the test email to %s. The key is a test key, so it will not be delivered.', 'sendora-for-wp' )
				/* translators: %s: the recipient's email address. */
				: __( 'Sendora accepted the test email to %s.', 'sendora-for-wp' );
			return array(
				'sent'    => true,
				'message' => sprintf( $message, $to ),
			);
		}
		return array(
			'sent'    => false,
			'message' => sprintf(
				/* translators: %s: the reason the send failed. */
				__( 'The test email was not sent: %s', 'sendora-for-wp' ),
				$error instanceof WP_Error ? $error->get_error_message() : __( 'an unknown error.', 'sendora-for-wp' )
			),
		);
	}

	/** The page's address. */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/** Whether the plugin is sending, and in which mode. */
	private function render_status(): void {
		if ( ! $this->settings->is_configured() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Not sending: until an API key is set, WordPress sends email as it would without this plugin.', 'sendora-for-wp' ) . '</p></div>';
			return;
		}
		if ( $this->settings->is_test_mode() ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Test mode.', 'sendora-for-wp' ) . '</strong> ' . esc_html__( 'The API key belongs to a Sendora test server: it accepts email and delivers none of it.', 'sendora-for-wp' ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-success inline"><p>' . esc_html__( "Sending: the site's email goes through Sendora.", 'sendora-for-wp' ) . '</p></div>';
	}

	/** The last test email's result, once, when the page is shown after one. */
	private function render_test_result(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which notice to show; the result itself is the user's own transient.
		if ( ! isset( $_GET[ self::TEST_RESULT_ARG ] ) ) {
			return;
		}
		$result = get_transient( self::test_result_key() );
		delete_transient( self::test_result_key() );
		if ( ! is_array( $result ) || ! isset( $result['sent'], $result['message'] ) ) {
			return;
		}
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			$result['sent'] ? 'notice-success' : 'notice-error',
			esc_html( (string) $result['message'] )
		);
	}

	/**
	 * A secret's row: never shows the secret, only whether one is saved.
	 *
	 * @param string $field        The setting.
	 * @param string $label        Its label.
	 * @param string $description  What it is for.
	 * @param string $saved_hint   The placeholder when one is saved.
	 * @param string $empty_hint   The placeholder when none is.
	 * @param string $remove_label The label of the checkbox that removes the saved one.
	 */
	private function render_secret_row( string $field, string $label, string $description, string $saved_hint, string $empty_hint, string $remove_label ): void {
		$id = 'sendora-wp-' . $field;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( Settings::API_KEY === $field && $this->settings->is_test_mode() ) {
			echo ' <span class="sendora-wp-test-mode">(' . esc_html__( 'test mode', 'sendora-for-wp' ) . ')</span>';
		}
		echo '</th><td>';

		if ( $this->settings->is_constant( $field ) ) {
			echo '<input type="password" id="' . esc_attr( $id ) . '" class="regular-text" value="" placeholder="' . esc_attr__( 'Set in wp-config.php', 'sendora-for-wp' ) . '" readonly disabled />';
			echo '<p class="description">' . esc_html( self::constant_note( $field ) ) . '</p></td></tr>';
			return;
		}

		$saved = '' !== $this->settings->get( $field );
		printf(
			'<input type="password" id="%s" name="%s" class="regular-text" value="" autocomplete="off" placeholder="%s" />',
			esc_attr( $id ),
			esc_attr( Settings::OPTION . '[' . $field . ']' ),
			esc_attr( $saved ? $saved_hint : $empty_hint )
		);
		echo '<p class="description">' . esc_html( $description ) . '</p>';
		if ( $saved ) {
			printf(
				'<p><label><input type="checkbox" name="%s" value="1" /> %s</label></p>',
				esc_attr( Settings::OPTION . '[' . self::SECRETS[ $field ] . ']' ),
				esc_html( $remove_label )
			);
		}
		echo '</td></tr>';
	}

	/** Where Sendora sends its delivery reports, and which to subscribe to. */
	private function render_webhook(): void {
		echo '<h2>' . esc_html__( 'Delivery reports', 'sendora-for-wp' ) . '</h2>';
		echo '<p>' . esc_html__( "Create a webhook in Sendora's dashboard with this address, and paste its signing secret above:", 'sendora-for-wp' ) . '</p>';
		echo '<p><input type="text" class="large-text code" value="' . esc_attr( Webhook::url() ) . '" readonly onfocus="this.select()" /></p>';
		echo '<p>' . esc_html__( 'Subscribe it to these events:', 'sendora-for-wp' ) . ' <code>' . implode( '</code>, <code>', array_map( 'esc_html', Webhook::EVENTS ) ) . '</code></p>';
		printf(
			'<p>%s <a href="%s">%s</a></p>',
			esc_html__( 'A hard bounce stops all mail to the address; a complaint or an unsubscribe stops broadcasts to it.', 'sendora-for-wp' ),
			esc_url( SuppressionPage::url() ),
			esc_html__( 'Suppressed addresses', 'sendora-for-wp' )
		);
	}

	/**
	 * A text field's row, read-only when a constant sets it.
	 *
	 * @param string $field       The setting.
	 * @param string $label       Its label.
	 * @param string $description What it is for.
	 * @param string $type        The input's type.
	 */
	private function render_row( string $field, string $label, string $description, string $type = 'text' ): void {
		$id       = 'sendora-wp-' . $field;
		$constant = $this->settings->is_constant( $field );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="%s" id="%s" %s class="regular-text" value="%s" %s />',
			esc_attr( $type ),
			esc_attr( $id ),
			$constant ? '' : 'name="' . esc_attr( Settings::OPTION . '[' . $field . ']' ) . '"',
			esc_attr( $this->settings->get( $field ) ),
			$constant ? 'readonly disabled' : ''
		);
		echo '<p class="description">' . esc_html( $constant ? self::constant_note( $field ) : $description ) . '</p>';
		echo '</td></tr>';
	}

	/** The form that sends a test email. */
	private function render_test_form(): void {
		echo '<h2>' . esc_html__( 'Send a test email', 'sendora-for-wp' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::TEST_ACTION ) . '" />';
		wp_nonce_field( self::TEST_ACTION );
		$user = wp_get_current_user();
		printf(
			'<p><label for="sendora-wp-test-to">%s</label> <input type="email" id="sendora-wp-test-to" name="to" class="regular-text" value="%s" required /></p>',
			esc_html__( 'Send to', 'sendora-for-wp' ),
			esc_attr( $user->user_email )
		);
		submit_button( __( 'Send test email', 'sendora-for-wp' ), 'secondary', 'submit', false, $this->settings->is_configured() ? '' : array( 'disabled' => 'disabled' ) );
		echo '</form>';
	}

	/**
	 * Says which constant sets the field.
	 *
	 * @param string $field The setting.
	 */
	private static function constant_note( string $field ): string {
		return sprintf(
			/* translators: %s: the constant's name. */
			__( 'Set by %s in wp-config.php.', 'sendora-for-wp' ),
			Settings::CONSTANTS[ $field ]
		);
	}

	/** Where the current user's last test result waits. */
	private static function test_result_key(): string {
		return self::TEST_RESULT_PREFIX . get_current_user_id();
	}
}
