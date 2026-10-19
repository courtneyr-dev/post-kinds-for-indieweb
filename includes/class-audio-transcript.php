<?php
/**
 * Audio transcript discovery and block pattern.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the first filled audio transcript group in post content.
 *
 * @since 1.9.0
 */
final class Audio_Transcript {

	/**
	 * Transcript group class.
	 *
	 * @var string
	 */
	public const CLASS_NAME = 'pkiw-transcript';

	/**
	 * Transcript block pattern name.
	 *
	 * @var string
	 */
	public const PATTERN = 'post-kinds-indieweb/audio-transcript';

	/**
	 * Register the transcript block pattern after its category.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( did_action( 'init' ) > 0 ) {
			self::register_pattern();
			return;
		}

		add_action( 'init', [ self::class, 'register_pattern' ], 11 );
	}

	/**
	 * Register the transcript group pattern once.
	 *
	 * @return void
	 */
	public static function register_pattern(): void {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		if ( $registry->is_registered( self::PATTERN ) ) {
			return;
		}

		register_block_pattern(
			self::PATTERN,
			[
				'title'      => __( 'Audio transcript', 'post-kinds-for-indieweb-in-block-themes' ),
				'categories' => [ 'post-kinds-for-indieweb-in-block-themes' ],
				'content'    => '<!-- wp:group {"className":"pkiw-transcript"} -->'
					. '<div class="wp-block-group pkiw-transcript"><!-- wp:paragraph -->'
					. '<p></p>'
					. '<!-- /wp:paragraph --></div>'
					. '<!-- /wp:group -->',
			]
		);
	}

	/**
	 * Find the first non-empty transcript group in document order.
	 *
	 * @param int|\WP_Post $post Post object or ID.
	 * @return array<string, mixed>|null Parsed transcript group, or null.
	 */
	public static function find( int|\WP_Post $post ): ?array {
		$post = is_int( $post ) ? get_post( $post ) : $post;
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		foreach ( flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( ! is_array( $block ) || 'core/group' !== ( $block['blockName'] ?? '' ) || ! self::has_class( $block ) ) {
				continue;
			}

			if ( self::has_text( $block ) ) {
				return $block;
			}
		}

		return null;
	}

	/**
	 * Whether a post contains a filled transcript group.
	 *
	 * @param int|\WP_Post $post Post object or ID.
	 * @return bool True when a transcript is present.
	 */
	public static function has_transcript( int|\WP_Post $post ): bool {
		return null !== self::find( $post );
	}

	/**
	 * Whether a group has the transcript class as a complete token.
	 *
	 * @param array<string, mixed> $block Parsed group block.
	 * @return bool True when the class token is present.
	 */
	private static function has_class( array $block ): bool {
		$class_name = is_string( $block['attrs']['className'] ?? null ) ? $block['attrs']['className'] : '';
		$classes    = preg_split( '/\s+/', trim( $class_name ) );

		return is_array( $classes ) && in_array( self::CLASS_NAME, $classes, true );
	}

	/**
	 * Whether a transcript group's descendants contain readable text.
	 *
	 * @param array<string, mixed> $block Parsed transcript group.
	 * @return bool True when normalized text remains.
	 */
	private static function has_text( array $block ): bool {
		$html = self::descendant_content( $block );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[\s\x{00A0}]+/u', '', $text );

		return '' !== $text;
	}

	/**
	 * Concatenate a block tree's stored inner-content strings.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string Descendant HTML in document order.
	 */
	private static function descendant_content( array $block ): string {
		$content = '';
		foreach ( $block['innerContent'] ?? [] as $fragment ) {
			if ( is_string( $fragment ) ) {
				$content .= $fragment;
			}
		}

		foreach ( $block['innerBlocks'] ?? [] as $inner_block ) {
			if ( is_array( $inner_block ) ) {
				$content .= self::descendant_content( $inner_block );
			}
		}

		return $content;
	}
}
