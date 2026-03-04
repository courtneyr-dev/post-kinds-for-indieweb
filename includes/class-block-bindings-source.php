<?php
/**
 * Block Bindings Source for Post Kinds for IndieWeb
 *
 * Registers a kind-aware block bindings source that maps friendly key names
 * to the appropriate internal post meta based on the post's kind taxonomy term.
 *
 * @package PostKindsForIndieWeb
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block Bindings Source
 *
 * Provides a simplified, kind-aware block bindings source for WordPress 7.0+.
 * Maps 9 friendly key names (title, artist, album, etc.) to the correct
 * internal _postkind_* meta key based on the post's indieblocks_kind term.
 *
 * Supplements the existing Block_Bindings class which provides 30+ detailed
 * bindings for WordPress 6.5+.
 *
 * @since 1.2.0
 */
final class Block_Bindings_Source {

	/**
	 * Binding source name.
	 *
	 * @var string
	 */
	public const SOURCE_NAME = 'post-kinds/kind-meta';

	/**
	 * Kind-aware key mapping.
	 *
	 * Maps friendly binding key names to kind-specific meta field suffixes.
	 * Each key maps to an array of [ kind => meta_suffix ] pairs.
	 * The '_default' entry is used when no kind-specific mapping exists.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const KEY_MAP = [
		'title'       => [
			'listen'   => 'listen_track',
			'jam'      => 'listen_track',
			'watch'    => 'watch_title',
			'read'     => 'read_title',
			'_default' => 'cite_name',
		],
		'artist'      => [
			'listen'   => 'listen_artist',
			'jam'      => 'listen_artist',
			'_default' => 'cite_author',
		],
		'album'       => [
			'_default' => 'listen_album',
		],
		'rating'      => [
			'listen'   => 'listen_rating',
			'watch'    => 'watch_rating',
			'read'     => 'read_rating',
			'_default' => 'review_rating',
		],
		'url'         => [
			'listen'   => 'listen_url',
			'jam'      => 'listen_url',
			'watch'    => 'watch_url',
			'read'     => 'read_url',
			'_default' => 'cite_url',
		],
		'cover_image' => [
			'listen'   => 'listen_cover',
			'jam'      => 'listen_cover',
			'watch'    => 'watch_poster',
			'read'     => 'read_cover',
			'_default' => 'cite_photo',
		],
		'summary'     => [
			'_default' => 'cite_summary',
		],
		'author'      => [
			'read'     => 'read_author',
			'_default' => 'cite_author',
		],
		'kind'        => [],
	];

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		if ( ! self::is_supported() ) {
			return;
		}

		add_action( 'init', [ $this, 'register_source' ] );
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	/**
	 * Check if the current WordPress version supports this feature.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if WordPress 7.0 or later.
	 */
	public static function is_supported(): bool {
		return version_compare( get_bloginfo( 'version' ), '7.0', '>=' );
	}

	/**
	 * Register the block bindings source.
	 *
	 * @since 1.2.0
	 */
	public function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		$bindable_keys = array_keys( self::KEY_MAP );

		/**
		 * Filters the list of bindable keys for the post-kinds binding source.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $bindable_keys List of key names available for binding.
		 */
		$bindable_keys = apply_filters( 'pkiw_block_bindings_keys', $bindable_keys );

		register_block_bindings_source(
			self::SOURCE_NAME,
			[
				'label'              => __( 'Post Kind Meta', 'post-kinds-for-indieweb' ),
				'get_value_callback' => [ $this, 'get_value' ],
				'uses_context'       => [ 'postId', 'postType' ],
			]
		);
	}

	/**
	 * Register public post meta aliases for REST API access.
	 *
	 * Creates pk_* prefixed meta keys that mirror the internal _postkind_* keys,
	 * making them accessible via the REST API and block bindings.
	 *
	 * @since 1.2.0
	 */
	public function register_meta(): void {
		$meta_keys = [
			'pk_title'       => 'string',
			'pk_artist'      => 'string',
			'pk_album'       => 'string',
			'pk_rating'      => 'string',
			'pk_url'         => 'string',
			'pk_cover_image' => 'string',
			'pk_summary'     => 'string',
			'pk_author'      => 'string',
			'pk_kind'        => 'string',
		];

		$post_types = [ 'post' ];

		/**
		 * Filters the post types that receive pk_* meta registration.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $post_types Post types for meta registration.
		 */
		$post_types = apply_filters( 'pkiw_block_bindings_post_types', $post_types );

		foreach ( $post_types as $post_type ) {
			foreach ( $meta_keys as $key => $type ) {
				register_post_meta(
					$post_type,
					$key,
					[
						'type'              => $type,
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => 'sanitize_text_field',
						'auth_callback'     => static function () {
							return current_user_can( 'edit_posts' );
						},
					]
				);
			}
		}
	}

	/**
	 * Resolve a binding key to its value for the given post.
	 *
	 * Called by the Block Bindings API when rendering a block with a
	 * post-kinds/kind-meta binding.
	 *
	 * @since 1.2.0
	 *
	 * @param array    $source_args  Binding source arguments. Expected key: 'key'.
	 * @param WP_Block $block_instance The block instance being rendered.
	 * @param string   $attribute_name The block attribute being bound.
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

		// Handle 'kind' key specially — it comes from taxonomy, not meta.
		if ( 'kind' === $key ) {
			return $this->get_kind( (int) $post_id );
		}

		if ( ! isset( self::KEY_MAP[ $key ] ) ) {
			return null;
		}

		$kind        = $this->get_kind( (int) $post_id );
		$key_map     = self::KEY_MAP[ $key ];
		$meta_suffix = $key_map[ $kind ] ?? $key_map['_default'] ?? null;

		if ( ! $meta_suffix ) {
			return null;
		}

		$meta_key = Meta_Fields::PREFIX . $meta_suffix;
		$value    = get_post_meta( (int) $post_id, $meta_key, true );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Get the post kind term slug for a post.
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Kind slug, or empty string if none assigned.
	 */
	private function get_kind( int $post_id ): string {
		$terms = get_the_terms( $post_id, 'indieblocks_kind' );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		return $terms[0]->slug;
	}

	/**
	 * Get all bindable key names.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] List of supported binding keys.
	 */
	public static function get_bindable_keys(): array {
		return array_keys( self::KEY_MAP );
	}
}
