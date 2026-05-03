<?php
/**
 * Test the main plugin class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;

/**
 * Test the main plugin functionality.
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * Test that WordPress is properly loaded.
	 */
	public function test_wordpress_is_loaded() {
		$this->assertTrue( function_exists( 'add_action' ) );
	}

	/**
	 * Test that the plugin is loaded.
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'POST_KINDS_INDIEWEB_VERSION' ) );
	}

	/**
	 * Test that the plugin version is set.
	 */
	public function test_plugin_version() {
		$this->assertEquals( '1.0.1', POST_KINDS_INDIEWEB_VERSION );
	}

	/**
	 * Test that the kind taxonomy is registered.
	 */
	public function test_kind_taxonomy_exists() {
		$this->assertTrue( taxonomy_exists( 'kind' ) );
	}

	/**
	 * Test that the plugin namespace is correct.
	 */
	public function test_plugin_namespace() {
		$this->assertTrue( class_exists( 'PostKindsForIndieWeb\Plugin' ) );
	}

	// --- add_plugin_templates: slug filter regression ---------------------

	/**
	 * Regression: when WP resolves the single-post block-template hierarchy,
	 * it queries get_block_templates(['slug__in' => ['singular']]). The
	 * filter MUST NOT inject taxonomy-venue into that result, or
	 * resolve_block_template will return the venue template for ordinary
	 * single posts and the post body never renders.
	 */
	public function test_add_plugin_templates_respects_slug_in_filter(): void {
		$plugin = \PostKindsForIndieWeb\Plugin::get_instance();

		$result = $plugin->add_plugin_templates(
			array(),
			array( 'slug__in' => array( 'singular' ) ),
			'wp_template'
		);

		$slugs = array_map(
			static fn( $tpl ) => $tpl->slug,
			$result
		);

		$this->assertNotContains(
			'taxonomy-venue',
			$slugs,
			'taxonomy-venue must not bleed into a slug__in => [singular] query.'
		);
	}

	/**
	 * Positive: when slug__in DOES contain taxonomy-venue, the template is
	 * injected as expected.
	 */
	public function test_add_plugin_templates_injects_when_slug_requested(): void {
		$plugin = \PostKindsForIndieWeb\Plugin::get_instance();

		$result = $plugin->add_plugin_templates(
			array(),
			array( 'slug__in' => array( 'taxonomy-venue' ) ),
			'wp_template'
		);

		$slugs = array_map(
			static fn( $tpl ) => $tpl->slug,
			$result
		);

		$this->assertContains( 'taxonomy-venue', $slugs );
	}

	/**
	 * Positive: when no slug filter is provided (Site Editor list view),
	 * the template is still injected.
	 */
	public function test_add_plugin_templates_injects_when_no_slug_filter(): void {
		$plugin = \PostKindsForIndieWeb\Plugin::get_instance();

		$result = $plugin->add_plugin_templates(
			array(),
			array(),
			'wp_template'
		);

		$slugs = array_map(
			static fn( $tpl ) => $tpl->slug,
			$result
		);

		$this->assertContains( 'taxonomy-venue', $slugs );
	}

	/**
	 * Negative: non-wp_template queries (e.g. wp_template_part) are passed
	 * through untouched.
	 */
	public function test_add_plugin_templates_skips_non_wp_template_type(): void {
		$plugin = \PostKindsForIndieWeb\Plugin::get_instance();

		$result = $plugin->add_plugin_templates(
			array(),
			array(),
			'wp_template_part'
		);

		$this->assertSame( array(), $result );
	}
}
