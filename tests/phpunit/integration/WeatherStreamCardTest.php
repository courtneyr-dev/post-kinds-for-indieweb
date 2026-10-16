<?php
/**
 * The weather Stream card (issue 246).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Simple_Location_Weather;
use PKIW\Taxonomy;

/**
 * A weather post draws its own Stream card through the kind filter: one
 * link, the reading once, no excerpt pass, no badge and no Read more.
 *
 * @group integration
 */
final class WeatherStreamCardTest extends WP_UnitTestCase {

	/**
	 * Author of the fixtures.
	 */
	private int $author_id = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/location-stub.php';
	}

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'sloc_measurements', 'imperial' );
		update_option( 'geo_public', '0' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'Stream Author' ] );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A post of a kind.
	 *
	 * @param string               $kind       Kind slug.
	 * @param array<string, mixed> $args       Post arguments.
	 * @param array<string, mixed> $weather    Weather rows, metric.
	 * @param string               $geo_public Simple Location visibility, '' for none.
	 */
	private function post( string $kind = 'weather', array $args = [], array $weather = [], string $geo_public = '1' ): WP_Post {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => '',
					'post_author'  => $this->author_id,
					'post_date'    => '2026-08-03 09:15:00',
					'post_content' => '<!-- wp:paragraph --><p>Out for a walk.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		foreach ( $weather as $prop => $value ) {
			add_post_meta( $id, 'weather_' . $prop, $value );
		}
		if ( '' !== $geo_public ) {
			add_post_meta( $id, 'geo_public', $geo_public );
		}
		add_post_meta( $id, 'geo_address', '100 Example Avenue, Sampletown' );

		$post = get_post( $id );
		$this->assertInstanceOf( WP_Post::class, $post );

		return $post;
	}

	/**
	 * Fixture weather: a coded, partly cloudy, 80 degree reading.
	 *
	 * @return array<string, mixed>
	 */
	private function partly_cloudy(): array {
		return [
			'summary'     => 'Partly Cloudy',
			'code'        => 801,
			'temperature' => 26.8,
		];
	}

	/**
	 * Render a post's Stream card the way the Stream card block does.
	 *
	 * @param WP_Post              $post       Post.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function card( WP_Post $post, array $attributes = [] ): string {
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$block          = new WP_Block(
			[
				'blockName' => 'post-kinds-indieweb/stream-card',
				'attrs'     => $attributes,
			],
			[ 'postId' => $post->ID ]
		);
		$block->context = [ 'postId' => $post->ID ];

		return \PKIW\render_stream_card_inner( $attributes, '', $block );
	}

	/**
	 * XPath over card HTML.
	 *
	 * @param string $html HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * XPath for a class token.
	 *
	 * @param string $class Class token.
	 * @param string $tag   Tag name or *.
	 */
	private function cls( string $class, string $tag = '*' ): string {
		return './/' . $tag . '[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]';
	}

	/**
	 * The tag and class skeleton of a card, without text or values.
	 *
	 * @param DOMNode $node Node.
	 */
	private function skeleton( DOMNode $node ): string {
		if ( ! $node instanceof DOMElement ) {
			return '';
		}
		$names = [];
		foreach ( $node->attributes as $attribute ) {
			$names[] = 'class' === $attribute->name ? 'class=' . $attribute->value : $attribute->name;
		}
		sort( $names );
		$inner = '';
		foreach ( $node->childNodes as $child ) {
			$inner .= $this->skeleton( $child );
		}

		return '<' . $node->tagName . ' ' . implode( ' ', $names ) . '>' . $inner . '</' . $node->tagName . '>';
	}

	public function test_the_card_is_drawn_through_the_kind_render_filter(): void {
		$this->assertSame( 10, has_filter( 'pkiw_stream_card_kind_render', [ Simple_Location_Weather::class, 'stream_card' ] ) );
	}

	public function test_a_note_gets_the_html_it_came_in_with(): void {
		$note = $this->post( 'note' );

		$this->assertNull( Simple_Location_Weather::stream_card( null, $note, 2, [] ) );
		$this->assertSame( '<p>earlier</p>', Simple_Location_Weather::stream_card( '<p>earlier</p>', $note, 3, [] ) );
	}

	public function test_a_weather_card_is_one_h_entry_article_with_one_rendered_link(): void {
		$post = $this->post( 'weather', [], $this->partly_cloudy() );
		$html = $this->card( $post );
		$xp   = $this->xpath( $html );

		$article = $xp->query( '//article' );
		$this->assertSame( 1, $article->length );
		$this->assertMatchesRegularExpression( '/^pk-card pk-card--stream k-weather h-entry$/', $article->item( 0 )->getAttribute( 'class' ) );
		$this->assertSame( 1, $xp->query( '//a[@href][not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertSame( 0, $xp->query( '//a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertSame( get_permalink( $post ), $xp->query( '//a[@href]' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 'u-url', $xp->query( '//a[@href]' )->item( 0 )->getAttribute( 'class' ) );
	}

	public function test_the_card_has_no_meta_image_excerpt_badge_or_read_more(): void {
		$post = $this->post( 'weather', [ 'post_excerpt' => 'A walk in the rain.' ], $this->partly_cloudy() );
		set_post_thumbnail( $post->ID, (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post->ID ) );
		$html = $this->card( $post );
		$xp   = $this->xpath( $html );

		foreach ( [ 'pk-meta', 'pk-excerpt', 'pk-badge', 'pk-media', 'p-summary', 'pk-link' ] as $class ) {
			$this->assertSame( 0, $xp->query( $this->cls( $class ) )->length, $class );
		}
		$this->assertSame( 0, $xp->query( '//img' )->length );
		$this->assertStringNotContainsString( 'Read more', $html );
		$this->assertStringNotContainsString( 'A walk in the rain', $html );
		$this->assertStringNotContainsString( 'Out for a walk', $html );
	}

	public function test_building_the_card_runs_no_content_or_excerpt_pass(): void {
		$post   = $this->post( 'weather', [], $this->partly_cloudy() );
		$passes = 0;
		$count  = static function ( $value ) use ( &$passes ) {
			++$passes;

			return $value;
		};
		add_filter( 'the_content', $count, 1 );
		add_filter( 'get_the_excerpt', $count, 1 );

		$this->card( $post );

		$this->assertSame( 0, $passes );
	}

	public function test_an_untitled_post_with_an_observation_puts_the_reading_inside_the_heading_link(): void {
		$post = $this->post( 'weather', [], $this->partly_cloudy() );
		$xp   = $this->xpath( $this->card( $post ) );

		$link = $xp->query( '//h2//a[@href]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $link );
		$weather = $xp->query( $this->cls( 'p-weather' ), $link );
		$this->assertSame( 1, $weather->length );
		$this->assertSame( 'span', $weather->item( 0 )->nodeName );
		$this->assertSame( 'Partly Cloudy, 80 °F', $weather->item( 0 )->textContent );
		$this->assertSame( 1, $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " p-weather ")]' )->length, 'No second reading.' );
		$this->assertSame( 0, $xp->query( $this->cls( 'p-name' ) )->length, 'A synthetic name is not a p-name.' );
		$this->assertSame( 'pk-title', $xp->query( '//h2' )->item( 0 )->getAttribute( 'class' ) );
	}

	public function test_the_link_name_carries_a_hidden_date_and_time(): void {
		$xp = $this->xpath( $this->card( $this->post( 'weather', [], $this->partly_cloudy() ) ) );

		$sr = $xp->query( '//a[@href]//span[contains(concat(" ", normalize-space(@class), " "), " pk-sr-only ")]' );
		$this->assertSame( 1, $sr->length );
		$this->assertSame( ', August 3, 2026 at 9:15 am', $sr->item( 0 )->textContent );
	}

	public function test_a_titled_post_links_its_title_and_prints_one_reading_paragraph(): void {
		$post = $this->post( 'weather', [ 'post_title' => 'Cold snap' ], $this->partly_cloudy() );
		$xp   = $this->xpath( $this->card( $post ) );

		$name = $xp->query( '//a[@href]//span[contains(concat(" ", normalize-space(@class), " "), " p-name ")]' );
		$this->assertSame( 1, $name->length );
		$this->assertSame( 'Cold snap', $name->item( 0 )->textContent );
		$paragraph = $xp->query( '//p[contains(concat(" ", normalize-space(@class), " "), " pk-weather ")]' );
		$this->assertSame( 1, $paragraph->length );
		$this->assertStringContainsString( 'p-weather', $paragraph->item( 0 )->getAttribute( 'class' ) );
		$this->assertSame( 'Partly Cloudy, 80 °F', $paragraph->item( 0 )->textContent );
		$this->assertSame( 1, $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " p-weather ")]' )->length );
		$this->assertSame( 0, $xp->query( '//a//*[contains(concat(" ", normalize-space(@class), " "), " p-weather ")]' )->length );
	}

	public function test_a_hidden_observation_draws_the_same_skeleton_as_no_observation(): void {
		$hidden = $this->post( 'weather', [], $this->partly_cloudy(), '0' );
		$bare   = $this->post( 'weather', [], [], '' );

		$this->assertSame(
			$this->skeleton( $this->xpath( $this->card( $bare ) )->query( '//article' )->item( 0 ) ),
			$this->skeleton( $this->xpath( $this->card( $hidden ) )->query( '//article' )->item( 0 ) )
		);
		$html = $this->card( $hidden );
		$this->assertStringNotContainsString( 'Partly', $html );
		$this->assertStringNotContainsString( '80', $html );
		$this->assertStringNotContainsString( 'data-pkiw-weather-icon', $html );
	}

	public function test_an_authored_reading_stands_in_when_the_observation_is_hidden(): void {
		$post = $this->post(
			'weather',
			[ 'post_content' => '<!-- wp:paragraph --><p><span class="p-weather">Sunny and warm</span></p><!-- /wp:paragraph -->' ],
			$this->partly_cloudy(),
			'0'
		);
		$xp   = $this->xpath( $this->card( $post ) );

		$this->assertSame( 'Sunny and warm', $xp->query( '//a//' . 'span[contains(concat(" ", normalize-space(@class), " "), " p-weather ")]' )->item( 0 )->textContent );
		$this->assertSame( 1, $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " p-weather ")]' )->length );
	}

	public function test_the_mark_needs_a_stored_condition_code(): void {
		$coded = $this->xpath( $this->card( $this->post( 'weather', [], $this->partly_cloudy() ) ) );
		$mark  = $coded->query( $this->cls( 'pk-weather-mark', 'span' ) );

		$this->assertSame( 1, $mark->length );
		$this->assertSame( 'wi-day-cloudy', $mark->item( 0 )->getAttribute( 'data-pkiw-weather-icon' ) );
		$this->assertSame( 'true', $mark->item( 0 )->getAttribute( 'aria-hidden' ) );
		$this->assertSame( '', trim( $mark->item( 0 )->textContent ) );

		$icon_only = $this->post(
			'weather',
			[],
			[
				'icon'        => 'wi-day-haze',
				'temperature' => 20,
			]
		);
		$this->assertSame( 0, $this->xpath( $this->card( $icon_only ) )->query( $this->cls( 'pk-weather-mark' ) )->length );
	}

	public function test_weather_appears_once_in_the_card_text(): void {
		$cases = [
			'reading'     => $this->post( 'weather', [], $this->partly_cloudy() ),
			'titled'      => $this->post( 'weather', [ 'post_title' => 'Cold snap' ], $this->partly_cloudy() ),
			'nothing yet' => $this->post( 'weather', [], [], '' ),
		];

		foreach ( $cases as $label => $post ) {
			$text = (string) $this->xpath( $this->card( $post ) )->query( '//article' )->item( 0 )->textContent;
			$this->assertSame( 1, substr_count( $text, 'Weather' ), $label . ': ' . $text );
		}
	}

	public function test_the_kind_label_prints_unless_the_heading_already_says_weather(): void {
		$with    = $this->xpath( $this->card( $this->post( 'weather', [], $this->partly_cloudy() ) ) );
		$without = $this->xpath( $this->card( $this->post( 'weather', [], [], '' ) ) );

		$this->assertSame( 'Weather', trim( $with->query( $this->cls( 'pk-kindlabel', 'span' ) )->item( 0 )->textContent ) );
		$this->assertSame( 0, $without->query( $this->cls( 'pk-kindlabel' ) )->length );
		$this->assertStringStartsWith( 'Weather', trim( $without->query( '//h2//a' )->item( 0 )->textContent ) );
	}

	public function test_the_date_line_prints_the_calendar_day_with_a_machine_timestamp(): void {
		$post = $this->post( 'weather', [], $this->partly_cloudy() );
		$xp   = $this->xpath( $this->card( $post ) );

		$time = $xp->query( '//p[contains(concat(" ", normalize-space(@class), " "), " pk-stream-date ")]/time[contains(concat(" ", normalize-space(@class), " "), " dt-published ")]' );
		$this->assertSame( 1, $time->length );
		$this->assertSame( 'August 3, 2026', $time->item( 0 )->textContent );
		$this->assertSame( get_post_time( 'c', true, $post ), $time->item( 0 )->getAttribute( 'datetime' ) );
	}

	public function test_the_heading_level_follows_the_block_attribute(): void {
		$xp = $this->xpath( $this->card( $this->post( 'weather', [], $this->partly_cloudy() ), [ 'headingLevel' => 3 ] ) );

		$this->assertSame( 1, $xp->query( '//h3//a[@href]' )->length );
		$this->assertSame( 0, $xp->query( '//h2' )->length );
	}

	public function test_a_featured_image_prints_no_img(): void {
		$post = $this->post( 'weather', [], $this->partly_cloudy() );
		set_post_thumbnail( $post->ID, (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post->ID ) );

		$this->assertTrue( has_post_thumbnail( $post->ID ) );
		$this->assertSame( 0, $this->xpath( $this->card( $post ) )->query( '//img' )->length );
	}

	public function test_a_protected_weather_post_gets_the_protected_card_and_never_reaches_the_filter(): void {
		$post   = $this->post( 'weather', [ 'post_password' => 'secret' ], $this->partly_cloudy() );
		$called = false;
		add_filter(
			'pkiw_stream_card_kind_render',
			static function ( $html ) use ( &$called ) {
				$called = true;

				return $html;
			},
			5
		);

		$html = $this->card( $post );

		$this->assertFalse( $called );
		$this->assertStringContainsString( 'pk-card--protected', $html );
		$this->assertStringNotContainsString( 'Partly', $html );
		$this->assertStringNotContainsString( 'pk-weather', $html );
	}

	public function test_through_the_block_filter_the_card_parses_as_one_entry_with_one_weather_value(): void {
		$post = $this->post( 'weather', [], $this->partly_cloudy() );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$this->go_to( get_permalink( $post ) );

		$html = render_block(
			[
				'blockName'    => 'post-kinds-indieweb/stream-card',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

		$items = \Mf2\parse( $html, home_url( '/' ) )['items'] ?? [];
		$this->assertCount( 1, $items );
		$entry = $items[0];
		$this->assertContains( 'h-entry', $entry['type'] );
		$this->assertSame( [ 'Partly Cloudy, 80 °F' ], $entry['properties']['weather'] ?? null );
		$this->assertNotEmpty( $entry['properties']['url'] ?? [] );
		$this->assertNotEmpty( $entry['properties']['published'] ?? [] );
		$this->assertSame( 'Stream Author', $entry['properties']['author'][0]['properties']['name'][0] ?? null );
		$this->assertArrayNotHasKey( 'name', $entry['properties'], 'A synthetic name is not a p-name.' );
	}

	public function test_no_street_or_coordinates_reach_the_card(): void {
		$post = $this->post( 'weather', [], $this->partly_cloudy() );
		add_post_meta( $post->ID, 'geo_latitude', '10.1' );
		add_post_meta( $post->ID, 'geo_longitude', '20.2' );

		$html = $this->card( $post );

		foreach ( [ '100 Example Avenue', 'Sampletown', '10.1', '20.2' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
	}
}
