<?php
/**
 * MCP exposure of the Post Kinds abilities.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities_Manager;

/**
 * The MCP Adapter's default server only lists abilities whose
 * meta.mcp.public is true. Read abilities are public on every site with the
 * mcp_integration flag on; the writes carry an explicit false, which also
 * blocks MCP Adapter 0.6+'s fallback to a top-level meta.public. Nothing
 * depends on WP Pinch any more.
 *
 * @group integration
 * @covers \PKIW\Abilities_Manager
 */
final class McpServerAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Reset registry state so later tests rebuild it lazily.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pkiw_feature_flag_mcp_integration' );
		$this->reset_abilities_registry();
		parent::tear_down();
	}

	/**
	 * Discard the WP_Abilities_Registry singleton so the next abilities call
	 * rebuilds it lazily from whatever wp_abilities_api_init listeners exist.
	 *
	 * WP_UnitTestCase restores $wp_filter between tests, so resetting here
	 * leaves no registry or hook state behind for later tests.
	 */
	private function reset_abilities_registry(): void {
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return;
		}

		$property = new \ReflectionProperty( \WP_Abilities_Registry::class, 'instance' );
		$property->setValue( null, null );
	}

	/**
	 * Skip when running against a WordPress without the Abilities API.
	 */
	private function skip_without_abilities_api(): void {
		if ( ! function_exists( 'wp_has_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version (needs 6.9+).' );
		}
	}

	/**
	 * Read abilities exposed on the default server.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function read_ability_names(): array {
		$names = [
			'post-kinds/list-kinds',
			'post-kinds/list-kind-fields',
			'post-kinds/get-kind',
			'post-kinds/get-post-meta',
			'post-kinds/get-quote-index',
			'post-kinds/get-rsvp-facts',
			'post-kinds/get-collection-items',
			'post-kinds/lookup-music',
			'post-kinds/lookup-video',
			'post-kinds/lookup-book',
			'post-kinds/lookup-podcast',
			'post-kinds/lookup-venue',
			'post-kinds/lookup-game',
			'post-kinds/get-surface',
			'post-kinds/get-public-location-fields',
			'post-kinds/get-checkin-stats',
			'post-kinds/list-venues',
		];

		return array_combine( $names, array_map( static fn( $name ) => [ $name ], $names ) );
	}

	/**
	 * Write abilities kept off the default server.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function write_ability_names(): array {
		$names = [
			'post-kinds/create-post',
			'post-kinds/set-kind',
			'post-kinds/update-post-meta',
			'post-kinds/backfill-surfaces',
			'post-kinds/backfill-card-meta',
			'post-kinds/backfill-listen-media-type',
			'post-kinds/backfill-rsvp-meta',
			'post-kinds/backfill-featured-artwork',
		];

		return array_combine( $names, array_map( static fn( $name ) => [ $name ], $names ) );
	}

	/**
	 * The exposure filter is hooked without WP Pinch, and the old WP Pinch
	 * server-list filter is gone.
	 */
	public function test_ability_args_filter_hooked_without_wp_pinch(): void {
		$this->skip_without_abilities_api();
		$this->assertFalse( class_exists( 'WP_Pinch\\Abilities' ) );

		remove_all_filters( 'wp_register_ability_args' );
		Abilities_Manager::reset();
		Abilities_Manager::instance();

		$this->assertSame( 10, has_filter( 'wp_register_ability_args', [ Abilities_Manager::class, 'filter_ability_args' ] ) );
		$this->assertFalse( has_filter( 'wp_pinch_mcp_server_abilities' ) );
	}

	/**
	 * With the mcp_integration flag off, nothing is exposed.
	 */
	public function test_filter_not_hooked_when_mcp_integration_disabled(): void {
		$this->skip_without_abilities_api();

		add_filter( 'pkiw_feature_flag_mcp_integration', '__return_false' );
		remove_all_filters( 'wp_register_ability_args' );
		Abilities_Manager::reset();
		Abilities_Manager::instance();

		$this->assertFalse( has_filter( 'wp_register_ability_args', [ Abilities_Manager::class, 'filter_ability_args' ] ) );
	}

	/**
	 * @dataProvider read_ability_names
	 */
	public function test_read_abilities_are_public( string $name ): void {
		$result = Abilities_Manager::filter_ability_args( [ 'label' => 'Test' ], $name );

		$this->assertTrue( $result['meta']['mcp']['public'] );
	}

	/**
	 * @dataProvider write_ability_names
	 */
	public function test_write_abilities_are_explicitly_private( string $name ): void {
		$result = Abilities_Manager::filter_ability_args( [ 'label' => 'Test' ], $name );

		$this->assertFalse( $result['meta']['mcp']['public'] );
	}

	/**
	 * A write registered with public: true still ends up private, and its
	 * other mcp meta survives.
	 */
	public function test_write_cannot_opt_in_through_registration_args(): void {
		$args = [
			'label' => 'Test',
			'meta'  => [
				'show_in_rest' => true,
				'mcp'          => [
					'public' => true,
					'type'   => 'tool',
				],
			],
		];

		$result = Abilities_Manager::filter_ability_args( $args, 'post-kinds/update-post-meta' );

		$this->assertFalse( $result['meta']['mcp']['public'] );
		$this->assertSame( 'tool', $result['meta']['mcp']['type'] );
		$this->assertTrue( $result['meta']['show_in_rest'] );
	}

	public function test_other_namespaces_are_untouched(): void {
		$args = [ 'label' => 'Test' ];

		$this->assertSame( $args, Abilities_Manager::filter_ability_args( $args, 'other/ability' ) );
	}

	/**
	 * End to end: the registered abilities carry the exposure the filter set.
	 */
	public function test_registered_abilities_carry_mcp_exposure(): void {
		$this->skip_without_abilities_api();

		// The plugin's own instance hooked the filter at load; rebuild only the registry.
		$this->reset_abilities_registry();
		wp_get_abilities();

		$public = [];
		foreach ( Abilities_Manager::get_ability_names() as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, sprintf( 'Ability "%s" should be registered.', $name ) );
			if ( true === ( $ability->get_meta()['mcp']['public'] ?? null ) ) {
				$public[] = $name;
			}
		}

		$expected = array_keys( self::read_ability_names() );
		sort( $expected );
		sort( $public );
		$this->assertSame( $expected, $public );
	}
}
