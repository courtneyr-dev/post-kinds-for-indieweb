<?php
/**
 * Reading time block for article archives.
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
 * Server-rendered reading time block (issue 263).
 *
 * @since 1.9.0
 */
final class Reading_Time {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	public const BLOCK = 'post-kinds-indieweb/reading-time';

	/**
	 * Whether the block registration hook is wired.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Whether the init registration hook is wired.
	 *
	 * @var bool
	 */
	private static bool $block_hook_registered = false;

	/**
	 * Whether the block type is registered.
	 *
	 * @var bool
	 */
	private static bool $is_registered = false;

	/**
	 * How deeply core/post-template is currently rendering.
	 *
	 * @var int
	 */
	private static int $template_depth = 0;

	/**
	 * Reading-time cache.
	 *
	 * @var array<string, int|null>
	 */
	private static array $minutes = [];

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! self::$hooks_registered ) {
			self::$hooks_registered = true;
			add_filter( 'render_block_data', [ self::class, 'enter_template' ] );
			add_filter( 'render_block_core/post-template', [ self::class, 'leave_template' ] );
		}

		if ( did_action( 'init' ) ) {
			self::register_block();
		} elseif ( ! self::$block_hook_registered ) {
			self::$block_hook_registered = true;
			add_action( 'init', [ self::class, 'register_block' ] );
		}
	}

	/**
	 * Register the block and its plain editor script.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		wp_register_script(
			'pkiw-reading-time-editor',
			\PKIW_URL . 'assets/js/reading-time-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-reading-time-editor', 'post-kinds-for-indieweb-in-block-themes' );

		if ( self::$is_registered || \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			self::$is_registered = true;
			return;
		}

		/**
		 * Block type arguments. No block.json, so apiVersion 3 is declared here.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Reading time', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'ancestor'        => [ 'core/post-template' ],
			'uses_context'    => [ 'postId', 'postType' ],
			'attributes'      => [],
			'supports'        => [
				'html'       => false,
				'reusable'   => false,
				'typography' => [
					'fontSize' => true,
				],
			],
			'render_callback' => [ self::class, 'render' ],
			'editor_script'   => 'pkiw-reading-time-editor',
		];
		register_block_type( self::BLOCK, $args );
		self::$is_registered = true;
	}

	/**
	 * Enter a core/post-template render.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $parsed Parsed block.
	 * @return array<string, mixed>
	 */
	public static function enter_template( array $parsed ): array {
		if ( 'core/post-template' === ( $parsed['blockName'] ?? '' ) ) {
			++self::$template_depth;
		}

		return $parsed;
	}

	/**
	 * Leave a core/post-template render.
	 *
	 * @since 1.9.0
	 *
	 * @param string $html Rendered block HTML.
	 * @return string
	 */
	public static function leave_template( string $html ): string {
		self::$template_depth = max( 0, self::$template_depth - 1 );

		return $html;
	}

	/**
	 * Reading minutes for a post.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 * @return int|null Minutes, or null when the post is unavailable or protected.
	 */
	public static function minutes( \WP_Post|int $post ): ?int {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return null;
		}

		$wpm = (int) apply_filters( 'pkiw_reading_words_per_minute', 200, $post );
		if ( $wpm < 1 ) {
			$wpm = 200;
		}

		$key = implode( ':', [ (string) $post->ID, (string) $post->post_modified_gmt, (string) $wpm ] );
		if ( array_key_exists( $key, self::$minutes ) ) {
			return self::$minutes[ $key ];
		}

		$words                 = self::word_count( (string) $post->post_content );
		self::$minutes[ $key ] = max( 1, (int) ceil( $words / $wpm ) );

		return self::$minutes[ $key ];
	}

	/**
	 * Count words by the same broad rules as wp_trim_words().
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Post content.
	 * @return int Word or character count.
	 */
	public static function word_count( string $content ): int {
		$text = wp_strip_all_tags( strip_shortcodes( $content ) );
		$text = trim( preg_replace( '/[\n\r\t ]+/', ' ', $text ) ?? '' );
		if ( '' === $text ) {
			return 0;
		}

		$count_type = wp_get_word_count_type();
		if ( str_starts_with( $count_type, 'characters' ) ) {
			if ( 'characters_excluding_spaces' === $count_type ) {
				$text = preg_replace( '/[\n\r\t ]+/', '', $text ) ?? '';
			}
			return (int) preg_match_all( '/./u', $text );
		}

		$words = preg_split( "/[\n\r\t ]+/", $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Human-readable reading-time label.
	 *
	 * @since 1.9.0
	 *
	 * @param int $minutes Minutes.
	 * @return string Label.
	 */
	public static function label( int $minutes ): string {
		return sprintf(
			/* translators: %s: whole minutes to read the post. */
			_n( '%s min read', '%s min read', $minutes, 'post-kinds-for-indieweb-in-block-themes' ),
			number_format_i18n( $minutes )
		);
	}

	/**
	 * Render the reading-time block.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string Markup, or an empty string outside Post Template renders.
	 */
	public static function render( array $attributes, string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$is_preview = class_exists( Grouped_Archive::class ) && Grouped_Archive::is_block_preview();
		if ( self::$template_depth <= 0 && ! $is_preview ) {
			return '';
		}

		$post_id = (int) ( $block instanceof \WP_Block ? ( $block->context['postId'] ?? 0 ) : 0 );
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}

		$minutes = self::minutes( $post_id );
		if ( null === $minutes ) {
			return '';
		}

		return sprintf(
			'<p %1$s>%2$s</p>',
			get_block_wrapper_attributes( [ 'class' => 'pkiw-reading-time' ] ),
			esc_html( self::label( $minutes ) )
		);
	}
}
