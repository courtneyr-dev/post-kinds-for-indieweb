<?php
/** On This Day privacy-surface coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Abilities\On_This_Day_Abilities;
use PKIW\On_This_Day;
use PKIW\Public_Eligibility;
use PKIW\Public_Listing;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

if ( ! function_exists( 'pantheon_wp_clear_edge_paths' ) ) {
	function pantheon_wp_clear_edge_paths( array $paths ): void {
		$GLOBALS['pkiw_otd_test_path_purges'][] = $paths;
	}
}
if ( ! function_exists( 'pantheon_clear_edge_paths' ) ) {
	function pantheon_clear_edge_paths( array $paths ): void {
		$GLOBALS['pkiw_otd_test_platform_paths'][] = $paths;
	}
}
if ( ! function_exists( 'pantheon_wp_clear_edge_keys' ) ) {
	function pantheon_wp_clear_edge_keys( array $keys ): void {
		$GLOBALS['pkiw_otd_test_key_purges'][] = $keys;
	}
}
if ( ! function_exists( 'pantheon_clear_edge_keys' ) ) {
	function pantheon_clear_edge_keys( array $keys ): void {
		$GLOBALS['pkiw_otd_test_platform_keys'][] = $keys;
	}
}

/** @group integration */
final class OnThisDayPrivacyTest extends WP_UnitTestCase {
	private DateTimeImmutable $today;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		foreach ( [ 'follow', 'tag' ] as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( ucfirst( $slug ), Taxonomy::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
		On_This_Day::register_blocks();
		$this->today = new DateTimeImmutable( '2026-05-09 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
		On_This_Day::reset();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		wp_reset_postdata();
		Public_Eligibility::override_cli_for_tests( null );
		On_This_Day::override_now( null );
		On_This_Day::override_headers_for_tests( null );
		On_This_Day::reset();
		remove_all_filters( 'pkiw_otd_edge_purge' );
		remove_all_filters( 'pkiw_on_this_day_kinds' );
		parent::tear_down();
	}

	public function test_archive_and_ability_hide_every_sentinel_and_keep_controls(): void {
		[ $visible, $hidden ] = $this->seed_matrix();
		$rows = On_This_Day::build_rows( $this->today );
		$ids = array_column( $rows, 'id' );
		$json = wp_json_encode( On_This_Day_Abilities::instance()->execute() );
		$archive = $this->archive_html();
		foreach ( $visible as $id ) {
			$this->assertContains( $id, $ids );
			$this->assertStringContainsString( $this->marker( $id ), (string) $json );
			$this->assertStringContainsString( $this->marker( $id ), $archive );
		}
		foreach ( $hidden as $id ) {
			$this->assertNotContains( $id, $ids );
			$this->assertStringNotContainsString( $this->marker( $id ), (string) $json );
			$this->assertStringNotContainsString( $this->marker( $id ), $archive );
		}
	}

	public function test_single_stream_rest_and_ability_surfaces_hide_every_sentinel(): void {
		[ $visible, $hidden ] = $this->seed_matrix();
		$viewed = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Viewed 2026', 'post_date' => '2026-05-09 12:00:00' ] );
		wp_set_object_terms( $viewed, 'note', Taxonomy::TAXONOMY );
		$this->go_to( get_permalink( $viewed ) );
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$single = On_This_Day::render_block( [ 'context' => 'single' ] );
		remove_filter( 'pkiw_otd_edge_purge', '__return_true' );

		$page = $this->stream_page();
		$page_html = $this->render_stream_page( $page );
		$start     = strpos( $page_html, '<li class="wp-block-post pkiw-otd-stream-item"' );
		$this->assertNotFalse( $start, 'The Stream page prints the On This Day card.' );
		// Only the card is an On This Day surface; the page's own list shows every post.
		$stream = substr( $page_html, (int) $start, (int) strpos( $page_html, '</li>', (int) $start ) + 5 - (int) $start );
		On_This_Day::reset();
		$this->go_to( home_url( '/' ) );
		$response = rest_do_request( '/wp/v2/pages/' . $page );
		$rest = (string) ( $response->get_data()['content']['rendered'] ?? '' );
		$ability = (string) wp_json_encode( On_This_Day_Abilities::instance()->execute() );
		$this->assertStringContainsString( $this->marker( $visible[0] ), $single );
		$this->assertStringContainsString( $this->marker( $visible[0] ), $stream );
		$this->assertStringContainsString( $this->marker( $visible[0] ), $ability );
		$this->assertStringNotContainsString( 'pkiw-otd', $rest );
		foreach ( $hidden as $id ) {
			// REST content.rendered holds the page's own Query Loop; its contract is "no card", asserted above.
			foreach ( [ $single, $stream, $ability ] as $surface ) {
				$this->assertStringNotContainsString( $this->marker( $id ), $surface );
			}
		}
	}

	public function test_visibility_does_not_change_for_an_administrator(): void {
		$id = $this->make( 'admin-private', 'private' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( On_This_Day::row_visible( get_post( $id ), On_This_Day::kinds() ) );
		wp_set_current_user( 0 );
		$this->assertFalse( On_This_Day::row_visible( get_post( $id ), On_This_Day::kinds() ) );
	}

	public function test_ids_are_identical_for_anonymous_administrator_and_cli_mode(): void {
		$this->seed_matrix();
		wp_set_current_user( 0 );
		$anonymous = array_column( On_This_Day::build_rows( $this->today ), 'id' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$administrator = array_column( On_This_Day::build_rows( $this->today ), 'id' );
		wp_set_current_user( 0 );
		Public_Eligibility::override_cli_for_tests( true );
		$cli = array_column( On_This_Day::build_rows( $this->today ), 'id' );
		$this->assertSame( $anonymous, $administrator );
		$this->assertSame( $anonymous, $cli );
	}

	public function test_archive_item_has_no_excerpt_or_visible_date_and_one_link(): void {
		$id = $this->make( 'markup', 'publish' );
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => 'Stored private-looking excerpt' ] );
		$html = On_This_Day::item_html( get_post( $id ), true, 'note' );
		$this->assertSame( 1, substr_count( $html, '<a' ) );
		$this->assertStringContainsString( 'dt-published', $html );
		$this->assertStringContainsString( ' hidden', $html );
		$this->assertStringNotContainsString( 'Stored private-looking excerpt', $html );
		$this->assertStringNotContainsString( '<li', $html );
	}

	public function test_rest_and_feeds_never_embed_on_this_day_markup(): void {
		$this->make( 'feed-control', 'publish', '', null, 'note', '2025-05-09 23:30:00' );
		$viewed = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Feed viewed', 'post_content' => 'Feed body', 'post_date' => '2026-05-09 12:00:00' ] );
		$this->go_to( get_permalink( $viewed ) );
		$GLOBALS['post'] = get_post( $viewed );
		setup_postdata( $GLOBALS['post'] );
		$GLOBALS['wp_query']->is_feed = true;
		$post_feed = get_the_content_feed( 'rss2' );

		$page = $this->stream_page();
		$this->go_to( get_permalink( $page ) );
		$GLOBALS['post'] = get_post( $page );
		setup_postdata( $GLOBALS['post'] );
		$GLOBALS['wp_query']->is_feed = true;
		On_This_Day::override_headers_for_tests( static function (): void {} );
		On_This_Day::send_day_headers();
		$page_feed = get_the_content_feed( 'rss2' );
		$this->assertStringNotContainsString( 'pkiw-otd', $post_feed );
		$this->assertStringNotContainsString( 'pkiw-otd', $page_feed );

		On_This_Day::reset();
		$this->go_to( home_url( '/' ) );
		$response = rest_do_request( '/wp/v2/pages/' . $page );
		$this->assertStringNotContainsString( 'pkiw-otd', (string) ( $response->get_data()['content']['rendered'] ?? '' ) );
	}

	public function test_titles_hide_venue_data_and_no_excerpt_is_synthesized(): void {
		$include_checkin = static function ( array $kinds ): array {
			$kinds[] = 'checkin';
			return $kinds;
		};
		add_filter( 'pkiw_on_this_day_kinds', $include_checkin );
		$venue = 'OTD268-HIDDEN-VENUE';
		$titled = $this->make( 'title-location', 'publish', '', null, 'checkin', '2025-05-09 23:50:00', 'Checked in at ' . $venue );
		update_post_meta( $titled, '_pkiw_checkin_name', $venue );
		update_post_meta( $titled, '_pkiw_geo_privacy', 'private' );
		Title_Privacy::mark_location_title( $titled );
		wp_update_post(
			[
				'ID'           => $titled,
				'post_excerpt' => 'Coffee at ' . $venue,
			]
		);
		$untitled = $this->make( 'untitled-location', 'publish', '', null, 'checkin', '2024-05-09 23:50:00', '' );
		wp_update_post( [ 'ID' => $untitled, 'post_content' => $venue ] );
		update_post_meta( $untitled, '_pkiw_checkin_name', $venue );
		update_post_meta( $untitled, '_pkiw_geo_privacy', 'private' );

		$viewed = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Viewed for safe title', 'post_date' => '2026-05-09 12:00:00' ] );
		$this->go_to( get_permalink( $viewed ) );
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$single = On_This_Day::render_block( [ 'context' => 'single' ] );
		$archive = $this->archive_html();
		$ability = (string) wp_json_encode( On_This_Day_Abilities::instance()->execute() );
		$this->assertStringContainsString( 'Check-in, May 9, 2025', $single );
		$this->assertStringNotContainsString( $venue, $single . $archive . $ability );
		$this->assertStringNotContainsString( 'pkiw-otd__excerpt', $single );
	}

	public function test_counts_do_not_change_when_hidden_posts_are_added(): void {
		$this->make( 'count-control', 'publish', '', null, 'note', '2025-05-09 23:00:00' );
		$before = On_This_Day_Abilities::instance()->execute();
		$this->make( 'count-private', 'private' );
		$this->make( 'count-password', 'publish', 'secret' );
		$this->make( 'count-quiet', 'publish', '', 'quiet_public' );
		$after = On_This_Day_Abilities::instance()->execute();
		$this->assertSame( $before['total'], $after['total'] );
		$this->assertSame( array_column( $before['groups'], 'year' ), array_column( $after['groups'], 'year' ) );
		$this->assertSame( array_map( static fn( array $group ): int => count( $group['items'] ), $before['groups'] ), array_map( static fn( array $group ): int => count( $group['items'] ), $after['groups'] ) );
	}

	private function make( string $case, string $status, string $password = '', string|array|null $visibility = null, string $kind = 'note', string $date = '2025-05-09 10:00:00', ?string $title = null ): int {
		$actual = 'trash' === $status ? 'publish' : $status;
		$date = 'future' === $status ? '2027-05-09 10:00:00' : $date;
		$id = self::factory()->post->create( [ 'post_status' => $actual, 'post_password' => $password, 'post_title' => $title ?? 'OTD268-' . $case, 'post_content' => '', 'post_date' => $date ] );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		foreach ( (array) $visibility as $value ) {
			add_post_meta( $id, Public_Listing::meta_keys()[0], $value );
		}
		if ( 'trash' === $status ) {
			wp_trash_post( $id );
		}
		return $id;
	}

	private function seed_matrix(): array {
		$include_governed = static function ( array $kinds ): array {
			return array_values( array_unique( array_merge( $kinds, [ 'follow', 'tag' ] ) ) );
		};
		add_filter( 'pkiw_on_this_day_kinds', $include_governed );
		$visible = [
			$this->make( 'control', 'publish', '', null, 'note', '2025-05-09 23:00:00' ),
			$this->make( 'local', 'publish', '', 'local', 'note', '2025-05-09 22:00:00' ),
			$this->make( 'no-meta', 'publish', '', null, 'note', '2025-05-09 21:00:00' ),
		];
		$hidden = [
			$this->make( 'private', 'private' ),
			$this->make( 'draft', 'draft' ),
			$this->make( 'pending', 'pending' ),
			$this->make( 'future', 'future' ),
			$this->make( 'password', 'publish', 'secret' ),
			$this->make( 'trash', 'trash' ),
			$this->make( 'quiet-public', 'publish', '', 'quiet_public' ),
			$this->make( 'quiet-public-two-rows', 'publish', '', [ '', 'quiet_public' ] ),
			$this->make( 'kind-drink', 'publish', '', null, 'drink' ),
			$this->make( 'follow-hidden', 'publish', 'secret', null, 'follow' ),
			$this->make( 'tag-hidden', 'private', '', null, 'tag' ),
			$this->make( 'sticky-other-date', 'publish', '', null, 'note', '2025-05-08 23:00:00' ),
		];
		stick_post( end( $hidden ) );
		return [ $visible, $hidden ];
	}

	private function marker( int $id ): string {
		return (string) get_post_field( 'post_title', $id );
	}

	private function archive_html(): string {
		$GLOBALS['wp_query'] = new WP_Query( On_This_Day::query_args( $this->today, [ 'pkiw_group_source' => On_This_Day::SOURCE, 'pkiw_group_order' => 'DESC', 'posts_per_page' => 50 ] ) );
		$markup = '<!-- wp:query {"queryId":7,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:' . On_This_Day::ENTRY_BLOCK . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		return do_blocks( $markup );
	}

	private function stream_page(): int {
		$content = '<!-- wp:post-kinds-indieweb/on-this-day {"context":"stream"} /-->'
			. '<!-- wp:query {"queryId":1,"query":{"perPage":1,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":false}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Current filler', 'post_date' => '2026-05-08 12:00:00' ] );
		return self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Stream proof', 'post_content' => $content ] );
	}

	private function render_stream_page( int $page_id ): string {
		$this->go_to( get_permalink( $page_id ) );
		$GLOBALS['post'] = get_post( $page_id );
		setup_postdata( $GLOBALS['post'] );
		On_This_Day::override_headers_for_tests( static function (): void {} );
		On_This_Day::send_day_headers();
		$this->assertTrue( On_This_Day::is_uncached() );
		return (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $page_id ) );
	}
}
