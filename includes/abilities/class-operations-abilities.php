<?php
/**
 * Operational abilities provider.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\Card_Meta_Sync;
use PKIW\Meta_Fields;
use PKIW\Operations;
use PKIW\Post_Surface;
use PKIW\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers maintenance operations and their read-only reports.
 */
final class Operations_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var Operations_Abilities|null
	 */
	private static ?Operations_Abilities $instance = null;

	/**
	 * Get the singleton instance.
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
	 * Reset the singleton for tests.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Register all operational abilities.
	 */
	public function register(): void {
		$this->register_backfill_surfaces();
		$this->register_backfill_card_meta();
		$this->register_backfill_listen_media_type();
		$this->register_backfill_featured_artwork();
		$this->register_get_checkin_stats();
		$this->register_list_venues();
		$this->register_get_surface();
		$this->register_get_public_location_fields();
	}

	/**
	 * Register the surface backfill.
	 */
	private function register_backfill_surfaces(): void {
		$this->register_write(
			'post-kinds/backfill-surfaces',
			__( 'Backfill Surfaces', 'post-kinds-for-indieweb-in-block-themes' ),
			__( 'Reports or recomputes the cached surface for every post.', 'post-kinds-for-indieweb-in-block-themes' ),
			$this->write_input_schema(),
			[
				'type'                 => 'object',
				'properties'           => [
					'dry_run'   => [ 'type' => 'boolean' ],
					'processed' => [ 'type' => 'integer' ],
					'changed'   => [ 'type' => 'integer' ],
				],
				'required'             => [ 'dry_run', 'processed', 'changed' ],
				'additionalProperties' => false,
			],
			[ $this, 'execute_backfill_surfaces' ],
			false
		);
	}

	/**
	 * Register the card-meta backfill.
	 */
	private function register_backfill_card_meta(): void {
		$input                        = $this->write_input_schema();
		$input['properties']['batch'] = [
			'type'        => 'integer',
			'minimum'     => 1,
			'default'     => Card_Meta_Sync::BACKFILL_BATCH,
			'description' => __( 'Posts to process in each batch.', 'post-kinds-for-indieweb-in-block-themes' ),
		];

		$this->register_write(
			'post-kinds/backfill-card-meta',
			__( 'Backfill Card Meta', 'post-kinds-for-indieweb-in-block-themes' ),
			__( 'Reports or re-syncs card block attributes into post meta.', 'post-kinds-for-indieweb-in-block-themes' ),
			$input,
			[
				'type'                 => 'object',
				'properties'           => [
					'dry_run'   => [ 'type' => 'boolean' ],
					'processed' => [ 'type' => 'integer' ],
					'batch'     => [ 'type' => 'integer' ],
				],
				'required'             => [ 'dry_run', 'processed', 'batch' ],
				'additionalProperties' => false,
			],
			[ $this, 'execute_backfill_card_meta' ],
			true
		);
	}

	/**
	 * Register the listen-media-type backfill.
	 */
	private function register_backfill_listen_media_type(): void {
		$input                      = $this->write_input_schema();
		$input['properties']['ids'] = [
			'type'        => 'array',
			'items'       => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'description' => __( 'Candidate post IDs to update on a write run.', 'post-kinds-for-indieweb-in-block-themes' ),
		];

		$this->register_write(
			'post-kinds/backfill-listen-media-type',
			__( 'Backfill Listen Media Type', 'post-kinds-for-indieweb-in-block-themes' ),
			__( 'Reports podcast candidates or marks an explicit candidate list.', 'post-kinds-for-indieweb-in-block-themes' ),
			$input,
			[
				'type'                 => 'object',
				'properties'           => [
					'dry_run'    => [ 'type' => 'boolean' ],
					'candidates' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'id'       => [ 'type' => 'integer' ],
								'status'   => [ 'type' => 'string' ],
								'password' => [ 'type' => 'boolean' ],
								'signals'  => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
							],
							'required'             => [ 'id', 'status', 'password', 'signals' ],
							'additionalProperties' => false,
						],
					],
					'written'    => [
						'type'  => 'array',
						'items' => [ 'type' => 'integer' ],
					],
					'skipped'    => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'id'     => [ 'type' => 'integer' ],
								'reason' => [ 'type' => 'string' ],
							],
							'required'             => [ 'id', 'reason' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'dry_run', 'candidates', 'written', 'skipped' ],
				'additionalProperties' => false,
			],
			[ $this, 'execute_backfill_listen_media_type' ],
			false
		);
	}

	/**
	 * Register the featured-artwork backfill.
	 */
	private function register_backfill_featured_artwork(): void {
		$this->register_write(
			'post-kinds/backfill-featured-artwork',
			__( 'Backfill Featured Artwork', 'post-kinds-for-indieweb-in-block-themes' ),
			__( 'Reports or sets featured images from kind artwork.', 'post-kinds-for-indieweb-in-block-themes' ),
			$this->write_input_schema(),
			[
				'type'                 => 'object',
				'properties'           => [
					'dry_run' => [ 'type' => 'boolean' ],
					'scanned' => [ 'type' => 'integer' ],
					'updated' => [ 'type' => 'integer' ],
					'skipped' => [ 'type' => 'integer' ],
					'failed'  => [ 'type' => 'integer' ],
				],
				'required'             => [ 'dry_run', 'scanned', 'updated', 'skipped', 'failed' ],
				'additionalProperties' => false,
			],
			[ $this, 'execute_backfill_featured_artwork' ],
			false
		);
	}

	/**
	 * Register the check-in statistics read.
	 */
	private function register_get_checkin_stats(): void {
		wp_register_ability(
			'post-kinds/get-checkin-stats',
			[
				'label'               => __( 'Get Check-in Statistics', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Counts published check-ins and venue terms.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => $this->empty_input_schema(),
				'output_schema'       => [
					'type'                 => 'object',
					'properties'           => [
						'checkin_posts' => [ 'type' => 'integer' ],
						'venue_terms'   => [ 'type' => 'integer' ],
					],
					'required'             => [ 'checkin_posts', 'venue_terms' ],
					'additionalProperties' => false,
				],
				'execute_callback'    => [ $this, 'execute_get_checkin_stats' ],
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'meta'                => $this->read_meta(),
			]
		);
	}

	/**
	 * Register the venue listing read.
	 */
	private function register_list_venues(): void {
		wp_register_ability(
			'post-kinds/list-venues',
			[
				'label'               => __( 'List Venues', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Lists venue terms and their stored coordinates.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => $this->empty_input_schema(),
				'output_schema'       => [
					'type'                 => 'object',
					'properties'           => [
						'venues' => [
							'type'  => 'array',
							'items' => [
								'type'                 => 'object',
								'properties'           => [
									'id'        => [ 'type' => 'integer' ],
									'name'      => [ 'type' => 'string' ],
									'slug'      => [ 'type' => 'string' ],
									'city'      => [ 'type' => 'string' ],
									'country'   => [ 'type' => 'string' ],
									'latitude'  => [ 'type' => [ 'number', 'null' ] ],
									'longitude' => [ 'type' => [ 'number', 'null' ] ],
									'count'     => [ 'type' => 'integer' ],
								],
								'required'             => [ 'id', 'name', 'slug', 'city', 'country', 'latitude', 'longitude', 'count' ],
								'additionalProperties' => false,
							],
						],
						'total'  => [ 'type' => 'integer' ],
					],
					'required'             => [ 'venues', 'total' ],
					'additionalProperties' => false,
				],
				'execute_callback'    => [ $this, 'execute_list_venues' ],
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'meta'                => $this->read_meta(),
			]
		);
	}

	/**
	 * Register the surface read.
	 */
	private function register_get_surface(): void {
		wp_register_ability(
			'post-kinds/get-surface',
			[
				'label'               => __( 'Get Surface', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Gets a post surface and the inputs used to classify it.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => $this->post_input_schema(),
				'output_schema'       => [
					'type'                 => 'object',
					'properties'           => [
						'post_id'      => [ 'type' => 'integer' ],
						'surface'      => [
							'type' => 'string',
							'enum' => [ Post_Surface::STREAM, Post_Surface::MAIN ],
						],
						'cached'       => [ 'type' => 'string' ],
						'promoted'     => [ 'type' => 'boolean' ],
						'kinds'        => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'stream_kinds' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
					'required'             => [ 'post_id', 'surface', 'cached', 'promoted', 'kinds', 'stream_kinds' ],
					'additionalProperties' => false,
				],
				'execute_callback'    => [ $this, 'execute_get_surface' ],
				'permission_callback' => static function ( $input ): bool {
					return current_user_can( 'read_post', (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'                => $this->read_meta(),
			]
		);
	}

	/**
	 * Register the visitor-safe location read.
	 */
	private function register_get_public_location_fields(): void {
		wp_register_ability(
			'post-kinds/get-public-location-fields',
			[
				'label'               => __( 'Get Public Location Fields', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Gets the location fields a logged-out visitor may see.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => $this->post_input_schema(),
				'output_schema'       => [
					'type'                 => 'object',
					'properties'           => [
						'post_id'  => [ 'type' => 'integer' ],
						'visible'  => $this->visible_schema(),
						'location' => [
							'type'                 => 'object',
							'additionalProperties' => [ 'type' => 'string' ],
						],
					],
					'required'             => [ 'post_id', 'visible', 'location' ],
					'additionalProperties' => false,
				],
				'execute_callback'    => [ $this, 'execute_get_public_location_fields' ],
				'permission_callback' => static function ( $input ): bool {
					return current_user_can( 'read_post', (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'                => $this->read_meta(),
			]
		);
	}

	/**
	 * Register one administrator-only write ability.
	 *
	 * @param string   $name          Ability name.
	 * @param string   $label         Ability label.
	 * @param string   $description   Ability description.
	 * @param array    $input_schema  Input schema.
	 * @param array    $output_schema Output schema.
	 * @param callable $callback      Execute callback.
	 * @param bool     $destructive   Whether the operation can remove stored data.
	 */
	private function register_write( string $name, string $label, string $description, array $input_schema, array $output_schema, callable $callback, bool $destructive ): void {
		wp_register_ability(
			$name,
			[
				'label'               => $label,
				'description'         => $description,
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'execute_callback'    => $callback,
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'meta'                => [
					'mcp'         => [ 'public' => false ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => $destructive,
						'idempotent'  => true,
					],
				],
			]
		);
	}

	/**
	 * Input schema shared by write abilities.
	 *
	 * @return array<string,mixed>
	 */
	private function write_input_schema(): array {
		return [
			'type'                 => 'object',
			'default'              => [ 'dry_run' => true ],
			'properties'           => [
				'dry_run' => [
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Report what would change without writing.', 'post-kinds-for-indieweb-in-block-themes' ),
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * Input schema for reads with no arguments.
	 *
	 * @return array<string,mixed>
	 */
	private function empty_input_schema(): array {
		return [
			'type'       => 'object',
			'default'    => [],
			'properties' => new \stdClass(),
		];
	}

	/**
	 * Input schema for reads of one post.
	 *
	 * @return array<string,mixed>
	 */
	private function post_input_schema(): array {
		return [
			'type'                 => 'object',
			'default'              => [],
			'properties'           => [
				'post_id' => [ 'type' => 'integer' ],
			],
			'required'             => [ 'post_id' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Metadata shared by read abilities.
	 *
	 * @return array<string,mixed>
	 */
	private function read_meta(): array {
		return [
			'show_in_rest' => true,
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
		];
	}

	/**
	 * Output schema for the eleven location visibility fields.
	 *
	 * @return array<string,mixed>
	 */
	private function visible_schema(): array {
		$keys       = [ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'coordinates', 'map', 'url', 'osm_id', 'venue_id' ];
		$properties = [];
		foreach ( $keys as $key ) {
			$properties[ $key ] = [ 'type' => 'boolean' ];
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $keys,
			'additionalProperties' => false,
		];
	}

	/**
	 * Treat every value except explicit false representations as a dry run.
	 *
	 * @param array $args Input arguments.
	 */
	private function is_dry_run( array $args ): bool {
		return ! array_key_exists( 'dry_run', $args )
			|| ! in_array( $args['dry_run'], [ false, 0, '0', 'false' ], true );
	}

	/**
	 * Execute the surface backfill.
	 *
	 * @param array $args Input arguments.
	 * @return array Operation result.
	 */
	public function execute_backfill_surfaces( array $args ): array {
		return Operations::backfill_surfaces( $this->is_dry_run( $args ) );
	}

	/**
	 * Execute the card-meta backfill.
	 *
	 * @param array $args Input arguments.
	 * @return array Operation result.
	 */
	public function execute_backfill_card_meta( array $args ): array {
		$batch = (int) ( $args['batch'] ?? Card_Meta_Sync::BACKFILL_BATCH );
		return Operations::backfill_card_meta( $this->is_dry_run( $args ), $batch );
	}

	/**
	 * Execute the listen-media-type backfill.
	 *
	 * @param array $args Input arguments.
	 * @return array|\WP_Error Operation result.
	 */
	public function execute_backfill_listen_media_type( array $args ): array|\WP_Error {
		$ids = array_key_exists( 'ids', $args ) ? (array) $args['ids'] : null;
		return Operations::backfill_listen_media_type( $this->is_dry_run( $args ), $ids );
	}

	/**
	 * Execute the featured-artwork backfill.
	 *
	 * @param array $args Input arguments.
	 * @return array Operation result.
	 */
	public function execute_backfill_featured_artwork( array $args ): array {
		return Operations::backfill_featured_artwork( $this->is_dry_run( $args ) );
	}

	/**
	 * Execute the check-in statistics read.
	 *
	 * @param array $args Input arguments.
	 * @return array Operation result.
	 */
	public function execute_get_checkin_stats( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Operations::checkin_stats();
	}

	/**
	 * Execute the venue listing read.
	 *
	 * @param array $args Input arguments.
	 * @return array|\WP_Error Operation result.
	 */
	public function execute_list_venues( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$venues = Operations::venues();
		if ( is_wp_error( $venues ) ) {
			return $venues;
		}

		foreach ( $venues as &$venue ) {
			$venue['latitude']  = '' === $venue['latitude'] ? null : (float) $venue['latitude'];
			$venue['longitude'] = '' === $venue['longitude'] ? null : (float) $venue['longitude'];
		}
		unset( $venue );

		return [
			'venues' => $venues,
			'total'  => count( $venues ),
		];
	}

	/**
	 * Execute the surface read.
	 *
	 * @param array $args Input arguments.
	 * @return array|\WP_Error Operation result.
	 */
	public function execute_get_surface( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found.', 'post-kinds-for-indieweb-in-block-themes' ),
				[ 'status' => 404 ]
			);
		}

		if ( Meta_Fields::hides_kind_meta( $post ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to read this post kind data.', 'post-kinds-for-indieweb-in-block-themes' ),
				[ 'status' => 403 ]
			);
		}

		$kinds = wp_get_object_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $kinds ) ) {
			$kinds = [];
		}
		$stream_kinds = (array) apply_filters( 'pkiw_stream_kinds', [] );

		return [
			'post_id'      => $post_id,
			'surface'      => Post_Surface::get( $post ),
			'cached'       => (string) get_post_meta( $post_id, '_pkiw_surface', true ),
			'promoted'     => (bool) get_post_meta( $post_id, 'pkiw_promote', true ),
			'kinds'        => array_values( array_map( 'strval', $kinds ) ),
			'stream_kinds' => array_values( array_map( 'strval', $stream_kinds ) ),
		];
	}

	/**
	 * Execute the visitor-safe location read.
	 *
	 * @param array $args Input arguments.
	 * @return array|\WP_Error Operation result.
	 */
	public function execute_get_public_location_fields( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found.', 'post-kinds-for-indieweb-in-block-themes' ),
				[ 'status' => 404 ]
			);
		}

		$view = Meta_Fields::get_public_location_view( $post_id );

		return [
			'post_id'  => $post_id,
			'visible'  => $view['visible'],
			'location' => $view['meta'],
		];
	}
}
