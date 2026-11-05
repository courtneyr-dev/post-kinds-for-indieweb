<?php
/**
 * Public visitor-safe travel fact abilities.
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\Kind_Facts;
use PKIW\Travel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers trip and itinerary reads. */
final class Travel_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var Travel_Abilities|null
	 */
	private static ?Travel_Abilities $instance = null;

	/** Return the singleton instance. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Reset the singleton for tests. */
	public static function reset(): void {
		self::$instance = null;
	}

	/** Prevent direct construction. */
	private function __construct() {}

	/** Register both MCP-public read abilities. */
	public function register(): void {
		$this->register_one( 'post-kinds/get-trip-facts', __( 'Get Trip Facts', 'post-kinds-for-indieweb-in-block-themes' ), [ $this, 'execute_get_trip_facts' ], self::trip_schema() );
		$this->register_one( 'post-kinds/get-itinerary-facts', __( 'Get Itinerary Facts', 'post-kinds-for-indieweb-in-block-themes' ), [ $this, 'execute_get_itinerary_facts' ], self::itinerary_schema() );
	}

	/**
	 * Return public trip facts or a uniform non-public error.
	 *
	 * @param array<string, mixed> $input Ability input.
	 */
	public function execute_get_trip_facts( array $input ): array|\WP_Error {
		return $this->execute( $input, Travel::TRIP );
	}

	/**
	 * Return public itinerary facts or a uniform non-public error.
	 *
	 * @param array<string, mixed> $input Ability input.
	 */
	public function execute_get_itinerary_facts( array $input ): array|\WP_Error {
		return $this->execute( $input, Travel::ITINERARY );
	}

	/**
	 * Register one travel read.
	 *
	 * @param string               $name          Ability name.
	 * @param string               $label         Ability label.
	 * @param callable             $callback      Ability callback.
	 * @param array<string, mixed> $output_schema Output schema.
	 */
	private function register_one( string $name, string $label, callable $callback, array $output_schema ): void {
		wp_register_ability(
			$name,
			[
				'label'               => $label,
				'description'         => __( 'Gets visitor-visible travel facts for a published post.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'post_id' => [
							'type'    => 'integer',
							'minimum' => 1,
						],
					],
					'required'             => [ 'post_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => $output_schema,
				'execute_callback'    => $callback,
				'permission_callback' => static fn(): bool => current_user_can( 'read' ),
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);
	}

	/**
	 * Execute one public travel read.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $kind  Required kind.
	 */
	private function execute( array $input, string $kind ): array|\WP_Error {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password || Kind_Facts::kind_of( $post ) !== $kind ) {
			return new \WP_Error( 'pkiw_not_public', __( 'Travel facts are available only for public, unprotected posts of the requested kind.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 404 ] );
		}

		$facts  = Travel::TRIP === $kind ? Travel::trip_facts( $post_id ) : Travel::itinerary_facts( $post_id );
		$result = [
			'post_id'     => $post_id,
			'count'       => $facts['count'],
			'mapped'      => $facts['mapped'],
			'range'       => $facts['range'],
			'origin'      => $facts['origin'],
			'destination' => $facts['destination'],
		];
		$result[ Travel::TRIP === $kind ? 'stops' : 'legs' ] = $facts[ Travel::TRIP === $kind ? 'stops' : 'legs' ];
		return $result;
	}

	/** JSON schema for a visible place dictionary. */
	private static function place_properties(): array {
		$properties = [];
		foreach ( Travel::PLACE_FIELDS as $key ) {
			$properties[ $key ] = [ 'type' => 'string' ];
		}
		return $properties;
	}

	/** JSON schema for a visible endpoint. */
	private static function endpoint_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'place' => [
					'type'                 => 'object',
					'properties'           => self::place_properties(),
					'additionalProperties' => false,
				],
				'label' => [ 'type' => 'string' ],
				'lat'   => [ 'type' => [ 'number', 'null' ] ],
				'lng'   => [ 'type' => [ 'number', 'null' ] ],
			],
			'required'             => [ 'place', 'label', 'lat', 'lng' ],
			'additionalProperties' => false,
		];
	}

	/** Return the common date-range schema. */
	private static function range_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'start'   => [ 'type' => 'string' ],
				'end'     => [ 'type' => 'string' ],
				'display' => [ 'type' => 'string' ],
			],
			'required'             => [ 'start', 'end', 'display' ],
			'additionalProperties' => false,
		];
	}

	/** Return properties shared by both results. */
	private static function common_properties(): array {
		return [
			'post_id'     => [ 'type' => 'integer' ],
			'count'       => [ 'type' => 'integer' ],
			'mapped'      => [ 'type' => 'integer' ],
			'range'       => self::range_schema(),
			'origin'      => [ 'type' => 'string' ],
			'destination' => [ 'type' => 'string' ],
		];
	}

	/** Return the trip result schema. */
	private static function trip_schema(): array {
		$properties          = self::common_properties();
		$properties['stops'] = [
			'type'  => 'array',
			'items' => [
				'type'                 => 'object',
				'properties'           => [
					'number'      => [ 'type' => 'integer' ],
					'day'         => [ 'type' => 'string' ],
					'day_display' => [ 'type' => 'string' ],
					'label'       => [ 'type' => 'string' ],
					'place'       => [
						'type'                 => 'object',
						'properties'           => self::place_properties(),
						'additionalProperties' => false,
					],
					'lat'         => [ 'type' => [ 'number', 'null' ] ],
					'lng'         => [ 'type' => [ 'number', 'null' ] ],
				],
				'required'             => [ 'number', 'day', 'day_display', 'label', 'place', 'lat', 'lng' ],
				'additionalProperties' => false,
			],
		];
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		];
	}

	/** Return the itinerary result schema. */
	private static function itinerary_schema(): array {
		$clock              = [
			'type'                 => [ 'object', 'null' ],
			'properties'           => [
				'iso'     => [ 'type' => 'string' ],
				'display' => [ 'type' => 'string' ],
			],
			'required'             => [ 'iso', 'display' ],
			'additionalProperties' => false,
		];
		$properties         = self::common_properties();
		$properties['legs'] = [
			'type'  => 'array',
			'items' => [
				'type'                 => 'object',
				'properties'           => [
					'number'    => [ 'type' => 'integer' ],
					'from'      => self::endpoint_schema(),
					'to'        => self::endpoint_schema(),
					'departure' => $clock,
					'arrival'   => $clock,
					'carrier'   => [ 'type' => 'string' ],
				],
				'required'             => [ 'number', 'from', 'to', 'departure', 'arrival', 'carrier' ],
				'additionalProperties' => false,
			],
		];
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		];
	}
}
