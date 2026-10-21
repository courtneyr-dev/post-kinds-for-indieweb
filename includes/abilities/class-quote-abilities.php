<?php
/**
 * Read-only quote index ability.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW\Abilities;

use PKIW\Abilities_Manager;
use PKIW\Quote_Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the aggregate quote index for REST and MCP readers.
 *
 * @since 1.9.0
 */
final class Quote_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var Quote_Abilities|null
	 */
	private static ?Quote_Abilities $instance = null;

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

	/**
	 * Register the quote index ability.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_ability(
			'post-kinds/get-quote-index',
			[
				'label'               => __( 'Get Quote Index', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Gets public quote counts grouped by author, initial, and source work.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => new \stdClass(),
					'default'    => [],
				],
				'output_schema'       => self::output_schema(),
				'execute_callback'    => [ $this, 'execute_get_quote_index' ],
				'permission_callback' => static fn(): bool => current_user_can( 'read' ),
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	/**
	 * Return the public aggregate without initial post IDs.
	 *
	 * @param array<string, mixed> $input Empty input.
	 * @return array{authors:list<array{name:string, initial:string, count:int}>, initials:list<array{letter:string, count:int}>, works:list<array{name:string, author:string, count:int}>, total:int}
	 */
	public function execute_get_quote_index( array $input = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Ability callback signature.
		$index    = Quote_Archive::index();
		$initials = [];
		foreach ( $index['initials'] as $letter => $data ) {
			$initials[] = [
				'letter' => (string) $letter,
				'count'  => $data['count'],
			];
		}
		$works = array_map(
			static fn( array $work ): array => [
				'name'   => $work['name'],
				'author' => $work['author'],
				'count'  => $work['count'],
			],
			$index['works']
		);

		return [
			'authors'  => $index['authors'],
			'initials' => $initials,
			'works'    => $works,
			'total'    => $index['total'],
		];
	}

	/** Ability output schema. */
	private static function output_schema(): array {
		$counted = static fn( array $properties ): array => [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => $properties,
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'authors'  => $counted(
					[
						'name'    => [ 'type' => 'string' ],
						'initial' => [ 'type' => 'string' ],
						'count'   => [ 'type' => 'integer' ],
					]
				),
				'initials' => $counted(
					[
						'letter' => [ 'type' => 'string' ],
						'count'  => [ 'type' => 'integer' ],
					]
				),
				'works'    => $counted(
					[
						'name'   => [ 'type' => 'string' ],
						'author' => [ 'type' => 'string' ],
						'count'  => [ 'type' => 'integer' ],
					]
				),
				'total'    => [ 'type' => 'integer' ],
			],
		];
	}
}
