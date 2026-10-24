<?php
/**
 * A check-in card's privacy holds wherever it sits in the post (issue 369).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

/**
 * Card_Meta_Sync held `_pkiw_geo_privacy` to the check-in card's setting
 * only when the check-in card was the post's first mapped card. Behind a
 * read card, a Private check-in card was never held, so a later write of
 * 'public' (or no row at all) printed its street and venue.
 *
 * @group integration
 */
final class CheckinCardPositionPrivacyTest extends WP_UnitTestCase {

	private const STREET = '369 Lantern Row';
	private const VENUE  = 'Lantern Annex 369';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		if ( ! term_exists( 'checkin', 'kind' ) ) {
			wp_insert_term( 'Checkin', 'kind', [ 'slug' => 'checkin' ] );
		}
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A read card, then a check-in card with the given privacy.
	 *
	 * @param string $privacy The check-in card's locationPrivacy.
	 */
	private function content( string $privacy ): string {
		$read    = '<!-- wp:post-kinds-indieweb/read-card ' . wp_json_encode(
			[
				'bookTitle'  => 'Lantern Book',
				'authorName' => 'A. Writer',
			]
		) . ' /-->';
		$checkin = '<!-- wp:post-kinds-indieweb/checkin-card ' . wp_json_encode(
			[
				'venueName'       => self::VENUE,
				'address'         => self::STREET,
				'locality'        => 'Lanternburg',
				'latitude'        => 36.936936,
				'longitude'       => -76.369369,
				'locationPrivacy' => $privacy,
			]
		) . ' /-->';

		return $read . "\n\n" . $checkin;
	}

	/**
	 * A published post with the read card first and the check-in card second.
	 *
	 * @param string $privacy The check-in card's locationPrivacy.
	 * @return int Post ID.
	 */
	private function post( string $privacy ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->content( $privacy ),
			]
		);
		wp_set_object_terms( $id, 'checkin', 'kind' );

		return $id;
	}

	/**
	 * The post's rendered content as a visitor gets it.
	 *
	 * @param int $id Post ID.
	 */
	private function render( int $id ): string {
		$this->go_to( (string) get_permalink( $id ) );

		return (string) apply_filters( 'the_content', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_a_second_position_private_card_with_no_row_stores_private(): void {
		$id = $this->post( 'private' );

		$this->assertSame( 'private', get_post_meta( $id, '_pkiw_geo_privacy', true ) );
		$html = $this->render( $id );
		$this->assertStringNotContainsString( self::STREET, $html );
		$this->assertStringNotContainsString( self::VENUE, $html );
	}

	public function test_a_later_public_write_does_not_loosen_a_second_position_private_card(): void {
		$id = $this->post( 'private' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );

		$this->assertSame( 'private', get_post_meta( $id, '_pkiw_geo_privacy', true ), 'The row is held at the card\'s setting.' );
		$this->assertStringNotContainsString( self::STREET, $this->render( $id ) );
	}

	public function test_a_resave_does_not_loosen_a_second_position_private_card_over_a_public_row(): void {
		$id = $this->post( 'private' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $this->content( 'private' ),
			]
		);

		$this->assertSame( 'private', get_post_meta( $id, '_pkiw_geo_privacy', true ) );
		$this->assertStringNotContainsString( self::STREET, $this->render( $id ) );
	}

	public function test_a_second_position_public_card_needs_a_public_row_to_show_the_street(): void {
		$id = $this->post( 'public' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );

		$this->assertSame( 'public', get_post_meta( $id, '_pkiw_geo_privacy', true ) );
		$this->assertStringContainsString( self::STREET, $this->render( $id ), 'Card and row both public.' );
	}
}
