<?php
/**
 * RSVP ability registration and read coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Rsvp_Abilities;
use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class RsvpAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Rsvp_Archive() )->register();
	}

	private function rsvp( array $attrs = [], array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Ability RSVP',
					'post_content' => '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode( $attrs ) . ' /-->',
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'rsvp', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	public function test_both_abilities_register_with_the_frozen_exposure_and_schemas(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable.' );
		}

		$read  = wp_get_ability( 'post-kinds/get-rsvp-facts' );
		$write = wp_get_ability( 'post-kinds/backfill-rsvp-meta' );
		$this->assertNotNull( $read );
		$this->assertNotNull( $write );
		$this->assertTrue( $read->get_meta()['mcp']['public'] ?? false );
		$this->assertFalse( $write->get_meta()['mcp']['public'] ?? true );
		$this->assertTrue( $read->get_meta()['show_in_rest'] ?? false );
		$this->assertSame( false, $write->get_meta()['annotations']['readonly'] ?? null );
		$this->assertSame( [ 'post_id' ], $read->get_input_schema()['required'] ?? [] );
		$this->assertFalse( $read->get_output_schema()['additionalProperties'] ?? true );
		$this->assertSame( true, $write->get_input_schema()['properties']['dry_run']['default'] ?? null );
		$this->assertArrayHasKey( 'ids', $write->get_input_schema()['properties'] ?? [] );
	}

	public function test_read_returns_the_public_subset_and_redacts_private_location_for_an_editor(): void {
		$id = $this->rsvp( [ 'eventName' => 'Private event', 'eventLocation' => 'Private Hall', 'locationVisibility' => 'private', 'eventStart' => '2026-10-08T18:00' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['post_id'] );
		$this->assertSame( 'Private event', $result['name'] );
		$this->assertSame( '', $result['location'] );
		$this->assertSame(
			[
				'post_id', 'name', 'url', 'url_host', 'status', 'status_label', 'start_iso',
				'start_day', 'day_display', 'time_display', 'end_iso', 'location',
				'description', 'image', 'image_alt', 'group', 'name_is_title', 'all_day',
				'has_card', 'image_local',
			],
			array_keys( $result )
		);
	}

	public function test_read_returns_precise_not_found_kind_and_forbidden_errors(): void {
		$provider = Rsvp_Abilities::instance();
		$missing  = $provider->execute_get_rsvp_facts( [ 'post_id' => PHP_INT_MAX ] );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'invalid_post', $missing->get_error_code() );
		$this->assertSame( 404, $missing->get_error_data()['status'] ?? null );

		$note = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$invalid = $provider->execute_get_rsvp_facts( [ 'post_id' => $note ] );
		$this->assertSame( 'invalid_kind', $invalid->get_error_code() );

		$protected = $this->rsvp( [ 'eventName' => 'Locked' ], [ 'post_password' => 'pass' ] );
		$forbidden = $provider->execute_get_rsvp_facts( [ 'post_id' => $protected ] );
		$this->assertSame( 'forbidden', $forbidden->get_error_code() );
		$this->assertSame( 403, $forbidden->get_error_data()['status'] ?? null );
	}

	public function test_read_rejects_a_multikind_post_whose_first_kind_is_not_rsvp(): void {
		$id = $this->rsvp( [ 'eventName' => 'Wrong reader' ] );
		wp_set_object_terms( $id, [ 'note', 'rsvp' ], Taxonomy::TAXONOMY );
		$this->assertSame( 'note', \PKIW\Kind_Facts::kind_of( get_post( $id ) ) );

		$result = Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $id ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_kind', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_read_defaults_every_output_key_when_a_facts_filter_drops_keys(): void {
		$id     = $this->rsvp( [ 'eventName' => 'Filtered facts' ] );
		$filter = static fn( array $facts ): array => [ 'name' => $facts['name'] ];
		add_filter( 'pkiw_kind_facts', $filter );
		try {
			$result = Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $id ] );
		} finally {
			remove_filter( 'pkiw_kind_facts', $filter );
		}

		$this->assertIsArray( $result );
		$this->assertSame( 'Filtered facts', $result['name'] );
		$this->assertSame( '', $result['url'] );
		$this->assertSame( 'past', $result['group'] );
		$this->assertFalse( $result['name_is_title'] );
		$this->assertFalse( $result['all_day'] );
		$this->assertFalse( $result['has_card'] );
		$this->assertFalse( $result['image_local'] );
	}

	public function test_write_requires_manage_options(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable.' );
		}
		$ability = wp_get_ability( 'post-kinds/backfill-rsvp-meta' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse( $ability->check_permissions( [ 'dry_run' => true ] ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $ability->check_permissions( [ 'dry_run' => true ] ) );
	}
}
