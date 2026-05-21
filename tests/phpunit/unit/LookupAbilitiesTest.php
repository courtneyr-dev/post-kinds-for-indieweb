<?php
/**
 * Test the Lookup Abilities provider class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Abilities\Lookup_Abilities;
use WP_UnitTestCase;

/**
 * Test the Lookup Abilities provider functionality.
 *
 * @covers \PostKindsForIndieWeb\Abilities\Lookup_Abilities
 */
class LookupAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Lookup_Abilities instance.
	 *
	 * @var Lookup_Abilities
	 */
	private Lookup_Abilities $provider;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->provider = Lookup_Abilities::instance();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		Lookup_Abilities::reset();
		parent::tear_down();
	}

	/**
	 * Test get_lookup_definitions returns six definitions.
	 */
	public function test_get_lookup_definitions_returns_six(): void {
		$definitions = $this->provider->get_lookup_definitions();
		$this->assertCount( 6, $definitions );
	}

	/**
	 * Test lookup definitions contain expected kinds.
	 */
	public function test_lookup_definitions_contain_expected_kinds(): void {
		$definitions = $this->provider->get_lookup_definitions();
		$slugs       = array_keys( $definitions );
		$this->assertContains( 'music', $slugs );
		$this->assertContains( 'video', $slugs );
		$this->assertContains( 'book', $slugs );
		$this->assertContains( 'podcast', $slugs );
		$this->assertContains( 'venue', $slugs );
		$this->assertContains( 'game', $slugs );
	}

	/**
	 * Test each definition has required keys.
	 */
	public function test_each_definition_has_required_keys(): void {
		$definitions = $this->provider->get_lookup_definitions();
		foreach ( $definitions as $slug => $def ) {
			$this->assertArrayHasKey( 'label', $def, "Missing label for {$slug}" );
			$this->assertArrayHasKey( 'description', $def, "Missing description for {$slug}" );
			$this->assertArrayHasKey( 'rest_route', $def, "Missing rest_route for {$slug}" );
			$this->assertArrayHasKey( 'input_schema', $def, "Missing input_schema for {$slug}" );
		}
	}
}
