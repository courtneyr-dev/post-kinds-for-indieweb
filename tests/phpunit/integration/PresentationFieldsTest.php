<?php
/** Presentation field registration and sanitization. */

declare(strict_types=1);

use PKIW\Integrations\Calendar_Events;
use PKIW\Meta_Fields;
use PKIW\Presentation;

final class PresentationFieldsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
	}

	public function test_event_types_are_ordered_filterable_and_cleaned(): void {
		$this->assertSame(
			[
				'talk'           => 'Talk',
				'keynote'        => 'Keynote',
				'workshop'       => 'Workshop',
				'panel'          => 'Panel',
				'lightning-talk' => 'Lightning talk',
				'meetup'         => 'Meetup',
				'podcast-guest'  => 'Podcast guest',
			],
			Presentation::event_types()
		);
		add_filter(
			'pkiw_presentation_event_types',
			static fn(): array => [ ' Guest Type! ' => ' Guest <b>label</b> ', '' => 'Dropped', 'empty' => '' ]
		);
		$this->assertSame( [ 'guesttype' => 'Guest label' ], Presentation::event_types() );
		remove_all_filters( 'pkiw_presentation_event_types' );
	}

	public function test_presentation_sanitizers_accept_only_registered_values(): void {
		$this->assertSame( Calendar_Events::SOURCE_MY_CALENDAR, Meta_Fields::sanitize_presentation_calendar_source( Calendar_Events::SOURCE_MY_CALENDAR ) );
		$this->assertSame( '', Meta_Fields::sanitize_presentation_calendar_source( 'private-calendar' ) );
		$this->assertSame( 'talk', Meta_Fields::sanitize_presentation_event_type( 'talk' ) );
		$this->assertSame( '', Meta_Fields::sanitize_presentation_event_type( 'fireside' ) );
		add_filter( 'pkiw_presentation_event_types', static fn( array $types ): array => $types + [ 'fireside' => 'Fireside' ] );
		$this->assertSame( 'fireside', Meta_Fields::sanitize_presentation_event_type( 'fireside' ) );
		remove_all_filters( 'pkiw_presentation_event_types' );
	}

	public function test_all_seven_fields_are_single_rest_meta(): void {
		$taxonomy = PKIW\Plugin::get_instance()->get_taxonomy();
		$this->assertInstanceOf( PKIW\Taxonomy::class, $taxonomy );
		foreach ( $taxonomy->get_post_types() as $post_type ) {
			$registered = get_registered_meta_keys( 'post', $post_type );
			foreach ( [ 'slides_url', 'recording_url', 'event_name', 'event_url', 'calendar_source', 'calendar_event_id', 'event_type' ] as $suffix ) {
				$key = '_pkiw_presentation_' . $suffix;
				$this->assertArrayHasKey( $key, $registered, $post_type . ':' . $key );
				$this->assertTrue( $registered[ $key ]['single'] );
				$this->assertNotFalse( $registered[ $key ]['show_in_rest'] );
			}
		}
	}
}
