<?php
/**
 * Wires Book_Completion into the plugin.
 *
 * @package PostKindsForIndieWeb
 * @since   1.2.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

use PostKindsForIndieWeb\APIs\GoogleBooks;
use PostKindsForIndieWeb\APIs\Hardcover;
use PostKindsForIndieWeb\APIs\OpenLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires Book_Completion into the plugin: REST endpoint for the editor
 * button, save_post fill of blank read meta, and the Micropub read path.
 *
 * @since 1.2.0
 */
class Book_Completion_Controller {

	/**
	 * Canonical completion key => Meta_Fields suffix (appended to Meta_Fields::PREFIX).
	 *
	 * @var array<string, string>
	 */
	private const META_BY_KEY = [
		'title'        => 'read_title',
		'author'       => 'read_author',
		'isbn'         => 'read_isbn',
		'publisher'    => 'read_publisher',
		'publish_date' => 'read_publish_date',
		'pages'        => 'read_pages',
		'cover'        => 'read_cover',
		'url'          => 'read_url',
		'asin'         => 'read_asin',
	];

	/**
	 * Register the REST route and save-time completion hook.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'save_post', [ $this, 'complete_on_save' ], 30, 2 ); // After Card_Meta_Sync@25.
	}

	/**
	 * Build (or fetch via the filter seam) the completion service.
	 *
	 * Lazily constructed here — never built at class-load time — so unit
	 * tests that swap in a stub via the filter never touch the real API
	 * clients (and never make a live HTTP request).
	 *
	 * @return object Object with complete( array ): array.
	 */
	private function service(): object {
		/**
		 * Filters the completion service (test seam / replacement point).
		 *
		 * @since 1.2.0
		 *
		 * @param object|null $service Object with complete( array ): array, or
		 *                             null to use the default Book_Completion.
		 */
		$service = apply_filters( 'pkiw_book_completion_service', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( is_object( $service ) && method_exists( $service, 'complete' ) ) {
			return $service;
		}

		// GoogleBooks doesn't match the plugin autoloader's naming scheme
		// (class-google-books.php vs the derived class-googlebooks.php), so
		// load it explicitly — same as tests/phpunit/unit/GoogleBooksApiTest.php.
		if ( ! class_exists( GoogleBooks::class ) ) {
			require_once __DIR__ . '/apis/class-google-books.php';
		}

		return new Book_Completion( new OpenLibrary(), new GoogleBooks(), new Hardcover() );
	}

	/**
	 * Register the /pkiw/v1/book-complete REST route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'pkiw/v1',
			'/book-complete',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_complete' ],
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => array_fill_keys(
					array_keys( self::META_BY_KEY ),
					[
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					]
				),
			]
		);
	}

	/**
	 * REST callback: complete a partial book payload.
	 *
	 * Input is restricted to the canonical keys — get_params() also returns
	 * arbitrary caller-supplied params, which bypass the registered args'
	 * sanitization and would otherwise be reflected verbatim in the response.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response Completed book data (canonical keys).
	 */
	public function rest_complete( \WP_REST_Request $request ): \WP_REST_Response {
		$book = array_filter(
			array_intersect_key( $request->get_params(), self::META_BY_KEY ),
			'is_string'
		);
		return rest_ensure_response( $this->service()->complete( $book ) );
	}

	/**
	 * Fill blank read-card meta from the completion service on save.
	 *
	 * Runs at save_post:30, after Card_Meta_Sync@25 has mirrored the card's
	 * attrs into meta, so this reads the freshly-synced values. Only ever
	 * fills meta that is currently blank — never overwrites — and only
	 * calls update_post_meta (no wp_update_post), so there is no recursion
	 * risk with save_post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function complete_on_save( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! has_block( 'post-kinds-indieweb/read-card', $post ) ) {
			return;
		}

		if ( '' !== (string) get_post_meta( $post_id, Micropub_Content_Builder::GENERATED_META_KEY, true ) ) {
			/**
			 * Filters whether Micropub-originated read-of posts run book
			 * completion, independent of the general save-time completion
			 * that applies to editor saves. Lets a site opt out of a slow
			 * outbound API call specifically on the Micropub ingestion path.
			 *
			 * @since 1.2.0
			 *
			 * @param bool $enabled Whether to run completion. Default true.
			 */
			if ( ! apply_filters( 'pkiw_micropub_book_completion', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				return;
			}
		}

		$book = [];
		foreach ( self::META_BY_KEY as $key => $suffix ) {
			$value = get_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, true );
			if ( is_string( $value ) && '' !== $value ) {
				$book[ $key ] = $value;
			}
		}
		if ( empty( $book['isbn'] ) && empty( $book['title'] ) && empty( $book['url'] ) ) {
			return; // Nothing to complete from.
		}

		// Every completable field is already filled — calling out to the
		// completion service (a live HTTP request in production) would
		// only re-fetch data this post already has. Skips the request on
		// every ordinary resave of an already-complete read-card; only
		// posts with at least one blank completable field (including a
		// freshly ISBN-changed post, whose read_asin Card_Meta_Sync@25
		// just cleared) reach the service call below.
		if ( ! array_diff_key( self::META_BY_KEY, $book ) ) {
			return;
		}

		$completed = $this->service()->complete( $book );

		foreach ( self::META_BY_KEY as $key => $suffix ) {
			if ( empty( $book[ $key ] ) && ! empty( $completed[ $key ] ) ) {
				update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, sanitize_text_field( (string) $completed[ $key ] ) );
			}
		}
	}
}
