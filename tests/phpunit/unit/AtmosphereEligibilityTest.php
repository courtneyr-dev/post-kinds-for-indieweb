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

	public function test_content_kinds_default_to_enabled() {
		foreach ( [ 'note', 'article', 'photo', 'video', 'audio', 'review', 'recipe', 'event', 'jam', 'quote', 'question', 'craft' ] as $kind ) {
			$post_id = $this->make_kind_post( $kind );

			$this->assertTrue( $this->sharing_enabled( $post_id ), "kind: $kind" );
		}
	}

	public function test_reaction_and_consumption_kinds_default_to_disabled() {
		foreach ( [ 'like', 'reply', 'repost', 'bookmark', 'rsvp', 'favorite', 'follow', 'listen', 'watch', 'read', 'checkin', 'play', 'eat', 'drink', 'wish', 'mood', 'acquisition', 'weather', 'exercise', 'sleep', 'trip', 'itinerary' ] as $kind ) {
			$post_id = $this->make_kind_post( $kind );

			$this->assertFalse( $this->sharing_enabled( $post_id ), "kind: $kind" );
		}
	}

	public function test_explicit_author_choice_wins_over_the_default() {
		$post_id = $this->make_kind_post( 'listen' );

		// Author flips ATmosphere's own toggle back on: stored meta wins.
		update_post_meta( $post_id, 'atmosphere_disabled', '' );

		$this->assertTrue( $this->sharing_enabled( $post_id ) );

		// And an explicit opt-out on a content kind wins too.
		$note_id = $this->make_kind_post( 'note' );
		update_post_meta( $note_id, 'atmosphere_disabled', '1' );

		$this->assertFalse( $this->sharing_enabled( $note_id ) );
	}

	public function test_already_published_posts_are_never_default_disabled() {
		$post_id = $this->make_kind_post( 'listen' );
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
				return 'listen' === $kind ? false : $disabled;
			},
			10,
			3
		);

		$post_id = $this->make_kind_post( 'listen' );

		$this->assertTrue( $this->sharing_enabled( $post_id ) );

		remove_all_filters( 'pkiw_atmosphere_post_default_disabled' );
	}

	public function test_other_meta_keys_are_untouched() {
		$post_id = $this->make_kind_post( 'listen' );

		$this->assertSame( '', get_post_meta( $post_id, 'some_unrelated_key', true ) );
	}

	public function test_unregister_removes_the_default() {
		$post_id = $this->make_kind_post( 'listen' );
		$this->assertFalse( $this->sharing_enabled( $post_id ) );

		$this->eligibility->unregister();

		$this->assertTrue( $this->sharing_enabled( $post_id ) );
	}
}
