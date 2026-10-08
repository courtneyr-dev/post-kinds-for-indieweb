<?php
/**
 * Parsed microformats2 coverage for rendered kind cards.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies that published kind cards expose their canonical properties.
 *
 * TODO: Follow has no card; u-follow-of comes from the Micropub content
 * builder and needs a later Micropub-output test. RSVP is omitted here: its
 * card roots as its own h-entry and emits p-rsvp inside the nested h-event,
 * while the entry-level p-rsvp comes from add_hidden_mf2_data() on the
 * the_content filter (which do_blocks() alone does not run) — so RSVP needs a
 * dedicated test that exercises the full published pipeline, not the card
 * render. The experimental eat, drink, jam, checkin, and acquisition kinds are
 * omitted while their microformats2 vocabulary remains unsettled.
 *
 * @group integration
 */
final class MicroformatsRenderTest extends WP_UnitTestCase {

	/**
	 * Card-backed kinds and their canonical target properties.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function kind_cards(): array {
		return [
			'like'     => [ 'like', 'like-of', 'https://example.com/targets/like' ],
			'reply'    => [ 'reply', 'in-reply-to', 'https://example.com/targets/reply' ],
			'repost'   => [ 'repost', 'repost-of', 'https://example.com/targets/repost' ],
			'bookmark' => [ 'bookmark', 'bookmark-of', 'https://example.com/targets/bookmark' ],
			'favorite' => [ 'favorite', 'favorite-of', 'https://example.com/targets/favorite' ],
			'listen'   => [ 'listen', 'listen-of', 'https://example.com/targets/listen' ],
			'watch'    => [ 'watch', 'watch-of', 'https://example.com/targets/watch' ],
			'read'     => [ 'read', 'read-of', 'https://example.com/targets/read' ],
			'play'     => [ 'play', 'play-of', 'https://example.com/targets/play' ],
		];
	}

	/**
	 * Render a published kind card and parse its canonical microformats2 data.
	 *
	 * @dataProvider kind_cards
	 *
	 * @param string $kind               Post kind and card slug.
	 * @param string $canonical_property Canonical parsed property name.
	 * @param string $target_url         Expected target URL.
	 */
	public function test_kind_card_parses_to_canonical_microformats(
		string $kind,
		string $canonical_property,
		string $target_url
	): void {
		$attributes = $this->card_attributes( $kind, $target_url );
		$block      = sprintf(
			'<!-- wp:post-kinds-indieweb/%s-card %s /-->',
			$kind,
			wp_json_encode( $attributes )
		);
		$post_id    = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $block,
			]
		);

		$term_result = wp_set_object_terms( $post_id, $kind, 'kind' );
		$this->assertNotWPError( $term_result );

		$this->go_to( get_permalink( $post_id ) );
		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );

		// Simulate the theme's post_class h-entry wrapper around the card h-cite.
		$html = '<div class="h-entry">' . $html . '</div>';

		$entry      = $this->top_level_h_entry( \Mf2\parse( $html ) );
		$properties = $entry['properties'] ?? [];

		$this->assertArrayHasKey( $canonical_property, $properties );
		$this->assertPropertyContainsTarget( $properties[ $canonical_property ], $target_url );
	}

	/**
	 * The eight kind cards, plus a like whose cited URL carries a query
	 * string with an ampersand.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function stream_cite_cards(): array {
		return array_merge(
			$this->kind_cards(),
			[
				'like with query' => [ 'like', 'like-of', 'https://example.com/targets/like?a=1&b=2' ],
			]
		);
	}

	/**
	 * Stream relinking keeps the post URL on the outer entry and the cited
	 * URL on the nested h-cite. The canonical property holds one value,
	 * the cited URL, and never the permalink.
	 *
	 * @dataProvider stream_cite_cards
	 */
	public function test_stream_cite_cards_keep_the_canonical_target(
		string $kind,
		string $canonical_property,
		string $target_url
	): void {
		$block   = sprintf(
			'<!-- wp:post-kinds-indieweb/%s-card %s /-->',
			$kind,
			wp_json_encode( $this->card_attributes( $kind, $target_url ) )
		);
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $block,
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, $kind, 'kind' ) );
		$post            = get_post( $post_id );
		$GLOBALS['post'] = $post;
		$html            = \PKIW\ensure_entry_properties( \PKIW\render_stream_card(), $post, false );
		$entry           = $this->top_level_h_entry( \Mf2\parse( '<li class="h-entry">' . $html . '</li>' ) );
		$properties      = $entry['properties'] ?? [];
		$permalink       = (string) get_permalink( $post_id );

		$this->assertContains( $permalink, $properties['url'] ?? [] );
		$this->assertArrayHasKey( $canonical_property, $properties );
		$this->assertCount( 1, $properties[ $canonical_property ] );
		$target_urls = $this->property_urls( $properties[ $canonical_property ] );
		$this->assertNotContains( $permalink, $target_urls );
		$this->assertSame( [ $target_url ], $target_urls );
		$this->assertSame( 1, substr_count( $html, 'href="' . esc_url( $permalink ) . '"' ) );

		// Listen, watch and read cards keep their visible action links to the
		// source; the five cite cards have no other link to the cited page.
		if ( in_array( $kind, [ 'like', 'reply', 'repost', 'bookmark', 'favorite' ], true ) ) {
			$this->assertStringNotContainsString( 'href="' . esc_url( $target_url ) . '"', $html );
		}
	}

	/**
	 * Play cards with and without a game URL, as a video game and as a
	 * board game, each away from its single so the title prints.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public function play_cards(): array {
		return [
			'video with a game url' => [
				[
					'title'   => 'Starbound Courier',
					'rawgId'  => '900001',
					'gameUrl' => 'https://rawg.example/games/starbound-courier',
				],
				'https://rawg.example/games/starbound-courier',
			],
			'video with no game url' => [
				[
					'title'  => 'Copper Kite',
					'rawgId' => '900009',
				],
				null,
			],
			'board with a game url' => [
				[
					'title'   => 'Forest Paths',
					'bggId'   => '9990001',
					'gameUrl' => 'https://example.test/games/forest-paths',
				],
				'https://example.test/games/forest-paths',
			],
			'board with no game url' => [
				[
					'title' => 'Orbit Table',
					'bggId' => '9990002',
				],
				null,
			],
		];
	}

	/**
	 * The entry's play-of is the card's h-cite, named with the stored
	 * title, with a url only when a game URL is stored, and never an empty
	 * value php-mf2 would resolve to the page URL.
	 *
	 * @dataProvider play_cards
	 *
	 * @param array<string, mixed> $attributes Card attributes.
	 * @param string|null          $url        Stored game URL, or null.
	 */
	public function test_a_play_card_is_the_entrys_play_of_citation( array $attributes, ?string $url ): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'A night of games',
				'post_content' => '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES ) . ' /-->',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'play', 'kind' ) );
		$this->go_to( get_permalink( $post_id ) );

		$entry  = $this->top_level_h_entry( \Mf2\parse( '<div class="h-entry">' . do_blocks( (string) get_post_field( 'post_content', $post_id ) ) . '</div>' ) );
		$values = $entry['properties']['play-of'] ?? [];

		$this->assertCount( 1, $values );
		$this->assertSame( [ 'h-cite' ], $values[0]['type'] ?? null );
		$this->assertSame( [ $attributes['title'] ], $values[0]['properties']['name'] ?? null );
		$this->assertNotSame( '', $values[0]['value'] ?? '' );
		$this->assertNotSame( get_permalink( $post_id ), $values[0]['value'] ?? '' );
		if ( null === $url ) {
			$this->assertArrayNotHasKey( 'url', $values[0]['properties'] );
			return;
		}
		$this->assertSame( [ $url ], $values[0]['properties']['url'] ?? null );
		$this->assertSame( $url, $values[0]['value'] ?? null );
	}

	/**
	 * Card-backed kinds that render a star rating, and the minimal
	 * attributes each render.php needs to render at all.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function rating_cards(): array {
		return [
			'listen' => [ 'listen', [ 'trackTitle' => 'Test track', 'listenUrl' => 'https://example.com/listen' ] ],
			'watch'  => [ 'watch', [ 'mediaTitle' => 'Test film', 'watchUrl' => 'https://example.com/watch' ] ],
			'read'   => [ 'read', [ 'bookTitle' => 'Test book', 'bookUrl' => 'https://example.com/read' ] ],
			'play'   => [ 'play', [ 'title' => 'Test game' ] ],
			'eat'    => [ 'eat', [ 'name' => 'Test dish' ] ],
			'drink'  => [ 'drink', [ 'name' => 'Test drink' ] ],
		];
	}

	/**
	 * `.pk-stars` holds only `aria-hidden` SVG stars, so mf2 parsers
	 * previously read `rating` as an empty string with no value. Each of
	 * the six cards must expose the numeric rating via a sibling, empty
	 * `<data class="p-rating" value="N" hidden>` — the same pattern
	 * `class-microformats.php`'s review case already uses — kept off the
	 * visible `.pk-stars` element entirely so the digit never lands in
	 * plain-text extraction (feed readers, excerpts, ATmosphere/
	 * Standard.site document text) that does not respect `hidden`.
	 *
	 * @dataProvider rating_cards
	 *
	 * @param string               $kind            Post kind and card slug.
	 * @param array<string, mixed> $base_attributes Minimal attributes the card needs to render.
	 */
	public function test_kind_card_rating_parses_as_numeric_value( string $kind, array $base_attributes ): void {
		$with_rating    = $this->render_card_html( $kind, array_merge( $base_attributes, [ 'rating' => 4 ] ) );
		$without_rating = $this->render_card_html( $kind, $base_attributes );

		$entry     = $this->top_level_h_entry( \Mf2\parse( $with_rating ) );
		$card_item = $this->find_item_with_property( [ $entry ], 'rating' );

		$this->assertNotNull( $card_item, "No parsed item carried a rating property for the {$kind} card." );
		$this->assertSame( [ '4' ], $card_item['properties']['rating'] );

		// The rating digit must not leak into plain-text extraction: the
		// stripped text of the card is identical whether or not a rating is
		// present. wp_strip_all_tags() (and any similar strip_tags-based
		// extraction a feed reader or ATmosphere/Standard.site document
		// might do) does not consult the `hidden` attribute, so if the
		// digit ever sat in a visible element's text content it would show
		// up here even though it is invisible in a browser.
		$this->assertSame(
			$this->strip_and_normalize( $without_rating ),
			$this->strip_and_normalize( $with_rating ),
			"Rendering the {$kind} card with a rating changed its plain-text content — the rating value leaked into extractable text."
		);

		$this->assertMatchesRegularExpression(
			'/<data class="p-rating" value="4" hidden><\/data>/',
			$with_rating,
			"Expected an empty <data class=\"p-rating\"> element (no text content) for the {$kind} card."
		);

		$this->assertMatchesRegularExpression(
			'/<div class="pk-stars" role="img"[^>]*>\s*(?:<svg[^>]*>.*?<\/svg>\s*)+<\/div>/s',
			$with_rating,
			"Expected .pk-stars to contain only SVG star children (no text node) for the {$kind} card."
		);
	}

	/**
	 * Control: a card with no rating attribute renders no `.pk-stars` or
	 * `.p-rating` markup at all (every render.php guards the whole block
	 * behind `$pkiw_rating > 0`), so no item in the parsed tree should
	 * carry a rating property.
	 */
	public function test_kind_card_without_rating_emits_no_rating_property(): void {
		$html = $this->render_card_html(
			'listen',
			[
				'trackTitle' => 'Test track',
				'listenUrl'  => 'https://example.com/listen',
			]
		);

		$entry     = $this->top_level_h_entry( \Mf2\parse( $html ) );
		$card_item = $this->find_item_with_property( [ $entry ], 'rating' );

		$this->assertNull( $card_item, 'A card with no rating attribute must not emit a rating property.' );
		$this->assertStringNotContainsString( 'p-rating', $html );
		$this->assertStringNotContainsString( 'pk-stars', $html );
	}

	/**
	 * Issue 233: a kind menu line inside a real Query Loop is one h-entry
	 * per post (the Post Template <li> root from post_class), carrying the
	 * permalink as u-url and the same p-ate/p-drank h-food the card emits.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function menu_entry_kinds(): array {
		return [
			'eat'   => [ 'eat', '{"name":"Cacio e pepe","cuisine":"Italian","rating":4}', 'ate' ],
			'drink' => [ 'drink', '{"name":"Imperial stout","drinkType":"beer","rating":5}', 'drank' ],
		];
	}

	/**
	 * @dataProvider menu_entry_kinds
	 */
	public function test_menu_entry_parses_as_one_h_entry_with_kind_property( string $kind, string $attrs, string $property ): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:post-kinds-indieweb/' . $kind . '-card ' . $attrs . ' /-->',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, $kind, 'kind' ) );
		$term_id = (int) get_term_by( 'slug', $kind, 'kind' )->term_id;

		$html = do_blocks(
			'<!-- wp:query {"queryId":9,"query":{"perPage":5,"postType":"post","inherit":false,"taxQuery":{"kind":[' . $term_id . ']}}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template {"className":"is-style-pkiw-menu"} -->'
			. '<!-- wp:post-kinds-indieweb/menu-entry /-->'
			. '<!-- /wp:post-template --></div><!-- /wp:query -->'
		);

		$entries = array_values(
			array_filter(
				\Mf2\parse( $html )['items'] ?? [],
				static fn( $item ) => in_array( 'h-entry', $item['type'] ?? [], true )
			)
		);

		$this->assertCount( 1, $entries, 'Exactly one h-entry root per menu line.' );
		$this->assertContains( get_permalink( $post_id ), $entries[0]['properties']['url'] ?? [] );
		$this->assertArrayHasKey( $property, $entries[0]['properties'] );
		// Same shape the card emits: the u-* string plus the nested h-food.
		$foods = array_values(
			array_filter(
				$entries[0]['properties'][ $property ],
				static fn( $value ) => is_array( $value ) && in_array( 'h-food', $value['type'] ?? [], true )
			)
		);
		$this->assertCount( 1, $foods );
		$this->assertSame( [ json_decode( $attrs, true )['name'] ], $foods[0]['properties']['name'] );
	}

	/**
	 * Weather has no card block. A weather post renders through the generic
	 * stream card, which roots its own h-entry; with a Simple Location
	 * observation stored, that entry carries exactly one `weather` value.
	 * The post's authored text reaches the card only as a tag-stripped
	 * excerpt, so it can't add a second one.
	 */
	public function test_weather_stream_card_parses_one_weather_property(): void {
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		update_option( 'sloc_measurements', 'metric' );

		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => '<!-- wp:paragraph -->' . "\n"
					. '<p><span class="p-weather">Sunny and warm</span></p>' . "\n"
					. '<!-- /wp:paragraph -->',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'weather', 'kind' ) );
		add_post_meta( $post_id, 'weather_temperature', 26.8 );
		add_post_meta( $post_id, 'weather_code', 800 );
		add_post_meta( $post_id, 'geo_public', '1' );

		$html  = \PKIW\render_generic_stream_card( get_post( $post_id ) );
		$entry = $this->top_level_h_entry( \Mf2\parse( $html ) );
		$this->assertSame( [ 'Clear Sky, 27 °C' ], $entry['properties']['weather'] ?? null );
	}

	/**
	 * A weather single keeps the authored weather as its only weather
	 * property: Simple Location's location line stays, without its own
	 * nested p-weather.
	 */
	public function test_weather_single_content_parses_one_weather_property(): void {
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/location-stub.php';
		update_option( 'sloc_measurements', 'metric' );

		$content = '<!-- wp:paragraph -->' . "\n"
			. '<p><span class="p-weather">Sunny and warm</span></p>' . "\n"
			. '<!-- /wp:paragraph -->';
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => $content,
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'weather', 'kind' ) );
		add_post_meta( $post_id, 'weather_temperature', 26.8 );
		add_post_meta( $post_id, 'weather_code', 800 );
		add_post_meta( $post_id, 'geo_public', '1' );
		add_post_meta( $post_id, 'geo_latitude', '40.7128' );
		add_post_meta( $post_id, 'geo_longitude', '-74.0060' );
		add_post_meta( $post_id, 'geo_address', 'New York, NY' );
		$this->hook_simple_location_content();
		$this->go_to( get_permalink( $post_id ) );

		// On a single, the_content wraps the post in Post Kinds' own h-entry
		// (Microformats::wrap_singular_content() at priority 100), after
		// Simple Location appends its line at 12. Parse that as served.
		$html = apply_filters( 'the_content', $content );
		$this->assertStringContainsString( 'pkiw-singular-entry', $html );
		$entry = $this->top_level_h_entry( \Mf2\parse( $html ) );

		$this->assertSame( [ 'Sunny and warm' ], $entry['properties']['weather'] ?? null );
		$this->assertArrayHasKey( 'location', $entry['properties'], 'Simple Location\'s p-location line should stay.' );
		$this->assertNull( $this->find_nested_item_with_property( $entry, 'weather' ), 'The nested Simple Location h-adr must not carry a weather property.' );
		$this->assertStringNotContainsString( 'sloc-weather', $html );
	}

	/**
	 * A weather post made in the editor has no authored weather, but its
	 * Stream card prints Post Kinds' weather line, so the card's excerpt
	 * doesn't repeat Simple Location's weather.
	 */
	public function test_weather_stream_card_summary_has_no_simple_location_weather(): void {
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/location-stub.php';
		update_option( 'sloc_measurements', 'metric' );

		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Out for a walk.</p><!-- /wp:paragraph -->',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'weather', 'kind' ) );
		add_post_meta( $post_id, 'weather_temperature', 26.8 );
		add_post_meta( $post_id, 'weather_code', 800 );
		add_post_meta( $post_id, 'geo_public', '1' );
		add_post_meta( $post_id, 'geo_latitude', '40.7128' );
		add_post_meta( $post_id, 'geo_longitude', '-74.0060' );
		add_post_meta( $post_id, 'geo_address', 'New York, NY' );
		$this->hook_simple_location_content();
		$this->go_to( get_permalink( $post_id ) );

		// Through the block, as a Query Loop renders it: the excerpt's
		// the_content pass runs inside the Stream card's render callback.
		$html = render_block(
			[
				'blockName'    => 'post-kinds-indieweb/stream-card',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		$this->assertStringContainsString( '<p class="pk-weather p-weather">Clear Sky, 27 °C</p>', $html );
		$this->assertSame( 1, substr_count( $html, 'Clear Sky' ) );

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$summary = ( new DOMXPath( $dom ) )->query( '//p[contains(concat(" ", @class, " "), " pk-excerpt ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $summary );
		$this->assertStringNotContainsString( 'Clear Sky', $summary->textContent );
		$this->assertStringNotContainsString( '°', $summary->textContent );
	}

	/**
	 * Add Simple Location's content filter when the fixture is in use.
	 */
	private function hook_simple_location_content(): void {
		if ( false === has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) ) {
			add_filter( 'the_content', [ 'Geo_Data', 'location_content' ], 12 );
		}
	}

	/**
	 * Render a card block for the given kind/attributes through the full
	 * dynamic-render pipeline, wrapped in a synthetic `h-entry` the way the
	 * theme wraps a published post's content.
	 *
	 * @param string               $kind       Post kind and card slug.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_card_html( string $kind, array $attributes ): string {
		$block   = sprintf(
			'<!-- wp:post-kinds-indieweb/%s-card %s /-->',
			$kind,
			wp_json_encode( $attributes )
		);
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $block,
			]
		);

		$term_result = wp_set_object_terms( $post_id, $kind, 'kind' );
		$this->assertNotWPError( $term_result );

		$this->go_to( get_permalink( $post_id ) );
		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );

		return '<div class="h-entry">' . $html . '</div>';
	}

	/**
	 * wp_strip_all_tags(), with whitespace collapsed, so plain-text output
	 * can be compared between two renders regardless of incidental
	 * whitespace shifts from the markup difference itself.
	 *
	 * @param string $html Rendered HTML.
	 */
	private function strip_and_normalize( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	/**
	 * Attributes each render.php reads to emit its target URL.
	 *
	 * @param string $kind       Card kind.
	 * @param string $target_url Target URL.
	 * @return array<string, string>
	 */
	private function card_attributes( string $kind, string $target_url ): array {
		switch ( $kind ) {
			case 'listen':
				return [
					'trackTitle' => 'Test track',
					'listenUrl'  => $target_url,
				];
			case 'watch':
				return [
					'mediaTitle' => 'Test film',
					'watchUrl'   => $target_url,
				];
			case 'read':
				return [
					'bookTitle' => 'Test book',
					'bookUrl'   => $target_url,
				];
			case 'play':
				return [
					'title'   => 'Test game',
					'gameUrl' => $target_url,
				];
			case 'rsvp':
				return [
					'eventName'  => 'Test event',
					'eventUrl'   => $target_url,
					'rsvpStatus' => 'yes',
				];
			default:
				return [
					'title' => 'Test target',
					'url'   => $target_url,
				];
		}
	}

	/**
	 * Find the top-level h-entry item in parsed microformats2 data.
	 *
	 * @param array<string, mixed> $parsed Parsed microformats2 document.
	 * @return array<string, mixed>
	 */
	private function top_level_h_entry( array $parsed ): array {
		foreach ( $parsed['items'] ?? [] as $item ) {
			if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
				return $item;
			}
		}

		$this->fail( 'No top-level h-entry item was parsed.' );
	}

	/**
	 * Recursively search parsed microformats2 items — including nested
	 * items embedded as property values (e.g. a `u-listen-of h-cite` card)
	 * and as unclaimed children (e.g. a bare `h-cite` card with no matching
	 * `u-*-of` property on the entry) — for the first item exposing
	 * $property.
	 *
	 * @param array<int, array<string, mixed>> $items    Parsed mf2 items to search.
	 * @param string                            $property Property name to find.
	 * @return array<string, mixed>|null The first matching item, or null.
	 */
	private function find_item_with_property( array $items, string $property ): ?array {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( isset( $item['properties'][ $property ] ) ) {
				return $item;
			}

			foreach ( $item['properties'] ?? [] as $values ) {
				if ( ! is_array( $values ) ) {
					continue;
				}

				$nested_items = array_values(
					array_filter(
						$values,
						static fn( $value ) => is_array( $value ) && isset( $value['type'] )
					)
				);

				if ( $nested_items ) {
					$found = $this->find_item_with_property( $nested_items, $property );
					if ( null !== $found ) {
						return $found;
					}
				}
			}

			if ( ! empty( $item['children'] ) ) {
				$found = $this->find_item_with_property( $item['children'], $property );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Search only the nested items below one parsed microformats2 item.
	 *
	 * @param array<string, mixed> $item     Parsed parent item.
	 * @param string               $property Property name to find.
	 * @return array<string, mixed>|null The first nested match, or null.
	 */
	private function find_nested_item_with_property( array $item, string $property ): ?array {
		$nested_items = [];
		foreach ( $item['properties'] ?? [] as $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				if ( is_array( $value ) && isset( $value['type'] ) ) {
					$nested_items[] = $value;
				}
			}
		}
		foreach ( $item['children'] ?? [] as $child ) {
			if ( is_array( $child ) ) {
				$nested_items[] = $child;
			}
		}

		return $this->find_item_with_property( $nested_items, $property );
	}

	/**
	 * Every URL a parsed property carries: plain values, and the url
	 * values of nested items.
	 *
	 * @param array<int, mixed> $values Parsed property values.
	 * @return array<int, string>
	 */
	private function property_urls( array $values ): array {
		$urls = [];
		foreach ( $values as $value ) {
			if ( is_string( $value ) ) {
				$urls[] = $value;
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value['properties']['url'] ?? [] as $url ) {
				$urls[] = is_array( $url ) ? (string) ( $url['value'] ?? '' ) : (string) $url;
			}
		}

		return $urls;
	}

	/**
	 * Assert that a parsed property contains the expected target URL.
	 *
	 * @param array<int, mixed> $values     Parsed property values.
	 * @param string            $target_url Expected target URL.
	 */
	private function assertPropertyContainsTarget( array $values, string $target_url ): void {
		foreach ( $values as $value ) {
			if ( $target_url === $value ) {
				$this->addToAssertionCount( 1 );
				return;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value['properties']['url'] ?? [] as $url ) {
				if ( $target_url === $url || ( is_array( $url ) && $target_url === ( $url['value'] ?? null ) ) ) {
					$this->addToAssertionCount( 1 );
					return;
				}
			}
		}

		$this->fail( 'Canonical property did not contain target URL ' . $target_url );
	}
}
