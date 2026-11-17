<?php
/** Shared zine integration fixtures. @package PKIW */
declare(strict_types=1);

use PKIW\Taxonomy;
use PKIW\Zine_Export;
use PKIW\Zine_Manifest;

/** Base class for zine integration tests. */
abstract class ZineTestCase extends WP_UnitTestCase {
	protected WP_Term $recipe;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		$this->recipe = get_term_by( 'slug', 'recipe', Taxonomy::TAXONOMY );
		Zine_Manifest::override_narrowed_visibility_for_tests( null );
		remove_all_filters( 'pkiw_zine_excluded_kinds' );
	}

	public function tear_down(): void {
		Zine_Manifest::override_narrowed_visibility_for_tests( null );
		Zine_Export::override_header_sink_for_tests( null );
		remove_all_filters( 'pkiw_zine_excluded_kinds' );
		parent::tear_down();
	}

	protected function post( string $title, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_title'   => $title,
					'post_status'  => 'publish',
					'post_date'    => '2025-03-09 12:00:00',
					'post_content' => '<!-- wp:paragraph --><p>' . esc_html( $title ) . ' body</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'recipe', Taxonomy::TAXONOMY );
		return $id;
	}

	protected function archive_query( int $pages = 8 ): WP_Query {
		return new WP_Query(
			PKIW\Public_Listing::query_args(
				[
					'tax_query'                   => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => [ 'recipe' ] ] ],
					'posts_per_page'              => 8 === $pages ? 6 : 14,
					'pkiw_zine_narrow_visibility' => true,
				]
			)
		);
	}

	protected function manifest( WP_Query $query, int $pages = 8 ): array {
		$result = Zine_Manifest::build( [ 'source' => 'archive', 'kind' => 'recipe', 'pages' => $pages, 'query' => $query ] );
		$this->assertIsArray( $result );
		return $result;
	}
}
