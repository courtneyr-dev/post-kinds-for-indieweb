<?php
/**
 * Public follow index ability.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\Follow_Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register the public follow index. */
final class Follow_Abilities {
	/**
	 * Shared provider instance.
	 *
	 * @var Follow_Abilities|null
	 */
	private static ?Follow_Abilities $instance = null;

	// Ability callback signatures carry the public contract types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/** Return the provider singleton. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Reset the provider between tests. */
	public static function reset(): void {
		self::$instance = null;
	}

	/** Prevent external construction. */
	private function __construct() {}

	/** Register the follow index ability. */
	public function register(): void {
		wp_register_ability(
			'post-kinds/get-follow-index',
			[
				'label'               => __( 'Get Follow Index', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Gets public follows grouped by first letter.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'letter' => [ 'type' => 'string' ],
					],
					'additionalProperties' => false,
				],
				'output_schema'       => self::output_schema(),
				'execute_callback'    => [ $this, 'execute_get_follow_index' ],
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

	/** Return the public index, optionally narrowed to one letter. */
	public function execute_get_follow_index( array $input = [] ): array {
		$index  = Follow_Archive::served_index();
		$letter = isset( $input['letter'] ) && '' !== trim( (string) $input['letter'] ) ? Follow_Archive::initial( (string) $input['letter'] ) : '';
		$rows   = [];
		foreach ( $index['follows'] as $follow ) {
			if ( '' !== $letter && $letter !== $follow['initial'] ) {
				continue;
			}
			$rows[] = [
				'post_id'   => (int) $follow['post_id'],
				'name'      => (string) $follow['name'],
				'host'      => (string) $follow['host'],
				'url'       => (string) $follow['url'],
				'initial'   => (string) $follow['initial'],
				'permalink' => (string) get_permalink( (int) $follow['post_id'] ),
			];
		}
		$letters = [];
		foreach ( $index['letters'] as $key => $data ) {
			if ( '' === $letter || $letter === $key ) {
				$letters[] = [
					'letter' => (string) $key,
					'count'  => (int) $data['count'],
				];
			}
		}
		return [
			'total'   => count( $rows ),
			'letters' => $letters,
			'follows' => $rows,
		];
	}

	/** Ability output schema. */
	private static function output_schema(): array {
		$string = [ 'type' => 'string' ];
		return [
			'type'                 => 'object',
			'properties'           => [
				'total'   => [ 'type' => 'integer' ],
				'letters' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [
							'letter' => $string,
							'count'  => [ 'type' => 'integer' ],
						],
						'required'             => [ 'letter', 'count' ],
						'additionalProperties' => false,
					],
				],
				'follows' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [
							'post_id'   => [ 'type' => 'integer' ],
							'name'      => $string,
							'host'      => $string,
							'url'       => $string,
							'initial'   => $string,
							'permalink' => $string,
						],
						'required'             => [ 'post_id', 'name', 'host', 'url', 'initial', 'permalink' ],
						'additionalProperties' => false,
					],
				],
			],
			'required'             => [ 'total', 'letters', 'follows' ],
			'additionalProperties' => false,
		];
	}
}
