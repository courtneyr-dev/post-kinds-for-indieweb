<?php
/**
 * Event archive template selection and markup.
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
final class EventCalendarTemplateTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		delete_option( 'pkiw_settings' );
		remove_all_filters( 'pkiw_calendar_source_active' );
		$this->set_permalink_structure( '' );
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

	private function go_to_event_archive( int $page = 1 ): void {
		$url = get_term_link( Event_Calendar::KIND, Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );
	}

	public function test_template_is_offered_as_event_archive(): void {
		$template = get_block_template( 'post-kinds-for-indieweb//taxonomy-kind-event' );

		$this->assertNotNull( $template );
		$this->assertSame( 'Event Archive', $template->title );
		$this->assertSame( 'Event archive showing the My Calendar calendar.', $template->description );
	}

	public function test_template_parses_with_header_and_calendar_but_no_query(): void {
		$content = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-event.html' );
		$blocks  = parse_blocks( $content );
		$names   = array_column( $blocks, 'blockName' );

		$this->assertNotEmpty( $blocks );
		$this->assertContains( 'core/template-part', $names );
		$this->assertStringContainsString( '<!-- wp:query-title {"type":"archive","showPrefix":false} /-->', $content );
		$this->assertStringContainsString( '<!-- wp:term-description /-->', $content );
		$this->assertStringContainsString( '<!-- wp:post-kinds-indieweb/event-calendar /-->', $content );
		$this->assertStringNotContainsString( '<!-- wp:query ', $content );
	}

	public function test_hierarchy_drops_event_template_when_provider_is_inactive(): void {
		$this->force_my_calendar( false );
		$this->go_to_event_archive();
		$templates = [ 'taxonomy-kind-event', 'taxonomy-kind-event.php', 'taxonomy-kind.php' ];

		$this->assertSame( [ 'taxonomy-kind.php' ], Event_Calendar::hierarchy( $templates ) );
	}

	public function test_hierarchy_drops_event_template_on_paged_archive(): void {
		$this->force_my_calendar( true );
		update_option( 'posts_per_page', 1 );
		foreach ( [ 'First event', 'Second event' ] as $title ) {
			$post_id = self::factory()->post->create(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
				]
			);
			wp_set_object_terms( $post_id, Event_Calendar::KIND, Taxonomy::TAXONOMY );
		}
		$this->go_to_event_archive( 2 );
		$templates = [ 'taxonomy-kind-event', 'taxonomy-kind-event.php', 'taxonomy-kind.php' ];

		$this->assertTrue( is_tax( Taxonomy::TAXONOMY, Event_Calendar::KIND ) && is_paged() );
		$this->assertSame( [ 'taxonomy-kind.php' ], Event_Calendar::hierarchy( $templates ) );
	}

	public function test_hierarchy_keeps_event_template_when_calendar_can_render(): void {
		$this->force_my_calendar( true );
		$this->go_to_event_archive();
		$templates = [ 'taxonomy-kind-event', 'taxonomy-kind-event.php', 'taxonomy-kind.php' ];

		$this->assertSame( $templates, Event_Calendar::hierarchy( $templates ) );
	}
}
