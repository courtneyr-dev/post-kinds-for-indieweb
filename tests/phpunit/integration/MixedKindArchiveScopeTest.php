<?php
/**
 * Mixed-kind archive repair: what must not change (N7, option B).
 *
 * Every hash below was recorded on the base commit d7ab33d before the repair
 * existed. A row without a Check-in term, a Check-in row on its own archive,
 * and every non-archive surface must render byte for byte as before. Names,
 * URLs and dates are invented.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Scope of the mixed-kind repair.
 *
 * @group integration
 */
final class MixedKindArchiveScopeTest extends WP_UnitTestCase {

	/**
	 * Hashes recorded on the base commit, by surface.
	 *
	 * @var array<string, string>
	 */
	private const BASE = [
		'archive-like'     => '0392c835c01322f20c0cf24ed855f802',
		'archive-repost'   => '89d4974cbcd44c2842daff79e4472a4f',
		'archive-favorite' => '52e99c184da4caf125176feb47841bb4',
		'archive-jam'      => '752d9db51844c4b734f68572095d5df8',
		'checkin-archive'  => 'd08ada7a7e872fe6cfc3ecaa4e94fe55',
		'home-card'        => 'c38763ccf832ad89d146aeb3d81a7326',
		'single-card'      => '73a293aa4005993aa33164ced4780d47',
		'rest-item'        => '77c76c80322d9bfb8bfd74985a392996',
	];

	/**
	 * Every post ID the test created, for normalizing output.
	 *
	 * @var int[]
	 */
	private array $ids = [];

	/**
	 * Register the request environment.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'blocked', 'No network in tests.' ) );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	/**
	 * Restore URL and user state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A published post in the given kinds with fixed content.
	 *
	 * @param string[] $kinds   Kind slugs, in term order.
	 * @param string   $slug    Slug.
	 * @param string   $date    Post date.
	 * @param string   $content Post content.
	 */
	private function post( array $kinds, string $slug, string $date, string $content ): int {
		$id = wp_insert_post(
			[
				'post_status'  => 'publish',
				'post_title'   => ucfirst( str_replace( '-', ' ', $slug ) ),
				'post_name'    => $slug,
				'post_date'    => $date,
				'post_content' => wp_slash( $content ),
			]
		);
		wp_set_object_terms( $id, $kinds, Taxonomy::TAXONOMY );
		$this->ids[] = (int) $id;

		return (int) $id;
	}

	/**
	 * A card block comment.
	 *
	 * @param string               $kind  Kind slug.
	 * @param array<string, mixed> $attrs Attributes.
	 */
	private function card( string $kind, array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/' . $kind . '-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * Unmixed rows for one kind.
	 *
	 * @param string $kind Kind slug.
	 */
	private function unmixed( string $kind ): void {
		$attrs = 'jam' === $kind
			? [
				'title'  => 'Quiet Orchards',
				'artist' => 'Marlowe Kite',
			]
			: [
				'title'  => 'A Cited Page',
				'url'    => 'https://example.test/cited-' . $kind,
				'author' => 'Rowan Vale',
			];
		$this->post( [ $kind ], 'plain-' . $kind . '-one', '2026-08-15 10:00:00', $this->card( $kind, $attrs ) );
		$this->post( [ $kind ], 'plain-' . $kind . '-two', '2026-07-12 10:00:00', $this->card( $kind, $attrs ) );
		$this->post( [ $kind ], 'plain-' . $kind . '-meta', '2026-06-03 10:00:00', '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->' );
	}

	/**
	 * Serve a kind archive from its resolved template.
	 *
	 * @param string $kind Kind slug.
	 */
	private function serve( string $kind ): string {
		if ( 'like' === $kind || 'checkin' === $kind ) {
			$markup = '<!-- wp:query {"queryId":0,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/archive-sections {"linesPerPage":12} /--><!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
			$post_id = self::factory()->post->create(
				[
					'post_type'    => 'wp_template',
					'post_name'    => 'taxonomy-kind-' . $kind,
					'post_title'   => 'Test ' . $kind,
					'post_status'  => 'publish',
					'post_content' => $markup,
				]
			);
			wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );
		}
		$this->go_to( (string) get_term_link( $kind, Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-' . $kind . '.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Output with post IDs, where an attribute or class carries one, replaced by creation order.
	 *
	 * @param string $html HTML.
	 */
	private function normalize( string $html ): string {
		foreach ( $this->ids as $n => $id ) {
			$html = (string) preg_replace( '/(?<=post-|t-|a-|item-|p=)' . $id . '(?!\d)/', '{id' . $n . '}', $html );
		}

		return $html;
	}

	/**
	 * The stream card block for a post, rendered as the loop renders it.
	 *
	 * @param int $post_id Post ID.
	 */
	private function stream_card( int $post_id ): string {
		$block = new WP_Block(
			[
				'blockName' => 'post-kinds-indieweb/stream-card',
				'attrs'     => [ 'headingLevel' => 3 ],
			],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			]
		);

		return $this->normalize( $block->render() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function kinds(): array {
		return [
			'like'     => [ 'like' ],
			'repost'   => [ 'repost' ],
			'favorite' => [ 'favorite' ],
			'jam'      => [ 'jam' ],
		];
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_an_unmixed_archive_is_byte_identical_to_the_base( string $kind ): void {
		$this->unmixed( $kind );

		$html = $this->normalize( $this->serve( $kind ) );

		$this->assertStringContainsString( 'plain-' . $kind . '-one', $html );
		$this->assertSame( self::BASE[ 'archive-' . $kind ], md5( $html ), 'archive-' . $kind . ' md5 ' . md5( $html ) );
	}

	public function test_the_checkin_archive_the_home_card_the_single_card_and_rest_are_byte_identical_to_the_base(): void {
		$mixed = $this->post(
			[ 'like', 'checkin' ],
			'mixed-like-checkin',
			'2026-08-20 10:00:00',
			$this->card(
				'like',
				[
					'title' => 'A Cited Page',
					'url'   => 'https://example.test/cited-like',
				]
			) . $this->card( 'checkin', [ 'venueName' => 'Fixture Cafe' ] )
		);
		$this->post( [ 'checkin' ], 'plain-checkin', '2026-08-10 10:00:00', $this->card( 'checkin', [ 'venueName' => 'Other Cafe' ] ) );

		$checkin_html = $this->normalize( $this->serve( 'checkin' ) );
		$this->assertStringContainsString( 'mixed-like-checkin', $checkin_html );
		$this->assertSame( self::BASE['checkin-archive'], md5( $checkin_html ), 'checkin-archive md5 ' . md5( $checkin_html ) );

		$this->go_to( home_url( '/' ) );
		$home = $this->stream_card( $mixed );
		$this->assertNotSame( '', $home );
		$this->assertSame( self::BASE['home-card'], md5( $home ), 'home-card md5 ' . md5( $home ) );

		$this->go_to( (string) get_permalink( $mixed ) );
		$single = $this->stream_card( $mixed );
		$this->assertSame( self::BASE['single-card'], md5( $single ), 'single-card md5 ' . md5( $single ) );

		$GLOBALS['wp_rest_server'] = null;
		$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $mixed ) )->get_data();
		$rest = $this->normalize( (string) wp_json_encode( [ $data['content'] ?? null, $data['title'] ?? null ] ) );
		$this->assertSame( self::BASE['rest-item'], md5( $rest ), 'rest-item md5 ' . md5( $rest ) );
	}

	public function test_no_archive_frame_exists_outside_the_inherited_grouped_loop(): void {
		$mixed = $this->post( [ 'like', 'checkin' ], 'frame-probe', '2026-08-20 10:00:00', $this->card( 'like', [ 'url' => 'https://example.test/x' ] ) );

		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', Grouped_Archive::current_archive_kind() );
		$this->assertSame( '', Grouped_Archive::current_archive_kind( $mixed ) );
		$this->stream_card( $mixed );
		$this->assertSame( '', Grouped_Archive::current_archive_kind(), 'No frame outlives a render.' );
	}

	/**
	 * @return array<string, array{string, string, array<string, string>}>
	 */
	public function native_kinds(): array {
		return [
			'watch'  => [ 'watch', 'watch', [ 'mediaTitle' => 'Native Film' ] ],
			'listen' => [ 'listen', 'listen', [ 'trackTitle' => 'Native Song' ] ],
			'comics' => [ 'comics', 'comic', [ 'title' => 'Native Comic' ] ],
		];
	}

	/**
	 * @dataProvider native_kinds
	 * @param string                $kind Queried kind.
	 * @param string                $card Card block suffix.
	 * @param array<string, string> $attrs Card attributes.
	 */
	public function test_native_archive_selects_its_card_in_either_order_without_affecting_an_independent_loop( string $kind, string $card, array $attrs ): void {
		$foreign = $this->card( 'checkin', [ 'venueName' => 'Native Archive Foreign Venue' ] );
		$own     = $this->card( $card, $attrs );
		$first   = $this->post( [ 'checkin', $kind ], 'native-checkin-first', '2026-08-20 10:00:00', $foreign . $own );
		$this->post( [ 'checkin', $kind ], 'native-own-first', '2026-08-21 10:00:00', $own . $foreign );
		$this->go_to( (string) get_term_link( $kind, Taxonomy::TAXONOMY ) );

		$loop = '<!-- wp:query {"queryId":0,"query":{"inherit":%s,"postType":"post","perPage":10}} -->'
			. '<!-- wp:post-template --><!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /--><!-- /wp:post-template -->'
			. '<!-- /wp:query -->';
		$archive = do_blocks( sprintf( $loop, 'true' ) );
		$this->assertSame( 2, substr_count( $archive, '<article ' ) );
		$this->assertSame( 2, substr_count( $archive, 'pk-card k-' . $kind . ' ' ) );
		$this->assertStringNotContainsString( 'Native Archive Foreign Venue', $archive );
		$this->assertStringNotContainsString( 'Also a check-in', $archive );

		$independent = do_blocks( sprintf( $loop, 'false' ) );
		$this->assertSame( 4, substr_count( $independent, '<article ' ) );
		$this->assertStringContainsString( 'Native Archive Foreign Venue', $independent );
		$this->assertSame( '', Grouped_Archive::current_native_archive_kind( $first ), 'No native frame outlives its Post Template.' );
	}
}
