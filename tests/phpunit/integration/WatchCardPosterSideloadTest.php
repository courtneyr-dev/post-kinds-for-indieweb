<?php
/**
 * Watch card poster under the Sideload image setting (issue 320).
 *
 * Featured_Artwork sideloads a watch poster into the media library when the
 * post is saved, but the watch card printed its stored posterImage URL
 * verbatim, so a visitor's browser still requested image.tmdb.org. These
 * tests save a post through the real save path, render it through the real
 * the_content path, and read the poster URL out of the markup.
 *
 * Every fetch is intercepted with pre_http_request, so no test touches the
 * network.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Featured_Artwork;

/**
 * @group integration
 */
final class WatchCardPosterSideloadTest extends WP_UnitTestCase {

	private const POSTER = 'https://image.tmdb.org/t/p/w342/ted-lasso-s4.jpg';

	/**
	 * URLs the HTTP stub intercepted.
	 *
	 * @var string[]
	 */
	private array $http_urls = [];

	/**
	 * Whether the stub answers 200-with-PNG (true) or 404 (false).
	 *
	 * @var bool
	 */
	private bool $http_ok = true;

	public function set_up(): void {
		parent::set_up();
		$this->http_urls = [];
		$this->http_ok   = true;

		update_option( 'pkiw_settings', [ 'image_handling' => 'sideload' ] );

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) {
				$this->http_urls[] = (string) $url;

				if ( ! $this->http_ok ) {
					return [
						'response' => [
							'code'    => 404,
							'message' => 'Not Found',
						],
						'headers'  => [],
						'body'     => '',
						'cookies'  => [],
					];
				}

				// 1x1 transparent PNG.
				$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );

				if ( ! empty( $parsed_args['filename'] ) ) {
					file_put_contents( $parsed_args['filename'], $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test stub writing the streamed download.
				}

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'headers'  => [ 'content-type' => 'image/png' ],
					'body'     => $png,
					'cookies'  => [],
				];
			},
			10,
			3
		);
	}

	/**
	 * Create a watch post whose card carries a remote TMDB poster.
	 *
	 * @param array<string, mixed> $extra_attrs Card attributes merged over the defaults.
	 * @return int Post ID.
	 */
	private function make_watch_post( array $extra_attrs = [] ): int {
		$attrs = array_merge(
			[
				'mediaTitle'  => 'Ted Lasso',
				'mediaType'   => 'tv',
				'posterImage' => self::POSTER,
			],
			$extra_attrs
		);

		return self::factory()->post->create(
			[
				'post_title'   => 'Ted Lasso',
				'post_content' => '<!-- wp:group {"className":"h-entry","layout":{"type":"constrained"}} --><div class="wp-block-group h-entry"><!-- wp:post-kinds-indieweb/watch-card ' . wp_json_encode( $attrs ) . ' /--></div><!-- /wp:group -->',
			]
		);
	}

	/**
	 * Render a post's content the way the single template does.
	 *
	 * @param int $post_id Post ID.
	 * @return string Rendered HTML.
	 */
	private function render_single( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		setup_postdata( get_post( $post_id ) );

		return apply_filters( 'the_content', get_post( $post_id )->post_content );
	}

	/**
	 * Every src and srcset URL printed on the card's images.
	 *
	 * @param string $html Rendered HTML.
	 * @return string[] URLs.
	 */
	private function image_urls( string $html ): array {
		$urls = [];
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\ssrc="([^"]*)"/i', $tag, $m ) ) {
					$urls[] = $m[1];
				}
				if ( preg_match( '/\ssrcset="([^"]*)"/i', $tag, $m ) ) {
					foreach ( explode( ',', $m[1] ) as $candidate ) {
						$urls[] = strtok( trim( $candidate ), ' ' );
					}
				}
			}
		}
		return $urls;
	}

	public function test_sideloaded_poster_renders_the_local_copy_not_tmdb(): void {
		$post_id = $this->make_watch_post();

		$attachment_id = (int) get_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META, true );
		$this->assertGreaterThan( 0, $attachment_id, 'the save must have sideloaded the poster' );
		$this->assertSame( [ self::POSTER ], $this->http_urls, 'one fetch, at save time' );

		$html = $this->render_single( $post_id );
		$urls = $this->image_urls( $html );

		$this->assertNotEmpty( $urls, 'the card must print its poster' );
		$this->assertSame(
			[],
			array_values( array_filter( $urls, static fn( string $u ): bool => false !== strpos( $u, 'image.tmdb.org' ) ) ),
			'no image URL on the card may point at image.tmdb.org'
		);
		$this->assertStringNotContainsString( 'image.tmdb.org', $html, 'no TMDB image host anywhere in the markup' );
		$this->assertStringContainsString( (string) wp_get_attachment_url( $attachment_id ), $html, 'the poster is the media library copy' );
	}

	public function test_local_poster_keeps_card_classes_and_alt(): void {
		$post_id = $this->make_watch_post( [ 'posterImageAlt' => 'Ted Lasso season 4 key art' ] );

		$html = $this->render_single( $post_id );

		$this->assertMatchesRegularExpression(
			'/<img\b(?=[^>]*\bclass="[^"]*\bpk-thumb--poster\b[^"]*\bu-photo\b)(?=[^>]*\balt="Ted Lasso season 4 key art")[^>]*>/',
			$html,
			'the local poster keeps the card image classes and the stored alt'
		);
	}

	public function test_local_poster_without_stored_alt_uses_the_poster_for_title_fallback(): void {
		$post_id = $this->make_watch_post();

		$html = $this->render_single( $post_id );

		$this->assertMatchesRegularExpression(
			'/<img\b(?=[^>]*\bpk-thumb--poster\b)(?=[^>]*\balt="Poster for Ted Lasso")[^>]*>/',
			$html
		);
	}

	public function test_rendering_the_card_makes_no_http_request(): void {
		$post_id = $this->make_watch_post();
		$saved   = $this->http_urls;

		$this->render_single( $post_id );
		$this->render_single( $post_id );

		$this->assertSame( $saved, $this->http_urls, 'a front-end render must not fetch anything' );
	}

	public function test_poster_that_was_never_sideloaded_is_not_fetched_on_render(): void {
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		$post_id = $this->make_watch_post();
		remove_filter( 'pkiw_set_featured_from_artwork', '__return_false' );

		$this->assertSame( [], $this->http_urls, 'sideloading was switched off for the save' );

		$this->render_single( $post_id );

		$this->assertSame( [], $this->http_urls, 'render must not sideload on demand' );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ), 'render must not write a featured image' );
	}
}
