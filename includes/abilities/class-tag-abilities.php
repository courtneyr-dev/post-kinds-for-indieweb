<?php
/**
 * Tag facts and label-repair abilities.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\Operations;
use PKIW\Public_Eligibility;
use PKIW\Tag_Archive;
use PKIW\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register one public read and one private maintenance ability. */
final class Tag_Abilities {

	/**
	 * Shared registrar instance.
	 *
	 * @var Tag_Abilities|null
	 */
	private static ?Tag_Abilities $instance = null;

	/** Return the shared registrar. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Reset the registrar between tests. */
	public static function reset(): void {
		self::$instance = null;
	}

	/** Prevent external construction. */
	private function __construct() {}

	// Ability callbacks expose typed array inputs; omit duplicate parameter tags.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/** Register tag abilities. */
	public function register(): void {
		wp_register_ability(
			'post-kinds/get-tag-facts',
			[
				'label'               => __( 'Get Tag Facts', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Gets public facts for one tag post.', 'post-kinds-for-indieweb-in-block-themes' ),
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
				'execute_callback'    => [ $this, 'execute_get_tag_facts' ],
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
			'post-kinds/backfill-tag-labels',
			[
				'label'               => __( 'Backfill Tag Labels', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Reports or inserts missing ordered tag-label rows.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'                 => 'object',
					'default'              => [ 'dry_run' => true ],
					'properties'           => [
						'dry_run' => [
							'type'    => 'boolean',
							'default' => true,
						],
						'ids'     => [
							'type'  => 'array',
							'items' => [
								'type'    => 'integer',
								'minimum' => 1,
							],
						],
					],
					'additionalProperties' => false,
				],
				'output_schema'       => self::backfill_schema(),
				'execute_callback'    => [ $this, 'execute_backfill_tag_labels' ],
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

	/** Return a uniform 404 for every non-public tag ID. */
	public function execute_get_tag_facts( array $input ): array|\WP_Error {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! has_term( Tag_Archive::KIND, Taxonomy::TAXONOMY, $post ) || ! Public_Eligibility::is_eligible( $post ) ) {
			return new \WP_Error( 'not_found', __( 'No public tag post has this ID.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 404 ] );
		}
		$facts = Tag_Archive::facts( $post_id );
		unset( $facts['image_id'], $facts['tagged_display'] );
		return [ 'post_id' => $post_id ] + $facts;
	}

	/** Execute the shared label repair operation. */
	public function execute_backfill_tag_labels( array $input = [] ): array|\WP_Error {
		return Operations::backfill_tag_labels( (bool) ( $input['dry_run'] ?? true ), isset( $input['ids'] ) ? (array) $input['ids'] : null );
	}

	/** Return the public facts output schema. @return array<string, mixed> */
	private static function facts_schema(): array {
		$properties = [
			'post_id'         => [ 'type' => 'integer' ],
			'target_type'     => [
				'type' => 'string',
				'enum' => [ 'content', 'person' ],
			],
			'labels'          => [
				'type'  => 'array',
				'items' => [ 'type' => 'string' ],
			],
			'title_is_target' => [ 'type' => 'boolean' ],
			'has_card'        => [ 'type' => 'boolean' ],
		];
		foreach ( [ 'url', 'url_host', 'title', 'display_title', 'label', 'description', 'image', 'image_alt', 'initial', 'tagged_iso', 'date_iso' ] as $key ) {
			$properties[ $key ] = [ 'type' => 'string' ];
		}
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		];
	}

	/** Return the label-repair output schema. @return array<string, mixed> */
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
						'type'       => 'object',
						'properties' => [
							'id'     => [ 'type' => 'integer' ],
							'status' => [ 'type' => 'string' ],
							'ops'    => [
								'type'  => 'array',
								'items' => $op,
							],
						],
					],
				],
				'written'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'skipped'    => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'     => [ 'type' => 'integer' ],
							'reason' => [ 'type' => 'string' ],
						],
					],
				],
			],
			'required'             => [ 'dry_run', 'candidates', 'written', 'skipped' ],
			'additionalProperties' => false,
		];
	}
}
