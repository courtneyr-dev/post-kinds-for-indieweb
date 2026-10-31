<?php
/**
 * Sentinel privacy coverage for every new RSVP archive surface.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Abilities\Rsvp_Abilities;
use PKIW\Grouping\Rsvp_Timing_Source;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Operations;
use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class RsvpArchivePrivacyTest extends WP_UnitTestCase {

	private const LOCATION = 'Rv251 Sentinel Hall, 1 Sentinel Way';
	private const EVENT    = 'https://events.example/rv251-sentinel';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Rsvp_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	private function card( string $status, string $start, string $visibility ): string {
		return '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode(
			[
				'eventName'          => 'Sentinel event',
				'eventUrl'           => self::EVENT,
				'eventStart'         => $start,
				'eventLocation'      => self::LOCATION,
				'locationVisibility' => $visibility,
				'rsvpStatus'         => $status,
			]
		) . ' /-->';
	}

	private function rsvp( string $status, string $start, string $visibility, ?string $row, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Sentinel RSVP',
					'post_content' => $this->card( $status, $start, $visibility ),
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'rsvp', Taxonomy::TAXONOMY );
		if ( null !== $row ) {
			update_post_meta( $id, '_pkiw_rsvp_location_privacy', $row );
		}
		Rsvp_Archive::refresh_sort( $id );

		return (int) $id;
	}

	/**
	 * Build acceptance 11's private, protected and public shapes.
	 *
	 * @return array{readable:int[],protected:int,public:int,cardless:int}
	 */
	private function fixtures(): array {
		$private_future = $this->rsvp( 'yes', '2027-10-08T18:00', 'private', 'private' );
		$private_past   = $this->rsvp( 'no', '2025-10-08T18:00', 'private', 'private' );
		$geo_private    = $this->rsvp( 'remote', '2027-10-09T18:00', 'public', 'public' );
		update_post_meta( $geo_private, '_pkiw_geo_privacy', 'private' );
		$missing_row = $this->rsvp( 'maybe', '2025-10-09T18:00', 'public', null );
		delete_post_meta( $missing_row, '_pkiw_rsvp_location_privacy' );
		$this->assertFalse( metadata_exists( 'post', $missing_row, '_pkiw_rsvp_location_privacy' ) );

		$cardless = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Card-less sentinel RSVP',
				'post_content' => '<p>Quick RSVP.</p>',
			]
		);
		wp_set_object_terms( $cardless, 'rsvp', Taxonomy::TAXONOMY );
		update_post_meta( $cardless, '_pkiw_event_url', self::EVENT );
		update_post_meta( $cardless, '_pkiw_event_location', self::LOCATION );
		update_post_meta( $cardless, '_pkiw_rsvp_value', 'yes' );

		$protected = $this->rsvp( 'yes', '2027-10-10T18:00', 'public', 'public', [ 'post_password' => 'pass' ] );
		$public    = $this->rsvp( 'interested', '2027-10-11T18:00', 'public', 'public' );
		update_post_meta( $public, '_pkiw_geo_privacy', 'public' );

		return [
			'readable'  => [ $private_future, $private_past, $geo_private, $missing_row, (int) $cardless ],
			'protected' => $protected,
			'public'    => $public,
			'cardless'  => (int) $cardless,
		];
	}

	private function archive_html(): string {
		$this->go_to( (string) get_term_link( 'rsvp', Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-rsvp.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-rsvp', $template->id );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	private function stream_html( int $post_id ): string {
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		try {
			return \PKIW\render_stream_card();
		} finally {
			wp_reset_postdata();
		}
	}

	private function hidden_mf2( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		try {
			return apply_filters( 'the_content', get_the_content( null, false, get_post( $post_id ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		} finally {
			wp_reset_postdata();
		}
	}

	/** @return array<string, mixed> */
	private function rest_post( int $post_id ): array {
		$GLOBALS['wp_rest_server'] = null;
		return (array) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) )->get_data();
	}

	private function kind_feed_entry( int $post_id ): string {
		$term = get_term_by( 'slug', 'rsvp', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->go_to( get_term_feed_link( $term->term_id, Taxonomy::TAXONOMY ) );
		$this->assertTrue( is_feed(), 'The RSVP kind feed context did not take.' );
		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $post_id ) {
				return get_the_content_feed( 'rss2' );
			}
		}
		$this->fail( sprintf( 'RSVP %d never appeared in the kind feed.', $post_id ) );
	}

	/** @return array<string, mixed> */
	private function mcp_editor_meta( int $post_id ): array {
		$this->assertTrue( function_exists( 'wp_get_ability' ), 'The Abilities API is required for the MCP execution-path assertion.' );
		$ability = wp_get_ability( 'post-kinds/get-post-meta' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$result = $ability->execute( [ 'post_id' => $post_id ] );
		$this->assertIsArray( $result );

		return $result;
	}

	private function assert_readable_markup( string $html, string $surface ): void {
		$this->assertStringNotContainsString( self::LOCATION, $html, $surface . ' leaked the location.' );
		$this->assertStringContainsString( self::EVENT, $html, $surface . ' did not render the RSVP.' );
		$this->assertStringContainsString( 'u-in-reply-to', $html, $surface . ' lacks the presence-control property.' );
	}

	public function test_every_nonpublic_shape_omits_the_sentinel_for_visitors_and_editors(): void {
		$fixtures = $this->fixtures();
		$editor   = self::factory()->user->create( [ 'role' => 'editor' ] );

		foreach ( [ 'visitor' => 0, 'editor' => $editor ] as $reader => $user_id ) {
			wp_set_current_user( $user_id );
			$archive = $this->archive_html();
			$this->assertStringNotContainsString( self::LOCATION, $archive, $reader . ' archive.' );
			$this->assertSame( 6, substr_count( $archive, 'u-in-reply-to' ), $reader . ' archive readable-entry count.' );

			foreach ( $fixtures['readable'] as $post_id ) {
				$this->assert_readable_markup( Rsvp_Archive::entry_html( get_post( $post_id ), [ 'headingLevel' => 2 ], true ), $reader . ' entry ' . $post_id );
				$this->assert_readable_markup( $this->stream_html( $post_id ), $reader . ' Stream ' . $post_id );
				$facts = Kind_Facts::get( $post_id );
				$this->assertSame( self::EVENT, $facts['url'] ?? '', $reader . ' Kind_Facts presence ' . $post_id );
				$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( $facts ), $reader . ' Kind_Facts ' . $post_id );
				$ability = Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $post_id ] );
				$this->assertIsArray( $ability );
				$this->assertSame( self::EVENT, $ability['url'], $reader . ' RSVP ability presence ' . $post_id );
				$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( $ability ), $reader . ' RSVP ability ' . $post_id );
				$meta = 0 === $user_id
					? Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $post_id ] )
					: $this->mcp_editor_meta( $post_id );
				$this->assertIsArray( $meta );
				$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( $meta ), $reader . ' get-post-meta ' . $post_id );
				if ( $fixtures['cardless'] === $post_id ) {
					$this->assertArrayHasKey( 'event_location', $meta['meta'] );
					$this->assertSame( '', $meta['meta']['event_location'], $reader . ' card-less location redaction.' );
				}
				$this->assert_readable_markup( $this->kind_feed_entry( $post_id ), $reader . ' kind feed ' . $post_id );
				$this->assert_readable_markup( $this->hidden_mf2( $post_id ), $reader . ' hidden mf2 ' . $post_id );
			}
			$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( Operations::backfill_rsvp_meta( true, null ) ), $reader . ' backfill dry run.' );
		}

		wp_set_current_user( 0 );
		foreach ( $fixtures['readable'] as $post_id ) {
			$rest = $this->rest_post( $post_id );
			$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( $rest ), 'Visitor REST ' . $post_id );
			$this->assert_readable_markup( (string) ( $rest['content']['rendered'] ?? '' ), 'Visitor REST content ' . $post_id );
			if ( $fixtures['cardless'] === $post_id ) {
				$this->assertSame( '', $rest['meta']['_pkiw_event_location'] ?? null, 'Visitor REST must blank the stored card-less sentinel.' );
			}
		}
		$this->assertSame( self::LOCATION, get_post_meta( $fixtures['cardless'], '_pkiw_event_location', true ), 'The card-less sentinel stays stored.' );
	}

	public function test_protected_future_is_past_in_sql_archive_and_stream_without_private_facts(): void {
		$fixtures = $this->fixtures();
		$id       = $fixtures['protected'];
		$entry    = Rsvp_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true );

		$this->assertSame( 'past', Rsvp_Archive::group_of_post( get_post( $id ) ) );
		$source = new Rsvp_Timing_Source();
		$sql    = $source->sql( new WP_Query() );
		global $wpdb;
		$group = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Contract probe of the source's real SQL expression.
			$wpdb->prepare(
				"SELECT {$sql['value']} FROM {$wpdb->posts} WHERE ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The expression comes from the source; the ID is prepared.
				$id
			)
		);
		$this->assertSame( 'past', $group, 'The protected RSVP must file under Past in SQL too.' );
		$this->assertSame( [], Kind_Facts::get( $id ) );
		$this->assertStringContainsString( 'pkiw-rsvp-entry--protected', $entry );
		foreach ( [ self::LOCATION, self::EVENT, 'pkiw-rsvp-entry__day', 'p-rsvp', '<figure', 'p-author' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $entry );
		}

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			$stream = $this->stream_html( $id );
			$this->assertStringContainsString( 'pk-card--protected', $stream );
			$this->assertStringNotContainsString( self::LOCATION, $stream );
			$this->assertStringNotContainsString( self::EVENT, $stream );
			$meta = 0 === $user_id
				? Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] )
				: $this->mcp_editor_meta( $id );
			$this->assertIsArray( $meta );
			$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( $meta ) );
			foreach ( [ $this->kind_feed_entry( $id ), $this->hidden_mf2( $id ) ] as $surface ) {
				$this->assertStringNotContainsString( self::LOCATION, $surface );
				$this->assertStringNotContainsString( self::EVENT, $surface );
			}
		}

		wp_set_current_user( 0 );
		$rest = $this->rest_post( $id );
		$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( $rest ) );
		$this->assertStringNotContainsString( self::EVENT, (string) wp_json_encode( $rest ) );
		$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( Operations::backfill_rsvp_meta( true, null ) ) );

		$error = Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $id ] );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'forbidden', $error->get_error_code() );
	}

	public function test_all_public_location_appears_only_on_the_location_bearing_surfaces(): void {
		$fixtures = $this->fixtures();
		$id       = $fixtures['public'];

		$this->assertSame( self::LOCATION, Kind_Facts::get( $id )['location'] );
		$this->assertSame( self::LOCATION, Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $id ] )['location'] );
		$parsed = parse_blocks( (string) get_post_field( 'post_content', $id ) );
		$single = ( new WP_Block( $parsed[0], [ 'postId' => $id ] ) )->render();
		$this->assertStringContainsString( self::LOCATION, $single );

		foreach ( [ 'archive' => $this->archive_html(), 'Stream' => $this->stream_html( $id ) ] as $surface => $html ) {
			$this->assertStringContainsString( self::EVENT, $html, $surface . ' presence control.' );
			$this->assertStringNotContainsString( self::LOCATION, $html, $surface . ' must never print the location.' );
		}
		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_event_location' ), 'The card location must not gain a meta row.' );
		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			$meta = 0 === $user_id
				? Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] )
				: $this->mcp_editor_meta( $id );
			$this->assertIsArray( $meta );
			$this->assertArrayNotHasKey( 'event_location', $meta['meta'] );
			$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( $meta ) );
		}
		$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( Operations::backfill_rsvp_meta( true, null ) ) );
	}
}
