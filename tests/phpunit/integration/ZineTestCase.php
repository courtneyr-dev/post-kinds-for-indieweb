<?php
/** Shared zine integration fixtures. @package PKIW */
declare(strict_types=1);

use PKIW\Taxonomy;
use PKIW\Zine_Export;
use PKIW\Zine_Manifest;
use PKIW\Public_Eligibility;

/** Base class for zine integration tests. */
abstract class ZineTestCase extends WP_UnitTestCase {
	protected WP_Term $recipe;

	/** Header lines the default sink collected. */
	protected array $sent_headers = [];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		$this->recipe = get_term_by( 'slug', 'recipe', Taxonomy::TAXONOMY );
		Zine_Manifest::override_narrowed_visibility_for_tests( null );
		Public_Eligibility::override_cli_for_tests( null );
		remove_all_filters( 'pkiw_zine_excluded_kinds' );
		// Core's canonical redirect and the header() fallback can't run once PHPUnit has printed output.
		remove_action( 'template_redirect', 'redirect_canonical' );
		// The test bootstrap loads a WP Recipe Maker stand-in; the spec's fixtures use the fallback course taxonomy.
		add_filter( 'pkiw_recipe_plugin_active', '__return_false' );
		$this->sent_headers = [];
		Zine_Export::override_header_sink_for_tests( fn( string $line ) => $this->sent_headers[] = $line );
	}

	public function tear_down(): void {
		Zine_Manifest::override_narrowed_visibility_for_tests( null );
		Public_Eligibility::override_cli_for_tests( null );
		Zine_Export::override_header_sink_for_tests( null );
		remove_all_filters( 'pkiw_zine_excluded_kinds' );
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_admin_bar'], $GLOBALS['_wp_admin_bar'] );
		parent::tear_down();
	}

	/** Create a fixed-date post and optionally assign one kind. */
	protected function post( string $title, array $args = [], ?string $kind = 'recipe' ): int {
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
		if ( null !== $kind ) {
			wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		}
		return $id;
	}

	/** Return the recipe archive URL with optional query variables. */
	protected function archive_url( array $vars = [] ): string {
		$url = get_term_link( $this->recipe );
		$this->assertIsString( $url );

		return [] === $vars ? $url : add_query_arg( $vars, $url );
	}

	/** Create a page containing the zine block and one non-inheriting Query Loop. */
	protected function stream_page( array $args = [], string $source = 'stream' ): int {
		$content = '<!-- wp:post-kinds-indieweb/zine-export {"source":"' . esc_attr( $source ) . '"} /-->'
			. '<!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"post","order":"desc","orderBy":"date","inherit":false}} -->'
			. '<!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->';

		return self::factory()->post->create(
			array_merge(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => 'stream',
					'post_title'   => 'Stream',
					'post_date'    => '2025-03-01 12:00:00',
					'post_content' => $content,
				],
				$args
			)
		);
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

	/** Render a request through the plugin's template lifecycle. */
	protected function render_document( string $url, int $user_id = 0 ): array {
		wp_set_current_user( $user_id );
		unset( $GLOBALS['wp_admin_bar'], $GLOBALS['_wp_admin_bar'] );
		show_admin_bar( 0 < $user_id );
		$this->go_to( $url );
		do_action( 'template_redirect' );
		$template = apply_filters( 'template_include', 'theme-template.php' );
		$html     = '';
		if ( is_string( $template ) && file_exists( $template ) ) {
			$before = array_keys( $this->caught_doing_it_wrong );
			ob_start();
			include $template;
			$html = (string) ob_get_clean();
			// Earlier tests in a full run leave theme state that makes core's wp_head() raise these two notices.
			// They come from core style and duotone output, not zine code; every other notice still fails the test.
			foreach ( [ 'WP_Styles::add', 'WP_Duotone::get_filter_svg' ] as $theme_state_notice ) {
				if ( ! in_array( $theme_state_notice, $before, true ) ) {
					unset( $this->caught_doing_it_wrong[ $theme_state_notice ] );
				}
			}
		}

		return [ 'template' => $template, 'html' => $html ];
	}

	/** Render the archive-source dynamic block on one request. */
	protected function render_archive_block( string $url ): string {
		$this->go_to( $url );

		return $this->render_block_on_current_request();
	}

	/** Render the archive-source dynamic block on the request already routed. */
	protected function render_block_on_current_request(): string {
		return render_block(
			[
				'blockName'    => Zine_Export::BLOCK,
				'attrs'        => [ 'source' => 'archive' ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/** Return the plugin print-template path. */
	protected function print_template(): string {
		return PKIW_PATH . 'templates/zine-print.php';
	}
}
