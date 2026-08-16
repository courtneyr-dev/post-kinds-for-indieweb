<?php
/**
 * Yoast SEO schema integration coverage.
 *
 * The full Yoast pipeline (graph generation, context, output) was
 * verified manually against Yoast SEO 28.2 in wp-env; these tests
 * cover the plugin's own halves at the PHP layer: representative-image
 * resolution (meta first, block-attr fallback, URL validation) and
 * graph wiring against a Yoast-28.2-shaped synthetic graph. Yoast is
 * intentionally absent here — the suite also proves the integration
 * is inert without it.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Yoast_SEO;

/**
 * @group integration
 */
final class YoastSeoIntegrationTest extends WP_UnitTestCase {

	private const COVER = 'https://coverartarchive.org/release/abc/front-500.jpg';

	/**
	 * Build a published post whose content is a listen card nested in
	 * the h-entry group — the exact shape the Micropub bridge writes.
	 */
	private function make_listen_post( array $attrs, array $post_args = [] ): int {
		$json = wp_json_encode( $attrs );
		return self::factory()->post->create(
			array_merge(
				[
					'post_content' => '<!-- wp:group {"className":"h-entry","layout":{"type":"constrained"}} --><div class="wp-block-group h-entry"><!-- wp:post-kinds-indieweb/listen-card ' . $json . ' /--></div><!-- /wp:group -->',
				],
				$post_args
			)
		);
	}

	/**
	 * A minimal Yoast-28.2-shaped graph for a singular post.
	 */
	private function make_graph( string $permalink ): array {
		return [
			[
				'@type'     => 'Article',
				'@id'       => $permalink . '#article',
				'headline'  => 'Test',
				'wordCount' => 2,
			],
			[
				'@type' => 'WebPage',
				'@id'   => $permalink,
				'url'   => $permalink,
			],
		];
	}

	private function make_context( string $permalink, ?string $main_image_url = null ): object {
		return (object) [
			'canonical'      => $permalink,
			'main_image_url' => $main_image_url,
		];
	}

	// --- Inertness without Yoast (matrix case 8) ---------------------------

	public function test_register_is_inert_without_yoast(): void {
		( new Yoast_SEO() )->register();
		$this->assertFalse(
			defined( 'WPSEO_VERSION' ),
			'precondition: Yoast must not be loaded in the test suite'
		);
		$this->assertFalse(
			has_filter( 'wpseo_schema_graph' ),
			'register() must not hook anything when Yoast is absent'
		);
	}

	// --- Representative-image resolution -----------------------------------

	public function test_listen_cover_resolves_from_normalized_meta(): void {
		// Meta only, no block content: proves the meta path stands alone.
		$post_id = self::factory()->post->create( [ 'post_content' => 'plain text' ] );
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		update_post_meta( $post_id, '_pkiw_listen_cover', self::COVER );

		$this->assertSame( self::COVER, Yoast_SEO::get_representative_image_url( $post_id ) );
	}

	public function test_block_attr_fallback_covers_posts_saved_before_sync(): void {
		// Matrix case 11: existing posts predate the Card_Meta_Sync map
		// extension, so they have the attr but no meta.
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );
		delete_post_meta( $post_id, '_pkiw_listen_cover' ); // Simulate the pre-change save.
		wp_set_object_terms( $post_id, 'listen', 'kind' );

		$this->assertSame( self::COVER, Yoast_SEO::get_representative_image_url( $post_id ) );
	}

	public function test_listen_without_artwork_resolves_null(): void {
		// Matrix case 5 — the American Obituary shape.
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'American Obituary', 'artistName' => 'U2' ] );
		wp_set_object_terms( $post_id, 'listen', 'kind' );

		$this->assertNull( Yoast_SEO::get_representative_image_url( $post_id ) );
	}

	public function test_ordinary_post_resolves_null(): void {
		// Matrix case 2: no kind media, no card — nothing to expose.
		$post_id = self::factory()->post->create( [ 'post_content' => 'Just words.' ] );

		$this->assertNull( Yoast_SEO::get_representative_image_url( $post_id ) );
	}

	public function test_watch_poster_resolves_from_meta(): void {
		// Matrix case 6: another media kind.
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Dune","posterImage":"https://image.tmdb.org/t/p/w500/dune.jpg"} /-->',
		] );
		wp_set_object_terms( $post_id, 'watch', 'kind' );

		$this->assertSame(
			'https://image.tmdb.org/t/p/w500/dune.jpg',
			Yoast_SEO::get_representative_image_url( $post_id ),
			'watch poster must resolve (via the meta Card_Meta_Sync mirrored on create)'
		);
	}

	/**
	 * Matrix case 10: invalid, unsafe, relative, or non-http(s) image
	 * metadata must yield a truthful omission, never a broken node.
	 *
	 * @dataProvider bad_url_provider
	 */
	public function test_unusable_image_urls_are_omitted( string $bad_url ): void {
		$post_id = self::factory()->post->create( [ 'post_content' => 'x' ] );
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		// Bypass registered-meta sanitization on purpose — simulates
		// hostile or corrupted stored data reaching the resolver.
		update_metadata( 'post', $post_id, '_pkiw_listen_cover', $bad_url );

		$this->assertNull( Yoast_SEO::get_representative_image_url( $post_id ) );
	}

	public function bad_url_provider(): array {
		return [
			'javascript scheme' => [ 'javascript:alert(1)' ],
			'data uri'          => [ 'data:image/png;base64,iVBORw0KGgo=' ],
			'relative path'     => [ '/wp-content/uploads/cover.jpg' ],
			'not a url'         => [ 'american obituary' ],
			'ftp scheme'        => [ 'ftp://example.com/cover.jpg' ],
			'scheme only'       => [ 'https://' ],
		];
	}

	public function test_plain_http_url_is_accepted(): void {
		// http(s) both pass: a non-TLS self-hosted image is still a real,
		// fetchable image — only unsafe/malformed values are omitted.
		$post_id = self::factory()->post->create( [ 'post_content' => 'x' ] );
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		update_metadata( 'post', $post_id, '_pkiw_listen_cover', 'http://example.com/cover.jpg' );

		$this->assertSame( 'http://example.com/cover.jpg', Yoast_SEO::get_representative_image_url( $post_id ) );
	}

	// --- Graph wiring -------------------------------------------------------

	public function test_graph_gains_native_shaped_image_when_yoast_found_none(): void {
		// Matrix case 4, wiring half.
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		$permalink = get_permalink( $post_id );
		$this->go_to( $permalink );

		$graph = ( new Yoast_SEO() )->filter_schema_graph(
			$this->make_graph( $permalink ),
			$this->make_context( $permalink )
		);

		$image_id = $permalink . '#primaryimage';
		$this->assertSame( [ '@id' => $image_id ], $graph[0]['image'], 'Article.image must reference #primaryimage' );
		$this->assertSame( self::COVER, $graph[0]['thumbnailUrl'] );
		$this->assertSame( [ '@id' => $image_id ], $graph[1]['primaryImageOfPage'], 'WebPage.primaryImageOfPage must reference #primaryimage' );
		$this->assertCount( 3, $graph, 'exactly one ImageObject node must be appended' );
		$this->assertSame( 'ImageObject', $graph[2]['@type'] );
		$this->assertSame( $image_id, $graph[2]['@id'] );
		$this->assertSame( self::COVER, $graph[2]['url'] );
		$this->assertSame( self::COVER, $graph[2]['contentUrl'] );
		$this->assertSame( 2, $graph[0]['wordCount'], 'wordCount must never be touched' );
	}

	public function test_graph_untouched_when_yoast_already_has_an_image(): void {
		// Matrix cases 1 and 3: featured image (or content image) present
		// means Yoast resolved main_image_url — the filter must not compete.
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		$permalink = get_permalink( $post_id );
		$this->go_to( $permalink );

		$graph  = $this->make_graph( $permalink );
		$result = ( new Yoast_SEO() )->filter_schema_graph(
			$graph,
			$this->make_context( $permalink, 'https://example.com/featured.jpg' )
		);

		$this->assertSame( $graph, $result );
	}

	public function test_graph_untouched_outside_singular_post_views(): void {
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		$permalink = get_permalink( $post_id );
		$this->go_to( home_url( '/' ) );

		$graph  = $this->make_graph( $permalink );
		$result = ( new Yoast_SEO() )->filter_schema_graph( $graph, $this->make_context( $permalink ) );

		$this->assertSame( $graph, $result );
	}

	public function test_draft_post_gets_no_wiring(): void {
		// Matrix case 9: a draft's permalink doesn't resolve to a singular
		// public view, so the anonymous request path never wires an image.
		$post_id = $this->make_listen_post(
			[ 'trackTitle' => 'One', 'coverImage' => self::COVER ],
			[ 'post_status' => 'draft' ]
		);
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		$permalink = get_permalink( $post_id );
		$this->go_to( $permalink );

		$graph  = $this->make_graph( $permalink );
		$result = ( new Yoast_SEO() )->filter_schema_graph( $graph, $this->make_context( $permalink ) );

		$this->assertSame( $graph, $result );
	}

	public function test_graph_untouched_when_kind_has_no_image(): void {
		// Matrix case 5, wiring half: no artwork → no node, no refs.
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'American Obituary', 'artistName' => 'U2' ] );
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		$permalink = get_permalink( $post_id );
		$this->go_to( $permalink );

		$graph  = $this->make_graph( $permalink );
		$result = ( new Yoast_SEO() )->filter_schema_graph( $graph, $this->make_context( $permalink ) );

		$this->assertSame( $graph, $result );
	}

	// --- Micropub / editor consistency (matrix case 7) ----------------------

	public function test_micropub_and_editor_posts_resolve_identically(): void {
		// Micropub path: real builder output from a real property bag.
		$micropub_content = \PKIW\Micropub_Content_Builder::fill_empty_content(
			'',
			[
				'properties' => [
					'listen-of' => [ 'https://musicbrainz.org/recording/one' ],
					'name'      => [ 'One' ],
					'author'    => [ 'U2' ],
					'photo'     => [ self::COVER ],
				],
			]
		);
		$micropub_id = self::factory()->post->create( [ 'post_content' => $micropub_content ] );
		wp_set_object_terms( $micropub_id, 'listen', 'kind' );

		// Editor path: the same normalized data as card attributes.
		$editor_id = $this->make_listen_post( [
			'listenUrl'  => 'https://musicbrainz.org/recording/one',
			'trackTitle' => 'One',
			'artistName' => 'U2',
			'coverImage' => self::COVER,
		] );
		wp_set_object_terms( $editor_id, 'listen', 'kind' );

		// Both origins must normalize the same core meta…
		$this->assertSame( 'One', get_post_meta( $micropub_id, '_pkiw_listen_track', true ) );
		$this->assertSame(
			get_post_meta( $editor_id, '_pkiw_listen_track', true ),
			get_post_meta( $micropub_id, '_pkiw_listen_track', true )
		);
		$this->assertSame(
			get_post_meta( $editor_id, '_pkiw_listen_artist', true ),
			get_post_meta( $micropub_id, '_pkiw_listen_artist', true )
		);

		// …and the same representative-image outcome. The Micropub builder
		// intentionally renders `photo` as a visible image block (which
		// Yoast's own first-content-image scan resolves), while editor
		// artwork rides the card attr — so the *schema image* both posts
		// end up with is the same URL, arriving via the two documented
		// lanes.
		$this->assertSame( self::COVER, Yoast_SEO::get_representative_image_url( $editor_id ) );
	}
}
