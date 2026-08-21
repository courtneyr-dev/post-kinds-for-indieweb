<?php
/**
 * Tests for the ATmosphere document-record enrichment.
 *
 * The map hooks `atmosphere_transform_document` and only fills gaps:
 * a derived title when ATmosphere mapped an empty one, and the kind slug
 * as a tag. Fields ATmosphere maps correctly are never replaced.
 *
 * @package PKIW
 * @group   atmosphere
 */

namespace PKIW\Tests\Unit;

use PKIW\Integrations\Atmosphere_Document_Map;
use PKIW\Meta_Fields;

/**
 * Atmosphere_Document_Map tests.
 *
 * @group atmosphere
 */
class AtmosphereDocumentMapTest extends \WP_UnitTestCase {

	/**
	 * The instance under test.
	 *
	 * @var Atmosphere_Document_Map
	 */
	private Atmosphere_Document_Map $map;

	public function set_up(): void {
		parent::set_up();
		$this->map = new Atmosphere_Document_Map();
	}

	/**
	 * Create a post of a given kind.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $meta Meta suffix => value.
	 * @param array<string, mixed> $args Post args.
	 * @return \WP_Post
	 */
	private function make_kind_post( string $kind, array $meta = [], array $args = [] ): \WP_Post {
		$post_id = self::factory()->post->create( array_merge( [ 'post_title' => '' ], $args ) );
		wp_set_object_terms( $post_id, $kind, 'kind' );

		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, $value );
		}

		return get_post( $post_id );
	}

	/**
	 * A minimal record as ATmosphere's Document transformer shapes it.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function record( array $overrides = [] ): array {
		return array_merge(
			[
				'$type'       => 'site.standard.document',
				'title'       => '',
				'publishedAt' => '2026-08-21T00:00:00Z',
				'site'        => 'at://did:plc:abc/site.standard.publication/3xyz',
			],
			$overrides
		);
	}

	public function test_empty_title_is_filled_from_derivation() {
		$post = $this->make_kind_post(
			'listen',
			[
				'listen_track'  => 'Range Life',
				'listen_artist' => 'Pavement',
			]
		);

		$enriched = $this->map->enrich( $this->record(), $post );

		$this->assertSame( 'Listened to Range Life by Pavement', $enriched['title'] );
	}

	public function test_existing_title_is_never_replaced() {
		$post = $this->make_kind_post(
			'listen',
			[ 'listen_track' => 'Range Life' ],
			[ 'post_title' => 'My listening notes' ]
		);

		$enriched = $this->map->enrich( $this->record( [ 'title' => 'My listening notes' ] ), $post );

		$this->assertSame( 'My listening notes', $enriched['title'] );
	}

	public function test_kind_slug_is_appended_as_a_tag() {
		$post = $this->make_kind_post( 'review', [ 'review_item_name' => 'A book' ] );

		$enriched = $this->map->enrich( $this->record( [ 'tags' => [ 'books', 'fiction' ] ] ), $post );

		$this->assertSame( [ 'books', 'fiction', 'review' ], $enriched['tags'] );
	}

	public function test_kind_tag_is_added_when_record_has_no_tags() {
		$post = $this->make_kind_post( 'jam', [ 'jam_track' => 'Song' ] );

		$enriched = $this->map->enrich( $this->record(), $post );

		$this->assertSame( [ 'jam' ], $enriched['tags'] );
	}

	public function test_kind_tag_is_not_duplicated() {
		$post = $this->make_kind_post( 'review' );

		$enriched = $this->map->enrich( $this->record( [ 'tags' => [ 'Review' ] ] ), $post );

		$this->assertSame( [ 'Review' ], $enriched['tags'] );
	}

	public function test_kind_tag_is_filterable_and_null_disables_it() {
		$post = $this->make_kind_post( 'listen', [ 'listen_track' => 'Song' ] );

		add_filter( 'pkiw_atmosphere_document_kind_tag', '__return_null' );

		$enriched = $this->map->enrich( $this->record(), $post );

		remove_filter( 'pkiw_atmosphere_document_kind_tag', '__return_null' );

		$this->assertArrayNotHasKey( 'tags', $enriched );
	}

	public function test_other_fields_are_untouched() {
		$post = $this->make_kind_post( 'listen', [ 'listen_track' => 'Song' ] );

		$record = $this->record(
			[
				'description' => 'ATmosphere derived this.',
				'textContent' => 'Full text.',
				'coverImage'  => [ '$type' => 'blob' ],
			]
		);

		$enriched = $this->map->enrich( $record, $post );

		$this->assertSame( 'ATmosphere derived this.', $enriched['description'] );
		$this->assertSame( 'Full text.', $enriched['textContent'] );
		$this->assertSame( [ '$type' => 'blob' ], $enriched['coverImage'] );
		$this->assertSame( $record['site'], $enriched['site'] );
		$this->assertSame( $record['publishedAt'], $enriched['publishedAt'] );
	}

	public function test_post_without_kind_terms_passes_through() {
		$post_id = self::factory()->post->create( [ 'post_title' => '' ] );
		wp_set_object_terms( $post_id, [], 'kind' );

		$record   = $this->record( [ 'tags' => [ 'existing' ] ] );
		$enriched = $this->map->enrich( $record, get_post( $post_id ) );

		$this->assertSame( $record['tags'], $enriched['tags'] );
	}
}
