<?php
/**
 * Waiting, picked-up and unset issue lanes.
 *
 * @package PKIW
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/fixtures/issue-render-state-reset.php';

use PKIW\Issue_Archive;
use PKIW\Meta_Fields;
use PKIW\Grouping\Archive_Group;

/**
 * @group integration
 */
final class IssueLanesTest extends WP_UnitTestCase {

	use Issue_Render_State_Reset;

	/**
	 * Create a post carrying one raw issue status.
	 */
	private function ticket( string $status = '', string $password = '' ): int {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => $password ] );
		if ( '' !== $status ) {
			add_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_status', $status );
		}

		return (int) $post_id;
	}

	/**
	 * Default normalized engine settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings( array $overrides = [] ): array {
		return array_merge(
			[
				'level'        => 2,
				'label'        => '',
				'group_labels' => [],
			],
			$overrides
		);
	}

	public function test_empty_items_return_no_lane_markup(): void {
		$this->assertSame( '', Issue_Archive::lanes( new Archive_Group( 'repo', 'Repo' ), [], $this->settings() ) );
	}

	public function test_lanes_are_open_closed_none_and_keep_each_engine_item_unchanged(): void {
		$closed = $this->ticket( 'closed' );
		$none   = $this->ticket();
		$open   = $this->ticket( 'open' );
		$items  = [
			$closed => '<li data-ticket="closed">Closed item</li>',
			$none   => '<li data-ticket="none">Other item</li>',
			$open   => '<li data-ticket="open">Open item</li>',
		];

		$html = Issue_Archive::lanes( new Archive_Group( 'repo', 'Repo' ), $items, $this->settings() );
		$xpath = $this->xpath( $html );
		$order = [];
		foreach ( $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " pkiw-issue-lane ")]' ) as $lane ) {
			$order[] = $lane->getAttribute( 'data-pkiw-status' );
		}
		$this->assertSame( [ 'open', 'closed', 'none' ], $order );
		foreach ( [ 'open', 'closed', 'none' ] as $status ) {
			$tickets = [];
			foreach ( $xpath->query( '//div[@data-pkiw-status="' . $status . '"]/ul[contains(concat(" ", normalize-space(@class), " "), " pkiw-issue-lane__items ")]/li' ) as $li ) {
				$tickets[] = $li->getAttribute( 'data-ticket' );
			}
			$this->assertSame( [ $status ], $tickets, 'Each ticket sits in the lane list for its own status.' );
		}
		foreach ( $items as $item ) {
			$this->assertStringContainsString( $item, $html );
		}
		$this->assertStringContainsString( '<h3 class="pkiw-issue-lane__heading">Open</h3>', $html );
		$this->assertStringContainsString( '<h3 class="pkiw-issue-lane__heading">Closed</h3>', $html );
		$this->assertStringContainsString( '<h3 class="pkiw-issue-lane__heading">Other</h3>', $html );
	}

	public function test_only_nonempty_lanes_print_and_custom_labels_win(): void {
		$closed = $this->ticket( 'closed' );
		$html   = Issue_Archive::lanes(
			new Archive_Group( 'repo', 'Repo' ),
			[ $closed => '<li>Closed item</li>' ],
			$this->settings(
				[
					'level'        => 3,
					'label'        => 'No workflow',
					'group_labels' => [ 'open' => 'Waiting — Open', 'closed' => 'Picked up — Closed' ],
				]
			)
		);

		$this->assertStringNotContainsString( 'data-pkiw-status="open"', $html );
		$this->assertStringContainsString( 'data-pkiw-status="closed"', $html );
		$this->assertStringNotContainsString( 'data-pkiw-status="none"', $html );
		$this->assertStringContainsString( '<h4 class="pkiw-issue-lane__heading">Picked up — Closed</h4>', $html );
	}

	public function test_level_four_rack_gets_level_five_lane_heading(): void {
		$open = $this->ticket( 'open' );
		$html = Issue_Archive::lanes( new Archive_Group( 'repo', 'Repo' ), [ $open => '<li>Open</li>' ], $this->settings( [ 'level' => 4 ] ) );

		$this->assertStringContainsString( '<h5 class="pkiw-issue-lane__heading">Open</h5>', $html );
	}

	public function test_protected_open_issue_is_forced_into_the_unset_lane(): void {
		$protected = $this->ticket( 'open', 'ticket-secret' );
		$html      = Issue_Archive::lanes(
			new Archive_Group( '', '' ),
			[ $protected => '<li>Protected title</li>' ],
			$this->settings( [ 'label' => 'Issue' ] )
		);

		$this->assertStringNotContainsString( 'data-pkiw-status="open"', $html );
		$this->assertStringContainsString( 'data-pkiw-status="none"', $html );
		$this->assertStringContainsString( '>Issue</h3>', $html );
	}

	private function xpath( string $html ): DOMXPath {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $dom );
	}
}
