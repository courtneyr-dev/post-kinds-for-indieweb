<?php
/**
 * Kind archive block templates (issue 233).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies the plugin ships kind archive templates through the
 * get_block_templates path, that each template carries the shared layout
 * contract (one h1, term description, inherited Query Loop, centered arrow
 * pagination, no-results), and that a theme's own kind template or a Site
 * Editor customization keeps winning over the plugin's.
 *
 * @group integration
 */
final class KindArchiveTemplatesTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		add_filter( 'query_vars', [ $this, 'listen_view_query_var' ] );
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( 'twentytwentyfive' );
	}

	public function tear_down(): void {
		\PKIW\Kind_Archive_Layouts::unregister_filtered_view( 'listen', 'pkiw_listen_type' );
		\PKIW\Kind_Archive_Layouts::set_rest_view( '' );
		remove_filter( 'query_vars', [ $this, 'listen_view_query_var' ] );
		switch_theme( $this->original_stylesheet );
		unset( $GLOBALS['wp_theme_directories'][ array_search( dirname( __DIR__ ) . '/fixtures/themes', $GLOBALS['wp_theme_directories'], true ) ] );
		search_theme_directories( true );
		wp_clean_themes_cache();
		parent::tear_down();
	}

	/**
	 * Make the fixture view's query variable public for the main query.
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public function listen_view_query_var( array $vars ): array {
		$vars[] = 'pkiw_listen_type';

		return $vars;
	}

	/**
	 * Register the filtered listen view used by these tests.
	 *
	 * @return void
	 */
	private function register_listen_view(): void {
		\PKIW\Kind_Archive_Layouts::register_filtered_view( 'listen', 'pkiw_listen_type', [ 'podcast' ] );
	}

	/**
	 * A kind term, creating it when another test hasn't.
	 *
	 * @param string $slug Kind slug.
	 * @return WP_Term
	 */
	private function kind_term( string $slug ): WP_Term {
		$term = get_term_by( 'slug', $slug, 'kind' );
		if ( ! $term instanceof WP_Term ) {
			$created = wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			$this->assertIsArray( $created );
			$term = get_term( (int) $created['term_id'], 'kind' );
		}
		$this->assertInstanceOf( WP_Term::class, $term );

		return $term;
	}

	/**
	 * Core's taxonomy hierarchy for a kind term, as block-template slugs' PHP names.
	 *
	 * @param string $kind Kind term slug.
	 * @return string[]
	 */
	private function hierarchy( string $kind ): array {
		return [ "taxonomy-kind-{$kind}.php", 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	public function test_filtered_view_matches_only_its_main_kind_archive_query(): void {
		$this->assertNull( \PKIW\Kind_Archive_Layouts::filtered_view() );
		$this->register_listen_view();
		$listen = $this->kind_term( 'listen' );
		$watch  = $this->kind_term( 'watch' );

		$this->go_to( add_query_arg( 'pkiw_listen_type', 'podcast', get_term_link( $listen ) ) );
		$this->assertSame(
			[
				'kind'  => 'listen',
				'var'   => 'pkiw_listen_type',
				'value' => 'podcast',
				'slug'  => 'taxonomy-kind-listen-podcast',
			],
			\PKIW\Kind_Archive_Layouts::filtered_view()
		);

		$this->go_to( add_query_arg( 'pkiw_listen_type', 'music', get_term_link( $listen ) ) );
		$this->assertNull( \PKIW\Kind_Archive_Layouts::filtered_view() );

		$this->go_to( add_query_arg( 'pkiw_listen_type', 'podcast', get_term_link( $watch ) ) );
		$this->assertNull( \PKIW\Kind_Archive_Layouts::filtered_view() );
		$this->assertNull( \PKIW\Kind_Archive_Layouts::filtered_view( new WP_Query() ) );
	}

	public function test_filtered_view_prepends_its_taxonomy_template_candidate_only_when_active(): void {
		$this->register_listen_view();
		$listen    = $this->kind_term( 'listen' );
		$templates = [ 'taxonomy-kind-listen.php', 'taxonomy-kind.php', 'taxonomy.php' ];

		$this->go_to( add_query_arg( 'pkiw_listen_type', 'podcast', get_term_link( $listen ) ) );
		$this->assertSame( 'taxonomy-kind-listen-podcast.php', apply_filters( 'taxonomy_template_hierarchy', $templates )[0] );

		foreach ( [ '', 'music' ] as $value ) {
			$url = '' === $value ? get_term_link( $listen ) : add_query_arg( 'pkiw_listen_type', $value, get_term_link( $listen ) );
			$this->go_to( $url );
			$this->assertSame( $templates, apply_filters( 'taxonomy_template_hierarchy', $templates ) );
		}
	}

	public function test_filtered_and_term_only_templates_use_separate_cache_entries(): void {
		$this->register_listen_view();
		$listen = $this->kind_term( 'listen' );
		$shelf  = '<!-- wp:post-template {"className":"is-style-pkiw-shelf"} /-->';
		$view   = '<!-- wp:query {"query":{"inherit":true}} --><!-- wp:post-template {"className":"is-style-pkiw-lineup"} --><!-- wp:post-kinds-indieweb/archive-sections {"linesPerPage":7} /--><!-- /wp:post-template --><!-- /wp:query -->';
		$inject = static function ( array $templates ) use ( $shelf, $view ): array {
			foreach ( [ 'taxonomy-kind-listen' => $shelf, 'taxonomy-kind-listen-podcast' => $view ] as $slug => $content ) {
				$template          = new WP_Block_Template();
				$template->slug    = $slug;
				$template->content = $content;
				$templates[]       = $template;
			}

			return $templates;
		};
		add_filter( 'get_block_templates', $inject );

		try {
			$this->go_to( add_query_arg( 'pkiw_listen_type', 'podcast', get_term_link( $listen ) ) );
			global $wp_query;
			$this->assertSame( 7, (int) $wp_query->get( 'posts_per_page' ), 'The main query read the view template.' );
			$this->assertSame( $shelf, \PKIW\Kind_Archive_Layouts::kind_template_content( 'listen' ), 'The term-only lookup did not reuse the view cache entry.' );

			$this->go_to( get_term_link( $listen ) );
			$this->assertSame( $shelf, \PKIW\Kind_Archive_Layouts::kind_template_content( 'listen' ) );
		} finally {
			remove_filter( 'get_block_templates', $inject );
		}
	}

	public function test_lineup_style_is_registered_and_triggers_stable_main_query_order(): void {
		$style = WP_Block_Styles_Registry::get_instance()->get_registered( 'core/post-template', 'pkiw-lineup' );
		$this->assertIsArray( $style );
		$this->assertSame( 'Lineup', $style['label'] );

		$listen = $this->kind_term( 'listen' );
		foreach ( [ 'shelf', 'shelf-spine', 'menu', 'lineup' ] as $name ) {
			$content = '<!-- wp:post-template {"className":"is-style-pkiw-' . $name . '"} /-->';
			$inject  = static function ( array $templates ) use ( $content ): array {
				$template          = new WP_Block_Template();
				$template->slug    = 'taxonomy-kind-listen';
				$template->content = $content;
				$templates[]       = $template;

				return $templates;
			};
			add_filter( 'get_block_templates', $inject );
			try {
				$this->go_to( get_term_link( $listen ) );
				global $wp_query;
				$this->assertSame( [ 'date' => 'DESC', 'ID' => 'DESC' ], $wp_query->get( 'orderby' ), $name );
			} finally {
				remove_filter( 'get_block_templates', $inject );
			}
		}
	}

	public function test_template_preview_settings_end_with_registered_views(): void {
		$this->register_listen_view();
		do_action( 'enqueue_block_editor_assets' );
		$inline = implode( '', (array) wp_scripts()->get_data( 'pkiw-kind-template-preview', 'before' ) );
		preg_match_all( '/window\.pkiwKindTemplatePreview = (\{.*?\});/', $inline, $found );
		$this->assertNotEmpty( $found[1] );
		$json = (string) end( $found[1] );

		$this->assertStringStartsWith( '{"perPage":', $json );
		$this->assertStringEndsWith( ',"views":[{"kind":"listen","var":"pkiw_listen_type","values":["podcast"]}]}', $json );
		$this->assertSame(
			[ [ 'kind' => 'listen', 'var' => 'pkiw_listen_type', 'values' => [ 'podcast' ] ] ],
			json_decode( $json, true )['views']
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function shipped_templates(): array {
		return [
			'generic kind archive' => [ 'taxonomy-kind', 'is-style-pkiw-shelf' ],
			'eat menu'             => [ 'taxonomy-kind-eat', 'is-style-pkiw-menu' ],
			'drink menu'           => [ 'taxonomy-kind-drink', 'is-style-pkiw-menu' ],
		];
	}

	/**
	 * @dataProvider shipped_templates
	 */
	public function test_template_ships_through_get_block_templates( string $slug ): void {
		$templates = get_block_templates( [ 'slug__in' => [ $slug ] ] );
		$ids       = wp_list_pluck( $templates, 'id' );

		$this->assertContains( 'post-kinds-for-indieweb//' . $slug, $ids );
	}

	/**
	 * @dataProvider shipped_templates
	 */
	public function test_template_carries_the_layout_contract( string $slug, string $layout_class ): void {
		$content = (string) file_get_contents( PKIW_PATH . 'templates/' . $slug . '.html' );
		$blocks  = $this->flatten( parse_blocks( $content ) );

		$titles = array_values( array_filter( $blocks, static fn( $b ) => 'core/query-title' === $b['blockName'] ) );
		$this->assertCount( 1, $titles, 'Exactly one query-title.' );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ), 'The query title is the h1.' );

		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], [ 'core/heading', 'core/post-title' ], true ) ) {
				$this->assertNotSame( 1, (int) ( $block['attrs']['level'] ?? 2 ), 'No second h1.' );
			}
		}

		$this->assertNotEmpty( $this->of_type( $blocks, 'core/term-description' ), 'Term description.' );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false, 'Query Loop inherits the main query.' );

		$templates = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $templates );
		$this->assertStringContainsString( $layout_class, $templates[0]['attrs']['className'] ?? '' );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$this->assertNotEmpty( $this->of_type( $blocks, $part ), $part );
		}

		$this->assertNotEmpty( $this->of_type( $blocks, 'core/query-no-results' ), 'No-results state.' );
	}

	public function test_generic_kind_archive_resolves_to_plugin_template(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy( 'listen' ), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind', $template->id );
	}

	public function test_eat_archive_resolves_to_plugin_menu_template(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy( 'eat' ), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-eat', $template->id );
	}

	public function test_rendered_archive_has_one_h1_and_centered_arrow_pagination(): void {
		$ids = self::factory()->post->create_many( 12, [ 'post_status' => 'publish' ] );
		foreach ( $ids as $id ) {
			wp_set_object_terms( $id, 'listen', 'kind' );
		}
		update_option( 'posts_per_page', 10 );

		$this->go_to( get_term_link( 'listen', 'kind' ) );
		$this->assertTrue( is_tax( 'kind', 'listen' ) );

		$html = $this->render_current_template( 'listen' );

		$this->assertSame( 1, substr_count( $html, '<h1' ), 'Exactly one h1 on the rendered archive.' );
		$this->assertMatchesRegularExpression( '/wp-block-query-pagination[^"]*is-content-justification-center/', $html );
		$this->assertStringContainsString( 'wp-block-query-pagination-next-arrow', $html );
		$this->assertStringContainsString( 'is-style-pkiw-shelf', $html );
	}

	public function test_theme_kind_template_wins_over_every_plugin_kind_template(): void {
		switch_theme( 'pkiw-kind-theme' );
		$this->assertSame( 'pkiw-kind-theme', get_stylesheet() );

		foreach ( [ 'listen', 'eat', 'drink', 'play', 'read', 'chicken', 'photo', 'favorite', 'acquisition', 'repost', 'article' ] as $kind ) {
			$template = resolve_block_template( 'taxonomy', $this->hierarchy( $kind ), '' );

			$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id, "Theme template must win for {$kind}." );
			$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function w1_archive_slugs(): array {
		return [
			'play archive' => [ 'taxonomy-kind-play' ],
			'read archive' => [ 'taxonomy-kind-read' ],
		];
	}

	/**
	 * The W1 kind lanes add the template files; the definitions are here
	 * first, and a definition serves only once its file exists.
	 *
	 * @dataProvider w1_archive_slugs
	 */
	public function test_the_play_and_read_archives_have_template_definitions( string $slug ): void {
		$definitions = ( new ReflectionMethod( \PKIW\Plugin::class, 'get_plugin_template_definitions' ) )->invoke( \PKIW\Plugin::get_instance() );

		$this->assertArrayHasKey( $slug, $definitions );
		$this->assertNotSame( '', trim( (string) $definitions[ $slug ]['title'] ) );
		$this->assertNotSame( '', trim( (string) $definitions[ $slug ]['description'] ) );
		$this->assertSame( [], $definitions[ $slug ]['post_types'] );
	}

	public function test_the_review_archive_has_a_template_definition(): void {
		$definitions = ( new ReflectionMethod( \PKIW\Plugin::class, 'get_plugin_template_definitions' ) )->invoke( \PKIW\Plugin::get_instance() );

		$this->assertArrayHasKey( 'taxonomy-kind-review', $definitions );
		$this->assertNotSame( '', trim( (string) $definitions['taxonomy-kind-review']['title'] ) );
		$this->assertNotSame( '', trim( (string) $definitions['taxonomy-kind-review']['description'] ) );
		$this->assertSame( [], $definitions['taxonomy-kind-review']['post_types'] );
	}

	public function test_the_review_template_serves_only_when_its_file_exists(): void {
		$served = in_array(
			'post-kinds-for-indieweb//taxonomy-kind-review',
			wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-review' ] ] ), 'id' ),
			true
		);

		$this->assertSame( file_exists( PKIW_PATH . 'templates/taxonomy-kind-review.html' ), $served );
		$this->assertSame( $served, null !== get_block_template( 'post-kinds-for-indieweb//taxonomy-kind-review' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function w3_archive_slugs(): array {
		return [
			'chicken archive'     => [ 'taxonomy-kind-chicken' ],
			'photo archive'       => [ 'taxonomy-kind-photo' ],
			'favorite archive'    => [ 'taxonomy-kind-favorite' ],
			'acquisition archive' => [ 'taxonomy-kind-acquisition' ],
			'repost archive'      => [ 'taxonomy-kind-repost' ],
			'article archive'     => [ 'taxonomy-kind-article' ],
		];
	}

	/**
	 * @dataProvider w3_archive_slugs
	 */
	public function test_the_w3_archives_have_template_definitions( string $slug ): void {
		$definitions = ( new ReflectionMethod( \PKIW\Plugin::class, 'get_plugin_template_definitions' ) )->invoke( \PKIW\Plugin::get_instance() );

		$this->assertArrayHasKey( $slug, $definitions );
		$this->assertNotSame( '', trim( (string) $definitions[ $slug ]['title'] ) );
		$this->assertNotSame( '', trim( (string) $definitions[ $slug ]['description'] ) );
		$this->assertSame( [], $definitions[ $slug ]['post_types'] );
	}

	/**
	 * @dataProvider w3_archive_slugs
	 */
	public function test_the_w3_archives_serve_only_when_their_files_exist( string $slug ): void {
		$definitions = ( new ReflectionMethod( \PKIW\Plugin::class, 'get_plugin_template_definitions' ) )->invoke( \PKIW\Plugin::get_instance() );
		$this->assertArrayHasKey( $slug, $definitions );

		$served = in_array(
			'post-kinds-for-indieweb//' . $slug,
			wp_list_pluck( get_block_templates( [ 'slug__in' => [ $slug ] ] ), 'id' ),
			true
		);

		$this->assertSame( file_exists( PKIW_PATH . 'templates/' . $slug . '.html' ), $served );
		$this->assertSame( $served, null !== get_block_template( 'post-kinds-for-indieweb//' . $slug ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function w4_archive_slugs(): array {
		return [
			'mood archive'     => [ 'taxonomy-kind-mood', 'Mood Archive' ],
			'weather archive'  => [ 'taxonomy-kind-weather', 'Weather Archive' ],
			'exercise archive' => [ 'taxonomy-kind-exercise', 'Exercise Archive' ],
			'sleep archive'    => [ 'taxonomy-kind-sleep', 'Sleep Archive' ],
			'craft archive'    => [ 'taxonomy-kind-craft', 'Craft Archive' ],
			'note archive'     => [ 'taxonomy-kind-note', 'Note Archive' ],
		];
	}

	/**
	 * @dataProvider w4_archive_slugs
	 */
	public function test_the_w4_archives_have_template_definitions( string $slug, string $title ): void {
		$definitions = ( new ReflectionMethod( \PKIW\Plugin::class, 'get_plugin_template_definitions' ) )->invoke( \PKIW\Plugin::get_instance() );

		$this->assertArrayHasKey( $slug, $definitions );
		$this->assertSame( $title, $definitions[ $slug ]['title'] );
		$this->assertNotSame( '', trim( (string) $definitions[ $slug ]['description'] ) );
		$this->assertSame( [], $definitions[ $slug ]['post_types'] );
	}

	/**
	 * A definition serves only once its file exists. Each kind lane ships its
	 * file; until then the slug is registered and absent from the list.
	 *
	 * @dataProvider w4_archive_slugs
	 */
	public function test_the_w4_archives_serve_only_when_their_files_exist( string $slug ): void {
		$served = in_array(
			'post-kinds-for-indieweb//' . $slug,
			wp_list_pluck( get_block_templates( [ 'slug__in' => [ $slug ] ] ), 'id' ),
			true
		);

		$this->assertSame( file_exists( PKIW_PATH . 'templates/' . $slug . '.html' ), $served );
		$this->assertSame( $served, null !== get_block_template( 'post-kinds-for-indieweb//' . $slug ) );
	}

	/**
	 * With a theme taxonomy-kind in the list the plugin adds none of its kind
	 * templates; without one it adds the slug exactly when the file exists.
	 *
	 * @dataProvider w4_archive_slugs
	 */
	public function test_a_theme_taxonomy_kind_template_blocks_each_w4_archive( string $slug ): void {
		$plugin = \PKIW\Plugin::get_instance();
		$theme  = new WP_Block_Template();

		$theme->id     = 'pkiw-kind-theme//taxonomy-kind';
		$theme->slug   = 'taxonomy-kind';
		$theme->theme  = 'pkiw-kind-theme';
		$theme->type   = 'wp_template';
		$theme->source = 'theme';

		$with    = $plugin->add_plugin_templates( [ $theme ], [ 'slug__in' => [ $slug ] ], 'wp_template' );
		$without = $plugin->add_plugin_templates( [], [ 'slug__in' => [ $slug ] ], 'wp_template' );

		$this->assertSame( [ 'pkiw-kind-theme//taxonomy-kind' ], wp_list_pluck( $with, 'id' ) );
		$this->assertSame(
			file_exists( PKIW_PATH . 'templates/' . $slug . '.html' ) ? [ 'post-kinds-for-indieweb//' . $slug ] : [],
			wp_list_pluck( $without, 'id' )
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function w5_archive_slugs(): array {
		return [
			'video archive'          => [ 'taxonomy-kind-video', 'Video Archive' ],
			'audio archive'          => [ 'taxonomy-kind-audio', 'Audio Archive' ],
			'jam archive'            => [ 'taxonomy-kind-jam', 'Jam Archive' ],
			'podcast listens archive' => [ 'taxonomy-kind-listen-podcast', 'Podcast Listens Archive' ],
			'wish archive'           => [ 'taxonomy-kind-wish', 'Wish Archive' ],
			'question archive'       => [ 'taxonomy-kind-question', 'Question Archive' ],
		];
	}

	/**
	 * @dataProvider w5_archive_slugs
	 */
	public function test_the_w5_archives_have_template_definitions( string $slug, string $title ): void {
		$definitions = ( new ReflectionMethod( \PKIW\Plugin::class, 'get_plugin_template_definitions' ) )->invoke( \PKIW\Plugin::get_instance() );

		$this->assertArrayHasKey( $slug, $definitions );
		$this->assertSame( $title, $definitions[ $slug ]['title'] );
		$this->assertNotSame( '', trim( (string) $definitions[ $slug ]['description'] ) );
		$this->assertSame( [], $definitions[ $slug ]['post_types'] );
	}

	/**
	 * @dataProvider w5_archive_slugs
	 */
	public function test_the_w5_archives_serve_only_when_their_files_exist( string $slug ): void {
		$served = in_array(
			'post-kinds-for-indieweb//' . $slug,
			wp_list_pluck( get_block_templates( [ 'slug__in' => [ $slug ] ] ), 'id' ),
			true
		);

		$this->assertSame( file_exists( PKIW_PATH . 'templates/' . $slug . '.html' ), $served );
		$this->assertSame( $served, null !== get_block_template( 'post-kinds-for-indieweb//' . $slug ) );
	}

	/**
	 * @dataProvider w5_archive_slugs
	 */
	public function test_a_theme_taxonomy_kind_template_blocks_each_w5_archive( string $slug ): void {
		$plugin = \PKIW\Plugin::get_instance();
		$theme  = new WP_Block_Template();

		$theme->id     = 'pkiw-kind-theme//taxonomy-kind';
		$theme->slug   = 'taxonomy-kind';
		$theme->theme  = 'pkiw-kind-theme';
		$theme->type   = 'wp_template';
		$theme->source = 'theme';

		$with    = $plugin->add_plugin_templates( [ $theme ], [ 'slug__in' => [ $slug ] ], 'wp_template' );
		$without = $plugin->add_plugin_templates( [], [ 'slug__in' => [ $slug ] ], 'wp_template' );

		$this->assertSame( [ 'pkiw-kind-theme//taxonomy-kind' ], wp_list_pluck( $with, 'id' ) );
		$this->assertSame(
			file_exists( PKIW_PATH . 'templates/' . $slug . '.html' ) ? [ 'post-kinds-for-indieweb//' . $slug ] : [],
			wp_list_pluck( $without, 'id' )
		);
	}

	public function test_the_plugin_wires_the_w5_kind_classes_after_the_w2_archives_each_behind_class_exists(): void {
		$source   = (string) file_get_contents( PKIW_PATH . 'includes/class-plugin.php' );
		$previous = strpos( $source, "class_exists( __NAMESPACE__ . '\\\\Bookmark_Archive' )" );

		$this->assertNotFalse( $previous );
		foreach ( [ 'Video_Archive', 'Audio_Archive', 'Audio_Transcript', 'Jam_Archive', 'Listen_Archive', 'Wish_Archive', 'Question_Archive', 'Question_Answers', 'Question_Location' ] as $class ) {
			$at = strpos( $source, "class_exists( __NAMESPACE__ . '\\\\{$class}' )" );
			$this->assertNotFalse( $at, $class );
			$this->assertGreaterThan( $previous, $at, $class );
			$this->assertStringContainsString( "( new {$class}() )->register();", substr( $source, $at, 140 ), $class );
			$previous = $at;
		}
		$months = strpos( $source, '$this->init_month_archives();', (int) $previous );
		$this->assertNotFalse( $months );
		$this->assertGreaterThan( $previous, $months );
	}

	public function test_the_w5_class_wiring_is_a_no_op_until_the_kind_lanes_arrive(): void {
		foreach ( [ 'Video_Archive', 'Audio_Archive', 'Audio_Transcript', 'Jam_Archive', 'Listen_Archive', 'Wish_Archive', 'Question_Archive', 'Question_Answers', 'Question_Location' ] as $class ) {
			$this->assertFalse( class_exists( 'PKIW\\' . $class ), $class );
		}
		$this->assertNull( \PKIW\Kind_Facts::reader( 'video' ) );
	}

	public function test_the_plugin_wires_the_w4_kind_classes_after_staff_picks_each_behind_class_exists(): void {
		$source = (string) file_get_contents( PKIW_PATH . 'includes/class-plugin.php' );
		$staff  = strpos( $source, "class_exists( __NAMESPACE__ . '\\\\Staff_Picks' )" );

		$this->assertNotFalse( $staff );
		foreach ( [ 'Mood_Year_Grid', 'Almanac_Month', 'Exercise_Archive', 'Exercise_Medals', 'Exercise_Location', 'Sleep_Archive', 'Sleep_Night', 'Sleep_Location', 'Craft_Archive', 'Note_Archive' ] as $class ) {
			$at = strpos( $source, "class_exists( __NAMESPACE__ . '\\\\{$class}' )" );
			$this->assertNotFalse( $at, $class );
			$this->assertGreaterThan( $staff, $at, $class );
			$this->assertStringContainsString( "( new {$class}() )->register();", substr( $source, $at, 140 ), $class );
			$staff = $at;
		}
	}

	public function test_the_w4_block_names_are_in_the_block_list_and_skip_until_their_block_json_exists(): void {
		$source = (string) file_get_contents( PKIW_PATH . 'includes/class-plugin.php' );
		$start  = strpos( $source, '$blocks = [' );
		$end    = strpos( $source, '];', (int) $start );
		$list   = substr( $source, (int) $start, (int) $end - (int) $start );

		foreach ( [ 'exercise-card', 'sleep-card', 'craft-card', 'steps', 'step' ] as $block ) {
			$this->assertStringContainsString( "'{$block}',", $list, $block );
			$registered = WP_Block_Type_Registry::get_instance()->is_registered( 'post-kinds-indieweb/' . $block );
			$has_json   = file_exists( PKIW_PATH . 'build/blocks/' . $block . '/block.json' ) || file_exists( PKIW_PATH . 'src/blocks/' . $block . '/block.json' );
			$this->assertSame( $has_json, $registered, "{$block} registers when its block.json exists and not before." );
		}
	}

	/**
	 * The W2 archive classes arrive in later lanes, so this source check pins
	 * the loader wiring until the classes exist and can be observed directly.
	 */
	public function test_the_plugin_wires_the_w2_archive_classes_after_staff_picks(): void {
		$source = (string) file_get_contents( PKIW_PATH . 'includes/class-plugin.php' );
		$staff  = strpos( $source, "class_exists( __NAMESPACE__ . '\\\\Staff_Picks' )" );

		$this->assertNotFalse( $staff );
		foreach ( [ 'Review_Archive', 'Reply_Archive', 'Like_Archive', 'Bookmark_Archive' ] as $class ) {
			$at = strpos( $source, "class_exists( __NAMESPACE__ . '\\\\{$class}' )" );
			$this->assertNotFalse( $at, $class );
			$this->assertGreaterThan( $staff, $at, $class );
			$this->assertStringContainsString( "( new {$class}() )->register();", substr( $source, $at, 120 ), $class );
			$staff = $at;
		}
	}

	public function test_the_plugin_wires_the_w3_archive_classes_after_staff_picks(): void {
		$source = (string) file_get_contents( PKIW_PATH . 'includes/class-plugin.php' );
		$staff  = strpos( $source, "class_exists( __NAMESPACE__ . '\\\\Staff_Picks' )" );

		$this->assertNotFalse( $staff );
		foreach ( [ 'Chicken_Archive', 'Photo_Archive', 'Favorite_Archive', 'Acquisition_Archive', 'Repost_Archive', 'Article_Archive', 'Reading_Time' ] as $class ) {
			$at = strpos( $source, "class_exists( __NAMESPACE__ . '\\\\{$class}' )" );
			$this->assertNotFalse( $at, $class );
			$this->assertGreaterThan( $staff, $at, $class );
			$this->assertStringContainsString( "( new {$class}() )->register();", substr( $source, $at, 140 ), $class );
			$staff = $at;
		}
	}

	public function test_favorite_archive_registers_its_kind_facts_reader_when_available(): void {
		if ( ! class_exists( 'PKIW\\Favorite_Archive' ) ) {
			$this->markTestSkipped( 'Favorite_Archive arrives with issue 259.' );
		}

		$this->assertNotNull( \PKIW\Kind_Facts::reader( 'favorite' ) );
	}

	public function test_question_archive_joins_the_grouped_kinds_when_available(): void {
		if ( ! class_exists( 'PKIW\\Question_Archive' ) ) {
			$this->markTestSkipped( 'Question_Archive arrives with issue 256.' );
		}

		$this->assertContains( 'question', \PKIW\Grouped_Archive::grouped_kinds() );
	}

	public function test_site_editor_customization_wins_over_plugin_template(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-eat',
				'post_title'   => 'Eat archive (customized)',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-eat</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy( 'eat' ), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-eat', $template->content );
	}

	public function test_file_template_filter_never_answers_for_a_theme_id(): void {
		switch_theme( 'pkiw-kind-theme' );

		$template = get_block_file_template( 'pkiw-kind-theme//taxonomy-kind' );

		$this->assertNotNull( $template );
		$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
	}

	/**
	 * Render the block template the current request resolves to.
	 *
	 * @param string $kind Kind term slug.
	 */
	private function render_current_template( string $kind ): string {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy( $kind ), '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$out[] = $block;
			$out   = array_merge( $out, $this->flatten( $block['innerBlocks'] ?? [] ) );
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Flattened blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( $b ) => $name === $b['blockName'] ) );
	}
}
