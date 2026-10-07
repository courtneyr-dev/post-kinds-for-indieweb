<?php
/**
 * Play groups: video, board or none, by one rule everywhere (issues 232 and 237).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Cases_Source;
use PKIW\Micropub_Content_Builder;
use PKIW\Taxonomy;

/**
 * Covers \PKIW\play_group(), \PKIW\play_group_of_attrs(), the play source
 * the archive groups by, and the archive order on the combined W1 play
 * fixture set (27 published plays, 12 a page).
 *
 * Every test runs under the default `pkiw_archive_group_source`. A site
 * filter that swaps the play source changes the archive's sections but not
 * play_group(), so the gates can disagree there by design.
 *
 * @group integration
 */
final class PlayGroupTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		\PKIW\register_play_kind();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 10 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A play-card block comment.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
	}

	/**
	 * A post of a kind, with meta rows written as given.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $args Post args.
	 * @param array<string, string> $meta Meta key => value, added as rows.
	 */
	private function kind_post( string $kind, array $args = [], array $meta = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish' ], $args ) );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Write a meta row as stored, past the key's sanitizer (the live 37741
	 * shape holds rows the sanitizer would have trimmed).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param string $value   Stored value.
	 */
	private function raw_row( int $post_id, string $key, string $value ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->postmeta,
			[
				'post_id'    => $post_id,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * The first play-card block's attributes, searched depth first.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	private function first_card_attrs( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( 'post-kinds-indieweb/play-card' === ( $block['blockName'] ?? '' ) ) {
				return (array) ( $block['attrs'] ?? [] );
			}
			$found = $this->first_card_attrs( (array) ( $block['innerBlocks'] ?? [] ) );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	// The play source.

	public function test_the_play_kind_groups_by_the_play_type_cases_source(): void {
		$source = Grouped_Archive::source( 'play_type' );

		$this->assertInstanceOf( Cases_Source::class, $source );
		$this->assertContains( 'play', Grouped_Archive::grouped_kinds() );

		$id = $this->kind_post( 'play', [], [ '_pkiw_play_bgg_id' => '9990001' ] );
		$this->assertSame( 'board', Grouped_Archive::group_of_post( 'play', $id )->key() );
	}

	public function test_the_plugin_labels_are_neutral_with_no_site_copy(): void {
		$source = Grouped_Archive::source( 'play_type' );
		$video  = $this->kind_post( 'play', [], [ '_pkiw_play_rawg_id' => '900001' ] );
		$board  = $this->kind_post( 'play', [], [ '_pkiw_play_bgg_id' => '9990001' ] );

		$this->assertSame( 'Video games', $source->label( $source->group_of( get_post( $video ) ), [] ) );
		$this->assertSame( 'Board games', $source->label( $source->group_of( get_post( $board ) ), [] ) );
		$this->assertSame( '', $source->empty_label(), 'The empty group takes the engine label (Other) or the marker emptyLabel.' );
	}

	public function test_the_archive_sections_editor_offers_a_field_per_play_group(): void {
		$groups = \PKIW\Kind_Archive_Layouts::archive_sections_groups();

		$this->assertSame(
			[
				'video' => 'Video games',
				'board' => 'Board games',
			],
			$groups['play'] ?? null
		);
	}

	// play_group().

	/**
	 * @return array<string, array{array<string, string>, string}>
	 */
	public function stored_ids(): array {
		return [
			'rawg only'      => [ [ '_pkiw_play_rawg_id' => '900001' ], 'video' ],
			'steam only'     => [ [ '_pkiw_play_steam_id' => '900002' ], 'video' ],
			'rawg plus bgg'  => [
				[
					'_pkiw_play_rawg_id' => '900003',
					'_pkiw_play_bgg_id'  => '900103',
				],
				'video',
			],
			'steam plus bgg' => [
				[
					'_pkiw_play_steam_id' => '900006',
					'_pkiw_play_bgg_id'   => '900108',
				],
				'video',
			],
			'bgg only'       => [ [ '_pkiw_play_bgg_id' => '9990001' ], 'board' ],
			'none'           => [ [], '' ],
			'empty rows'     => [
				[
					'_pkiw_play_rawg_id'  => '',
					'_pkiw_play_steam_id' => '',
					'_pkiw_play_bgg_id'   => '',
				],
				'',
			],
		];
	}

	/**
	 * @dataProvider stored_ids
	 *
	 * @param array<string, string> $meta     Provider ID rows.
	 * @param string                $expected Group.
	 */
	public function test_play_group_files_a_play_by_its_stored_provider_ids( array $meta, string $expected ): void {
		$id = $this->kind_post( 'play', [], $meta );

		$this->assertSame( $expected, \PKIW\play_group( $id ) );
	}

	public function test_whitespace_rows_file_a_play_in_no_group(): void {
		$id = $this->kind_post( 'play' );
		$this->raw_row( $id, '_pkiw_play_rawg_id', '  ' );
		$this->raw_row( $id, '_pkiw_play_bgg_id', '   ' );

		$this->assertSame( '', \PKIW\play_group( $id ) );
	}

	/**
	 * The C1 control: a watch post with a non-empty RAWG row. The rows
	 * alone would file it as video, so only the kind term keeps it out.
	 */
	public function test_a_non_play_post_with_a_provider_id_has_no_play_group(): void {
		$watch = $this->kind_post( 'watch', [], [ '_pkiw_play_rawg_id' => '900099' ] );
		$plain = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		add_post_meta( $plain, '_pkiw_play_bgg_id', '9990001' );

		$this->assertSame( 'video', Grouped_Archive::source( 'play_type' )->group_of( get_post( $watch ) )->key(), 'The rows alone say video.' );
		$this->assertSame( '', \PKIW\play_group( $watch ) );
		$this->assertSame( '', \PKIW\play_group( $plain ) );
		$this->assertSame( '', \PKIW\play_group( PHP_INT_MAX ), 'A missing post has no group.' );
	}

	// play_group_of_attrs().

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public function card_attrs(): array {
		return [
			'rawg'                    => [ [ 'rawgId' => '900001' ], 'video' ],
			'steam'                   => [ [ 'steamId' => '900002' ], 'video' ],
			'rawg plus bgg'           => [
				[
					'rawgId' => '900003',
					'bggId'  => '900103',
				],
				'video',
			],
			'bgg'                     => [ [ 'bggId' => '9990001' ], 'board' ],
			'no ids'                  => [ [ 'title' => 'Backyard Tag' ], '' ],
			'blank bgg'               => [ [ 'bggId' => '   ' ], '' ],
			'markup-only rawg, bgg'   => [
				[
					'rawgId' => '<b></b>',
					'bggId'  => '9990002',
				],
				'board',
			],
			'backslash rawg'          => [ [ 'rawgId' => '\\' ], '' ],
			'number bgg'              => [ [ 'bggId' => 9990001 ], '' ],
			'boolean steam, bgg'      => [
				[
					'steamId' => true,
					'bggId'   => '9990003',
				],
				'board',
			],
		];
	}

	/**
	 * Attributes name a provider by the row Card_Meta_Sync would store:
	 * a string that stays non-empty once sanitized and unslashed.
	 *
	 * @dataProvider card_attrs
	 *
	 * @param array<string, mixed> $attrs    Card attributes.
	 * @param string               $expected Group.
	 */
	public function test_play_group_of_attrs_uses_the_same_video_first_rule( array $attrs, string $expected ): void {
		$this->assertSame( $expected, \PKIW\play_group_of_attrs( $attrs ) );
	}

	// One answer for every gate.

	/**
	 * Card switches, as stored provider IDs then the card saved over them.
	 *
	 * @return array<string, array{array<string, string>, array<string, string>, string}>
	 */
	public function switches(): array {
		return [
			'rawg to bgg'   => [ [ '_pkiw_play_rawg_id' => '900008' ], [ 'bggId' => '900108' ], 'board' ],
			'steam to bgg'  => [ [ '_pkiw_play_steam_id' => '900006' ], [ 'bggId' => '900106' ], 'board' ],
			'bgg to rawg'   => [ [ '_pkiw_play_bgg_id' => '9990001' ], [ 'rawgId' => '900001' ], 'video' ],
			'bgg to steam'  => [ [ '_pkiw_play_bgg_id' => '9990002' ], [ 'steamId' => '900002' ], 'video' ],
			'rawg plus bgg' => [ [], [ 'rawgId' => '900003', 'bggId' => '900103' ], 'video' ],
		];
	}

	/**
	 * The gates agree after the save path: play_group() from meta, the
	 * first card's attributes, and the SQL key the archive sorts by.
	 *
	 * @param int    $id       Post ID.
	 * @param string $expected Group.
	 */
	private function assert_gates_agree( int $id, string $expected ): void {
		clean_post_cache( $id );
		$attrs = $this->first_card_attrs( parse_blocks( (string) get_post( $id )->post_content ) );

		$this->assertTrue( has_term( 'play', Taxonomy::TAXONOMY, $id ), 'The save path left the post a play.' );
		$this->assertNotNull( $attrs, 'The post holds a play card.' );
		$this->assertSame( $expected, \PKIW\play_group( $id ), 'play_group() from meta.' );
		$this->assertSame( $expected, \PKIW\play_group_of_attrs( $attrs ), 'play_group_of_attrs() from the card.' );
		$this->assertSame( $expected, $this->sql_keys( [ $id ] )[ $id ], 'The archive SQL key.' );
	}

	/**
	 * A play whose card first named the stored IDs.
	 *
	 * @param array<string, string> $stored Stored provider IDs.
	 */
	private function play_with_ids( array $stored ): int {
		$attrs = [ 'title' => 'Tidepool Express' ];
		foreach ( [
			'_pkiw_play_rawg_id'  => 'rawgId',
			'_pkiw_play_steam_id' => 'steamId',
			'_pkiw_play_bgg_id'   => 'bggId',
		] as $key => $attr ) {
			if ( isset( $stored[ $key ] ) ) {
				$attrs[ $attr ] = $stored[ $key ];
			}
		}
		$id = $this->kind_post( 'play', [ 'post_content' => $this->card( $attrs ) ] );
		foreach ( $stored as $key => $value ) {
			$this->assertSame( $value, get_post_meta( $id, $key, true ), 'The first save stored the ID.' );
		}

		return $id;
	}

	/**
	 * Save through the REST posts route as an administrator.
	 *
	 * @param int                  $post_id Post ID, 0 to create.
	 * @param array<string, mixed> $params  Body params.
	 * @return int Post ID.
	 */
	private function rest_save( int $post_id, array $params ): int {
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' . ( $post_id > 0 ? '/' . $post_id : '' ) );
		$request->set_body_params( $params );
		$response = rest_get_server()->dispatch( $request );
		$this->assertContains( $response->get_status(), [ 200, 201 ], (string) wp_json_encode( $response->get_data() ) );

		return (int) $response->get_data()['id'];
	}

	/**
	 * The block editor's save: the new content plus the meta it loaded.
	 *
	 * @dataProvider switches
	 *
	 * @param array<string, string> $stored   Stored provider IDs.
	 * @param array<string, string> $ids      Provider IDs on the saved card.
	 * @param string                $expected Group.
	 */
	public function test_the_gates_agree_after_an_editor_save( array $stored, array $ids, string $expected ): void {
		$id = $this->play_with_ids( $stored );

		$params = [ 'content' => $this->card( [ 'title' => 'Tidepool Express' ] + $ids ) ];
		if ( [] !== $stored ) {
			$params['meta'] = $stored;
		}
		$this->rest_save( $id, $params );

		$this->assert_gates_agree( $id, $expected );
	}

	/**
	 * A REST client creating the play, with no kind and no meta.
	 *
	 * @dataProvider switches
	 *
	 * @param array<string, string> $stored   Unused: a new post stores nothing.
	 * @param array<string, string> $ids      Provider IDs on the card.
	 * @param string                $expected Group.
	 */
	public function test_the_gates_agree_after_a_rest_create( array $stored, array $ids, string $expected ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		$id = $this->rest_save(
			0,
			[
				'status'  => 'publish',
				'title'   => 'Tidepool Express',
				'content' => $this->card( [ 'title' => 'Tidepool Express' ] + $ids ),
			]
		);

		$this->assert_gates_agree( $id, $expected );
	}

	/**
	 * wp_update_post(), as WP-CLI's `wp post update` and imports save.
	 *
	 * @dataProvider switches
	 *
	 * @param array<string, string> $stored   Stored provider IDs.
	 * @param array<string, string> $ids      Provider IDs on the saved card.
	 * @param string                $expected Group.
	 */
	public function test_the_gates_agree_after_wp_update_post( array $stored, array $ids, string $expected ): void {
		$id = $this->play_with_ids( $stored );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $this->card( [ 'title' => 'Tidepool Express' ] + $ids ) ),
			]
		);

		$this->assert_gates_agree( $id, $expected );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function micropub_play_urls(): array {
		return [
			'boardgamegeek boardgame'    => [ 'https://boardgamegeek.com/boardgame/13/catan', 'board' ],
			'boardgamegeek expansion'    => [ 'https://boardgamegeek.com/boardgameexpansion/461932/wingspan-americas-expansion', 'board' ],
			'videogamegeek'              => [ 'https://videogamegeek.com/videogame/12345/game-name', '' ],
			'example host'               => [ 'https://example.test/game', '' ],
		];
	}

	/**
	 * A Micropub create: the bridge writes the card, then saves the post.
	 *
	 * @dataProvider micropub_play_urls
	 *
	 * @param string $url      The play-of URL.
	 * @param string $expected Group.
	 */
	public function test_the_gates_agree_after_a_micropub_create( string $url, string $expected ): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => 'Played tonight.',
			]
		);

		Micropub_Content_Builder::apply(
			[
				'type'       => [ 'h-entry' ],
				'properties' => [
					'play-of' => [ $url ],
					'name'    => [ 'Catan' ],
				],
			],
			[ 'ID' => $id ]
		);

		$this->assert_gates_agree( $id, $expected );
	}

	// The archive on the combined fixture set.

	/**
	 * The SQL section key the archive sorts each post by.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int, string>
	 */
	private function sql_keys( array $ids ): array {
		global $wpdb;
		$sql  = Grouped_Archive::source( 'play_type' )->sql( new WP_Query() );
		$in   = implode( ', ', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results( "SELECT {$wpdb->posts}.ID AS id, {$sql['value']} AS k FROM {$wpdb->posts} WHERE {$wpdb->posts}.ID IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out  = [];
		foreach ( $rows as $row ) {
			$out[ (int) $row->id ] = (string) $row->k;
		}

		return $out;
	}

	/**
	 * The combined W1 play fixtures: 27 published plays, a draft and a
	 * watch control. Created out of archive order on purpose; the dates
	 * set the order inside each group, and B12 and B13 share a date so ID
	 * DESC breaks the tie.
	 *
	 * @return array<string, int> Fixture key => post ID.
	 */
	private function fixtures(): array {
		$play = function ( string $title, string $date, string $content, array $meta = [], array $args = [] ): int {
			return $this->kind_post(
				'play',
				array_merge(
					[
						'post_title'   => $title,
						'post_date'    => $date,
						'post_content' => $content,
					],
					$args
				),
				$meta
			);
		};
		$para = '<!-- wp:paragraph --><p>We played twice.</p><!-- /wp:paragraph -->';
		$ids  = [];

		$ids['E2']  = $play( 'Rainy Day Charades', '2026-08-30 10:00:00', $para, [ '_pkiw_play_title' => 'Rainy Day Charades', '_pkiw_play_status' => 'completed' ] );
		$ids['E1']  = $play( 'Backyard Tag', '2026-08-31 10:00:00', $this->card( [ 'title' => 'Backyard Tag', 'gameUrl' => 'https://games.example/backyard-tag' ] ) );
		$ids['B16'] = $play( 'Tidepool Express', '2026-08-14 10:00:00', $this->card( [ 'title' => 'Tidepool Express', 'rawgId' => '900008' ] ) );
		$ids['B15'] = $play( 'Poster Problem', '2026-08-15 10:00:00', $this->card( [ 'title' => 'Poster Problem', 'bggId' => '9990020' ] ) );
		$ids['B14'] = $play( 'Tide Pool Commons', '2026-08-16 10:00:00', $this->card( [ 'title' => 'Tide Pool Commons', 'bggId' => '9990019' ] ) . $para );
		$ids['B12'] = $play( 'Harbor Lanterns', '2026-08-17 10:00:00', $this->card( [ 'title' => 'Harbor Lanterns', 'bggId' => '900101', 'rating' => 3 ] ) );
		$ids['B13'] = $play( 'Meadow Tiles', '2026-08-17 10:00:00', $this->card( [ 'title' => 'Meadow Tiles', 'bggId' => '900102' ] ) );
		$ids['V1']  = $play( 'Starbound Courier', '2026-08-10 10:00:00', $this->card( [ 'title' => 'Starbound Courier', 'rawgId' => '900001', 'rating' => 4 ] ) );
		$ids['B11'] = $play( 'Locked Box', '2026-08-18 10:00:00', $this->card( [ 'title' => 'Locked Box', 'bggId' => '9990018', 'rating' => 5 ] ), [], [ 'post_password' => 'secret' ] );
		$ids['B10'] = $play( '', '2026-08-19 10:00:00', $this->card( [ 'bggId' => '9990017', 'gameUrl' => 'https://example.test/games/untitled' ] ) );
		$ids['V4']  = $play( 'The Extraordinarily Long Chronicle of the Lighthouse Keeper\'s Third Apprentice: Definitive Edition', '2026-08-09 10:00:00', $this->card( [ 'title' => 'Long Chronicle', 'rawgId' => '900004' ] ) );
		$ids['B9']  = $play( 'Cartographers\' Table', '2026-08-20 10:00:00', $this->card( [ 'title' => 'Cartographer\'s Table: Deluxe', 'bggId' => '9990007' ] ) );
		$ids['B8']  = $play( 'Paper Skies, late', '2026-08-21 10:00:00', $this->card( [ 'title' => 'Paper Skies', 'bggId' => '9990009', 'playedAt' => '2026-09-20T23:30' ] ) );
		$ids['B7']  = $play( 'Paper Skies', '2026-08-22 10:00:00', $this->card( [ 'title' => 'Paper Skies', 'bggId' => '9990006', 'playedAt' => '2026-02-30' ] ) );
		$ids['V2']  = $play( 'Garden Circuit', '2026-08-08 10:00:00', $this->card( [ 'title' => 'Garden Circuit', 'steamId' => '900002' ] ) );
		$ids['B6']  = $play( 'Harbor Lights', '2026-08-23 10:00:00', $this->card( [ 'title' => 'Harbor Lights', 'bggId' => '9990003' ] ) );
		$ids['B5']  = $play( 'Orbit Table: The Long Weekend Expansion With Every Promo Card Included', '2026-08-24 10:00:00', $this->card( [ 'title' => 'Orbit Table: The Long Weekend Expansion', 'bggId' => '9990008' ] ) );
		$ids['V3']  = $play( 'Clockwork Harbor', '2026-08-07 10:00:00', $this->card( [ 'title' => 'Clockwork Harbor', 'rawgId' => '900003', 'bggId' => '900103', 'rating' => 5 ] ) );
		$ids['B4']  = $play( 'Meadow Songs', '2026-08-25 10:00:00', $this->card( [ 'title' => 'Meadow Songs', 'bggId' => '9990004', 'rating' => 3 ] ) );
		$ids['B3']  = $play( 'Orbit Table', '2026-08-26 10:00:00', $this->card( [ 'title' => 'Orbit Table', 'bggId' => '9990002', 'rating' => 4 ] ) );
		$ids['V6']  = $play( 'Night Shift', '2026-08-05 10:00:00', $this->card( [ 'title' => 'Night Shift', 'steamId' => '900006', 'status' => 'completed' ] ) );
		$ids['V5']  = $play( 'Night Shift', '2026-08-06 10:00:00', $this->card( [ 'title' => 'Night Shift', 'rawgId' => '900005', 'status' => 'wishlist' ] ) );
		$ids['B2']  = $play( 'Forest Paths', '2026-08-27 10:00:00', $this->card( [ 'title' => 'Forest Paths', 'bggId' => '9990001', 'rating' => 4 ] ) );
		$ids['B1']  = $play( 'Forest Paths', '2026-08-28 10:00:00', $this->card( [ 'title' => 'Forest Paths', 'bggId' => '9990001', 'rating' => 5 ] ) );
		$ids['V9']  = $play( 'Copper Kite', '2026-08-04 10:00:00', $this->card( [ 'title' => 'Copper Kite', 'rawgId' => '900009' ] ) );
		$ids['V10'] = $play( 'Ember Relay', '2026-08-03 10:00:00', $this->card( [ 'title' => 'Ember Relay', 'rawgId' => '900010' ] ) . $para );
		$ids['V12'] = $play( 'Lantern Drift', '2026-08-02 10:00:00', $para, [ '_pkiw_play_rawg_id' => '900012' ] );
		$ids['D1']  = $play( 'Draft Quest', '2026-08-31 11:00:00', $this->card( [ 'title' => 'Draft Quest', 'rawgId' => '900011' ] ), [], [ 'post_status' => 'draft' ] );
		$ids['C1']  = $this->kind_post( 'watch', [ 'post_title' => 'Control', 'post_date' => '2026-08-31 12:00:00' ], [ '_pkiw_play_rawg_id' => '900099' ] );

		// B16 switches from RAWG to BGG through wp_update_post.
		wp_update_post(
			[
				'ID'           => $ids['B16'],
				'post_content' => wp_slash( $this->card( [ 'title' => 'Tidepool Express', 'bggId' => '900108' ] ) ),
			]
		);

		return $ids;
	}

	/**
	 * Expected archive order: video, board, then no group (G4); date DESC
	 * then ID DESC inside each.
	 */
	private const ARCHIVE_ORDER = [
		'V1',
		'V4',
		'V2',
		'V3',
		'V5',
		'V6',
		'V9',
		'V10',
		'V12',
		'B1',
		'B2',
		'B3',
		'B4',
		'B5',
		'B6',
		'B7',
		'B8',
		'B9',
		'B10',
		'B11',
		'B13',
		'B12',
		'B14',
		'B15',
		'B16',
		'E1',
		'E2',
	];

	/**
	 * Serve /kind/play/ and return the main query.
	 *
	 * @param int $page Page number.
	 */
	private function serve_archive( int $page ): WP_Query {
		$url = get_term_link( 'play', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );
		$this->assertTrue( is_tax( Taxonomy::TAXONOMY, 'play' ) );

		return $GLOBALS['wp_query'];
	}

	public function test_the_archive_pages_the_fixture_set_video_then_board_then_none(): void {
		$ids     = $this->fixtures();
		$by_id   = array_flip( $ids );
		$served  = [];
		$numbers = [];
		foreach ( [ 1, 2, 3 ] as $page ) {
			$query = $this->serve_archive( $page );
			$this->assertSame( 27, (int) $query->found_posts, "found_posts on page {$page} is the ungrouped count." );
			$this->assertSame( 3, (int) $query->max_num_pages );
			$keys = array_map( static fn( WP_Post $post ): string => $by_id[ $post->ID ] ?? 'unknown', $query->posts );
			$numbers[ $page ] = count( $keys );
			$served = array_merge( $served, $keys );
		}

		$this->assertSame( [ 1 => 12, 2 => 12, 3 => 3 ], $numbers, '12 a page from the marker linesPerPage.' );
		$this->assertSame( self::ARCHIVE_ORDER, $served );
		$this->assertSame( count( $served ), count( array_unique( $served ) ), 'Every post appears once across the pages.' );
		$this->assertNotContains( 'D1', $served );
		$this->assertNotContains( 'C1', $served );
	}

	public function test_play_group_matches_the_sql_key_for_every_fixture(): void {
		$ids  = $this->fixtures();
		$keys = $this->sql_keys( array_values( $ids ) );

		$expected = [];
		foreach ( self::ARCHIVE_ORDER as $key ) {
			$expected[ $key ] = str_starts_with( $key, 'V' ) ? 'video' : ( str_starts_with( $key, 'B' ) ? 'board' : '' );
		}
		$expected['D1'] = 'video';

		foreach ( $expected as $key => $group ) {
			$this->assertSame( $group, \PKIW\play_group( $ids[ $key ] ), "play_group() for {$key}." );
			$this->assertSame( $group, $keys[ $ids[ $key ] ], "SQL key for {$key}." );
		}

		$this->assertSame( 'video', $keys[ $ids['C1'] ], 'The control has a video row.' );
		$this->assertSame( '', \PKIW\play_group( $ids['C1'] ), 'Only the kind term keeps the control out.' );
	}
}
