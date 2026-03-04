<?php
/**
 * Format Badge Block (PHP-only, WP 7.0+)
 *
 * Auto-injected before post titles via Block Hooks API to display
 * a visual indicator for non-standard post formats.
 *
 * @package PostKindsForIndieWeb
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format Badge block.
 *
 * Registers a PHP-only block that renders a small format indicator badge.
 * Uses the Block Hooks API to conditionally inject before core/post-title
 * when the post has a non-standard format.
 *
 * @since 1.3.0
 */
final class Format_Badge {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	public const BLOCK_NAME = 'post-formats/format-badge';

	/**
	 * Format-to-dashicon mapping.
	 *
	 * @var array<string, string>
	 */
	private const FORMAT_ICONS = [
		'aside'   => 'dashicons-format-aside',
		'gallery' => 'dashicons-format-gallery',
		'link'    => 'dashicons-admin-links',
		'image'   => 'dashicons-format-image',
		'quote'   => 'dashicons-format-quote',
		'status'  => 'dashicons-format-status',
		'video'   => 'dashicons-format-video',
		'audio'   => 'dashicons-format-audio',
		'chat'    => 'dashicons-format-chat',
	];

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		if ( ! self::is_supported() ) {
			return;
		}

		add_action( 'init', [ $this, 'register' ] );
		add_filter( 'hooked_block_types', [ $this, 'filter_hooked_blocks' ], 10, 4 );
	}

	/**
	 * Check if the current WordPress version supports this feature.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True if WordPress 7.0 or later.
	 */
	public static function is_supported(): bool {
		return version_compare( get_bloginfo( 'version' ), '7.0', '>=' );
	}

	/**
	 * Register the block type.
	 *
	 * @since 1.3.0
	 */
	public function register(): void {
		register_block_type(
			self::BLOCK_NAME,
			[
				'api_version'     => 3,
				'title'           => __( 'Format Badge', 'post-kinds-for-indieweb' ),
				'description'     => __( 'Displays a post format indicator badge.', 'post-kinds-for-indieweb' ),
				'category'        => 'post-kinds-for-indieweb',
				'icon'            => 'tag',
				'keywords'        => [ 'format', 'badge', 'indicator', 'post-format' ],
				'uses_context'    => [ 'postId', 'postType' ],
				'supports'        => [
					'html'                 => false,
					'inserter'             => false,
					'color'                => [
						'background' => true,
						'text'       => true,
					],
					'spacing'              => [
						'padding' => true,
						'margin'  => true,
					],
					'typography'           => [
						'fontSize' => true,
					],
					'__experimentalBorder' => [
						'radius' => true,
					],
				],
				'block_hooks'     => [
					'core/post-title' => 'before',
				],
				'render_callback' => [ $this, 'render' ],
			]
		);
	}

	/**
	 * Conditionally inject the format badge via Block Hooks.
	 *
	 * Only injects when the current post has a non-standard format.
	 *
	 * @since 1.3.0
	 *
	 * @param string[]                      $hooked_blocks List of hooked block types.
	 * @param string                        $relative_to   Block type being hooked relative to.
	 * @param string                        $position      Hook position (before/after/first_child/last_child).
	 * @param array|\WP_Block_Template|null $context Block template, pattern, or null.
	 * @return string[] Modified list of hooked block types.
	 */
	public function filter_hooked_blocks( array $hooked_blocks, string $relative_to, string $position, $context ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( 'core/post-title' !== $relative_to || 'before' !== $position ) {
			return $hooked_blocks;
		}

		$format = get_post_format();

		// Don't inject for standard posts or when no format is set.
		if ( false === $format || 'standard' === $format ) {
			// Remove our block if it was added by block_hooks metadata.
			$key = array_search( self::BLOCK_NAME, $hooked_blocks, true );
			if ( false !== $key ) {
				unset( $hooked_blocks[ $key ] );
			}
			return array_values( $hooked_blocks );
		}

		// Add format badge if not already present.
		if ( ! in_array( self::BLOCK_NAME, $hooked_blocks, true ) ) {
			$hooked_blocks[] = self::BLOCK_NAME;
		}

		return $hooked_blocks;
	}

	/**
	 * Render the format badge on the front end.
	 *
	 * @since 1.3.0
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Block content.
	 * @param \WP_Block $block      Block instance.
	 * @return string Rendered HTML.
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string {
		$post_id = $block->context['postId'] ?? get_the_ID();

		if ( ! $post_id ) {
			return '';
		}

		$format = get_post_format( (int) $post_id );

		// Don't render for standard or no format.
		if ( false === $format || 'standard' === $format ) {
			return '';
		}

		$label = get_post_format_string( $format );
		$icon  = self::FORMAT_ICONS[ $format ] ?? 'dashicons-format-standard';

		$badge_class = 'pk-format-badge pk-format-badge--' . esc_attr( $format );

		// Guard against missing block supports context (e.g., outside normal block rendering).
		if ( ! empty( \WP_Block_Supports::$block_to_render ) ) {
			$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => $badge_class ] );
		} else {
			$wrapper_attrs = sprintf( 'class="%s"', esc_attr( $badge_class ) );
		}

		return sprintf(
			'<span %1$s><span class="dashicons %2$s" aria-hidden="true"></span> %3$s</span>',
			$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $icon ),
			esc_html( $label )
		);
	}
}
