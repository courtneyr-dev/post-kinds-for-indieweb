<?php
/**
 * Zine manifest ability.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\Zine_Manifest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register and execute the public zine manifest read. */
final class Zine_Abilities {
	/**
	 * Shared registrar instance.
	 *
	 * @var Zine_Abilities|null
	 */
	private static ?Zine_Abilities $instance = null;

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

	/** Register the public zine manifest read. */
	public function register(): void {
		wp_register_ability(
			'post-kinds/zine-manifest',
			[
				'label'               => __( 'Get Zine Manifest', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Gets the public entries and booklet layout for a kind archive or Stream zine.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => self::input_schema(),
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

	/**
	 * Build one public zine manifest.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		$source = is_string( $input['source'] ?? null ) ? $input['source'] : '';
		$pages  = self::integer_input( $input['pages'] ?? 8 );
		if ( ! in_array( $source, [ 'archive', 'stream' ], true ) || null === $pages || ! in_array( $pages, [ 8, 16 ], true ) ) {
			return self::invalid_args();
		}

		$from = self::date_input( $input, 'from' );
		$to   = self::date_input( $input, 'to' );
		if ( null === $from || null === $to || ( '' !== $from && '' !== $to && $from > $to ) ) {
			return self::invalid_args();
		}

		$orderby = $input['orderby'] ?? 'date';
		$order   = $input['order'] ?? 'desc';
		if ( ! is_string( $orderby ) || ! in_array( $orderby, [ 'date', 'title' ], true ) || ! is_string( $order ) || ! in_array( $order, [ 'asc', 'desc' ], true ) ) {
			return self::invalid_args();
		}

		$args = [
			'source'  => $source,
			'pages'   => $pages,
			'from'    => $from,
			'to'      => $to,
			'orderby' => $orderby,
			'order'   => $order,
		];
		if ( 'archive' === $source ) {
			if ( ! isset( $input['kind'] ) || ! is_string( $input['kind'] ) || '' === $input['kind'] ) {
				return self::invalid_args();
			}
			$args['kind'] = $input['kind'];
		} else {
			if ( ! array_key_exists( 'page_id', $input ) ) {
				return self::invalid_args();
			}
			$page_id = self::integer_input( $input['page_id'] );
			if ( null === $page_id || $page_id < 1 ) {
				return self::invalid_args();
			}
			$args['page_id'] = $page_id;
		}

		$manifest = Zine_Manifest::build( $args );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		if ( 'archive' === $source && 0 === (int) $manifest['total'] ) {
			return self::not_found();
		}
		foreach ( $manifest['entries'] as &$entry ) {
			unset( $entry['kindLabel'] );
		}
		unset( $entry );

		return $manifest;
	}

	/**
	 * Return an integer input, including numeric REST query strings.
	 *
	 * @param mixed $value Raw input value.
	 */
	private static function integer_input( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A-?\d+\z/', $value ) ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * Return a validated date, an empty bound, or null for invalid input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $key   Date-bound key.
	 */
	private static function date_input( array $input, string $key ): ?string {
		if ( ! array_key_exists( $key, $input ) || '' === $input[ $key ] ) {
			return '';
		}
		if ( ! is_string( $input[ $key ] ) || 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/', $input[ $key ], $parts ) ) {
			return null;
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $input[ $key ] : null;
	}

	/** Return the uniform invalid-argument response. */
	private static function invalid_args(): \WP_Error {
		return new \WP_Error( 'invalid_zine_args', __( 'Invalid zine arguments.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 400 ] );
	}

	/** Return the uniform empty-source response. */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'not_found', __( 'Zine source not found.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 404 ] );
	}

	/** Return the open semantic input schema with closed properties. */
	private static function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'source'  => [
					'type'        => 'string',
					'enum'        => [ 'archive', 'stream' ],
					'description' => __( 'Select a kind archive or a Stream page.', 'post-kinds-for-indieweb-in-block-themes' ),
				],
				'kind'    => [
					'type'        => 'string',
					'description' => __( 'Kind slug, required for an archive source.', 'post-kinds-for-indieweb-in-block-themes' ),
				],
				'page_id' => [
					'type'        => 'integer',
					'description' => __( 'Stream page ID, required for a stream source.', 'post-kinds-for-indieweb-in-block-themes' ),
				],
				'pages'   => [
					'type'        => 'integer',
					'default'     => 8,
					'description' => __( 'Booklet page count: 8 or 16.', 'post-kinds-for-indieweb-in-block-themes' ),
				],
				'from'    => [
					'type'        => 'string',
					'description' => __( 'Optional first date in YYYY-MM-DD format.', 'post-kinds-for-indieweb-in-block-themes' ),
				],
				'to'      => [
					'type'        => 'string',
					'description' => __( 'Optional last date in YYYY-MM-DD format.', 'post-kinds-for-indieweb-in-block-themes' ),
				],
				'orderby' => [
					'type'        => 'string',
					'default'     => 'date',
					'description' => __( 'Order archive entries by date or title.', 'post-kinds-for-indieweb-in-block-themes' ),
				],
				'order'   => [
					'type'        => 'string',
					'default'     => 'desc',
					'description' => __( 'Sort in asc or desc order.', 'post-kinds-for-indieweb-in-block-themes' ),
				],
			],
			'required'             => [ 'source' ],
			'additionalProperties' => false,
		];
	}

	/** Return the closed public manifest output schema. */
	private static function output_schema(): array {
		$entry   = [
			'type'                 => 'object',
			'properties'           => [
				'id'        => [ 'type' => 'integer' ],
				'title'     => [ 'type' => 'string' ],
				'kind'      => [ 'type' => [ 'string', 'null' ] ],
				'date'      => [ 'type' => 'string' ],
				'permalink' => [ 'type' => 'string' ],
				'page'      => [ 'type' => 'integer' ],
			],
			'required'             => [ 'id', 'title', 'kind', 'date', 'permalink', 'page' ],
			'additionalProperties' => false,
		];
		$content = [
			'type'                 => 'object',
			'properties'           => [
				'title' => [ 'type' => 'string' ],
				'page'  => [ 'type' => 'integer' ],
			],
			'required'             => [ 'title', 'page' ],
			'additionalProperties' => false,
		];
		$cell    = [
			'type'                 => 'object',
			'properties'           => [
				'page'     => [ 'type' => 'integer' ],
				'row'      => [
					'type' => 'string',
					'enum' => [ 'top', 'bottom' ],
				],
				'col'      => [ 'type' => 'integer' ],
				'inverted' => [ 'type' => 'boolean' ],
			],
			'required'             => [ 'page', 'row', 'col', 'inverted' ],
			'additionalProperties' => false,
		];
		$side    = [
			'type'                 => 'object',
			'properties'           => [
				'side'  => [ 'type' => 'integer' ],
				'cells' => [
					'type'  => 'array',
					'items' => $cell,
				],
			],
			'required'             => [ 'side', 'cells' ],
			'additionalProperties' => false,
		];

		return [
			'type'                 => 'object',
			'properties'           => [
				'source'     => [
					'type' => 'string',
					'enum' => [ 'archive', 'stream' ],
				],
				'kind'       => [ 'type' => [ 'string', 'null' ] ],
				'page_id'    => [ 'type' => [ 'integer', 'null' ] ],
				'pages'      => [ 'type' => 'integer' ],
				'capacity'   => [ 'type' => 'integer' ],
				'total'      => [ 'type' => 'integer' ],
				'included'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'from'       => [ 'type' => 'string' ],
				'to'         => [ 'type' => 'string' ],
				'entries'    => [
					'type'  => 'array',
					'items' => $entry,
				],
				'contents'   => [
					'type'  => 'array',
					'items' => $content,
				],
				'span'       => [
					'oneOf' => [
						[
							'type'     => 'array',
							'maxItems' => 0,
						],
						[
							'type'                 => 'object',
							'properties'           => [
								'from' => [ 'type' => 'string' ],
								'to'   => [ 'type' => 'string' ],
							],
							'required'             => [ 'from', 'to' ],
							'additionalProperties' => false,
						],
					],
				],
				'imposition' => [
					'type'                 => 'object',
					'properties'           => [
						'pages' => [ 'type' => 'integer' ],
						'sides' => [
							'type'  => 'array',
							'items' => $side,
						],
					],
					'required'             => [ 'pages', 'sides' ],
					'additionalProperties' => false,
				],
			],
			'required'             => [ 'source', 'kind', 'page_id', 'pages', 'capacity', 'total', 'included', 'from', 'to', 'entries', 'contents', 'span', 'imposition' ],
			'additionalProperties' => false,
		];
	}
}
