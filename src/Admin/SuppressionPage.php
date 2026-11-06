<?php
/**
 * The suppression list's screen, under Tools.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Admin;

use ConnectorForSendora\Suppression\SuppressionList;

/**
 * Tools → Sendora suppressions: the addresses the site no longer sends to,
 * searchable, each removable, and an import of an address verifier's
 * results. Sendora keeps a list of its own, which this one cannot clear.
 */
final class SuppressionPage {

	/** The page's slug, in its address. */
	public const SLUG = 'connector-for-sendora-suppressions';

	/** The admin-post action that removes a row. */
	public const REMOVE_ACTION = 'connector_for_sendora_remove_suppression';

	/** The query argument the page is shown with after a removal. */
	public const REMOVED_ARG = 'connector_for_sendora_removed';

	/** The query argument that holds the search. */
	public const SEARCH_ARG = 's';

	/** The query argument that holds the page number. */
	public const PAGE_ARG = 'paged';

	/** How many rows a page shows. */
	public const PER_PAGE = 50;

	/** The capability the page and a removal need. */
	private const CAPABILITY = 'manage_options';

	/**
	 * A page.
	 *
	 * @param SuppressionList   $suppression The list it shows.
	 * @param SuppressionImport $import      The import it offers.
	 */
	public function __construct( private readonly SuppressionList $suppression, private readonly SuppressionImport $import ) {
	}

	/** Hooks the page and its handler in. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::REMOVE_ACTION, array( $this, 'handle_remove' ) );
		$this->import->register();
	}

	/** Adds the page under Tools. */
	public function add_page(): void {
		add_management_page(
			__( 'Sendora suppressions', 'connector-for-sendora' ),
			__( 'Sendora suppressions', 'connector-for-sendora' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** The page's address. */
	public static function url(): string {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	/** Removes a row, then returns to the page. */
	public function handle_remove(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change the suppression list.', 'connector-for-sendora' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::REMOVE_ACTION );

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$removed = $this->suppression->remove( $id );

		wp_safe_redirect( add_query_arg( self::REMOVED_ARG, $removed ? '1' : '0', self::url() ) );
		exit;
	}

	/** Shows the page. */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a search and a page number only choose what to show.
		$search  = isset( $_GET[ self::SEARCH_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SEARCH_ARG ] ) ) : '';
		$current = isset( $_GET[ self::PAGE_ARG ] ) ? max( 1, absint( $_GET[ self::PAGE_ARG ] ) ) : 1;
		$removed = isset( $_GET[ self::REMOVED_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::REMOVED_ARG ] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$total = $this->suppression->count( $search );
		$rows  = $this->suppression->find( $search, self::PER_PAGE, ( $current - 1 ) * self::PER_PAGE );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Sendora suppressions', 'connector-for-sendora' ) . '</h1>';

		if ( '1' === $removed ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The address was removed from the suppression list.', 'connector-for-sendora' ) . '</p></div>';
		} elseif ( '0' === $removed ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That row was not on the suppression list.', 'connector-for-sendora' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'The site sends nothing to an address that bounced for good, and no broadcast to an address that complained or unsubscribed. Removing an address here sends to it again from this site.', 'connector-for-sendora' ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( "Sendora also keeps a suppression list of its own, which this screen does not change. Only Sendora's support can take an address off it after a complaint or an unsubscribe.", 'connector-for-sendora' ) . '</p></div>';

		echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '"><p class="search-box">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		printf(
			'<label class="screen-reader-text" for="connector-for-sendora-search">%s</label><input type="search" id="connector-for-sendora-search" name="%s" value="%s" />',
			esc_html__( 'Search addresses', 'connector-for-sendora' ),
			esc_attr( self::SEARCH_ARG ),
			esc_attr( $search )
		);
		submit_button( __( 'Search addresses', 'connector-for-sendora' ), '', '', false );
		echo '</p></form>';

		$this->render_table( $rows );
		$this->render_pagination( $total, $current, $search );
		$this->import->render();
		echo '</div>';
	}

	/**
	 * The rows, each with its remove button.
	 *
	 * @param list<array{id: int, email: string, reason: string, source: string, stream_id: string|null, suppressed_at: string}> $rows The rows.
	 */
	private function render_table( array $rows ): void {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Address', 'connector-for-sendora' ), __( 'Reason', 'connector-for-sendora' ), __( 'Source', 'connector-for-sendora' ), __( 'Stream', 'connector-for-sendora' ), __( 'Since', 'connector-for-sendora' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No suppressed addresses.', 'connector-for-sendora' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['email'] ) . '</td>';
			echo '<td>' . esc_html( SuppressionList::reason_label( $row['reason'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['source'] ) . '</td>';
			echo '<td>' . esc_html( $row['stream_id'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( get_date_from_gmt( $row['suppressed_at'] ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::REMOVE_ACTION ) . '" />';
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $row['id'] ) . '" />';
			wp_nonce_field( self::REMOVE_ACTION );
			submit_button( __( 'Remove', 'connector-for-sendora' ), 'small', 'submit', false );
			echo '</form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Links to the other pages of rows.
	 *
	 * @param int    $total   How many rows match.
	 * @param int    $current The page shown.
	 * @param string $search  The search.
	 */
	private function render_pagination( int $total, int $current, string $search ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		$base  = add_query_arg( array( self::SEARCH_ARG => '' === $search ? false : $search ), self::url() );
		$links = paginate_links(
			array(
				'base'    => add_query_arg( self::PAGE_ARG, '%#%', $base ),
				'format'  => '',
				'current' => $current,
				'total'   => $pages,
			)
		);
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
	}
}
