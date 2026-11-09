<?php
/**
 * Event calendar asset gating.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Event_Calendar;

if ( ! function_exists( 'mc_enqueue_calendar_styles' ) ) {
	function mc_enqueue_calendar_styles( $stylesheet ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		wp_enqueue_style( 'my-calendar-style' );
	}
}

if ( ! function_exists( 'mc_enqueue_calendar_js' ) ) {
	function mc_enqueue_calendar_js() {
		$GLOBALS['pkiw_test_mc_js_enqueued'] = true;
	}
}

if ( ! function_exists( 'mc_get_option' ) ) {
	function mc_get_option( $key ) {
		return 'css_file' === $key ? 'twentyeighteen.css' : '';
	}
}

if ( ! function_exists( 'mc_get_style_path' ) ) {
	function mc_get_style_path( $file, $type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return 'https://example.org/my-calendar/' . $file;
	}
}

if ( ! function_exists( 'mc_footer_js' ) ) {
	function mc_footer_js() {}
}

/**
 * @group integration
 * @covers \PKIW\Event_Calendar
 */
final class EventCalendarAssetsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'pkiw_settings' );
		$this->reset_rendered();
		wp_register_style( 'my-calendar-lists', 'https://example.org/list-presets.css', [], '3.8.11' );
		wp_register_style( 'my-calendar-reset', 'https://example.org/reset.css', [ 'my-calendar-lists' ], '3.8.11' );
		wp_register_style( 'my-calendar-style', 'https://example.org/calendar.css', [ 'my-calendar-reset' ], '3.8.11' );
		add_filter( 'pkiw_calendar_source_active', '__return_true' );
	}

	public function tear_down(): void {
		foreach ( [ 'my-calendar-style', 'my-calendar-reset', 'my-calendar-lists' ] as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
		remove_all_filters( 'pkiw_calendar_source_active' );
		unset( $GLOBALS['pkiw_test_mc_js_enqueued'] );
		$this->reset_rendered();
		parent::tear_down();
	}

	private function reset_rendered(): void {
		$property = new ReflectionProperty( Event_Calendar::class, 'rendered' );
		$property->setValue( null, false );
	}

	private function enqueue_my_calendar_styles(): void {
		foreach ( [ 'my-calendar-style', 'my-calendar-reset', 'my-calendar-lists' ] as $handle ) {
			wp_enqueue_style( $handle );
		}
	}

	public function test_global_archive_css_and_footer_loader_are_disabled(): void {
		add_action( 'wp_footer', 'mc_footer_js' );
		( new Event_Calendar() )->register();

		$this->assertFalse( apply_filters( 'mc_display_css_on_archives', true ) );
		$this->assertFalse( has_action( 'wp_footer', 'mc_footer_js' ) );
		$this->assertSame( 21, has_action( 'wp_enqueue_scripts', [ Event_Calendar::class, 'dequeue_unrendered_assets' ] ) );
	}

	public function test_unrendered_calendar_dequeues_my_calendar_styles(): void {
		$this->enqueue_my_calendar_styles();
		Event_Calendar::dequeue_unrendered_assets();

		$this->assertFalse( wp_style_is( 'my-calendar-style', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'my-calendar-reset', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'my-calendar-lists', 'enqueued' ) );
	}

	public function test_rendered_calendar_keeps_my_calendar_styles_and_enqueues_scripts(): void {
		$this->enqueue_my_calendar_styles();
		Event_Calendar::render( [] );
		Event_Calendar::dequeue_unrendered_assets();

		$this->assertTrue( wp_style_is( 'my-calendar-style', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'my-calendar-reset', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'my-calendar-lists', 'enqueued' ) );
		$this->assertTrue( $GLOBALS['pkiw_test_mc_js_enqueued'] );
	}

	public function test_rucss_exclusions_cover_plugin_and_my_calendar_styles(): void {
		$excluded = Event_Calendar::exclude_rucss( [] );

		$this->assertContains( 'styles/event-calendar.css', $excluded );
		$this->assertContains( 'my-calendar/css/reset.css', $excluded );
		$this->assertContains( 'my-calendar/css/list-presets.css', $excluded );
	}
}
