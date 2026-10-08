<?php
/**
 * The archive-sections marker block (W1, #232 #237 #234).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Cases_Source;
use PKIW\Grouping\Label_Mode;

/**
 * The marker sits in an archive's Post Template beside core blocks or the
 * stream card. It prints nothing on the front end; its attributes size the
 * page and set the section headings, and the engine prints the sections.
 * A fixture play source stands in for the kind lanes' sources: the
 * `pkiw_archive_group_source` filter points the play kind at it, so a real
 * play source registered later doesn't change these tests. Titles and IDs
 * are invented.
 *
 * @group integration
 */
final class ArchiveSectionsBlockTest extends WP_UnitTestCase {

	private const BLOCK  = 'post-kinds-indieweb/archive-sections';
	private const SOURCE = 'w1test_play';

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Points the play kind at the fixture source.
	 *
	 * @var callable
	 */
	private $pick_source;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 20 );

		Grouped_Archive::register_source(
			new Cases_Source(
				self::SOURCE,
				[
					'video' => [ '_pkiw_w1test_rawg' ],
					'board' => [ '_pkiw_w1test_bgg' ],
				],
				[ 'video', 'board' ],
				Label_Mode::map(
					[
						'video' => 'Video games',
						'board' => 'Board games',
					]
				)
			)
		);
		$this->pick_source = static fn( $id, string $kind ) => 'play' === $kind ? self::SOURCE : $id;
		add_filter( 'pkiw_archive_group_source', $this->pick_source, 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pkiw_archive_group_source', $this->pick_source, 10 );
		Grouped_Archive::unregister_source( self::SOURCE );
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A published play.
	 *
	 * @param string                $title Title.
	 * @param string                $date  Post date.
	 * @param array<string, string> $meta  Meta key => value.
	 */
	private function play( string $title, string $date, array $meta = [] ): int {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_date'   => $date,
			]
		);
		wp_set_object_terms( $id, 'play', 'kind' );
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Two videos, three boards, one with neither: video, board, then empty.
	 *
	 * @return array<string, int>
	 */
	private function plays(): array {
		return [
			'courier' => $this->play( 'Starbound Courier', '2026-08-20 10:00:00', [ '_pkiw_w1test_rawg' => '900001' ] ),
			'forest'  => $this->play( 'Forest Paths', '2026-08-19 10:00:00', [ '_pkiw_w1test_bgg' => '9990001' ] ),
			'garden'  => $this->play( 'Garden Circuit', '2026-08-18 10:00:00', [ '_pkiw_w1test_rawg' => '900002' ] ),
			'orbit'   => $this->play( 'Orbit Table', '2026-08-17 10:00:00', [ '_pkiw_w1test_bgg' => '9990002' ] ),
			'tag'     => $this->play( 'Backyard Tag', '2026-08-16 10:00:00' ),
			'meadow'  => $this->play( 'Meadow Songs', '2026-08-15 10:00:00', [ '_pkiw_w1test_bgg' => '9990004' ] ),
		];
	}

	/**
	 * An archive template: an inheriting Query Loop whose Post Template holds
	 * a title and the marker.
	 *
	 * @param array<string, mixed> $attrs Marker attributes.
	 */
	private function template( array $attrs = [] ): string {
		$marker = '<!-- wp:' . self::BLOCK . ( $attrs ? ' ' . wp_json_encode( $attrs ) : '' ) . ' /-->';

		return '<!-- wp:query {"queryId":12,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template {"className":"is-style-pkiw-shelf"} --><!-- wp:post-title {"level":3,"isLink":true} /-->' . $marker . '<!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * Serve /kind/play/ from a template and render it.
	 *
	 * @param string $template Template content.
	 * @param int    $page     Page number.
	 */
	private function serve( string $template, int $page = 1 ): string {
		$swap = static function ( $templates ) use ( $template ) {
			foreach ( $templates as $found ) {
				if ( in_array( $found->slug, [ 'taxonomy-kind', 'taxonomy-kind-play' ], true ) ) {
					$found->content = $template;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$url = get_term_link( 'play', 'kind' );
		$this->assertIsString( $url );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );
		$html = do_blocks( $template );
		remove_filter( 'get_block_templates', $swap );

		return $html;
	}

	/**
	 * Each section: its group key, heading tag, heading text and heading id.
	 *
	 * @return array<int, array{group:string, heading:string, id:string, titles:string[]}>
	 */
	private function sections( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $section->firstChild;
			$titles  = [];
			foreach ( $xpath->query( './ul/li//*[contains(@class, "wp-block-post-title")]', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$out[] = [
				'group'   => $section->hasAttribute( 'data-pkiw-group' ) ? $section->getAttribute( 'data-pkiw-group' ) : '(none)',
				'heading' => $heading instanceof DOMElement ? $heading->nodeName . ':' . trim( $heading->textContent ) : '',
				'id'      => $heading instanceof DOMElement ? $heading->getAttribute( 'id' ) : '',
				'titles'  => $titles,
			];
		}

		return $out;
	}

	/**
	 * The editor's render of the marker for one post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $attrs   Attributes.
	 */
	private function editor_render( int $post_id, array $attrs = [] ): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . self::BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'pkiw_kind', 'play' );
		if ( $attrs ) {
			$request->set_param( 'attributes', $attrs );
		}
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return (string) $response->get_data()['rendered'];
	}

	// Registration.

	public function test_the_marker_registers_as_an_entry_block_inside_post_templates(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );

		$this->assertNotNull( $type, 'The archive-sections block is registered.' );
		$this->assertTrue( Grouped_Archive::is_entry_block( self::BLOCK ), 'The engine knows it as an entry block.' );
		$this->assertSame( 3, $type->api_version );
		$this->assertSame( [ 'core/post-template' ], $type->ancestor );
		$this->assertTrue( $type->is_dynamic() );
		foreach ( [ 'postId', 'postType', 'queryId', 'templateSlug' ] as $context ) {
			$this->assertContains( $context, $type->uses_context );
		}
		$this->assertSame( [ 'fixed' => false ], Grouped_Archive::editor_entries()[ self::BLOCK ] ?? null, 'The Site Editor preview finds it through pkiwGroupedEntries and groups by the kind source.' );
	}

	public function test_the_attributes_are_the_shared_entry_schema_plus_group_labels(): void {
		$attributes = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK )->attributes;

		foreach ( Grouped_Archive::entry_attributes() as $name => $schema ) {
			$this->assertArrayHasKey( $name, $attributes );
			$this->assertSame( $schema['type'], $attributes[ $name ]['type'], $name );
			$this->assertSame( $schema['default'], $attributes[ $name ]['default'], $name );
		}
		$this->assertSame( 'object', $attributes['groupLabels']['type'] );
		$this->assertSame( [], $attributes['groupLabels']['default'] );
		$this->assertSame( '', $attributes['emptyLabel']['default'], 'An empty label falls back to the source, then "Other" (X2).' );
	}

	public function test_the_label_attributes_carry_the_content_role(): void {
		$server = get_block_editor_server_block_settings()[ self::BLOCK ]['attributes'] ?? [];

		$this->assertSame( 'content', $server['groupLabels']['role'] ?? null, 'A contentOnly pattern can edit the group labels.' );
		$this->assertSame( 'content', $server['emptyLabel']['role'] ?? null, 'A contentOnly pattern can edit the empty-group label.' );
		$this->assertArrayNotHasKey( 'role', $server['linesPerPage'], 'Settings stay out of contentOnly editing.' );
	}

	public function test_the_marker_prints_nothing_on_the_front_end(): void {
		$id = $this->play( 'Forest Paths', '2026-08-19 10:00:00', [ '_pkiw_w1test_bgg' => '9990001' ] );
		$block = new WP_Block(
			[
				'blockName' => self::BLOCK,
				'attrs'     => [ 'groupLabels' => [ 'board' => 'Game Night' ] ],
			],
			[ 'postId' => $id ]
		);

		$this->assertSame( '', $block->render() );
	}

	public function test_the_editor_gets_each_kind_s_group_keys_from_the_filter(): void {
		$groups = static fn(): array => [
			'play'      => [
				'Video' => 'Video games',
				' '     => 'Blank key',
				'board' => '<b>Board games</b>',
			],
			'Bad Kind!' => 'not a list',
		];
		add_filter( 'pkiw_archive_sections_groups', $groups );
		$out = \PKIW\Kind_Archive_Layouts::archive_sections_groups();
		\PKIW\Kind_Archive_Layouts::add_archive_sections_groups();
		remove_filter( 'pkiw_archive_sections_groups', $groups );

		$this->assertSame(
			[
				'play' => [
					'video' => 'Video games',
					'board' => 'Board games',
				],
			],
			$out,
			'Keys are lowercase group keys, labels are plain text, and anything that isn\'t a kind\'s list is dropped.'
		);
		$inline = implode( "\n", (array) wp_scripts()->get_data( 'pkiw-archive-sections-editor', 'before' ) );
		$this->assertStringContainsString( 'window.pkiwArchiveSections = {"groups":{"play":{"video":"Video games","board":"Board games"}}};', $inline );

		// Without the test's filter, the kind lanes' own filters answer. Read
		// lists its four statuses (Read_Archive::sections_groups()).
		$default = \PKIW\Kind_Archive_Layouts::archive_sections_groups();
		$this->assertSame( \PKIW\read_status_labels(), $default['read'] ?? null, 'Read lists its statuses with the plugin labels, in shelf order.' );
		$this->assertSame( [], array_values( array_diff( array_keys( $default ), Grouped_Archive::grouped_kinds() ) ), 'No kind without a registered source lists groups.' );
	}

	// Page size.

	public function test_lines_per_page_sizes_the_inheriting_main_query(): void {
		$this->plays();
		$query = new WP_Query();
		$plan  = Grouped_Archive::main_query_plan( $query, 'play', $this->template( [ 'linesPerPage' => 12 ] ) );

		$this->assertNotNull( $plan );
		$this->assertSame( 12, $plan['per_page'] );
		$this->assertSame( self::SOURCE, $plan['vars']['pkiw_group_source'] ?? '' );
		$this->assertSame( 0, Grouped_Archive::main_query_plan( $query, 'play', $this->template() )['per_page'], 'Unset, the page size is left to the site.' );

		$this->serve( $this->template( [ 'linesPerPage' => 4 ] ) );
		global $wp_query;
		$this->assertSame( 4, (int) $wp_query->get( 'posts_per_page' ) );
		$this->assertSame( 4, $wp_query->post_count );
		$this->assertSame( 6, $wp_query->found_posts, 'Grouping leaves the count alone.' );
	}

	// Sections.

	public function test_sections_expose_their_group_and_take_the_labels_the_block_names(): void {
		$this->plays();
		$sections = $this->sections(
			$this->serve(
				$this->template(
					[
						'groupLabels' => [ 'board' => 'Game Night' ],
						'emptyLabel'  => 'Play',
					]
				)
			)
		);

		$this->assertSame( [ 'video', 'board', '' ], array_column( $sections, 'group' ) );
		$this->assertSame( [ 'h2:Video games', 'h2:Game Night', 'h2:Play' ], array_column( $sections, 'heading' ), 'groupLabels beats the source label; emptyLabel names the empty group.' );
		$this->assertSame( [ [ 'Starbound Courier', 'Garden Circuit' ], [ 'Forest Paths', 'Orbit Table', 'Meadow Songs' ], [ 'Backyard Tag' ] ], array_column( $sections, 'titles' ) );

		$plain = $this->sections( $this->serve( $this->template( [ 'headingLevel' => 3 ] ) ) );
		$this->assertSame( [ 'h3:Video games', 'h3:Board games', 'h3:Other' ], array_column( $plain, 'heading' ), 'With no labels set, the source labels and "Other".' );
	}

	public function test_a_group_absent_from_a_page_prints_no_heading_and_a_continued_group_repeats_its_label(): void {
		$this->plays();
		$attrs = [
			'linesPerPage' => 2,
			'groupLabels'  => [ 'board' => 'Game Night' ],
		];
		$pages = [];
		for ( $page = 1; $page <= 3; $page++ ) {
			$pages[ $page ] = array_column( $this->sections( $this->serve( $this->template( $attrs ), $page ) ), 'heading' );
		}

		$this->assertSame( [ 'h2:Video games' ], $pages[1] );
		$this->assertSame( [ 'h2:Game Night' ], $pages[2], 'Page 2 holds only boards: no video heading.' );
		$this->assertSame( [ 'h2:Game Night', 'h2:Other' ], $pages[3], 'The board group runs on, so page 3 repeats its label.' );
	}

	public function test_every_section_heading_has_an_id_unique_on_the_page(): void {
		$this->plays();
		$template = $this->template( [ 'emptyLabel' => 'Play' ] );
		$sections = $this->sections( $this->serve( $template ) );
		$ids      = array_column( $sections, 'id' );

		$this->assertCount( 3, $ids );
		$this->assertSame( [ 'pkiw-group-video', 'pkiw-group-board', 'pkiw-group-empty' ], $ids );

		// Two grouped loops on one page: the second loop's ids don't repeat the first's.
		$twice = $this->sections( $this->serve( $template . $template ) );
		$ids   = array_column( $twice, 'id' );
		$this->assertCount( 6, $ids );
		$this->assertSame( $ids, array_unique( $ids ), 'No id repeats on the page.' );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^pkiw-group-[a-z0-9-]+$/', $id );
		}

		$this->assertSame( [ 'pkiw-group-video', 'pkiw-group-board', 'pkiw-group-empty' ], array_column( $this->sections( $this->serve( $template ) ), 'id' ), 'A new request starts the ids over.' );
	}

	// Editor preview.

	public function test_in_a_block_renderer_request_only_the_group_opening_post_prints_the_heading(): void {
		$p     = $this->plays();
		$attrs = [
			'linesPerPage' => 4,
			'groupLabels'  => [ 'board' => 'Game Night' ],
		];

		$this->assertSame( '<h2 id="pkiw-group-video" class="pkiw-group__heading">Video games</h2>', $this->editor_render( $p['courier'], $attrs ) );
		$this->assertSame( '<div class="pkiw-group-placeholder" hidden></div>', $this->editor_render( $p['garden'], $attrs ) );
		$this->assertSame( '<h2 id="pkiw-group-board" class="pkiw-group__heading">Game Night</h2>', $this->editor_render( $p['forest'], $attrs ) );
		$this->assertSame( '<div class="pkiw-group-placeholder" hidden></div>', $this->editor_render( $p['orbit'], $attrs ) );
		$this->assertSame( '<h2 id="pkiw-group-board" class="pkiw-group__heading">Game Night</h2>', $this->editor_render( $p['meadow'], $attrs ), 'Meadow Songs opens page 2, so it heads the board group again.' );
		$this->assertSame( '<h2 id="pkiw-group-empty" class="pkiw-group__heading">Other</h2>', $this->editor_render( $p['tag'], $attrs ) );
	}
}
