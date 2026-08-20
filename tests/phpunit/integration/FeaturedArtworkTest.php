<?php
/**
 * Featured_Artwork integration coverage.
 *
 * All remote fetches are intercepted with pre_http_request — no test
 * touches the network. The stub writes a real 1x1 PNG to the stream
 * filename download_url() passes, which is exactly what a successful
 * sideload consumes.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Featured_Artwork;

/**
 * @group integration
 */
final class FeaturedArtworkTest extends WP_UnitTestCase {

	private const COVER   = 'https://coverartarchive.org/release/abc/front-500.png';
	private const COVER_2 = 'https://coverartarchive.org/release/def/front-500.png';

	/**
	 * Number of HTTP requests the stub intercepted.
	 *
	 * @var int
	 */
	private int $http_requests = 0;

	/**
	 * Whether the stub should answer 200-with-PNG (true) or 404 (false).
	 *
	 * @var bool
	 */
	private bool $http_ok = true;

	public function set_up(): void {
		parent::set_up();
		$this->http_requests = 0;
		$this->http_ok       = true;

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) {
				++$this->http_requests;

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

	public function test_artwork_becomes_featured_image_on_save(): void {
		$post_id = $this->make_listen_post(
			[ 'trackTitle' => 'One', 'coverImage' => self::COVER ],
			[ 'post_title' => 'One' ]
		);

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		$this->assertGreaterThan( 0, $thumbnail_id, 'featured image must be set from artwork' );
		$this->assertSame( 1, $this->http_requests, 'exactly one sideload fetch' );
		$this->assertSame( self::COVER, get_post_meta( $post_id, Featured_Artwork::SOURCE_META, true ) );
		$this->assertSame( (string) $thumbnail_id, get_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META, true ) );
		$this->assertSame(
			'One',
			get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
			'sideloaded artwork gets the post title as alt text'
		);
	}

	public function test_existing_featured_image_is_never_replaced(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		// Create with artwork AND a pre-chosen featured image: the save
		// hook must leave the editorial choice alone.
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );
		set_post_thumbnail( $post_id, $attachment_id );
		$requests_before = $this->http_requests;

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'touch' ] );

		$this->assertSame( $attachment_id, get_post_thumbnail_id( $post_id ), 'user-chosen featured image must survive' );
		$this->assertSame( $requests_before, $this->http_requests, 'no fetch when a featured image exists' );
	}

	public function test_removed_auto_featured_image_stays_removed(): void {
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );
		$this->assertGreaterThan( 0, get_post_thumbnail_id( $post_id ) );

		delete_post_thumbnail( $post_id );
		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'touch' ] );

		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ), 'a deliberate removal must not be undone' );
		$this->assertSame( 1, $this->http_requests, 'the same URL must not be re-fetched' );
	}

	public function test_changed_artwork_replaces_only_our_own_image(): void {
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );
		$first   = get_post_thumbnail_id( $post_id );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"One","coverImage":"' . self::COVER_2 . '"} /-->',
			]
		);

		$second = get_post_thumbnail_id( $post_id );
		$this->assertGreaterThan( 0, $second );
		$this->assertNotSame( $first, $second, 'new artwork must replace the auto-set image' );
		$this->assertSame( self::COVER_2, get_post_meta( $post_id, Featured_Artwork::SOURCE_META, true ) );
	}

	public function test_failed_fetch_records_attempt_and_never_retries(): void {
		$this->http_ok = false;
		$post_id       = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );

		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
		$this->assertSame( self::COVER, get_post_meta( $post_id, Featured_Artwork::SOURCE_META, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META, true ) );
		$fetches = $this->http_requests;

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'touch' ] );
		$this->assertSame( $fetches, $this->http_requests, 'a failed URL must not be re-fetched on the next save' );
	}

	public function test_local_attachment_url_is_reused_without_fetch(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$local_url     = wp_get_attachment_url( $attachment_id );

		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => $local_url ] );

		$this->assertSame( $attachment_id, get_post_thumbnail_id( $post_id ), 'existing local attachment must be reused' );
		$this->assertSame( 0, $this->http_requests, 'no sideload for a URL that is already in the media library' );
	}

	public function test_post_without_artwork_is_untouched(): void {
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'American Obituary', 'artistName' => 'U2' ] );

		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
		$this->assertSame( 0, $this->http_requests );
		$this->assertSame( '', get_post_meta( $post_id, Featured_Artwork::SOURCE_META, true ) );
	}

	public function test_filter_disables_the_behavior(): void {
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );

		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );

		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
		$this->assertSame( 0, $this->http_requests );
	}

	public function test_user_without_upload_files_cannot_trigger_sideload(): void {
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		wp_set_current_user( $contributor );

		$post_id = self::factory()->post->create( [
			'post_author'  => $contributor,
			'post_status'  => 'draft',
			'post_content' => '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"One","coverImage":"' . self::COVER . '"} /-->',
		] );

		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
		$this->assertSame( 0, $this->http_requests, 'no sideload for a user without upload_files' );
		$this->assertSame(
			'',
			get_post_meta( $post_id, Featured_Artwork::SOURCE_META, true ),
			'a blocked save must leave no attempt marker'
		);

		// A capable user's later save applies the same artwork normally.
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'touch' ] );

		$this->assertGreaterThan( 0, get_post_thumbnail_id( $post_id ), 'an upload-capable save still applies the artwork' );
	}

	public function test_backfill_sets_missing_featured_images(): void {
		// A post that predates the feature: artwork attr present, no
		// featured image, no attempt marker.
		$post_id = $this->make_listen_post( [ 'trackTitle' => 'One', 'coverImage' => self::COVER ] );
		delete_post_thumbnail( $post_id );
		delete_post_meta( $post_id, Featured_Artwork::SOURCE_META );
		delete_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META );
		wp_set_object_terms( $post_id, 'listen', 'kind' );

		$dry = ( new Featured_Artwork() )->backfill( true );
		$this->assertSame( 1, $dry['updated'], 'dry run must count the candidate' );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ), 'dry run must not write' );

		$stats = ( new Featured_Artwork() )->backfill( false );
		$this->assertSame( 1, $stats['updated'] );
		$this->assertGreaterThan( 0, get_post_thumbnail_id( $post_id ), 'backfill must set the featured image' );
	}
}
