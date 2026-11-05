<?php
/**
 * Presentation type-list and reclassification abilities.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\Plugin;
use PKIW\Presentation;
use PKIW\Presentation_Classifier;
use PKIW\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register presentation reads and maintenance operations. */
final class Presentation_Abilities {

	/**
	 * Singleton provider.
	 *
	 * @var Presentation_Abilities|null
	 */
	private static ?Presentation_Abilities $instance = null;

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

	/** Register the public list and private write. */
	public function register(): void {
		wp_register_ability(
			'post-kinds/list-presentation-event-types',
			[
				'label'               => __( 'List Presentation Event Types', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Lists the event types available for presentation posts.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'default'              => [],
					'additionalProperties' => false,
				],
				'output_schema'       => self::types_schema(),
				'execute_callback'    => [ $this, 'execute_list_event_types' ],
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

		wp_register_ability(
			'post-kinds/reclassify-presentations',
			[
				'label'               => __( 'Reclassify Presentations', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Reports or reclassifies presentation candidates in an optional category or post list.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => self::write_input_schema(),
				'output_schema'       => self::report_schema(),
				'execute_callback'    => [ $this, 'execute_reclassify_presentations' ],
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
	 * Return ordered type objects.
	 *
	 * @param array<string, mixed> $input Empty input.
	 * @return array{types:list<array{slug:string,label:string}>}
	 */
	public function execute_list_event_types( array $input = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$types = [];
		foreach ( Presentation::event_types() as $slug => $label ) {
			$types[] = [
				'slug'  => $slug,
				'label' => $label,
			];
		}
		return [ 'types' => $types ];
	}

	/**
	 * Execute the classifier's shared scoped operation.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public function execute_reclassify_presentations( array $input = [] ): array {
		$taxonomy = Plugin::get_instance()->get_taxonomy();
		if ( ! $taxonomy instanceof Taxonomy ) {
			return [ 'dry_run' => true ] + self::empty_report();
		}
		$dry_run = ! array_key_exists( 'dry_run', $input ) || ! in_array( $input['dry_run'], [ false, 0, '0', 'false' ], true );
		$scope   = [ 'category' => sanitize_title( (string) ( $input['category'] ?? '' ) ) ];
		if ( array_key_exists( 'ids', $input ) ) {
			$scope['ids'] = (array) $input['ids'];
		}
		return [ 'dry_run' => $dry_run ] + Presentation_Classifier::backfill( $taxonomy, $dry_run, $scope );
	}

	/**
	 * Return an empty classifier report.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_report(): array {
		return [
			'scanned'      => 0,
			'would_change' => 0,
			'changed'      => 0,
			'protected'    => 0,
			'unchanged'    => 0,
			'failed'       => 0,
			'rows'         => [],
		];
	}

	/**
	 * Return the public read schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function types_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'types' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [
							'slug'  => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
						],
						'required'             => [ 'slug', 'label' ],
						'additionalProperties' => false,
					],
				],
			],
			'required'             => [ 'types' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Return the write input schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function write_input_schema(): array {
		return [
			'type'                 => 'object',
			'default'              => [ 'dry_run' => true ],
			'properties'           => [
				'dry_run'  => [
					'type'    => 'boolean',
					'default' => true,
				],
				'category' => [
					'type'    => 'string',
					'default' => '',
				],
				'ids'      => [
					'type'    => 'array',
					'default' => [],
					'items'   => [
						'type'    => 'integer',
						'minimum' => 1,
					],
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * Return the write report schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function report_schema(): array {
		$string_list = [
			'type'  => 'array',
			'items' => [ 'type' => 'string' ],
		];
		$signals     = [
			'type'                 => 'object',
			'properties'           => [
				'deck'              => $string_list,
				'talk_recording'    => $string_list,
				'recording'         => $string_list,
				'presentation_meta' => [ 'type' => 'boolean' ],
				'listen'            => [ 'type' => 'boolean' ],
			],
			'required'             => [ 'deck', 'talk_recording', 'recording', 'presentation_meta', 'listen' ],
			'additionalProperties' => false,
		];
		$row         = [
			'type'                 => 'object',
			'properties'           => [
				'post_id'     => [ 'type' => 'integer' ],
				'title'       => [ 'type' => 'string' ],
				'kind'        => [ 'type' => 'string' ],
				'auto_marker' => [ 'type' => 'string' ],
				'status'      => [ 'type' => 'string' ],
				'signals'     => $signals,
			],
			'required'             => [ 'post_id', 'title', 'kind', 'auto_marker', 'status', 'signals' ],
			'additionalProperties' => false,
		];
		$properties  = [ 'dry_run' => [ 'type' => 'boolean' ] ];
		foreach ( [ 'scanned', 'would_change', 'changed', 'protected', 'unchanged', 'failed' ] as $key ) {
			$properties[ $key ] = [ 'type' => 'integer' ];
		}
		$properties['rows'] = [
			'type'  => 'array',
			'items' => $row,
		];
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		];
	}
}
