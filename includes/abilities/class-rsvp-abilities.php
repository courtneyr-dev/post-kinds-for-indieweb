<?php
/**
 * RSVP facts and repair abilities.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\Kind_Facts;
use PKIW\Operations;
use PKIW\Rsvp_Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register RSVP read and maintenance abilities. */
final class Rsvp_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var Rsvp_Abilities|null
	 */
	private static ?Rsvp_Abilities $instance = null;

	/** Return the provider singleton. */
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

	/** The provider has no constructor work. */
	private function __construct() {}

	/** Register both abilities. */
	public function register(): void {
		wp_register_ability(
			'post-kinds/get-rsvp-facts',
			[
				'label'               => __( 'Get RSVP Facts', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Gets the visitor-safe facts for one RSVP post.', 'post-kinds-for-indieweb-in-block-themes' ),
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
				'output_schema'       => self::facts_schema(),
				'execute_callback'    => [ $this, 'execute_get_rsvp_facts' ],
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

		$input                      = self::write_input_schema();
		$input['properties']['ids'] = [
			'type'  => 'array',
			'items' => [
				'type'    => 'integer',
				'minimum' => 1,
			],
		];
		wp_register_ability(
			'post-kinds/backfill-rsvp-meta',
			[
				'label'               => __( 'Backfill RSVP Meta', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Reports or inserts missing RSVP metadata for an explicit post list.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => $input,
				'output_schema'       => self::backfill_schema(),
				'execute_callback'    => [ $this, 'execute_backfill_rsvp_meta' ],
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'meta'                => [
					'mcp'         => [ 'public' => false ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);
	}

	/**
	 * Return a fixed, visitor-safe facts subset.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_get_rsvp_facts( array $input ): array|\WP_Error {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'invalid_post', __( 'RSVP post not found.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 404 ] );
		}
		if ( Rsvp_Archive::KIND !== Kind_Facts::kind_of( $post ) ) {
			return new \WP_Error( 'invalid_kind', __( 'The post is not an RSVP.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 404 ] );
		}
		$facts = Kind_Facts::get( $post_id );
		if ( [] === $facts ) {
			return new \WP_Error( 'forbidden', __( 'The RSVP is not readable.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 403 ] );
		}
		$defaults = [
			'name'          => '',
			'url'           => '',
			'url_host'      => '',
			'status'        => '',
			'status_label'  => '',
			'start_iso'     => '',
			'start_day'     => '',
			'day_display'   => '',
			'time_display'  => '',
			'end_iso'       => '',
			'location'      => '',
			'description'   => '',
			'image'         => '',
			'image_alt'     => '',
			'group'         => 'past',
			'name_is_title' => false,
			'all_day'       => false,
			'has_card'      => false,
			'image_local'   => false,
		];
		$out      = [ 'post_id' => $post_id ];
		foreach ( $defaults as $key => $default ) {
			$out[ $key ] = $facts[ $key ] ?? $default;
		}
		return $out;
	}

	/**
	 * Execute the shared RSVP repair operation.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_backfill_rsvp_meta( array $input = [] ): array|\WP_Error {
		return Operations::backfill_rsvp_meta( (bool) ( $input['dry_run'] ?? true ), isset( $input['ids'] ) ? (array) $input['ids'] : null );
	}

	/** Read output schema. @return array<string, mixed> */
	private static function facts_schema(): array {
		$properties = [ 'post_id' => [ 'type' => 'integer' ] ];
		foreach ( [ 'name', 'url', 'url_host', 'status', 'status_label', 'start_iso', 'start_day', 'day_display', 'time_display', 'end_iso', 'location', 'description', 'image', 'image_alt' ] as $key ) {
			$properties[ $key ] = [ 'type' => 'string' ];
		}
		$properties['group'] = [
			'type' => 'string',
			'enum' => Rsvp_Archive::GROUPS,
		];
		foreach ( [ 'name_is_title', 'all_day', 'has_card', 'image_local' ] as $key ) {
			$properties[ $key ] = [ 'type' => 'boolean' ];
		}
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		];
	}

	/** Write input schema. @return array<string, mixed> */
	private static function write_input_schema(): array {
		return [
			'type'                 => 'object',
			'default'              => [ 'dry_run' => true ],
			'properties'           => [
				'dry_run' => [
					'type'    => 'boolean',
					'default' => true,
				],
			],
			'additionalProperties' => false,
		];
	}

	/** Write output schema. @return array<string, mixed> */
	private static function backfill_schema(): array {
		$op = [
			'type'                 => 'object',
			'properties'           => [
				'op'      => [ 'type' => 'string' ],
				'post_id' => [ 'type' => 'integer' ],
				'key'     => [ 'type' => 'string' ],
				'value'   => [ 'type' => 'string' ],
			],
			'additionalProperties' => false,
		];
		return [
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
							'ops'      => [
								'type'  => 'array',
								'items' => $op,
							],
						],
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
						'additionalProperties' => false,
					],
				],
			],
			'additionalProperties' => false,
		];
	}
}
