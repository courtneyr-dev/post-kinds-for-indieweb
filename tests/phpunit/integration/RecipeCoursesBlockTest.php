<?php
/**
 * The Recipe courses block and the editor preview of kind archive templates (issue 229).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Recipe_Archive;
use PKIW\Taxonomy;

require_once dirname( __DIR__ ) . '/fixtures/wprm-stand-in.php';

/**
 * The course links on the recipe archive are a server-rendered block, so the
 * Site Editor prints the links the front end prints. The Site Editor also
 * loads the script that previews a kind's archive template with that kind's
 * posts.
 *
 * @group integration
 */
final class RecipeCoursesBlockTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		pkiw_register_wprm_stand_in_types();
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
	}

	/**
	 * A published recipe post whose WP Recipe Maker recipe sits in the given courses.
	 *
	 * @param string   $name    Recipe and post title.
	 * @param string[] $courses Course names.
	 */
	private function recipe_post( string $name, array $courses ): int {
		$recipe_id = self::factory()->post->create(
			[
				'post_type'   => 'wprm_recipe',
				'post_title'  => $name,
				'post_status' => 'publish',
			]
		);
		wp_set_object_terms( $recipe_id, $courses, 'wprm_course' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => $name,
				'post_content' => '<!-- wp:wp-recipe-maker/recipe {"id":' . $recipe_id . '} -->[wprm-recipe id="' . $recipe_id . '"]<!-- /wp:wp-recipe-maker/recipe -->',
				'post_status'  => 'publish',
			]
		);
		wp_set_object_terms( $post_id, 'recipe', Taxonomy::TAXONOMY );
		update_post_meta( $recipe_id, 'wprm_parent_post_id', $post_id );

		return $post_id;
	}

	private function archive_url(): string {
		return (string) get_term_link( get_term_by( 'slug', 'recipe', Taxonomy::TAXONOMY ) );
	}

	/**
	 * Render the block as a template would.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function render( array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => Recipe_Archive::COURSES_BLOCK,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * The block's links in document order.
	 *
	 * @return array<int, array{label: string, href: string, current: string}>
	 */
	private function links( string $html ): array {
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$links = [];
		foreach ( ( new DOMXPath( $dom ) )->query( '//nav/ul/li/a' ) as $a ) {
			$links[] = [
				'label'   => trim( $a->textContent ),
				'href'    => $a->getAttribute( 'href' ),
				'current' => $a->getAttribute( 'aria-current' ),
			];
		}

		return $links;
	}

	public function test_the_block_is_registered_with_an_editor_script(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Recipe_Archive::COURSES_BLOCK );

		$this->assertNotNull( $block );
		$this->assertContains( 'pkiw-recipe-courses-editor', $block->editor_script_handles );
		$this->assertTrue( wp_script_is( 'pkiw-recipe-courses-editor', 'registered' ) );
	}

	public function test_it_links_all_then_each_course_in_use_then_the_title_index(): void {
		$this->recipe_post( 'Tomato Basil Soup', [ 'Soup' ] );
		$this->recipe_post( 'Blueberry Pancakes', [ 'Breakfast' ] );
		$base = $this->archive_url();
		$this->go_to( $base );

		$this->assertSame(
			[
				[
					'label'   => 'All',
					'href'    => $base,
					'current' => 'page',
				],
				[
					'label'   => 'Breakfast',
					'href'    => add_query_arg( Recipe_Archive::QUERY_VAR, 'breakfast', $base ),
					'current' => '',
				],
				[
					'label'   => 'Soup',
					'href'    => add_query_arg( Recipe_Archive::QUERY_VAR, 'soup', $base ),
					'current' => '',
				],
				[
					'label'   => 'A–Z index',
					'href'    => add_query_arg(
						[
							'orderby' => 'title',
							'order'   => 'asc',
						],
						$base
					),
					'current' => '',
				],
			],
			$this->links( $this->render() )
		);
	}

	public function test_the_course_being_filtered_is_the_current_link(): void {
		$this->recipe_post( 'Tomato Basil Soup', [ 'Soup' ] );
		$this->recipe_post( 'Blueberry Pancakes', [ 'Breakfast' ] );
		$this->go_to( add_query_arg( Recipe_Archive::QUERY_VAR, 'soup', $this->archive_url() ) );

		$this->assertSame(
			[ 'Soup' ],
			array_values( wp_list_pluck( wp_list_filter( $this->links( $this->render() ), [ 'current' => 'page' ] ), 'label' ) )
		);
	}

	public function test_title_order_makes_the_index_the_current_link(): void {
		$this->recipe_post( 'Tomato Basil Soup', [ 'Soup' ] );
		$this->go_to(
			add_query_arg(
				[
					'orderby' => 'title',
					'order'   => 'asc',
				],
				$this->archive_url()
			)
		);

		$this->assertSame(
			[ 'A–Z index' ],
			array_values( wp_list_pluck( wp_list_filter( $this->links( $this->render() ), [ 'current' => 'page' ] ), 'label' ) )
		);
	}

	public function test_it_is_one_labelled_nav_that_carries_the_class_name_it_is_given(): void {
		$this->recipe_post( 'Tomato Basil Soup', [ 'Soup' ] );
		$this->go_to( $this->archive_url() );

		$html = $this->render( [ 'className' => 'example-tabs' ] );

		$this->assertSame( 1, substr_count( $html, '<nav ' ) );
		$this->assertMatchesRegularExpression( '/^<nav [^>]*aria-label="Recipe courses"/', $html );
		$this->assertMatchesRegularExpression( '/^<nav [^>]*class="[^"]*\bwp-block-post-kinds-indieweb-recipe-courses\b[^"]*"/', $html );
		$this->assertMatchesRegularExpression( '/^<nav [^>]*class="[^"]*\bexample-tabs\b[^"]*"/', $html );
		$this->assertStringNotContainsString( '<button', $html );
	}

	public function test_with_no_course_in_use_it_still_links_all_and_the_title_index(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'recipe', Taxonomy::TAXONOMY );
		$this->go_to( $this->archive_url() );

		$this->assertSame( [ 'All', 'A–Z index' ], wp_list_pluck( $this->links( $this->render() ), 'label' ) );
	}

	public function test_away_from_the_recipe_archive_no_link_is_current(): void {
		$this->recipe_post( 'Tomato Basil Soup', [ 'Soup' ] );
		$this->go_to( home_url( '/' ) );

		$links = $this->links( $this->render() );

		$this->assertSame( [ 'All', 'Soup', 'A–Z index' ], wp_list_pluck( $links, 'label' ) );
		$this->assertSame( [], wp_list_filter( $links, [ 'current' => 'page' ] ) );
	}

	public function test_the_editor_render_shows_the_archive_as_it_opens(): void {
		$this->recipe_post( 'Tomato Basil Soup', [ 'Soup' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Recipe_Archive::COURSES_BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'attributes', [ 'className' => 'example-tabs' ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$links = $this->links( (string) $response->get_data()['rendered'] );
		$this->assertSame( [ 'All', 'Soup', 'A–Z index' ], wp_list_pluck( $links, 'label' ) );
		$this->assertSame( [ 'All' ], array_values( wp_list_pluck( wp_list_filter( $links, [ 'current' => 'page' ] ), 'label' ) ) );
		$this->assertStringContainsString( 'example-tabs', (string) $response->get_data()['rendered'] );
	}

	public function test_a_render_after_the_editor_request_is_not_treated_as_a_preview(): void {
		$this->recipe_post( 'Tomato Basil Soup', [ 'Soup' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Recipe_Archive::COURSES_BLOCK );
		$request->set_param( 'context', 'edit' );
		rest_get_server()->dispatch( $request );

		$this->go_to( home_url( '/' ) );

		$this->assertSame( [], wp_list_filter( $this->links( $this->render() ), [ 'current' => 'page' ] ) );
	}

	public function test_the_site_editor_loads_the_kind_template_preview_script(): void {
		do_action( 'enqueue_block_editor_assets' );

		$this->assertTrue( wp_script_is( 'pkiw-kind-template-preview', 'enqueued' ) );
		$deps = wp_scripts()->registered['pkiw-kind-template-preview']->deps;
		foreach ( [ 'wp-hooks', 'wp-compose', 'wp-data', 'wp-core-data', 'wp-element' ] as $dep ) {
			$this->assertContains( $dep, $deps );
		}
	}

	public function test_the_preview_is_told_the_page_size_a_site_sets_for_a_kind(): void {
		$sizes = static function ( int $per_page, string $slug ): int {
			return 'recipe' === $slug ? 4 : $per_page;
		};
		add_filter( 'pkiw_kind_archive_preview_per_page', $sizes, 10, 2 );

		$this->assertSame( [ 'recipe' => 4 ], \PKIW\Kind_Archive_Layouts::preview_page_sizes() );

		do_action( 'enqueue_block_editor_assets' );
		$inline = implode( '', (array) wp_scripts()->get_data( 'pkiw-kind-template-preview', 'before' ) );
		$this->assertStringContainsString( 'window.pkiwKindTemplatePreview = {"perPage":{"recipe":4}};', $inline );
	}

	public function test_with_no_page_size_set_the_preview_keeps_the_editors_own(): void {
		$this->assertSame( [], \PKIW\Kind_Archive_Layouts::preview_page_sizes() );

		do_action( 'enqueue_block_editor_assets' );
		$inline = implode( '', (array) wp_scripts()->get_data( 'pkiw-kind-template-preview', 'before' ) );
		$this->assertStringContainsString( 'window.pkiwKindTemplatePreview = {"perPage":{}};', $inline );
	}
}
