<?php
namespace PKIW\Tests\Unit;

use WP_UnitTestCase;
use PKIW\Taxonomy;

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

	// --- first-block kind sync ------------------------------------------------

	private function ensure_kind_terms( string ...$slugs ): void {
		foreach ( $slugs as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( $slug, Taxonomy::TAXONOMY );
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function kind_slugs( int $post_id ): array {
		$slugs = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
		return is_array( $slugs ) ? $slugs : [];
	}

	public function test_kind_card_blocks_map_only_default_kinds() {
		$default_kinds = $this->taxonomy->get_default_kinds();
		foreach ( Taxonomy::KIND_CARD_BLOCKS as $block_name => $kind ) {
			$this->assertArrayHasKey( $kind, $default_kinds, "$block_name maps to unknown kind $kind" );
		}
	}

	public function test_get_first_block_kind_maps_every_card_block() {
		foreach ( Taxonomy::KIND_CARD_BLOCKS as $block_name => $kind ) {
			$this->assertSame(
				$kind,
				Taxonomy::get_first_block_kind( "<!-- wp:$block_name /-->" )
			);
		}
	}

	public function test_get_first_block_kind_skips_leading_whitespace() {
		$this->assertSame(
			'eat',
			Taxonomy::get_first_block_kind( "\n\n<!-- wp:post-kinds-indieweb/eat-card /-->\n" )
		);
	}

	public function test_get_first_block_kind_null_for_plain_text_and_non_card_first_block() {
		$this->assertNull( Taxonomy::get_first_block_kind( '' ) );
		$this->assertNull( Taxonomy::get_first_block_kind( 'just a plain note' ) );
		// Only the FIRST block counts — a card further down doesn't.
		$this->assertNull(
			Taxonomy::get_first_block_kind(
				"<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->\n<!-- wp:post-kinds-indieweb/eat-card /-->"
			)
		);
	}

	public function test_save_assigns_kind_from_first_card_block() {
		$this->ensure_kind_terms( 'eat' );

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/eat-card {"name":"Pizza"} /-->' ]
		);

		$this->assertSame( [ 'eat' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( 'eat', get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_save_overrides_note_default_term() {
		// The test suite's between-class cleanup deletes the bootstrap-created
		// `note` term while `default_term_kind` still points at it, silently
		// breaking core's default stamping. Re-registering recreates the term
		// and repairs the option — what init does on a real site.
		$this->taxonomy->register_taxonomy();
		$this->ensure_kind_terms( 'checkin' );
		// Core only stamps the `note` default_term when the acting user can
		// assign terms — create as an author like a real editor session.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$post_id = self::factory()->post->create(
			[
				'post_content' => 'plain text, no blocks',
				'post_status'  => 'publish',
			]
		);
		$this->assertSame( [ 'note' ], $this->kind_slugs( $post_id ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/checkin-card /-->',
			]
		);

		$this->assertSame( [ 'checkin' ], $this->kind_slugs( $post_id ) );
	}

	public function test_save_respects_manually_chosen_kind() {
		$this->ensure_kind_terms( 'eat', 'photo' );

		$post_id = self::factory()->post->create( [ 'post_content' => 'plain' ] );
		wp_set_post_terms( $post_id, [ 'photo' ], Taxonomy::TAXONOMY );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/eat-card /-->',
			]
		);

		$this->assertSame( [ 'photo' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_changing_first_block_resyncs_auto_assigned_kind() {
		$this->ensure_kind_terms( 'eat', 'drink' );

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/eat-card /-->' ]
		);
		$this->assertSame( [ 'eat' ], $this->kind_slugs( $post_id ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/drink-card /-->',
			]
		);

		$this->assertSame( [ 'drink' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( 'drink', get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_manual_change_after_auto_assign_is_never_overridden() {
		$this->ensure_kind_terms( 'eat', 'watch' );

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/eat-card /-->' ]
		);
		$this->assertSame( [ 'eat' ], $this->kind_slugs( $post_id ) );

		// A person re-kinds the post; the stale auto marker still says eat.
		wp_set_post_terms( $post_id, [ 'watch' ], Taxonomy::TAXONOMY );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/eat-card {"name":"edited"} /-->',
			]
		);

		$this->assertSame( [ 'watch' ], $this->kind_slugs( $post_id ) );
	}

	public function test_sync_skips_kind_without_registered_term() {
		// The sync must not invent a term the site doesn't have.
		$term = get_term_by( 'slug', 'jam', Taxonomy::TAXONOMY );
		if ( $term instanceof \WP_Term ) {
			wp_delete_term( $term->term_id, Taxonomy::TAXONOMY );
		}

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/jam-card /-->' ]
		);

		$this->assertSame( [], $this->kind_slugs( $post_id ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_sync_ignores_non_card_content() {
		$this->ensure_kind_terms( 'eat' );

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:paragraph --><p>dinner was great</p><!-- /wp:paragraph -->' ]
		);

		$this->assertSame( [], $this->kind_slugs( $post_id ) );
	}
}
