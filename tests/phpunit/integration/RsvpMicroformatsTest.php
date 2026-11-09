<?php
/**
 * RSVP hidden microformats coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class RsvpMicroformatsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Rsvp_Archive() )->register();
	}

	private function rsvp( string $content, array $post = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish', 'post_content' => $content ], $post ) );
		wp_set_object_terms( $id, 'rsvp', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function content( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		return apply_filters( 'the_content', (string) get_post_field( 'post_content', $post_id ) );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	public function test_card_single_has_one_rsvp_and_one_reply_target_with_no_hidden_duplicate(): void {
		$card = '<!-- wp:post-kinds-indieweb/rsvp-card {"eventName":"Card event","eventUrl":"https://events.example/card","rsvpStatus":"yes"} /-->';
		$id   = $this->rsvp( $card );
		$html = $this->content( $id );

		$xpath = $this->xpath( $html );
		$this->assertSame( 1, $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " p-rsvp ")]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " u-in-reply-to ")]' )->length );
		$this->assertStringNotContainsString( 'p-location', $html );
	}

	public function test_cardless_quick_post_gets_status_and_reply_target_from_facts(): void {
		$id = $this->rsvp( '<p>Quick RSVP note.</p>' );
		update_post_meta( $id, '_pkiw_event_url', 'https://events.example/quick' );
		update_post_meta( $id, '_pkiw_rsvp_value', 'maybe' );
		update_post_meta( $id, '_pkiw_event_location', 'Hidden quick location' );

		$html = $this->content( $id );
		$this->assertStringContainsString( '<data class="p-rsvp" value="maybe"></data>', $html );
		$this->assertStringContainsString( '<data class="u-in-reply-to" value="https://events.example/quick"></data>', $html );
		$this->assertStringNotContainsString( 'Hidden quick location', $html );
	}

	public function test_protected_single_gets_no_hidden_rsvp_data(): void {
		$id = $this->rsvp( '<p>Protected RSVP.</p>', [ 'post_password' => 'pass' ] );
		update_post_meta( $id, '_pkiw_event_url', 'https://events.example/protected' );
		update_post_meta( $id, '_pkiw_rsvp_status', 'yes' );

		$html = $this->content( $id );
		$this->assertStringNotContainsString( 'p-rsvp', $html );
		$this->assertStringNotContainsString( 'u-in-reply-to', $html );
	}
}
