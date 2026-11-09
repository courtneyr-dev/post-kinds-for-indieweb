<?php
/**
 * Search indexing rules for Event calendar URL variants.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Event_Calendar;
use PKIW\Taxonomy;

/**
 * @group integration
 * @covers \PKIW\Event_Calendar
 */
final class EventCalendarRobotsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		$_GET = [];
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	private function go_to_kind( string $kind ): void {
		$url = get_term_link( $kind, Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$this->go_to( $url );
	}

	public function test_each_calendar_variant_is_noindex_follow_and_sends_header(): void {
		$this->go_to_kind( Event_Calendar::KIND );

		foreach ( Event_Calendar::NOINDEX_VARS as $variable ) {
			$_GET = [ $variable => 'sentinel' ];
			$robots = Event_Calendar::robots( [] );

			$this->assertTrue( $robots['noindex'], $variable );
			$this->assertTrue( $robots['follow'], $variable );
			$this->assertSame( 'noindex, follow', Event_Calendar::robots_header_value(), $variable );
		}
	}

	public function test_bare_event_archive_stays_indexable(): void {
		$this->go_to_kind( Event_Calendar::KIND );
		$_GET = [];

		$this->assertSame( [], Event_Calendar::robots( [] ) );
		$this->assertNull( Event_Calendar::robots_header_value() );
	}

	public function test_other_kind_variant_stays_indexable(): void {
		$this->go_to_kind( 'note' );
		$_GET = [ 'month' => '11' ];

		$this->assertSame( [], Event_Calendar::robots( [] ) );
		$this->assertNull( Event_Calendar::robots_header_value() );
	}

	public function test_header_callback_runs_before_my_calendar_print_view(): void {
		$this->assertSame( 0, has_action( 'template_redirect', [ Event_Calendar::class, 'send_robots_header' ] ) );
	}
}
