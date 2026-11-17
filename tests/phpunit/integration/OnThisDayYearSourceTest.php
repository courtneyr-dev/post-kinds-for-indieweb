<?php
/** On This Day year source coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Grouping\Archive_Group;
use PKIW\Grouping\Date_Source;
use PKIW\Grouping\On_This_Day_Year_Source;
use PKIW\On_This_Day;
use PKIW\Public_Listing;
use PKIW\Taxonomy;

/** @group integration */
final class OnThisDayYearSourceTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		On_This_Day::register_blocks();
		On_This_Day::override_now( new DateTimeImmutable( '2026-06-01 12:00:00', wp_timezone() ) );
	}

	public function tear_down(): void {
		On_This_Day::override_now( null );
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_group_of_uses_the_stored_local_year(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$post = new WP_Post( (object) [ 'ID' => 268, 'post_date' => '2025-12-31 23:30:00' ] );
		$this->assertSame( '2025', ( new On_This_Day_Year_Source() )->group_of( $post )->key() );
	}

	public function test_labels_cover_one_two_and_eleven_years(): void {
		On_This_Day::override_now( new DateTimeImmutable( '2026-06-01 12:00:00', wp_timezone() ) );
		$source = new On_This_Day_Year_Source();
		$this->assertSame( '1 year ago · 2025', $source->label( new Archive_Group( '2025', '2025' ), [] ) );
		$this->assertSame( '2 years ago · 2024', $source->label( new Archive_Group( '2024', '2024' ), [] ) );
		$this->assertSame( '11 years ago · 2015', $source->label( new Archive_Group( '2015', '2015' ), [] ) );
		$this->assertSame( '', $source->empty_label(), 'An empty year must not create a heading.' );
	}

	public function test_date_source_contract_is_unchanged(): void {
		$post = get_post( self::factory()->post->create( [ 'post_date' => '2025-01-01 00:00:00' ] ) );
		$this->assertSame( '2025', ( new Date_Source( 'control-year', 'year' ) )->group_of( $post )->key() );
		$this->assertTrue( ( new ReflectionClass( Date_Source::class ) )->isFinal() );
	}

	public function test_page_two_repeats_continuing_year_heading(): void {
		foreach ( [ '12:00:00', '11:00:00', '10:00:00' ] as $time ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Continuing ' . $time, 'post_date' => '2025-06-01 ' . $time ] );
			wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		}
		$GLOBALS['wp_query'] = new WP_Query( On_This_Day::query_args( On_This_Day::today(), [ 'pkiw_group_source' => On_This_Day::SOURCE, 'pkiw_group_order' => 'DESC', 'posts_per_page' => 2, 'paged' => 2 ] ) );
		$html = $this->render_archive();
		$this->assertStringContainsString( '1 year ago · 2025', $html );
		$this->assertSame( 1, substr_count( $html, 'id="pkiw-group-2025"' ) );
		$this->assertStringContainsString( 'Continuing 10:00:00', $html );
	}

	public function test_year_with_no_eligible_row_prints_no_heading(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Hidden empty year', 'post_date' => '2024-06-01 10:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		update_post_meta( $id, Public_Listing::meta_keys()[0], 'quiet_public' );
		$GLOBALS['wp_query'] = new WP_Query( On_This_Day::query_args( On_This_Day::today(), [ 'pkiw_group_source' => On_This_Day::SOURCE, 'pkiw_group_order' => 'DESC', 'posts_per_page' => 2 ] ) );
		$html = $this->render_archive();
		$this->assertStringNotContainsString( 'pkiw-group-2024', $html );
		$this->assertStringNotContainsString( 'Hidden empty year', $html );
	}

	private function render_archive(): string {
		$markup = '<!-- wp:query {"queryId":7,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:' . On_This_Day::ENTRY_BLOCK . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		return do_blocks( $markup );
	}
}
