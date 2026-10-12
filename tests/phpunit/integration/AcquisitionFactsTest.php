<?php
/**
 * Acquisition facts reader coverage for issue 239.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * The acquisition facts reader normalizes card, meta and image data before
 * archives or Stream cards see it.
 *
 * @group integration
 */
final class AcquisitionFactsTest extends WP_UnitTestCase {

	/**
	 * Register kind terms, meta and no-network guards.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'blocked', 'No network in tests.' )
		);
		wp_set_current_user( 0 );
	}

	/**
	 * Restore anonymous state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A serialized acquisition-card comment.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/acquisition-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * A published acquisition.
	 *
	 * @param array<string, mixed>|null $attrs Card attributes, or null for no card.
	 * @param array<string, mixed>      $args  Post args.
	 */
	private function acquisition( ?array $attrs = [], array $args = [] ): int {
		$content = null === $attrs ? ( $args['post_content'] ?? '' ) : $this->card( $attrs );
		unset( $args['post_content'] );
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Field Recorder',
					'post_date'    => '2026-08-12 10:00:00',
					'post_content' => wp_slash( $content ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'acquisition', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * An uploaded image with optional stored alt text.
	 *
	 * @param string $alt Alt text.
	 */
	private function image( string $alt = '' ): int {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

		return $attachment_id;
	}

	/**
	 * Facts for a post.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>
	 */
	private function facts( int $id ): array {
		return \PKIW\Acquisition_Archive::facts( $id );
	}

	public function test_the_reader_is_registered_and_kind_facts_uses_it(): void {
		$id = $this->acquisition( [ 'title' => 'Field Recorder' ] );

		$this->assertNotNull( Kind_Facts::reader( 'acquisition' ) );
		$this->assertSame( $this->facts( $id ), \PKIW\kind_facts( $id ) );
	}

	public function test_facts_have_the_exact_public_shape(): void {
		$facts = $this->facts( $this->acquisition( [ 'title' => 'Field Recorder' ] ) );

		$this->assertEqualsCanonicalizing(
			[ 'has_card', 'title', 'type', 'type_label', 'where', 'where_url', 'notes', 'acquired_at', 'acquired_display', 'cost', 'picture', 'name', 'name_is_title' ],
			array_keys( $facts )
		);
		$this->assertSame(
			[ 'source', 'attachment_id', 'url', 'alt', 'remote', 'suppress_featured', 'width', 'height' ],
			array_keys( $facts['picture'] )
		);
	}

	public function test_type_labels_are_in_the_public_order(): void {
		$this->assertSame(
			[
				'purchase'  => 'Purchase',
				'gift'      => 'Gift',
				'found'     => 'Found',
				'won'       => 'Won',
				'trade'     => 'Trade',
				'free'      => 'Free',
				'inherited' => 'Inherited',
				'other'     => 'Other',
			],
			\PKIW\Acquisition_Archive::type_labels()
		);
	}

	public function test_card_title_beats_meta_and_no_card_uses_meta_only(): void {
		$card = $this->acquisition( [ 'title' => 'Brass Desk Lamp' ] );
		update_post_meta( $card, '_pkiw_acquisition_name', 'Meta Lamp Zq1' );
		$this->assertSame( 'Brass Desk Lamp', $this->facts( $card )['title'] );

		$no_card = $this->acquisition( null, [ 'post_content' => '<!-- wp:paragraph --><p>Quick note.</p><!-- /wp:paragraph -->' ] );
		update_post_meta( $no_card, '_pkiw_acquisition_name', 'Canvas Tote' );
		$facts = $this->facts( $no_card );

		$this->assertFalse( $facts['has_card'] );
		$this->assertSame( 'Canvas Tote', $facts['title'] );
		foreach ( [ 'type', 'type_label', 'where', 'where_url', 'notes', 'acquired_at', 'acquired_display', 'cost' ] as $key ) {
			$this->assertSame( '', $facts[ $key ], $key );
		}
	}

	public function test_cost_comes_only_from_the_card_when_both_public_flags_are_on(): void {
		$id = $this->acquisition( [ 'title' => 'Field Recorder', 'cost' => '$129.00' ] );
		$this->assertSame( '', $this->facts( $id )['cost'] );

		wp_update_post( [ 'ID' => $id, 'post_content' => wp_slash( $this->card( [ 'title' => 'Field Recorder', 'cost' => '$129.00', 'showCostPublicly' => true ] ) ) ] );
		delete_post_meta( $id, '_pkiw_acquisition_cost_public' );
		$this->assertSame( '', $this->facts( $id )['cost'] );

		update_post_meta( $id, '_pkiw_acquisition_cost_public', '1' );
		$this->assertSame( '$129.00', $this->facts( $id )['cost'] );

		wp_update_post( [ 'ID' => $id, 'post_content' => wp_slash( $this->card( [ 'title' => 'Field Recorder', 'cost' => '$129.00' ] ) ) ] );
		$this->assertSame( '', $this->facts( $id )['cost'] );

		$empty_cost = $this->acquisition( [ 'title' => 'Empty Cost', 'cost' => '', 'showCostPublicly' => true ] );
		update_post_meta( $empty_cost, '_pkiw_acquisition_price', '$20.00' );
		$this->assertSame( '', $this->facts( $empty_cost )['cost'] );

		$no_card = $this->acquisition( null );
		update_post_meta( $no_card, '_pkiw_acquisition_price', '$18.00' );
		$this->assertSame( '', $this->facts( $no_card )['cost'] );
	}

	public function test_type_defaults_known_labels_and_unknown_labels(): void {
		$default = $this->facts( $this->acquisition( [ 'title' => 'Default Type' ] ) );
		$this->assertSame( 'purchase', $default['type'] );
		$this->assertSame( 'Purchase', $default['type_label'] );

		$inherited = $this->facts( $this->acquisition( [ 'title' => 'Family Clock', 'acquisitionType' => 'inherited' ] ) );
		$this->assertSame( 'Inherited', $inherited['type_label'] );

		$unknown = $this->facts( $this->acquisition( [ 'title' => 'Market Box', 'acquisitionType' => 'swap-meet' ] ) );
		$this->assertSame( 'swap-meet', $unknown['type_label'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function date_zones(): array {
		return [
			'New York' => [ 'America/New_York' ],
			'Honolulu' => [ 'Pacific/Honolulu' ],
		];
	}

	/**
	 * @dataProvider date_zones
	 *
	 * @param string $zone Timezone string.
	 */
	public function test_calendar_dates_keep_the_stored_day_across_timezones( string $zone ): void {
		update_option( 'timezone_string', $zone );
		$facts = $this->facts( $this->acquisition( [ 'title' => 'Dated Thing', 'acquiredAt' => '2026-08-01' ] ) );

		$this->assertSame( '2026-08-01', $facts['acquired_at'] );
		$this->assertSame( 'August 1, 2026', $facts['acquired_display'] );
	}

	public function test_invalid_calendar_dates_are_empty(): void {
		$facts = $this->facts( $this->acquisition( [ 'title' => 'Future Thing', 'acquiredAt' => 'tomorrow' ] ) );

		$this->assertSame( '', $facts['acquired_at'] );
		$this->assertSame( '', $facts['acquired_display'] );
	}

	public function test_where_url_accepts_only_web_urls(): void {
		$bad = $this->facts( $this->acquisition( [ 'title' => 'Bad URL', 'whereUrl' => 'javascript:alert(1)' ] ) );
		$this->assertSame( '', $bad['where_url'] );

		$good = $this->facts( $this->acquisition( [ 'title' => 'Good URL', 'whereUrl' => 'https://shop.example.com/field-recorder' ] ) );
		$this->assertSame( 'https://shop.example.com/field-recorder', $good['where_url'] );
	}

	public function test_plain_text_strips_tags_and_decodes_entities_once(): void {
		$facts = $this->facts(
			$this->acquisition(
				[
					'title' => 'Brass <strong>Desk</strong> Lamp &amp; Shade',
					'notes' => 'One<br>Two',
					'where' => '<em>Corner</em> Hardware',
				]
			)
		);

		$this->assertSame( 'Brass Desk Lamp & Shade', $facts['title'] );
		$this->assertSame( "One\nTwo", $facts['notes'] );
		$this->assertSame( 'Corner Hardware', $facts['where'] );
		$this->assertSame( 'Brass Desk Lamp & Shade', \PKIW\Acquisition_Archive::plain( 'Brass <strong>Desk</strong> Lamp &amp; Shade' ) );
		$this->assertSame( "One\nTwo", \PKIW\Acquisition_Archive::plain( 'One<br>Two', true ) );
		$this->assertSame( 'OneTwo', \PKIW\Acquisition_Archive::plain( 'One<br>Two' ) );
	}

	public function test_first_card_wins_and_nested_cards_are_found(): void {
		$id = $this->acquisition(
			null,
			[
				'post_content' => $this->card( [ 'title' => 'First Card' ] ) . $this->card( [ 'title' => 'Second Card' ] ),
			]
		);
		$this->assertSame( 'First Card', $this->facts( $id )['title'] );

		$nested = $this->acquisition(
			null,
			[
				'post_content' => '<!-- wp:group --><div class="wp-block-group">' . $this->card( [ 'title' => 'Nested Card' ] ) . '</div><!-- /wp:group -->',
			]
		);
		$this->assertSame( 'Nested Card', $this->facts( $nested )['title'] );
	}

	public function test_protected_and_private_visibility_controls_facts(): void {
		$protected = $this->acquisition( [ 'title' => 'Locked Box' ], [ 'post_password' => 'secret' ] );
		$this->assertSame( [], $this->facts( $protected ) );
		$this->assertSame( [], \PKIW\kind_facts( $protected ) );

		$private = $this->acquisition( [ 'title' => 'Private Box' ], [ 'post_status' => 'private' ] );
		wp_set_current_user( 0 );
		$this->assertSame( [], $this->facts( $private ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertNotSame( [], $this->facts( $private ) );
	}

	public function test_picture_source_order_local_resolution_dimensions_and_alt(): void {
		$featured_id = $this->image( 'Featured alt' );
		$card_id     = $this->image( 'Card alt' );
		$meta_id     = $this->image( 'Meta alt' );

		$featured_post = $this->acquisition( [ 'title' => 'Featured Wins', 'photo' => wp_get_attachment_url( $card_id ) ] );
		set_post_thumbnail( $featured_post, $featured_id );
		$this->assertSame( 'featured', $this->facts( $featured_post )['picture']['source'] );
		$this->assertSame( $featured_id, $this->facts( $featured_post )['picture']['attachment_id'] );

		$card_post = $this->acquisition( [ 'title' => 'Card Wins', 'photo' => wp_get_attachment_url( $card_id ) ] );
		update_post_meta( $card_post, '_pkiw_acquisition_photo', wp_get_attachment_url( $meta_id ) );
		$picture = $this->facts( $card_post )['picture'];
		$this->assertSame( 'cover', $picture['source'] );
		$this->assertSame( $card_id, $picture['attachment_id'] );
		$this->assertSame( wp_get_attachment_url( $card_id ), $picture['url'] );
		$this->assertFalse( $picture['remote'] );
		$this->assertGreaterThan( 0, $picture['width'] );
		$this->assertGreaterThan( 0, $picture['height'] );

		$remote = $this->facts( $this->acquisition( [ 'title' => 'Remote Lantern', 'photo' => 'https://images.example.com/missing-lantern.jpg' ] ) )['picture'];
		$this->assertSame( 'cover', $remote['source'] );
		$this->assertSame( 0, $remote['attachment_id'] );
		$this->assertSame( 'https://images.example.com/missing-lantern.jpg', $remote['url'] );
		$this->assertTrue( $remote['remote'] );
		$this->assertSame( '', $remote['alt'] );
		$this->assertSame( 0, $remote['width'] );
		$this->assertSame( 0, $remote['height'] );

		$meta_local = $this->acquisition( [ 'title' => 'Meta Local' ] );
		update_post_meta( $meta_local, '_pkiw_acquisition_photo', wp_get_attachment_url( $meta_id ) );
		$this->assertSame( $meta_id, $this->facts( $meta_local )['picture']['attachment_id'] );

		$meta_remote = $this->acquisition( [ 'title' => 'Meta Remote' ] );
		update_post_meta( $meta_remote, '_pkiw_acquisition_photo', 'https://images.example.com/meta-lantern.jpg' );
		$this->assertTrue( $this->facts( $meta_remote )['picture']['remote'] );

		$verbatim = $this->facts( $this->acquisition( [ 'title' => 'Recorder', 'photo' => wp_get_attachment_url( $card_id ), 'photoAlt' => 'A handheld recorder' ] ) )['picture'];
		$this->assertSame( 'A handheld recorder', $verbatim['alt'] );

		$attachment_alt = $this->facts( $this->acquisition( [ 'title' => 'Recorder', 'photo' => wp_get_attachment_url( $card_id ), 'photoAlt' => '' ] ) )['picture'];
		$this->assertSame( 'Card alt', $attachment_alt['alt'] );

		$remote_empty = $this->facts( $this->acquisition( [ 'title' => 'Remote Title Zq2', 'photo' => 'https://images.example.com/remote-title.jpg', 'photoAlt' => '' ] ) )['picture'];
		$this->assertSame( '', $remote_empty['alt'] );
		foreach ( [ $verbatim, $attachment_alt, $remote_empty ] as $candidate ) {
			$this->assertStringNotContainsString( 'Cover of', $candidate['alt'] );
			$this->assertNotSame( 'Remote Title Zq2', $candidate['alt'] );
		}
	}

	public function test_names_prefer_post_title_then_card_title_then_untitled_fallback(): void {
		$titled = $this->facts( $this->acquisition( [ 'title' => 'Card Title' ], [ 'post_title' => 'Field Recorder' ] ) );
		$this->assertSame( 'Field Recorder', $titled['name'] );
		$this->assertTrue( $titled['name_is_title'] );

		$card_named = $this->facts( $this->acquisition( [ 'title' => 'Watering Can' ], [ 'post_title' => '' ] ) );
		$this->assertSame( 'Watering Can', $card_named['name'] );
		$this->assertFalse( $card_named['name_is_title'] );

		$spaces = $this->facts( $this->acquisition( [ 'title' => 'Spaces Card' ], [ 'post_title' => '   ' ] ) );
		$this->assertSame( 'Spaces Card', $spaces['name'] );
		$this->assertFalse( $spaces['name_is_title'] );

		$untitled = $this->acquisition( null, [ 'post_title' => '', 'post_content' => '<!-- wp:paragraph --><p>Quick note.</p><!-- /wp:paragraph -->' ] );
		$facts    = $this->facts( $untitled );
		$this->assertSame( 'Acquisition, ' . get_the_date( '', $untitled ), $facts['name'] );
		$this->assertFalse( $facts['name_is_title'] );
	}

	public function test_stream_cite_and_cite_map_expose_only_the_acquired_item_name(): void {
		$id = $this->acquisition(
			[
				'title'            => 'Field Recorder',
				'whereUrl'         => 'https://shop.example.com/field-recorder',
				'cost'             => '$129.00',
				'showCostPublicly' => true,
			]
		);
		$post = get_post( $id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$map = \PKIW\Acquisition_Archive::cite_map( [] );
		$this->assertArrayHasKey( 'acquisition', $map );
		$this->assertIsCallable( $map['acquisition'] );
		$this->assertArrayHasKey( 'acquisition', \PKIW\stream_card_kind_cite_map() );

		$html = \PKIW\Acquisition_Archive::stream_cite( $this->facts( $id ), $post );
		$this->assertStringContainsString( 'h-cite', $html );
		$this->assertStringContainsString( 'u-acquisition-of', $html );
		$this->assertStringContainsString( '<data class="p-name" value="Field Recorder"></data>', $html );
		$this->assertStringNotContainsString( 'u-url', $html );
		$this->assertStringNotContainsString( 'https://shop.example.com/field-recorder', $html );
		$this->assertStringNotContainsString( '$129.00', $html );
		$this->assertSame( '', \PKIW\Acquisition_Archive::stream_cite( [], $post ) );

		$empty_title = $this->acquisition( [ 'title' => '' ], [ 'post_title' => 'Post Title Stays Out' ] );
		$empty_post  = get_post( $empty_title );
		$this->assertInstanceOf( WP_Post::class, $empty_post );
		$this->assertSame( '', \PKIW\Acquisition_Archive::stream_cite( $this->facts( $empty_title ), $empty_post ) );

		$quick = $this->acquisition( null, [ 'post_title' => '', 'post_content' => '<!-- wp:paragraph --><p>Quick acquisition.</p><!-- /wp:paragraph -->' ] );
		update_post_meta( $quick, '_pkiw_acquisition_name', 'Canvas Tote' );
		$quick_post = get_post( $quick );
		$this->assertInstanceOf( WP_Post::class, $quick_post );
		$this->assertStringContainsString( 'value="Canvas Tote"', \PKIW\stream_card_kind_cite( $quick_post ) );
	}
}
