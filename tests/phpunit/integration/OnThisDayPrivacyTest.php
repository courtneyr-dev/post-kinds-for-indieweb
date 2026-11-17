<?php
/** On This Day privacy-surface coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Abilities\On_This_Day_Abilities;
use PKIW\On_This_Day;
use PKIW\Public_Listing;
use PKIW\Taxonomy;

/** @group integration */
final class OnThisDayPrivacyTest extends WP_UnitTestCase {
	private DateTimeImmutable $today;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->today = new DateTimeImmutable( '2026-05-09 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
	}

	public function tear_down(): void {
		On_This_Day::override_now( null );
		parent::tear_down();
	}

	public function test_archive_and_ability_hide_every_sentinel_and_keep_controls(): void {
		$visible = [
			$this->make( 'control', 'publish' ),
			$this->make( 'local', 'publish', '', 'local' ),
			$this->make( 'no-meta', 'publish' ),
		];
		$hidden = [
			$this->make( 'private', 'private' ),
			$this->make( 'draft', 'draft' ),
			$this->make( 'pending', 'pending' ),
			$this->make( 'future', 'future' ),
			$this->make( 'password', 'publish', 'secret' ),
			$this->make( 'trash', 'trash' ),
			$this->make( 'quiet', 'publish', '', 'quiet_public' ),
			$this->make( 'double', 'publish', '', [ '', 'quiet_public' ] ),
			$this->make( 'non-opted', 'publish', '', null, 'drink' ),
		];
		$rows = On_This_Day::build_rows( $this->today );
		$ids = array_column( $rows, 'id' );
		$json = wp_json_encode( On_This_Day_Abilities::instance()->execute() );
		foreach ( $visible as $id ) {
			$this->assertContains( $id, $ids );
			$this->assertStringContainsString( get_the_title( $id ), (string) $json );
		}
		foreach ( $hidden as $id ) {
			$this->assertNotContains( $id, $ids );
			$this->assertStringNotContainsString( get_the_title( $id ), (string) $json );
			$this->assertSame( '', On_This_Day::item_html( get_post( $id ), true, 'note' ) );
		}
	}

	public function test_visibility_does_not_change_for_an_administrator(): void {
		$id = $this->make( 'admin-private', 'private' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( On_This_Day::row_visible( get_post( $id ), On_This_Day::kinds() ) );
		wp_set_current_user( 0 );
		$this->assertFalse( On_This_Day::row_visible( get_post( $id ), On_This_Day::kinds() ) );
	}

	public function test_archive_item_has_no_excerpt_or_visible_date_and_one_link(): void {
		$id = $this->make( 'markup', 'publish' );
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => 'Stored private-looking excerpt' ] );
		$html = On_This_Day::item_html( get_post( $id ), true, 'note' );
		$this->assertSame( 1, substr_count( $html, '<a' ) );
		$this->assertStringContainsString( 'dt-published', $html );
		$this->assertStringContainsString( ' hidden', $html );
		$this->assertStringNotContainsString( 'Stored private-looking excerpt', $html );
		$this->assertStringNotContainsString( '<li', $html );
	}

	private function make( string $case, string $status, string $password = '', string|array|null $visibility = null, string $kind = 'note' ): int {
		$actual = 'trash' === $status ? 'publish' : $status;
		$date = 'future' === $status ? '2027-05-09 10:00:00' : '2025-05-09 10:00:00';
		$id = self::factory()->post->create( [ 'post_status' => $actual, 'post_password' => $password, 'post_title' => 'OTD268-' . $case, 'post_content' => '', 'post_date' => $date ] );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		foreach ( (array) $visibility as $value ) {
			add_post_meta( $id, Public_Listing::meta_keys()[0], $value );
		}
		if ( 'trash' === $status ) {
			wp_trash_post( $id );
		}
		return $id;
	}
}
