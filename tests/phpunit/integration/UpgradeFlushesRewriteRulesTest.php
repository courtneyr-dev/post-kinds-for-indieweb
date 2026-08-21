<?php
/**
 * Rewrite-rule freshness across plugin updates.
 *
 * Rewrite rules were flushed only from the activation hook and on a
 * storage-mode change. WordPress does not fire the activation hook when a
 * plugin updates in place, so a release that adds a feed or rewrite rule
 * left every upgrading site serving the ruleset generated at its original
 * activation. 1.5.0 added `/firehose`, and it 404'd for the entire
 * existing install base until someone re-saved permalinks.
 *
 * Kind archives were unaffected because they resolve through a generic
 * `kind/([^/]+)` rule that has been stored since 1.0.0 — only genuinely
 * new rules go missing, which is what makes this easy to overlook.
 *
 * @group integration
 */

declare(strict_types=1);

/**
 * Verifies an update refreshes the persisted rewrite rules.
 */
final class UpgradeFlushesRewriteRulesTest extends WP_UnitTestCase {

	/**
	 * Restore a permalink structure so rules are actually generated.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Reset to the default structure so later tests are unaffected.
	 */
	public function tear_down(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Run the plugin's init-time flush check the way the hook does.
	 */
	private function run_flush_check(): void {
		$plugin = \PKIW\Plugin::get_instance();
		$plugin->maybe_flush_rewrite_rules();
	}

	/**
	 * A site whose stored rules predate this version gets them rebuilt.
	 */
	public function test_update_refreshes_stale_rewrite_rules(): void {
		// A site that activated under an older release: rules were flushed
		// then, the flag was consumed, and the stamp records that version.
		delete_option( 'pkiw_flush_rewrite' );
		update_option( 'pkiw_rewrite_version', '1.0.0' );

		$this->run_flush_check();

		$this->assertSame(
			PKIW_VERSION,
			get_option( 'pkiw_rewrite_version' ),
			'An update should re-stamp the rewrite version after flushing.'
		);

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertNotEmpty( $rules, 'Rewrite rules were not regenerated.' );
	}

	/**
	 * The firehose feed registered in 1.5.0 survives the rebuild.
	 */
	public function test_firehose_rule_is_present_after_an_update_flush(): void {
		delete_option( 'pkiw_flush_rewrite' );
		update_option( 'pkiw_rewrite_version', '1.0.0' );

		$this->run_flush_check();

		$rules = (array) get_option( 'rewrite_rules' );

		$this->assertArrayHasKey(
			'^firehose/?$',
			$rules,
			'The /firehose rewrite rule is missing after an update flush.'
		);
	}

	/**
	 * No repeat flush once this version has already stamped.
	 */
	public function test_no_flush_when_the_version_already_matches(): void {
		delete_option( 'pkiw_flush_rewrite' );
		update_option( 'pkiw_rewrite_version', PKIW_VERSION );

		$sentinel = [ 'pkiw-sentinel/?$' => 'index.php?pkiw_sentinel=1' ];
		update_option( 'rewrite_rules', $sentinel );

		$this->run_flush_check();

		$this->assertSame(
			$sentinel,
			get_option( 'rewrite_rules' ),
			'Rules were flushed even though this version had already stamped.'
		);
	}

	/**
	 * The explicit request flag still forces a flush and is consumed.
	 */
	public function test_explicit_flush_request_is_honored_and_cleared(): void {
		update_option( 'pkiw_rewrite_version', PKIW_VERSION );
		update_option( 'pkiw_flush_rewrite', true );

		$this->run_flush_check();

		$this->assertFalse(
			get_option( 'pkiw_flush_rewrite' ),
			'The one-shot flush request should be consumed.'
		);

		$rules = (array) get_option( 'rewrite_rules' );
		$this->assertArrayHasKey( '^firehose/?$', $rules );
	}
}
