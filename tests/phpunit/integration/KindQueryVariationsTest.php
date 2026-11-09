<?php
/**
 * Kind archive Query Loop variations.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Query_Variations;
use PKIW\Grouped_Archive;
use PKIW\Presentation_Archive;
use PKIW\Taxonomy;
use PKIW\Travel_Map;

/** @group integration */
final class KindQueryVariationsTest extends WP_UnitTestCase {

	/** @var array<string, string> */
	private const TEMPLATES = [
		'pkiw-kind-quote'         => 'taxonomy-kind-quote',
		'pkiw-kind-issue'         => 'taxonomy-kind-issue',
		'pkiw-kind-comics-drawn' => 'taxonomy-kind-comics-drawn',
		'pkiw-kind-rsvp'          => 'taxonomy-kind-rsvp',
		'pkiw-kind-presentation'  => 'taxonomy-kind-presentation',
		'pkiw-kind-trip'          => 'taxonomy-kind-trip',
		'pkiw-kind-itinerary'     => 'taxonomy-kind-itinerary',
	];

	/** @var array<string, array{title:string,icon:string}> */
	private const LABELS = [
		'pkiw-kind-quote'         => [ 'title' => 'Quote commonplace book', 'icon' => 'format-quote' ],
		'pkiw-kind-issue'         => [ 'title' => 'Issue sections', 'icon' => 'list-view' ],
		'pkiw-kind-comics-drawn' => [ 'title' => 'Drawn comic series', 'icon' => 'art' ],
		'pkiw-kind-rsvp'          => [ 'title' => 'RSVP list', 'icon' => 'calendar-alt' ],
		'pkiw-kind-presentation'  => [ 'title' => 'Speaking badges', 'icon' => 'megaphone' ],
		'pkiw-kind-trip'          => [ 'title' => 'Trip map', 'icon' => 'location-alt' ],
		'pkiw-kind-itinerary'     => [ 'title' => 'Itinerary map', 'icon' => 'location-alt' ],
	];

	/** @var array<string, array{kind:string,marker:string}> */
	private const RENDER_CASES = [
		'pkiw-kind-quote'         => [ 'kind' => 'quote', 'marker' => 'pkiw-quote-entry' ],
		'pkiw-kind-issue'         => [ 'kind' => 'issue', 'marker' => 'pk-card' ],
		'pkiw-kind-comics-drawn' => [ 'kind' => 'comics-drawn', 'marker' => 'wp-block-post-title' ],
		'pkiw-kind-rsvp'          => [ 'kind' => 'rsvp', 'marker' => 'pkiw-rsvp-entry' ],
		'pkiw-kind-presentation'  => [ 'kind' => 'presentation', 'marker' => 'pkiw-presentation-badge' ],
		'pkiw-kind-trip'          => [ 'kind' => 'trip', 'marker' => 'pkiw-trip-entry' ],
		'pkiw-kind-itinerary'     => [ 'kind' => 'itinerary', 'marker' => 'pkiw-itinerary-entry' ],
	];

	/** @var array<string, string> */
	private const NO_RESULTS = [
		'pkiw-kind-quote'         => 'Nothing here yet.',
		'pkiw-kind-issue'         => 'No issues yet.',
		'pkiw-kind-comics-drawn' => 'No drawn comics yet.',
		'pkiw-kind-rsvp'          => 'Nothing here yet.',
		'pkiw-kind-presentation'  => 'Nothing here yet.',
		'pkiw-kind-trip'          => 'No trips yet.',
		'pkiw-kind-itinerary'     => 'No itineraries yet.',
	];

	private bool $restore_presentation_entry = false;
	private bool $restore_travel_map = false;

	public function tear_down(): void {
		if ( $this->restore_presentation_entry ) {
			Presentation_Archive::register_entry_block();
		}
		if ( $this->restore_travel_map ) {
			Travel_Map::register_block();
		}
		wp_reset_postdata();
		parent::tear_down();
	}

	/** @return array<int, array<string, mixed>> */
	private function variations( array $existing = [] ): array {
		$query = WP_Block_Type_Registry::get_instance()->get_registered( 'core/query' );
		$this->assertInstanceOf( WP_Block_Type::class, $query );

		return Kind_Query_Variations::add( $existing, $query );
	}

	/** @return array<string, array<string, mixed>> */
	private function variations_by_name(): array {
		return $this->index_variations( $this->variations() );
	}

	/**
	 * @param array<int, array<string, mixed>> $variations Variations.
	 * @return array<string, array<string, mixed>>
	 */
	private function index_variations( array $variations ): array {
		$indexed = [];
		foreach ( $variations as $variation ) {
			if ( isset( $variation['name'] ) && is_string( $variation['name'] ) ) {
				$indexed[ $variation['name'] ] = $variation;
			}
		}

		return $indexed;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	private function find_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( $name === ( $block['blockName'] ?? null ) ) {
				return $block;
			}
			$found = $this->find_block( (array) ( $block['innerBlocks'] ?? [] ), $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Compare parsed template blocks directly with variation tuples.
	 *
	 * @param array<int, array<string, mixed>> $blocks    Parsed template blocks.
	 * @param array<int, array<mixed>>         $tuples    Variation tuples.
	 * @param string                           $namespace Variation namespace.
	 * @param string                           $parent    Parent block name.
	 */
	private function assert_template_blocks_match( array $blocks, array $tuples, string $namespace, string $parent = '' ): void {
		$blocks = array_values( array_filter( $blocks, static fn( array $block ): bool => is_string( $block['blockName'] ?? null ) ) );
		$this->assertCount( count( $blocks ), $tuples, $namespace . ':' . $parent );

		foreach ( $blocks as $index => $block ) {
			$name       = (string) $block['blockName'];
			$attributes = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$this->assertSame( $name, $tuples[ $index ][0] ?? null, $namespace . ':' . $name );

			if ( 'pkiw-kind-quote' === $namespace && 'post-kinds-indieweb/quote-entry' === $name ) {
				$raw_classes = array_values( array_filter( explode( ' ', (string) ( $attributes['className'] ?? '' ) ) ) );
				$this->assertContains( 'is-style-cr-commonplace', $raw_classes, 'The raw quote template is the positive control.' );
				$other_classes = array_values( array_diff( $raw_classes, [ 'is-style-cr-commonplace' ] ) );
				if ( [] === $other_classes ) {
					unset( $attributes['className'] );
				} else {
					$attributes['className'] = implode( ' ', $other_classes );
				}
				$variation_classes = array_values( array_filter( explode( ' ', (string) ( $tuples[ $index ][1]['className'] ?? '' ) ) ) );
				$this->assertNotContains( 'is-style-cr-commonplace', $variation_classes );
				$this->assertSame( $other_classes, $variation_classes, 'Every non-theme quote class is retained.' );
			}

			if ( 'core/query-no-results' === $parent && 'core/paragraph' === $name ) {
				$attributes['content'] = trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
			}

			$this->assertSame( $attributes, $tuples[ $index ][1] ?? null, $namespace . ':' . $name );
			$this->assert_template_blocks_match( (array) ( $block['innerBlocks'] ?? [] ), (array) ( $tuples[ $index ][2] ?? [] ), $namespace, $name );
		}
	}

	/**
	 * @param array<int, array<mixed>> $blocks Variation tuples.
	 * @return array<mixed>|null
	 */
	private function find_variation_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( $name === ( $block[0] ?? null ) ) {
				return $block;
			}
			$found = $this->find_variation_block( (array) ( $block[2] ?? [] ), $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/** Render the same inherited Query from a rewound main query. */
	private function render_query( array $query ): string {
		global $wp_query;
		Grouped_Archive::forget_heading_ids();
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->rewind_posts();
		}
		wp_reset_postdata();

		return do_blocks( serialize_block( $query ) );
	}

	public function test_seven_variations_have_the_permanent_names_and_query_contract(): void {
		$variations = $this->variations_by_name();

		$this->assertSame( array_keys( self::TEMPLATES ), array_values( array_intersect( array_keys( $variations ), array_keys( self::TEMPLATES ) ) ) );
		foreach ( array_keys( self::TEMPLATES ) as $namespace ) {
			$variation = $variations[ $namespace ];
			$this->assertSame( self::LABELS[ $namespace ]['title'], $variation['title'], $namespace );
			$this->assertSame( self::LABELS[ $namespace ]['icon'], $variation['icon'], $namespace );
			$this->assertSame( [ 'namespace' ], $variation['isActive'], $namespace );
			$this->assertSame( [ 'inserter', 'block' ], $variation['scope'], $namespace );
			$this->assertSame( $namespace, $variation['attributes']['namespace'], $namespace );
			$this->assertSame( 'pkiw-kind-archive', $variation['attributes']['className'], $namespace );
			$this->assertTrue( $variation['attributes']['query']['inherit'], $namespace );
		}
	}

	public function test_variation_filter_is_wired_after_init(): void {
		$query = WP_Block_Type_Registry::get_instance()->get_registered( 'core/query' );
		$this->assertInstanceOf( WP_Block_Type::class, $query );
		$this->assertSame( 10, has_filter( 'get_block_type_variations', [ Kind_Query_Variations::class, 'add' ] ) );

		$names = wp_list_pluck( apply_filters( 'get_block_type_variations', [], $query ), 'name' );
		foreach ( array_keys( self::TEMPLATES ) as $namespace ) {
			$this->assertContains( $namespace, $names );
		}
	}

	public function test_each_variation_uses_its_templates_query_children(): void {
		$variations = $this->variations_by_name();

		foreach ( self::TEMPLATES as $namespace => $slug ) {
			$content = file_get_contents( PKIW_PATH . 'templates/' . $slug . '.html' );
			$this->assertIsString( $content, $slug );
			$query = $this->find_block( parse_blocks( $content ), 'core/query' );
			$this->assertNotNull( $query, $slug );
			$this->assert_template_blocks_match( (array) $query['innerBlocks'], $variations[ $namespace ]['innerBlocks'], $namespace );
		}
	}

	public function test_no_results_paragraphs_keep_the_template_text_as_content(): void {
		$variations = $this->variations_by_name();
		foreach ( self::NO_RESULTS as $namespace => $text ) {
			$no_results = $this->find_variation_block( $variations[ $namespace ]['innerBlocks'], 'core/query-no-results' );
			$this->assertNotNull( $no_results, $namespace );
			$paragraph = $this->find_variation_block( (array) $no_results[2], 'core/paragraph' );
			$this->assertNotNull( $paragraph, $namespace );
			$this->assertSame( $text, $paragraph[1]['content'] ?? null, $namespace );
		}
	}

	public function test_quote_style_removal_keeps_other_class_tokens(): void {
		$method = new ReflectionMethod( Kind_Query_Variations::class, 'variation_blocks' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$blocks = [
			[
				'blockName'  => 'post-kinds-indieweb/quote-entry',
				'attrs'      => [ 'className' => 'quote-local is-style-cr-commonplace quote-extra' ],
				'innerBlocks' => [],
			],
		];

		$converted = $method->invoke( null, $blocks, true );
		$this->assertSame( 'quote-local quote-extra', $converted[0][1]['className'] ?? null );
	}

	public function test_a_missing_entry_block_omits_its_variation_and_keeps_the_other_six(): void {
		$before = $this->variations_by_name();
		$this->assertArrayHasKey( 'pkiw-kind-presentation', $before );
		$this->assertInstanceOf( WP_Block_Type::class, unregister_block_type( Presentation_Archive::ENTRY_BLOCK ) );
		$this->restore_presentation_entry = true;

		$after = $this->variations_by_name();
		$this->assertArrayNotHasKey( 'pkiw-kind-presentation', $after );
		foreach ( array_diff( array_keys( self::TEMPLATES ), [ 'pkiw-kind-presentation' ] ) as $namespace ) {
			$this->assertArrayHasKey( $namespace, $after );
		}
	}

	public function test_travel_variations_require_the_travel_map_block(): void {
		$before = $this->variations_by_name();
		$this->assertArrayHasKey( 'pkiw-kind-trip', $before );
		$this->assertArrayHasKey( 'pkiw-kind-itinerary', $before );
		$this->assertInstanceOf( WP_Block_Type::class, unregister_block_type( Travel_Map::BLOCK ) );
		$this->restore_travel_map = true;

		$after = $this->variations_by_name();
		$this->assertArrayNotHasKey( 'pkiw-kind-trip', $after );
		$this->assertArrayNotHasKey( 'pkiw-kind-itinerary', $after );
		foreach ( array_diff( array_keys( self::TEMPLATES ), [ 'pkiw-kind-trip', 'pkiw-kind-itinerary' ] ) as $namespace ) {
			$this->assertArrayHasKey( $namespace, $after );
		}
	}

	public function test_existing_registry_variations_are_unchanged_and_only_seven_are_added(): void {
		$query = WP_Block_Type_Registry::get_instance()->get_registered( 'core/query' );
		$this->assertInstanceOf( WP_Block_Type::class, $query );
		$callback = [ Kind_Query_Variations::class, 'add' ];
		$this->assertTrue( remove_filter( 'get_block_type_variations', $callback, 10 ) );
		try {
			$before = $query->get_variations();
		} finally {
			add_filter( 'get_block_type_variations', $callback, 10, 2 );
		}
		$after        = $query->get_variations();
		$before_names = $this->index_variations( $before );
		$after_names  = $this->index_variations( $after );

		foreach ( [ 'pkiw-kind-shelf', 'pkiw-kind-menu', 'pkiw-kind-lineup' ] as $name ) {
			$this->assertArrayHasKey( $name, $before_names );
			$this->assertSame( [ 'namespace' ], $before_names[ $name ]['isActive'], $name );
			$this->assertSame( $before_names[ $name ]['attributes'], $after_names[ $name ]['attributes'], $name );
			$this->assertSame( $before_names[ $name ], $after_names[ $name ], $name );
		}

		$new_names = array_values( array_diff( array_keys( $after_names ), array_keys( $before_names ) ) );
		$this->assertSame( array_keys( self::TEMPLATES ), $new_names );
		$this->assertCount( count( $before ) + 7, $after );
	}

	public function test_parsed_templates_are_cached_by_slug(): void {
		$cache = new ReflectionProperty( Kind_Query_Variations::class, 'template_cache' );
		if ( PHP_VERSION_ID < 80100 ) {
			$cache->setAccessible( true );
		}
		$original = $cache->getValue();
		$cache->setValue( null, [] );
		try {
			$query = WP_Block_Type_Registry::get_instance()->get_registered( 'core/query' );
			$this->assertInstanceOf( WP_Block_Type::class, $query );
			$query->get_variations();
			$first = $cache->getValue();
			$this->assertSame( array_values( self::TEMPLATES ), array_keys( $first ) );
			$first['taxonomy-kind-quote'][0]['attrs']['data-pkiw-cache-probe'] = 'cached';
			$cache->setValue( null, $first );
			$second = $this->index_variations( $query->get_variations() );
			$this->assertSame( 'cached', $second['pkiw-kind-quote']['innerBlocks'][0][1]['data-pkiw-cache-probe'] ?? null );
		} finally {
			$cache->setValue( null, $original );
		}
	}

	public function test_non_query_block_variations_are_unchanged(): void {
		$existing = [ [ 'name' => 'paragraph-control' ] ];

		$this->assertSame( $existing, Kind_Query_Variations::add( $existing, new WP_Block_Type( 'core/paragraph' ) ) );
	}

	public function test_template_namespaces_do_not_change_query_rendering(): void {
		foreach ( self::RENDER_CASES as $namespace => $case ) {
			$post_id = self::factory()->post->create(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Variation render ' . $case['kind'],
					'post_content' => '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Variation quote text.</p></blockquote><!-- /wp:quote -->',
				]
			);
			$this->assertNotWPError( wp_set_object_terms( $post_id, $case['kind'], Taxonomy::TAXONOMY ) );
			$url = get_term_link( $case['kind'], Taxonomy::TAXONOMY );
			$this->assertIsString( $url, $case['kind'] );
			$this->go_to( $url );
			$this->assertContains( $post_id, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), $case['kind'] );

			$slug    = self::TEMPLATES[ $namespace ];
			$content = file_get_contents( PKIW_PATH . 'templates/' . $slug . '.html' );
			$this->assertIsString( $content, $slug );
			$query = $this->find_block( parse_blocks( $content ), 'core/query' );
			$this->assertNotNull( $query, $slug );
			$this->assertSame( $namespace, $query['attrs']['namespace'] ?? null, $slug );
			$this->assertSame( 'pkiw-kind-archive', $query['attrs']['className'] ?? null, $slug );
			$this->assertTrue( $query['attrs']['query']['inherit'] ?? false, $slug );

			$without_namespace = $query;
			unset( $without_namespace['attrs']['namespace'] );
			$without = $this->render_query( $without_namespace );
			$with    = $this->render_query( $query );
			$this->assertNotSame( '', $with, $slug );
			$this->assertStringContainsString( 'post-' . $post_id, $with, $slug );
			$this->assertStringContainsString( $case['marker'], $with, $slug );
			$this->assertSame( $without, $with, $slug );
		}
	}
}
