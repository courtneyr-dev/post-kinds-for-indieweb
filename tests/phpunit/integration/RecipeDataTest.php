<?php
/**
 * Recipe data ownership, fallbacks and the recipe archive's native queries (issue 229).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\WP_Recipe_Maker;
use PKIW\Recipe_Archive;
use PKIW\Taxonomy;

require_once dirname( __DIR__ ) . '/fixtures/wprm-stand-in.php';

/**
 * WP Recipe Maker owns recipe data when it is active: Post Kinds reads it at
 * render time and copies nothing into its own meta. Its yield and duration
 * fields serve sites with no supported recipe plugin, and a recipe post
 * with no recipe card. Course filters and A-Z order are ordinary WordPress
 * queries on the kind archive, so they survive pagination.
 *
 * @group integration
 */
final class RecipeDataTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		pkiw_register_wprm_stand_in_types();
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
	}

	/**
	 * A WP Recipe Maker recipe, stored the way that plugin stores one.
	 *
	 * @param array<string, mixed> $args Recipe values.
	 */
	private function recipe( array $args = [] ): int {
		$args = wp_parse_args(
			$args,
			[
				'name'     => 'Tomato Basil Soup',
				'courses'  => [ 'Soup' ],
				'prep'     => 15,
				'cook'     => 30,
				'total'    => 45,
				'servings' => 4,
				'unit'     => '',
				'picture'  => true,
			]
		);
		$id   = self::factory()->post->create(
			[
				'post_type'   => 'wprm_recipe',
				'post_title'  => $args['name'],
				'post_status' => 'publish',
			]
		);
		$meta = [
			'servings' => 'wprm_servings',
			'unit'     => 'wprm_servings_unit',
			'prep'     => 'wprm_prep_time',
			'cook'     => 'wprm_cook_time',
			'total'    => 'wprm_total_time',
		];
		foreach ( $meta as $key => $meta_key ) {
			if ( '' !== $args[ $key ] && 0 !== $args[ $key ] ) {
				update_post_meta( $id, $meta_key, $args[ $key ] );
			}
		}
		if ( $args['courses'] ) {
			wp_set_object_terms( $id, $args['courses'], 'wprm_course' );
		}
		if ( $args['picture'] ) {
			set_post_thumbnail( $id, $this->picture() );
		}

		return $id;
	}

	private function picture(): int {
		return self::factory()->attachment->create_object( 'recipe.png', 0, [ 'post_mime_type' => 'image/png' ] );
	}

	/**
	 * A published post of the recipe kind, embedding a recipe when given one.
	 *
	 * @param int                  $recipe_id Recipe to embed, or 0 for a post with no recipe card.
	 * @param array<string, mixed> $args      Post fields.
	 */
	private function recipe_post( int $recipe_id = 0, array $args = [] ): int {
		$content = '<!-- wp:paragraph --><p>Sample.</p><!-- /wp:paragraph -->';
		if ( $recipe_id ) {
			$content .= "\n\n" . '<!-- wp:wp-recipe-maker/recipe {"id":' . $recipe_id . '} -->[wprm-recipe id="' . $recipe_id . '"]<!-- /wp:wp-recipe-maker/recipe -->';
		}
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_title'   => $recipe_id ? get_the_title( $recipe_id ) : 'Campfire Chili',
					'post_content' => $content,
					'post_status'  => 'publish',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'recipe', Taxonomy::TAXONOMY );
		if ( $recipe_id ) {
			update_post_meta( $recipe_id, 'wprm_parent_post_id', $post_id );
		}

		return $post_id;
	}

	private function archive_url( array $args = [] ): string {
		return add_query_arg( $args, get_term_link( 'recipe', Taxonomy::TAXONOMY ) );
	}

	/**
	 * @return int[] IDs the main query returned for the URL, in order.
	 */
	private function main_query_ids( string $url ): array {
		$this->go_to( $url );

		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	// Metadata ownership.

	public function test_saving_a_post_with_a_recipe_copies_nothing_into_post_kinds_meta(): void {
		new WP_Recipe_Maker(); // Registers the integration's save hooks, as on a site running the recipe plugin.
		$post_id = $this->recipe_post( $this->recipe() );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_excerpt' => 'Saved again.',
			]
		);

		$this->assertSame( '', get_post_meta( $post_id, '_pkiw_recipe_yield', true ), 'Servings were copied out of the recipe.' );
		$this->assertSame( '', get_post_meta( $post_id, '_pkiw_recipe_duration', true ), 'Total time was copied out of the recipe.' );
	}

	public function test_yield_and_duration_already_stored_survive_a_save(): void {
		new WP_Recipe_Maker();
		$post_id = $this->recipe_post( $this->recipe() );
		update_post_meta( $post_id, '_pkiw_recipe_yield', '6 servings' );
		update_post_meta( $post_id, '_pkiw_recipe_duration', 'PT1H30M' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_excerpt' => 'Saved again.',
			]
		);

		$this->assertSame( '6 servings', get_post_meta( $post_id, '_pkiw_recipe_yield', true ) );
		$this->assertSame( 'PT1H30M', get_post_meta( $post_id, '_pkiw_recipe_duration', true ) );
	}

	// Supported-plugin detection.

	public function test_a_running_recipe_plugin_is_detected_and_can_be_ruled_out(): void {
		$this->assertTrue( WP_Recipe_Maker::is_supported_plugin_active() );

		add_filter( 'pkiw_recipe_plugin_active', '__return_false' );

		$this->assertFalse( WP_Recipe_Maker::is_supported_plugin_active() );
	}

	// Render-time reads.

	public function test_facts_come_from_the_recipe_when_the_page_renders(): void {
		$recipe_id = $this->recipe();
		$post_id   = $this->recipe_post( $recipe_id );

		$facts = \PKIW\recipe_facts( $post_id );

		$this->assertSame( 'wp-recipe-maker', $facts['source'] );
		$this->assertSame( $recipe_id, $facts['recipe_id'] );
		$this->assertSame( (int) get_post_thumbnail_id( $recipe_id ), $facts['image_id'] );
		$this->assertSame( 45, $facts['total_minutes'] );
		$this->assertSame( 'PT45M', $facts['duration'] );
		$this->assertSame( '4', $facts['yield'] );
		$this->assertSame( [ 'Soup' ], wp_list_pluck( $facts['courses'], 'name' ) );

		// The recipe changes; the post is not saved again.
		update_post_meta( $recipe_id, 'wprm_total_time', 50 );
		update_post_meta( $recipe_id, 'wprm_servings', 6 );
		$facts = \PKIW\recipe_facts( $post_id );

		$this->assertSame( 50, $facts['total_minutes'] );
		$this->assertSame( '6', $facts['yield'] );
	}

	public function test_the_recipe_plugin_wins_over_copies_stored_earlier(): void {
		$post_id = $this->recipe_post( $this->recipe() );
		update_post_meta( $post_id, '_pkiw_recipe_yield', '99 servings' );
		update_post_meta( $post_id, '_pkiw_recipe_duration', 'PT99M' );

		$facts = \PKIW\recipe_facts( $post_id );

		$this->assertSame( '4', $facts['yield'] );
		$this->assertSame( 45, $facts['total_minutes'] );
	}

	public function test_total_time_is_prep_plus_cook_when_the_recipe_stores_no_total(): void {
		$post_id = $this->recipe_post(
			$this->recipe(
				[
					'prep'  => 20,
					'cook'  => 25,
					'total' => 0,
				]
			)
		);

		$this->assertSame( 45, \PKIW\recipe_facts( $post_id )['total_minutes'] );
	}

	// Fallbacks.

	public function test_post_kinds_fields_serve_a_site_with_no_recipe_plugin(): void {
		add_filter( 'pkiw_recipe_plugin_active', '__return_false' );
		$post_id  = $this->recipe_post();
		$picture  = $this->picture();
		$category = self::factory()->category->create( [ 'name' => 'Soups' ] );
		set_post_thumbnail( $post_id, $picture );
		wp_set_post_categories( $post_id, [ $category ] );
		update_post_meta( $post_id, '_pkiw_recipe_yield', '6 servings' );
		update_post_meta( $post_id, '_pkiw_recipe_duration', 'PT1H30M' );

		$facts = \PKIW\recipe_facts( $post_id );

		$this->assertSame( 'post', $facts['source'] );
		$this->assertSame( 0, $facts['recipe_id'] );
		$this->assertSame( $picture, $facts['image_id'] );
		$this->assertSame( 90, $facts['total_minutes'] );
		$this->assertSame( 'PT1H30M', $facts['duration'] );
		$this->assertSame( '6 servings', $facts['yield'] );
		$this->assertSame( [ 'Soups' ], wp_list_pluck( $facts['courses'], 'name' ) );
	}

	public function test_unknown_values_are_left_out_not_guessed(): void {
		add_filter( 'pkiw_recipe_plugin_active', '__return_false' );
		$post_id = $this->recipe_post();

		$facts = \PKIW\recipe_facts( $post_id );

		$this->assertSame( 0, $facts['image_id'] );
		$this->assertSame( 0, $facts['total_minutes'] );
		$this->assertSame( '', $facts['duration'] );
		$this->assertSame( '', $facts['yield'] );
		$this->assertSame( [], $facts['courses'], 'The default category is not a course.' );
	}

	// The missing-card case.

	public function test_a_recipe_post_with_no_recipe_card_falls_back_to_the_post(): void {
		$post_id = $this->recipe_post();
		$picture = $this->picture();
		set_post_thumbnail( $post_id, $picture );
		update_post_meta( $post_id, '_pkiw_recipe_yield', '8 bowls' );

		$facts = \PKIW\recipe_facts( $post_id );

		$this->assertTrue( WP_Recipe_Maker::is_supported_plugin_active() );
		$this->assertSame( 'post', $facts['source'] );
		$this->assertSame( 0, $facts['recipe_id'] );
		$this->assertSame( $picture, $facts['image_id'] );
		$this->assertSame( '8 bowls', $facts['yield'] );
		$this->assertSame( 0, $facts['total_minutes'] );
		$this->assertSame( [], $facts['courses'], 'With a recipe plugin running, a post it holds no recipe for has no course.' );
	}

	// The fourteen local fixture cases.

	/**
	 * @dataProvider fixture_cases
	 *
	 * @param array<string, mixed>|null $recipe   Recipe values, or null for the post with no recipe card.
	 * @param array<string, mixed>      $expected Facts the post must report.
	 */
	public function test_fixture_case( ?array $recipe, array $expected ): void {
		$post_id = $this->recipe_post( null === $recipe ? 0 : $this->recipe( $recipe ) );

		$facts = \PKIW\recipe_facts( $post_id );

		$this->assertSame( $expected['source'], $facts['source'] );
		$this->assertSame( $expected['minutes'], $facts['total_minutes'] );
		$this->assertSame( $expected['duration'], $facts['duration'] );
		$this->assertSame( $expected['label'], \PKIW\recipe_time_label( $facts['total_minutes'] ) );
		$this->assertSame( $expected['yield'], $facts['yield'] );
		$this->assertSame( $expected['courses'], wp_list_pluck( $facts['courses'], 'name' ) );
		$this->assertSame( $expected['picture'], $facts['image_id'] > 0 );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>}>
	 */
	public function fixture_cases(): array {
		$case = static fn( string $name, array $courses, int $prep, int $cook, int $total, int $servings, string $unit, bool $picture ) => [
			'name'     => $name,
			'courses'  => $courses,
			'prep'     => $prep,
			'cook'     => $cook,
			'total'    => $total,
			'servings' => $servings,
			'unit'     => $unit,
			'picture'  => $picture,
		];
		$want = static fn( int $minutes, string $duration, string $label, string $yield, array $courses, bool $picture, string $source = 'wp-recipe-maker' ) => compact( 'source', 'minutes', 'duration', 'label', 'yield', 'courses', 'picture' );

		return [
			'the approved single'              => [ $case( 'Tomato Basil Soup', [ 'Soup' ], 15, 30, 45, 4, '', true ), $want( 45, 'PT45M', '45 min', '4', [ 'Soup' ], true ) ],
			'breakfast'                        => [ $case( 'Blueberry Pancakes', [ 'Breakfast' ], 10, 15, 25, 4, '', true ), $want( 25, 'PT25M', '25 min', '4', [ 'Breakfast' ], true ) ],
			'main course'                      => [ $case( 'Lemon Herb Pasta', [ 'Main Course' ], 10, 20, 30, 2, '', true ), $want( 30, 'PT30M', '30 min', '2', [ 'Main Course' ], true ) ],
			'exactly one hour'                 => [ $case( 'Peach Cobbler', [ 'Dessert' ], 15, 45, 60, 8, '', true ), $want( 60, 'PT1H', '1 hr', '8', [ 'Dessert' ], true ) ],
			'no picture, no cook time, a unit' => [ $case( 'Overnight Oats', [ 'Breakfast' ], 10, 0, 10, 2, 'jars', false ), $want( 10, 'PT10M', '10 min', '2 jars', [ 'Breakfast' ], false ) ],
			'no times and no servings'         => [ $case( 'Refrigerator Pickles', [ 'Side Dish' ], 0, 0, 0, 0, '', true ), $want( 0, '', '', '', [ 'Side Dish' ], true ) ],
			'long title, over two hours'       => [ $case( 'Slow-Roasted Tomato and White Bean Stew with Crispy Sage and Garlic Toast', [ 'Main Course' ], 20, 130, 150, 6, '', true ), $want( 150, 'PT2H30M', '2 hr 30 min', '6', [ 'Main Course' ], true ) ],
			'two courses'                      => [ $case( 'Corn Chowder', [ 'Soup', 'Main Course' ], 15, 35, 50, 6, '', true ), $want( 50, 'PT50M', '50 min', '6', [ 'Main Course', 'Soup' ], true ) ],
			'no course, no picture'            => [ $case( 'House Vinaigrette', [], 5, 0, 5, 1, 'cup', false ), $want( 5, 'PT5M', '5 min', '1 cup', [], false ) ],
			'hour and minutes, a unit'         => [ $case( 'Carrot Cake', [ 'Dessert' ], 25, 40, 65, 12, 'slices', true ), $want( 65, 'PT1H5M', '1 hr 5 min', '12 slices', [ 'Dessert' ], true ) ],
			'side dish'                        => [ $case( 'Garlic Green Beans', [ 'Side Dish' ], 5, 10, 15, 4, '', true ), $want( 15, 'PT15M', '15 min', '4', [ 'Side Dish' ], true ) ],
			'drinks'                           => [ $case( 'Hot Cocoa', [ 'Drinks' ], 5, 5, 10, 2, 'mugs', true ), $want( 10, 'PT10M', '10 min', '2 mugs', [ 'Drinks' ], true ) ],
			'snack'                            => [ $case( 'Granola Bars', [ 'Snack' ], 15, 25, 40, 12, 'bars', true ), $want( 40, 'PT40M', '40 min', '12 bars', [ 'Snack' ], true ) ],
			'a post with no recipe card'       => [ null, $want( 0, '', '', '', [], false, 'post' ) ],
		];
	}

	// Course filter: a native query on the kind archive.

	public function test_a_course_link_keeps_only_posts_whose_recipe_has_that_course(): void {
		$soup    = $this->recipe_post( $this->recipe( [ 'courses' => [ 'Soup' ] ] ) );
		$chowder = $this->recipe_post(
			$this->recipe(
				[
					'name'    => 'Corn Chowder',
					'courses' => [ 'Soup', 'Main Course' ],
				]
			)
		);
		$this->recipe_post(
			$this->recipe(
				[
					'name'    => 'Lemon Herb Pasta',
					'courses' => [ 'Main Course' ],
				]
			)
		);
		$this->recipe_post();

		$ids = $this->main_query_ids( $this->archive_url( [ Recipe_Archive::QUERY_VAR => 'soup' ] ) );

		$this->assertEqualsCanonicalizing( [ $soup, $chowder ], $ids );
		$this->assertTrue( is_tax( Taxonomy::TAXONOMY, 'recipe' ), 'The filtered view is still the recipe archive.' );
	}

	public function test_an_unknown_course_shows_no_recipes(): void {
		$this->recipe_post( $this->recipe() );

		$this->assertSame( [], $this->main_query_ids( $this->archive_url( [ Recipe_Archive::QUERY_VAR => 'no-such-course' ] ) ) );
	}

	public function test_the_course_filter_survives_pagination(): void {
		update_option( 'posts_per_page', 2 );
		$soups = [];
		foreach ( [ 'Tomato Basil Soup', 'Corn Chowder', 'Minestrone' ] as $name ) {
			$soups[] = $this->recipe_post(
				$this->recipe(
					[
						'name'    => $name,
						'courses' => [ 'Soup' ],
					]
				)
			);
		}
		$this->recipe_post(
			$this->recipe(
				[
					'name'    => 'Peach Cobbler',
					'courses' => [ 'Dessert' ],
				]
			)
		);

		$first = $this->main_query_ids( $this->archive_url( [ Recipe_Archive::QUERY_VAR => 'soup' ] ) );
		$next  = get_pagenum_link( 2, false );

		$this->assertCount( 2, $first );
		$this->assertSame( 2, (int) $GLOBALS['wp_query']->max_num_pages );
		$this->assertStringContainsString( Recipe_Archive::QUERY_VAR . '=soup', $next );
		$this->assertStringContainsString( '/page/2/', $next );

		$second = $this->main_query_ids( $next );

		$this->assertCount( 1, $second );
		$this->assertEqualsCanonicalizing( $soups, array_merge( $first, $second ) );
	}

	public function test_the_course_filter_reads_categories_on_a_site_with_no_recipe_plugin(): void {
		add_filter( 'pkiw_recipe_plugin_active', '__return_false' );
		$soups    = self::factory()->category->create( [ 'name' => 'Soups' ] );
		$desserts = self::factory()->category->create( [ 'name' => 'Desserts' ] );
		$soup     = $this->recipe_post( 0, [ 'post_title' => 'Lentil Soup' ] );
		$cobbler  = $this->recipe_post( 0, [ 'post_title' => 'Peach Cobbler' ] );
		wp_set_post_categories( $soup, [ $soups ] );
		wp_set_post_categories( $cobbler, [ $desserts ] );

		$this->assertSame( [ $soup ], $this->main_query_ids( $this->archive_url( [ Recipe_Archive::QUERY_VAR => 'soups' ] ) ) );
	}

	// A-Z: WordPress's own orderby and order.

	public function test_title_order_uses_native_query_vars_and_pages_without_repeats(): void {
		update_option( 'posts_per_page', 2 );
		$ids = [];
		foreach ( [ 'Peach Cobbler', 'Carrot Cake', 'Carrot Cake', 'Blueberry Pancakes' ] as $index => $title ) {
			$ids[ $index ] = $this->recipe_post(
				0,
				[
					'post_title' => $title,
					'post_date'  => '2026-08-0' . ( $index + 1 ) . ' 09:00:00',
				]
			);
		}
		$url = $this->archive_url(
			[
				'orderby' => 'title',
				'order'   => 'asc',
			]
		);

		$first = $this->main_query_ids( $url );
		$next  = get_pagenum_link( 2, false );

		$this->assertSame( [ $ids[3], $ids[1] ], $first, 'Titles ascend; two posts with one title keep ID order.' );
		$this->assertStringContainsString( 'orderby=title', $next );
		$this->assertStringContainsString( 'order=asc', $next );
		$this->assertSame( [ $ids[2], $ids[0] ], $this->main_query_ids( $next ) );
	}

	public function test_a_course_filter_and_title_order_travel_together_through_pagination(): void {
		update_option( 'posts_per_page', 2 );
		$ids = [];
		foreach ( [ 'Tomato Basil Soup', 'Corn Chowder', 'Minestrone' ] as $name ) {
			$ids[ $name ] = $this->recipe_post(
				$this->recipe(
					[
						'name'    => $name,
						'courses' => [ 'Soup' ],
					]
				)
			);
		}
		$this->recipe_post(
			$this->recipe(
				[
					'name'    => 'Apple Crisp',
					'courses' => [ 'Dessert' ],
				]
			)
		);
		$url = $this->archive_url(
			[
				Recipe_Archive::QUERY_VAR => 'soup',
				'orderby'                 => 'title',
				'order'                   => 'asc',
			]
		);

		$first = $this->main_query_ids( $url );
		$next  = get_pagenum_link( 2, false );

		$this->assertSame( [ $ids['Corn Chowder'], $ids['Minestrone'] ], $first );
		$this->assertStringContainsString( Recipe_Archive::QUERY_VAR . '=soup', $next );
		$this->assertStringContainsString( 'orderby=title', $next );
		$this->assertSame( [ $ids['Tomato Basil Soup'] ], $this->main_query_ids( $next ) );
	}

	// Archive navigation data.

	public function test_archive_courses_lists_the_courses_in_use_with_their_links(): void {
		$this->recipe_post( $this->recipe( [ 'courses' => [ 'Soup' ] ] ) );
		$this->recipe_post(
			$this->recipe(
				[
					'name'    => 'Corn Chowder',
					'courses' => [ 'Soup', 'Main Course' ],
				]
			)
		);
		$this->recipe_post(
			$this->recipe(
				[
					'name'    => 'Draft Tart',
					'courses' => [ 'Dessert' ],
				]
			),
			[ 'post_status' => 'draft' ]
		);

		$courses = \PKIW\recipe_archive_courses();

		$this->assertSame( [ 'Main Course', 'Soup' ], wp_list_pluck( $courses, 'name' ), 'A course only a draft uses is not offered.' );
		$this->assertSame( [ 1, 2 ], wp_list_pluck( $courses, 'count' ) );
		$this->assertSame( $this->archive_url( [ Recipe_Archive::QUERY_VAR => 'soup' ] ), $courses[1]['url'] );
		$this->assertSame( [ false, false ], wp_list_pluck( $courses, 'current' ) );

		$this->go_to( $courses[1]['url'] );

		$this->assertSame( [ false, true ], wp_list_pluck( \PKIW\recipe_archive_courses(), 'current' ) );
	}

	// Archive heading.

	public function test_the_recipe_archive_heading_is_plural(): void {
		add_filter( 'get_the_archive_title_prefix', '__return_empty_string' );
		$this->go_to( $this->archive_url() );

		$this->assertSame( 'Recipes', get_the_archive_title() );
		$this->assertSame( 'Recipe', get_term_by( 'slug', 'recipe', Taxonomy::TAXONOMY )->name, 'The term keeps its name.' );
	}

	public function test_a_renamed_recipe_term_keeps_the_name_the_site_gave_it(): void {
		add_filter( 'get_the_archive_title_prefix', '__return_empty_string' );
		$term = get_term_by( 'slug', 'recipe', Taxonomy::TAXONOMY );
		wp_update_term( $term->term_id, Taxonomy::TAXONOMY, [ 'name' => 'Receitas' ] );
		$this->go_to( $this->archive_url() );

		$this->assertSame( 'Receitas', get_the_archive_title() );
	}

	public function test_other_kind_archive_headings_are_unchanged(): void {
		add_filter( 'get_the_archive_title_prefix', '__return_empty_string' );
		$this->go_to( get_term_link( 'listen', Taxonomy::TAXONOMY ) );

		$this->assertSame( 'Listen', get_the_archive_title() );
	}

	// Stream card.

	public function test_the_stream_card_prints_course_and_time_from_the_recipe(): void {
		$post_id = $this->recipe_post( $this->recipe() );

		$html = \PKIW\render_generic_stream_card( get_post( $post_id ) );

		$this->assertStringContainsString( '<span class="pk-recipe-course">Soup</span>', $html );
		$this->assertStringContainsString( '<time class="pk-recipe-time" datetime="PT45M">45 min</time>', $html );
	}

	public function test_the_stream_card_shows_the_recipe_picture_when_the_post_has_no_featured_image(): void {
		$recipe_id = $this->recipe();
		$post_id   = $this->recipe_post( $recipe_id );
		$this->assertFalse( has_post_thumbnail( $post_id ) );

		$html = \PKIW\render_generic_stream_card( get_post( $post_id ) );

		$this->assertStringContainsString( '<div class="pk-media pk-media--stream"><img', $html );
		$this->assertStringContainsString( wp_get_attachment_url( get_post_thumbnail_id( $recipe_id ) ), $html );
		$this->assertStringContainsString( 'class="u-photo"', $html );
	}

	public function test_the_stream_card_prints_no_facts_it_does_not_have(): void {
		$post_id = $this->recipe_post();

		$html = \PKIW\render_generic_stream_card( get_post( $post_id ) );

		$this->assertStringNotContainsString( 'pk-recipe-facts', $html );
		$this->assertStringNotContainsString( 'pk-recipe-time', $html );
	}
}
