<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Taxonomy;

class TaxonomyTest extends WP_UnitTestCase {

	private Taxonomy $taxonomy;

	public function set_up(): void {
		parent::set_up();
		$this->taxonomy = new Taxonomy();
	}

	public function test_taxonomy_constant() {
		$this->assertSame( 'kind', Taxonomy::TAXONOMY );
	}

	public function test_taxonomy_is_registered() {
		$this->assertTrue( taxonomy_exists( Taxonomy::TAXONOMY ) );
	}

	public function test_taxonomy_is_public() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertTrue( $tax->public );
	}

	public function test_taxonomy_shows_in_rest() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertTrue( $tax->show_in_rest );
		$this->assertSame( 'kind', $tax->rest_base );
	}

	public function test_taxonomy_is_not_hierarchical() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertFalse( $tax->hierarchical );
	}

	public function test_taxonomy_attached_to_post() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertContains( 'post', $tax->object_type );
	}

	public function test_taxonomy_default_term_is_note() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertSame( 'note', $tax->default_term['slug'] );
	}

	public function test_get_default_kinds_returns_24() {
		$kinds = $this->taxonomy->get_default_kinds();
		$this->assertCount( 24, $kinds );
	}

	/**
	 * @dataProvider kind_slugs_provider
	 */
	public function test_default_kind_exists( string $slug ) {
		$kinds = $this->taxonomy->get_default_kinds();
		$this->assertArrayHasKey( $slug, $kinds );
	}

	public function kind_slugs_provider(): array {
		return [
			'note'        => [ 'note' ],
			'article'     => [ 'article' ],
			'reply'       => [ 'reply' ],
			'like'        => [ 'like' ],
			'repost'      => [ 'repost' ],
			'bookmark'    => [ 'bookmark' ],
			'rsvp'        => [ 'rsvp' ],
			'checkin'     => [ 'checkin' ],
			'listen'      => [ 'listen' ],
			'watch'       => [ 'watch' ],
			'read'        => [ 'read' ],
			'event'       => [ 'event' ],
			'photo'       => [ 'photo' ],
			'video'       => [ 'video' ],
			'review'      => [ 'review' ],
			'favorite'    => [ 'favorite' ],
			'jam'         => [ 'jam' ],
			'wish'        => [ 'wish' ],
			'mood'        => [ 'mood' ],
			'acquisition' => [ 'acquisition' ],
			'drink'       => [ 'drink' ],
			'eat'         => [ 'eat' ],
			'recipe'      => [ 'recipe' ],
			'play'        => [ 'play' ],
		];
	}

	/**
	 * @dataProvider kind_slugs_provider
	 */
	public function test_default_kind_has_name_and_description( string $slug ) {
		$kinds = $this->taxonomy->get_default_kinds();
		$this->assertNotEmpty( $kinds[ $slug ]['name'] );
		$this->assertNotEmpty( $kinds[ $slug ]['description'] );
	}

	public function test_set_and_get_post_kind() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'listen', Taxonomy::TAXONOMY );

		$result = $this->taxonomy->set_post_kind( $post_id, 'listen' );
		$this->assertTrue( $result );

		$term = $this->taxonomy->get_post_kind( $post_id );
		$this->assertNotNull( $term );
		$this->assertSame( 'listen', $term->slug );
	}

	public function test_get_post_kind_returns_null_when_unset() {
		$post_id = self::factory()->post->create();
		$term    = $this->taxonomy->get_post_kind( $post_id );
		$this->assertNull( $term );
	}

	public function test_is_valid_kind_with_existing_term() {
		wp_insert_term( 'note', Taxonomy::TAXONOMY );
		$this->assertTrue( $this->taxonomy->is_valid_kind( 'note' ) );
	}

	public function test_is_valid_kind_with_nonexistent_term() {
		$this->assertFalse( $this->taxonomy->is_valid_kind( 'nonexistent_kind_xyz' ) );
	}

	public function test_get_kinds_returns_terms() {
		wp_insert_term( 'note', Taxonomy::TAXONOMY );
		wp_insert_term( 'like', Taxonomy::TAXONOMY );

		$kinds = $this->taxonomy->get_kinds();
		$this->assertIsArray( $kinds );
		$this->assertNotEmpty( $kinds );
	}

	public function test_get_post_types_includes_post() {
		$this->assertContains( 'post', $this->taxonomy->get_post_types() );
	}

	public function test_taxonomy_capabilities() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertSame( 'manage_categories', $tax->cap->manage_terms );
		$this->assertSame( 'edit_posts', $tax->cap->assign_terms );
	}

	public function test_filter_term_link_passes_through_other_taxonomies() {
		$term_data = wp_insert_term( 'test-cat', 'category' );
		$wp_term   = get_term( $term_data['term_id'], 'category' );
		$result    = $this->taxonomy->filter_term_link( 'http://example.com/test', $wp_term, 'category' );
		$this->assertSame( 'http://example.com/test', $result );
	}
}
