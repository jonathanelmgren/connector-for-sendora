<?php
/**
 * Tests for importing an address verifier's results.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Integration;

use ConnectorForSendora\Import\ImportFailed;
use ConnectorForSendora\Import\Importer;
use ConnectorForSendora\Import\ImportOptions;
use ConnectorForSendora\Import\ImportPlan;
use ConnectorForSendora\Suppression\SuppressionList;
use ConnectorForSendora\Tests\Support\CsvFiles;
use DateTimeImmutable;

/**
 * Reads verifier exports against the suppression list.
 */
final class ImporterTest extends TestCase {

	private SuppressionList $list;

	private Importer $importer;

	private CsvFiles $files;

	public function set_up(): void {
		parent::set_up();
		$this->list     = new SuppressionList();
		$this->importer = new Importer( $this->list );
		$this->files    = new CsvFiles();
	}

	public function tear_down(): void {
		$this->files->delete_all();
		parent::tear_down();
	}

	public function test_a_dry_run_counts_and_writes_nothing(): void {
		$plan = $this->plan(
			array(
				'email,status',
				'anna@example.com,undeliverable',
				'bo@example.org,deliverable',
				'cecilia@example.net,invalid',
			)
		);

		$this->assertSame( 3, $plan->rows_read );
		$this->assertSame( array( 'anna@example.com', 'cecilia@example.net' ), $plan->suppress );
		$this->assertSame( array(), $plan->already );
		$this->assertSame( 1, $plan->skipped_counts()[ ImportPlan::SKIP_STATUS ] );
		$this->assertSame( 0, $this->list->count( '' ) );
	}

	public function test_apply_adds_hard_bounces_from_an_import(): void {
		$plan   = $this->plan( array( 'email,status', 'Anna@Example.com,undeliverable' ) );
		$result = $this->importer->apply( $plan->suppress );

		$this->assertSame( array( 'anna@example.com' ), $result['suppressed'] );
		$row = $this->list->find( 'anna@example.com', 1, 0 )[0];
		$this->assertSame( SuppressionList::BOUNCED, $row['reason'] );
		$this->assertSame( SuppressionList::SOURCE_IMPORT, $row['source'] );
		$this->assertNull( $row['stream_id'] );
	}

	public function test_an_address_already_on_the_list_is_left_as_it_is(): void {
		$since = new DateTimeImmutable( '2026-01-02 03:04:05', new \DateTimeZone( 'UTC' ) );
		$this->list->add( 'anna@example.com', SuppressionList::BOUNCED, SuppressionList::SOURCE_WEBHOOK, 'str_1', $since );

		$plan   = $this->plan( array( 'email,status', 'anna@example.com,undeliverable', 'bo@example.org,undeliverable' ) );
		$result = $this->importer->apply( $plan->suppress );

		$this->assertSame( array( 'anna@example.com' ), $plan->already );
		$this->assertSame( array( 'bo@example.org' ), $result['suppressed'] );
		$row = $this->list->find( 'anna@example.com', 1, 0 )[0];
		$this->assertSame( SuppressionList::SOURCE_WEBHOOK, $row['source'] );
		$this->assertSame( 'str_1', $row['stream_id'] );
		$this->assertSame( '2026-01-02 03:04:05', $row['suppressed_at'] );
	}

	public function test_an_address_on_the_list_for_another_reason_also_becomes_a_hard_bounce(): void {
		$this->list->add( 'anna@example.com', SuppressionList::UNSUBSCRIBED, SuppressionList::SOURCE_WEBHOOK );

		$plan = $this->plan( array( 'email,status', 'anna@example.com,invalid' ) );
		$this->importer->apply( $plan->suppress );

		$this->assertSame( array( 'anna@example.com' ), $plan->suppress );
		$this->assertSame( 2, $this->list->count( 'anna@example.com' ) );
	}

	public function test_importing_the_same_file_again_changes_nothing(): void {
		$lines = array( 'email,status', 'anna@example.com,undeliverable', 'bo@example.org,invalid' );
		$this->importer->apply( $this->plan( $lines )->suppress );
		$before = $this->list->find( '', 10, 0 );

		$again  = $this->plan( $lines );
		$result = $this->importer->apply( $again->suppress );

		$this->assertSame( array(), $again->suppress );
		$this->assertSame( array( 'anna@example.com', 'bo@example.org' ), $again->already );
		$this->assertSame( array(), $result['suppressed'] );
		$this->assertSame( $before, $this->list->find( '', 10, 0 ) );
	}

	public function test_an_address_added_between_the_dry_run_and_apply_is_left_as_it_is(): void {
		$plan = $this->plan( array( 'email,status', 'anna@example.com,undeliverable' ) );
		$this->list->add( 'anna@example.com', SuppressionList::BOUNCED, SuppressionList::SOURCE_WEBHOOK );

		$result = $this->importer->apply( $plan->suppress );

		$this->assertSame( array(), $result['suppressed'] );
		$this->assertSame( array( 'anna@example.com' ), $result['already'] );
		$this->assertSame( SuppressionList::SOURCE_WEBHOOK, $this->list->find( 'anna@example.com', 1, 0 )[0]['source'] );
	}

	/**
	 * Header rows as verifiers and spreadsheets write them.
	 *
	 * @return array<string, array{0: string, 1: ImportOptions}>
	 */
	public static function header_variants(): array {
		$default = new ImportOptions();
		return array(
			'upper case, spaces'                 => array( " EMAIL , Status \nanna@example.com,undeliverable\n", $default ),
			'quoted'                             => array( "\"email\",\"status\"\n\"anna@example.com\",\"undeliverable\"\n", $default ),
			'other columns first'                => array( "id,name,status,email\n7,Anna,undeliverable,anna@example.com\n", $default ),
			'semicolons'                         => array( "email;status;reason\nanna@example.com;undeliverable;rejected_email\n", $default ),
			'tabs'                               => array( "email\tstatus\nanna@example.com\tundeliverable\n", $default ),
			'CRLF and blank lines'               => array( "email,status\r\n\r\nanna@example.com,undeliverable\r\n\r\n", $default ),
			'a quoted comma in a field'          => array( "name,email,status\n\"Svensson, Anna\",anna@example.com,undeliverable\n", $default ),
			'ZeroBounce'                         => array( "Email Address,First Name,ZB Status,ZB Sub Status\nanna@example.com,Anna,Invalid,mailbox_not_found\n", new ImportOptions( 'Email Address', 'zb status', array( 'invalid' ) ) ),
			'BOM'                                => array( CsvFiles::BOM . "email,status\nanna@example.com,undeliverable\n", $default ),
			'BOM before a quoted header'         => array( CsvFiles::BOM . "\"email\",\"status\"\nanna@example.com,undeliverable\n", $default ),
			'semicolons in a quoted column name' => array( "email,status,\"Notes; a; b; c\"\nanna@example.com,undeliverable,x\n", $default ),
			'commas in a quoted column name'     => array( "email;status;\"Notes, a, b, c\"\nanna@example.com;undeliverable;x\n", $default ),
			'a column name spanning lines'       => array( "email,\"Sub\nStatus\",status\nanna@example.com,mailbox_not_found,undeliverable\n", $default ),
		);
	}

	/**
	 * Every variant finds the address.
	 *
	 * @dataProvider header_variants
	 *
	 * @param string        $contents The file.
	 * @param ImportOptions $options  The options.
	 */
	public function test_a_header_variant_is_read( string $contents, ImportOptions $options ): void {
		$plan = $this->importer->plan( $this->files->write( $contents ), $options );

		$this->assertSame( 1, $plan->rows_read );
		$this->assertSame( array( 'anna@example.com' ), $plan->suppress );
	}

	public function test_statuses_are_matched_whatever_their_case_and_spaces(): void {
		$plan = $this->plan(
			array( 'email,status', 'anna@example.com, Undeliverable ', 'bo@example.org,INVALID', 'cecilia@example.net,risky', 'dan@example.com,' ),
		);

		$this->assertSame( array( 'anna@example.com', 'bo@example.org' ), $plan->suppress );
		$this->assertSame( 2, $plan->skipped_counts()[ ImportPlan::SKIP_STATUS ] );
	}

	public function test_the_statuses_that_suppress_can_be_named(): void {
		$plan = $this->importer->plan(
			$this->files->lines( array( 'email,status', 'anna@example.com,Do Not Mail', 'bo@example.org,spamtrap', 'cecilia@example.net,invalid' ) ),
			ImportOptions::from_text( '', '', 'do not mail, spamtrap' )
		);

		$this->assertSame( array( 'anna@example.com', 'bo@example.org' ), $plan->suppress );
	}

	public function test_rows_without_a_valid_address_or_repeated_are_skipped_with_why(): void {
		$plan = $this->importer->plan(
			$this->files->lines(
				array(
					'email,status',
					',undeliverable',
					'not-an-address,undeliverable',
					'anna@example.com,undeliverable',
					'ANNA@example.com,invalid',
					'bo@example.org',
				)
			),
			new ImportOptions(),
			true
		);

		$this->assertSame( 5, $plan->rows_read );
		$this->assertSame( array( 'anna@example.com' ), $plan->suppress );
		$this->assertSame(
			array(
				ImportPlan::SKIP_STATUS          => 1,
				ImportPlan::SKIP_NO_ADDRESS      => 1,
				ImportPlan::SKIP_INVALID_ADDRESS => 1,
				ImportPlan::SKIP_REPEATED        => 1,
			),
			$plan->skipped_counts()
		);
		$this->assertSame(
			array(
				'row'   => 3,
				'email' => 'not-an-address',
			),
			$plan->skipped_rows[ ImportPlan::SKIP_INVALID_ADDRESS ][0]
		);
	}

	public function test_skipped_rows_are_only_counted_unless_asked_for(): void {
		$plan = $this->plan( array( 'email,status', 'not-an-address,undeliverable', 'bo@example.org,valid' ) );

		$this->assertSame( 1, $plan->skipped_counts()[ ImportPlan::SKIP_INVALID_ADDRESS ] );
		$this->assertSame( 1, $plan->skipped_counts()[ ImportPlan::SKIP_STATUS ] );
		$this->assertSame( array(), $plan->skipped_rows );
	}

	public function test_a_row_number_counts_blank_records(): void {
		$plan = $this->importer->plan( $this->files->lines( array( 'email,status', '', 'not-an-address,invalid' ) ), new ImportOptions(), true );

		$this->assertSame( 1, $plan->rows_read );
		$this->assertSame( 3, $plan->skipped_rows[ ImportPlan::SKIP_INVALID_ADDRESS ][0]['row'] );
	}

	public function test_the_summary_gives_counts_and_no_address(): void {
		$this->list->add( 'bo@example.org', SuppressionList::BOUNCED, SuppressionList::SOURCE_WEBHOOK );
		$plan = $this->plan( array( 'email,status', 'anna@example.com,invalid', 'bo@example.org,invalid', 'cecilia@example.net,valid' ) );

		$summary = implode( "\n", $plan->summary() );

		$this->assertSame( "3 rows read.\n1 address would be suppressed.\n1 address already on the list.\n1 row skipped: status does not suppress.", $summary );
		$this->assertStringNotContainsString( '@', $summary );
		$this->assertStringContainsString( '1 address suppressed.', implode( "\n", $plan->summary( $this->importer->apply( $plan->suppress ) ) ) );
	}

	public function test_a_missing_column_is_refused_with_the_columns_the_file_has(): void {
		$this->expectException( ImportFailed::class );
		$this->expectExceptionMessage( 'The file has no column named "status". Its columns are: email, result.' );

		$this->plan( array( 'email,result', 'anna@example.com,invalid' ) );
	}

	public function test_an_empty_file_is_refused(): void {
		$this->expectException( ImportFailed::class );
		$this->expectExceptionMessage( 'header row' );

		$this->importer->plan( $this->files->write( CsvFiles::BOM ), new ImportOptions() );
	}

	public function test_a_missing_file_is_refused(): void {
		$this->expectException( ImportFailed::class );

		$this->importer->plan( sys_get_temp_dir() . '/cfs-no-such-file.csv', new ImportOptions() );
	}

	/**
	 * A plan for a file of these lines, with the default options.
	 *
	 * @param list<string> $lines The lines.
	 */
	private function plan( array $lines ): ImportPlan {
		return $this->importer->plan( $this->files->lines( $lines ), new ImportOptions() );
	}
}
