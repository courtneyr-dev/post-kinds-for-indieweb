<?php
/**
 * On This Day public read ability.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\On_This_Day;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register and execute the public On This Day read. */
final class On_This_Day_Abilities {
	// Ability callbacks expose typed array inputs; omit duplicate parameter tags.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/**
	 * Shared registrar instance.
	 *
	 * @var On_This_Day_Abilities|null
	 */
	private static ?On_This_Day_Abilities $instance = null;

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

	/** Register the public read ability. */
	public function register(): void {
		wp_register_ability(
			'post-kinds/get-on-this-day',
			[
				'label'               => __( 'Get On This Day', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Gets public posts from earlier years on one calendar date.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'                 => 'object',
					'default'              => [
						'limit' => 20,
					],
					'properties'           => [
						'date'  => [
							'type'        => 'string',
							'description' => __( 'Site-local date in YYYY-MM-DD format.', 'post-kinds-for-indieweb-in-block-themes' ),
						],
						'limit' => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						],
					],
					'additionalProperties' => false,
				],
				'output_schema'       => self::output_schema(),
				'execute_callback'    => [ $this, 'execute' ],
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

	/** Execute the public read for one site-local day. */
	public function execute( array $input = [] ): array|\WP_Error {
		$today = On_This_Day::today();
		$date  = isset( $input['date'] ) ? (string) $input['date'] : $today->format( 'Y-m-d' );
		$day   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		$valid = $day instanceof \DateTimeImmutable && $day->format( 'Y-m-d' ) === $date;
		if ( ! $valid || $day > $today->setTime( 23, 59, 59 ) ) {
			return new \WP_Error( 'invalid_date', __( 'Date must be a valid YYYY-MM-DD date no later than today.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 400 ] );
		}
		$limit = (int) ( $input['limit'] ?? 20 );
		if ( $limit < 1 || $limit > 100 ) {
			return new \WP_Error( 'invalid_limit', __( 'Limit must be between 1 and 100.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 400 ] );
		}

		$rows = $date === $today->format( 'Y-m-d' ) ? On_This_Day::served_rows( $day ) : On_This_Day::build_rows( $day );
		$rows = array_slice( $rows, 0, $limit );
		$map  = [];
		foreach ( $rows as $row ) {
			$post = get_post( $row['id'] );
			if ( ! $post instanceof \WP_Post || ! On_This_Day::row_visible( $post, On_This_Day::kinds() ) ) {
				continue;
			}
			$data = On_This_Day::display( $post );
			$year = (int) $row['year'];
			if ( ! isset( $map[ $year ] ) ) {
				$map[ $year ] = [
					'year'      => $year,
					'years_ago' => (int) $day->format( 'Y' ) - $year,
					'items'     => [],
				];
			}
			$map[ $year ]['items'][] = [
				'post_id'      => $post->ID,
				'date'         => $data['date'],
				'kind'         => $data['kind'],
				'kind_label'   => $data['kind_label'],
				'title'        => $data['title'],
				'title_source' => $data['stored'] ? 'stored' : 'derived',
				'url'          => $data['url'],
				'image_id'     => $data['image_id'],
			];
		}
		$groups = array_values( $map );
		$total  = array_sum( array_map( static fn( array $group ): int => count( $group['items'] ), $groups ) );

		return [
			'date'     => $date,
			'timezone' => wp_timezone_string(),
			'kinds'    => On_This_Day::kinds(),
			'total'    => $total,
			'groups'   => $groups,
		];
	}

	/** Return the closed public output schema. */
	private static function output_schema(): array {
		$item  = [
			'type'                 => 'object',
			'properties'           => [
				'post_id'      => [ 'type' => 'integer' ],
				'date'         => [ 'type' => 'string' ],
				'kind'         => [ 'type' => 'string' ],
				'kind_label'   => [ 'type' => 'string' ],
				'title'        => [ 'type' => 'string' ],
				'title_source' => [
					'type' => 'string',
					'enum' => [ 'stored', 'derived' ],
				],
				'url'          => [ 'type' => 'string' ],
				'image_id'     => [ 'type' => 'integer' ],
			],
			'required'             => [ 'post_id', 'date', 'kind', 'kind_label', 'title', 'title_source', 'url', 'image_id' ],
			'additionalProperties' => false,
		];
		$group = [
			'type'                 => 'object',
			'properties'           => [
				'year'      => [ 'type' => 'integer' ],
				'years_ago' => [ 'type' => 'integer' ],
				'items'     => [
					'type'  => 'array',
					'items' => $item,
				],
			],
			'required'             => [ 'year', 'years_ago', 'items' ],
			'additionalProperties' => false,
		];

		return [
			'type'                 => 'object',
			'properties'           => [
				'date'     => [ 'type' => 'string' ],
				'timezone' => [ 'type' => 'string' ],
				'kinds'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'total'    => [ 'type' => 'integer' ],
				'groups'   => [
					'type'  => 'array',
					'items' => $group,
				],
			],
			'required'             => [ 'date', 'timezone', 'kinds', 'total', 'groups' ],
			'additionalProperties' => false,
		];
	}
}
