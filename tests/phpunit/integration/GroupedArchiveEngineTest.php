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
	private const PAIRS  = 'pkiw-test/two-a-page';
	private const MONTHS = 'pkiw-test/month-marker';

	private const SOURCES = [ 'test_status', 'test_series', 'test_play', 'test_tags', 'test_cats', 'test_month', 'test_year', 'test_week' ];

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
	}

	public function tear_down(): void {
		foreach ( [ self::MARKER, self::LINE, self::PAIRS, self::MONTHS ] as $name ) {
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
}
