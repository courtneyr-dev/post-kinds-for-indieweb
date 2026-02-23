<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Venue_Taxonomy;

class VenueTaxonomyTest extends WP_UnitTestCase {

	private Venue_Taxonomy $venue_taxonomy;

	public function set_up(): void {
		parent::set_up();
		$this->venue_taxonomy = new Venue_Taxonomy();
		$this->venue_taxonomy->register_taxonomy();
		$this->venue_taxonomy->register_term_meta();
	}

	public function test_taxonomy_constant() {
		$this->assertSame( 'venue', Venue_Taxonomy::TAXONOMY );
	}

	public function test_taxonomy_is_registered() {
		$this->assertTrue( taxonomy_exists( Venue_Taxonomy::TAXONOMY ) );
	}

	public function test_taxonomy_is_public() {
		$tax = get_taxonomy( Venue_Taxonomy::TAXONOMY );
		$this->assertTrue( $tax->public );
	}

	public function test_taxonomy_shows_in_rest() {
		$tax = get_taxonomy( Venue_Taxonomy::TAXONOMY );
		$this->assertTrue( $tax->show_in_rest );
	}

	public function test_taxonomy_is_not_hierarchical() {
		$tax = get_taxonomy( Venue_Taxonomy::TAXONOMY );
		$this->assertFalse( $tax->hierarchical );
	}

	public function test_taxonomy_attached_to_post() {
		$tax = get_taxonomy( Venue_Taxonomy::TAXONOMY );
		$this->assertContains( 'post', $tax->object_type );
	}

	public function test_taxonomy_rewrite_slug() {
		$tax = get_taxonomy( Venue_Taxonomy::TAXONOMY );
		$this->assertSame( 'venue', $tax->rewrite['slug'] );
		$this->assertFalse( $tax->rewrite['with_front'] );
	}

	public function test_taxonomy_show_admin_column() {
		$tax = get_taxonomy( Venue_Taxonomy::TAXONOMY );
		$this->assertTrue( $tax->show_admin_column );
	}

	public function test_create_venue_term() {
		$result = wp_insert_term( 'Test Cafe', Venue_Taxonomy::TAXONOMY );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'term_id', $result );

		$term = get_term( $result['term_id'], Venue_Taxonomy::TAXONOMY );
		$this->assertSame( 'Test Cafe', $term->name );
	}

	public function test_update_venue_term() {
		$result = wp_insert_term( 'Old Name', Venue_Taxonomy::TAXONOMY );
		wp_update_term( $result['term_id'], Venue_Taxonomy::TAXONOMY, [ 'name' => 'New Name' ] );

		$term = get_term( $result['term_id'], Venue_Taxonomy::TAXONOMY );
		$this->assertSame( 'New Name', $term->name );
	}

	public function test_delete_venue_term() {
		$result = wp_insert_term( 'Deletable Venue', Venue_Taxonomy::TAXONOMY );
		wp_delete_term( $result['term_id'], Venue_Taxonomy::TAXONOMY );

		$term = get_term( $result['term_id'], Venue_Taxonomy::TAXONOMY );
		$this->assertNull( $term );
	}

	public function test_venue_term_meta_address() {
		$result = wp_insert_term( 'Meta Venue', Venue_Taxonomy::TAXONOMY );
		update_term_meta( $result['term_id'], 'address', '123 Main St' );

		$address = get_term_meta( $result['term_id'], 'address', true );
		$this->assertSame( '123 Main St', $address );
	}

	public function test_venue_term_meta_coordinates() {
		$result = wp_insert_term( 'Geo Venue', Venue_Taxonomy::TAXONOMY );
		update_term_meta( $result['term_id'], 'latitude', 40.7128 );
		update_term_meta( $result['term_id'], 'longitude', -74.006 );

		$lat = get_term_meta( $result['term_id'], 'latitude', true );
		$lng = get_term_meta( $result['term_id'], 'longitude', true );
		$this->assertEquals( 40.7128, $lat );
		$this->assertEquals( -74.006, $lng );
	}

	public function test_create_or_get_creates_new_venue() {
		$term_id = Venue_Taxonomy::create_or_get( [
			'name'    => 'Brand New Venue',
			'city'    => 'Portland',
			'country' => 'US',
		] );

		$this->assertIsInt( $term_id );
		$term = get_term( $term_id, Venue_Taxonomy::TAXONOMY );
		$this->assertSame( 'Brand New Venue', $term->name );
		$this->assertSame( 'Portland', get_term_meta( $term_id, 'city', true ) );
	}

	public function test_create_or_get_returns_existing_by_name() {
		$first_id = Venue_Taxonomy::create_or_get( [ 'name' => 'Duplicate Venue' ] );
		$second_id = Venue_Taxonomy::create_or_get( [ 'name' => 'Duplicate Venue' ] );

		$this->assertSame( $first_id, $second_id );
	}

	public function test_create_or_get_returns_error_without_name() {
		$result = Venue_Taxonomy::create_or_get( [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_create_or_get_returns_error_with_empty_name() {
		$result = Venue_Taxonomy::create_or_get( [ 'name' => '' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_create_or_get_stores_meta_fields() {
		$term_id = Venue_Taxonomy::create_or_get( [
			'name'          => 'Full Meta Venue',
			'address'       => '456 Oak Ave',
			'city'          => 'Seattle',
			'region'        => 'WA',
			'postal_code'   => '98101',
			'country'       => 'US',
			'foursquare_id' => 'abc123',
			'url'           => 'https://example.com',
			'phone'         => '555-0100',
		] );

		$this->assertSame( '456 Oak Ave', get_term_meta( $term_id, 'address', true ) );
		$this->assertSame( 'Seattle', get_term_meta( $term_id, 'city', true ) );
		$this->assertSame( 'WA', get_term_meta( $term_id, 'region', true ) );
		$this->assertSame( '98101', get_term_meta( $term_id, 'postal_code', true ) );
		$this->assertSame( 'US', get_term_meta( $term_id, 'country', true ) );
		$this->assertSame( 'abc123', get_term_meta( $term_id, 'foursquare_id', true ) );
		$this->assertSame( 'https://example.com', get_term_meta( $term_id, 'url', true ) );
		$this->assertSame( '555-0100', get_term_meta( $term_id, 'phone', true ) );
	}

	public function test_get_by_foursquare_id_returns_term() {
		$term_id = Venue_Taxonomy::create_or_get( [
			'name'          => 'Foursquare Venue',
			'foursquare_id' => 'fsq_xyz',
		] );

		$found = Venue_Taxonomy::get_by_foursquare_id( 'fsq_xyz' );
		$this->assertInstanceOf( \WP_Term::class, $found );
		$this->assertSame( $term_id, $found->term_id );
	}

	public function test_get_by_foursquare_id_returns_null_for_unknown() {
		$found = Venue_Taxonomy::get_by_foursquare_id( 'nonexistent_id' );
		$this->assertNull( $found );
	}

	public function test_create_or_get_finds_existing_by_foursquare_id() {
		$first_id = Venue_Taxonomy::create_or_get( [
			'name'          => 'FSQ Venue',
			'foursquare_id' => 'fsq_dedup',
		] );

		$second_id = Venue_Taxonomy::create_or_get( [
			'name'          => 'FSQ Venue Renamed',
			'foursquare_id' => 'fsq_dedup',
		] );

		$this->assertSame( $first_id, $second_id );
	}

	public function test_add_columns_inserts_location_after_name() {
		$columns = [
			'cb'          => '<input type="checkbox" />',
			'name'        => 'Name',
			'description' => 'Description',
			'slug'        => 'Slug',
			'posts'       => 'Count',
		];

		$result = $this->venue_taxonomy->add_columns( $columns );
		$keys   = array_keys( $result );
		$name_pos     = array_search( 'name', $keys, true );
		$location_pos = array_search( 'location', $keys, true );

		$this->assertNotFalse( $location_pos );
		$this->assertSame( $name_pos + 1, $location_pos );
	}

	public function test_render_column_returns_location_string() {
		$result  = wp_insert_term( 'Column Venue', Venue_Taxonomy::TAXONOMY );
		$term_id = $result['term_id'];
		update_term_meta( $term_id, 'city', 'Austin' );
		update_term_meta( $term_id, 'country', 'US' );

		$content = $this->venue_taxonomy->render_column( '', 'location', $term_id );
		$this->assertStringContainsString( 'Austin', $content );
		$this->assertStringContainsString( 'US', $content );
	}

	public function test_render_column_returns_dash_without_location() {
		$result  = wp_insert_term( 'No Location Venue', Venue_Taxonomy::TAXONOMY );
		$content = $this->venue_taxonomy->render_column( '', 'location', $result['term_id'] );
		$this->assertSame( '—', $content );
	}

	public function test_render_column_passes_through_other_columns() {
		$content = $this->venue_taxonomy->render_column( 'original', 'other_column', 1 );
		$this->assertSame( 'original', $content );
	}

	public function test_save_venue_meta_from_post_data() {
		$result  = wp_insert_term( 'POST Venue', Venue_Taxonomy::TAXONOMY );
		$term_id = $result['term_id'];

		$_POST['venue_address'] = '789 Elm St';
		$_POST['venue_city']    = 'Denver';

		$this->venue_taxonomy->save_venue_meta( $term_id );

		$this->assertSame( '789 Elm St', get_term_meta( $term_id, 'address', true ) );
		$this->assertSame( 'Denver', get_term_meta( $term_id, 'city', true ) );

		unset( $_POST['venue_address'], $_POST['venue_city'] );
	}

	public function test_save_venue_meta_deletes_empty_values() {
		$result  = wp_insert_term( 'Clear Venue', Venue_Taxonomy::TAXONOMY );
		$term_id = $result['term_id'];
		update_term_meta( $term_id, 'city', 'OldCity' );

		$_POST['venue_city'] = '';
		$this->venue_taxonomy->save_venue_meta( $term_id );

		$this->assertEmpty( get_term_meta( $term_id, 'city', true ) );

		unset( $_POST['venue_city'] );
	}
}
