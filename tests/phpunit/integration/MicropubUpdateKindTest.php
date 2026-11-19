<?php
/**
 * Kind changes on a Micropub update, through the Micropub plugin's endpoint.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Sends create and update requests to the real Micropub plugin and reads the
 * kind the post ends with. Skips unless PKIW_TESTS_MICROPUB_FILE points at
 * the plugin's micropub.php (tests/phpunit/bootstrap.php).
 *
 * @group integration
 */
final class MicropubUpdateKindTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\Micropub\Rest\Endpoint_Controller' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_MICROPUB_FILE to run requests through the Micropub plugin.' );
		}
		foreach ( array( 'note', 'article', 'reply', 'bookmark', 'checkin', 'like', 'quote' ) as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', array( 'slug' => $slug ) );
			}
		}
		$note = get_term_by( 'slug', 'note', 'kind' );
		if ( $note instanceof WP_Term ) {
			update_option( 'default_term_kind', $note->term_id );
		}

		add_filter(
			'indieauth_response',
			static fn() => array(
				'client_id'   => 'https://client.example/',
				'client_name' => 'Test client',
			)
		);
		add_filter( 'indieauth_scopes', static fn() => array( 'create', 'update', 'delete', 'undelete', 'media' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		global $wp_rest_server;
		$wp_rest_server = null;
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Send a JSON Micropub request.
	 *
	 * @param array<string, mixed> $body Micropub request body.
	 * @return WP_REST_Response
	 */
	private function send( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/micropub/1.0/endpoint' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	/**
	 * Create an entry and return its URL.
	 *
	 * @param array<string, mixed> $properties Micropub properties.
	 * @return string Post URL.
	 */
	private function create( array $properties ): string {
		$response = $this->send(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => $properties,
			)
		);
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$headers = $response->get_headers();
		return (string) $headers['Location'];
	}

	private function kind_at( string $url ): string {
		$post_id = url_to_postid( $url );
		$this->assertGreaterThan( 0, $post_id, $url );
		return implode( ',', wp_get_post_terms( $post_id, 'kind', array( 'fields' => 'slugs' ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
	 */
	public static function update_provider(): array {
		return array(
			'like-of replaced on a note'              => array(
				array( 'content' => array( 'Plain words.' ) ),
				array( 'replace' => array( 'like-of' => array( 'https://example.test/liked' ) ) ),
				'like',
			),
			'in-reply-to replaced on an article'      => array(
				array(
					'name'    => array( 'A titled post' ),
					'content' => array( 'The body says something else.' ),
				),
				array( 'replace' => array( 'in-reply-to' => array( 'https://example.test/parent' ) ) ),
				'reply',
			),
			'pkiw-kind replaced'                      => array(
				array( 'content' => array( 'Words by someone else.' ) ),
				array( 'replace' => array( 'pkiw-kind' => array( 'quote' ) ) ),
				'quote',
			),
			'bookmark-of deleted, a note'             => array(
				array(
					'bookmark-of' => array( 'https://example.test/saved' ),
					'content'     => array( 'Why I saved it.' ),
				),
				array( 'delete' => array( 'bookmark-of' ) ),
				'note',
			),
			'bookmark-of deleted, an article'         => array(
				array(
					'name'        => array( 'A titled post' ),
					'bookmark-of' => array( 'https://example.test/saved' ),
					'content'     => array( 'Why I saved it.' ),
				),
				array( 'delete' => array( 'bookmark-of' ) ),
				'article',
			),
			'like-of replaced on a note hinted as a note' => array(
				array(
					'content'   => array( 'Plain words.' ),
					'pkiw-kind' => array( 'note' ),
				),
				array( 'replace' => array( 'like-of' => array( 'https://example.test/liked' ) ) ),
				'like',
			),
			'bookmark-of deleted from a hinted bookmark' => array(
				array(
					'bookmark-of' => array( 'https://example.test/saved' ),
					'content'     => array( 'Why I saved it.' ),
					'pkiw-kind'   => array( 'bookmark' ),
				),
				array( 'delete' => array( 'bookmark-of' ) ),
				'note',
			),
			'a photo added to a venue check-in'       => array(
				array(
					'name'          => array( 'Cafe Test' ),
					'location'      => array( 'geo:40.78963,-77.85019' ),
					'mp-place-name' => array( 'Cafe Test' ),
				),
				array( 'add' => array( 'photo' => array( 'https://example.test/uploads/pkiw-test-photo.jpg' ) ) ),
				'checkin',
			),
			'a venue and a location replaced'         => array(
				array( 'content' => array( 'Lunch.' ) ),
				array(
					'replace' => array(
						'location'      => array( 'geo:40.78963,-77.85019' ),
						'mp-place-name' => array( 'Cafe Test' ),
					),
				),
				'checkin',
			),
			'a venue replaced on a note with a location' => array(
				array(
					'content'  => array( 'Lunch.' ),
					'location' => array( 'geo:40.78963,-77.85019' ),
				),
				array( 'replace' => array( 'mp-place-name' => array( 'Cafe Test' ) ) ),
				'checkin',
			),
			'a location replaced on a check-in'       => array(
				array(
					'name'          => array( 'Cafe Test' ),
					'location'      => array( 'geo:40.78963,-77.85019' ),
					'mp-place-name' => array( 'Cafe Test' ),
				),
				array( 'replace' => array( 'location' => array( 'geo:40.79,-77.86' ) ) ),
				'checkin',
			),
		);
	}

	/**
	 * @dataProvider update_provider
	 *
	 * @param array<string, mixed> $created  Properties the entry is created with.
	 * @param array<string, mixed> $body     Micropub update operations.
	 * @param string               $expected Kind after the update.
	 */
	public function test_update_through_the_endpoint( array $created, array $body, string $expected ): void {
		$url = $this->create( $created );

		$response = $this->send( array( 'action' => 'update', 'url' => $url ) + $body );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $expected, $this->kind_at( $url ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function update_without_a_kind_provider(): array {
		return array(
			'content replaced'    => array( array( 'replace' => array( 'content' => array( 'Edited body.' ) ) ) ),
			'category added'      => array( array( 'add' => array( 'category' => array( 'travel' ) ) ) ),
			'syndication added'   => array( array( 'add' => array( 'syndication' => array( 'https://example.test/1' ) ) ) ),
			'a bare location'     => array( array( 'replace' => array( 'location' => array( 'geo:40.78963,-77.85019' ) ) ) ),
		);
	}

	/**
	 * A kind set in the editor stands against stored properties that would
	 * infer another one: only an update that names a kind property reads them.
	 *
	 * @dataProvider update_without_a_kind_provider
	 *
	 * @param array<string, mixed> $body Micropub update operations.
	 */
	public function test_update_that_names_no_kind_keeps_the_kind( array $body ): void {
		$url     = $this->create( array( 'like-of' => array( 'https://example.test/liked' ) ) );
		$post_id = url_to_postid( $url );
		wp_set_post_terms( $post_id, array( 'article' ), 'kind' );

		$response = $this->send( array( 'action' => 'update', 'url' => $url ) + $body );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'article', $this->kind_at( $url ) );
	}

	public function test_the_plugin_rejects_adding_a_kind_property(): void {
		$url = $this->create(
			array(
				'name'    => array( 'A titled post' ),
				'content' => array( 'The body says something else.' ),
			)
		);

		$response = $this->send(
			array(
				'action' => 'update',
				'url'    => $url,
				'add'    => array( 'in-reply-to' => array( 'https://example.test/parent' ) ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'article', $this->kind_at( $url ) );
	}

	public function test_delete_and_undelete_through_the_endpoint_keep_the_kind(): void {
		$url = $this->create(
			array(
				'name'        => array( 'A titled post' ),
				'bookmark-of' => array( 'https://example.test/saved' ),
				'content'     => array( 'Why I saved it.' ),
			)
		);
		$this->assertSame( 'bookmark', $this->kind_at( $url ) );
		$post_id = url_to_postid( $url );
		wp_set_post_terms( $post_id, array( 'article' ), 'kind' );

		$this->assertSame( 200, $this->send( array( 'action' => 'delete', 'url' => $url, 'replace' => array( 'like-of' => array( 'https://example.test/liked' ) ) ) )->get_status() );
		$this->assertSame( 'article', implode( ',', wp_get_post_terms( $post_id, 'kind', array( 'fields' => 'slugs' ) ) ) );

		$guid     = get_the_guid( $post_id );
		$response = $this->send( array( 'action' => 'undelete', 'url' => $guid, 'replace' => array( 'like-of' => array( 'https://example.test/liked' ) ) ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'article', implode( ',', wp_get_post_terms( $post_id, 'kind', array( 'fields' => 'slugs' ) ) ) );
	}
}
