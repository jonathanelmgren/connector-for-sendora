<?php
/**
 * The upload on the suppression list's screen.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Admin;

use ConnectorForSendora\Database\QueryFailed;
use ConnectorForSendora\Import\ImportFailed;
use ConnectorForSendora\Import\Importer;
use ConnectorForSendora\Import\ImportOptions;
use ConnectorForSendora\Import\ImportPlan;

/**
 * Imports an address verifier's export from the suppression list's screen,
 * in two steps: an upload that is read as a dry run, and a confirmation that
 * writes what the dry run showed. The file is deleted once read; the
 * addresses to add wait in a transient of the user's own until confirmed,
 * cancelled or an hour has passed.
 */
final class SuppressionImport {

	/** The admin-post action that reads an upload. */
	public const PREVIEW_ACTION = 'connector_for_sendora_import_preview';

	/** The admin-post action that writes a previewed import. */
	public const CONFIRM_ACTION = 'connector_for_sendora_import_confirm';

	/** The admin-post action that drops a previewed import. */
	public const CANCEL_ACTION = 'connector_for_sendora_import_cancel';

	/** The upload's field. */
	public const FILE_FIELD = 'connector_for_sendora_import_file';

	/** The field naming the address column. */
	public const EMAIL_COLUMN_FIELD = 'email_column';

	/** The field naming the status column. */
	public const STATUS_COLUMN_FIELD = 'status_column';

	/** The field listing the statuses that suppress. */
	public const STATUSES_FIELD = 'statuses';

	/** The field carrying the previewed import's token. */
	public const TOKEN_FIELD = 'token';

	/** The largest file the screen takes: 10 MB, some 100 000 rows of a typical export. */
	public const MAX_BYTES = 10 * MB_IN_BYTES;

	/** The transient holding a user's import, followed by the user's id. */
	public const TRANSIENT_PREFIX = 'connector_for_sendora_import_';

	/** A previewed import, waiting for its confirmation. */
	private const STATE_PREVIEW = 'preview';

	/** A written import, to report once. */
	private const STATE_DONE = 'done';

	/** A refused upload, to report once. */
	private const STATE_ERROR = 'error';

	/** How long a previewed import waits. */
	private const LIFETIME = HOUR_IN_SECONDS;

	/** The only file type the upload takes. */
	private const MIME_TYPES = array( 'csv' => 'text/csv' );

	/** The capability an import needs. */
	public const CAPABILITY = 'manage_options';

	/**
	 * Tells an upload from any other file; PHP's own check, unless a test
	 * stands in for the upload.
	 *
	 * @var callable(string): bool
	 */
	private $is_upload;

	/**
	 * An upload form.
	 *
	 * @param Importer                      $importer  What it imports with.
	 * @param (callable(string): bool)|null $is_upload Whether a path is a file PHP received in this request; is_uploaded_file() when null.
	 */
	public function __construct( private readonly Importer $importer, ?callable $is_upload = null ) {
		$this->is_upload = $is_upload ?? 'is_uploaded_file';
	}

	/** Hooks the handlers in. */
	public function register(): void {
		add_action( 'admin_post_' . self::PREVIEW_ACTION, array( $this, 'handle_preview' ) );
		add_action( 'admin_post_' . self::CONFIRM_ACTION, array( $this, 'handle_confirm' ) );
		add_action( 'admin_post_' . self::CANCEL_ACTION, array( $this, 'handle_cancel' ) );
	}

	/** Reads an upload as a dry run, then returns to the screen. */
	public function handle_preview(): void {
		$this->authorise( self::PREVIEW_ACTION );

		$path = null;
		try {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in authorise(); checked below, field by field; the file itself is read as data only.
			$file = isset( $_FILES[ self::FILE_FIELD ] ) && is_array( $_FILES[ self::FILE_FIELD ] ) ? $_FILES[ self::FILE_FIELD ] : array();
			$path = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) && '' !== $file['tmp_name'] ? $file['tmp_name'] : null;
			$this->check_upload( $file, $path );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in authorise().
			$options = ImportOptions::from_text(
				isset( $_POST[ self::EMAIL_COLUMN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::EMAIL_COLUMN_FIELD ] ) ) : '',
				isset( $_POST[ self::STATUS_COLUMN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::STATUS_COLUMN_FIELD ] ) ) : '',
				isset( $_POST[ self::STATUSES_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::STATUSES_FIELD ] ) ) : ''
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$plan = $this->importer->plan( (string) $path, $options );
			$kept = $this->save(
				array(
					'state' => self::STATE_PREVIEW,
					'token' => wp_generate_password( 20, false ),
					'plan'  => array(
						'rows_read' => $plan->rows_read,
						'suppress'  => $plan->suppress,
						'already'   => $plan->already,
						'skipped'   => $plan->skipped_counts(),
					),
				)
			);
			if ( ! $kept ) {
				$this->save_error( ImportFailed::not_kept()->getMessage() );
			}
		} catch ( ImportFailed $e ) {
			$this->save_error( $e->getMessage() );
		} catch ( QueryFailed $e ) {
			$this->save_error( ImportFailed::database( $e )->getMessage() );
		} finally {
			if ( null !== $path && ( $this->is_upload )( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$this->back();
	}

	/** Writes the previewed import, then returns to the screen. */
	public function handle_confirm(): void {
		$this->authorise( self::CONFIRM_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorise().
		$token = isset( $_POST[ self::TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TOKEN_FIELD ] ) ) : '';
		$saved = $this->saved();
		if ( null === $saved || self::STATE_PREVIEW !== $saved['state'] || ! hash_equals( (string) $saved['token'], $token ) ) {
			$this->save_error( __( 'That import has expired or was already done. Upload the file again.', 'connector-for-sendora' ) );
			$this->back();
		}

		$plan = self::plan( $saved['plan'] );
		try {
			$applied = $this->importer->apply( $plan->suppress );
		} catch ( QueryFailed $e ) {
			$this->save_error( ImportFailed::database( $e )->getMessage() );
			$this->back();
		}
		$this->save(
			array(
				'state'   => self::STATE_DONE,
				'summary' => $plan->summary( $applied ),
			)
		);
		$this->back();
	}

	/** Drops the previewed import, then returns to the screen. */
	public function handle_cancel(): void {
		$this->authorise( self::CANCEL_ACTION );
		delete_transient( self::transient() );
		$this->back();
	}

	/** Shows the import's part of the screen: a pending dry run, a result, or the upload form. */
	public function render(): void {
		$saved = $this->saved();
		echo '<h2>' . esc_html__( 'Import an address verifier\'s results', 'connector-for-sendora' ) . '</h2>';

		if ( null !== $saved && self::STATE_PREVIEW === $saved['state'] ) {
			$this->render_preview( self::plan( $saved['plan'] ), (string) $saved['token'] );
			return;
		}
		if ( null !== $saved && self::STATE_DONE === $saved['state'] ) {
			delete_transient( self::transient() );
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The import is done.', 'connector-for-sendora' ) . '</p>';
			self::render_lines( $saved['summary'] );
			echo '</div>';
		} elseif ( null !== $saved && self::STATE_ERROR === $saved['state'] ) {
			delete_transient( self::transient() );
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $saved['message'] ) . '</p></div>';
		}
		$this->render_form();
	}

	/**
	 * Refuses a request from a user who cannot import, or without the form's nonce.
	 *
	 * @param string $action The nonce's action.
	 */
	private function authorise( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change the suppression list.', 'connector-for-sendora' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Refuses anything but a CSV file uploaded in this request, within the size limit.
	 *
	 * @param array<mixed> $file The upload's entry in $_FILES.
	 * @param string|null  $path Where PHP put it.
	 * @throws ImportFailed The upload is missing, failed, too large or not CSV.
	 */
	private function check_upload( array $file, ?string $path ): void {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			throw ImportFailed::too_large( self::max_bytes() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where it is shown.
		}
		if ( UPLOAD_ERR_OK !== $error || null === $path || ! ( $this->is_upload )( $path ) ) {
			throw ImportFailed::no_upload();
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::max_bytes() ) {
			throw ImportFailed::too_large( self::max_bytes() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where it is shown.
		}
		$name  = isset( $file['name'] ) && is_string( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$found = wp_check_filetype_and_ext( $path, $name, self::MIME_TYPES );
		if ( 'csv' !== $found['ext'] ) {
			throw ImportFailed::not_csv();
		}
	}

	/** The largest file the screen takes, within what PHP accepts. */
	private static function max_bytes(): int {
		return min( self::MAX_BYTES, wp_max_upload_size() );
	}

	/** The upload form. */
	private function render_form(): void {
		echo '<p>' . esc_html__( 'Sendora wants hard bounces to stay below 5% of the mail sent. Before the first send to an old list, run the list through an address verifier and upload its results as CSV: every address with a status that suppresses goes on the list as a hard bounce. Nothing is written until you confirm.', 'connector-for-sendora' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::PREVIEW_ACTION ) . '" />';
		wp_nonce_field( self::PREVIEW_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="connector-for-sendora-import-file">%s</label></th><td><input type="file" id="connector-for-sendora-import-file" name="%s" accept=".csv,text/csv" required /><p class="description">%s</p></td></tr>',
			esc_html__( 'CSV file', 'connector-for-sendora' ),
			esc_attr( self::FILE_FIELD ),
			/* translators: %s: the largest file size, such as "10 MB". */
			esc_html( sprintf( __( 'With a header row. At most %s.', 'connector-for-sendora' ), size_format( self::max_bytes() ) ) )
		);
		$fields = array(
			self::EMAIL_COLUMN_FIELD  => array( __( 'Address column', 'connector-for-sendora' ), ImportOptions::DEFAULT_EMAIL_COLUMN, '' ),
			self::STATUS_COLUMN_FIELD => array( __( 'Status column', 'connector-for-sendora' ), ImportOptions::DEFAULT_STATUS_COLUMN, '' ),
			self::STATUSES_FIELD      => array( __( 'Statuses that suppress', 'connector-for-sendora' ), implode( ',', ImportOptions::DEFAULT_STATUSES ), __( 'Comma-separated. Column names and statuses are matched whatever their case.', 'connector-for-sendora' ) ),
		);
		foreach ( $fields as $name => [ $label, $value, $help ] ) {
			printf(
				'<tr><th scope="row"><label for="connector-for-sendora-import-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="connector-for-sendora-import-%1$s" name="%1$s" value="%3$s" />%4$s</td></tr>',
				esc_attr( $name ),
				esc_html( $label ),
				esc_attr( $value ),
				'' === $help ? '' : '<p class="description">' . esc_html( $help ) . '</p>'
			);
		}
		echo '</tbody></table>';
		submit_button( __( 'Check the file', 'connector-for-sendora' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The dry run, with the buttons that write or drop it.
	 *
	 * @param ImportPlan $plan  The dry run.
	 * @param string     $token The import's token.
	 */
	private function render_preview( ImportPlan $plan, string $token ): void {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Dry run: nothing has been written yet.', 'connector-for-sendora' ) . '</p>';
		self::render_lines( $plan->summary() );
		echo '</div><p>';
		foreach ( array(
			self::CONFIRM_ACTION => array( __( 'Import', 'connector-for-sendora' ), 'primary' ),
			self::CANCEL_ACTION  => array( __( 'Cancel', 'connector-for-sendora' ), 'secondary' ),
		) as $action => [ $label, $type ] ) {
			echo '<form method="post" style="display:inline-block;margin-right:8px" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
			echo '<input type="hidden" name="' . esc_attr( self::TOKEN_FIELD ) . '" value="' . esc_attr( $token ) . '" />';
			wp_nonce_field( $action );
			// Nothing to write: the import button would do nothing.
			$disabled = self::CONFIRM_ACTION === $action && array() === $plan->suppress;
			submit_button( $label, $type, 'submit', false, $disabled ? array( 'disabled' => 'disabled' ) : '' );
			echo '</form>';
		}
		echo '</p>';
	}

	/**
	 * Sentences as a list.
	 *
	 * @param array<mixed> $lines The sentences.
	 */
	private static function render_lines( array $lines ): void {
		echo '<ul>';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( (string) $line ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * A stored dry run, as a plan again.
	 *
	 * @param mixed $stored The plan's fields.
	 */
	private static function plan( mixed $stored ): ImportPlan {
		$stored = is_array( $stored ) ? $stored : array();
		/**
		 * The fields as {@see self::handle_preview()} stored them.
		 *
		 * @var array{rows_read?: int, suppress?: list<string>, already?: list<string>, skipped?: array<string, int>} $stored
		 */
		return new ImportPlan( (int) ( $stored['rows_read'] ?? 0 ), $stored['suppress'] ?? array(), $stored['already'] ?? array(), $stored['skipped'] ?? array() );
	}

	/**
	 * Keeps the user's import in place of the one before, which is dropped
	 * even when the new one cannot be kept.
	 *
	 * @param array<string, mixed> $state The import.
	 * @return bool Whether it was kept.
	 */
	private function save( array $state ): bool {
		delete_transient( self::transient() );
		return set_transient( self::transient(), $state, self::LIFETIME );
	}

	/**
	 * Keeps a refusal, to report once.
	 *
	 * @param string $message Why, as a sentence for the person importing.
	 */
	private function save_error( string $message ): void {
		$this->save(
			array(
				'state'   => self::STATE_ERROR,
				'message' => $message,
			)
		);
	}

	/**
	 * The user's import, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	private function saved(): ?array {
		$saved = get_transient( self::transient() );
		return is_array( $saved ) && isset( $saved['state'] ) ? $saved : null;
	}

	/** The transient holding the current user's import. */
	private static function transient(): string {
		return self::TRANSIENT_PREFIX . get_current_user_id();
	}

	/** Returns to the suppression list's screen. */
	private function back(): never {
		wp_safe_redirect( SuppressionPage::url() );
		exit;
	}
}
