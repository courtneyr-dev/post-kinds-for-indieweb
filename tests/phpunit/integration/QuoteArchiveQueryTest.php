<?php
/**
 * Quote archive query variables and public filtering.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Quote_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuoteArchiveQueryTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Quote_Archive() )->register();
		delete_transient( Quote_Archive::INDEX_TRANSIENT );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function quote( string $title, string $author, string $work, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => '2026-09-04 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );
		add_post_meta( $id, '_pkiw_cite_author', $author );
		add_post_meta( $id, '_pkiw_cite_name', $work );

		return (int) $id;
	}

	private function archive_url(): string {
		return (string) get_term_link( 'quote', Taxonomy::TAXONOMY );
	}

	/** @return int[] */
	private function current_ids(): array {
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	public function test_all_three_query_variables_are_public(): void {
		$vars = apply_filters( 'query_vars', [] );

		$this->assertContains( Quote_Archive::VAR_AUTHOR, $vars );
		$this->assertContains( Quote_Archive::VAR_WORK, $vars );
		$this->assertContains( Quote_Archive::VAR_INITIAL, $vars );
	}

	public function test_author_and_work_filters_are_sanitized_and_case_insensitive(): void {
		$match = $this->quote( 'Match', 'Avery Quill', 'Collected Essays' );
		$this->quote( 'Other', 'Bryn Alder', 'Other Work' );

		$this->go_to( add_query_arg( Quote_Archive::VAR_AUTHOR, '<b>avery quill</b>', $this->archive_url() ) );
		$this->assertSame( [ $match ], $this->current_ids() );
		$this->assertSame( 1, (int) $GLOBALS['wp_query']->get( 'pkiw_quote_public' ) );

		$this->go_to( add_query_arg( Quote_Archive::VAR_WORK, 'COLLECTED ESSAYS', $this->archive_url() ) );
		$this->assertSame( [ $match ], $this->current_ids() );
	}

	public function test_initial_filter_uses_the_public_index_ids_and_one_character(): void {
		$riley = $this->quote( 'Riley', 'Riley Example', 'Book One' );
		$this->quote( 'Avery', 'Avery Example', 'Book Two' );

		$this->go_to( add_query_arg( Quote_Archive::VAR_INITIAL, 'rubbish', $this->archive_url() ) );

		$this->assertSame( [ $riley ], $this->current_ids() );
		$this->assertSame( [ $riley ], array_map( 'intval', (array) $GLOBALS['wp_query']->get( 'post__in' ) ) );
	}

	public function test_every_filter_excludes_normal_and_spaces_only_passwords(): void {
		$public = $this->quote( 'Public', 'Avery Example', 'Public Work' );
		$this->quote( 'Locked', 'Avery Example', 'Public Work', [ 'post_password' => 'secret' ] );
		$this->quote( 'Spaces', 'Avery Example', 'Public Work', [ 'post_password' => '   ' ] );

		foreach ( [ Quote_Archive::VAR_AUTHOR => 'Avery Example', Quote_Archive::VAR_WORK => 'Public Work', Quote_Archive::VAR_INITIAL => 'A' ] as $var => $value ) {
			$this->go_to( add_query_arg( $var, $value, $this->archive_url() ) );
			$this->assertSame( [ $public ], $this->current_ids(), $var );
			$this->assertSame( 1, (int) $GLOBALS['wp_query']->get( 'pkiw_quote_public' ), $var );
		}
	}

	public function test_filters_do_not_apply_to_other_archives_or_secondary_queries(): void {
		$quote = $this->quote( 'Quote', 'Avery Example', 'Book' );
		$note  = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$this->go_to( add_query_arg( Quote_Archive::VAR_AUTHOR, 'Avery Example', get_term_link( 'note', Taxonomy::TAXONOMY ) ) );
		$this->assertContains( $note, $this->current_ids() );

		$secondary = new WP_Query( [ 'post__in' => [ $quote ], Quote_Archive::VAR_AUTHOR => 'Nobody', 'fields' => 'ids' ] );
		$this->assertSame( [ $quote ], array_map( 'intval', $secondary->posts ) );
	}

	public function test_template_entry_sets_eight_posts_per_page_through_the_engine_plan(): void {
		foreach ( range( 1, 9 ) as $number ) {
			$this->quote( 'Quote ' . $number, 'Author ' . $number, 'Work ' . $number, [ 'post_date' => sprintf( '2026-09-%02d 10:00:00', $number ) ] );
		}

		$this->go_to( $this->archive_url() );

		$this->assertSame( Quote_Archive::PER_PAGE, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );
		$this->assertSame( Quote_Archive::PER_PAGE, $GLOBALS['wp_query']->post_count );
	}
}
