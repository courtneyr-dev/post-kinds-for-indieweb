<?php
/**
 * Public issue facts reader.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Issue_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class IssueFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Issue_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $args Post overrides.
	 * @param array<string, mixed> $meta Issue meta values by suffix.
	 */
	private function issue( array $args = [], array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Export drops the last row',
					'post_content' => '<p>Ticket body.</p>',
					'post_date'    => '2026-07-24 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'issue', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}

		return (int) $post_id;
	}

	public function test_titled_issue_returns_the_complete_ordered_fact_set(): void {
		$post_id = $this->issue(
			[],
			[
				'cite_url'    => 'https://a.example.net/acorn-ledger',
				'cite_name'   => 'acorn-ledger',
				'issue_number' => '101',
				'issue_status' => 'open',
			]
		);
		$facts = Issue_Archive::facts( $post_id );

		$this->assertSame(
			[ 'name', 'name_is_title', 'kind_label', 'repo_url', 'repo_name', 'repo_label', 'number', 'number_text', 'status', 'status_label', 'date_iso', 'date_display', 'has_facts' ],
			array_keys( $facts )
		);
		$this->assertSame( 'Export drops the last row', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );
		$this->assertSame( 'Issue', $facts['kind_label'] );
		$this->assertSame( 'https://a.example.net/acorn-ledger', $facts['repo_url'] );
		$this->assertSame( 'acorn-ledger', $facts['repo_name'] );
		$this->assertSame( 'acorn-ledger', $facts['repo_label'] );
		$this->assertSame( '101', $facts['number'] );
		$this->assertSame( '#101', $facts['number_text'] );
		$this->assertSame( 'open', $facts['status'] );
		$this->assertSame( 'Open', $facts['status_label'] );
		$this->assertSame( 'July 24, 2026', $facts['date_display'] );
		$this->assertTrue( $facts['has_facts'] );
		$this->assertArrayNotHasKey( 'location', $facts );
	}

	public function test_protected_issue_facts_are_hidden_from_a_logged_out_visitor(): void {
		$post_id = $this->issue(
			[ 'post_password' => 'ticket-secret' ],
			[ 'cite_name' => 'acorn-ledger', 'issue_number' => '110', 'issue_status' => 'open' ]
		);

		$this->assertTrue( post_password_required( $post_id ) );
		$this->assertSame( [], Issue_Archive::facts( $post_id ) );
	}

	public function test_no_meta_and_legacy_content_have_no_structured_issue_facts(): void {
		$plain  = $this->issue();
		$legacy = $this->issue(
			[
				'post_title'   => '',
				'post_content' => '<p>Issue for <a class="u-in-reply-to" href="https://a.example.net/acorn-ledger">acorn-ledger</a></p>',
			]
		);

		$this->assertFalse( Issue_Archive::facts( $plain )['has_facts'] );
		$this->assertFalse( Issue_Archive::facts( $legacy )['has_facts'] );
	}

	public function test_repository_label_falls_back_to_the_valid_url(): void {
		$post_id = $this->issue( [], [ 'cite_url' => 'https://a.example.net/acorn-ledger' ] );

		$this->assertSame( 'https://a.example.net/acorn-ledger', Issue_Archive::facts( $post_id )['repo_label'] );
	}

	public function test_untitled_issue_gets_a_nonempty_fallback_name_without_calling_it_a_title(): void {
		$post_id = $this->issue( [ 'post_title' => '' ] );
		$facts   = Issue_Archive::facts( $post_id );

		$this->assertNotSame( '', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'] );
	}

	public function test_kind_label_filter_can_override_the_issue_label(): void {
		$post_id = $this->issue();
		$filter  = static fn( string $label, string $kind ): string => 'issue' === $kind ? 'Ticket' : $label;
		add_filter( 'pkiw_kind_label', $filter, 10, 2 );
		try {
			$this->assertSame( 'Ticket', Issue_Archive::facts( $post_id )['kind_label'] );
		} finally {
			remove_filter( 'pkiw_kind_label', $filter, 10 );
		}
	}

	public function test_public_kind_facts_door_returns_the_issue_reader_result(): void {
		$post_id = $this->issue( [], [ 'issue_number' => '101' ] );

		$this->assertSame( Issue_Archive::facts( $post_id ), \PKIW\kind_facts( $post_id ) );
	}

	public function test_another_kind_with_issue_meta_has_no_issue_facts(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'note', Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_number', '101' );

		$this->assertSame( [], Issue_Archive::facts( $post_id ) );
	}
}
