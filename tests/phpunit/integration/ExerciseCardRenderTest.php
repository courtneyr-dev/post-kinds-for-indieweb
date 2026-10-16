<?php
/**
 * The exercise card's render.php: three shapes and the double health gate (issue 245).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * render.php prints a Stream skeleton inside a Stream card, the activity alone
 * on an HTML listing, and the full record everywhere else (singles, every
 * feed, REST content, the Markdown copy, a push). Distance, duration, splits
 * and the personal best print only while the card's own toggle AND the post's
 * details row both say so.
 *
 * block.json arrives with the editor bundle, after this lane, so setUp()
 * registers the block itself the way the bundle will (same attributes, the
 * postId context, render.php as the callback) when nothing has.
 *
 * @group integration
 */
final class ExerciseCardRenderTest extends WP_UnitTestCase {

	private const BLOCK      = 'post-kinds-indieweb/exercise-card';
	private const PUBLIC_KEY = '_pkiw_exercise_details_public';

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	private bool $registered_here = false;

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		$this->original_stylesheet = get_stylesheet();
		$this->original_timezone   = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			$this->registered_here = true;
			register_block_type(
				self::BLOCK,
				[
					'api_version'     => 3,
					'attributes'      => [
						'activity'            => [ 'type' => 'string' ],
						'distance'            => [ 'type' => 'number' ],
						'distanceUnit'        => [
							'type'    => 'string',
							'default' => 'km',
						],
						'duration'            => [ 'type' => 'string' ],
						'splits'              => [ 'type' => 'array' ],
						'personalBest'        => [
							'type'    => 'boolean',
							'default' => false,
						],
						'pbCategory'          => [ 'type' => 'string' ],
						'showDetailsPublicly' => [
							'type'    => 'boolean',
							'default' => false,
						],
						'origin'              => [
							'type'    => 'string',
							'default' => '',
						],
					],
					'uses_context'    => [ 'postId' ],
					'render_callback' => static function ( array $attributes, string $content, WP_Block $block ): string {
						ob_start();
						include PKIW_PATH . 'src/blocks/exercise-card/render.php';
						return (string) ob_get_clean();
					},
				]
			);
		}
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		if ( $this->registered_here && WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			unregister_block_type( self::BLOCK );
		}
		update_option( 'timezone_string', $this->original_timezone );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * A card comment.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/exercise-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * A published exercise post.
	 *
	 * @param string               $title Title.
	 * @param string               $date  Local date.
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Post args.
	 */
	private function exercise( string $title, string $date, array $attrs, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => $date,
					'post_content' => $this->card( $attrs ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'exercise', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * Attributes of the plan's A01 (a 5K personal best that shares its details).
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function a01( array $over = [] ): array {
		return array_merge(
			[
				'activity'            => 'Run',
				'distance'            => 5.0,
				'duration'            => 'PT29M12S',
				'splits'              => [
					[
						'i' => 1,
						't' => 'PT5M58S',
					],
					[
						'i' => 2,
						't' => 'PT5M51S',
					],
					[
						'i' => 3,
						't' => 'PT5M49S',
					],
					[
						'i' => 4,
						't' => 'PT5M47S',
					],
					[
						'i' => 5,
						't' => 'PT5M47S',
					],
				],
				'personalBest'        => true,
				'pbCategory'          => '5k',
				'showDetailsPublicly' => true,
			],
			$over
		);
	}

	/**
	 * The card as a single prints it: the post is the queried and global post.
	 *
	 * @param int $id Post ID.
	 */
	private function single( int $id ): string {
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );

		return (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * The card as it prints at a URL other than the post's own.
	 *
	 * @param int    $id  Post ID.
	 * @param string $url URL.
	 */
	private function printed_at( int $id, string $url ): string {
		$this->go_to( $url );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );

		return (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * A main query with no flag set, as a REST request has (REST_REQUEST keeps is_home off in production).
	 */
	private function neutral_query(): void {
		$GLOBALS['wp_query'] = new WP_Query();
		unset( $GLOBALS['post'] );
	}

	private function stream( int $id ): string {
		// The Query Loop sets the loop post up before it renders a card.
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
		$block = new WP_Block(
			[
				'blockName' => 'post-kinds-indieweb/stream-card',
				'attrs'     => [],
			],
			[ 'postId' => $id ]
		);

		return \PKIW\render_stream_card( [], '', $block );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	private function text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	// The full record.

	public function test_the_full_record_prints_the_card_in_order(): void {
		$id    = $this->exercise( 'Lakeside Loop', '2026-08-23 07:10:00', $this->a01() );
		$html  = $this->single( $id );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," p-exercise ")]' )->length );
		$this->assertSame( 'Run', trim( $xpath->query( '//*[contains(@class,"p-exercise")]' )->item( 0 )->textContent ) );
		$this->assertSame( '2026-08-23', $xpath->query( '//time[contains(@class,"pkiw-exercise__date")]' )->item( 0 )->getAttribute( 'datetime' ) );
		$this->assertSame( 'August 23, 2026', trim( $xpath->query( '//time[contains(@class,"pkiw-exercise__date")]' )->item( 0 )->textContent ) );
		$this->assertSame( '5.00 km', trim( $xpath->query( '//*[contains(@class,"pkiw-exercise__distance")]' )->item( 0 )->textContent ) );
		$duration = $xpath->query( '//*[contains(@class,"pkiw-exercise__duration")]/time' )->item( 0 );
		$this->assertSame( 'PT29M12S', $duration->getAttribute( 'datetime' ) );
		$this->assertSame( '29:12', trim( $duration->textContent ) );
		$this->assertSame( 'Personal best: 5K, 29:12', trim( $xpath->query( '//*[contains(@class,"pkiw-exercise__best")]' )->item( 0 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//*[contains(@class,"pk-title")]' )->length, 'The H1 is the title; the record has no title of its own.' );
	}

	public function test_splits_are_a_labelled_table_in_stored_order(): void {
		$html  = $this->single( $this->exercise( 'Lakeside Loop', '2026-08-23 07:10:00', $this->a01() ) );
		$xpath = $this->xpath( $html );

		$table = $xpath->query( '//table[contains(@class,"pkiw-exercise__splits")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $table );
		$heading = $xpath->query( '//h2[@id="' . $table->getAttribute( 'aria-labelledby' ) . '"]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $heading );
		$this->assertSame( 'Splits', trim( $heading->textContent ) );
		$this->assertSame( [ 'km', 'Time' ], array_map( static fn( DOMNode $th ): string => trim( $th->textContent ), iterator_to_array( $xpath->query( './/th[@scope="col"]', $table ) ) ) );
		$this->assertSame( 5, $xpath->query( './/tbody/tr', $table )->length );
		$rows = [];
		foreach ( $xpath->query( './/tbody/tr', $table ) as $row ) {
			$cells  = $xpath->query( './td', $row );
			$rows[] = trim( $cells->item( 0 )->textContent ) . ' ' . trim( $cells->item( 1 )->textContent );
		}
		$this->assertSame( [ '1 5:58', '2 5:51', '3 5:49', '4 5:47', '5 5:47' ], $rows );
	}

	public function test_miles_head_the_split_table_and_print_with_their_unit(): void {
		$id    = $this->exercise(
			'Hill Repeats',
			'2026-08-29 23:30:00',
			[
				'activity'            => 'Run',
				'distance'            => 3.1,
				'distanceUnit'        => 'mi',
				'duration'            => 'PT27M40S',
				'splits'              => [
					[
						'i' => 1,
						't' => 'PT8M58S',
					],
					[
						'i' => 2,
						't' => 'PT9M01S',
					],
					[
						'i' => 3,
						't' => 'PT9M41S',
					],
				],
				'showDetailsPublicly' => true,
			]
		);
		$xpath = $this->xpath( $this->single( $id ) );

		$this->assertSame( '3.10 mi', trim( $xpath->query( '//*[contains(@class,"pkiw-exercise__distance")]' )->item( 0 )->textContent ) );
		$this->assertSame( 'mi', trim( $xpath->query( '//th[@scope="col"]' )->item( 0 )->textContent ) );
		$this->assertSame( '2026-08-29', $xpath->query( '//time[contains(@class,"pkiw-exercise__date")]' )->item( 0 )->getAttribute( 'datetime' ), 'The local day.' );
	}

	public function test_a_private_workout_prints_exactly_what_a_workout_with_no_metrics_prints(): void {
		$private = $this->exercise( 'Evening Walk', '2026-08-24 19:40:00', [ 'activity' => 'Walk', 'distance' => 7.77, 'duration' => 'PT1H32M', 'personalBest' => true, 'pbCategory' => '10k' ] );
		$bare    = $this->exercise( 'Evening Walk', '2026-08-25 19:40:00', [ 'activity' => 'Walk' ] );

		// The card element alone: the single's own wrapper adds the post's permalink and publish time (G2).
		$card_of = function ( string $html, int $id, string $iso, string $display ): string {
			$xpath = $this->xpath( $html );
			$card  = $xpath->query( '//div[contains(concat(" ",normalize-space(@class)," ")," pkiw-exercise ")]' )->item( 0 );
			$this->assertInstanceOf( DOMElement::class, $card );

			return str_replace( [ $iso, $display, 'post-' . $id ], [ 'DAY', 'DAY', 'post-N' ], $card->ownerDocument->saveHTML( $card ) );
		};
		$hidden = $card_of( $this->single( $private ), $private, '2026-08-24', 'August 24, 2026' );
		$twin   = $card_of( $this->single( $bare ), $bare, '2026-08-25', 'August 25, 2026' );

		$this->assertSame( $twin, $hidden, 'Byte for byte.' );
		$this->assertStringContainsString( 'Walk', $hidden );
		foreach ( [ '7.77', '1:32:00', 'PT1H32M', 'Personal best', 'Splits', '10K' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $hidden, $needle );
		}
	}

	public function test_a_post_row_of_one_does_not_open_a_card_whose_own_toggle_is_off(): void {
		$id = $this->exercise( 'Stale row', '2026-08-24 19:40:00', $this->a01( [ 'showDetailsPublicly' => false ] ) );
		update_post_meta( $id, self::PUBLIC_KEY, '1' );
		$this->assertTrue( Meta_Fields::health_details_visible( $id ), 'The row alone says visible.' );

		$html = $this->single( $id );
		$this->assertStringContainsString( 'Run', $html );
		foreach ( [ '5.00 km', 'PT29M12S', '29:12', 'Personal best', 'Splits', '5:58' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
	}

	public function test_a_card_toggle_that_is_on_does_not_open_a_post_whose_row_is_gone(): void {
		$id = $this->exercise( 'Missing row', '2026-08-24 19:40:00', $this->a01() );
		$this->assertStringContainsString( '5.00 km', $this->single( $id ), 'Both agree: it prints.' );

		delete_post_meta( $id, self::PUBLIC_KEY );
		$html = $this->single( $id );
		foreach ( [ '5.00 km', '29:12', 'Personal best', 'Splits' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
		$this->assertStringContainsString( 'Run', $html );
	}

	public function test_an_administrator_gets_the_same_markup_as_a_visitor(): void {
		$id      = $this->exercise( 'Same for all', '2026-08-24 19:40:00', $this->a01( [ 'showDetailsPublicly' => false ] ) );
		$visitor = $this->single( $id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( $visitor, $this->single( $id ) );
	}

	public function test_a_card_in_a_post_of_another_kind_hides_every_opt_in_value(): void {
		$id = $this->exercise( 'Wrong kind', '2026-08-24 19:40:00', $this->a01() );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );

		$html = $this->single( $id );
		$this->assertStringNotContainsString( '5.00 km', $html );
		$this->assertStringNotContainsString( 'Splits', $html );
	}

	public function test_a_legacy_body_keeps_its_own_p_exercise_and_the_card_adds_none(): void {
		$content = '<!-- wp:paragraph --><p><span class="p-exercise">Ran 5 km in 29:12</span></p><!-- /wp:paragraph -->' . $this->card( [ 'activity' => 'Run' ] );
		$id      = $this->exercise( 'Ran Before Work', '2026-08-11 06:45:00', [ 'activity' => 'Run' ], [ 'post_content' => $content ] );
		$xpath   = $this->xpath( $this->single( $id ) );

		$this->assertSame( 1, $xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," p-exercise ")]' )->length, 'The paragraph prints as written; the card prints none.' );
		$this->assertSame( 1, $xpath->query( '//*[contains(@class,"pkiw-exercise__activity")]' )->length, 'The activity still prints.' );
		$this->assertSame( 'Ran 5 km in 29:12', trim( $xpath->query( '//*[contains(@class,"p-exercise")]' )->item( 0 )->textContent ) );
	}

	public function test_the_origin_the_micropub_builder_records_never_prints(): void {
		$id   = $this->exercise( 'Posted from a phone', '2026-08-24 19:40:00', $this->a01( [ 'origin' => 'micropub' ] ) );
		$html = $this->single( $id ) . $this->stream( $id );

		$this->assertStringNotContainsString( 'micropub', strtolower( $html ) );
		$this->assertStringNotContainsString( 'origin', strtolower( $html ) );
	}

	public function test_invalid_attribute_values_print_nothing(): void {
		$id    = $this->exercise(
			'Garbled',
			'2026-08-24 19:40:00',
			[
				'activity'            => 'Run',
				'distance'            => 'far',
				'duration'            => 'half an hour',
				'splits'              => 'many',
				'personalBest'        => 'yes',
				'pbCategory'          => '5k',
				'showDetailsPublicly' => true,
			]
		);
		$xpath = $this->xpath( $this->single( $id ) );

		foreach ( [ 'distance', 'duration', 'best', 'splits' ] as $part ) {
			$this->assertSame( 0, $xpath->query( '//*[contains(@class,"pkiw-exercise__' . $part . '")]' )->length, $part );
		}
		$this->assertSame( 0, $xpath->query( '//table' )->length );
		$this->assertSame( 1, $xpath->query( '//*[contains(@class,"pkiw-exercise__activity")]' )->length );
	}

	public function test_a_best_with_no_category_and_a_time_prints_the_time_alone(): void {
		$id   = $this->exercise( 'Uncategorized best', '2026-08-24 19:40:00', $this->a01( [ 'pbCategory' => '', 'splits' => [] ] ) );
		$html = $this->single( $id );

		$this->assertStringContainsString( 'Personal best: 29:12', $this->text( $html ) );
		$this->assertStringNotContainsString( 'Splits', $html );
	}

	public function test_a_protected_post_prints_nothing_from_its_card_even_when_asked_directly(): void {
		$id = $this->exercise( 'Members only', '2026-08-20 18:00:00', $this->a01(), [ 'post_password' => 'secret' ] );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );

		$this->assertSame( '', trim( do_blocks( (string) get_post_field( 'post_content', $id ) ) ) );
		$this->assertSame( '', trim( do_blocks( (string) get_post_field( 'post_content', $id ) ) ), 'Whatever asks: a feed push, the Markdown copy, REST.' );
	}

	public function test_the_place_line_follows_the_public_tier_and_prints_on_a_single_only(): void {
		$venue = [
			'_pkiw_checkin_venue'     => 'Maplewood Track',
			'_pkiw_checkin_city'      => 'Fernvale',
			'_pkiw_checkin_region'    => 'North Province',
			'_pkiw_checkin_country'   => 'Freedonia',
			'_pkiw_checkin_address'   => '12 Example Lane',
			'_pkiw_checkin_latitude'  => '45.1000',
			'_pkiw_checkin_longitude' => '-75.2000',
		];
		$track = $this->exercise( 'Track Session', '2026-08-06 18:00:00', [ 'activity' => 'Run', 'distance' => 6.0, 'showDetailsPublicly' => true ], [ 'meta_input' => $venue + [ '_pkiw_geo_privacy' => 'approximate' ] ] );
		$laps  = $this->exercise( 'Park Laps', '2026-08-07 18:00:00', [ 'activity' => 'Run' ], [ 'meta_input' => $venue + [ '_pkiw_geo_privacy' => 'private' ] ] );

		$single = $this->xpath( $this->single( $track ) );
		$this->assertSame( 'Maplewood Track, Fernvale, North Province, Freedonia', trim( $single->query( '//*[contains(@class,"pkiw-exercise__place")]' )->item( 0 )->textContent ) );
		$html = $this->single( $track );
		foreach ( [ '12 Example Lane', '45.1', '-75.2', 'geo:', 'openstreetmap' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
		$this->assertStringNotContainsString( 'pkiw-exercise__place', $this->single( $laps ), 'A private place prints nothing.' );
		$this->assertStringNotContainsString( 'pkiw-exercise__place', $this->printed_at( $track, get_term_link( 'exercise', Taxonomy::TAXONOMY ) ), 'No place on a listing.' );

		// REST content is not a singular request.
		$this->neutral_query();
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $track ) );
		$this->assertStringNotContainsString( 'Maplewood', (string) wp_json_encode( $response->get_data()['content'] ) );
		$this->assertStringContainsString( '6.00 km', (string) $response->get_data()['content']['rendered'] );
	}

	// The Stream shape.

	public function test_inside_a_stream_card_the_skeleton_names_the_kind_title_activity_and_distance_only(): void {
		$id   = $this->exercise(
			'Harbor Run',
			'2026-10-11 09:00:00',
			[
				'activity'            => 'Run',
				'distance'            => 8.0,
				'duration'            => 'PT41M10S',
				'splits'              => [
					[
						'i' => 1,
						't' => 'PT5M00S',
					],
				],
				'personalBest'        => true,
				'pbCategory'          => '5k',
				'showDetailsPublicly' => true,
			],
			[ 'meta_input' => [ '_pkiw_checkin_venue' => 'Maplewood Track', '_pkiw_checkin_city' => 'Fernvale' ] ]
		);
		$html  = $this->stream( $id );
		$xpath = $this->xpath( $html );

		$article = $xpath->query( '//article[contains(@class,"pk-card")][contains(@class,"k-exercise")][contains(@class,"h-entry")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $article );
		$this->assertSame( 'Exercise', trim( $xpath->query( './/span[contains(@class,"pk-kindlabel")]', $article )->item( 0 )->textContent ) );
		$title = $xpath->query( './/h2[contains(concat(" ",@class," ")," pk-title ")]', $article )->item( 0 );
		$this->assertSame( 'Harbor Run', trim( $title->textContent ) );
		$this->assertStringContainsString( 'p-name', $title->getAttribute( 'class' ) );
		$link = $xpath->query( './a[contains(@class,"u-url")]', $title )->item( 0 );
		$this->assertSame( get_permalink( $id ), $link->getAttribute( 'href' ) );
		$this->assertSame( 'Run', trim( $xpath->query( './/*[contains(@class,"p-exercise")]', $article )->item( 0 )->textContent ) );
		$this->assertSame( '8.00 km', trim( $xpath->query( './/*[contains(@class,"pkiw-exercise__distance")]', $article )->item( 0 )->textContent ) );

		$date = $xpath->query( './/time[contains(@class,"dt-published")]', $article )->item( 0 );
		$this->assertSame( '2026-10-11', $date->getAttribute( 'datetime' ), 'A date, no clock time.' );

		foreach ( [ '41:10', 'PT41M10S', 'Splits', 'Personal best', 'Maplewood', 'Fernvale', '<img' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
	}

	public function test_an_untitled_stream_card_is_named_by_kind_and_date_with_no_p_name(): void {
		$id    = $this->exercise( '', '2026-08-27 12:15:00', [ 'activity' => 'Bike ride' ] );
		$xpath = $this->xpath( $this->stream( $id ) );

		$title = $xpath->query( '//h2[contains(@class,"pk-title")]' )->item( 0 );
		$this->assertSame( 'Exercise, August 27, 2026', trim( $title->textContent ) );
		$this->assertStringNotContainsString( 'p-name', $title->getAttribute( 'class' ) );
		$this->assertSame( '2026-08-27', $xpath->query( '//time[contains(@class,"dt-published")]' )->item( 0 )->getAttribute( 'datetime' ) );
	}

	public function test_an_editor_reading_a_private_workout_in_the_stream_sees_no_private_prefix(): void {
		$id = $this->exercise( 'Quiet walk', '2026-08-18 09:00:00', [ 'activity' => 'Walk' ], [ 'post_status' => 'private' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->stream( $id );
		$this->assertStringContainsString( 'Quiet walk', $html );
		$this->assertStringNotContainsString( 'Private', $html );
	}

	public function test_a_stream_card_hides_the_distance_of_a_private_workout(): void {
		$id   = $this->exercise( 'Harbor Walk', '2026-10-11 10:00:00', [ 'activity' => 'Walk', 'distance' => 3.0 ] );
		$html = $this->stream( $id );

		$this->assertStringContainsString( 'Walk', $html );
		$this->assertStringNotContainsString( '3.00', $html );
		$this->assertStringNotContainsString( 'pkiw-exercise__distance', $html );
	}

	public function test_a_stream_card_follows_both_halves_of_the_gate(): void {
		$stale = $this->exercise( 'Stale', '2026-10-11 10:00:00', $this->a01( [ 'showDetailsPublicly' => false ] ) );
		update_post_meta( $stale, self::PUBLIC_KEY, '1' );
		$gone = $this->exercise( 'Gone', '2026-10-11 11:00:00', $this->a01() );
		delete_post_meta( $gone, self::PUBLIC_KEY );

		$this->assertStringNotContainsString( '5.00 km', $this->stream( $stale ) );
		$this->assertStringNotContainsString( '5.00 km', $this->stream( $gone ) );
	}

	public function test_a_protected_post_in_the_stream_prints_its_title_link_only(): void {
		$id   = $this->exercise( 'Members Only Ride', '2026-08-20 18:00:00', $this->a01( [ 'activity' => 'Cycle' ] ), [ 'post_password' => 'secret' ] );
		$html = $this->stream( $id );

		$this->assertStringContainsString( 'Protected: Members Only Ride', $html );
		foreach ( [ 'Cycle', '5.00 km', '29:12', 'Personal best' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
	}

	// The listing shape.

	public function test_on_a_kind_archive_and_a_search_and_the_blog_only_the_activity_prints(): void {
		$id = $this->exercise( 'Lakeside Loop', '2026-08-23 07:10:00', $this->a01() );

		foreach ( [ 'archive' => get_term_link( 'exercise', Taxonomy::TAXONOMY ), 'search' => home_url( '/?s=Lakeside' ), 'blog' => home_url( '/' ) ] as $name => $url ) {
			$html = $this->printed_at( $id, (string) $url );
			$this->assertStringContainsString( 'pkiw-exercise--listing', $html, $name );
			$this->assertStringContainsString( 'Run', $html, $name );
			foreach ( [ '5.00 km', '29:12', 'Personal best', 'Splits', 'datetime' ] as $needle ) {
				$this->assertStringNotContainsString( $needle, $html, $name . ' ' . $needle );
			}
		}
		$this->assertTrue( is_home() || is_search() || is_archive() );
	}

	public function test_the_kind_feed_keeps_the_full_record_although_core_flags_it_an_archive(): void {
		$open   = $this->exercise( 'Lakeside Loop', '2026-08-23 07:10:00', $this->a01() );
		$closed = $this->exercise( 'Evening Walk', '2026-08-24 19:40:00', [ 'activity' => 'Walk', 'distance' => 7.77, 'duration' => 'PT1H32M' ] );
		$feed   = (string) get_term_feed_link( (int) get_term_by( 'slug', 'exercise', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY );

		$this->go_to( $feed );
		$this->assertTrue( is_archive() && is_feed(), 'Core sets both on a kind feed.' );
		$GLOBALS['post'] = get_post( $open );
		setup_postdata( $GLOBALS['post'] );
		$full = (string) apply_filters( 'the_content_feed', (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $open ) ), 'rss2' );
		$this->assertStringContainsString( '5.00 km', $full );
		$this->assertStringContainsString( 'Personal best: 5K, 29:12', $this->text( $full ) );
		$this->assertStringNotContainsString( 'pkiw-exercise--listing', $full );

		$GLOBALS['post'] = get_post( $closed );
		setup_postdata( $GLOBALS['post'] );
		$private = (string) apply_filters( 'the_content_feed', (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $closed ) ), 'rss2' );
		$this->assertStringContainsString( 'Walk', $private );
		$this->assertStringNotContainsString( '7.77', $private );
		$this->assertStringNotContainsString( '1:32:00', $private );
	}

	public function test_the_site_feed_the_rest_content_and_the_embed_excerpt_follow_the_same_gate(): void {
		$open   = $this->exercise( 'Lakeside Loop', '2026-08-23 07:10:00', $this->a01() );
		$closed = $this->exercise( 'Evening Walk', '2026-08-24 19:40:00', [ 'activity' => 'Walk', 'distance' => 7.77, 'duration' => 'PT1H32M' ] );

		$this->neutral_query();
		$rest_open   = (string) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $open ) )->get_data()['content']['rendered'];
		$rest_closed = (string) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $closed ) )->get_data()['content']['rendered'];
		$this->assertStringContainsString( '5.00 km', $rest_open );
		$this->assertStringContainsString( 'Walk', $rest_closed );
		$this->assertStringNotContainsString( '7.77', $rest_closed );

		// oEmbed and the embed template excerpt: a card is not an allowed excerpt block.
		$GLOBALS['post'] = get_post( $closed );
		setup_postdata( $GLOBALS['post'] );
		$excerpt = (string) apply_filters( 'the_excerpt_embed', get_the_excerpt( $closed ) );
		$this->assertStringNotContainsString( '7.77', $excerpt );
		$data = get_oembed_response_data( $closed, 600 );
		$this->assertIsArray( $data );
		$this->assertStringNotContainsString( '7.77', (string) wp_json_encode( $data ) );
	}

	public function test_the_markdown_copy_and_a_push_build_from_the_same_gated_render(): void {
		$closed = $this->exercise( 'Evening Walk', '2026-08-24 19:40:00', [ 'activity' => 'Walk', 'distance' => 7.77, 'duration' => 'PT1H32M' ] );

		$GLOBALS['post'] = get_post( $closed );
		setup_postdata( $GLOBALS['post'] );
		$text = $this->text( (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $closed ) ) );

		$this->assertStringNotContainsString( '7.77', $text );
		$this->assertStringNotContainsString( '32', $text );
		$this->assertStringContainsString( 'Walk', $text );
	}

	public function test_no_shape_prints_a_route_visibility_text_a_lock_a_globe_or_a_biometric(): void {
		$open   = $this->exercise( 'Lakeside Loop', '2026-08-23 07:10:00', $this->a01( [ 'origin' => 'micropub' ] ) );
		$closed = $this->exercise( 'Evening Walk', '2026-08-24 19:40:00', array_merge( $this->a01(), [ 'showDetailsPublicly' => false ] ) );

		$outputs = [
			$this->single( $open ),
			$this->single( $closed ),
			$this->stream( $open ),
			$this->stream( $closed ),
			$this->printed_at( $open, (string) get_term_link( 'exercise', Taxonomy::TAXONOMY ) ),
		];
		foreach ( $outputs as $at => $html ) {
			foreach ( [ 'Route', 'Public', 'public', 'Private', 'private', 'Show details', 'details_public', 'showDetailsPublicly', 'micropub' ] as $word ) {
				$this->assertStringNotContainsString( $word, $html, "Output {$at}: {$word}" );
			}
			$this->assertDoesNotMatchRegularExpression( '/\block(?:ed)?\b|\bglobe\b/i', wp_strip_all_tags( $html ), "Output {$at}" );
			$this->assertDoesNotMatchRegularExpression( '/[\x{1F512}\x{1F513}\x{1F510}\x{1F30D}-\x{1F310}]/u', $html, "Output {$at}" );
			$this->assertDoesNotMatchRegularExpression( '/\b(?:heart|calorie|bpm|biometric)/i', $html, "Output {$at}" );
		}
	}

	public function test_a_single_parses_as_one_entry_with_one_exercise_and_no_property_on_a_metric(): void {
		if ( ! function_exists( '\Mf2\parse' ) ) {
			$this->markTestSkipped( 'php-mf2 is not available.' );
		}
		$id   = $this->exercise( 'Lakeside Loop', '2026-08-23 07:10:00', $this->a01() );
		$html = $this->single( $id );

		$parsed = \Mf2\parse( $html, (string) get_permalink( $id ) );
		$this->assertCount( 1, $parsed['items'], 'One entry.' );
		$this->assertContains( 'h-entry', $parsed['items'][0]['type'] );
		$props = $parsed['items'][0]['properties'];
		$this->assertSame( [ 'Run' ], $props['exercise'] );
		foreach ( array_keys( $props ) as $name ) {
			$this->assertContains( $name, [ 'url', 'exercise', 'published', 'name' ], "Property {$name} on the entry." );
		}
		$this->assertArrayNotHasKey( 'duration', $props );
		$this->assertArrayNotHasKey( 'location', $props );
	}
}
