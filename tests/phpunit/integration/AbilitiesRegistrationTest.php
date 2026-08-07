<?php
/**
 * Abilities registration integration coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities_Manager;

/**
 * Verifies every declared ability actually lands in the registry.
 *
 * Core rejects an ability name that doesn't match /^[a-z0-9-]+\/[a-z0-9-]+$/
 * with a _doing_it_wrong() notice and registers nothing. With WP_DEBUG off
 * that is completely silent, which is how thirteen `post_kinds/*` names sat
 * unregistered from 1.1.0 until the WP 7.1-RC1 audit found them. Asserting on
 * wp_get_abilities() is what separates "we called wp_register_ability()" from
 * "the ability exists" — the manager-level unit tests can't tell the two apart.
 *
 * @group integration
 * @covers \PKIW\Abilities_Manager
 */
final class AbilitiesRegistrationTest extends WP_UnitTestCase {

	/**
	 * Core's ability name grammar, copied from WP_Abilities_Registry::register().
	 */
	private const NAME_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

	/**
	 * Every declared name must satisfy core's grammar.
	 *
	 * Runs without the Abilities API present so a bad name fails fast and
	 * points at itself, rather than surfacing as a missing-key assertion.
	 */
	public function test_declared_names_match_core_grammar(): void {
		foreach ( Abilities_Manager::get_ability_names() as $name ) {
			$this->assertMatchesRegularExpression(
				self::NAME_PATTERN,
				$name,
				sprintf( 'Ability "%s" would be rejected by WP_Abilities_Registry::register().', $name )
			);
		}
	}

	/**
	 * Every declared ability is present in the registry after init.
	 */
	public function test_declared_abilities_are_registered(): void {
		$this->skip_without_abilities_api();

		$registered = array_keys( wp_get_abilities() );
		$declared   = Abilities_Manager::get_ability_names();

		$missing = array_diff( $declared, $registered );

		$this->assertSame(
			[],
			array_values( $missing ),
			'Declared abilities are missing from the registry: ' . implode( ', ', $missing )
		);
	}

	/**
	 * Nothing registers under post-kinds/ without being declared.
	 *
	 * Abilities_Manager::get_ability_names() is what feeds the WP Pinch MCP
	 * server list, so an ability that registers but isn't declared is invisible
	 * to MCP callers.
	 */
	public function test_registered_abilities_are_all_declared(): void {
		$this->skip_without_abilities_api();

		$registered = array_keys( wp_get_abilities( [ 'namespace' => 'post-kinds' ] ) );
		$undeclared = array_diff( $registered, Abilities_Manager::get_ability_names() );

		$this->assertSame(
			[],
			array_values( $undeclared ),
			'Abilities registered but not declared in get_ability_names(): ' . implode( ', ', $undeclared )
		);
	}

	/**
	 * The declared set is the full 13 — 7 core plus 6 lookups.
	 */
	public function test_declared_ability_count(): void {
		$this->assertCount( 13, Abilities_Manager::get_ability_names() );
	}

	/**
	 * Skip when running against a WordPress without the Abilities API.
	 */
	private function skip_without_abilities_api(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version (needs 6.9+).' );
		}
	}
}
