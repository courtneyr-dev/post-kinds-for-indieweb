<?php
/**
 * Wish type, priority, grouping and Stream citation helpers.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Meta_Source;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Wish_Archive;

/**
 * Covers the closed wish type vocabulary and public helper contracts.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishTypeLabelsTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Register the real wish archive dependencies.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	/**
	 * Restore theme state.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * Stored wish types and their public labels.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function type_label_provider(): array {
		return [
			'item'       => [ 'item', 'Item' ],
			'experience' => [ 'experience', 'Experience' ],
			'book'       => [ 'book', 'Book' ],
			'game'       => [ 'game', 'Game' ],
			'movie'      => [ 'movie', 'Movie/Show' ],
			'media'      => [ 'media', 'Movie/Show' ],
			'music'      => [ 'music', 'Music' ],
			'product'    => [ 'product', 'Product' ],
			'travel'     => [ 'travel', 'Travel' ],
			'tech'       => [ 'tech', 'Tech' ],
			'food'       => [ 'food', 'Food' ],
			'other'      => [ 'other', 'Other' ],
			'empty'      => [ '', 'Other' ],
			'unknown'    => [ 'gizmo', 'Other' ],
			'uppercase'  => [ 'BOOK', 'Book' ],
			'spaces'     => [ ' Media ', 'Movie/Show' ],
			'label text' => [ 'Movie/Show', 'Other' ],
		];
	}

	/**
	 * Every accepted spelling maps to a fixed translated label.
	 *
	 * @since 1.9.0
	 *
	 * @dataProvider type_label_provider
	 *
	 * @param string $stored Stored type.
	 * @param string $label  Expected label.
	 * @return void
	 */
	public function test_type_label_maps_only_the_closed_vocabulary( string $stored, string $label ): void {
		$this->assertSame( $label, Wish_Archive::type_label( $stored ) );
	}

	/**
	 * Group keys normalize case and spaces but never expose unknown values.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_group_keys_map_media_to_movie_and_unknowns_to_empty(): void {
		$this->assertSame( 'book', Wish_Archive::group_key( ' BOOK ' ) );
		$this->assertSame( 'movie', Wish_Archive::group_key( 'media' ) );
		$this->assertSame( 'movie', Wish_Archive::group_key( ' Movie ' ) );
		$this->assertSame( '', Wish_Archive::group_key( 'other' ) );
		$this->assertSame( '', Wish_Archive::group_key( 'Movie/Show' ) );
		$this->assertSame( '', Wish_Archive::group_key( '' ) );
	}

	/**
	 * The display map has one movie label and leaves Other to the engine.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_type_labels_have_the_documented_order_and_no_other_key(): void {
		$labels = Wish_Archive::type_labels();

		$this->assertSame( [ 'item', 'experience', 'book', 'game', 'movie', 'music', 'product', 'travel', 'tech', 'food' ], array_keys( $labels ) );
		$this->assertSame( 1, count( array_keys( $labels, 'Movie/Show', true ) ) );
		$this->assertArrayNotHasKey( 'other', $labels );
		$this->assertNotContains( 'Other', $labels );
	}

	/**
	 * Priority normalization accepts the three public ranks only.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_priorities_normalize_and_fall_back_to_medium(): void {
		$this->assertSame( 'high', Wish_Archive::priority( 'high' ) );
		$this->assertSame( 'high', Wish_Archive::priority( ' HIGH ' ) );
		$this->assertSame( 'medium', Wish_Archive::priority( 'Medium' ) );
		$this->assertSame( 'low', Wish_Archive::priority( 'low' ) );
		$this->assertSame( 'medium', Wish_Archive::priority( 'urgent' ) );
		$this->assertSame( 'medium', Wish_Archive::priority( '' ) );
	}

	/**
	 * Priority labels and prose use the normalized rank.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_priority_labels_and_text_use_the_normalized_rank(): void {
		$this->assertSame( 'High', Wish_Archive::priority_label( ' HIGH ' ) );
		$this->assertSame( 'Medium', Wish_Archive::priority_label( 'urgent' ) );
		$this->assertSame( 'Low', Wish_Archive::priority_label( 'low' ) );
		$this->assertSame( 'High priority', Wish_Archive::priority_text( 'high' ) );
		$this->assertSame( 'Medium priority', Wish_Archive::priority_text( '' ) );
		$this->assertSame( 'Low priority', Wish_Archive::priority_text( 'LOW' ) );
	}

	/**
	 * Only HTTP URLs with a usable host reach public markup.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_link_url_rejects_non_web_and_escaped_host_values(): void {
		$this->assertSame( 'https://example.com/item', Wish_Archive::link_url( 'https://example.com/item' ) );
		$this->assertSame( 'http://example.com/item', Wish_Archive::link_url( 'http://example.com/item' ) );
		$this->assertSame( '', Wish_Archive::link_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', Wish_Archive::link_url( 'not a url' ) );
		$this->assertSame( '', Wish_Archive::link_url( '' ) );
		$this->assertSame( '', Wish_Archive::link_url( 'ftp://example.com/x' ) );
		$this->assertSame( '', Wish_Archive::link_url( 'https://bad%20host.example/x' ) );
	}

	/**
	 * Archive section labels extend the map without replacing other kinds.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_sections_groups_adds_wish_and_tolerates_non_arrays(): void {
		$groups = Wish_Archive::sections_groups( [ 'read' => [ 'read' => 'Read' ] ] );

		$this->assertSame( [ 'read' => 'Read' ], $groups['read'] );
		$this->assertSame( Wish_Archive::type_labels(), $groups['wish'] );
		$this->assertSame( [ 'wish' => Wish_Archive::type_labels() ], Wish_Archive::sections_groups( 'invalid' ) );
	}

	/**
	 * Plugin bootstrap registers the wish Meta_Source and kind mapping.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_plugin_bootstrap_registers_the_wish_source(): void {
		$this->assertInstanceOf( Meta_Source::class, Grouped_Archive::source( Wish_Archive::SOURCE ) );
		$this->assertContains( Wish_Archive::KIND, Grouped_Archive::grouped_kinds() );
	}

	/**
	 * The Stream citation map accepts malformed input and installs one callback.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_cite_map_adds_a_callable_without_losing_existing_entries(): void {
		$existing = static fn(): string => 'kept';
		$map      = Wish_Archive::cite_map( [ 'read' => $existing ] );

		$this->assertSame( $existing, $map['read'] );
		$this->assertIsCallable( $map['wish'] );
		$this->assertIsCallable( Wish_Archive::cite_map( null )['wish'] );
	}

	/**
	 * Generic Stream cards get a hidden wish citation only for a public URL.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_stream_cite_requires_a_url_and_omits_synthetic_names(): void {
		$post = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertSame( '', Wish_Archive::stream_cite( [], $post ) );
		$this->assertSame( '', Wish_Archive::stream_cite( [ 'url' => '', 'name' => 'Lamp', 'name_kind' => 'title' ], $post ) );

		$named = Wish_Archive::stream_cite(
			[
				'url'       => 'https://shop.example/lamp',
				'name'      => 'Desk lamp',
				'name_kind' => 'title',
			],
			$post
		);
		$this->assertStringContainsString( 'h-cite u-wish-of', $named );
		$this->assertStringContainsString( 'class="p-name" value="Desk lamp"', $named );
		$this->assertStringContainsString( 'class="u-url" value="https://shop.example/lamp"', $named );

		$synthetic = Wish_Archive::stream_cite(
			[
				'url'       => 'https://shop.example/unnamed',
				'name'      => 'Wish on October 18, 2026',
				'name_kind' => 'untitled',
			],
			$post
		);
		$this->assertStringContainsString( 'class="u-url"', $synthetic );
		$this->assertStringNotContainsString( 'class="p-name"', $synthetic );
	}
}
