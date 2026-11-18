<?php
/** On This Day query coverage. @package PKIW */
declare(strict_types=1);

use PKIW\On_This_Day;
use PKIW\Public_Eligibility;
use PKIW\Public_Listing;
use PKIW\Taxonomy;

/** @group integration */
final class OnThisDayQueryTest extends WP_UnitTestCase {
	private DateTimeImmutable $today;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->today = new DateTimeImmutable( '2026-03-14 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
	}

	public function tear_down(): void {
		On_This_Day::override_now( null );
		remove_all_filters( 'pkiw_on_this_day_kinds' );
		parent::tear_down();
	}

	public function test_only_eligible_rows_return_in_year_date_and_id_order(): void {
		$older = $this->post( 'Older', '2023-03-14 22:00:00', 'note' );
		$newer_low = $this->post( 'Newer low', '2025-03-14 09:00:00', 'note' );
		$newer_high = $this->post( 'Newer high', '2025-03-14 09:00:00', 'note' );
		$current = $this->post( 'Current', '2026-03-14 10:00:00', 'note' );
		$wrong_day = $this->post( 'Wrong day', '2024-03-13 23:59:00', 'note' );
		$no_kind = $this->post( 'No kind', '2024-03-14 12:00:00' );
		$local = $this->post( 'Local', '2022-03-14 12:00:00', 'note' );
		add_post_meta( $local, Public_Listing::meta_keys()[0], 'local' );
		$hidden = $this->post( 'Hidden', '2021-03-14 12:00:00', 'note' );
		add_post_meta( $hidden, Public_Listing::meta_keys()[0], 'quiet_public' );

		$ids = $this->ids();
		$this->assertSame( [ $newer_high, $newer_low, $no_kind, $older, $local ], $ids );
		$this->assertNotContains( $current, $ids );
		$this->assertNotContains( $wrong_day, $ids );
		$this->assertNotContains( $hidden, $ids );
	}

	public function test_kind_filter_none_and_sticky_contracts(): void {
		$none = $this->post( 'No kind', '2025-03-14 12:00:00' );
		$drink = $this->post( 'Drink', '2024-03-14 12:00:00', 'drink' );
		$sticky = $this->post( 'Sticky wrong date', '2025-03-13 12:00:00', 'note' );
		stick_post( $sticky );
		$this->assertContains( $none, $this->ids() );
		$this->assertNotContains( $drink, $this->ids() );
		$this->assertNotContains( $sticky, $this->ids() );

		$without_none = static fn(): array => [ 'note' ];
		add_filter( 'pkiw_on_this_day_kinds', $without_none );
		$this->assertNotContains( $none, $this->ids() );
		remove_filter( 'pkiw_on_this_day_kinds', $without_none );

		$with_drink = static fn( array $kinds ): array => [ ...$kinds, 'drink' ];
		add_filter( 'pkiw_on_this_day_kinds', $with_drink );
		$this->assertContains( $drink, $this->ids() );
	}

	public function test_february_29_and_site_local_dates_use_stored_local_post_date(): void {
		foreach ( [ 'UTC', 'America/New_York', 'Asia/Kolkata' ] as $timezone ) {
			update_option( 'timezone_string', $timezone );
			$zone = new DateTimeZone( $timezone );
			On_This_Day::override_now( new DateTimeImmutable( '2028-02-29 12:00:00', $zone ) );
			$late = $this->post( 'Late ' . $timezone, '2024-02-29 23:30:00', 'note' );
			$early = $this->post( 'Early ' . $timezone, '2024-03-01 00:30:00', 'note' );
			$this->assertContains( $late, $this->ids() );
			$this->assertNotContains( $early, $this->ids() );
			On_This_Day::override_now( new DateTimeImmutable( '2028-03-01 12:00:00', $zone ) );
			$this->assertContains( $early, $this->ids() );
			$this->assertNotContains( $late, $this->ids() );
		}
	}

	public function test_governed_hidden_rows_and_both_sql_predicates_remain_active(): void {
		global $wpdb;
		$follow = $this->post( 'Protected follow', '2025-03-14 12:00:00', 'follow', [ 'post_password' => 'secret' ] );
		$tag = $this->post( 'Private tag', '2024-03-14 12:00:00', 'tag', [ 'post_status' => 'private' ] );
		$filter = static fn( array $kinds ): array => [ ...$kinds, 'follow', 'tag' ];
		add_filter( 'pkiw_on_this_day_kinds', $filter );
		$query = new WP_Query( On_This_Day::query_args( $this->today, [ 'posts_per_page' => 20 ] ) );
		$this->assertNotContains( $follow, wp_list_pluck( $query->posts, 'ID' ) );
		$this->assertNotContains( $tag, wp_list_pluck( $query->posts, 'ID' ) );
		$this->assertStringContainsString( Public_Eligibility::sql( $wpdb->posts ), $query->request );
		$this->assertStringContainsString( 'NOT EXISTS ( SELECT 1 FROM', $query->request );
		$this->assertStringContainsString( Public_Listing::meta_keys()[0], $query->request );
	}

	public function test_implementation_and_eval_keep_visibility_literals_in_the_shared_helper_only(): void {
		$root = dirname( __DIR__, 3 );
		$files = [
			'includes/class-on-this-day.php',
			'includes/grouping/class-on-this-day-year-source.php',
			'includes/abilities/class-on-this-day-abilities.php',
		];
		foreach ( $files as $file ) {
			$text = (string) file_get_contents( $root . '/' . $file );
			$this->assertStringNotContainsString( self::visibility_key(), $text, $file );
			$this->assertStringNotContainsString( self::hidden_value(), $text, $file );
		}
		$eval = (string) file_get_contents( $root . '/tests/phpunit/fixtures/privacy-evals/x-268-on-this-day.php' );
		$this->assertStringNotContainsString( self::visibility_key(), $eval, 'The eval must obtain the key through Public_Listing::meta_keys().' );
	}

	private static function visibility_key(): string {
		return 'activitypub_' . 'content_visibility';
	}

	private static function hidden_value(): string {
		return 'quiet_' . 'public';
	}

	private function ids(): array {
		return array_map( 'intval', ( new WP_Query( On_This_Day::query_args( On_This_Day::today(), [ 'fields' => 'ids', 'posts_per_page' => -1 ] ) ) )->posts );
	}

	private function post( string $title, string $date, string $kind = '', array $extra = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_title' => $title, 'post_status' => 'publish', 'post_date' => $date ], $extra ) );
		if ( '' !== $kind ) {
			wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		}
		return $id;
	}
}
