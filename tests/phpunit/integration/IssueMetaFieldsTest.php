<?php
/**
 * Issue meta registration, sanitization and editor panel data.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Issue_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class IssueMetaFieldsTest extends WP_UnitTestCase {

	private Meta_Fields $fields;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->fields = new Meta_Fields();
		$this->fields->register_meta_fields();
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		wp_dequeue_script( Issue_Archive::SCRIPT_HANDLE );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public function issue_numbers(): array {
		return [
			'prefixed'       => [ '#42', '42' ],
			'spaced'         => [ ' 7 ', '7' ],
			'leading zeroes' => [ '0042', '0042' ],
			'letters'        => [ 'abc', '' ],
			'mixed'          => [ '12a', '' ],
			'too long'       => [ '1234567890', '' ],
			'array'          => [ [], '' ],
			'empty'          => [ '', '' ],
			'boolean'        => [ false, '' ],
		];
	}

	/**
	 * @dataProvider issue_numbers
	 */
	public function test_issue_number_sanitizer_accepts_only_one_to_nine_ascii_digits( mixed $value, string $expected ): void {
		$this->assertSame( $expected, $this->fields->sanitize_issue_number( $value ) );
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public function issue_statuses(): array {
		return [
			'open case'    => [ 'Open', 'open' ],
			'closed space' => [ ' closed ', 'closed' ],
			'unknown'      => [ 'wontfix', '' ],
			'empty'        => [ '', '' ],
			'integer'      => [ 1, '' ],
			'array'        => [ [], '' ],
		];
	}

	/**
	 * @dataProvider issue_statuses
	 */
	public function test_issue_status_sanitizer_normalizes_only_open_and_closed( mixed $value, string $expected ): void {
		$this->assertSame( $expected, $this->fields->sanitize_issue_status( $value ) );
	}

	public function test_issue_meta_defaults_and_rest_schema_are_registered(): void {
		$post_id = self::factory()->post->create();
		// No row is stored; the registered default reads '' through the normal API.
		$this->assertNull( get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . 'issue_number', true ) );
		$this->assertSame( '', get_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_number', true ) );
		$this->assertSame( '', get_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_status', true ) );

		$registered = get_registered_meta_keys( 'post', 'post' );
		$number     = $registered[ Meta_Fields::PREFIX . 'issue_number' ];
		$status     = $registered[ Meta_Fields::PREFIX . 'issue_status' ];
		$this->assertNotEmpty( $number['show_in_rest'] );
		$this->assertNotEmpty( $status['show_in_rest'] );
		$this->assertSame( [ '', 'open', 'closed' ], $status['show_in_rest']['schema']['enum'] );
	}

	public function test_editor_rest_response_contains_both_registered_issue_fields(): void {
		$post_id = self::factory()->post->create();
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_number', '#42' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_status', 'Open' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$this->assertSame( 200, $response->get_status() );
		$meta = $response->get_data()['meta'];
		$this->assertSame( '42', $meta[ Meta_Fields::PREFIX . 'issue_number' ] );
		$this->assertSame( 'open', $meta[ Meta_Fields::PREFIX . 'issue_status' ] );
	}

	public function test_post_screen_enqueues_panel_with_the_issue_term_id(): void {
		set_current_screen( 'post' );
		Issue_Archive::enqueue_editor_panel();
		$term = get_term_by( 'slug', 'issue', Taxonomy::TAXONOMY );

		$this->assertTrue( wp_script_is( Issue_Archive::SCRIPT_HANDLE, 'enqueued' ) );
		$before = wp_scripts()->get_data( Issue_Archive::SCRIPT_HANDLE, 'before' );
		$this->assertStringContainsString( 'window.pkiwIssueFields = {"termId":' . $term->term_id . '};', implode( "\n", (array) $before ) );
	}

	public function test_dashboard_screen_does_not_enqueue_panel(): void {
		set_current_screen( 'dashboard' );
		Issue_Archive::enqueue_editor_panel();

		$this->assertFalse( wp_script_is( Issue_Archive::SCRIPT_HANDLE, 'enqueued' ) );
	}
}
