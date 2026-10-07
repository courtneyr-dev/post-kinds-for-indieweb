<?php
/**
 * The kind facts reader: one function per kind, read through one door.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * kind_facts() hands a theme the facts a kind's reader returns, and only
 * those a visitor may see: nothing for a post they can't read, and
 * location facts cut down to the post's public location tier.
 *
 * @group integration
 */
final class KindFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function tear_down(): void {
		$registered = new ReflectionProperty( Kind_Facts::class, 'registered' );
		$registered->setAccessible( true );
		$registered->setValue( null, [] );
		parent::tear_down();
	}

	/**
	 * A published post of one kind.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $args Post args.
	 */
	private function kind_post( string $kind, array $args = [] ): int {
		$post_id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish' ], $args ) );
		wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * A jam reader that reports a venue at every location tier, plus a title.
	 */
	private function register_jam_reader_with_a_venue(): void {
		Kind_Facts::register(
			'jam',
			static fn( int $post_id ): array => [
				'title'    => get_the_title( $post_id ),
				'venue'    => 'The Hideout',
				'locality' => 'Chicago',
				'street'   => '1354 W Wabansia Ave',
				'lat'      => 41.913,
				'map'      => [ 41.913, -87.662 ],
			],
			[
				'venue'    => 'name',
				'locality' => 'locality',
				'street'   => 'street',
				'lat'      => 'coordinates',
				'map'      => 'map',
			]
		);
	}

	public function test_a_recipe_post_reads_the_same_facts_as_recipe_facts(): void {
		$post_id = $this->kind_post( 'recipe' );
		update_post_meta( $post_id, '_pkiw_recipe_duration', 'PT1H30M' );
		update_post_meta( $post_id, '_pkiw_recipe_yield', '4 bowls' );

		$facts = \PKIW\kind_facts( $post_id );

		$this->assertSame( 90, $facts['total_minutes'] );
		$this->assertSame( \PKIW\recipe_facts( $post_id ), $facts );
	}

	public function test_a_post_with_no_kind_has_no_facts(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assertSame( [], \PKIW\kind_facts( $post_id ) );
	}

	public function test_a_kind_with_no_reader_has_no_facts(): void {
		$this->assertSame( [], \PKIW\kind_facts( $this->kind_post( 'listen' ) ) );
	}

	public function test_a_missing_post_has_no_facts(): void {
		$this->assertSame( [], \PKIW\kind_facts( PHP_INT_MAX ) );
	}

	public function test_a_registered_reader_answers_for_its_kind_only(): void {
		Kind_Facts::register( 'jam', static fn( int $post_id ): array => [ 'title' => get_the_title( $post_id ) ] );
		$jam    = $this->kind_post( 'jam', [ 'post_title' => 'Blue in Green' ] );
		$listen = $this->kind_post( 'listen', [ 'post_title' => 'So What' ] );

		$this->assertSame( [ 'title' => 'Blue in Green' ], \PKIW\kind_facts( $jam ) );
		$this->assertSame( [], \PKIW\kind_facts( $listen ) );
	}

	public function test_an_approximate_location_keeps_the_place_and_drops_the_street_and_coordinates(): void {
		$this->register_jam_reader_with_a_venue();
		$post_id = $this->kind_post( 'jam', [ 'post_title' => 'Late set' ] );

		$facts = \PKIW\kind_facts( $post_id );

		$this->assertSame( 'Late set', $facts['title'] );
		$this->assertSame( 'The Hideout', $facts['venue'] );
		$this->assertSame( 'Chicago', $facts['locality'] );
		$this->assertSame( '', $facts['street'] );
		$this->assertSame( 0, $facts['lat'] );
		$this->assertSame( [], $facts['map'] );
	}

	public function test_a_public_location_keeps_every_location_fact(): void {
		$this->register_jam_reader_with_a_venue();
		$post_id = $this->kind_post( 'jam' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'public' );

		$facts = \PKIW\kind_facts( $post_id );

		$this->assertSame( '1354 W Wabansia Ave', $facts['street'] );
		$this->assertSame( 41.913, $facts['lat'] );
	}

	public function test_a_private_location_drops_every_location_fact_and_keeps_the_rest(): void {
		$this->register_jam_reader_with_a_venue();
		$post_id = $this->kind_post( 'jam', [ 'post_title' => 'Late set' ] );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		$facts = \PKIW\kind_facts( $post_id );

		$this->assertSame( 'Late set', $facts['title'] );
		$this->assertSame( '', $facts['venue'] );
		$this->assertSame( '', $facts['locality'] );
		$this->assertSame( '', $facts['street'] );
	}

	public function test_an_editor_gets_the_public_facts_too(): void {
		$this->register_jam_reader_with_a_venue();
		$post_id = $this->kind_post( 'jam' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( '', \PKIW\kind_facts( $post_id )['street'] );
	}

	public function test_a_filter_cannot_bring_back_a_hidden_location_fact(): void {
		$this->register_jam_reader_with_a_venue();
		$post_id = $this->kind_post( 'jam' );
		$restore = static function ( array $facts ): array {
			$facts['street'] = '1354 W Wabansia Ave';
			$facts['extra']  = 'added';
			return $facts;
		};
		add_filter( 'pkiw_kind_facts', $restore );

		$facts = \PKIW\kind_facts( $post_id );

		$this->assertSame( '', $facts['street'] );
		$this->assertSame( 'added', $facts['extra'] );
	}

	public function test_a_location_fact_with_an_unknown_tier_stays_hidden(): void {
		Kind_Facts::register( 'jam', static fn(): array => [ 'spot' => 'Booth 4' ], [ 'spot' => 'no-such-tier' ] );
		$post_id = $this->kind_post( 'jam' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'public' );

		$this->assertSame( '', \PKIW\kind_facts( $post_id )['spot'] );
	}

	public function test_a_password_protected_post_has_no_facts(): void {
		$post_id = $this->kind_post( 'recipe', [ 'post_password' => 'secret' ] );
		update_post_meta( $post_id, '_pkiw_recipe_yield', '4 bowls' );

		$this->assertSame( [], \PKIW\kind_facts( $post_id ) );
	}

	public function test_a_draft_has_facts_only_for_someone_who_can_read_it(): void {
		$post_id = $this->kind_post( 'recipe', [ 'post_status' => 'draft' ] );
		update_post_meta( $post_id, '_pkiw_recipe_yield', '4 bowls' );

		$this->assertSame( [], \PKIW\kind_facts( $post_id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( '4 bowls', \PKIW\kind_facts( $post_id )['yield'] );
	}

	public function test_a_reader_that_returns_no_array_gives_no_facts(): void {
		Kind_Facts::register( 'jam', static fn() => 'not facts' );

		$this->assertSame( [], \PKIW\kind_facts( $this->kind_post( 'jam' ) ) );
	}
}
