<?php
/**
 * The Comic Card contract.
 *
 * A comic someone read is a `comics` post carrying a comic-card. It is not
 * a book read (read-card, `read` kind) and it is not a comic someone drew
 * (a `comics` post with no card), and all three keep their own output.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Block_Bindings_Source;
use PKIW\Card_Meta_Sync;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ComicCardTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/comic-card';

	/**
	 * Block every live HTTP request so a render never reaches the network.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', '__return_empty_array' );
		// The test case unregisters meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		// Kind sync on save only assigns a kind whose term exists.
		foreach ( [ 'comics' => 'Comics', 'read' => 'Read' ] as $slug => $name ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				$this->assertNotWPError( wp_insert_term( $name, 'kind', [ 'slug' => $slug ] ) );
			}
		}
	}

	/**
	 * Every field the contract names, filled.
	 *
	 * @return array<string, mixed>
	 */
	private function full_attributes(): array {
		return [
			'title'         => 'Saga',
			'creators'      => 'Brian K. Vaughan, Fiona Staples',
			'series'        => 'Saga',
			'volume'        => '2',
			'issueNumber'   => '7',
			'publisher'     => 'Image Comics',
			'coverImage'    => 'https://example.com/covers/saga-7.jpg',
			'coverImageAlt' => 'A horned parent holds a swaddled baby beside a winged parent.',
			'sourceUrl'     => 'https://example.com/comics/saga-7',
			'readStatus'    => 'finished',
			'rating'        => 4,
			'startedAt'     => '2026-08-30',
			'finishedAt'    => '2026-09-02',
			'review'        => 'Lying Cat steals every page.',
		];
	}

	/**
	 * Render the block on its own.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function render( array $attributes ): string {
		return render_block(
			[
				'blockName'    => self::BLOCK,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Block comment for post content.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function block_comment( array $attributes ): string {
		return '<!-- wp:' . self::BLOCK . ' ' . wp_json_encode( $attributes ) . ' /-->';
	}

	/**
	 * The first h-entry in a parsed document.
	 *
	 * @param string $html Markup wrapped as an entry.
	 * @return array<string, mixed>
	 */
	private function parsed_entry( string $html ): array {
		$parsed = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' );
		foreach ( $parsed['items'] as $item ) {
			if ( in_array( 'h-entry', $item['type'], true ) ) {
				return $item;
			}
		}
		$this->fail( 'No h-entry parsed.' );
	}

	public function test_comic_card_is_registered_with_the_contract_fields(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );

		$this->assertInstanceOf( WP_Block_Type::class, $type );
		foreach ( array_keys( $this->full_attributes() ) as $attribute ) {
			$this->assertArrayHasKey( $attribute, $type->attributes, "Missing attribute {$attribute}." );
		}
		$this->assertSame( 3, $type->api_version );
	}

	/**
	 * The editor leaves an attribute that equals its block.json default out
	 * of the saved comment, and the meta mirror never overwrites a stored
	 * value with a missing one. A default on readStatus would therefore
	 * leave the meta at "finished" after an author switched back to
	 * "reading". With no default, every choice is saved.
	 */
	public function test_status_has_no_block_default_so_every_choice_is_saved(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );

		$this->assertArrayNotHasKey( 'default', $type->attributes['readStatus'] );
	}

	public function test_switching_a_saved_status_back_to_reading_updates_the_meta(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->block_comment(
					[
						'title'      => 'Saga',
						'readStatus' => 'finished',
					]
				),
			]
		);
		$this->assertSame( 'finished', get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_status', true ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->block_comment(
					[
						'title'      => 'Saga',
						'readStatus' => 'reading',
					]
				),
			]
		);

		$this->assertSame( 'reading', get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_status', true ) );
	}

	public function test_comic_card_maps_to_comics_and_read_card_still_maps_to_read(): void {
		$this->assertSame( 'comics', Taxonomy::KIND_CARD_BLOCKS[ self::BLOCK ] ?? null );
		$this->assertSame( 'read', Taxonomy::KIND_CARD_BLOCKS['post-kinds-indieweb/read-card'] );
	}

	public function test_a_post_that_opens_with_a_comic_card_gets_the_comics_kind(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->block_comment( $this->full_attributes() ),
			]
		);

		$this->assertTrue( has_term( 'comics', 'kind', $post_id ) );
		$this->assertFalse( has_term( 'read', 'kind', $post_id ) );
	}

	public function test_render_carries_read_of_and_every_stored_field(): void {
		$html = $this->render( $this->full_attributes() );

		$this->assertMatchesRegularExpression( '/<article[^>]*class="[^"]*\bpk-card k-comics h-cite u-read-of\b/', $html );
		$this->assertStringNotContainsString( 'k-read', $html );
		$this->assertMatchesRegularExpression( '#<h2 class="pk-title p-name">\s*<a class="u-url" href="https://example.com/comics/saga-7"#', $html );
		$this->assertStringContainsString( '<span class="pk-comic-series">Saga</span>', $html );
		$this->assertStringContainsString( '<span class="pk-comic-volume">Vol. 2</span>', $html );
		$this->assertStringContainsString( '<span class="pk-comic-number">#7</span>', $html );
		$this->assertStringContainsString( '<span class="p-author h-card"><span class="p-name">Brian K. Vaughan, Fiona Staples</span></span>', $html );
		$this->assertStringContainsString( '<span class="pk-comic-publisher">Image Comics</span>', $html );
		$this->assertStringContainsString( '<span class="pk-comic-status">Finished</span>', $html );
		$this->assertStringContainsString( 'aria-label="Rated 4 of 5"', $html );
		$this->assertStringContainsString( '<data class="p-rating" value="4" hidden></data>', $html );
		$this->assertStringContainsString( 'src="https://example.com/covers/saga-7.jpg"', $html );
		$this->assertStringContainsString( '<div class="pk-note p-content">Lying Cat steals every page.</div>', $html );
	}

	public function test_kind_label_reads_comic_not_read(): void {
		$html = $this->render( $this->full_attributes() );

		$this->assertStringContainsString( '<span class="pk-kindlabel">Comic</span>', $html );
	}

	public function test_reading_comic_labels_its_date_started_and_claims_no_completion(): void {
		$attributes               = $this->full_attributes();
		$attributes['readStatus'] = 'reading';
		$html                     = $this->render( $attributes );

		$this->assertStringContainsString( '<span class="pk-comic-status">Currently reading</span>', $html );
		$this->assertMatchesRegularExpression( '#<time class="pk-comic-started" datetime="2026-08-30">\s*Started: August 30, 2026\s*</time>#', $html );
		// The stored finish date belongs to a status this comic does not have.
		$this->assertStringNotContainsString( 'Finished', $html );
		$this->assertStringNotContainsString( 'September 2, 2026', $html );
		$this->assertStringNotContainsString( 'dt-published', $html );
		$this->assertDoesNotMatchRegularExpression( '/\bRead:/', $html );
	}

	public function test_finished_comic_shows_its_completion_date(): void {
		$html = $this->render( $this->full_attributes() );

		$this->assertMatchesRegularExpression( '#<time class="pk-comic-started" datetime="2026-08-30">\s*Started: August 30, 2026\s*</time>#', $html );
		$this->assertMatchesRegularExpression( '#<time class="pk-comic-finished dt-published" datetime="2026-09-02">\s*Finished: September 2, 2026\s*</time>#', $html );
	}

	public function test_set_aside_comic_labels_its_end_date_set_aside(): void {
		$attributes               = $this->full_attributes();
		$attributes['readStatus'] = 'abandoned';
		$html                     = $this->render( $attributes );

		$this->assertStringContainsString( '<span class="pk-comic-status">Set aside</span>', $html );
		$this->assertMatchesRegularExpression( '#<time class="pk-comic-finished" datetime="2026-09-02">\s*Set aside: September 2, 2026\s*</time>#', $html );
		$this->assertStringNotContainsString( 'Finished', $html );
	}

	public function test_to_read_comic_renders_no_dates(): void {
		$attributes               = $this->full_attributes();
		$attributes['readStatus'] = 'to-read';
		$html                     = $this->render( $attributes );

		$this->assertStringContainsString( '<span class="pk-comic-status">To read</span>', $html );
		$this->assertStringNotContainsString( '<time', $html );
	}

	public function test_a_stored_calendar_day_does_not_shift_with_the_site_timezone(): void {
		update_option( 'timezone_string', 'America/New_York' );

		$html = $this->render(
			[
				'title'      => 'Anzuelo',
				'readStatus' => 'reading',
				'startedAt'  => '2026-09-06',
			]
		);

		$this->assertStringContainsString( 'Started: September 6, 2026', $html );
		$this->assertStringNotContainsString( 'September 5', $html );
	}

	/**
	 * Stored text that is not a real calendar day.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function not_a_calendar_day(): array {
		return [
			'a day the month does not have' => [ '2026-02-30' ],
			'a relative phrase'              => [ 'tomorrow' ],
			'zeros'                          => [ '0000-00-00' ],
			'prose'                          => [ 'last week sometime' ],
		];
	}

	/**
	 * @dataProvider not_a_calendar_day
	 *
	 * @param string $stored Stored start date.
	 */
	public function test_text_that_is_not_a_calendar_day_renders_no_date( string $stored ): void {
		$html = $this->render(
			[
				'title'      => 'Anzuelo',
				'readStatus' => 'reading',
				'startedAt'  => $stored,
			]
		);

		$this->assertStringNotContainsString( '<time', $html );
		$this->assertStringNotContainsString( 'Started', $html );
	}

	public function test_a_stored_date_with_a_time_keeps_its_calendar_day(): void {
		$html = $this->render(
			[
				'title'      => 'Anzuelo',
				'readStatus' => 'reading',
				'startedAt'  => '2026-09-06T23:30:00-04:00',
			]
		);

		$this->assertMatchesRegularExpression( '#<time class="pk-comic-started" datetime="2026-09-06">\s*Started: September 6, 2026\s*</time>#', $html );
	}

	public function test_a_rating_above_five_reads_as_five(): void {
		$html = $this->render(
			[
				'title'  => 'Anzuelo',
				'rating' => 9,
			]
		);

		$this->assertStringContainsString( 'aria-label="Rated 5 of 5"', $html );
		$this->assertStringContainsString( '<data class="p-rating" value="5" hidden></data>', $html );
	}

	public function test_a_rating_below_zero_renders_no_rating(): void {
		$html = $this->render(
			[
				'title'  => 'Anzuelo',
				'rating' => -3,
			]
		);

		$this->assertStringNotContainsString( 'pk-stars', $html );
		$this->assertStringNotContainsString( 'p-rating', $html );
	}

	/**
	 * A field left empty, and markup that must not appear for it.
	 *
	 * @return array<string, array{0: string[], 1: string[]}>
	 */
	public function omitted_fields(): array {
		return [
			'cover'            => [ [ 'coverImage', 'coverImageAlt' ], [ 'pk-media', '<img' ] ],
			'series and issue' => [ [ 'series', 'volume', 'issueNumber' ], [ 'pk-comic-issue', 'pk-comic-series', 'pk-comic-volume', 'pk-comic-number' ] ],
			'issue number'     => [ [ 'issueNumber' ], [ 'pk-comic-number' ] ],
			'volume'           => [ [ 'volume' ], [ 'pk-comic-volume', 'Vol.' ] ],
			'creators'         => [ [ 'creators' ], [ 'p-author' ] ],
			'publisher'        => [ [ 'publisher' ], [ 'pk-comic-publisher' ] ],
			'rating'           => [ [ 'rating' ], [ 'pk-stars', 'p-rating' ] ],
			'dates'            => [ [ 'startedAt', 'finishedAt' ], [ '<time' ] ],
			'note'             => [ [ 'review' ], [ 'pk-note' ] ],
			'source link'      => [ [ 'sourceUrl' ], [ '<a ' ] ],
		];
	}

	/**
	 * @dataProvider omitted_fields
	 *
	 * @param string[] $unset  Attributes to leave out.
	 * @param string[] $absent Strings the markup must not contain.
	 */
	public function test_an_unstored_field_is_left_out( array $unset, array $absent ): void {
		$attributes = array_diff_key( $this->full_attributes(), array_flip( $unset ) );
		$html       = $this->render( $attributes );

		foreach ( $absent as $needle ) {
			$this->assertStringNotContainsString( $needle, $html );
		}
		$this->assertStringContainsString( 'pk-card k-comics h-cite u-read-of', $html );
	}

	public function test_a_card_with_only_a_title_renders_the_title_alone(): void {
		$html = $this->render( [ 'title' => 'Anzuelo' ] );

		$this->assertMatchesRegularExpression( '#<h2 class="pk-title p-name">\s*Anzuelo\s*</h2>#', $html );
		$this->assertStringContainsString( '<span class="pk-comic-status">Currently reading</span>', $html );
		foreach ( [ 'pk-media', 'pk-comic-issue', 'p-author', 'pk-stars', '<time', 'pk-note' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html );
		}
	}

	public function test_cover_uses_the_stored_alt_text(): void {
		$html = $this->render( $this->full_attributes() );

		$this->assertStringContainsString( 'alt="A horned parent holds a swaddled baby beside a winged parent."', $html );
	}

	public function test_cover_without_stored_alt_names_the_comic(): void {
		$attributes = $this->full_attributes();
		unset( $attributes['coverImageAlt'] );

		$this->assertStringContainsString( 'alt="Cover of Saga"', $this->render( $attributes ) );
	}

	public function test_saving_mirrors_the_card_into_comic_meta_and_writes_no_read_meta(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->block_comment( $this->full_attributes() ),
			]
		);

		$expected = [
			'comic_title'       => 'Saga',
			'comic_creators'    => 'Brian K. Vaughan, Fiona Staples',
			'comic_series'      => 'Saga',
			'comic_volume'      => '2',
			'comic_issue'       => '7',
			'comic_publisher'   => 'Image Comics',
			'comic_cover'       => 'https://example.com/covers/saga-7.jpg',
			'comic_cover_alt'   => 'A horned parent holds a swaddled baby beside a winged parent.',
			'comic_url'         => 'https://example.com/comics/saga-7',
			'comic_status'      => 'finished',
			'comic_rating'      => '4',
			'comic_started_at'  => '2026-08-30',
			'comic_finished_at' => '2026-09-02',
			'comic_review'      => 'Lying Cat steals every page.',
		];
		foreach ( $expected as $suffix => $value ) {
			$this->assertSame( $value, (string) get_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, true ), "Meta {$suffix}." );
		}
		$this->assertSame( array_keys( $expected ), array_values( Card_Meta_Sync::ATTR_META_MAP[ self::BLOCK ] ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'read_title', true ) );
	}

	public function test_comic_meta_is_registered_for_rest(): void {
		$registered = get_registered_meta_keys( 'post', 'post' );

		foreach ( [ 'comic_title', 'comic_series', 'comic_issue', 'comic_status', 'comic_started_at', 'comic_cover_alt' ] as $suffix ) {
			$key = Meta_Fields::PREFIX . $suffix;
			$this->assertArrayHasKey( $key, $registered, "Meta {$key} is not registered." );
			$this->assertNotEmpty( $registered[ $key ]['show_in_rest'], "Meta {$key} is hidden from REST." );
		}
		$this->assertSame(
			[ '', 'to-read', 'reading', 'finished', 'abandoned' ],
			$registered[ Meta_Fields::PREFIX . 'comic_status' ]['show_in_rest']['schema']['enum'] ?? null
		);
	}

	public function test_a_card_saved_without_a_status_stores_the_status_it_displays(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->block_comment( [ 'title' => 'Anzuelo' ] ),
			]
		);

		$this->assertSame( 'reading', get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_status', true ) );
	}

	public function test_an_unrecognised_status_is_stored_as_no_status(): void {
		$meta = new Meta_Fields();

		$this->assertSame( '', $meta->sanitize_comic_status( 'devoured' ) );
		$this->assertSame( 'finished', $meta->sanitize_comic_status( 'finished' ) );
	}

	public function test_a_comic_read_parses_as_an_entry_with_read_of(): void {
		$entry   = $this->parsed_entry( $this->render( $this->full_attributes() ) );
		$read_of = $entry['properties']['read-of'][0] ?? null;

		$this->assertIsArray( $read_of );
		$this->assertContains( 'h-cite', $read_of['type'] );
		$this->assertSame( [ 'Saga' ], $read_of['properties']['name'] );
		$this->assertSame( [ 'https://example.com/comics/saga-7' ], $read_of['properties']['url'] );
		$this->assertSame( [ '4' ], $read_of['properties']['rating'] );
		$this->assertSame( 'https://example.com/covers/saga-7.jpg', $read_of['properties']['photo'][0]['value'] );
		$this->assertSame( 'A horned parent holds a swaddled baby beside a winged parent.', $read_of['properties']['photo'][0]['alt'] );
	}

	public function test_an_authored_comic_keeps_its_kind_and_claims_no_read_of(): void {
		$content = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/strip.png" alt="A three-panel strip about a cat."/></figure><!-- /wp:image -->'
			. "\n\n<!-- wp:paragraph --><p>New strip this week.</p><!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Cat Strip 12',
				'post_content' => $content,
			]
		);
		wp_set_object_terms( $post_id, 'comics', 'kind' );
		wp_update_post( [ 'ID' => $post_id ] );

		$this->assertTrue( has_term( 'comics', 'kind', $post_id ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_title', true ) );
		// A strip its author drew has no reading state, not a default one.
		$this->assertSame( '', get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_status', true ) );

		$this->go_to( get_permalink( $post_id ) );
		$card = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );

		$this->assertStringContainsString( 'k-comics', $card );
		$this->assertStringNotContainsString( 'u-read-of', $card );
		$this->assertStringNotContainsString( 'h-cite', $card );
		$this->assertArrayNotHasKey( 'read-of', $this->parsed_entry( do_blocks( $content ) )['properties'] );
	}

	public function test_a_book_read_keeps_its_own_card_kind_and_meta(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Piranesi","authorName":"Susanna Clarke"} /-->',
			]
		);
		$html    = do_blocks( (string) get_post_field( 'post_content', $post_id ) );

		$this->assertTrue( has_term( 'read', 'kind', $post_id ) );
		$this->assertFalse( has_term( 'comics', 'kind', $post_id ) );
		$this->assertStringContainsString( 'pk-card k-read h-cite u-read-of', $html );
		$this->assertStringNotContainsString( 'k-comics', $html );
		$this->assertSame( 'Piranesi', (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'read_title', true ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_title', true ) );
	}

	public function test_the_stream_card_renders_a_card_only_comic_as_the_comic_card(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Saga',
				'post_content' => $this->block_comment( $this->full_attributes() ),
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		$card = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /-->' );

		$this->assertStringContainsString( 'pk-card k-comics h-cite u-read-of', $card );
		$this->assertMatchesRegularExpression(
			'#<data class="u-url" value="https://example.com/comics/saga-7" hidden></data><h3 class="pk-title p-name">\s*<a href="' . preg_quote( esc_url( (string) get_permalink( $post_id ) ), '#' ) . '">Saga</a>#',
			$card
		);
	}

	public function test_kind_meta_bindings_resolve_comic_fields_for_a_comics_post(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->block_comment( $this->full_attributes() ),
			]
		);
		$source  = new Block_Bindings_Source();
		$block   = (object) [ 'context' => [ 'postId' => $post_id ] ];

		$this->assertSame( 'Saga', $source->get_value( [ 'key' => 'title' ], $block, 'content' ) );
		$this->assertSame( 'Brian K. Vaughan, Fiona Staples', $source->get_value( [ 'key' => 'author' ], $block, 'content' ) );
		$this->assertSame( 'https://example.com/comics/saga-7', $source->get_value( [ 'key' => 'url' ], $block, 'url' ) );
		$this->assertSame( 'https://example.com/covers/saga-7.jpg', $source->get_value( [ 'key' => 'cover_image' ], $block, 'url' ) );
		$this->assertSame( 'Image Comics', $source->get_value( [ 'key' => 'publisher' ], $block, 'content' ) );
		$this->assertSame( '4', $source->get_value( [ 'key' => 'rating' ], $block, 'content' ) );
	}
}
