<?php
/** On This Day ability coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Abilities\On_This_Day_Abilities;
use PKIW\Abilities_Manager;
use PKIW\On_This_Day;
use PKIW\Taxonomy;

/** @group integration */
final class OnThisDayAbilityTest extends WP_UnitTestCase {
	private DateTimeImmutable $today;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->today = new DateTimeImmutable( '2026-04-05 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
		if ( null === wp_get_ability( 'post-kinds/get-on-this-day' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
	}

	public function tear_down(): void {
		On_This_Day::override_now( null );
		parent::tear_down();
	}

	public function test_registration_schema_and_mcp_public_contract(): void {
		$ability = wp_get_ability( 'post-kinds/get-on-this-day' );
		$this->assertNotNull( $ability );
		$this->assertContains( 'post-kinds/get-on-this-day', Abilities_Manager::MCP_PUBLIC_ABILITIES );
		$this->assertTrue( $ability->get_meta()['mcp']['public'] ?? false );
		$input = $ability->get_input_schema();
		$this->assertFalse( $input['additionalProperties'] );
		$this->assertArrayNotHasKey( 'kinds', $input['properties'] );
		$item = $ability->get_output_schema()['properties']['groups']['items']['properties']['items']['items']['properties'];
		foreach ( [ 'excerpt', 'content', 'meta', 'author' ] as $private ) {
			$this->assertArrayNotHasKey( $private, $item );
		}
		$this->assertTrue( $ability->get_meta()['annotations']['readonly'] ?? false );
		$writes = array_filter(
			array_keys( wp_get_abilities() ),
			static fn( string $name ): bool => str_contains( $name, 'on-this-day' ) && 'post-kinds/get-on-this-day' !== $name
		);
		$this->assertSame( [], array_values( $writes ), 'No other On This Day ability, write or read, is registered.' );
	}

	public function test_validation_rejects_bad_future_and_out_of_range_inputs(): void {
		$provider = On_This_Day_Abilities::instance();
		foreach ( [ [ 'date' => 'not-a-date' ], [ 'date' => '2026-04-06' ], [ 'limit' => 0 ], [ 'limit' => 101 ] ] as $input ) {
			$this->assertWPError( $provider->execute( $input ) );
		}
		$schema = wp_get_ability( 'post-kinds/get-on-this-day' )->get_input_schema();
		$this->assertWPError( rest_validate_value_from_schema( [ 'unknown' => true ], $schema ) );
	}

	public function test_output_matches_schema_and_uses_requested_date_for_years_ago(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Ability sentinel', 'post_date' => '2021-04-05 10:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		$output = On_This_Day_Abilities::instance()->execute( [ 'date' => '2025-04-05', 'limit' => 10 ] );
		$this->assertIsArray( $output );
		$this->assertTrue( rest_validate_value_from_schema( $output, wp_get_ability( 'post-kinds/get-on-this-day' )->get_output_schema() ) );
		$this->assertSame( 4, $output['groups'][0]['years_ago'] );
		$this->assertSame( [ $id ], array_column( $output['groups'][0]['items'], 'post_id' ) );
		$empty = On_This_Day_Abilities::instance()->execute( [ 'date' => '2025-04-06' ] );
		$this->assertSame( [], $empty['groups'] );
		$this->assertSame( 0, $empty['total'] );
	}

	public function test_execute_returns_the_same_ids_for_visitor_admin_and_cli_contexts(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2025-04-05 10:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		foreach ( [ 'private', 'draft' ] as $status ) {
			$hidden = self::factory()->post->create( [ 'post_status' => $status, 'post_date' => '2024-04-05 10:00:00' ] );
			wp_set_object_terms( $hidden, 'note', Taxonomy::TAXONOMY );
		}
		$ids = [];
		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'administrator' ] ) ] as $user ) {
			wp_set_current_user( $user );
			$result = On_This_Day_Abilities::instance()->execute();
			$ids[]  = array_merge( ...array_map( static fn( array $group ): array => array_column( $group['items'], 'post_id' ), $result['groups'] ) );
		}
		$this->assertSame( [ [ $id ], [ $id ] ], $ids );
	}
}
