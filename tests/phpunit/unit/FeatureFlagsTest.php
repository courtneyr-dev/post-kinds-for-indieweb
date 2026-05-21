<?php
/**
 * Test the Feature Flags class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Feature_Flags;
use WP_UnitTestCase;

/**
 * Test the Feature Flags functionality.
 *
 * @covers \PostKindsForIndieWeb\Feature_Flags
 */
class FeatureFlagsTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		parent::tear_down();
		delete_option( 'pkiw_feature_flags' );
		remove_all_filters( 'pkiw_feature_flag_abilities_api' );
		remove_all_filters( 'pkiw_feature_flag_mcp_integration' );
	}

	/**
	 * Test default flag values return expected results.
	 */
	public function test_default_abilities_api_is_true() {
		$this->assertTrue( Feature_Flags::is_enabled( 'abilities_api' ) );
	}

	/**
	 * Test default mcp_integration flag is true.
	 */
	public function test_default_mcp_integration_is_true() {
		$this->assertTrue( Feature_Flags::is_enabled( 'mcp_integration' ) );
	}

	/**
	 * Test unknown flag returns false.
	 */
	public function test_unknown_flag_returns_false() {
		$this->assertFalse( Feature_Flags::is_enabled( 'nonexistent_flag' ) );
	}

	/**
	 * Test option override works.
	 */
	public function test_option_override_disables_flag() {
		update_option( 'pkiw_feature_flags', array( 'abilities_api' => false ) );

		$this->assertFalse( Feature_Flags::is_enabled( 'abilities_api' ) );
	}

	/**
	 * Test option override for unknown flag still returns false.
	 */
	public function test_option_override_unknown_flag_returns_false() {
		update_option( 'pkiw_feature_flags', array( 'bogus' => true ) );

		$this->assertFalse( Feature_Flags::is_enabled( 'bogus' ) );
	}

	/**
	 * Test filter override works.
	 */
	public function test_filter_override_disables_flag() {
		add_filter( 'pkiw_feature_flag_abilities_api', '__return_false' );

		$this->assertFalse( Feature_Flags::is_enabled( 'abilities_api' ) );
	}

	/**
	 * Test filter takes priority over option.
	 */
	public function test_filter_takes_priority_over_option() {
		update_option( 'pkiw_feature_flags', array( 'abilities_api' => false ) );
		add_filter( 'pkiw_feature_flag_abilities_api', '__return_true' );

		$this->assertTrue( Feature_Flags::is_enabled( 'abilities_api' ) );
	}

	/**
	 * Test has_abilities_api returns bool.
	 */
	public function test_has_abilities_api_returns_bool() {
		$result = Feature_Flags::has_abilities_api();
		$this->assertIsBool( $result );
	}

	/**
	 * Test has_abilities_api is false when flag disabled.
	 */
	public function test_has_abilities_api_false_when_flag_disabled() {
		add_filter( 'pkiw_feature_flag_abilities_api', '__return_false' );

		$this->assertFalse( Feature_Flags::has_abilities_api() );
	}

	/**
	 * Test has_mcp returns bool.
	 */
	public function test_has_mcp_returns_bool() {
		$result = Feature_Flags::has_mcp();
		$this->assertIsBool( $result );
	}

	/**
	 * Test has_mcp is false when mcp flag disabled.
	 */
	public function test_has_mcp_false_when_mcp_flag_disabled() {
		add_filter( 'pkiw_feature_flag_mcp_integration', '__return_false' );

		$this->assertFalse( Feature_Flags::has_mcp() );
	}

	/**
	 * Test has_mcp is false when abilities_api flag disabled.
	 */
	public function test_has_mcp_false_when_abilities_api_disabled() {
		add_filter( 'pkiw_feature_flag_abilities_api', '__return_false' );

		$this->assertFalse( Feature_Flags::has_mcp() );
	}
}
