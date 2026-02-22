<?php
/**
 * Lookup Abilities Provider for Post Kinds for IndieWeb
 *
 * Registers 6 lookup abilities that proxy to existing REST API endpoints.
 *
 * @package PostKindsForIndieWeb
 * @since   1.1.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Abilities;

use PostKindsForIndieWeb\Abilities_Manager;
use PostKindsForIndieWeb\REST_API;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lookup Abilities provider.
 *
 * Provides abilities for looking up music, video, book, podcast,
 * venue, and game data via the existing REST API endpoints.
 *
 * @since 1.1.0
 */
final class Lookup_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var Lookup_Abilities|null
	 */
	private static ?Lookup_Abilities $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.1.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset singleton for testing.
	 *
	 * @internal Only for use in tests.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone(): void {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception If unserialization is attempted.
	 */
	public function __wakeup(): void {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Register all 6 lookup abilities.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		foreach ( $this->get_lookup_definitions() as $slug => $definition ) {
			wp_register_ability(
				'post_kinds/lookup_' . $slug,
				[
					'label'               => $definition['label'],
					'description'         => $definition['description'],
					'category'            => Abilities_Manager::CATEGORY_SLUG,
					'input_schema'        => $definition['input_schema'],
					'output_schema'       => [
						'type'       => 'object',
						'properties' => [
							'results' => [ 'type' => 'array' ],
						],
					],
					'execute_callback'    => function ( array $args ) use ( $definition ) {
						return $this->execute_lookup( $definition['rest_route'], $args );
					},
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
					'meta'                => [ 'show_in_rest' => true ],
				]
			);
		}
	}

	/**
	 * Get lookup definitions for all 6 lookup types.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array> Lookup definitions keyed by slug.
	 */
	public function get_lookup_definitions(): array {
		return [
			'music'   => [
				'label'        => __( 'Lookup Music', 'post-kinds-for-indieweb' ),
				'description'  => __( 'Search for music by artist, track, or album name.', 'post-kinds-for-indieweb' ),
				'rest_route'   => '/' . REST_API::NAMESPACE . '/lookup/music',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'query' => [
							'type'        => 'string',
							'description' => __( 'Search query (artist, track, or album).', 'post-kinds-for-indieweb' ),
						],
						'type'  => [
							'type'        => 'string',
							'enum'        => [ 'recording', 'release', 'artist' ],
							'default'     => 'recording',
							'description' => __( 'Type of music entity to search for.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'query' ],
				],
			],
			'video'   => [
				'label'        => __( 'Lookup Video', 'post-kinds-for-indieweb' ),
				'description'  => __( 'Search for movies or TV shows by title.', 'post-kinds-for-indieweb' ),
				'rest_route'   => '/' . REST_API::NAMESPACE . '/lookup/video',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'query' => [
							'type'        => 'string',
							'description' => __( 'Movie or TV show title.', 'post-kinds-for-indieweb' ),
						],
						'type'  => [
							'type'        => 'string',
							'enum'        => [ 'movie', 'tv' ],
							'default'     => 'movie',
							'description' => __( 'Type of video content to search for.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'query' ],
				],
			],
			'book'    => [
				'label'        => __( 'Lookup Book', 'post-kinds-for-indieweb' ),
				'description'  => __( 'Search for books by title, author, or ISBN.', 'post-kinds-for-indieweb' ),
				'rest_route'   => '/' . REST_API::NAMESPACE . '/lookup/book',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'query' => [
							'type'        => 'string',
							'description' => __( 'Book title, author, or ISBN.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'query' ],
				],
			],
			'podcast' => [
				'label'        => __( 'Lookup Podcast', 'post-kinds-for-indieweb' ),
				'description'  => __( 'Search for podcasts by name.', 'post-kinds-for-indieweb' ),
				'rest_route'   => '/' . REST_API::NAMESPACE . '/lookup/podcast',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'query' => [
							'type'        => 'string',
							'description' => __( 'Podcast name.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'query' ],
				],
			],
			'venue'   => [
				'label'        => __( 'Lookup Venue', 'post-kinds-for-indieweb' ),
				'description'  => __( 'Search for venues and places by name or location.', 'post-kinds-for-indieweb' ),
				'rest_route'   => '/' . REST_API::NAMESPACE . '/lookup/venue',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'query' => [
							'type'        => 'string',
							'description' => __( 'Venue or place name.', 'post-kinds-for-indieweb' ),
						],
						'll'    => [
							'type'        => 'string',
							'description' => __( 'Latitude,longitude for nearby search.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'query' ],
				],
			],
			'game'    => [
				'label'        => __( 'Lookup Game', 'post-kinds-for-indieweb' ),
				'description'  => __( 'Search for video games or board games by title.', 'post-kinds-for-indieweb' ),
				'rest_route'   => '/' . REST_API::NAMESPACE . '/lookup/game',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'query' => [
							'type'        => 'string',
							'description' => __( 'Game title.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'query' ],
				],
			],
		];
	}

	/**
	 * Execute a lookup by proxying to the REST API route.
	 *
	 * @since 1.1.0
	 *
	 * @param string $route REST API route.
	 * @param array  $args  Input arguments to pass as query params.
	 * @return array|\WP_Error Results array or error.
	 */
	private function execute_lookup( string $route, array $args ): array|\WP_Error {
		$request = new \WP_REST_Request( 'GET', $route );

		// Translate ability params to REST API params.
		foreach ( $args as $key => $value ) {
			if ( 'query' === $key ) {
				$request->set_param( 'q', $value );
			} elseif ( 'll' === $key ) {
				// Split lat,lng into separate params.
				$parts = explode( ',', $value );
				if ( 2 === count( $parts ) ) {
					$request->set_param( 'lat', trim( $parts[0] ) );
					$request->set_param( 'lng', trim( $parts[1] ) );
				}
			} else {
				$request->set_param( $key, $value );
			}
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return new \WP_Error(
				'lookup_failed',
				$response->as_error()->get_error_message()
			);
		}

		return [ 'results' => $response->get_data() ];
	}
}
