<?php
/**
 * Test the Meta Fields class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Meta_Fields;

/**
 * Test the Meta_Fields class functionality.
 */
class MetaFieldsTest extends WP_UnitTestCase {

	/**
	 * Meta_Fields instance.
	 *
	 * @var Meta_Fields
	 */
	private Meta_Fields $meta_fields;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->meta_fields = new Meta_Fields();
	}

	/**
	 * Test that the meta prefix is correct.
	 */
	public function test_meta_prefix() {
		$this->assertEquals( '_postkind_', Meta_Fields::PREFIX );
	}

	/**
	 * Test that meta fields are registered.
	 */
	public function test_meta_fields_registered() {
		// Create a test post.
		$post_id = $this->factory->post->create();

		// Check that we can save and retrieve a meta value.
		$meta_key = Meta_Fields::PREFIX . 'cite_url';
		update_post_meta( $post_id, $meta_key, 'https://example.com' );

		$this->assertEquals( 'https://example.com', get_post_meta( $post_id, $meta_key, true ) );
	}

	/**
	 * Test the get_fields method returns an array.
	 */
	public function test_get_fields_returns_array() {
		$fields = Meta_Fields::get_fields();
		$this->assertIsArray( $fields );
		$this->assertNotEmpty( $fields );
	}

	/**
	 * Test that common fields exist.
	 */
	public function test_common_fields_exist() {
		$fields = Meta_Fields::get_fields();

		// Check for common meta keys.
		$expected_keys = [
			'cite_url',
			'cite_name',
			'cite_author',
			'rsvp_status',
			'watch_status',
			'read_status',
			'play_status',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $fields, "Field '{$key}' should exist" );
		}
	}

	/**
	 * Test sanitize_rsvp_status with valid values.
	 *
	 * @dataProvider rsvp_status_valid_provider
	 */
	public function test_sanitize_rsvp_status_valid( string $input, string $expected ) {
		$result = $this->meta_fields->sanitize_rsvp_status( $input );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Data provider for valid RSVP status values.
	 */
	public function rsvp_status_valid_provider(): array {
		return [
			'yes'        => [ 'yes', 'yes' ],
			'no'         => [ 'no', 'no' ],
			'maybe'      => [ 'maybe', 'maybe' ],
			'interested' => [ 'interested', 'interested' ],
			'empty'      => [ '', '' ],
		];
	}

	/**
	 * Test sanitize_rsvp_status with invalid values returns empty string.
	 *
	 * @dataProvider rsvp_status_invalid_provider
	 */
	public function test_sanitize_rsvp_status_invalid( $input ) {
		$result = $this->meta_fields->sanitize_rsvp_status( $input );
		$this->assertEquals( '', $result );
	}

	/**
	 * Data provider for invalid RSVP status values.
	 */
	public function rsvp_status_invalid_provider(): array {
		return [
			'invalid string' => [ 'invalid' ],
			'number'         => [ 123 ],
			'uppercase'      => [ 'YES' ],
			'script'         => [ '<script>alert(1)</script>' ],
		];
	}

	/**
	 * Test sanitize_watch_status with valid values.
	 *
	 * @dataProvider watch_status_valid_provider
	 */
	public function test_sanitize_watch_status_valid( string $input, string $expected ) {
		$result = $this->meta_fields->sanitize_watch_status( $input );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Data provider for valid watch status values.
	 */
	public function watch_status_valid_provider(): array {
		return [
			'to-watch'   => [ 'to-watch', 'to-watch' ],
			'watching'   => [ 'watching', 'watching' ],
			'watched'    => [ 'watched', 'watched' ],
			'rewatching' => [ 'rewatching', 'rewatching' ],
			'abandoned'  => [ 'abandoned', 'abandoned' ],
		];
	}

	/**
	 * Test sanitize_watch_status with invalid values returns 'to-watch'.
	 */
	public function test_sanitize_watch_status_invalid() {
		$result = $this->meta_fields->sanitize_watch_status( 'invalid' );
		$this->assertEquals( 'to-watch', $result );
	}

	/**
	 * Test sanitize_read_status with valid values.
	 *
	 * @dataProvider read_status_valid_provider
	 */
	public function test_sanitize_read_status_valid( string $input, string $expected ) {
		$result = $this->meta_fields->sanitize_read_status( $input );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Data provider for valid read status values.
	 */
	public function read_status_valid_provider(): array {
		return [
			'to-read'   => [ 'to-read', 'to-read' ],
			'reading'   => [ 'reading', 'reading' ],
			'finished'  => [ 'finished', 'finished' ],
			'abandoned' => [ 'abandoned', 'abandoned' ],
		];
	}

	/**
	 * Test sanitize_read_status with invalid values returns 'to-read'.
	 */
	public function test_sanitize_read_status_invalid() {
		$result = $this->meta_fields->sanitize_read_status( 'invalid' );
		$this->assertEquals( 'to-read', $result );
	}

	/**
	 * Test sanitize_play_status with valid values.
	 *
	 * @dataProvider play_status_valid_provider
	 */
	public function test_sanitize_play_status_valid( string $input, string $expected ) {
		$result = $this->meta_fields->sanitize_play_status( $input );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Data provider for valid play status values.
	 */
	public function play_status_valid_provider(): array {
		return [
			'playing'   => [ 'playing', 'playing' ],
			'completed' => [ 'completed', 'completed' ],
			'abandoned' => [ 'abandoned', 'abandoned' ],
			'backlog'   => [ 'backlog', 'backlog' ],
			'wishlist'  => [ 'wishlist', 'wishlist' ],
		];
	}

	/**
	 * Test sanitize_play_status with invalid values returns 'playing'.
	 */
	public function test_sanitize_play_status_invalid() {
		$result = $this->meta_fields->sanitize_play_status( 'invalid' );
		$this->assertEquals( 'playing', $result );
	}

	/**
	 * Test sanitize_coordinate with valid values.
	 *
	 * @dataProvider coordinate_valid_provider
	 */
	public function test_sanitize_coordinate_valid( $input, float $expected ) {
		$result = $this->meta_fields->sanitize_coordinate( $input );
		$this->assertEqualsWithDelta( $expected, $result, 0.000001 );
	}

	/**
	 * Data provider for valid coordinate values.
	 */
	public function coordinate_valid_provider(): array {
		return [
			'positive float'  => [ 37.7749, 37.7749 ],
			'negative float'  => [ -122.4194, -122.4194 ],
			'string float'    => [ '51.5074', 51.5074 ],
			'zero'            => [ 0, 0.0 ],
			'max latitude'    => [ 90.0, 90.0 ],
			'min latitude'    => [ -90.0, -90.0 ],
			'max longitude'   => [ 180.0, 180.0 ],
			'min longitude'   => [ -180.0, -180.0 ],
		];
	}

	/**
	 * Test sanitize_coordinate clamps out-of-range values.
	 *
	 * @dataProvider coordinate_clamped_provider
	 */
	public function test_sanitize_coordinate_clamped( $input, float $expected ) {
		$result = $this->meta_fields->sanitize_coordinate( $input );
		$this->assertEqualsWithDelta( $expected, $result, 0.000001 );
	}

	/**
	 * Data provider for clamped coordinate values.
	 */
	public function coordinate_clamped_provider(): array {
		return [
			'over 180'    => [ 200.0, 180.0 ],
			'under -180'  => [ -200.0, -180.0 ],
		];
	}

	/**
	 * Test sanitize_coordinate with invalid input returns 0.
	 *
	 * @dataProvider coordinate_invalid_provider
	 */
	public function test_sanitize_coordinate_invalid( $input ) {
		$result = $this->meta_fields->sanitize_coordinate( $input );
		$this->assertEquals( 0.0, $result );
	}

	/**
	 * Data provider for invalid coordinate values.
	 */
	public function coordinate_invalid_provider(): array {
		return [
			'non-numeric string' => [ 'abc' ],
			'script'             => [ '<script>alert(1)</script>' ],
		];
	}

	/**
	 * Test sanitize_geo_privacy with valid values.
	 *
	 * @dataProvider geo_privacy_valid_provider
	 */
	public function test_sanitize_geo_privacy_valid( string $input, string $expected ) {
		$result = $this->meta_fields->sanitize_geo_privacy( $input );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Data provider for valid geo privacy values.
	 */
	public function geo_privacy_valid_provider(): array {
		return [
			'public'      => [ 'public', 'public' ],
			'approximate' => [ 'approximate', 'approximate' ],
			'private'     => [ 'private', 'private' ],
		];
	}

	/**
	 * Test sanitize_geo_privacy with invalid values returns 'approximate'.
	 */
	public function test_sanitize_geo_privacy_invalid() {
		$result = $this->meta_fields->sanitize_geo_privacy( 'invalid' );
		$this->assertEquals( 'approximate', $result );
	}

	/**
	 * Test sanitize_rating with valid values.
	 *
	 * @dataProvider rating_valid_provider
	 */
	public function test_sanitize_rating_valid( $input, float $expected ) {
		$result = $this->meta_fields->sanitize_rating( $input );
		$this->assertEqualsWithDelta( $expected, $result, 0.01 );
	}

	/**
	 * Data provider for valid rating values.
	 */
	public function rating_valid_provider(): array {
		return [
			'zero'         => [ 0, 0.0 ],
			'half star'    => [ 0.5, 0.5 ],
			'one star'     => [ 1, 1.0 ],
			'five stars'   => [ 5, 5.0 ],
			'string float' => [ '4.5', 4.5 ],
		];
	}

	/**
	 * Test sanitize_rating clamps out-of-range values.
	 *
	 * @dataProvider rating_clamped_provider
	 */
	public function test_sanitize_rating_clamped( $input, float $expected ) {
		$result = $this->meta_fields->sanitize_rating( $input );
		$this->assertEqualsWithDelta( $expected, $result, 0.01 );
	}

	/**
	 * Data provider for clamped rating values.
	 */
	public function rating_clamped_provider(): array {
		return [
			'negative'  => [ -1, 0.0 ],
			'over five' => [ 10, 5.0 ],
		];
	}

	/**
	 * Test sanitize_isbn with valid ISBN formats.
	 *
	 * @dataProvider isbn_valid_provider
	 */
	public function test_sanitize_isbn_valid( string $input, string $expected ) {
		$result = $this->meta_fields->sanitize_isbn( $input );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Data provider for valid ISBN values.
	 */
	public function isbn_valid_provider(): array {
		return [
			'isbn-10'          => [ '0-306-40615-2', '0306406152' ],
			'isbn-10 no dash'  => [ '0306406152', '0306406152' ],
			'isbn-13'          => [ '978-3-16-148410-0', '9783161484100' ],
			'isbn-13 no dash'  => [ '9783161484100', '9783161484100' ],
			'isbn-10 with X'   => [ '080442957X', '080442957X' ],
		];
	}

	/**
	 * Test sanitize_isbn with invalid values returns empty string.
	 *
	 * @dataProvider isbn_invalid_provider
	 */
	public function test_sanitize_isbn_invalid( string $input ) {
		$result = $this->meta_fields->sanitize_isbn( $input );
		$this->assertEquals( '', $result );
	}

	/**
	 * Data provider for invalid ISBN values.
	 */
	public function isbn_invalid_provider(): array {
		return [
			'too short'     => [ '123' ],
			'letters'       => [ 'ABCDEFGHIJ' ],
			'script'        => [ '<script>alert(1)</script>' ],
		];
	}

	/**
	 * Test sanitize_priority with valid values.
	 *
	 * @dataProvider priority_valid_provider
	 */
	public function test_sanitize_priority_valid( string $input, string $expected ) {
		$result = $this->meta_fields->sanitize_priority( $input );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Data provider for valid priority values.
	 */
	public function priority_valid_provider(): array {
		return [
			'low'    => [ 'low', 'low' ],
			'medium' => [ 'medium', 'medium' ],
			'high'   => [ 'high', 'high' ],
		];
	}

	/**
	 * Test sanitize_priority with invalid values returns 'medium'.
	 */
	public function test_sanitize_priority_invalid() {
		$result = $this->meta_fields->sanitize_priority( 'invalid' );
		$this->assertEquals( 'medium', $result );
	}

	/**
	 * Test sanitize_mood_rating with valid values.
	 *
	 * @dataProvider mood_rating_valid_provider
	 */
	public function test_sanitize_mood_rating_valid( $input, int $expected ) {
		$result = $this->meta_fields->sanitize_mood_rating( $input );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Data provider for valid mood rating values.
	 */
	public function mood_rating_valid_provider(): array {
		return [
			'one'         => [ 1, 1 ],
			'five'        => [ 5, 5 ],
			'ten'         => [ 10, 10 ],
			'string five' => [ '5', 5 ],
		];
	}

	/**
	 * Test sanitize_mood_rating clamps out-of-range values.
	 *
	 * @dataProvider mood_rating_clamped_provider
	 */
	public function test_sanitize_mood_rating_clamped( $input, int $expected ) {
		$result = $this->meta_fields->sanitize_mood_rating( $input );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Data provider for clamped mood rating values.
	 */
	public function mood_rating_clamped_provider(): array {
		return [
			'zero'     => [ 0, 1 ],
			'negative' => [ -5, 1 ],
			'over ten' => [ 15, 10 ],
		];
	}

	/**
	 * Test sanitize_float with various input types.
	 *
	 * @dataProvider float_provider
	 */
	public function test_sanitize_float( $input, float $expected ) {
		$result = $this->meta_fields->sanitize_float( $input );
		$this->assertEqualsWithDelta( $expected, $result, 0.01 );
	}

	/**
	 * Data provider for float sanitization.
	 */
	public function float_provider(): array {
		return [
			'integer'      => [ 5, 5.0 ],
			'float'        => [ 3.14, 3.14 ],
			'string float' => [ '2.5', 2.5 ],
			'negative'     => [ -1.5, -1.5 ],
			'invalid'      => [ 'abc', 0.0 ],
		];
	}

	/**
	 * Test XSS protection in sanitizers.
	 */
	public function test_xss_protection_in_status_sanitizers() {
		$malicious = '<script>alert("xss")</script>';

		$this->assertEquals( '', $this->meta_fields->sanitize_rsvp_status( $malicious ) );
		$this->assertEquals( 'to-watch', $this->meta_fields->sanitize_watch_status( $malicious ) );
		$this->assertEquals( 'to-read', $this->meta_fields->sanitize_read_status( $malicious ) );
		$this->assertEquals( 'playing', $this->meta_fields->sanitize_play_status( $malicious ) );
		$this->assertEquals( 'approximate', $this->meta_fields->sanitize_geo_privacy( $malicious ) );
		$this->assertEquals( 'medium', $this->meta_fields->sanitize_priority( $malicious ) );
	}

	/**
	 * Test SQL injection protection in sanitizers.
	 */
	public function test_sql_injection_protection() {
		$malicious = "'; DROP TABLE wp_posts; --";

		$this->assertEquals( '', $this->meta_fields->sanitize_rsvp_status( $malicious ) );
		$this->assertEquals( 'to-watch', $this->meta_fields->sanitize_watch_status( $malicious ) );
		$this->assertEquals( 'to-read', $this->meta_fields->sanitize_read_status( $malicious ) );
		$this->assertEquals( 'playing', $this->meta_fields->sanitize_play_status( $malicious ) );
	}

	/**
	 * Test that new drink meta fields exist.
	 */
	public function test_drink_meta_fields_exist() {
		$fields = Meta_Fields::get_fields();

		$drink_fields = [
			'drink_name',
			'drink_type',
			'drink_brewery',
			'drink_photo',
			'drink_rating',
			'drink_notes',
			'drink_venue_url',
			'drink_location_name',
		];

		foreach ( $drink_fields as $key ) {
			$this->assertArrayHasKey( $key, $fields, "Field '{$key}' should exist" );
		}
	}

	/**
	 * Test that new play meta fields exist.
	 */
	public function test_play_meta_fields_exist() {
		$fields = Meta_Fields::get_fields();

		$play_fields = [
			'play_title',
			'play_platform',
			'play_status',
			'play_hours',
			'play_cover',
			'play_rating',
			'play_review',
			'play_game_url',
			'play_bgg_id',
		];

		foreach ( $play_fields as $key ) {
			$this->assertArrayHasKey( $key, $fields, "Field '{$key}' should exist" );
		}
	}
}
