<?php
/**
 * The play facts reader (issues 232 and 237).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Taxonomy;

/**
 * Covers \PKIW\play_facts() through \PKIW\kind_facts(): which facts come
 * from meta, which two come from the first play card, the labels a theme
 * prints verbatim, and who gets no facts.
 *
 * @group integration
 */
final class PlayFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		// The test framework drops registered meta between tests, and with
		// it each key's default.
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		\PKIW\register_play_kind();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A play post whose content holds the given card.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Post args.
	 */
	private function play( array $attrs, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Forest Paths',
					'post_content' => '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'play', Taxonomy::TAXONOMY );

		return $id;
	}

	public function test_the_reader_registers_through_kind_facts_register_not_readers(): void {
		$this->assertArrayNotHasKey( 'play', Kind_Facts::READERS );
		$this->assertSame( 'PKIW\\play_facts', Kind_Facts::reader( 'play' )['reader'] ?? null );
	}

	public function test_a_board_play_reports_every_fact_from_meta(): void {
		$id = $this->play(
			[
				'title'       => 'Forest Paths',
				'platform'    => 'Board Game',
				'status'      => 'completed',
				'hoursPlayed' => 2.5,
				'rating'      => 5,
				'review'      => 'Tense to the last turn.',
				'gameUrl'     => 'https://example.test/games/forest-paths',
				'officialUrl' => 'https://forest.example/',
				'purchaseUrl' => 'https://shop.example/forest-paths',
				'bggId'       => '9990001',
				'playedAt'    => '2026-09-20',
				'coverAlt'    => 'Forest Paths box',
			]
		);

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame(
			[
				'title'          => 'Forest Paths',
				'platform'       => 'Board Game',
				'status'         => 'completed',
				'status_label'   => 'Completed',
				'hours'          => 2.5,
				'hours_label'    => '2.5 hours played',
				'rating'         => 5.0,
				'rating_label'   => 'Rated 5 of 5',
				'review'         => 'Tense to the last turn.',
				'game_url'       => 'https://example.test/games/forest-paths',
				'game_url_label' => 'example.test',
				'official_url'   => 'https://forest.example/',
				'purchase_url'   => 'https://shop.example/forest-paths',
				'bgg_id'         => '9990001',
				'rawg_id'        => '',
				'steam_id'       => '',
				'group'          => 'board',
				'played_at'      => [ '2026-09-20', 'September 20, 2026' ],
				'cover_alt'      => 'Forest Paths box',
			],
			$facts
		);
	}

	/**
	 * Meta beats a stale card attribute: an import or the sidebar can write
	 * meta the card's copy never saw (X16). Every mirrored field is stale
	 * here, so a reader that reads any of them from the card fails.
	 */
	public function test_meta_beats_a_stale_card_attribute(): void {
		$id = $this->play(
			[
				'title'       => 'Old Title',
				'platform'    => 'PC',
				'status'      => 'abandoned',
				'hoursPlayed' => 1,
				'rating'      => 2,
				'review'      => 'Old review.',
				'gameUrl'     => 'https://rawg.io/games/old-title',
				'officialUrl' => 'https://old.example/',
				'purchaseUrl' => 'https://shop.example/old-title',
				'rawgId'      => '900001',
				'steamId'     => '900002',
				'bggId'       => '9990001',
			]
		);
		update_post_meta( $id, '_pkiw_play_title', 'New Title' );
		update_post_meta( $id, '_pkiw_play_platform', 'Nintendo Switch' );
		update_post_meta( $id, '_pkiw_play_status', 'completed' );
		update_post_meta( $id, '_pkiw_play_hours', 7.5 );
		update_post_meta( $id, '_pkiw_play_rating', 4 );
		update_post_meta( $id, '_pkiw_play_review', 'New review.' );
		update_post_meta( $id, '_pkiw_play_game_url', 'https://store.steampowered.com/app/900098/' );
		update_post_meta( $id, '_pkiw_play_official_url', 'https://new.example/' );
		update_post_meta( $id, '_pkiw_play_purchase_url', 'https://shop.example/new-title' );
		update_post_meta( $id, '_pkiw_play_rawg_id', '900099' );
		update_post_meta( $id, '_pkiw_play_steam_id', '900098' );
		update_post_meta( $id, '_pkiw_play_bgg_id', '9990077' );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( 'New Title', $facts['title'] );
		$this->assertSame( 'Nintendo Switch', $facts['platform'] );
		$this->assertSame( 'completed', $facts['status'] );
		$this->assertSame( 'Completed', $facts['status_label'] );
		$this->assertSame( 7.5, $facts['hours'] );
		$this->assertSame( '7.5 hours played', $facts['hours_label'] );
		$this->assertSame( 4.0, $facts['rating'] );
		$this->assertSame( 'New review.', $facts['review'] );
		$this->assertSame( 'https://store.steampowered.com/app/900098/', $facts['game_url'] );
		$this->assertSame( 'View on Steam', $facts['game_url_label'] );
		$this->assertSame( 'https://new.example/', $facts['official_url'] );
		$this->assertSame( 'https://shop.example/new-title', $facts['purchase_url'] );
		$this->assertSame( '900099', $facts['rawg_id'] );
		$this->assertSame( '900098', $facts['steam_id'] );
		$this->assertSame( '9990077', $facts['bgg_id'] );
		$this->assertSame( 'video', $facts['group'] );
	}

	/**
	 * Cleared meta stays cleared: the card's stale game URL and provider
	 * IDs don't fill the gap, and the group follows the meta that's left.
	 */
	public function test_cleared_meta_does_not_fall_back_to_a_stale_card_attribute(): void {
		$id = $this->play(
			[
				'title'   => 'Forest Paths',
				'review'  => 'Old review.',
				'gameUrl' => 'https://rawg.io/games/forest-paths',
				'rawgId'  => '900001',
				'steamId' => '900002',
			]
		);
		delete_post_meta( $id, '_pkiw_play_review' );
		delete_post_meta( $id, '_pkiw_play_game_url' );
		delete_post_meta( $id, '_pkiw_play_rawg_id' );
		delete_post_meta( $id, '_pkiw_play_steam_id' );
		update_post_meta( $id, '_pkiw_play_bgg_id', '9990077' );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( '', $facts['review'] );
		$this->assertSame( '', $facts['game_url'] );
		$this->assertSame( '', $facts['game_url_label'] );
		$this->assertSame( '', $facts['rawg_id'] );
		$this->assertSame( '', $facts['steam_id'] );
		$this->assertSame( '9990077', $facts['bgg_id'] );
		$this->assertSame( 'board', $facts['group'] );
	}

	public function test_a_meta_only_play_reports_its_facts_with_no_card(): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Lunch-break game.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $id, 'play', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_play_title', 'Lantern Drift' );
		update_post_meta( $id, '_pkiw_play_rawg_id', '900012' );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( 'Lantern Drift', $facts['title'] );
		$this->assertSame( 'playing', $facts['status'], 'No status row reads as the registered default, as the card does.' );
		$this->assertSame( 'Playing', $facts['status_label'] );
		$this->assertSame( '900012', $facts['rawg_id'] );
		$this->assertSame( 'video', $facts['group'] );
		$this->assertSame( [ '', '' ], $facts['played_at'], 'No card, no played day.' );
		$this->assertSame( '', $facts['cover_alt'] );
	}

	public function test_a_password_protected_play_has_no_facts(): void {
		$id = $this->play(
			[
				'title'  => 'Locked Box',
				'bggId'  => '9990018',
				'rating' => 5,
				'review' => 'Secret.',
			],
			[ 'post_password' => 'secret' ]
		);

		$this->assertSame( [], \PKIW\kind_facts( $id ) );
	}

	/**
	 * Kind_Facts::can_show() lets anyone who can read the post see its
	 * facts, so a private play is empty only to a logged-out visitor.
	 */
	public function test_a_private_play_has_facts_only_for_a_user_who_can_read_it(): void {
		$id = $this->play(
			[
				'title' => 'Private Game',
				'bggId' => '9990021',
			],
			[ 'post_status' => 'private' ]
		);

		wp_set_current_user( 0 );
		$this->assertSame( [], \PKIW\kind_facts( $id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 'Private Game', \PKIW\kind_facts( $id )['title'] ?? null );
	}

	public function test_a_post_of_another_kind_gets_no_play_facts(): void {
		$id = $this->play( [ 'title' => 'Forest Paths' ] );
		wp_set_object_terms( $id, 'watch', Taxonomy::TAXONOMY );

		$this->assertSame( [], \PKIW\kind_facts( $id ) );
	}

	// Labels.

	/**
	 * @return array<string, array{float, string}>
	 */
	public function hours(): array {
		return [
			'none'            => [ 0.0, '' ],
			'negative'        => [ -2.0, '' ],
			'one'             => [ 1.0, '1 hour played' ],
			'two'             => [ 2.0, '2 hours played' ],
			'one half'        => [ 1.5, '1.5 hours played' ],
			'three half'      => [ 3.5, '3.5 hours played' ],
			'half'            => [ 0.5, '0.5 hours played' ],
			'quarter'         => [ 2.25, '2.25 hours played' ],
			'thousands'       => [ 1200.0, '1,200 hours played' ],
			'rounds to 0'     => [ 0.004, '' ],
			'rounds to 0.01'  => [ 0.005, '0.01 hours played' ],
			'rounds to whole' => [ 1.999, '2 hours played' ],
		];
	}

	/**
	 * number_format_i18n() with no decimals would print 3.5 as 4.
	 *
	 * @dataProvider hours
	 *
	 * @param float  $hours    Hours played.
	 * @param string $expected Label.
	 */
	public function test_hours_label_keeps_decimals_and_picks_the_plural( float $hours, string $expected ): void {
		$this->assertSame( $expected, \PKIW\play_hours_label( $hours ) );
	}

	/**
	 * @return array<string, array{float, string}>
	 */
	public function hours_with_a_comma_decimal(): array {
		return [
			'one'             => [ 1.0, '1 hour played' ],
			'one half'        => [ 1.5, '1,5 hours played' ],
			'quarter'         => [ 2.25, '2,25 hours played' ],
			'tenth'           => [ 10.1, '10,1 hours played' ],
			'thousands'       => [ 1200.0, '1.200 hours played' ],
			'thousands half'  => [ 1200.5, '1.200,5 hours played' ],
			'rounds to 0'     => [ 0.004, '' ],
		];
	}

	/**
	 * A locale that writes 1.200,5 keeps its thousands dot and trims only
	 * trailing decimal zeros.
	 *
	 * @dataProvider hours_with_a_comma_decimal
	 *
	 * @param float  $hours    Hours played.
	 * @param string $expected Label.
	 */
	public function test_hours_label_follows_the_locale_s_separators( float $hours, string $expected ): void {
		global $wp_locale;
		$saved                                     = $wp_locale->number_format;
		$wp_locale->number_format['decimal_point'] = ',';
		$wp_locale->number_format['thousands_sep'] = '.';

		try {
			$label = \PKIW\play_hours_label( $hours );
		} finally {
			$wp_locale->number_format = $saved;
		}

		$this->assertSame( $expected, $label );
	}

	public function test_the_hours_fact_and_label_come_from_meta(): void {
		$id = $this->play( [ 'title' => 'Long Chronicle', 'hoursPlayed' => 3.5, 'rawgId' => '900004' ] );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( 3.5, $facts['hours'] );
		$this->assertSame( '3.5 hours played', $facts['hours_label'] );
	}

	public function test_status_labels_cover_every_stored_status(): void {
		$this->assertSame(
			[
				'playing'   => 'Playing',
				'completed' => 'Completed',
				'abandoned' => 'Abandoned',
				'backlog'   => 'Backlog',
				'wishlist'  => 'Wishlist',
			],
			\PKIW\play_status_labels()
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function statuses(): array {
		return [
			'playing'   => [ 'playing' ],
			'completed' => [ 'completed' ],
			'abandoned' => [ 'abandoned' ],
			'backlog'   => [ 'backlog' ],
			'wishlist'  => [ 'wishlist' ],
		];
	}

	/**
	 * @dataProvider statuses
	 *
	 * @param string $status Stored status.
	 */
	public function test_status_label_is_the_plugin_label_verbatim( string $status ): void {
		$id = $this->play( [ 'title' => 'Garden Circuit', 'status' => $status ] );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( $status, $facts['status'] );
		$this->assertSame( \PKIW\play_status_labels()[ $status ], $facts['status_label'] );
	}

	/**
	 * The status sanitizer turns 'paused' into 'playing' on every write, so
	 * an unknown status reaches the reader only as a row written past it.
	 */
	public function test_an_unknown_stored_status_passes_through_raw(): void {
		global $wpdb;
		$id = $this->play( [ 'title' => 'Garden Circuit' ] );
		delete_post_meta( $id, '_pkiw_play_status' );
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $id, 'meta_key' => '_pkiw_play_status', 'meta_value' => 'paused' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		wp_cache_delete( $id, 'post_meta' );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( 'paused', $facts['status'] );
		$this->assertSame( 'paused', $facts['status_label'] );
	}

	public function test_a_status_set_through_the_facts_filter_is_not_relabeled(): void {
		$id     = $this->play( [ 'title' => 'Garden Circuit', 'status' => 'completed' ] );
		$filter = static function ( array $facts, int $post_id, string $kind ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( 'play' === $kind ) {
				$facts['status'] = 'paused';
			}
			return $facts;
		};
		add_filter( 'pkiw_kind_facts', $filter, 10, 3 );
		$facts = \PKIW\kind_facts( $id );
		remove_filter( 'pkiw_kind_facts', $filter, 10 );

		$this->assertSame( 'paused', $facts['status'] );
		$this->assertSame( 'Completed', $facts['status_label'], 'The reader labels the stored status; a later filter owns what it changes.' );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function game_urls(): array {
		return [
			'boardgamegeek'     => [ 'https://boardgamegeek.com/boardgame/13/catan', 'View on BGG' ],
			'www boardgamegeek' => [ 'https://www.boardgamegeek.com/boardgame/13', 'View on BGG' ],
			'rawg'              => [ 'https://rawg.io/games/starbound-courier', 'View on RAWG' ],
			'steam store'       => [ 'https://store.steampowered.com/app/900002/', 'View on Steam' ],
			'example host'      => [ 'https://example.test/games/forest-paths', 'example.test' ],
			'look-alike host'   => [ 'https://boardgamegeek.com.example/boardgame/13', 'boardgamegeek.com.example' ],
			'steam community'   => [ 'https://steamcommunity.com/app/900002', 'steamcommunity.com' ],
			'none'              => [ '', '' ],
			'not a web url'     => [ 'javascript:alert(1)', '' ],
		];
	}

	/**
	 * @dataProvider game_urls
	 *
	 * @param string $url      Game URL.
	 * @param string $expected Label.
	 */
	public function test_game_url_label_goes_by_host( string $url, string $expected ): void {
		$this->assertSame( $expected, \PKIW\play_game_url_label( $url ) );
	}

	public function test_the_game_url_fact_drops_a_non_web_url(): void {
		$id = $this->play( [ 'title' => 'Forest Paths', 'bggId' => '9990001' ] );
		update_post_meta( $id, '_pkiw_play_game_url', 'ftp://files.example/forest' );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( '', $facts['game_url'] );
		$this->assertSame( '', $facts['game_url_label'] );
	}

	public function test_a_rating_above_five_reports_five(): void {
		$id = $this->play( [ 'title' => 'Forest Paths', 'bggId' => '9990001' ] );
		update_post_meta( $id, '_pkiw_play_rating', 8 );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( 5.0, $facts['rating'] );
		$this->assertSame( 'Rated 5 of 5', $facts['rating_label'] );
	}

	public function test_no_rating_gives_no_rating_label(): void {
		$id = $this->play( [ 'title' => 'Harbor Lights', 'bggId' => '9990003' ] );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( 0.0, $facts['rating'] );
		$this->assertSame( '', $facts['rating_label'] );
	}

	// The two card-only facts.

	/**
	 * @return array<string, array{string, array{string, string}}>
	 */
	public function played_days(): array {
		return [
			'a day'                => [ '2026-09-22', [ '2026-09-22', 'September 22, 2026' ] ],
			'a late local time'    => [ '2026-09-20T23:30', [ '2026-09-20', 'September 20, 2026' ] ],
			'an impossible day'    => [ '2026-02-30', [ '', '' ] ],
			'not a date'           => [ 'tomorrow', [ '', '' ] ],
		];
	}

	/**
	 * The played day comes from the first play card, as a calendar day
	 * that keeps its date west of UTC.
	 *
	 * @dataProvider played_days
	 *
	 * @param string                $played   Card playedAt.
	 * @param array{string, string} $expected Machine and display date.
	 */
	public function test_played_at_is_the_first_card_s_calendar_day( string $played, array $expected ): void {
		update_option( 'timezone_string', 'America/Los_Angeles' );
		update_option( 'date_format', 'F j, Y' );
		$id = $this->play( [ 'title' => 'Paper Skies', 'bggId' => '9990006', 'playedAt' => $played ] );

		$this->assertSame( $expected, \PKIW\kind_facts( $id )['played_at'] );
	}

	public function test_card_only_facts_read_the_first_play_card_even_inside_a_group(): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group {"className":"pkiw-entry"} --><div class="wp-block-group pkiw-entry">'
					. '<!-- wp:post-kinds-indieweb/play-card {"title":"First","bggId":"1","playedAt":"2026-09-01","coverAlt":"First box"} /-->'
					. '<!-- wp:post-kinds-indieweb/play-card {"title":"Second","bggId":"2","playedAt":"2026-09-02","coverAlt":"Second box"} /-->'
					. '</div><!-- /wp:group -->',
			]
		);
		wp_set_object_terms( $id, 'play', Taxonomy::TAXONOMY );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( '2026-09-01', $facts['played_at'][0] );
		$this->assertSame( 'First box', $facts['cover_alt'] );
	}

	public function test_cover_alt_is_trimmed_and_empty_without_one(): void {
		$with    = $this->play( [ 'title' => 'Forest Paths', 'coverAlt' => '  Forest Paths box  ' ] );
		$without = $this->play( [ 'title' => 'Orbit Table' ] );

		$this->assertSame( 'Forest Paths box', \PKIW\kind_facts( $with )['cover_alt'] );
		$this->assertSame( '', \PKIW\kind_facts( $without )['cover_alt'] );
	}

	// The Stream card's hidden citation reads these facts.

	public function test_a_long_form_play_s_stream_citation_comes_from_this_reader(): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Tide Pool Commons',
				'post_content' => '<!-- wp:post-kinds-indieweb/play-card {"title":"Tide Pool Commons","gameUrl":"https://example.test/games/tide-pool-commons","bggId":"9990019"} /-->'
					. '<!-- wp:paragraph --><p>We played twice.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $id, 'play', Taxonomy::TAXONOMY );

		$cite = \PKIW\stream_card_kind_cite( get_post( $id ) );

		$this->assertSame( 'PKIW\\play_facts', Kind_Facts::reader( 'play' )['reader'] );
		$this->assertStringContainsString( 'h-cite u-play-of', $cite );
		$this->assertStringContainsString( '<data class="p-name" value="Tide Pool Commons"></data>', $cite );
		$this->assertStringContainsString( '<data class="u-url" value="https://example.test/games/tide-pool-commons"></data>', $cite );
		$this->assertStringContainsString( '<data class="u-uid" value="https://boardgamegeek.com/boardgame/9990019"></data>', $cite );
	}
}
