<?php
/**
 * Output of the operational WP-CLI subcommands.
 *
 * The expected transcripts below were captured from CLI_Commands before the
 * subcommands were routed through PKIW\Operations. They pin the text, the
 * order of calls and the arguments handed to WP-CLI, which is everything
 * that decides what the shell prints. See tests/phpunit/fixtures/wp-cli-stub.php.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\CLI_Commands;
use PKIW\Featured_Artwork;
use PKIW\Taxonomy;
use PKIW\Venue_Taxonomy;

require_once dirname( __DIR__ ) . '/fixtures/wp-cli-stub.php';
require_once dirname( __DIR__, 3 ) . '/includes/class-cli-commands.php';

/**
 * @group integration
 * @covers \PKIW\CLI_Commands
 */
final class CliCommandsOutputTest extends WP_UnitTestCase {

	private const RULE = '────────────────────────────────────────';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->register_taxonomy();
		$venues = new Venue_Taxonomy();
		$venues->register_taxonomy();
		$venues->register_term_meta();
		foreach ( [ 'checkin', 'listen', 'note' ] as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			}
		}
		WP_CLI::reset();
	}

	/**
	 * Run a command callable and return the recorded WP-CLI transcript.
	 *
	 * @param callable $command Command invocation.
	 * @return array<int, array{0:string,1:mixed}>
	 */
	private function transcript( callable $command ): array {
		WP_CLI::reset();
		try {
			$command();
		} catch ( WP_CLI_Stub_Halt $halt ) {
			unset( $halt );
		}

		return WP_CLI::$calls;
	}

	private function checkin_post(): int {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'checkin', 'kind' );

		return $post_id;
	}

	// ─── checkin-stats ───

	public function test_checkin_stats_with_no_checkins_or_venues(): void {
		$expected = [
			[ 'log', '' ],
			[ 'colorize', '%BCheck-in Statistics%n' ],
			[ 'log', '%BCheck-in Statistics%n' ],
			[ 'log', self::RULE ],
			[ 'log', '' ],
			[ 'log', 'Posts with checkin kind: 0' ],
			[ 'log', 'Venue terms: 0' ],
			[ 'log', '' ],
		];

		$this->assertSame( $expected, $this->transcript( fn() => ( new CLI_Commands() )->checkin_stats( [], [] ) ) );
	}

	public function test_checkin_stats_counts_published_checkins_and_every_venue_term(): void {
		$this->checkin_post();
		$this->checkin_post();
		$this->checkin_post();
		$drafted = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_object_terms( $drafted, 'checkin', 'kind' );
		Venue_Taxonomy::create_or_get( [ 'name' => 'Corner Cafe' ] );
		Venue_Taxonomy::create_or_get( [ 'name' => 'Riverside Park' ] );

		$expected = [
			[ 'log', '' ],
			[ 'colorize', '%BCheck-in Statistics%n' ],
			[ 'log', '%BCheck-in Statistics%n' ],
			[ 'log', self::RULE ],
			[ 'log', '' ],
			[ 'log', 'Posts with checkin kind: 3' ],
			[ 'log', 'Venue terms: 2' ],
			[ 'log', '' ],
		];

		$this->assertSame( $expected, $this->transcript( fn() => ( new CLI_Commands() )->checkin_stats( [], [] ) ) );
	}

	public function test_checkin_stats_prints_zero_venues_when_the_taxonomy_is_missing(): void {
		$this->checkin_post();
		unregister_taxonomy( Venue_Taxonomy::TAXONOMY );

		$expected = [
			[ 'log', '' ],
			[ 'colorize', '%BCheck-in Statistics%n' ],
			[ 'log', '%BCheck-in Statistics%n' ],
			[ 'log', self::RULE ],
			[ 'log', '' ],
			[ 'log', 'Posts with checkin kind: 1' ],
			[ 'log', 'Venue terms: 0' ],
			[ 'log', '' ],
		];

		$this->assertSame( $expected, $this->transcript( fn() => ( new CLI_Commands() )->checkin_stats( [], [] ) ) );
	}

	// ─── venues ───

	public function test_venues_warns_when_there_are_none(): void {
		$this->assertSame(
			[ [ 'warning', 'No venues found.' ] ],
			$this->transcript( fn() => ( new CLI_Commands() )->venues( [], [] ) )
		);
	}

	public function test_venues_hands_wp_cli_rows_with_dashes_for_empty_values(): void {
		$full  = Venue_Taxonomy::create_or_get(
			[
				'name'      => 'Central Park',
				'city'      => 'New York',
				'country'   => 'USA',
				'latitude'  => '40.7829',
				'longitude' => '-73.9654',
			]
		);
		$bare  = Venue_Taxonomy::create_or_get( [ 'name' => 'Back Alley' ] );
		$zero  = Venue_Taxonomy::create_or_get(
			[
				'name'      => 'Null Island',
				'latitude'  => '0',
				'longitude' => '0',
			]
		);
		$post  = $this->checkin_post();
		wp_set_object_terms( $post, [ (int) $full ], Venue_Taxonomy::TAXONOMY );

		$expected = [
			[
				'format_items',
				[
					'format' => 'table',
					'items'  => [
						[
							'id'        => (int) $bare,
							'name'      => 'Back Alley',
							'slug'      => 'back-alley',
							'city'      => '-',
							'country'   => '-',
							'latitude'  => '-',
							'longitude' => '-',
							'count'     => 0,
						],
						[
							'id'        => (int) $full,
							'name'      => 'Central Park',
							'slug'      => 'central-park',
							'city'      => 'New York',
							'country'   => 'USA',
							'latitude'  => '40.7829',
							'longitude' => '-73.9654',
							'count'     => 1,
						],
						[
							'id'        => (int) $zero,
							'name'      => 'Null Island',
							'slug'      => 'null-island',
							'city'      => '-',
							'country'   => '-',
							'latitude'  => '-',
							'longitude' => '-',
							'count'     => 0,
						],
					],
					'fields' => [ 'id', 'name', 'city', 'country', 'latitude', 'longitude', 'count' ],
				],
			],
		];

		$this->assertSame( $expected, $this->transcript( fn() => ( new CLI_Commands() )->venues( [], [] ) ) );
	}

	public function test_venues_passes_the_requested_format_through(): void {
		Venue_Taxonomy::create_or_get( [ 'name' => 'Back Alley' ] );

		$calls = $this->transcript( fn() => ( new CLI_Commands() )->venues( [], [ 'format' => 'json' ] ) );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'format_items', $calls[0][0] );
		$this->assertSame( 'json', $calls[0][1]['format'] );
	}

	public function test_venues_reports_a_term_query_error(): void {
		unregister_taxonomy( Venue_Taxonomy::TAXONOMY );

		$this->assertSame(
			[ [ 'error', 'Invalid taxonomy.' ] ],
			$this->transcript( fn() => ( new CLI_Commands() )->venues( [], [] ) )
		);
	}

	// ─── surfaces ───

	public function test_surfaces_rejects_anything_but_backfill(): void {
		$this->assertSame(
			[ [ 'error', 'Usage: wp postkind surfaces backfill' ] ],
			$this->transcript( fn() => ( new CLI_Commands() )->surfaces( [ 'nope' ], [] ) )
		);
	}

	public function test_surfaces_backfill_reports_the_post_count(): void {
		self::factory()->post->create_many( 3 );

		$this->assertSame(
			[ [ 'success', 'Recomputed _pkiw_surface for 3 posts.' ] ],
			$this->transcript( fn() => ( new CLI_Commands() )->surfaces( [ 'backfill' ], [] ) )
		);
	}

	// ─── card-meta ───

	public function test_card_meta_rejects_anything_but_backfill(): void {
		$this->assertSame(
			[ [ 'error', 'Usage: wp postkind card-meta backfill [--batch=<size>]' ] ],
			$this->transcript( fn() => ( new CLI_Commands() )->card_meta( [], [] ) )
		);
	}

	public function test_card_meta_backfill_reports_posts_and_records_completion(): void {
		$content = '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"One"} /-->';
		self::factory()->post->create( [ 'post_content' => $content ] );
		self::factory()->post->create( [ 'post_content' => $content ] );
		self::factory()->post->create( [ 'post_content' => 'plain' ] );
		delete_option( \PKIW\Card_Meta_Sync::BACKFILL_OPTION );

		$calls = $this->transcript( fn() => ( new CLI_Commands() )->card_meta( [ 'backfill' ], [ 'batch' => '1' ] ) );

		$this->assertSame( [ [ 'success', 'Re-synced card meta for 2 post(s).' ] ], $calls );
		$this->assertSame( \PKIW\Card_Meta_Sync::BACKFILL_VERSION, get_option( \PKIW\Card_Meta_Sync::BACKFILL_OPTION ) );
		$this->assertFalse( get_option( \PKIW\Card_Meta_Sync::BACKFILL_CURSOR ) );
	}

	// ─── listen-media-type ───

	private function podcast_candidate( string $status = 'publish', string $password = '' ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => $status,
				'post_password' => $password,
			]
		);
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		update_post_meta( $post_id, '_pkiw_listen_artist', 'The Example Show' );
		update_post_meta( $post_id, '_pkiw_listen_album', 'The Example Show' );
		update_post_meta( $post_id, '_pkiw_source', 'Snipd' );

		return $post_id;
	}

	public function test_listen_media_type_rejects_anything_but_backfill(): void {
		$this->assertSame(
			[ [ 'error', 'Usage: wp postkind listen-media-type backfill [--dry-run] [--ids=<csv>]' ] ],
			$this->transcript( fn() => ( new CLI_Commands() )->listen_media_type( [], [] ) )
		);
	}

	public function test_listen_media_type_dry_run_lists_candidates_without_titles(): void {
		$one = $this->podcast_candidate();
		$two = $this->podcast_candidate( 'draft', 'secret' );

		$expected = [
			[ 'log', "ID {$one} status publish password no signals artist-equals-album,source" ],
			[ 'log', "ID {$two} status draft password yes signals artist-equals-album,source" ],
			[ 'success', 'Dry run: 2 candidate(s).' ],
		];

		$this->assertSame(
			$expected,
			$this->transcript( fn() => ( new CLI_Commands() )->listen_media_type( [ 'backfill' ], [ 'dry-run' => true ] ) )
		);
		$this->assertFalse( metadata_exists( 'post', $one, '_pkiw_listen_media_type' ) );
	}

	public function test_listen_media_type_refuses_to_write_without_ids(): void {
		$this->podcast_candidate();

		$this->assertSame(
			[ [ 'error', 'Refusing to write without --ids=<csv>. Run with --dry-run first.' ] ],
			$this->transcript( fn() => ( new CLI_Commands() )->listen_media_type( [ 'backfill' ], [] ) )
		);
	}

	public function test_listen_media_type_writes_only_the_listed_candidates(): void {
		$one = $this->podcast_candidate();
		$two = $this->podcast_candidate();

		$calls = $this->transcript(
			fn() => ( new CLI_Commands() )->listen_media_type( [ 'backfill' ], [ 'ids' => "{$one},99999" ] )
		);

		$this->assertSame( [ [ 'success', 'Backfill: 1 written, 1 skipped.' ] ], $calls );
		$this->assertSame( 'podcast', get_post_meta( $one, '_pkiw_listen_media_type', true ) );
		$this->assertFalse( metadata_exists( 'post', $two, '_pkiw_listen_media_type' ) );
	}

	// ─── featured-artwork ───

	public function test_featured_artwork_rejects_anything_but_backfill(): void {
		$this->assertSame(
			[ [ 'error', 'Usage: wp postkind featured-artwork backfill [--dry-run]' ] ],
			$this->transcript( fn() => ( new CLI_Commands() )->featured_artwork( [], [] ) )
		);
	}

	public function test_featured_artwork_dry_run_counts_without_fetching(): void {
		$requests = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$requests ) {
				++$requests;
				return $preempt;
			}
		);
		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"One","coverImage":"https://coverartarchive.org/release/abc/front-500.png"} /-->' ]
		);
		wp_set_object_terms( $post_id, 'listen', 'kind' );
		delete_post_meta( $post_id, Featured_Artwork::SOURCE_META );
		delete_post_thumbnail( $post_id );
		$requests = 0;

		$calls = $this->transcript( fn() => ( new CLI_Commands() )->featured_artwork( [ 'backfill' ], [ 'dry-run' => true ] ) );

		$this->assertSame( [ [ 'success', 'Dry run: 1 kind post(s) scanned, 1 would get a featured image, 0 skipped.' ] ], $calls );
		$this->assertSame( 0, $requests );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_featured_artwork_backfill_with_nothing_to_do(): void {
		$this->assertSame(
			[ [ 'success', '0 kind post(s) scanned, 0 featured image(s) set, 0 skipped, 0 failed.' ] ],
			$this->transcript( fn() => ( new CLI_Commands() )->featured_artwork( [ 'backfill' ], [] ) )
		);
	}
}
