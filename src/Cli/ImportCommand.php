<?php
/**
 * The WP-CLI command that imports a verifier's results.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Cli;

use ConnectorForSendora\Import\ImportFailed;
use ConnectorForSendora\Import\Importer;
use ConnectorForSendora\Import\ImportOptions;
use ConnectorForSendora\Import\ImportPlan;
use WP_CLI;

/**
 * `wp connector-for-sendora import <csv>`.
 */
final class ImportCommand {

	/** The command's name under `wp`. */
	public const NAME = 'connector-for-sendora import';

	/**
	 * A command.
	 *
	 * @param Importer $importer What it imports with.
	 */
	public function __construct( private readonly Importer $importer ) {
	}

	/** Adds the command to WP-CLI. */
	public function register(): void {
		WP_CLI::add_command( self::NAME, $this );
	}

	/**
	 * Imports an address verifier's results into the suppression list.
	 *
	 * Reads a CSV export with a header row. Each address whose status
	 * suppresses goes on the list as a hard bounce, from an import; an
	 * address already on the list is left as it is. Without --apply, only
	 * says what it would do.
	 *
	 * ## OPTIONS
	 *
	 * <csv>
	 * : The verifier's export.
	 *
	 * [--apply]
	 * : Write to the suppression list. Without it, nothing is written.
	 *
	 * [--email-column=<name>]
	 * : The column that holds the address.
	 * ---
	 * default: email
	 * ---
	 *
	 * [--status-column=<name>]
	 * : The column that holds the verifier's status.
	 * ---
	 * default: status
	 * ---
	 *
	 * [--statuses=<list>]
	 * : The statuses that suppress, comma-separated.
	 * ---
	 * default: undeliverable,invalid
	 * ---
	 *
	 * [--verbose]
	 * : List the addresses, and the skipped rows, as well as the counts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp connector-for-sendora import results.csv
	 *     wp connector-for-sendora import results.csv --status-column="ZB Status" --statuses=invalid --apply
	 *
	 * @param list<string>          $args       The file.
	 * @param array<string, string> $assoc_args The options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$options = ImportOptions::from_text(
			(string) ( $assoc_args['email-column'] ?? '' ),
			(string) ( $assoc_args['status-column'] ?? '' ),
			(string) ( $assoc_args['statuses'] ?? '' )
		);
		$apply   = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'apply', false );
		$verbose = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );

		try {
			$plan = $this->importer->plan( $args[0], $options );
		} catch ( ImportFailed $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$applied = $apply ? $this->importer->apply( $plan->suppress ) : null;
		foreach ( $plan->summary( $applied ) as $line ) {
			WP_CLI::log( $line );
		}
		if ( $verbose ) {
			$this->list_addresses( $plan, $applied );
		}

		if ( null === $applied ) {
			WP_CLI::success( __( 'Dry run: nothing was written. Run again with --apply to write.', 'connector-for-sendora' ) );
		} else {
			WP_CLI::success( __( 'Imported.', 'connector-for-sendora' ) );
		}
	}

	/**
	 * Lists each address under what happened to it.
	 *
	 * @param ImportPlan                                                  $plan    The plan.
	 * @param array{suppressed: list<string>, already: list<string>}|null $applied What the import did; null for a dry run.
	 */
	private function list_addresses( ImportPlan $plan, ?array $applied ): void {
		$groups = array(
			null === $applied ? __( 'Would suppress:', 'connector-for-sendora' ) : __( 'Suppressed:', 'connector-for-sendora' ) => $applied['suppressed'] ?? $plan->suppress,
			__( 'Already on the list:', 'connector-for-sendora' ) => array_merge( $plan->already, $applied['already'] ?? array() ),
		);
		foreach ( $groups as $heading => $emails ) {
			if ( array() === $emails ) {
				continue;
			}
			WP_CLI::log( $heading );
			foreach ( $emails as $email ) {
				WP_CLI::log( '  ' . $email );
			}
		}
		foreach ( ImportPlan::SKIP_REASONS as $reason ) {
			$rows = $plan->skipped[ $reason ] ?? array();
			if ( array() === $rows ) {
				continue;
			}
			/* translators: %s: why the rows were skipped. */
			WP_CLI::log( sprintf( __( 'Skipped, %s:', 'connector-for-sendora' ), ImportPlan::skip_label( $reason ) ) );
			foreach ( $rows as $row ) {
				/* translators: 1: a row number, 2: the row's address, if any. */
				WP_CLI::log( sprintf( __( '  row %1$d: %2$s', 'connector-for-sendora' ), $row['row'], $row['email'] ) );
			}
		}
	}
}
