<?php
/**
 * Privacy boundaries shared by every quote surface.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Quote_Abilities;
use PKIW\Quote_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuotePrivacyTest extends WP_UnitTestCase {

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Quote_Archive() )->register();
		delete_transient( Quote_Archive::INDEX_TRANSIENT );
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Entry Author' ] );
		wp_set_current_user( 0 );
	}

	private function quote( array $args = [], string $author = 'Public Example', string $work = 'Public Work' ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Quote fixture',
					'post_author'  => $this->author_id,
					'post_content' => '<!-- wp:quote --><blockquote><p>Public quote words.</p><cite>Public Cite</cite></blockquote><!-- /wp:quote -->',
					'post_date'    => '2026-09-04 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );
		add_post_meta( $id, '_pkiw_cite_author', $author );
		add_post_meta( $id, '_pkiw_cite_name', $work );
		add_post_meta( $id, '_pkiw_cite_url', 'https://example.com/work' );
		add_post_meta( $id, '_pkiw_cite_author_url', 'https://secret.example.com/author' );

		return (int) $id;
	}

	private function render( string $name ): string {
		return render_block( [ 'blockName' => $name, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] );
	}

	public function test_password_quote_is_absent_from_index_ability_tabs_and_works(): void {
		$this->quote();
		$this->quote( [ 'post_title' => 'Locked title', 'post_password' => 'secret' ], 'Locked Example', 'Locked Work' );
		$index = Quote_Archive::index();
		$this->assertSame( [ 'Public Example' ], array_column( $index['authors'], 'name' ) );
		$this->assertSame( [ 'Public Work' ], array_column( $index['works'], 'name' ) );
		$this->assertSame( 1, $index['total'] );

		$output = Quote_Abilities::instance()->execute_get_quote_index( [] );
		$this->assertStringNotContainsString( 'Locked', (string) wp_json_encode( $output ) );
		$this->assertStringNotContainsString( 'Locked', $this->render( Quote_Archive::AUTHORS_BLOCK ) . $this->render( Quote_Archive::WORKS_BLOCK ) );
	}

	public function test_password_quote_files_under_other_and_its_card_is_title_only(): void {
		$id    = $this->quote( [ 'post_title' => 'Locked title', 'post_password' => 'secret' ], 'Locked Example', 'Locked Work' );
		$group = Quote_Archive::source()->group_of( get_post( $id ) );
		$html  = Quote_Archive::entry_html( get_post( $id ) );

		$this->assertTrue( $group->is_empty() );
		$this->assertStringContainsString( 'Protected: Locked title', wp_strip_all_tags( $html ) );
		$this->assertStringNotContainsString( 'Public quote words', $html );
		$this->assertStringNotContainsString( 'Locked Example', $html );
		$this->assertStringNotContainsString( 'Locked Work', $html );
		$this->assertStringNotContainsString( 'p-author', $html );
		$this->assertStringNotContainsString( 'h-cite', $html );
	}

	public function test_registered_ability_runs_through_wp_ability_execute_without_leaking_a_password_quote(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version (needs 6.9+).' );
		}
		$this->quote();
		$this->quote( [ 'post_password' => 'secret' ], 'Locked Example', 'Locked Work' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$ability = wp_get_ability( 'post-kinds/get-quote-index' );
		$this->assertNotNull( $ability );
		foreach ( [ null, [] ] as $input ) {
			$result = null === $input ? $ability->execute() : $ability->execute( $input );
			$this->assertIsArray( $result );
			$this->assertSame( 1, $result['total'] );
			$this->assertSame( [ 'authors', 'initials', 'works', 'total' ], array_keys( $result ) );
			$this->assertStringNotContainsString( 'Locked', (string) wp_json_encode( $result ) );
			$this->assertStringNotContainsString( '"ids"', (string) wp_json_encode( $result ) );
		}
	}

	public function test_spaces_only_password_is_not_public(): void {
		$this->quote( [ 'post_password' => '   ' ], 'Spaces Example', 'Spaces Work' );

		$this->assertSame( 0, Quote_Archive::index()['total'] );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public function non_public_guards(): array {
		return [
			'draft'   => [ [ 'post_status' => 'draft' ], 'Draft Example' ],
			'private' => [ [ 'post_status' => 'private' ], 'Private Example' ],
			'future'  => [ [ 'post_status' => 'future', 'post_date' => '2036-09-04 10:00:00' ], 'Future Example' ],
		];
	}

	/**
	 * @dataProvider non_public_guards
	 *
	 * @param array<string, mixed> $args Post arguments.
	 */
	public function test_each_non_publish_status_is_absent_from_all_aggregates( array $args, string $author ): void {
		$this->quote( $args, $author, $author . ' Work' );

		$this->assertSame( 0, Quote_Archive::index()['total'] );
		$this->assertStringNotContainsString( $author, (string) wp_json_encode( Quote_Abilities::instance()->execute_get_quote_index( [] ) ) );
	}

	public function test_trashed_quote_is_absent_from_all_aggregates(): void {
		$id = $this->quote( [], 'Trashed Example', 'Trashed Work' );
		wp_trash_post( $id );

		$this->assertSame( 0, Quote_Archive::index()['total'] );
	}

	public function test_non_quote_with_cite_meta_is_absent_from_all_aggregates(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		add_post_meta( $id, '_pkiw_cite_author', 'Note Example' );
		add_post_meta( $id, '_pkiw_cite_name', 'Note Work' );

		$this->assertSame( 0, Quote_Archive::index()['total'] );
	}

	public function test_filtered_archive_excludes_locked_matches(): void {
		$public = $this->quote();
		$locked = $this->quote( [ 'post_password' => 'secret' ], 'Public Example', 'Public Work' );
		$query  = new WP_Query(
			[
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'fields'            => 'ids',
				'posts_per_page'    => -1,
				'pkiw_quote_public' => 1,
				'meta_query'        => [ [ 'key' => '_pkiw_cite_author', 'value' => 'Public Example' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$this->assertContains( $public, array_map( 'intval', $query->posts ) );
		$this->assertNotContains( $locked, array_map( 'intval', $query->posts ) );
	}

	public function test_facts_have_no_location_and_never_expose_author_url(): void {
		$id = $this->quote();
		add_post_meta( $id, '_pkiw_geo_latitude', '40.0000' );
		add_post_meta( $id, '_pkiw_checkin_name', 'Private Place' );
		$facts = Quote_Archive::facts( $id );
		$html  = Quote_Archive::entry_html( get_post( $id ) );

		foreach ( array_keys( $facts ) as $key ) {
			$this->assertStringNotContainsString( 'location', $key );
			$this->assertStringNotContainsString( 'latitude', $key );
		}
		$this->assertStringNotContainsString( 'Private Place', (string) wp_json_encode( $facts ) . $html );
		$this->assertStringNotContainsString( 'secret.example.com', (string) wp_json_encode( $facts ) . $html );
	}
}
