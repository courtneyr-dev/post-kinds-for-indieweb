<?php
/**
 * Test the Abilities Manager class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Abilities_Manager;
use WP_UnitTestCase;

/**
 * Test the Abilities Manager functionality.
 *
 * @covers \PostKindsForIndieWeb\Abilities_Manager
 */
class AbilitiesManagerTest extends WP_UnitTestCase {

	/**
	 * Test singleton returns same instance.
	 */
	public function test_singleton_returns_same_instance() {
		$instance1 = Abilities_Manager::instance();
		$instance2 = Abilities_Manager::instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test CATEGORY_SLUG constant value.
	 */
	public function test_category_slug_constant() {
		$this->assertSame( 'post-kinds', Abilities_Manager::CATEGORY_SLUG );
	}
}
