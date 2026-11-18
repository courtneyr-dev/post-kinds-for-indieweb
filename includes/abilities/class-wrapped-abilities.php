<?php
/**
 * Wrapped read and draft-creation abilities.
 *
 * @package PKIW
 */

declare(strict_types=1);
namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\Public_Listing;
use PKIW\Wrapped_Post;
use PKIW\Wrapped_Stats;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register the Wrapped ability pair. */
final class Wrapped_Abilities {
	// Public callback signatures document their input types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/**
	 * Shared provider.
	 *
	 * @var Wrapped_Abilities|null
	 */
	private static ?Wrapped_Abilities $instance = null;
	/** Return the shared provider. */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** Reset the provider for tests. */
	public static function reset(): void {
		self::$instance = null;
	}

	/** Prevent external construction. */
	private function __construct() {}

	/** Register both abilities. */
	public function register(): void {
		wp_register_ability(
			'post-kinds/kind-year-stats',
			[
				'label'               => __( 'Get kind year statistics', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Gets public Wrapped statistics for one kind and year.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::stats_schema(),
				'execute_callback'    => [ $this, 'stats' ],
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
			'post-kinds/create-wrapped-draft',
			[
				'label'               => __( 'Create Wrapped draft', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Prepares or creates a draft Wrapped post.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => self::draft_input_schema(),
				'output_schema'       => self::draft_output_schema(),
				'execute_callback'    => [ $this, 'create_draft' ],
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
			]
		);
	}
	/** Execute the public read. */
	public function stats( array $input ): array|\WP_Error {
		$year = (int) ( $input['year'] ?? 0 );
		if ( $year < 2000 || $year > (int) wp_date( 'Y' ) ) {
			return new \WP_Error( 'invalid_wrapped_scope', __( 'Kind and year must identify a valid Wrapped scope.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 400 ] );
		}
		$data = Wrapped_Stats::for_kind_year( sanitize_key( (string) ( $input['kind'] ?? '' ) ), $year );
		if ( [] === $data ) {
			return new \WP_Error( 'invalid_wrapped_scope', __( 'Kind and year must identify a valid Wrapped scope.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 400 ] );
		}
		unset( $data['schema'], $data['gen'] );
		$top = [];
		foreach ( $data['top'] as $row ) {
			if ( Public_Listing::is_listable( (int) $row['post_id'] ) ) {
				$row['title']     = get_the_title( (int) $row['post_id'] );
				$row['permalink'] = get_permalink( (int) $row['post_id'] );
				$top[]            = $row;
			}
		}
		$data['top'] = $top;

		return $data;
	}
	/** Execute a dry-run or create a draft post. */
	public function create_draft( array $input ): array|\WP_Error {
		$kind = sanitize_key( (string) ( $input['kind'] ?? '' ) );
		$year = (int) ( $input['year'] ?? 0 );
		$term = get_term_by( 'slug', $kind, 'kind' );
		if ( ! $term instanceof \WP_Term || $year < 2000 || $year > (int) wp_date( 'Y' ) ) {
			return new \WP_Error( 'invalid_wrapped_scope', __( 'Kind and year must identify a valid Wrapped scope.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 400 ] );
		}
		$title = trim( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = sprintf( '%s %d Wrapped', $term->name, $year );
		}
		$content  = '<!-- wp:post-kinds-indieweb/wrapped {"kind":"' . $kind . '","year":' . $year . '} /-->' . "\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
		$existing = get_posts(
			Public_Listing::query_args(
				[
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'meta_query'     => [
						[
							'key'   => Wrapped_Post::KIND_META,
							'value' => $kind,
						],
						[
							'key'   => Wrapped_Post::YEAR_META,
							'value' => $year,
							'type'  => 'NUMERIC',
						],
					],
				]
			)
		);
		if ( false !== ( $input['dry_run'] ?? true ) ) {
			return [
				'dry_run'            => true,
				'would_create'       => [
					'title'   => $title,
					'content' => $content,
				],
				'existing_published' => array_map( 'intval', $existing ),
			];
		}
		$id = wp_insert_post(
			[
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return [
			'dry_run'            => false,
			'created'            => [
				'post_id'  => $id,
				'edit_url' => get_edit_post_link( $id, 'raw' ),
			],
			'existing_published' => array_map( 'intval', $existing ),
		];
	}
	/** Return the read input schema. */
	private static function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'kind' => [ 'type' => 'string' ],
				'year' => [
					'type'    => 'integer',
					'minimum' => 2000,
				],
			],
			'required'             => [ 'kind', 'year' ],
			'additionalProperties' => false,
		];
	}

	/** Return the draft input schema. */
	private static function draft_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'kind'    => [ 'type' => 'string' ],
				'year'    => [
					'type'    => 'integer',
					'minimum' => 2000,
				],
				'title'   => [ 'type' => 'string' ],
				'dry_run' => [
					'type'    => 'boolean',
					'default' => true,
				],
			],
			'required'             => [ 'kind', 'year' ],
			'additionalProperties' => false,
		];
	}

	/** Return the read output schema. */
	private static function stats_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'kind'    => [ 'type' => 'string' ],
				'year'    => [ 'type' => 'integer' ],
				'main'    => [ 'type' => 'string' ],
				'metrics' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [
							'key'     => [ 'type' => 'string' ],
							'label'   => [ 'type' => 'string' ],
							'value'   => [ 'type' => 'number' ],
							'display' => [ 'type' => 'string' ],
							'basis'   => [ 'type' => [ 'integer', 'null' ] ],
						],
						'required'             => [ 'key', 'label', 'value', 'display', 'basis' ],
						'additionalProperties' => false,
					],
				],
				'top'     => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [
							'post_id'   => [ 'type' => 'integer' ],
							'rating'    => [ 'type' => 'number' ],
							'finished'  => [ 'type' => 'string' ],
							'title'     => [ 'type' => 'string' ],
							'permalink' => [
								'type'   => 'string',
								'format' => 'uri',
							],
						],
						'required'             => [ 'post_id', 'rating', 'finished', 'title', 'permalink' ],
						'additionalProperties' => false,
					],
				],
			],
			'required'             => [ 'kind', 'year', 'main', 'metrics', 'top' ],
			'additionalProperties' => false,
		];
	}

	/** Return the draft output schema. */
	private static function draft_output_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'dry_run'            => [ 'type' => 'boolean' ],
				'would_create'       => [
					'type'                 => 'object',
					'properties'           => [
						'title'   => [ 'type' => 'string' ],
						'content' => [ 'type' => 'string' ],
					],
					'required'             => [ 'title', 'content' ],
					'additionalProperties' => false,
				],
				'created'            => [
					'type'                 => 'object',
					'properties'           => [
						'post_id'  => [ 'type' => 'integer' ],
						'edit_url' => [ 'type' => [ 'string', 'null' ] ],
					],
					'required'             => [ 'post_id', 'edit_url' ],
					'additionalProperties' => false,
				],
				'existing_published' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
			],
			'required'             => [ 'dry_run', 'existing_published' ],
			'additionalProperties' => false,
		];
	}
}
