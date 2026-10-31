<?php
/**
 * RSVP archive entry block coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class RsvpEntryBlockTest extends WP_UnitTestCase {

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Rsvp_Archive() )->register();
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'RSVP Author' ] );
		update_option( 'timezone_string', 'America/New_York' );
		add_filter( 'pkiw_rsvp_now', [ $this, 'fixed_now' ] );
	}

	public function tear_down(): void {
		remove_filter( 'pkiw_rsvp_now', [ $this, 'fixed_now' ] );
		parent::tear_down();
	}

	public function fixed_now(): string {
		return '2026-10-08 12:00:00';
	}

	private function rsvp( array $attrs, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'RSVP title',
					'post_author'  => $this->author_id,
					'post_content' => '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode( $attrs ) . ' /-->',
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'rsvp', Taxonomy::TAXONOMY );
		Rsvp_Archive::refresh_sort( $id );

		return (int) $id;
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	public function test_block_registers_with_the_frozen_schema_assets_and_example(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Rsvp_Archive::ENTRY_BLOCK );
		$this->assertNotNull( $block );
		$this->assertTrue( Grouped_Archive::is_entry_block( Rsvp_Archive::ENTRY_BLOCK ) );
		$this->assertSame( 3, $block->api_version );
		$this->assertSame( 9, $block->attributes['linesPerPage']['default'] );
		$this->assertSame( 'content', $block->attributes['groupLabels']['role'] );
		$this->assertFalse( $block->supports['html'] );
		$this->assertFalse( $block->supports['reusable'] );
		$this->assertNotEmpty( $block->example );
		$this->assertContains( 'pkiw-rsvp-entry-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-kind-layouts', $block->style_handles );
		$this->assertSame( [ 'postId', 'postType', 'queryId', 'templateSlug' ], $block->uses_context );
		$before = wp_scripts()->get_data( 'pkiw-rsvp-entry-editor', 'before' );
		$inline = implode( "\n", is_array( $before ) ? $before : [] );
		$this->assertStringContainsString( 'window.pkiwRsvpEntry', $inline );
		$this->assertStringContainsString( 'Upcoming', $inline );
		$this->assertStringContainsString( 'Past', $inline );
	}

	public function test_upcoming_and_past_markup_print_only_the_contract_fields(): void {
		$future = $this->rsvp( [ 'eventName' => 'Future Event', 'eventUrl' => 'https://events.example/future', 'eventStart' => '2026-10-09T18:00', 'rsvpStatus' => 'remote', 'eventLocation' => 'Secret Hall', 'eventDescription' => 'Extra description', 'rsvpNote' => 'Extra note' ] );
		$past   = $this->rsvp( [ 'eventName' => 'Past Event', 'eventStart' => '2026-10-01T18:00', 'rsvpStatus' => 'no' ] );
		$future_html = Rsvp_Archive::entry_html( get_post( $future ), [ 'headingLevel' => 2 ], true );
		$past_html   = Rsvp_Archive::entry_html( get_post( $past ), [ 'headingLevel' => 2 ], true );

		$this->assertSame( 1, $this->xpath( $future_html )->query( '//a[@href][not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertSame( 0, $this->xpath( $future_html )->query( '//button|//input|//select|//textarea|//*[@tabindex and @tabindex != "-1"]' )->length );
		$this->assertStringContainsString( 'pkiw-rsvp-entry--upcoming', $future_html );
		$this->assertStringContainsString( '<figure', $future_html );
		$this->assertStringContainsString( 'Attending Remotely', $future_html );
		$this->assertStringContainsString( 'u-in-reply-to', $future_html );
		foreach ( [ 'Secret Hall', 'Extra description', 'Extra note', 'RSVPed' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $future_html );
		}
		$this->assertStringContainsString( 'pkiw-rsvp-entry--past', $past_html );
		$this->assertStringNotContainsString( '<figure', $past_html );
	}

	public function test_remote_images_use_the_typographic_fallback_and_local_images_have_intrinsic_dimensions(): void {
		$remote = $this->rsvp( [ 'eventName' => 'Remote poster', 'eventStart' => '2026-10-09T18:00', 'eventImage' => 'https://images.example/poster.jpg' ] );
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$local_url  = wp_get_attachment_url( $attachment );
		$local      = $this->rsvp( [ 'eventName' => 'Local poster', 'eventStart' => '2026-10-10T18:00', 'eventImage' => $local_url ] );

		$remote_html = Rsvp_Archive::entry_html( get_post( $remote ), [ 'headingLevel' => 2 ], true );
		$local_html  = Rsvp_Archive::entry_html( get_post( $local ), [ 'headingLevel' => 2 ], true );
		$this->assertStringNotContainsString( '<img', $remote_html );
		$this->assertStringContainsString( 'pkiw-rsvp-entry__poster-name', $remote_html );
		$this->assertMatchesRegularExpression( '/<img[^>]+width="\d+"[^>]+height="\d+"/', $local_html );
	}

	public function test_only_the_first_three_upcoming_local_images_on_page_one_are_eager(): void {
		global $paged, $wp_query;

		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$local_url  = wp_get_attachment_url( $attachment );
		$ids        = [];
		foreach ( range( 9, 12 ) as $day ) {
			$ids[] = $this->rsvp( [ 'eventName' => 'Poster ' . $day, 'eventStart' => sprintf( '2026-10-%02dT18:00', $day ), 'eventImage' => $local_url ] );
		}
		$paged = 1;
		$wp_query = new WP_Query();
		$wp_query->posts = array_map( 'get_post', $ids );
		$wp_query->set( 'pkiw_group_source', Rsvp_Archive::SOURCE );

		foreach ( $ids as $index => $id ) {
			$html = Rsvp_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true );
			if ( $index < 3 ) {
				$this->assertStringContainsString( 'loading="eager" fetchpriority="high"', $html );
			} else {
				$this->assertStringContainsString( 'loading="lazy" fetchpriority="auto"', $html );
			}
		}
	}

	public function test_repeated_names_get_date_disambiguation_and_unique_names_do_not(): void {
		$one    = $this->rsvp( [ 'eventName' => 'IndieWeb Meetup', 'eventStart' => '2026-10-09T18:00' ] );
		$two    = $this->rsvp( [ 'eventName' => 'IndieWeb Meetup', 'eventStart' => '2026-10-10T18:00' ] );
		$unique = $this->rsvp( [ 'eventName' => 'Unique Event', 'eventStart' => '2026-10-11T18:00' ] );

		$GLOBALS['wp_query'] = new WP_Query();
		$GLOBALS['wp_query']->posts = [ get_post( $one ), get_post( $two ), get_post( $unique ) ];
		$GLOBALS['wp_query']->set( 'pkiw_group_source', Rsvp_Archive::SOURCE );

		$this->assertStringContainsString( 'aria-labelledby="pkiw-rsvp-' . $one . '-name pkiw-rsvp-' . $one . '-day"', Rsvp_Archive::entry_html( get_post( $one ), [ 'headingLevel' => 2 ], true ) );
		$this->assertStringNotContainsString( 'aria-labelledby', Rsvp_Archive::entry_html( get_post( $unique ), [ 'headingLevel' => 2 ], true ) );
	}

	public function test_protected_markup_contains_only_title_link_and_published_value(): void {
		$id = $this->rsvp( [ 'eventName' => 'Hidden Event', 'eventStart' => '2026-10-09T18:00', 'rsvpStatus' => 'yes' ], [ 'post_password' => 'pass' ] );
		$html = Rsvp_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true );

		$this->assertStringContainsString( 'pkiw-rsvp-entry--past pkiw-rsvp-entry--protected', $html );
		$this->assertSame( 1, $this->xpath( $html )->query( '//a[@href]' )->length );
		$this->assertStringContainsString( 'dt-published', $html );
		foreach ( [ '<figure', 'p-rsvp', 'p-author', 'u-in-reply-to', 'pkiw-rsvp-entry__day' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $html );
		}
	}

	public function test_card_render_uses_the_shared_labels_without_changing_output(): void {
		foreach ( [ 'yes' => 'Going', 'no' => 'Not Going', 'maybe' => 'Maybe', 'interested' => 'Interested', 'remote' => 'Attending Remotely' ] as $status => $label ) {
			$id      = $this->rsvp( [ 'eventName' => 'Card event', 'rsvpStatus' => $status ] );
			$content = do_blocks( get_post_field( 'post_content', $id ) );
			$this->assertStringContainsString( 'value="' . $status . '">' . $label . '</data>', $content );
		}
	}

	public function test_readable_item_parses_as_one_location_free_h_entry(): void {
		if ( ! function_exists( '\\Mf2\\parse' ) ) {
			$this->markTestSkipped( 'php-mf2 is not available.' );
		}
		$id = $this->rsvp( [ 'eventName' => 'Parsed event', 'eventUrl' => 'https://events.example/parsed', 'eventStart' => '2026-10-09T18:00', 'eventLocation' => 'Never parsed', 'rsvpStatus' => 'yes' ] );
		$html = Rsvp_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true );
		$parsed = \Mf2\parse( $html, get_permalink( $id ) );
		$entries = array_values( array_filter( $parsed['items'], static fn( array $item ): bool => in_array( 'h-entry', $item['type'] ?? [], true ) ) );

		$this->assertCount( 1, $entries );
		$properties = $entries[0]['properties'];
		foreach ( [ 'url', 'published', 'author', 'rsvp', 'in-reply-to' ] as $property ) {
			$this->assertArrayHasKey( $property, $properties );
		}
		$this->assertArrayNotHasKey( 'location', $properties );
		$this->assertNotContains( 'h-event', $entries[0]['type'] );
	}
}
