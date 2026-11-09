<?php
/**
 * Event calendar dynamic block behavior.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Event_Calendar;

/**
 * @group integration
 * @covers \PKIW\Event_Calendar
 */
final class EventCalendarBlockTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'pkiw_settings' );
		Event_Calendar::register_blocks();
		add_shortcode(
			'my_calendar',
			static function ( $attributes ): string {
				return '<div class="mc-main" data-format="' . esc_attr( (string) ( $attributes['format'] ?? '' ) ) . '">Calendar output</div>';
			}
		);
	}

	public function tear_down(): void {
		delete_option( 'pkiw_settings' );
		remove_shortcode( 'my_calendar' );
		remove_all_filters( 'pkiw_calendar_source_active' );
		remove_all_filters( 'pkiw_event_calendar_enabled' );
		$property = new ReflectionProperty( Event_Calendar::class, 'rendered' );
		$property->setValue( null, false );
		parent::tear_down();
	}

	private function force_my_calendar( bool $active ): void {
		add_filter(
			'pkiw_calendar_source_active',
			static function ( bool $detected, string $source ) use ( $active ): bool {
				return 'my-calendar' === $source ? $active : $detected;
			},
			10,
			2
		);
	}

	public function test_block_registers_format_and_label_attributes(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Event_Calendar::BLOCK );

		$this->assertNotNull( $block );
		$this->assertSame( [ 'calendar', 'list', 'mini' ], $block->attributes['format']['enum'] );
		$this->assertSame( 'calendar', $block->attributes['format']['default'] );
		$this->assertSame( 'Event calendar', $block->attributes['label']['default'] );
		$this->assertSame( 'content', $block->attributes['label']['role'] );
	}

	public function test_render_returns_empty_when_provider_is_inactive(): void {
		$this->force_my_calendar( false );
		$this->assertSame( '', Event_Calendar::render( [] ) );
	}

	public function test_render_returns_empty_when_setting_is_off(): void {
		$this->force_my_calendar( true );
		update_option( 'pkiw_settings', [ Event_Calendar::SETTING_CALENDAR => false ] );
		$this->assertSame( '', Event_Calendar::render( [] ) );
	}

	public function test_render_returns_empty_when_enable_filter_is_false(): void {
		$this->force_my_calendar( true );
		add_filter( 'pkiw_event_calendar_enabled', '__return_false' );
		$this->assertSame( '', Event_Calendar::render( [] ) );
	}

	public function test_render_outputs_named_section_and_valid_format(): void {
		$this->force_my_calendar( true );
		$html = Event_Calendar::render( [ 'format' => 'mini', 'label' => 'Upcoming events' ] );

		$this->assertStringContainsString( '<section class="pkiw-event-calendar" aria-label="Upcoming events">', $html );
		$this->assertStringContainsString( 'data-format="mini"', $html );
		$this->assertStringContainsString( 'Calendar output', $html );
	}

	public function test_invalid_format_falls_back_to_calendar(): void {
		$this->force_my_calendar( true );
		$html = Event_Calendar::render( [ 'format' => 'agenda' ] );

		$this->assertStringContainsString( 'data-format="calendar"', $html );
	}
}
