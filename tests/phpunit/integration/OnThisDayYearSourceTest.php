<?php
/** On This Day year source coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Grouping\Archive_Group;
use PKIW\Grouping\Date_Source;
use PKIW\Grouping\On_This_Day_Year_Source;
use PKIW\On_This_Day;

/** @group integration */
final class OnThisDayYearSourceTest extends WP_UnitTestCase {
	public function tear_down(): void {
		On_This_Day::override_now( null );
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
}
