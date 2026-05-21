<?php
/**
 * Block Bindings Source for Post Format Data
 *
 * Registers post-formats/format-data so theme developers can bind
 * core blocks to post format information (name, label, icon, etc.).
 *
 * @package PostKindsForIndieWeb
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block Bindings Formats
 *
 * Provides a block bindings source for WordPress post format metadata.
 * Theme developers can bind core blocks to format_name, format_label,
 * format_icon, has_format, char_count, media_url, and quote_attribution.
 *
 * @since 1.3.0
 */
final class Block_Bindings_Formats {

	/**
	 * Binding source name.
	 *
	 * @var string
	 */
	public const SOURCE_NAME = 'post-formats/format-data';

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
	 * Supported bindable keys.
	 *
	 * @var string[]
	 */
	private const BINDABLE_KEYS = [
		'format_name',
		'format_label',
		'format_icon',
		'has_format',
		'char_count',
		'media_url',
		'quote_attribution',
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

		add_action( 'init', [ $this, 'register_source' ] );
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
	 * Register the block bindings source.
	 *
	 * @since 1.3.0
	 */
	public function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			self::SOURCE_NAME,
			[
				'label'              => __( 'Post Format Data', 'post-kinds-for-indieweb' ),
				'get_value_callback' => [ $this, 'get_value' ],
				'uses_context'       => [ 'postId', 'postType' ],
			]
		);
	}

	/**
	 * Resolve a binding key to its value for the given post.
	 *
	 * @since 1.3.0
	 *
	 * @param array     $source_args    Binding source arguments. Expected key: 'key'.
	 * @param \WP_Block $block_instance The block instance being rendered.
	 * @param string    $attribute_name The block attribute being bound.
	 * @return string|null The resolved value, or null if not found.
	 */
	public function get_value( array $source_args, $block_instance, string $attribute_name ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( empty( $source_args['key'] ) ) {
			return null;
		}

		$key     = $source_args['key'];
		$post_id = $block_instance->context['postId'] ?? get_the_ID();

		if ( ! $post_id ) {
			return null;
		}

		if ( ! in_array( $key, self::BINDABLE_KEYS, true ) ) {
			return null;
		}

		$format = get_post_format( (int) $post_id );

		// get_post_format() returns false for standard/no format.
		if ( false === $format ) {
			$format = 'standard';
		}

		switch ( $key ) {
			case 'format_name':
				return esc_html( $format );

			case 'format_label':
				return esc_html( $this->get_format_label( $format ) );

			case 'format_icon':
				return esc_attr( self::FORMAT_ICONS[ $format ] ?? 'dashicons-format-standard' );

			case 'has_format':
				return 'standard' !== $format ? 'true' : 'false';

			case 'char_count':
				return $this->get_char_count( (int) $post_id, $format );

			case 'media_url':
				return $this->get_media_url( (int) $post_id, $format );

			case 'quote_attribution':
				return $this->get_quote_attribution( (int) $post_id, $format );

			default:
				return null;
		}
	}

	/**
	 * Get translated format label.
	 *
	 * @since 1.3.0
	 *
	 * @param string $format Post format slug.
	 * @return string Translated label.
	 */
	private function get_format_label( string $format ): string {
		if ( 'standard' === $format ) {
			return __( 'Standard', 'post-kinds-for-indieweb' );
		}

		$label = get_post_format_string( $format );

		return $label ? $label : $format;
	}

	/**
	 * Get character count for status posts.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $format  Post format slug.
	 * @return string|null Character count string, or null if not a status post.
	 */
	private function get_char_count( int $post_id, string $format ): ?string {
		if ( 'status' !== $format ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		$content = wp_strip_all_tags( $post->post_content );

		return esc_html( (string) mb_strlen( $content ) );
	}

	/**
	 * Get media URL for audio/video posts.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $format  Post format slug.
	 * @return string|null Media URL, or null if not an audio/video post.
	 */
	private function get_media_url( int $post_id, string $format ): ?string {
		if ( ! in_array( $format, [ 'audio', 'video' ], true ) ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		// Try to extract the first URL from the post content.
		$url = get_url_in_content( $post->post_content );

		if ( $url ) {
			return esc_url( $url );
		}

		// Fall back to first attached media.
		$media_type  = 'audio' === $format ? 'audio' : 'video';
		$attachments = get_attached_media( $media_type, $post_id );

		if ( ! empty( $attachments ) ) {
			$attachment = reset( $attachments );
			$url        = wp_get_attachment_url( $attachment->ID );

			return $url ? esc_url( $url ) : null;
		}

		return null;
	}

	/**
	 * Get quote attribution for quote posts.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $format  Post format slug.
	 * @return string|null Attribution text, or null if not a quote post.
	 */
	private function get_quote_attribution( int $post_id, string $format ): ?string {
		if ( 'quote' !== $format ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		// Check post meta for attribution.
		$attribution = get_post_meta( $post_id, 'quote_source_name', true );

		if ( is_string( $attribution ) && '' !== $attribution ) {
			return esc_html( $attribution );
		}

		// Try the post title as attribution (common pattern for quote posts).
		if ( ! empty( $post->post_title ) ) {
			return esc_html( $post->post_title );
		}

		return null;
	}

	/**
	 * Get all bindable key names.
	 *
	 * @since 1.3.0
	 *
	 * @return string[] List of supported binding keys.
	 */
	public static function get_bindable_keys(): array {
		return self::BINDABLE_KEYS;
	}
}
