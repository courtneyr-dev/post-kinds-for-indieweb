<?php
/**
 * Tests for the ATmosphere integration's Post Kind eligibility policy.
 *
 * Eligibility rides ATmosphere's own per-post control: the integration
 * supplies a *default* for the `atmosphere_disabled` meta via the
 * `default_post_metadata` filter, so consumption/reaction kinds are opt-in
 * without writing any state, and an author's explicit toggle always wins.
 *
 * @package PKIW
 * @group   atmosphere
 */

namespace PKIW\Tests\Unit;

use PKIW\Integrations\Atmosphere_Eligibility;

/**
 * Atmosphere_Eligibility tests.
 *
 * @group atmosphere
 */
class AtmosphereEligibilityTest extends \WP_UnitTestCase {

	/**
	 * The instance under test, hooks registered.
	 *
	 * @var Atmosphere_Eligibility
	 */
	private Atmosphere_Eligibility $eligibility;

	public function set_up(): void {
		parent::set_up();
		delete_option( Atmosphere_Eligibility::OPTION );
		$this->eligibility = new Atmosphere_Eligibility();
		$this->eligibility->register();
	}

	public function tear_down(): void {
		$this->eligibility->unregister();
		delete_option( Atmosphere_Eligibility::OPTION );
		parent::tear_down();
	}

	/**
	 * Create a post of a given kind.
	 *
	 * @param string $kind Kind slug.
	 * @return int Post ID.
	 */
	private function make_kind_post( string $kind ): int {
		$post_id = self::factory()->post->create( [ 'post_title' => 'A post' ] );
		wp_set_object_terms( $post_id, $kind, 'kind' );

		return $post_id;
	}

	/**
	 * Whether ATmosphere would treat the post as shareable.
	 *
	 * Mirrors \Atmosphere\is_sharing_enabled(): sharing is on unless the
	 * atmosphere_disabled meta reads '1'.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function sharing_enabled( int $post_id ): bool {
		return '1' !== (string) get_post_meta( $post_id, 'atmosphere_disabled', true );
	}

	public function test_content_log_and_substantive_response_kinds_default_to_enabled() {
		foreach ( [ 'note', 'article', 'photo', 'video', 'audio', 'review', 'recipe', 'event', 'quote', 'question', 'craft', 'listen', 'watch', 'read', 'play', 'eat', 'drink', 'jam', 'reply', 'bookmark', 'rsvp', 'issue' ] as $kind ) {
			$post_id = $this->make_kind_post( $kind );

			$this->assertTrue( $this->sharing_enabled( $post_id ), "kind: $kind" );
		}
	}

	public function test_signal_and_sensitive_kinds_default_to_disabled() {
		foreach ( [ 'like', 'repost', 'favorite', 'follow', 'tag', 'checkin', 'mood', 'wish', 'acquisition', 'weather', 'exercise', 'sleep', 'trip', 'itinerary' ] as $kind ) {
			$post_id = $this->make_kind_post( $kind );

			$this->assertFalse( $this->sharing_enabled( $post_id ), "kind: $kind" );
		}
	}

	public function test_unknown_registry_kinds_default_to_disabled() {
		$post_id = $this->make_kind_post( 'somecustomkind' );

		$this->assertFalse(
			$this->sharing_enabled( $post_id ),
			'a kind added via the registry filter but unknown to the policy must be opt-in'
		);
	}

	public function test_explicit_author_choice_wins_over_the_default() {
		$post_id = $this->make_kind_post( 'checkin' );

		// Author flips ATmosphere's own toggle back on: stored meta wins.
		update_post_meta( $post_id, 'atmosphere_disabled', '' );

		$this->assertTrue( $this->sharing_enabled( $post_id ) );

		// And an explicit opt-out on a content kind wins too.
		$note_id = $this->make_kind_post( 'note' );
		update_post_meta( $note_id, 'atmosphere_disabled', '1' );

		$this->assertFalse( $this->sharing_enabled( $note_id ) );
	}

	public function test_already_published_posts_are_never_default_disabled() {
		$post_id = $this->make_kind_post( 'checkin' );
		update_post_meta( $post_id, '_atmosphere_doc_uri', 'at://did:plc:abc/site.standard.document/3xyz' );

		$this->assertTrue(
			$this->sharing_enabled( $post_id ),
			'a kind default must not retract a record published before the integration existed'
		);
	}

	public function test_site_setting_overrides_the_shipped_defaults() {
		update_option( Atmosphere_Eligibility::OPTION, [ 'eligible_kinds' => [ 'listen' ] ] );

		$listen_id = $this->make_kind_post( 'listen' );
		$note_id   = $this->make_kind_post( 'note' );

		$this->assertTrue( $this->sharing_enabled( $listen_id ) );
		$this->assertFalse( $this->sharing_enabled( $note_id ) );
	}

	public function test_default_decision_is_filterable() {
		add_filter(
			'pkiw_atmosphere_post_default_disabled',
			static function ( $disabled, $post, $kind ) {
				return 'checkin' === $kind ? false : $disabled;
			},
			10,
			3
		);

		$post_id = $this->make_kind_post( 'checkin' );

		$this->assertTrue( $this->sharing_enabled( $post_id ) );

		remove_all_filters( 'pkiw_atmosphere_post_default_disabled' );
	}

	public function test_other_meta_keys_are_untouched() {
		$post_id = $this->make_kind_post( 'listen' );

		$this->assertSame( '', get_post_meta( $post_id, 'some_unrelated_key', true ) );
	}

	public function test_unregister_removes_the_default() {
		if ( defined( 'ATMOSPHERE_VERSION' ) ) {
			$this->markTestSkipped( 'With ATmosphere loaded, the plugin coordinator keeps its own instance registered; this pins the standalone class behavior.' );
		}

		$post_id = $this->make_kind_post( 'checkin' );
		$this->assertFalse( $this->sharing_enabled( $post_id ) );

		$this->eligibility->unregister();

		$this->assertTrue( $this->sharing_enabled( $post_id ) );
	}
}
