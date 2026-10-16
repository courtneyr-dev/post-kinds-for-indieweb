<?php
/**
 * Grouped archive engine (P3): registry, sources, labels, sections and preview.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Archive_Group;
use PKIW\Grouping\Cases_Source;
use PKIW\Grouping\Date_Source;
use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;
use PKIW\Grouping\Term_Source;

/**
 * Each source orders posts in SQL and names their groups in PHP by the same
 * rule; sections, labels, page breaks and the editor preview follow from
 * that for any registered entry block. Fixture kinds, sources and entry
 * blocks register in set_up() and go away in tear_down(); names and hosts
 * are invented.
 *
 * @group integration
 */
final class GroupedArchiveEngineTest extends WP_UnitTestCase {

	private const MARKER = 'pkiw-test/group-marker';
	private const LINE   = 'pkiw-test/group-line';
	private const OL_LINE = 'pkiw-test/ol-line';
	private const PAIRS  = 'pkiw-test/two-a-page';
	private const MONTHS = 'pkiw-test/month-marker';
	private const IDS    = 'pkiw-test/id-marker';
	private const BODY   = 'pkiw-test/body-line';
	private const WEEKS  = 'pkiw-test/weeks-line';

	private const SOURCES = [ 'test_status', 'test_series', 'test_play', 'test_tags', 'test_cats', 'test_month', 'test_month_asc', 'test_year', 'test_week', 'test_flagged_tags' ];

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Called for each item a line entry prints; returns extra markup.
	 *
	 * @var callable|null
	 */
	private $on_item = null;

	/**
	 * What the section_body entry's callback received, one record per section.
	 *
	 * @var array<int, array{group:string, ids:int[], items:array<int, string>, settings:array<string, mixed>}>
	 */
	private array $bodies = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 20 );

		register_post_meta(
			'post',
			'_pkiw_test_status',
			[
				'single'  => true,
				'type'    => 'string',
				'default' => 'reading',
			]
		);

		Grouped_Archive::register_source(
			new Meta_Source(
				'test_status',
				[ '_pkiw_test_status' ],
				[ 'reading', 'to-read', 'finished', 'abandoned' ],
				Label_Mode::map(
					static fn(): array => [
						'reading'   => 'Currently reading',
						'to-read'   => 'To read',
						'finished'  => 'Finished',
						'abandoned' => 'Abandoned',
					]
				)
			),
			'shelfkind'
		);
		Grouped_Archive::register_source( new Meta_Source( 'test_series', [ '_pkiw_test_series', '_pkiw_test_series_alt' ] ), 'multikind' );
		Grouped_Archive::register_source(
			new Cases_Source(
				'test_play',
				[
					'video' => [ '_pkiw_test_rawg', '_pkiw_test_steam' ],
					'board' => [ '_pkiw_test_bgg' ],
				],
				[],
				Label_Mode::map(
					[
						'video' => 'Video games',
						'board' => 'Game Night',
					]
				)
			),
			'playkind'
		);
		Grouped_Archive::register_source( new Term_Source( 'test_tags', 'post_tag' ), 'tagkind' );
		Grouped_Archive::register_source( new Term_Source( 'test_cats', 'category', static fn(): int => (int) get_option( 'default_category' ) ), 'catkind' );
		Grouped_Archive::register_source( new Date_Source( 'test_month', 'month' ), 'monthkind' );
		Grouped_Archive::register_source( new Date_Source( 'test_month_asc', 'month', 'asc' ), 'monthasckind' );
		Grouped_Archive::register_source( new Date_Source( 'test_year', 'year' ), 'yearkind' );
		Grouped_Archive::register_source( new Date_Source( 'test_week', 'week' ), 'weekkind' );

		$this->entry_block(
			self::MARKER,
			[ 'expose_key' => true ],
			[
				'groupLabels' => [
					'type'    => 'object',
					'default' => [],
				],
				'dateFormat'  => [
					'type'    => 'string',
					'default' => '',
				],
			]
		);
		$this->entry_block( self::LINE, [ 'shape' => Grouped_Archive::ENTRY_LINE ] );
		$this->entry_block( self::PAIRS, [], [ 'linesPerPage' => [ 'default' => 2 ] ] );
		$this->entry_block( self::MONTHS, [ 'source' => 'test_month' ] );
		$this->entry_block(
			self::IDS,
			[
				'expose_key'  => true,
				'heading_ids' => true,
			]
		);
		$this->entry_block(
			self::BODY,
			[
				'shape'        => Grouped_Archive::ENTRY_LINE,
				'section_body' => function ( Archive_Group $group, array $items, array $settings ): string {
					$this->bodies[] = [
						'group'    => $group->key(),
						'ids'      => array_keys( $items ),
						'items'    => $items,
						'settings' => $settings,
					];

					return sprintf( '<div class="body" data-count="%d">%s</div>', count( $items ), implode( '', $items ) );
				},
			],
			[
				'weeksPerPage' => [
					'type' => 'integer',
				],
			]
		);
		$this->entry_block(
			self::WEEKS,
			[
				'shape'  => Grouped_Archive::ENTRY_LINE,
				'source' => 'test_week',
			],
			[
				'weeksPerPage' => [
					'type' => 'integer',
				],
			]
		);
	}

	public function tear_down(): void {
		foreach ( [ self::MARKER, self::LINE, self::OL_LINE, self::PAIRS, self::MONTHS, self::IDS, self::BODY, self::WEEKS ] as $name ) {
			Grouped_Archive::unregister_entry_block( $name );
			if ( WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				unregister_block_type( $name );
			}
		}
		foreach ( self::SOURCES as $id ) {
			Grouped_Archive::unregister_source( $id );
		}
		unregister_post_meta( 'post', '_pkiw_test_status' );
		$this->on_item = null;
		$this->bodies  = [];
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * Register a fixture entry block and its block type.
	 *
	 * @param string                              $name  Block name.
	 * @param array<string, mixed>                $args  Entry args.
	 * @param array<string, array<string, mixed>> $extra Extra attributes.
	 */
	private function entry_block( string $name, array $args, array $extra = [] ): void {
		Grouped_Archive::register_entry_block( $name, $args );
		register_block_type(
			$name,
			[
				'api_version'     => 3,
				'uses_context'    => [ 'postId', 'postType', 'queryId' ],
				'attributes'      => Grouped_Archive::entry_attributes( $extra ),
				'render_callback' => function ( array $attributes, string $content, WP_Block $block ) use ( $name ): string {
					return Grouped_Archive::render_entry(
						$name,
						$attributes,
						$block,
						function ( WP_Post $post, bool $in_section ): string {
							$group = Grouped_Archive::current_group();
							$extra = is_callable( $this->on_item ) ? (string) call_user_func( $this->on_item, $post ) : '';

							return sprintf(
								'<p class="line" data-in-section="%d" data-group="%s">%s</p>%s',
								$in_section ? 1 : 0,
								esc_attr( $group ? $group->key() : '-' ),
								esc_html( get_the_title( $post ) ),
								$extra
							);
						}
					);
				},
			]
		);
	}

	/**
	 * A published post of a kind.
	 *
	 * @param string                              $kind  Kind slug.
	 * @param string                              $title Title.
	 * @param string                              $date  Post date (site time).
	 * @param array<string, string|string[]>      $meta  Meta key => value, or values for several rows.
	 * @param array<string, array<int|string>>    $terms Taxonomy => terms.
	 */
	private function make( string $kind, string $title, string $date, array $meta = [], array $terms = [] ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $id, $kind, 'kind' );
		foreach ( $meta as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_post_meta( $id, $key, $value );
			}
		}
		foreach ( $terms as $taxonomy => $list ) {
			wp_set_object_terms( $id, $list, $taxonomy );
		}
		return $id;
	}

	/**
	 * Read shelf: every status, one with spaces and capitals, one with no row, one unlisted, one blank.
	 *
	 * @return array<string, int>
	 */
	private function shelf(): array {
		return [
			'dune'        => $this->make( 'shelfkind', 'Dune', '2026-01-10 10:00:00', [ '_pkiw_test_status' => 'reading' ] ),
			'emma'        => $this->make( 'shelfkind', 'Emma', '2026-01-09 10:00:00', [ '_pkiw_test_status' => 'to-read' ] ),
			'ulysses'     => $this->make( 'shelfkind', 'Ulysses', '2026-01-08 10:00:00', [ '_pkiw_test_status' => 'finished' ] ),
			'middlemarch' => $this->make( 'shelfkind', 'Middlemarch', '2026-01-07 10:00:00', [ '_pkiw_test_status' => 'abandoned' ] ),
			'beloved'     => $this->make( 'shelfkind', 'Beloved', '2026-01-06 10:00:00', [ '_pkiw_test_status' => 'reading' ] ),
			'kindred'     => $this->make( 'shelfkind', 'Kindred', '2026-01-05 10:00:00', [ '_pkiw_test_status' => ' Finished ' ] ),
			'persuasion'  => $this->make( 'shelfkind', 'Persuasion', '2026-01-04 10:00:00' ),
			'walden'      => $this->make( 'shelfkind', 'Walden', '2026-01-03 10:00:00', [ '_pkiw_test_status' => 'paused' ] ),
			'ada'         => $this->make( 'shelfkind', 'Ada', '2026-01-02 10:00:00', [ '_pkiw_test_status' => '   ' ] ),
		];
	}

	/**
	 * Series under two keys, read first-non-empty.
	 *
	 * @return array<string, int>
	 */
	private function series(): array {
		return [
			'a' => $this->make( 'multikind', 'Wizard', '2026-01-05 10:00:00', [ '_pkiw_test_series' => 'Earthsea' ] ),
			'b' => $this->make( 'multikind', 'Mort', '2026-01-04 10:00:00', [ '_pkiw_test_series' => '', '_pkiw_test_series_alt' => 'Discworld' ] ),
			'c' => $this->make( 'multikind', 'Tombs', '2026-01-03 10:00:00', [ '_pkiw_test_series' => '  ', '_pkiw_test_series_alt' => 'Earthsea' ] ),
			'd' => $this->make( 'multikind', 'Guards', '2026-01-02 10:00:00', [ '_pkiw_test_series_alt' => 'Discworld' ] ),
			'e' => $this->make( 'multikind', 'Standalone', '2026-01-01 10:00:00' ),
		];
	}

	/**
	 * Plays: a video id beats a board id; a blank id counts for nothing.
	 *
	 * @return array<string, int>
	 */
	private function plays(): array {
		return [
			'hades'    => $this->make( 'playkind', 'Hades', '2026-01-06 10:00:00', [ '_pkiw_test_rawg' => '123' ] ),
			'catan'    => $this->make( 'playkind', 'Catan', '2026-01-05 10:00:00', [ '_pkiw_test_bgg' => '13' ] ),
			'celeste'  => $this->make( 'playkind', 'Celeste', '2026-01-04 10:00:00', [ '_pkiw_test_steam' => '504230', '_pkiw_test_bgg' => '99' ] ),
			'wingspan' => $this->make( 'playkind', 'Wingspan', '2026-01-03 10:00:00', [ '_pkiw_test_bgg' => '266192', '_pkiw_test_rawg' => '  ' ] ),
			'tag'      => $this->make( 'playkind', 'Tag', '2026-01-02 10:00:00' ),
			'azul'     => $this->make( 'playkind', 'Azul', '2026-01-01 10:00:00', [ '_pkiw_test_bgg' => '230802' ] ),
		];
	}

	/**
	 * Tagged posts, including two tags that share a name.
	 *
	 * @return array<string, int>
	 */
	private function tagged(): array {
		$apple  = wp_insert_term( 'Apple', 'post_tag', [ 'slug' => 'apple' ] );
		$apple2 = wp_insert_term( 'Apple', 'post_tag', [ 'slug' => 'apple-press' ] );
		$this->assertIsArray( $apple2, 'A second tag can share a name under its own slug.' );

		return [
			'a' => $this->make( 'tagkind', 'Post A', '2026-01-06 10:00:00', [], [ 'post_tag' => [ 'zebra', (int) $apple['term_id'] ] ] ),
			'b' => $this->make( 'tagkind', 'Post B', '2026-01-05 10:00:00', [], [ 'post_tag' => [ 'banana' ] ] ),
			'c' => $this->make( 'tagkind', 'Post C', '2026-01-04 10:00:00' ),
			'd' => $this->make( 'tagkind', 'Post D', '2026-01-03 10:00:00', [], [ 'post_tag' => [ (int) $apple2['term_id'] ] ] ),
			'e' => $this->make( 'tagkind', 'Post E', '2026-01-02 10:00:00', [], [ 'post_tag' => [ 'zebra' ] ] ),
			'f' => $this->make( 'tagkind', 'Post F', '2026-01-01 10:00:00', [], [ 'post_tag' => [ 'banana' ] ] ),
		];
	}

	/**
	 * Months in America/Chicago, one post late on the last day of a month.
	 *
	 * @return array<string, int>
	 */
	private function months(): array {
		update_option( 'timezone_string', 'America/Chicago' );

		return [
			'september' => $this->make( 'monthkind', 'September', '2026-09-01 00:30:00' ),
			'late'      => $this->make( 'monthkind', 'Late August', '2026-08-31 23:30:00' ),
			'early'     => $this->make( 'monthkind', 'Early August', '2026-08-02 10:00:00' ),
			'july'      => $this->make( 'monthkind', 'July', '2026-07-15 10:00:00' ),
		];
	}

	/**
	 * An inherited Query Loop whose Post Template holds an entry block.
	 *
	 * @param string               $entry Entry block name.
	 * @param array<string, mixed> $attrs Entry attributes.
	 * @param bool                 $title Whether core prints each post's title.
	 */
	private function loop( string $entry, array $attrs = [], bool $title = true ): string {
		$block = '<!-- wp:' . $entry . ( $attrs ? ' ' . wp_json_encode( $attrs ) : '' ) . ' /-->';

		return '<!-- wp:query {"queryId":7,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template -->' . ( $title ? '<!-- wp:post-title /-->' : '' ) . $block . '<!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * Serve a kind archive from a template holding the given content and render it.
	 *
	 * @param string $kind     Kind slug.
	 * @param string $template Template content.
	 * @param int    $page     Page number.
	 */
	private function serve( string $kind, string $template, int $page = 1 ): string {
		$swap = static function ( $templates ) use ( $kind, $template ) {
			foreach ( $templates as $found ) {
				if ( in_array( $found->slug, [ 'taxonomy-kind', "taxonomy-kind-{$kind}" ], true ) ) {
					$found->content = $template;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$url = get_term_link( $kind, 'kind' );
		$this->assertIsString( $url );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );
		$html = do_blocks( $template );
		remove_filter( 'get_block_templates', $swap );

		return $html;
	}

	/**
	 * Each section: heading tag and text, then its items' titles.
	 *
	 * @return array<int, array{0:string,1:string[]}>
	 */
	private function sections( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$titles = [];
			foreach ( $xpath->query( './ul/li//*[contains(@class, "wp-block-post-title") or @class="line"]', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$heading = $section->firstChild;
			$out[]   = [ $heading ? $heading->nodeName . ':' . trim( $heading->textContent ) : '', $titles ];
		}

		return $out;
	}

	/**
	 * Section headings on each page of a kind archive.
	 *
	 * @param string               $kind  Kind slug.
	 * @param array<string, mixed> $attrs Marker attributes.
	 * @param int                  $pages Pages to read.
	 * @return array<int, string[]>
	 */
	private function headings_by_page( string $kind, array $attrs, int $pages ): array {
		$out = [];
		for ( $page = 1; $page <= $pages; $page++ ) {
			$out[ $page ] = array_column( $this->sections( $this->serve( $kind, $this->loop( self::MARKER, $attrs ), $page ) ), 0 );
		}

		return $out;
	}

	/**
	 * Post IDs of a kind in a source's grouped order.
	 *
	 * @param string               $kind   Kind slug.
	 * @param string               $source Source id.
	 * @param array<string, mixed> $args   Extra query args.
	 * @return int[]
	 */
	private function grouped_ids( string $kind, string $source, array $args = [] ): array {
		$query = new WP_Query(
			array_merge(
				[
					'post_type'         => 'post',
					'posts_per_page'    => -1,
					'fields'            => 'ids',
					'pkiw_group_source' => $source,
					'tax_query'         => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => $kind ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				],
				$args
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Assert that the SQL order keeps each PHP group together.
	 *
	 * @param string $source Source id.
	 * @param int[]  $ids    Post IDs in SQL order.
	 */
	private function assert_contiguous( string $source, array $ids ): void {
		$seen = [];
		$last = null;
		foreach ( $ids as $id ) {
			$key = Grouped_Archive::source( $source )->group_of( get_post( $id ) )->key();
			if ( $key !== $last ) {
				$this->assertNotContains( $key, $seen, "Group \"{$key}\" of {$source} comes back after another group." );
				$seen[] = $key;
				$last   = $key;
			}
		}
	}

	/**
	 * The editor's render of an entry block for one post.
	 *
	 * @param string               $name    Block name.
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $attrs   Attributes.
	 * @param array<string, mixed> $params  Extra request params.
	 */
	private function editor_render( string $name, int $post_id, array $attrs = [], array $params = [] ): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . $name );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		if ( $attrs ) {
			$request->set_param( 'attributes', $attrs );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return (string) $response->get_data()['rendered'];
	}

	/**
	 * Post IDs the REST posts route returns for a kind in grouped order.
	 *
	 * @param string $kind Kind slug.
	 * @return int[]
	 */
	private function rest_ids( string $kind ): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'kind', [ get_term_by( 'slug', $kind, 'kind' )->term_id ] );
		$request->set_param( 'orderby', 'pkiw_group' );
		$request->set_param( 'per_page', 20 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
	}

	// Registry.

	public function test_a_source_registers_looks_up_and_unregisters(): void {
		$source = new Meta_Source( 'test_tmp', [ '_pkiw_test_tmp' ] );
		Grouped_Archive::register_source( $source, 'tmpkind' );

		$this->assertSame( $source, Grouped_Archive::source( 'test_tmp' ) );
		$this->assertContains( 'tmpkind', Grouped_Archive::grouped_kinds() );
		$this->assertSame( [ 'eat', 'drink' ], array_slice( Grouped_Archive::grouped_kinds(), 0, 2 ), 'The menu kinds stay first.' );

		Grouped_Archive::unregister_source( 'test_tmp' );
		$this->assertNull( Grouped_Archive::source( 'test_tmp' ) );
		$this->assertNotContains( 'tmpkind', Grouped_Archive::grouped_kinds(), 'Unregistering drops the kind default too.' );
	}

	public function test_entry_blocks_register_and_the_menu_entry_registers_itself(): void {
		$this->assertTrue( Grouped_Archive::is_entry_block( 'post-kinds-indieweb/menu-entry' ) );
		$entries = Grouped_Archive::editor_entries();
		$this->assertSame( [ 'fixed' => false ], $entries['post-kinds-indieweb/menu-entry'] );
		$this->assertSame( [ 'fixed' => true ], $entries[ self::MONTHS ], 'An entry that fixes a date source says so to the editor.' );

		Grouped_Archive::unregister_entry_block( self::MARKER );
		$this->assertFalse( Grouped_Archive::is_entry_block( self::MARKER ) );
	}

	public function test_month_kinds_are_frozen_in_layout_order(): void {
		$this->assertSame(
			[ 'photo', 'chicken', 'favorite', 'acquisition', 'repost', 'article' ],
			\PKIW\Kind_Archive_Layouts::MONTH_KINDS
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function month_kinds(): array {
		return [
			'photo'       => [ 'photo' ],
			'chicken'     => [ 'chicken' ],
			'favorite'    => [ 'favorite' ],
			'acquisition' => [ 'acquisition' ],
			'repost'      => [ 'repost' ],
			'article'     => [ 'article' ],
		];
	}

	/**
	 * @dataProvider month_kinds
	 */
	public function test_month_kinds_group_posts_by_local_post_month( string $kind ): void {
		update_option( 'timezone_string', 'America/New_York' );
		$id     = $this->make( $kind, "{$kind} late", '2026-08-31 23:30:00' );
		$source = Grouped_Archive::source( 'post_month' );

		$this->assertInstanceOf( Date_Source::class, $source );
		$this->assertSame( 'post_month', $source->id() );
		$this->assertSame( '2026-08', Grouped_Archive::group_of_post( $kind, $id )->key() );
		$this->assertSame( 'August 2026', Grouped_Archive::group_label_of_post( $kind, $id ) );
	}

	public function test_grouped_kinds_include_the_month_kinds(): void {
		foreach ( [ 'photo', 'chicken', 'favorite', 'acquisition', 'repost', 'article' ] as $kind ) {
			$this->assertContains( $kind, Grouped_Archive::grouped_kinds(), "{$kind} should be grouped by post_month." );
		}
	}

	/**
	 * @dataProvider month_kinds
	 */
	public function test_month_kind_feeds_keep_rss_size_and_html_archives_group_by_month( string $kind ): void {
		update_option( 'posts_per_rss', 3 );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make( $kind, "{$kind} {$i}", '2026-08-0' . ( $i + 1 ) . ' 10:00:00' );
		}

		$template = $this->loop( 'post-kinds-indieweb/archive-sections', [ 'linesPerPage' => 20 ], false );
		$swap     = static function ( $templates ) use ( $template ) {
			foreach ( $templates as $found ) {
				if ( str_starts_with( $found->slug, 'taxonomy-kind' ) ) {
					$found->content = $template;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );

		try {
			$link = get_term_link( $kind, 'kind' );
			$this->assertIsString( $link );

			$this->go_to( add_query_arg( 'feed', 'rss2', $link ) );
			global $wp_query;
			$this->assertTrue( $wp_query->is_feed() );
			$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_source' ) );
			$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_by' ) );
			$this->assertSame( 3, (int) $wp_query->get( 'posts_per_page' ) );

			$this->go_to( $link );
			// go_to() unsets the global, which breaks the reference taken above.
			$wp_query = $GLOBALS['wp_query'];
			$this->assertFalse( $wp_query->is_feed() );
			$this->assertSame( 'post_month', (string) $wp_query->get( 'pkiw_group_source' ) );
			$this->assertSame( 20, (int) $wp_query->get( 'posts_per_page' ) );
		} finally {
			remove_filter( 'get_block_templates', $swap );
		}
	}

	/**
	 * Inputs the registry and the bundled sources refuse.
	 *
	 * @return array<string, array{0:\Closure}>
	 */
	public function data_bad_input(): array {
		return [
			'source id with a space'             => [ static fn() => new Meta_Source( 'bad id', [ '_k' ] ) ],
			'source id over 64 characters'       => [ static fn() => new Meta_Source( str_repeat( 'a', 65 ), [ '_k' ] ) ],
			'meta source with no key'            => [ static fn() => new Meta_Source( 'ok', [ '' ] ) ],
			'cases source with no case'          => [ static fn() => new Cases_Source( 'ok', [] ) ],
			'case with no key'                   => [ static fn() => new Cases_Source( 'ok', [ 'video' => [] ] ) ],
			'case id with capitals'              => [ static fn() => new Cases_Source( 'ok', [ 'Video' => [ '_k' ] ] ) ],
			'term source with no taxonomy'       => [ static fn() => new Term_Source( 'ok', '' ) ],
			'date source with an unknown unit'   => [ static fn() => new Date_Source( 'ok', 'fortnight' ) ],
			'label map with an unknown fallback' => [ static fn() => Label_Mode::map( [], 'upper' ) ],
			'entry block name with no namespace' => [ static fn() => Grouped_Archive::register_entry_block( 'menu-entry' ) ],
			'entry block with an unknown shape'  => [ static fn() => Grouped_Archive::register_entry_block( 'pkiw-test/x', [ 'shape' => 'grid' ] ) ],
			'entry block fixing a meta source'   => [ static fn() => Grouped_Archive::register_entry_block( 'pkiw-test/x', [ 'source' => 'test_status' ] ) ],
			'entry block fixing an unknown one'  => [ static fn() => Grouped_Archive::register_entry_block( 'pkiw-test/x', [ 'source' => 'nope' ] ) ],
			'entry block with an unknown list'   => [ static fn() => Grouped_Archive::register_entry_block( 'pkiw-test/x', [ 'list' => 'dl' ] ) ],
		];
	}

	/**
	 * @dataProvider data_bad_input
	 *
	 * @param \Closure $make Builds or registers the bad input.
	 */
	public function test_bad_input_is_refused( \Closure $make ): void {
		$this->expectException( InvalidArgumentException::class );
		$make();
	}

	public function test_an_unknown_group_source_is_ignored(): void {
		$p = $this->shelf();

		$this->assertSame( array_values( $p ), $this->grouped_ids( 'shelfkind', 'nope' ), 'An unregistered source leaves the query newest first.' );
	}

	public function test_the_group_fields_filter_adds_a_legacy_kind_and_registers_nothing(): void {
		$fields = static fn( array $map ): array => $map + [ 'legkind' => '_pkiw_test_series' ];
		add_filter( 'pkiw_archive_group_fields', $fields );
		$this->make( 'legkind', 'Second', '2026-01-03 10:00:00', [ '_pkiw_test_series' => 'beta' ] );
		$this->make( 'legkind', 'First', '2026-01-02 10:00:00', [ '_pkiw_test_series' => 'Alpha' ] );
		$this->make( 'legkind', 'Loose', '2026-01-01 10:00:00' );

		$sections = $this->sections( $this->serve( 'legkind', $this->loop( self::MARKER ) ) );
		global $wp_query;
		$by    = $wp_query->get( 'pkiw_group_by' );
		$named = $wp_query->get( 'pkiw_group_source' );
		remove_filter( 'pkiw_archive_group_fields', $fields );

		$this->assertSame( '_pkiw_test_series', $by );
		$this->assertSame( '', (string) $named );
		$this->assertSame(
			[
				[ 'h2:Alpha', [ 'First' ] ],
				[ 'h2:Beta', [ 'Second' ] ],
				[ 'h2:Other', [ 'Loose' ] ],
			],
			$sections,
			'A legacy kind keeps the #230 labels: first letter upper, empty group "Other".'
		);
		$this->assertNull( Grouped_Archive::source( '_pkiw_test_series' ) );
		$this->assertNull( Grouped_Archive::source( 'legacy' ) );
	}

	public function test_the_group_source_filter_can_turn_grouping_off_or_swap_the_source(): void {
		$p   = $this->shelf();
		$off = static fn( $id, string $kind ) => 'shelfkind' === $kind ? null : $id;
		add_filter( 'pkiw_archive_group_source', $off, 10, 2 );
		$html = $this->serve( 'shelfkind', $this->loop( self::LINE, [], false ) );
		remove_filter( 'pkiw_archive_group_source', $off, 10 );

		global $wp_query;
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_source' ) );
		$this->assertSame( [], $this->sections( $html ) );
		$this->assertSame( array_values( $p ), array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) ), 'With grouping off the archive is newest first.' );
		$this->assertSame( 0, substr_count( $html, 'data-in-section="1"' ) );
		$this->assertSame( 9, substr_count( $html, 'data-in-section="0"' ), 'With no section above it a line sits a heading level up.' );

		$year = static fn( $id, string $kind ) => 'shelfkind' === $kind ? 'test_year' : $id;
		add_filter( 'pkiw_archive_group_source', $year, 10, 2 );
		$sections = $this->sections( $this->serve( 'shelfkind', $this->loop( self::MARKER ) ) );
		remove_filter( 'pkiw_archive_group_source', $year, 10 );
		$this->assertSame( [ 'h2:2026' ], array_column( $sections, 0 ), 'The filter can name another registered source.' );
	}

	public function test_registered_defaults_apply_when_the_template_omits_them(): void {
		$this->shelf();
		$this->serve( 'shelfkind', $this->loop( self::PAIRS ) );

		global $wp_query;
		$this->assertSame( 2, (int) $wp_query->get( 'posts_per_page' ), 'The block type\'s linesPerPage default sets the page size.' );
		$this->assertSame( 5, (int) $wp_query->max_num_pages );

		$settings = get_block_editor_server_block_settings();
		$this->assertSame( 2, $settings[ self::PAIRS ]['attributes']['linesPerPage']['default'], 'The editor gets the default from the server.' );
	}

	public function test_an_entry_outside_the_inheriting_loop_does_not_configure_the_archive(): void {
		$this->shelf();
		$kind     = get_term_by( 'slug', 'shelfkind', 'kind' );
		$template = '<!-- wp:group --><div class="wp-block-group"><!-- wp:' . self::PAIRS . ' /--></div><!-- /wp:group -->'
			. '<!-- wp:query {"queryId":3,"query":{"perPage":3,"postType":"post","inherit":false,"taxQuery":{"include":{"kind":[' . $kind->term_id . ']}}}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- wp:' . self::MARKER . ' {"linesPerPage":3} /--><!-- /wp:post-template --></div><!-- /wp:query -->'
			. '<!-- wp:query {"queryId":4,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$this->serve( 'shelfkind', $template );

		global $wp_query;
		$this->assertSame( 20, (int) $wp_query->get( 'posts_per_page' ), 'Only an entry in the inheriting loop sets the archive\'s page size.' );
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_source' ) );
	}

	public function test_an_entry_in_a_loop_nested_in_the_archive_items_belongs_to_that_loop(): void {
		$this->shelf();
		$nested = '<!-- wp:query {"queryId":6,"query":{"perPage":1,"postType":"post","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:' . self::MARKER . ' {"linesPerPage":2,"showSections":false} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$archive = static fn( string $items ): string => '<!-- wp:query {"queryId":7,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template -->' . $items . '<!-- /wp:post-template --></div><!-- /wp:query -->';

		$this->serve( 'shelfkind', $archive( '<!-- wp:post-title /-->' . $nested ) );
		global $wp_query;
		$this->assertSame( 20, (int) $wp_query->get( 'posts_per_page' ), 'An entry in a loop inside each item doesn\'t size the archive.' );
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_source' ) );

		// go_to() unsets the global, so read the new request's query.
		$html = $this->serve( 'shelfkind', $archive( $nested . '<!-- wp:' . self::LINE . ' {"linesPerPage":4} /-->' ) );
		$this->assertSame( 4, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ), 'The archive\'s own entry sets its page size, after a nested one.' );
		$this->assertSame(
			[
				[ 'h2:Currently reading', [ 'Dune', 'Beloved' ] ],
				[ 'h2:To read', [ 'Emma' ] ],
				[ 'h2:Finished', [ 'Ulysses' ] ],
			],
			$this->sections( $html ),
			'The nested entry\'s showSections false doesn\'t turn the archive\'s sections off.'
		);
	}

	public function test_an_entry_fixed_to_a_source_id_follows_what_is_registered_under_it(): void {
		$this->shelf();
		$plan = function (): string {
			$this->serve( 'shelfkind', $this->loop( self::MONTHS ) );
			global $wp_query;

			return (string) $wp_query->get( 'pkiw_group_source' );
		};
		$this->assertSame( 'test_month', $plan() );

		Grouped_Archive::register_source( new Meta_Source( 'test_month', [ '_pkiw_test_status' ] ) );
		$this->assertSame( [ 'fixed' => false ], Grouped_Archive::editor_entries()[ self::MONTHS ], 'A source that replaced the date source under its id fixes nothing.' );
		$this->assertSame( 'test_status', $plan(), 'The entry falls back to the kind\'s source.' );

		Grouped_Archive::unregister_source( 'test_month' );
		$this->assertSame( [ 'fixed' => false ], Grouped_Archive::editor_entries()[ self::MONTHS ], 'An unregistered source fixes nothing.' );
		$this->assertSame( 'test_status', $plan() );
	}

	// Meta sources.

	public function test_explicit_order_files_statuses_in_order_with_the_empty_group_last_or_first(): void {
		$p = $this->shelf();

		$this->assertSame(
			[ $p['dune'], $p['beloved'], $p['emma'], $p['ulysses'], $p['kindred'], $p['middlemarch'], $p['walden'], $p['persuasion'], $p['ada'] ],
			$this->grouped_ids( 'shelfkind', 'test_status' ),
			'Listed statuses in order, an unlisted one after them, the empty group last.'
		);
		$this->assertSame(
			[ $p['walden'], $p['middlemarch'], $p['ulysses'], $p['kindred'], $p['emma'], $p['dune'], $p['beloved'], $p['persuasion'], $p['ada'] ],
			$this->grouped_ids( 'shelfkind', 'test_status', [ 'pkiw_group_order' => 'DESC' ] ),
			'Z to A reverses the listed order; the empty group stays last.'
		);
		$this->assertSame(
			[ $p['persuasion'], $p['ada'], $p['dune'], $p['beloved'], $p['emma'], $p['ulysses'], $p['kindred'], $p['middlemarch'], $p['walden'] ],
			$this->grouped_ids( 'shelfkind', 'test_status', [ 'pkiw_group_empty' => 'first' ] )
		);
		$this->assert_contiguous( 'test_status', $this->grouped_ids( 'shelfkind', 'test_status' ) );

		$this->assertSame(
			[
				[ 'h2:Currently reading', [ 'Dune', 'Beloved' ] ],
				[ 'h2:To read', [ 'Emma' ] ],
				[ 'h2:Finished', [ 'Ulysses', 'Kindred' ] ],
				[ 'h2:Abandoned', [ 'Middlemarch' ] ],
				[ 'h2:paused', [ 'Walden' ] ],
				[ 'h2:Other', [ 'Persuasion', 'Ada' ] ],
			],
			$this->sections( $this->serve( 'shelfkind', $this->loop( self::MARKER ) ) ),
			'Spaces and capitals around a value don\'t split it, a blank value is empty, a value the map lacks prints as stored.'
		);
	}

	public function test_a_post_with_no_row_is_empty_whatever_default_its_key_registers(): void {
		$p = $this->shelf();
		$this->assertSame( 'reading', get_post_meta( $p['persuasion'], '_pkiw_test_status', true ), 'The key registers a default.' );

		$sections = $this->sections( $this->serve( 'shelfkind', $this->loop( self::MARKER ) ) );
		$this->assertSame( [ 'h2:Other', [ 'Persuasion', 'Ada' ] ], end( $sections ), 'It files under Other, not "Currently reading".' );
		$this->assertTrue( Grouped_Archive::group_of_post( 'shelfkind', $p['persuasion'] )->is_empty() );
		$this->assertSame( '<h2 class="pkiw-group__heading">Other</h2>', $this->editor_render( self::MARKER, $p['persuasion'] ), 'The preview agrees.' );
	}

	public function test_several_keys_read_the_first_that_has_a_value(): void {
		$p = $this->series();

		$this->assertSame( [ $p['b'], $p['d'], $p['a'], $p['c'], $p['e'] ], $this->grouped_ids( 'multikind', 'test_series' ) );
		$this->assertSame(
			[
				[ 'h2:Discworld', [ 'Mort', 'Guards' ] ],
				[ 'h2:Earthsea', [ 'Wizard', 'Tombs' ] ],
				[ 'h2:Other', [ 'Standalone' ] ],
			],
			$this->sections( $this->serve( 'multikind', $this->loop( self::MARKER ) ) ),
			'An empty or blank first key falls through to the second.'
		);
	}

	// Cases.

	public function test_cases_file_a_video_id_ahead_of_a_board_id(): void {
		$p = $this->plays();

		$this->assertSame( [ $p['catan'], $p['wingspan'], $p['azul'], $p['hades'], $p['celeste'], $p['tag'] ], $this->grouped_ids( 'playkind', 'test_play' ) );
		$this->assert_contiguous( 'test_play', $this->grouped_ids( 'playkind', 'test_play' ) );
		$this->assertSame(
			[
				[ 'h2:Game Night', [ 'Catan', 'Wingspan', 'Azul' ] ],
				[ 'h2:Video games', [ 'Hades', 'Celeste' ] ],
				[ 'h2:Play', [ 'Tag' ] ],
			],
			$this->sections( $this->serve( 'playkind', $this->loop( self::MARKER, [ 'emptyLabel' => 'Play' ] ) ) ),
			'Celeste has both ids and files as a video game; a blank RAWG id leaves Wingspan a board game.'
		);
		$this->assertSame( 'video', Grouped_Archive::group_of_post( 'playkind', $p['celeste'] )->key(), 'One helper gives single and Stream scope the same key.' );
		$this->assertSame( 'board', Grouped_Archive::group_of_post( 'playkind', $p['wingspan'] )->key() );
		$this->assertNull( Grouped_Archive::group_of_post( 'listen', $p['hades'] ), 'A kind that isn\'t grouped has no group.' );
	}

	public function test_grouping_leaves_the_counts_alone(): void {
		$this->plays();
		$this->tagged();
		foreach ( [ 'playkind' => 'test_play', 'tagkind' => 'test_tags' ] as $kind => $source ) {
			$args    = [
				'post_type'      => 'post',
				'posts_per_page' => 4,
				'tax_query'      => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => $kind ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			];
			$plain   = new WP_Query( $args );
			$grouped = new WP_Query( $args + [ 'pkiw_group_source' => $source ] );
			$this->assertSame( $plain->found_posts, $grouped->found_posts, $kind );
			$this->assertSame( $plain->max_num_pages, $grouped->max_num_pages, $kind );
			$this->assertSame( 4, count( $grouped->posts ), $kind );
		}
	}

	// Terms.

	public function test_terms_file_under_the_first_name_and_same_names_stay_apart(): void {
		$p = $this->tagged();

		$this->assertSame( [ $p['a'], $p['d'], $p['b'], $p['f'], $p['e'], $p['c'] ], $this->grouped_ids( 'tagkind', 'test_tags' ) );
		$this->assertSame(
			[
				[ 'h2:Apple', [ 'Post A' ] ],
				[ 'h2:Apple', [ 'Post D' ] ],
				[ 'h2:banana', [ 'Post B', 'Post F' ] ],
				[ 'h2:zebra', [ 'Post E' ] ],
				[ 'h2:Other', [ 'Post C' ] ],
			],
			$this->sections( $this->serve( 'tagkind', $this->loop( self::MARKER ) ) ),
			'First tag by name, case folded; two tags named Apple are two sections; untagged posts go last.'
		);
	}

	public function test_a_query_filtered_to_one_tag_files_its_posts_under_that_tag(): void {
		$p     = $this->tagged();
		$query = new WP_Query(
			[
				'post_type'         => 'post',
				'posts_per_page'    => -1,
				'tag'               => 'zebra',
				'pkiw_group_source' => 'test_tags',
				'tax_query'         => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => 'tagkind' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);

		$this->assertSame( [ $p['a'], $p['e'] ], wp_list_pluck( $query->posts, 'ID' ) );
		$source = Grouped_Archive::source( 'test_tags' );
		$this->assertSame( 'zebra', $source->group_of( get_post( $p['a'] ), $query )->raw(), 'Post A files under the tag the page is filtered to, not under Apple.' );
		$this->assertSame( 'Apple', $source->group_of( get_post( $p['a'] ) )->raw(), 'Unfiltered, it files under Apple.' );
	}

	public function test_the_default_category_files_last_under_its_own_name(): void {
		$zeta  = self::factory()->category->create( [ 'name' => 'Zeta' ] );
		$alpha = self::factory()->category->create( [ 'name' => 'Alpha' ] );
		$beta  = self::factory()->category->create( [ 'name' => 'Beta' ] );
		$none  = (int) get_option( 'default_category' );
		$p1    = $this->make( 'catkind', 'P1', '2026-01-05 10:00:00', [], [ 'category' => [ $zeta ] ] );
		$p2    = $this->make( 'catkind', 'P2', '2026-01-04 10:00:00', [], [ 'category' => [ $alpha, $zeta ] ] );
		$p3    = $this->make( 'catkind', 'P3', '2026-01-03 10:00:00', [], [ 'category' => [ $none ] ] );
		$p4    = $this->make( 'catkind', 'P4', '2026-01-02 10:00:00', [], [ 'category' => [ $none, $beta ] ] );

		$this->assertSame( [ $p2, $p4, $p1, $p3 ], $this->grouped_ids( 'catkind', 'test_cats' ) );
		$this->assertSame( [ $p1, $p4, $p2, $p3 ], $this->grouped_ids( 'catkind', 'test_cats', [ 'pkiw_group_order' => 'DESC' ] ), 'Z to A still files the default category last.' );
		$this->assertSame(
			[ 'h2:Alpha', 'h2:Beta', 'h2:Zeta', 'h2:' . get_term( $none )->name ],
			array_column( $this->sections( $this->serve( 'catkind', $this->loop( self::MARKER ) ) ), 0 )
		);
	}

	public function test_terms_of_a_taxonomy_visitors_cannot_view_never_head_a_section(): void {
		register_taxonomy( 'pkiw_test_private', 'post', [ 'public' => false ] );
		Grouped_Archive::register_source( new Term_Source( 'test_private', 'pkiw_test_private' ), 'privkind' );
		$this->make( 'privkind', 'Post A', '2026-01-03 10:00:00', [], [ 'pkiw_test_private' => [ 'Secret project' ] ] );
		$this->make( 'privkind', 'Post B', '2026-01-02 10:00:00', [], [ 'pkiw_test_private' => [ 'Another secret' ] ] );
		$this->make( 'privkind', 'Post C', '2026-01-01 10:00:00' );

		$html = $this->serve( 'privkind', $this->loop( self::MARKER ) );
		Grouped_Archive::unregister_source( 'test_private' );
		unregister_taxonomy( 'pkiw_test_private' );

		$this->assertSame( [ [ 'h2:Other', [ 'Post A', 'Post B', 'Post C' ] ] ], $this->sections( $html ), 'Every post files in the empty group, newest first.' );
		$this->assertStringNotContainsString( 'secret', strtolower( $html ) );
	}

	// Dates.

	public function test_month_buckets_follow_the_site_timezone(): void {
		$this->months();

		$this->assertSame(
			[
				[ 'h2:September 2026', [ 'September' ] ],
				[ 'h2:August 2026', [ 'Late August', 'Early August' ] ],
				[ 'h2:July 2026', [ 'July' ] ],
			],
			$this->sections( $this->serve( 'monthkind', $this->loop( self::MARKER ) ) ),
			'11:30 pm on August 31 in Chicago is September 1 in UTC and still files under August.'
		);
		$this->assertSame(
			[ 'h2:Sep 2026', 'h2:Aug 2026', 'h2:Jul 2026' ],
			array_column( $this->sections( $this->serve( 'monthkind', $this->loop( self::MARKER, [ 'dateFormat' => 'M Y' ] ) ) ), 0 )
		);
	}

	public function test_article_archive_sections_use_the_month_source_and_date_format(): void {
		update_option( 'timezone_string', 'America/Chicago' );
		$this->make( 'article', 'September article', '2026-09-02 10:00:00' );
		$this->make( 'article', 'Late August article', '2026-08-31 23:30:00' );
		$this->make( 'article', 'Early August article', '2026-08-01 10:00:00' );

		$short = $this->sections( $this->serve( 'article', $this->loop( 'post-kinds-indieweb/archive-sections', [ 'dateFormat' => 'M Y' ] ) ) );
		$this->assertSame( [ 'h2:Sep 2026', 'h2:Aug 2026' ], array_column( $short, 0 ) );

		$long = $this->sections( $this->serve( 'article', $this->loop( 'post-kinds-indieweb/archive-sections' ) ) );
		$this->assertSame( [ 'h2:September 2026', 'h2:August 2026' ], array_column( $long, 0 ) );
	}

	public function test_a_loop_ordered_by_title_still_keeps_each_month_together(): void {
		$this->months();
		$kind = get_term_by( 'slug', 'monthkind', 'kind' );
		$loop = '<!-- wp:query {"queryId":5,"query":{"perPage":10,"postType":"post","order":"asc","orderBy":"title","inherit":false,"taxQuery":{"include":{"kind":[' . $kind->term_id . ']}}}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- wp:' . self::MONTHS . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';

		$this->go_to( home_url( '/' ) );
		$this->assertSame(
			[
				[ 'h2:September 2026', [ 'September' ] ],
				[ 'h2:August 2026', [ 'Late August', 'Early August' ] ],
				[ 'h2:July 2026', [ 'July' ] ],
			],
			$this->sections( do_blocks( $loop ) ),
			'An entry that fixes a month bucket orders newest first whatever order the loop asks for.'
		);
	}

	public function test_year_and_week_buckets(): void {
		$source = new Date_Source( 'test_tmp_year', 'year' );
		$new    = $this->make( 'yearkind', 'New year', '2027-01-02 10:00:00' );
		$old    = $this->make( 'yearkind', 'Old year', '2026-12-31 10:00:00' );
		$this->assertSame( '2027', $source->group_of( get_post( $new ) )->key() );
		$this->assertSame( '2026', $source->label( $source->group_of( get_post( $old ) ), [] ) );

		$week = Grouped_Archive::source( 'test_week' );
		$wed  = $this->make( 'weekkind', 'Midweek', '2026-09-23 10:00:00' );
		update_option( 'start_of_week', 1 );
		$this->assertSame( '2026-09-21', $week->group_of( get_post( $wed ) )->key(), 'A Monday-start week.' );
		$this->assertSame( 'September 21–27, 2026', $week->label( $week->group_of( get_post( $wed ) ), [] ) );
		update_option( 'start_of_week', 0 );
		$this->assertSame( '2026-09-20', $week->group_of( get_post( $wed ) )->key(), 'A Sunday-start week.' );
		$this->assertSame( 'September 20–26, 2026', $week->label( $week->group_of( get_post( $wed ) ), [] ) );

		update_option( 'start_of_week', 1 );
		$this->assertSame( 'September 28 – October 4, 2026', $week->label( new Archive_Group( '2026-09-28', '2026-09-28' ), [] ) );
		$this->assertSame( 'December 28, 2026 – January 3, 2027', $week->label( new Archive_Group( '2026-12-28', '2026-12-28' ), [] ) );
	}

	public function test_a_week_that_spans_new_year_holds_a_post_from_either_side(): void {
		update_option( 'timezone_string', 'America/Chicago' );
		$week = Grouped_Archive::source( 'test_week' );
		$eve  = get_post( $this->make( 'weekkind', 'New Year night', '2026-01-01 23:30:00' ) );
		$tue  = get_post( $this->make( 'weekkind', 'Tuesday before', '2025-12-30 10:00:00' ) );

		foreach ( [ 1 => '2025-12-29', 0 => '2025-12-28', 6 => '2025-12-27' ] as $start => $key ) {
			update_option( 'start_of_week', $start );
			$this->assertSame( $key, $week->group_of( $eve )->key(), "11:30 pm on January 1 in Chicago, weeks starting on day {$start}." );
			$this->assertSame( $key, $week->group_of( $tue )->key(), "December 30 shares that week, start day {$start}." );
		}

		update_option( 'start_of_week', 1 );
		$this->assertSame(
			[ [ 'h2:December 29, 2025 – January 4, 2026', [ 'New Year night', 'Tuesday before' ] ] ],
			$this->sections( $this->serve( 'weekkind', $this->loop( self::MARKER ) ) )
		);
	}

	public function test_grouping_works_beside_a_meta_query_and_a_second_tax_clause(): void {
		$p     = $this->shelf();
		$args  = [
			'post_type'      => 'post',
			'posts_per_page' => 3,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				[
					'key'     => '_pkiw_test_status',
					'compare' => 'EXISTS',
				],
				[
					'key'   => '_pkiw_test_series',
					'value' => 'Earthsea',
				],
			],
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'relation' => 'AND',
				[
					'taxonomy' => 'kind',
					'field'    => 'slug',
					'terms'    => 'shelfkind',
				],
				[
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => (int) get_option( 'default_category' ),
				],
			],
		];
		$plain = new WP_Query( $args );
		$ids   = [];
		for ( $page = 1; $page <= 3; $page++ ) {
			$grouped = new WP_Query( $args + [ 'pkiw_group_source' => 'test_status', 'paged' => $page ] );
			$this->assertSame( $plain->found_posts, $grouped->found_posts );
			$this->assertSame( $plain->max_num_pages, $grouped->max_num_pages );
			$ids = array_merge( $ids, array_map( 'intval', wp_list_pluck( $grouped->posts, 'ID' ) ) );
		}

		$this->assertSame( 8, $plain->found_posts, 'Every shelf post but Persuasion has a status row.' );
		$this->assertSame( [ $p['dune'], $p['beloved'], $p['emma'], $p['ulysses'], $p['kindred'], $p['middlemarch'], $p['walden'], $p['ada'] ], $ids, 'Three pages of three hold each post once, in grouped order.' );
	}

	// Labels.

	public function test_label_modes(): void {
		$this->assertSame( 'example.com', Label_Mode::verbatim()->format( 'example.com' ) );
		$this->assertSame( 'iOS', Label_Mode::verbatim()->format( 'iOS' ) );
		$this->assertSame( 'Thai', Label_Mode::title()->format( 'thai' ) );
		$map = Label_Mode::map( [ 'coffee' => 'Coffee' ] );
		$this->assertSame( 'Coffee', $map->format( 'COFFEE' ), 'A map matches case-insensitively.' );
		$this->assertSame( 'kvass', $map->format( 'kvass' ), 'A value the map lacks prints as stored.' );
		$this->assertSame( 'Kvass', Label_Mode::map( static fn(): array => [ 'coffee' => 'Coffee' ], 'title' )->format( 'kvass' ), 'Or with its first letter upper.' );
	}

	public function test_an_entry_can_name_groups_and_the_empty_group_falls_back_to_the_source_then_other(): void {
		$this->plays();
		Grouped_Archive::register_source(
			new Cases_Source( 'test_play', [ 'board' => [ '_pkiw_test_bgg' ] ], [], null, static fn(): string => 'Play' ),
			'playkind'
		);

		$this->assertSame(
			[ 'h2:Board games', 'h2:Play' ],
			array_column( $this->sections( $this->serve( 'playkind', $this->loop( self::MARKER, [ 'groupLabels' => [ 'board' => 'Board games' ] ] ) ) ), 0 ),
			'groupLabels names a group; with no emptyLabel the source\'s own empty label prints.'
		);

		Grouped_Archive::register_source( new Cases_Source( 'test_play', [ 'board' => [ '_pkiw_test_bgg' ] ] ), 'playkind' );
		$this->assertSame(
			[ 'h2:board', 'h2:Other' ],
			array_column( $this->sections( $this->serve( 'playkind', $this->loop( self::MARKER ) ) ), 0 ),
			'With neither, the empty group is "Other"; a case id prints as stored.'
		);
	}

	public function test_group_label_of_post_returns_a_term_name_verbatim(): void {
		$id = $this->make( 'tagkind', 'Tagged banana', '2026-02-01 10:00:00', [], [ 'post_tag' => [ 'Banana', 'Zebra' ] ] );

		$this->assertSame( 'Banana', Grouped_Archive::group_label_of_post( 'tagkind', $id ) );
	}

	public function test_group_label_of_post_uses_the_template_empty_label_then_other(): void {
		$id = $this->make( 'tagkind', 'Untagged post', '2026-02-01 10:00:00' );

		$this->go_to( get_term_link( 'tagkind', 'kind' ) );
		$this->assertSame( 'Other', Grouped_Archive::group_label_of_post( 'tagkind', $id ) );

		$swap = function ( $templates ) {
			foreach ( $templates as $found ) {
				if ( in_array( $found->slug, [ 'taxonomy-kind', 'taxonomy-kind-tagkind' ], true ) ) {
					$found->content = $this->loop( self::MARKER, [ 'emptyLabel' => 'Unfiled' ] );
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$this->go_to( get_term_link( 'tagkind', 'kind' ) );
		$this->assertSame( 'Unfiled', Grouped_Archive::group_label_of_post( 'tagkind', $id ) );
		remove_filter( 'get_block_templates', $swap );
		$this->go_to( home_url( '/' ) );
	}

	public function test_group_label_of_post_is_empty_for_a_missing_post_or_an_ungrouped_kind(): void {
		$id = $this->make( 'tagkind', 'Any grouped post', '2026-02-01 10:00:00', [], [ 'post_tag' => [ 'Banana' ] ] );

		$this->assertSame( '', Grouped_Archive::group_label_of_post( 'tagkind', 999999 ) );
		$this->assertSame( '', Grouped_Archive::group_label_of_post( 'nosuchkind', $id ) );
	}

	public function test_a_protected_post_in_a_flagged_source_gets_the_empty_label(): void {
		Grouped_Archive::register_source( new Term_Source( 'test_flagged_tags', 'post_tag', null, '', true ), 'flaggedkind' );
		$id = $this->make( 'flaggedkind', 'Protected tagged', '2026-02-01 10:00:00', [], [ 'post_tag' => [ 'Banana' ] ] );
		wp_update_post(
			[
				'ID'            => $id,
				'post_password' => 'secret',
			]
		);

		$this->assertSame( 'Other', Grouped_Archive::group_label_of_post( 'flaggedkind', $id ) );
		$this->assertSame( 'Banana', Grouped_Archive::group_label_of_post( 'tagkind', $id ) );
	}

	public function test_grouped_kinds_include_reply_and_like_once_cite_host_is_registered(): void {
		$source = Grouped_Archive::source( 'cite_host' );

		$this->assertContains( 'reply', Grouped_Archive::grouped_kinds() );
		$this->assertContains( 'like', Grouped_Archive::grouped_kinds() );
		$this->assertInstanceOf( Meta_Source::class, $source );
		$this->assertSame( 'cite_host', $source->id() );
		$this->assertTrue( $source->files_protected_as_empty() );
		$this->assertSame( 'cite_host', \PKIW\Kind_Archive_Layouts::cite_source()->id() );
	}

	public function test_group_of_post_files_a_like_and_a_reply_under_their_host(): void {
		$like = $this->make( 'like', 'Liked post', '2026-02-04 10:00:00', [ \PKIW\CITE_HOST_META => 'alpha.example' ] );
		$reply = $this->make( 'reply', 'Reply post', '2026-02-03 10:00:00', [ \PKIW\CITE_HOST_META => 'beta.example' ] );
		$empty = $this->make( 'like', 'Like with no host', '2026-02-02 10:00:00' );
		$secret = $this->make( 'like', 'Protected like', '2026-02-01 10:00:00', [ \PKIW\CITE_HOST_META => 'alpha.example' ] );
		wp_update_post(
			[
				'ID'            => $secret,
				'post_password' => 'secret',
			]
		);

		$this->assertSame( 'alpha.example', Grouped_Archive::group_of_post( 'like', $like )->key() );
		$this->assertSame( 'alpha.example', Grouped_Archive::group_of_post( 'like', $like )->raw() );
		$this->assertSame( 'beta.example', Grouped_Archive::group_of_post( 'reply', $reply )->key() );
		$this->assertSame( 'beta.example', Grouped_Archive::group_of_post( 'reply', $reply )->raw() );
		$this->assertTrue( Grouped_Archive::group_of_post( 'like', $empty )->is_empty() );
		$this->assertTrue( Grouped_Archive::group_of_post( 'like', $secret )->is_empty() );
	}

	public function test_group_label_of_post_returns_the_host_for_a_like(): void {
		$like = $this->make( 'like', 'Liked post', '2026-02-04 10:00:00', [ \PKIW\CITE_HOST_META => 'alpha.example' ] );
		$empty = $this->make( 'like', 'Like with no host', '2026-02-03 10:00:00' );

		$this->assertSame( 'alpha.example', Grouped_Archive::group_label_of_post( 'like', $like ) );
		$this->assertSame( 'Other', Grouped_Archive::group_label_of_post( 'like', $empty ) );
	}

	// Page breaks.

	public function test_every_source_repeats_its_plain_label_when_a_group_runs_onto_the_next_page(): void {
		$this->shelf();
		$this->series();
		$this->plays();
		$this->tagged();
		$this->months();

		$cases = [
			'shelfkind' => [ 2, 3, 'Finished' ],
			'multikind' => [ 3, 2, 'Earthsea' ],
			'playkind'  => [ 2, 2, 'Game Night' ],
			'tagkind'   => [ 3, 2, 'banana' ],
			'monthkind' => [ 2, 2, 'August 2026' ],
		];
		foreach ( $cases as $kind => [ $per_page, $page, $label ] ) {
			$pages = $this->headings_by_page( $kind, [ 'linesPerPage' => $per_page ], $page );
			$this->assertSame( 'h2:' . $label, end( $pages[ $page - 1 ] ), "{$kind}: page " . ( $page - 1 ) . ' ends in the group.' );
			$this->assertSame( 'h2:' . $label, $pages[ $page ][0], "{$kind}: page {$page} opens it again under the same label." );
			foreach ( $pages as $headings ) {
				foreach ( $headings as $heading ) {
					$this->assertStringNotContainsStringIgnoringCase( 'continued', $heading, $kind );
				}
			}
		}
	}

	// Sections.

	public function test_only_an_entry_that_asks_exposes_the_group_key(): void {
		$this->plays();
		$html = $this->serve( 'playkind', $this->loop( self::MARKER ) );

		$this->assertStringContainsString( '<section class="pkiw-group" data-pkiw-sections="3" data-pkiw-group="board"><h2 class="pkiw-group__heading">Game Night</h2><ul class="pkiw-group__items">', $html );
		$this->assertStringContainsString( 'data-pkiw-group=""><h2 class="pkiw-group__heading">Other</h2>', $html );
		$this->assertStringContainsString( 'class="pkiw-grouped ', $html, 'Entry blocks that name no classes get the neutral ones.' );
		$this->assertStringNotContainsString( 'data-pkiw-group', $this->serve( 'playkind', $this->loop( self::LINE ) ) );
	}

	public function test_line_entries_can_choose_an_ordered_section_list(): void {
		$this->entry_block( self::OL_LINE, [ 'shape' => Grouped_Archive::ENTRY_LINE, 'list' => 'ol' ] );
		$p = $this->shelf();

		$html = $this->serve( 'shelfkind', $this->loop( self::OL_LINE, [], false ) );
		$this->assertStringContainsString( '<ol class="pkiw-group__items">', $html );
		$this->assertStringNotContainsString( '<ul class="pkiw-group__items">', $html );

		$plain = $this->serve( 'shelfkind', $this->loop( self::LINE, [], false ) );
		$this->assertStringContainsString( '<ul class="pkiw-group__items">', $plain );

		$this->assertStringContainsString( '<ol', $this->editor_render( self::OL_LINE, $p['dune'], [ 'linesPerPage' => 2 ] ) );
	}

	public function test_an_entry_that_asks_gives_each_section_heading_an_id_unique_on_the_page(): void {
		$this->tagged();
		$this->make( 'tagkind', 'Post G', '2026-01-07 10:00:00', [], [ 'post_tag' => [ '日本' ] ] );
		$heading_ids = static function ( string $html ): array {
			preg_match_all( '#<h2 id="([^"]*)" class="pkiw-group__heading">#', $html, $found );
			return $found[1];
		};

		$once = $heading_ids( $this->serve( 'tagkind', $this->loop( self::IDS ) ) );
		$this->assertSame( [ 'pkiw-group-apple', 'pkiw-group-apple-press', 'pkiw-group-banana', 'pkiw-group-zebra', 'pkiw-group-e697a5e69cac', 'pkiw-group-empty' ], $once, 'Ids come from the group slug; an encoded slug keeps only id-safe characters; the empty group is "empty".' );

		$twice = $heading_ids( $this->serve( 'tagkind', $this->loop( self::IDS ) . $this->loop( self::IDS ) ) );
		$this->assertCount( 12, $twice );
		$this->assertSame( $twice, array_values( array_unique( $twice ) ), 'A second grouped loop on the page gets ids of its own.' );
		$this->assertSame( 'pkiw-group-apple-2', $twice[6] );

		$this->assertSame( $once, $heading_ids( $this->serve( 'tagkind', $this->loop( self::IDS ) ) ), 'Each request starts over.' );
		$this->assertStringNotContainsString( ' id="pkiw-group-', $this->serve( 'tagkind', $this->loop( self::MARKER ) ), 'An entry that doesn\'t ask prints headings without ids.' );
	}

	public function test_the_editor_preview_heading_of_an_entry_that_asks_carries_its_id(): void {
		$p = $this->shelf();

		$this->assertSame( '<h2 id="pkiw-group-to-read" class="pkiw-group__heading">To read</h2>', $this->editor_render( self::IDS, $p['emma'] ) );
		$this->assertSame( '<h2 id="pkiw-group-to-read" class="pkiw-group__heading">To read</h2>', $this->editor_render( self::IDS, $p['emma'] ), 'Each preview request starts the ids over.' );
		$this->assertSame( '<h2 class="pkiw-group__heading">To read</h2>', $this->editor_render( self::MARKER, $p['emma'] ) );
	}

	public function test_the_current_group_is_set_while_an_item_renders_and_cleared_after(): void {
		$this->plays();
		$html = $this->serve( 'playkind', $this->loop( self::LINE, [], false ) );

		$this->assertStringContainsString( 'data-in-section="1" data-group="video">Celeste<', $html );
		$this->assertStringContainsString( 'data-in-section="1" data-group="">Tag<', $html );
		$this->assertNull( Grouped_Archive::current_group() );
		$this->assertFalse( Grouped_Archive::is_sectioning() );
	}

	public function test_a_grouped_loop_inside_a_grouped_item_restores_the_outer_state(): void {
		$this->plays();
		$this->months();
		$kind   = get_term_by( 'slug', 'monthkind', 'kind' );
		$inner  = '<!-- wp:query {"queryId":8,"query":{"perPage":10,"postType":"post","inherit":false,"taxQuery":{"include":{"kind":[' . $kind->term_id . ']}}}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- wp:' . self::MONTHS . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$plain  = '<!-- wp:query {"queryId":9,"query":{"perPage":1,"postType":"post","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:' . self::LINE . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$states = [];

		$this->on_item = function ( WP_Post $post ) use ( $inner, $plain, &$states ): string {
			if ( 'Hades' !== $post->post_title ) {
				return '';
			}
			$this->on_item = null;
			$nested        = do_blocks( $inner );
			$states[]      = [ Grouped_Archive::current_group()->key(), Grouped_Archive::is_sectioning() ];
			$ungrouped     = do_blocks( $plain );
			$states[]      = [ Grouped_Archive::current_group()->key(), Grouped_Archive::is_sectioning() ];

			return $nested . $ungrouped;
		};
		$html = $this->serve( 'playkind', $this->loop( self::LINE, [], false ) );

		$this->assertSame( [ [ 'video', true ], [ 'video', true ] ], $states, 'After a nested loop renders, the outer item is still in its section.' );
		$this->assertStringContainsString( '<h2 class="pkiw-group__heading">August 2026</h2>', $html, 'The nested loop printed its own sections.' );
		$this->assertMatchesRegularExpression( '#<li class="wp-block-post[^"]*"><p class="line" data-in-section="0" data-group="-">#', $html, 'A loop with no grouping inside a grouped item renders its lines outside any section.' );
	}

	// Editor preview.

	public function test_the_editor_previews_markers_and_lines_across_a_page_break(): void {
		$p     = $this->shelf();
		$attrs = [ 'linesPerPage' => 2 ];

		$this->assertSame( '<h2 class="pkiw-group__heading">Currently reading</h2>', $this->editor_render( self::MARKER, $p['dune'], $attrs ) );
		$this->assertSame( '<div class="pkiw-group-placeholder" hidden></div>', $this->editor_render( self::MARKER, $p['beloved'], $attrs ) );
		$this->assertSame( '<h2 class="pkiw-group__heading">To read</h2>', $this->editor_render( self::MARKER, $p['emma'], $attrs ) );
		$this->assertSame( '<h2 class="pkiw-group__heading">Finished</h2>', $this->editor_render( self::MARKER, $p['ulysses'], $attrs ) );
		$this->assertSame( '<h2 class="pkiw-group__heading">Finished</h2>', $this->editor_render( self::MARKER, $p['kindred'], $attrs ), 'Kindred opens page 3, so it heads Finished again.' );
		$this->assertSame( '<h3 class="pkiw-group__heading">Currently reading</h3>', $this->editor_render( self::MARKER, $p['dune'], $attrs + [ 'headingLevel' => 3 ] ) );

		$line = $this->editor_render( self::LINE, $p['dune'], $attrs );
		$this->assertSame( [ [ 'h2:Currently reading', [ 'Dune', 'Beloved' ] ] ], $this->sections( $line ) );
		$this->assertStringContainsString( 'data-pkiw-sections="1"', $line );
		$this->assertSame( '<div class="pkiw-group-placeholder" hidden></div>', $this->editor_render( self::LINE, $p['beloved'], $attrs ) );
		$this->assertSame( [ [ 'h2:To read', [ 'Emma' ] ] ], $this->sections( $this->editor_render( self::LINE, $p['emma'], $attrs ) ) );
	}

	public function test_current_page_ids_follow_the_grouped_main_query_page(): void {
		$months = $this->months();

		$this->serve( 'monthkind', $this->loop( self::MARKER, [ 'linesPerPage' => 2 ] ) );
		$this->assertSame( [ $months['september'], $months['late'] ], Grouped_Archive::current_page_ids() );

		$this->serve( 'monthkind', $this->loop( self::MARKER, [ 'linesPerPage' => 2 ] ), 2 );
		$this->assertSame( [ $months['early'], $months['july'] ], Grouped_Archive::current_page_ids() );

		$this->serve( 'monthkind', $this->loop( self::MARKER ) );
		$this->assertSame( [ $months['september'], $months['late'], $months['early'], $months['july'] ], Grouped_Archive::current_page_ids() );

		$this->go_to( home_url( '/' ) );
		$this->assertSame( [], Grouped_Archive::current_page_ids() );
	}

	public function test_current_page_ids_follow_the_editor_preview_page_and_reset_afterwards(): void {
		$months   = $this->months();
		$captured = [];
		$this->go_to( home_url( '/' ) );

		$this->on_item = static function () use ( &$captured ): string {
			$page = Grouped_Archive::current_page_ids();
			if ( $page !== end( $captured ) ) {
				$captured[] = $page;
			}
			return '';
		};

		$this->editor_render( self::LINE, $months['september'], [ 'linesPerPage' => 2 ] );
		$this->assertSame( [], Grouped_Archive::current_page_ids() );
		$this->editor_render( self::LINE, $months['early'], [ 'linesPerPage' => 2 ] );
		$this->assertSame( [], Grouped_Archive::current_page_ids() );

		$this->assertSame(
			[
				[ $months['september'], $months['late'] ],
				[ $months['early'], $months['july'] ],
			],
			$captured
		);
	}

	public function test_a_post_of_two_kinds_previews_as_the_kind_the_template_names(): void {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Tea and noodles',
			]
		);
		wp_set_object_terms( $id, [ 'eat', 'drink' ], 'kind' );
		update_post_meta( $id, '_pkiw_eat_cuisine', 'Thai' );
		update_post_meta( $id, '_pkiw_drink_type', 'tea' );
		$heading = fn( array $params ): string => $this->sections( $this->editor_render( 'post-kinds-indieweb/menu-entry', $id, [], $params ) )[0][0];

		$this->assertSame( 'h2:Thai', $heading( [ 'pkiw_kind' => 'eat' ] ) );
		$this->assertSame( 'h2:Tea', $heading( [ 'pkiw_kind' => 'drink' ] ) );
		$this->assertSame( 'h2:Tea', $heading( [] ), 'With no kind named, the post\'s first kind.' );
		$this->assertSame( 'h2:Tea', $heading( [ 'pkiw_kind' => 'listen' ] ), 'A kind the post doesn\'t have is ignored.' );
	}

	public function test_the_rest_route_orders_by_a_registered_source(): void {
		$s = $this->shelf();
		$t = $this->tagged();

		$this->assertSame( [ $s['dune'], $s['beloved'], $s['emma'], $s['ulysses'], $s['kindred'], $s['middlemarch'], $s['walden'], $s['persuasion'], $s['ada'] ], $this->rest_ids( 'shelfkind' ) );
		$this->assertSame( [ $t['a'], $t['d'], $t['b'], $t['f'], $t['e'], $t['c'] ], $this->rest_ids( 'tagkind' ) );
	}

	// Calendar units and month order (W4-P0E).

	/**
	 * @return array<string, array{string}>
	 */
	public function date_units(): array {
		return [
			'year'  => [ 'year' ],
			'month' => [ 'month' ],
			'week'  => [ 'week' ],
		];
	}

	/**
	 * @dataProvider date_units
	 */
	public function test_unit_key_gives_the_key_and_start_group_of_gives( string $unit ): void {
		update_option( 'timezone_string', 'America/Chicago' );
		$source = new Date_Source( 'test_tmp_unit', $unit );

		foreach ( [ 0, 1 ] as $start_of_week ) {
			update_option( 'start_of_week', $start_of_week );
			foreach ( [ '2024-12-31 10:00:00', '2025-01-01 10:00:00', '2025-12-31 23:30:00', '2026-01-01 00:10:00', '2026-03-08 03:30:00' ] as $date ) {
				$post  = get_post( $this->make( 'weekkind', "At {$date}", $date ) );
				$group = $source->group_of( $post );
				$local = get_post_datetime( $post, 'date', 'local' );
				$this->assertInstanceOf( DateTimeImmutable::class, $local );

				$found = Date_Source::unit_key( $local->setTime( 0, 0 ), $unit );

				$this->assertSame( $group->key(), $found['key'], "{$unit} key of {$date}, week starting on day {$start_of_week}." );
				$this->assertSame( $group->raw(), $found['start'], "{$unit} start of {$date}, week starting on day {$start_of_week}." );
			}
		}
	}

	public function test_unit_key_names_the_week_across_a_year_edge(): void {
		$day = new DateTimeImmutable( '2024-12-31', wp_timezone() );
		$wed = new DateTimeImmutable( '2025-01-01', wp_timezone() );

		update_option( 'start_of_week', 0 );
		$this->assertSame( [ 'key' => '2024-12-29', 'start' => '2024-12-29' ], Date_Source::unit_key( $day, 'week' ) );
		$this->assertSame( [ 'key' => '2024-12-29', 'start' => '2024-12-29' ], Date_Source::unit_key( $wed, 'week' ) );
		update_option( 'start_of_week', 1 );
		$this->assertSame( [ 'key' => '2024-12-30', 'start' => '2024-12-30' ], Date_Source::unit_key( $day, 'week' ) );
		$this->assertSame( [ 'key' => '2024-12-30', 'start' => '2024-12-30' ], Date_Source::unit_key( $wed, 'week' ) );
		$this->assertSame( [ 'key' => '2024', 'start' => '2024-01-01' ], Date_Source::unit_key( $day, 'year' ) );
		$this->assertSame( [ 'key' => '2025-01', 'start' => '2025-01-01' ], Date_Source::unit_key( $wed, 'month' ) );
	}

	public function test_unit_key_refuses_an_unknown_unit(): void {
		$this->expectException( InvalidArgumentException::class );
		Date_Source::unit_key( new DateTimeImmutable( '2026-01-01', wp_timezone() ), 'fortnight' );
	}

	public function test_weeks_file_by_local_time_not_utc_and_across_the_end_of_daylight_saving(): void {
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'start_of_week', 0 );
		$week = Grouped_Archive::source( 'test_week' );

		$late = get_post( $this->make( 'weekkind', 'Saturday night', '2026-08-29 23:30:00' ) );
		$this->assertSame( '2026-08-23', $week->group_of( $late )->key(), 'Saturday August 29, 11:30 pm local is 03:30 UTC on the 30th, and still files in the week of the 23rd.' );

		$dst = get_post( $this->make( 'weekkind', 'Fall back', '2026-11-01 01:30:00' ) );
		$this->assertSame( '2026-11-01', $week->group_of( $dst )->key(), 'The Sunday the clocks go back starts its own week.' );
		$this->assertSame( '2026-11-01', Date_Source::unit_key( new DateTimeImmutable( '2026-11-01 00:00:00', wp_timezone() ), 'week' )['key'] );
	}

	public function test_a_date_source_takes_only_desc_or_asc_within_a_month(): void {
		$this->assertInstanceOf( Date_Source::class, new Date_Source( 'test_tmp_within', 'month', 'asc' ) );
		$this->expectException( InvalidArgumentException::class );
		new Date_Source( 'test_tmp_within', 'month', 'sideways' );
	}

	/**
	 * Six posts in three months; two share a minute and differ by ID.
	 *
	 * @param string $kind Kind slug.
	 * @return array<string, int>
	 */
	private function sleep_nights( string $kind ): array {
		return [
			'aug03'  => $this->make( $kind, 'Aug 3', '2026-08-03 12:00:00' ),
			'aug22a' => $this->make( $kind, 'Aug 22 a', '2026-08-22 12:00:00' ),
			'aug22b' => $this->make( $kind, 'Aug 22 b', '2026-08-22 12:00:00' ),
			'jul01'  => $this->make( $kind, 'Jul 1', '2026-07-01 12:00:00' ),
			'jul24'  => $this->make( $kind, 'Jul 24', '2026-07-24 12:00:00' ),
			'jun06'  => $this->make( $kind, 'Jun 6', '2026-06-06 12:00:00' ),
		];
	}

	public function test_an_ascending_month_source_runs_months_newest_first_and_days_oldest_first(): void {
		$p = $this->sleep_nights( 'monthasckind' );

		$ids = $this->grouped_ids( 'monthasckind', 'test_month_asc' );

		$this->assertSame( [ $p['aug03'], $p['aug22a'], $p['aug22b'], $p['jul01'], $p['jul24'], $p['jun06'] ], $ids );
		$this->assert_contiguous( 'test_month_asc', $ids );

		global $wpdb;
		$query = new WP_Query( [ 'post_type' => 'post', 'posts_per_page' => -1, 'pkiw_group_source' => 'test_month_asc', 'fields' => 'ids' ] );
		$this->assertStringContainsString( "ORDER BY DATE_FORMAT({$wpdb->posts}.post_date,'%Y-%m') DESC, {$wpdb->posts}.post_date ASC, {$wpdb->posts}.ID ASC", $query->request );
	}

	public function test_every_other_date_source_keeps_post_date_descending(): void {
		$p = $this->sleep_nights( 'monthkind' );
		global $wpdb;

		$this->assertSame( [ $p['aug22b'], $p['aug22a'], $p['aug03'], $p['jul24'], $p['jul01'], $p['jun06'] ], $this->grouped_ids( 'monthkind', 'test_month' ) );
		$query = new WP_Query( [ 'post_type' => 'post', 'posts_per_page' => -1, 'pkiw_group_source' => 'test_month', 'fields' => 'ids' ] );
		$this->assertStringContainsString( "ORDER BY {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC", $query->request );
		$this->assertStringNotContainsString( 'DATE_FORMAT', $query->request );
	}

	public function test_an_ascending_month_source_pages_and_previews_in_the_same_order(): void {
		$p = $this->sleep_nights( 'monthasckind' );
		$this->assertCount( 6, $p );

		$this->assertSame(
			[ [ 'h2:August 2026', [ 'Aug 3', 'Aug 22 a', 'Aug 22 b' ] ] ],
			$this->sections( $this->serve( 'monthasckind', $this->loop( self::LINE, [ 'linesPerPage' => 3 ], false ), 1 ) )
		);
		$this->assertSame(
			[
				[ 'h2:July 2026', [ 'Jul 1', 'Jul 24' ] ],
				[ 'h2:June 2026', [ 'Jun 6' ] ],
			],
			$this->sections( $this->serve( 'monthasckind', $this->loop( self::LINE, [ 'linesPerPage' => 3 ], false ), 2 ) ),
			'Page 2 holds July 1, July 24 and June 6.'
		);

		$this->assertSame(
			[ [ 'h2:August 2026', [ 'Aug 3', 'Aug 22 a', 'Aug 22 b' ] ] ],
			$this->sections( $this->editor_render( self::LINE, $p['aug03'], [ 'linesPerPage' => 3 ], [ 'pkiw_kind' => 'monthasckind' ] ) ),
			'The editor opens August at its oldest post and lists the days oldest first.'
		);
		$this->assertSame(
			'<div class="pkiw-group-placeholder" hidden></div>',
			$this->editor_render( self::LINE, $p['aug22a'], [ 'linesPerPage' => 3 ], [ 'pkiw_kind' => 'monthasckind' ] )
		);
		$this->assertSame(
			[ [ 'h2:July 2026', [ 'Jul 1', 'Jul 24' ] ] ],
			$this->sections( $this->editor_render( self::LINE, $p['jul01'], [ 'linesPerPage' => 3 ], [ 'pkiw_kind' => 'monthasckind' ] ) )
		);
	}

	// Section body.

	public function test_a_section_body_gets_the_posts_in_query_order_and_replaces_the_list(): void {
		$m = $this->months();

		$html = $this->serve( 'monthkind', $this->loop( self::BODY, [], false ) );

		$this->assertSame(
			[
				[ '2026-09', [ $m['september'] ] ],
				[ '2026-08', [ $m['late'], $m['early'] ] ],
				[ '2026-07', [ $m['july'] ] ],
			],
			array_map( static fn( array $body ): array => [ $body['group'], $body['ids'] ], $this->bodies )
		);
		$this->assertStringContainsString( '<div class="body" data-count="2">', $html );
		$this->assertStringNotContainsString( 'pkiw-group__items', $html, 'The default list is replaced.' );
		$this->assertMatchesRegularExpression( '/<section class="pkiw-group" data-pkiw-sections="3"><h2 class="pkiw-group__heading">September 2026<\/h2><div class="body"/', $html );

		foreach ( $this->bodies[1]['items'] as $post_id => $item ) {
			$this->assertStringStartsWith( '<li class="', $item );
			$this->assertStringContainsString( 'wp-block-post', $item, "Item {$post_id} is the engine's own post-template item." );
			$this->assertStringContainsString( 'post-' . $post_id, $item );
		}
		$this->assertSame( 2, $this->bodies[0]['settings']['level'] );
	}

	public function test_a_section_body_gets_the_posts_in_query_order_in_a_block_renderer_request(): void {
		$m = $this->months();

		$html = $this->editor_render( self::BODY, $m['late'], [], [ 'pkiw_kind' => 'monthkind' ] );

		$this->assertSame( [ [ '2026-08', [ $m['late'], $m['early'] ] ] ], array_map( static fn( array $body ): array => [ $body['group'], $body['ids'] ], $this->bodies ) );
		$this->assertStringContainsString( '<div class="body" data-count="2">', $html );
		$this->assertStringNotContainsString( 'pkiw-group__items', $html );
		foreach ( $this->bodies[0]['items'] as $item ) {
			$this->assertStringStartsWith( '<li>', $item, 'In the editor the engine wraps each item in a plain li.' );
		}
	}

	public function test_an_entry_without_a_section_body_prints_the_default_list(): void {
		$this->months();

		$html = $this->serve( 'monthkind', $this->loop( self::MARKER ) );

		$this->assertStringContainsString( '<ul class="pkiw-group__items">', $html );
		$this->assertSame( [], $this->bodies );
	}

	public function test_a_section_body_must_be_callable(): void {
		$this->expectException( InvalidArgumentException::class );
		Grouped_Archive::register_entry_block( 'pkiw-test/bad-body', [ 'section_body' => 'no_such_function_pkiw' ] );
	}

	public function test_the_weeks_setting_is_the_entrys_weeks_per_page_clamped_to_twelve(): void {
		$this->months();

		$weeks = function ( array $attrs ): int {
			$this->bodies = [];
			$this->serve( 'monthkind', $this->loop( self::BODY, $attrs, false ) );

			return $this->bodies[0]['settings']['weeks'];
		};

		$this->assertSame( 0, $weeks( [] ), 'Absent is 0.' );
		$this->assertSame( 3, $weeks( [ 'weeksPerPage' => 3 ] ) );
		$this->assertSame( 12, $weeks( [ 'weeksPerPage' => 99 ] ) );
		$this->assertSame( 0, $weeks( [ 'weeksPerPage' => -2 ] ) );
	}

	// Week previews.

	/**
	 * Five weeks, Monday start: two posts in week 1, one in 2, two in 3, one each in 4 and 5.
	 *
	 * @return array<string, int>
	 */
	private function five_weeks(): array {
		update_option( 'start_of_week', 1 );

		return [
			'w1a' => $this->make( 'weekkind', 'W1 Wed', '2026-09-23 10:00:00' ),
			'w1b' => $this->make( 'weekkind', 'W1 Mon', '2026-09-21 10:00:00' ),
			'w2'  => $this->make( 'weekkind', 'W2', '2026-09-15 10:00:00' ),
			'w3a' => $this->make( 'weekkind', 'W3 Fri', '2026-09-11 10:00:00' ),
			'w3b' => $this->make( 'weekkind', 'W3 Tue', '2026-09-08 10:00:00' ),
			'w4'  => $this->make( 'weekkind', 'W4', '2026-09-02 10:00:00' ),
			'w5'  => $this->make( 'weekkind', 'W5', '2026-08-26 10:00:00' ),
		];
	}

	public function test_the_editor_previews_whole_weeks_by_weeks_per_page(): void {
		$p        = $this->five_weeks();
		$captured = [];
		$this->on_item = static function () use ( &$captured ): string {
			$page = Grouped_Archive::current_page_ids();
			if ( $page !== end( $captured ) ) {
				$captured[] = $page;
			}
			return '';
		};
		$params = [ 'pkiw_kind' => 'weekkind' ];
		$attrs  = [ 'weeksPerPage' => 2 ];

		$first = $this->editor_render( self::WEEKS, $p['w1a'], $attrs, $params );
		$this->assertSame( [ [ 'h2:September 21–27, 2026', [ 'W1 Wed', 'W1 Mon' ] ] ], $this->sections( $first ) );
		$this->assertStringContainsString( 'data-pkiw-sections="2"', $first, 'Page 1 holds two weeks.' );
		$this->assertSame( '<div class="pkiw-group-placeholder" hidden></div>', $this->editor_render( self::WEEKS, $p['w1b'], $attrs, $params ) );
		$this->assertSame( [ [ 'h2:September 14–20, 2026', [ 'W2' ] ] ], $this->sections( $this->editor_render( self::WEEKS, $p['w2'], $attrs, $params ) ) );

		$hidden = '<div class="pkiw-group-placeholder" hidden></div>';
		foreach ( [ 'w3a', 'w3b', 'w4', 'w5' ] as $later ) {
			$this->assertSame( $hidden, $this->editor_render( self::WEEKS, $p[ $later ], $attrs, $params ), "{$later} sits on front-end page 2 or 3, so the editor leaves it hidden." );
		}

		$pages = [];
		foreach ( $captured as $page ) {
			if ( ! in_array( $page, $pages, true ) ) {
				$pages[] = $page;
			}
		}
		$this->assertSame( [ [ $p['w1a'], $p['w1b'], $p['w2'] ] ], $pages, 'Only the newest page, whole weeks, ever reaches an item.' );
	}

	public function test_the_editor_shows_only_the_newest_weeks_when_light_weeks_load_more_posts(): void {
		update_option( 'start_of_week', 1 );
		$ids = [];
		foreach ( [ '2026-09-23', '2026-09-22', '2026-09-15', '2026-09-08', '2026-09-01', '2026-08-26', '2026-08-18', '2026-08-11' ] as $i => $day ) {
			$ids[] = $this->make( 'weekkind', "Run {$i}", "{$day} 10:00:00" );
		}
		$ids    = array_reverse( $ids );
		$params = [ 'pkiw_kind' => 'weekkind' ];
		$attrs  = [ 'weeksPerPage' => 2 ];

		$visible = [];
		foreach ( array_reverse( $ids ) as $id ) {
			$html = $this->editor_render( self::WEEKS, $id, $attrs, $params );
			foreach ( $this->sections( $html ) as $section ) {
				$visible[] = $section[0];
			}
		}

		$this->assertSame(
			[ 'h2:September 21–27, 2026', 'h2:September 14–20, 2026' ],
			$visible,
			'Eight posts over six weeks, all loaded: the editor shows the two weeks of front-end page 1 and no more.'
		);
	}

	public function test_the_main_query_plan_carries_the_entrys_weeks_per_page(): void {
		$plan = static fn( array $attrs ) => Grouped_Archive::main_query_plan( new WP_Query(), 'weekkind', '<!-- wp:query {"queryId":7,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:' . self::WEEKS . ' ' . wp_json_encode( (object) $attrs ) . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->' );

		$this->assertSame( 3, $plan( [ 'weeksPerPage' => 3 ] )['weeks'] );
		$this->assertSame( 12, $plan( [ 'weeksPerPage' => 99 ] )['weeks'], 'Clamped to 12 like the section setting.' );
		$this->assertSame( 0, $plan( [] )['weeks'], 'Absent is 0.' );
		$this->assertSame( 2, $plan( [ 'weeksPerPage' => 2, 'linesPerPage' => 5 ] )['weeks'] );
	}

	public function test_weeks_per_page_beats_lines_per_page_in_the_editor_and_lines_still_count_without_it(): void {
		$p      = $this->five_weeks();
		$params = [ 'pkiw_kind' => 'weekkind' ];

		$both = $this->editor_render( self::WEEKS, $p['w1a'], [ 'weeksPerPage' => 2, 'linesPerPage' => 1 ], $params );
		$this->assertSame( [ [ 'h2:September 21–27, 2026', [ 'W1 Wed', 'W1 Mon' ] ] ], $this->sections( $both ), 'One line per page would cut week 1; whole weeks win.' );
		$this->assertStringContainsString( 'data-pkiw-sections="2"', $both );

		$lines = $this->editor_render( self::WEEKS, $p['w1a'], [ 'linesPerPage' => 2 ], $params );
		$this->assertStringContainsString( 'data-pkiw-sections="1"', $lines, 'With no weeksPerPage the page is two posts: one week.' );
	}

	// Registry wiring.

	public function test_the_shared_week_source_is_mapped_to_exercise_and_note(): void {
		$week = Grouped_Archive::source( 'post_week' );

		$this->assertInstanceOf( Date_Source::class, $week );
		$this->assertSame( 'week', $week->unit() );
		$this->assertSame( 'desc', $week->within() );
		$this->assertContains( 'exercise', Grouped_Archive::grouped_kinds() );
		$this->assertContains( 'note', Grouped_Archive::grouped_kinds() );

		update_option( 'start_of_week', 0 );
		$id = $this->make( 'exercise', 'Run', '2026-08-26 07:00:00' );
		$this->assertSame( '2026-08-23', Grouped_Archive::group_of_post( 'exercise', $id )->key() );
		$note = $this->make( 'note', 'A note', '2026-08-26 07:00:00' );
		$this->assertSame( '2026-08-23', Grouped_Archive::group_of_post( 'note', $note )->key() );
	}
}
