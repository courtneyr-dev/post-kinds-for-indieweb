<?php
/**
 * Matrix-driven render coverage for every dynamic block.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * For every dynamic block: render with every attribute populated and
 * assert each sample value reaches the front-end markup; render empty
 * and assert nothing leaks.
 *
 * Consumes tests/phpunit/fixtures/field-matrix.json (Task 1).
 *
 * @group integration
 */
final class BlockFieldRenderTest extends WP_UnitTestCase {

	/**
	 * URLs of live HTTP requests attempted during the test.
	 *
	 * @var string[]
	 */
	private array $http_requests = [];

	/**
	 * Block all live HTTP and record every attempt.
	 *
	 * Rendering a block must never depend on the network: an uncached
	 * oEmbed lookup blocks page render on a remote fetch.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->http_requests = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) {
				$this->http_requests[] = $url;
				return new WP_Error( 'http_blocked', 'Live HTTP is blocked in render tests: ' . $url );
			},
			10,
			3
		);
	}

	/**
	 * Attrs whose sample never appears verbatim in output, with the reason.
	 *
	 * Format: 'blockName' => [ 'attr' => 'reason' ]. The '*' key applies to
	 * every block. Later tasks must extend this map rather than skip blocks.
	 * An entry without a real reason is a review-blocking smell.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function assertion_exceptions(): array {
		return [
			'*'                                     => [
				'layout' => 'display-mode enum; the pk-card redesign renders a fixed structure, so cards no longer emit a layout-* wrapper class',
			],
			'post-kinds-indieweb/mood-card'         => [
				'intensity' => 'dropped from the minimal mood card (emoji + note only) in the pk-card redesign',
			],
			'post-kinds-indieweb/read-card'         => [
				'readStatus' => 'free-string status mapped to a human label (Reading/Finished/…); an unknown value like the fixture sample maps to an empty label and is never echoed raw',
			],
			'post-kinds-indieweb/wish-card'         => [
				'wishType' => 'wishlist subtype metadata; not surfaced as visible text in the card',
				'priority' => 'ordering hint metadata; not surfaced as visible text in the card',
			],
			'post-kinds-indieweb/checkin-card'      => [
				'venueType'       => 'enum mapped to icon + translated label; unknown values fall back to the Place label, raw slug never echoed',
				'locationPrivacy' => 'block.json enum (public/approximate/private); core drops the invalid sample pre-render and the gate value itself is never echoed',
				'address'         => 'privacy-aware by design: street address renders only when locationPrivacy=public, and enum validation forces the sample back to approximate',
				'postalCode'      => 'privacy-aware by design: renders only when locationPrivacy=public, same gate as address',
			],
			'post-kinds-indieweb/checkins-feed'     => [
				'count'   => 'posts_per_page query arg, never echoed',
				'venueId' => 'venue term query filter, never echoed',
				'columns' => 'echoed only as a columns-N wrapper class when layout=grid; fixture layout sample is list',
			],
			'post-kinds-indieweb/listen-card'       => [
				'musicbrainzId' => 'embedded in a canonical musicbrainz.org URL via esc_url(), which percent-encodes the space-containing sample',
			],
			'post-kinds-indieweb/play-card'         => [
				'status' => 'block.json enum (playing/completed/...); core drops the invalid sample pre-render and falls back to the default',
			],
			'post-kinds-indieweb/rsvp-card'         => [
				'eventStart' => 'rendered as a <time> only after strtotime() parses it; the non-date sample fails the parse gate by design',
				'eventEnd'   => 'rendered as a dt-end <data> only after strtotime() parses it, same gate as eventStart',
			],
			'post-kinds-indieweb/venue-detail'      => [
				'venueId'      => 'venue term lookup; term 4 does not exist in the test DB so the block renders nothing (all its content comes from term meta, not attributes)',
				'checkinCount' => 'posts_per_page limit for the checkins query, never echoed',
			],
			'post-kinds-indieweb/watch-card'        => [
				'mediaType'    => 'display-mode string driving the movie/tv/episode layout; the pk-card redesign shows a single Watch kind label and no longer echoes it as a badge modifier class',
				'captionsUrl'  => 'WebVTT file passed to the card video-embed filter; with no accessible-player filter active in tests the oEmbed fallback runs and the caption file is not echoed',
				'showTitle'    => 'renders only when mediaType=episode; fixture mediaType sample is not an episode',
				'episodeTitle' => 'renders only when mediaType=episode as part of the SxE episode string, same gate as showTitle',
				'tmdbId'       => 'embedded in a canonical themoviedb.org URL via esc_url(), which percent-encodes the space-containing sample',
				'imdbId'       => 'embedded in a canonical imdb.com URL via esc_url(), which percent-encodes the space-containing sample',
			],
		];
	}

	/**
	 * Data provider of every dynamic block and its attribute definitions.
	 *
	 * @return array<string, array{0: string, 1: array<string, array<string, mixed>>}>
	 */
	public function dynamic_blocks(): array {
		$cases  = [];
		$matrix = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/field-matrix.json' ),
			true
		);
		foreach ( $matrix as $name => $def ) {
			if ( 'dynamic' === $def['render'] ) {
				$cases[ $name ] = [ $name, $def['attributes'] ];
			}
		}
		return $cases;
	}

	/**
	 * Every populated attribute must surface in the rendered markup.
	 *
	 * @dataProvider dynamic_blocks
	 *
	 * @param string $block_name Block name, e.g. post-kinds-indieweb/read-card.
	 * @param array  $attributes Attribute definitions from the fixture.
	 */
	public function test_every_attribute_displays( string $block_name, array $attributes ): void {
		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( $block_name ),
			"$block_name: block is not registered in the test environment"
		);

		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );

		$attrs   = array_map( static fn( $a ) => $a['sample'], $attributes );
		$comment = sprintf(
			'<!-- wp:%s %s /-->',
			$block_name,
			wp_json_encode( $attrs )
		);

		$html       = do_blocks( $comment );
		$exceptions = array_merge(
			$this->assertion_exceptions()['*'],
			$this->assertion_exceptions()[ $block_name ] ?? []
		);

		$missing = [];
		foreach ( $attributes as $attr => $def ) {
			if ( isset( $exceptions[ $attr ] ) || 'boolean' === $def['type'] ) {
				continue;
			}
			if ( false === strpos( $html, (string) $def['sample'] ) ) {
				$missing[] = $attr;
			}
		}

		$this->assertSame(
			[],
			$missing,
			"$block_name: attribute sample(s) missing from rendered output"
		);

		$this->assertSame(
			[],
			$this->http_requests,
			"$block_name: rendering attempted live HTTP request(s)"
		);
	}

	/**
	 * Card titles must render as <h2>, not <h3>. A card sits directly under the
	 * page <h1> (single post) or the archive <h1> (stream), so an <h3> skips a
	 * heading level — WCAG 1.3.1 (Info and Relationships). Regression guard for
	 * the 2026-07 heading-order fix. Blocks that render no pk-title heading
	 * (e.g. mood-card) simply assert no stray <h3> title is present.
	 *
	 * @dataProvider dynamic_blocks
	 *
	 * @param string $block_name Block name.
	 * @param array  $attributes Attribute definitions from the fixture.
	 */
	public function test_card_title_uses_h2_not_h3( string $block_name, array $attributes ): void {
		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );

		$attrs   = array_map( static fn( $a ) => $a['sample'], $attributes );
		$comment = sprintf( '<!-- wp:%s %s /-->', $block_name, wp_json_encode( $attrs ) );
		$html    = do_blocks( $comment );

		$this->assertStringNotContainsString(
			'<h3 class="pk-title',
			$html,
			"$block_name: card title must not be <h3> — it skips a level under the page <h1> (WCAG 1.3.1)"
		);

		if ( false !== strpos( $html, 'class="pk-title' ) ) {
			$this->assertStringContainsString(
				'<h2 class="pk-title',
				$html,
				"$block_name: card title should render as <h2>"
			);
		}
	}

	/**
	 * Rendering with no attributes must not leak placeholders or PHP noise.
	 *
	 * @dataProvider dynamic_blocks
	 *
	 * @param string $block_name Block name, e.g. post-kinds-indieweb/read-card.
	 */
	public function test_empty_attributes_leak_nothing( string $block_name ): void {
		$html = do_blocks( "<!-- wp:$block_name /-->" );
		$this->assertStringNotContainsString( 'Sample', $html );
		$this->assertStringNotContainsString( 'undefined', $html );
		$this->assertStringNotContainsString( 'Array', $html );
		$this->assertSame(
			[],
			$this->http_requests,
			"$block_name: rendering attempted live HTTP request(s)"
		);
	}
}
