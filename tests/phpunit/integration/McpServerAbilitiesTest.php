<?php
/**
 * MCP server abilities advertisement coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities_Manager;

/**
 * Verifies the wp_pinch_mcp_server_abilities filter only advertises
 * abilities that actually exist in the registry.
 *
 * WP Pinch's MCP adapter builds its tool list from the advertised names and
 * logs a "WordPress ability '<name>' does not exist" error for every name it
 * can't resolve. Under WP-CLI the adapter can run in a lifecycle where
 * wp_abilities_api_init never registered the Post Kinds abilities, so an
 * unconditional advertise produces one logged error per declared ability on
 * every wp invocation.
 *
 * @group integration
 * @covers \PKIW\Abilities_Manager
 */
final class McpServerAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Reset registry state so later tests rebuild it lazily.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_pinch_mcp_server_abilities' );
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
	 * Regression for the staging mcp-adapter errors: in a lifecycle where
	 * wp_abilities_api_init never registered the Post Kinds abilities,
	 * advertising the declared names makes the adapter log one "ability does
	 * not exist" error per name. With no registered abilities, the filter
	 * must return the incoming list untouched.
	 */
	public function test_filter_advertises_nothing_when_no_abilities_registered(): void {
		$this->skip_without_abilities_api();

		$this->reset_abilities_registry();
		remove_all_actions( 'wp_abilities_api_init' );

		$result = Abilities_Manager::filter_mcp_server_abilities( [] );

		$this->assertSame( [], $result );
	}

	/**
	 * Existing non-Post-Kinds entries pass through unchanged when the Post
	 * Kinds abilities never registered.
	 */
	public function test_filter_preserves_existing_entries_when_no_abilities_registered(): void {
		$this->skip_without_abilities_api();

		$this->reset_abilities_registry();
		remove_all_actions( 'wp_abilities_api_init' );

		$result = Abilities_Manager::filter_mcp_server_abilities( [ 'other/ability' ] );

		$this->assertSame( [ 'other/ability' ], $result );
	}

	/**
	 * Once the registry initializes and the plugin's wp_abilities_api_init
	 * listener registers the abilities, the filter advertises all of them.
	 */
	public function test_filter_advertises_registered_abilities(): void {
		$this->skip_without_abilities_api();

		$this->reset_abilities_registry();

		$result = Abilities_Manager::filter_mcp_server_abilities( [ 'other/ability' ] );

		$this->assertContains( 'other/ability', $result );
		foreach ( Abilities_Manager::get_ability_names() as $name ) {
			$this->assertContains( $name, $result );
			$this->assertTrue( wp_has_ability( $name ), sprintf( 'Ability "%s" should be registered.', $name ) );
		}
	}

	/**
	 * When only part of the declared set is registered, the filter advertises
	 * exactly that registered subset.
	 */
	public function test_filter_advertises_only_registered_subset(): void {
		$this->skip_without_abilities_api();

		$this->reset_abilities_registry();
		wp_get_abilities(); // Force lazy registry init so the abilities register.

		$missing = [ 'post-kinds/lookup-venue', 'post-kinds/lookup-game' ];
		foreach ( $missing as $name ) {
			wp_unregister_ability( $name );
		}

		$result = Abilities_Manager::filter_mcp_server_abilities( [] );

		$expected = array_values( array_diff( Abilities_Manager::get_ability_names(), $missing ) );
		$this->assertSame( $expected, $result );
	}
}
