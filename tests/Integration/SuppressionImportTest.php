<?php
/**
 * Tests for the import on the suppression list's screen.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Integration;

use ConnectorForSendora\Admin\SuppressionImport;
use ConnectorForSendora\Admin\SuppressionPage;
use ConnectorForSendora\Import\Importer;
use ConnectorForSendora\Suppression\SuppressionList;
use ConnectorForSendora\Tests\Support\BrokenQueries;
use ConnectorForSendora\Tests\Support\CsvFiles;
use ConnectorForSendora\Tests\Support\Redirected;
use WPDieException;

/**
 * Uploads, previews and confirms as an administrator would.
 */
final class SuppressionImportTest extends TestCase {

	/** Bytes that start every PNG file. */
	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	private SuppressionList $list;

	private CsvFiles $files;

	private BrokenQueries $queries;

	/**
	 * The paths the screen treats as uploads.
	 *
	 * @var list<string>
	 */
	private array $uploads = array();

	public function set_up(): void {
		parent::set_up();
		$this->log_in( 'administrator' );
		$this->list    = new SuppressionList();
		$this->files   = new CsvFiles();
		$this->queries = new BrokenQueries();
		Redirected::catch();
	}

	public function tear_down(): void {
		$this->queries->restore();
		$this->files->delete_all();
		$_FILES   = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	public function test_an_upload_is_a_dry_run_and_its_file_is_deleted(): void {
		$path = $this->upload( "email,status\nanna@example.com,undeliverable\nbo@example.org,deliverable\n" );

		$this->assertSame( SuppressionPage::url(), $this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' ) );

		$this->assertFileDoesNotExist( $path );
		$this->assertSame( 0, $this->list->count( '' ) );
		$page = $this->render();
		$this->assertStringContainsString( 'Dry run: nothing has been written yet.', $page );
		$this->assertStringContainsString( '2 rows read.', $page );
		$this->assertStringContainsString( '1 address would be suppressed.', $page );
		$this->assertStringContainsString( '1 row skipped: status does not suppress.', $page );
		$this->assertStringNotContainsString( 'anna@example.com', $page );
		$this->assertStringContainsString( 'value="' . SuppressionImport::CONFIRM_ACTION . '"', $page );
		$this->assertStringNotContainsString( 'disabled', $page );
	}

	public function test_a_dry_run_with_nothing_to_add_cannot_be_confirmed(): void {
		$this->upload( "email,status\nanna@example.com,deliverable\n" );

		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );

		$this->assertStringContainsString( 'value="Import" disabled', $this->render() );
	}

	public function test_confirming_writes_what_the_dry_run_showed(): void {
		$this->upload( "Email;Result\nanna@example.com;Invalid\n" );
		$this->post(
			SuppressionImport::PREVIEW_ACTION,
			'handle_preview',
			array(
				SuppressionImport::STATUS_COLUMN_FIELD => 'result',
				SuppressionImport::STATUSES_FIELD      => 'invalid',
			)
		);

		$this->post( SuppressionImport::CONFIRM_ACTION, 'handle_confirm', array( SuppressionImport::TOKEN_FIELD => $this->token() ) );

		$row = $this->list->find( 'anna@example.com', 1, 0 )[0];
		$this->assertSame( SuppressionList::BOUNCED, $row['reason'] );
		$this->assertSame( SuppressionList::SOURCE_IMPORT, $row['source'] );
		$page = $this->render();
		$this->assertStringContainsString( 'The import is done.', $page );
		$this->assertStringContainsString( '1 address suppressed.', $page );
		$this->assertStringNotContainsString( 'The import is done.', $this->render() );
	}

	public function test_a_confirmation_with_the_wrong_token_writes_nothing(): void {
		$this->upload( "email,status\nanna@example.com,undeliverable\n" );
		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );

		$this->post( SuppressionImport::CONFIRM_ACTION, 'handle_confirm', array( SuppressionImport::TOKEN_FIELD => 'not-the-token' ) );

		$this->assertSame( 0, $this->list->count( '' ) );
		$this->assertStringContainsString( 'That import has expired or was already done.', $this->render() );
	}

	public function test_cancelling_drops_the_dry_run(): void {
		$this->upload( "email,status\nanna@example.com,undeliverable\n" );
		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );

		$this->post( SuppressionImport::CANCEL_ACTION, 'handle_cancel' );

		$this->assertSame( 0, $this->list->count( '' ) );
		$this->assertStringContainsString( 'name="' . SuppressionImport::FILE_FIELD . '"', $this->render() );
	}

	public function test_a_dry_run_the_site_cannot_keep_is_refused_and_no_older_one_shows(): void {
		$this->upload( "email,status\nanna@example.com,undeliverable\n" );
		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );
		$path = $this->upload( "email,status\nbo@example.org,undeliverable\n" );
		$this->queries->refuse( '/^INSERT\b.*_transient_' . preg_quote( SuppressionImport::TRANSIENT_PREFIX, '/' ) . '.*preview/s' );

		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );
		$this->queries->restore();

		$this->assertFileDoesNotExist( $path );
		$page = $this->render();
		$this->assertStringContainsString( 'The dry run could not be kept for you to confirm', $page );
		$this->assertStringNotContainsString( 'Dry run: nothing has been written yet.', $page );
	}

	public function test_a_file_that_is_not_csv_is_refused_and_deleted(): void {
		$path = $this->upload( self::PNG_SIGNATURE . str_repeat( "\0", 64 ) );

		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );

		$this->assertFileDoesNotExist( $path );
		$this->assertStringContainsString( 'That is not a CSV file.', $this->render() );
	}

	public function test_a_file_not_named_csv_is_refused(): void {
		$this->upload( "email,status\nanna@example.com,undeliverable\n", 'results.txt' );

		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );

		$this->assertStringContainsString( 'That is not a CSV file.', $this->render() );
	}

	public function test_a_file_over_the_limit_is_refused(): void {
		$limit = 1024;
		add_filter( 'upload_size_limit', static fn (): int => $limit );
		$path = $this->upload( "email,status\n" . str_repeat( "anna@example.com,undeliverable\n", 100 ) );

		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );

		$this->assertFileDoesNotExist( $path );
		$this->assertStringContainsString( 'The file is too large.', $this->render() );
	}

	public function test_a_file_php_did_not_receive_is_refused_and_kept(): void {
		$path = $this->files->lines( array( 'email,status', 'anna@example.com,undeliverable' ) );
		$this->set_file( $path, 'results.csv' );

		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );

		$this->assertFileExists( $path );
		$this->assertStringContainsString( 'No file was uploaded.', $this->render() );
	}

	public function test_a_file_without_the_status_column_is_refused(): void {
		$this->upload( "email,result\nanna@example.com,invalid\n" );

		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );

		$this->assertStringContainsString( 'The file has no column named &quot;status&quot;.', $this->render() );
	}

	public function test_a_user_who_cannot_manage_options_is_refused(): void {
		$this->log_in( 'editor' );
		$this->upload( "email,status\nanna@example.com,undeliverable\n" );

		$this->expectException( WPDieException::class );
		$this->post( SuppressionImport::PREVIEW_ACTION, 'handle_preview' );
	}

	public function test_an_upload_without_the_nonce_is_refused(): void {
		$this->upload( "email,status\nanna@example.com,undeliverable\n" );
		$_REQUEST['_wpnonce'] = 'not-a-nonce';

		$this->expectException( WPDieException::class );
		$this->import()->handle_preview();
	}

	/**
	 * Puts the contents in $_FILES as an upload.
	 *
	 * @param string $contents The file.
	 * @param string $name     The name the browser gave.
	 * @return string Where the file is.
	 */
	private function upload( string $contents, string $name = 'results.csv' ): string {
		$path            = $this->files->write( $contents );
		$this->uploads[] = $path;
		$this->set_file( $path, $name );
		return $path;
	}

	/**
	 * Fills $_FILES.
	 *
	 * @param string $path The file.
	 * @param string $name The name the browser gave.
	 */
	private function set_file( string $path, string $name ): void {
		$_FILES[ SuppressionImport::FILE_FIELD ] = array(
			'name'     => $name,
			'type'     => 'text/csv',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $path ),
		);
	}

	/**
	 * Posts the form to the handler.
	 *
	 * @param string                $action  The form's action, and its nonce's.
	 * @param string                $handler The handler's method.
	 * @param array<string, string> $fields  Other fields.
	 * @return string Where the handler redirected to.
	 */
	private function post( string $action, string $handler, array $fields = array() ): string {
		$_POST    = $fields + array( '_wpnonce' => wp_create_nonce( $action ) );
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the test posts the form.
		try {
			$this->import()->{$handler}();
		} catch ( Redirected $redirect ) {
			return $redirect->getMessage();
		}
		$this->fail( 'The handler did not redirect.' );
	}

	/** The previewed import's token, as the confirm form carries it. */
	private function token(): string {
		$this->assertSame( 1, preg_match( '/name="' . SuppressionImport::TOKEN_FIELD . '" value="([^"]+)"/', $this->render(), $found ) );
		return $found[1] ?? '';
	}

	/** The import's part of the screen. */
	private function render(): string {
		ob_start();
		$this->import()->render();
		return (string) ob_get_clean();
	}

	/** The import, treating the files this test uploaded as uploads. */
	private function import(): SuppressionImport {
		return new SuppressionImport( new Importer( $this->list ), fn ( string $path ): bool => in_array( $path, $this->uploads, true ) );
	}
}
