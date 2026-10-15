<?php
/**
 * Article archive contents layout helpers.
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
 * Article archive template filters (issue 263).
 *
 * @since 1.9.0
 */
final class Article_Archive {

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( 'article', [ self::class, 'facts' ] );

		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		add_filter( 'render_block_core/post-title', [ self::class, 'title' ], 10, 3 );
		add_filter( 'render_block_core/post-date', [ self::class, 'date' ], 10, 3 );
		add_filter( 'render_block_core/post-featured-image', [ self::class, 'picture' ], 10, 3 );
		add_filter( 'render_block_core/post-template', [ self::class, 'contents_wrapper' ], 10, 3 );
		add_filter( 'render_block_post-kinds-indieweb/stream-card', [ self::class, 'stream_card_style' ], 10, 3 );

		if ( did_action( 'init' ) ) {
			self::register_styles();
		} else {
			add_action( 'init', [ self::class, 'register_styles' ] );
		}
		add_action( 'enqueue_block_assets', [ self::class, 'editor_style' ] );
	}

	/**
	 * Facts for an article post.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, int|null>
	 */
	public static function facts( int $post_id ): array {
		return [ 'reading_minutes' => Reading_Time::minutes( $post_id ) ];
	}

	/**
	 * Add microformats classes to the article contents title.
	 *
	 * @since 1.9.0
	 *
	 * @param string    $html     Rendered block HTML.
	 * @param array     $block    Parsed block.
	 * @param \WP_Block $instance Block instance.
	 * @return string
	 */
	public static function title( string $html, array $block, \WP_Block $instance ): string {
		if ( ! self::block_has_class( $block, 'pkiw-contents__title' ) ) {
			return $html;
		}

		$post = self::block_post( $instance );
		if ( ! $post instanceof \WP_Post ) {
			return $html;
		}

		$has_stored_title = '' !== trim( (string) $post->post_title );
		if ( ! $has_stored_title ) {
			$html = self::fallback_title( $block, $post );
		} else {
			$tags = new \WP_HTML_Tag_Processor( $html );
			if ( $tags->next_tag( [ 'tag_closers' => 'skip' ] ) ) {
				$tags->add_class( 'p-name' );
			}
			if ( $tags->next_tag( [ 'tag_name' => 'A' ] ) ) {
				$tags->add_class( 'u-url' );
			}
			$html = $tags->get_updated_html();
		}

		if ( function_exists( __NAMESPACE__ . '\\entry_author_html' ) ) {
			$html .= '<span class="pk-entry-props" hidden>' . entry_author_html( $post ) . '</span>';
		}

		return $html;
	}

	/**
	 * Add dt-published to the article contents date.
	 *
	 * @since 1.9.0
	 *
	 * @param string    $html     Rendered block HTML.
	 * @param array     $block    Parsed block.
	 * @param \WP_Block $instance Block instance.
	 * @return string
	 */
	public static function date( string $html, array $block, \WP_Block $instance ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! self::block_has_class( $block, 'pkiw-contents__date' ) ) {
			return $html;
		}

		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( $tags->next_tag( [ 'tag_name' => 'TIME' ] ) ) {
			$tags->add_class( 'dt-published' );
		}

		return $tags->get_updated_html();
	}

	/**
	 * Prepare the article contents picture.
	 *
	 * @since 1.9.0
	 *
	 * @param string    $html     Rendered block HTML.
	 * @param array     $block    Parsed block.
	 * @param \WP_Block $instance Block instance.
	 * @return string
	 */
	public static function picture( string $html, array $block, \WP_Block $instance ): string {
		if ( ! self::block_has_class( $block, 'pkiw-contents__picture' ) ) {
			return $html;
		}

		$post = self::block_post( $instance );
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return '';
		}

		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( [ 'tag_name' => 'IMG' ] ) ) {
			return $html;
		}

		$tags->set_attribute( 'alt', '' );
		$tags->set_attribute( 'decoding', 'async' );
		if ( self::is_lead_post( $post ) ) {
			$tags->set_attribute( 'loading', 'eager' );
			$tags->set_attribute( 'fetchpriority', 'high' );
		} else {
			$tags->set_attribute( 'loading', 'lazy' );
			$tags->remove_attribute( 'fetchpriority' );
		}

		return $tags->get_updated_html();
	}

	/**
	 * Mark and enqueue the contents Post Template.
	 *
	 * @since 1.9.0
	 *
	 * @param string    $html     Rendered block HTML.
	 * @param array     $block    Parsed block.
	 * @param \WP_Block $instance Block instance.
	 * @return string
	 */
	public static function contents_wrapper( string $html, array $block, \WP_Block $instance ): string {
		if ( ! self::block_has_class( $block, 'is-style-pkiw-contents' ) ) {
			return $html;
		}

		wp_enqueue_style( 'pkiw-article-contents' );

		if ( empty( $instance->context['query']['inherit'] ) || is_paged() ) {
			return $html;
		}

		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( in_array( $tags->get_tag(), [ 'DIV', 'UL', 'OL' ], true ) ) {
				$tags->add_class( 'pkiw-contents--first-page' );
				return $tags->get_updated_html();
			}
		}

		return $html;
	}

	/**
	 * Enqueue the article stream card stylesheet when an article card appears.
	 *
	 * @since 1.9.0
	 *
	 * @param string $html     Rendered block HTML.
	 * @param array  $block    Parsed block.
	 * @param mixed  $instance Block instance.
	 * @return string
	 */
	public static function stream_card_style( string $html, array $block, $instance = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( preg_match( '/<article\b[^>]*class="[^"]*\bpk-card--stream\b[^"]*\bk-article\b[^"]*"/', $html ) ) {
			wp_enqueue_style( 'pkiw-article-stream-card' );
		}

		return $html;
	}

	/**
	 * Register article archive stylesheets.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register_styles(): void {
		$contents = \PKIW_PATH . 'styles/article-contents.css';
		if ( file_exists( $contents ) ) {
			wp_register_style( 'pkiw-article-contents', \PKIW_URL . 'styles/article-contents.css', [], (string) filemtime( $contents ) );
			wp_style_add_data( 'pkiw-article-contents', 'path', $contents );
		}

		$stream = \PKIW_PATH . 'styles/article-stream-card.css';
		if ( file_exists( $stream ) ) {
			wp_register_style( 'pkiw-article-stream-card', \PKIW_URL . 'styles/article-stream-card.css', [], (string) filemtime( $stream ) );
			wp_style_add_data( 'pkiw-article-stream-card', 'path', $stream );
		}
	}

	/**
	 * Show the contents layout in the editor canvas.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function editor_style(): void {
		if ( is_admin() ) {
			wp_enqueue_style( 'pkiw-article-contents' );
		}
	}

	/**
	 * Whether a parsed block carries a class.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param string               $wanted Class name.
	 * @return bool
	 */
	private static function block_has_class( array $block, string $wanted ): bool {
		$class_names = preg_split( '/\s+/', (string) ( $block['attrs']['className'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $class_names ) && in_array( $wanted, $class_names, true );
	}

	/**
	 * Current post for a block.
	 *
	 * @param \WP_Block $instance Block instance.
	 * @return \WP_Post|null
	 */
	private static function block_post( \WP_Block $instance ): ?\WP_Post {
		$post_id = (int) ( $instance->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Replacement title for an untitled article.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param \WP_Post             $post  Post.
	 * @return string Markup.
	 */
	private static function fallback_title( array $block, \WP_Post $post ): string {
		$level = max( 1, min( 6, (int) ( $block['attrs']['level'] ?? 2 ) ) );

		return sprintf(
			'<h%1$d class="wp-block-post-title pkiw-contents__title"><a class="u-url" href="%2$s">%3$s</a></h%1$d>',
			$level,
			esc_url( (string) get_permalink( $post ) ),
			esc_html( untitled_name( $post, false ) )
		);
	}

	/**
	 * Whether the post is the first post on page one.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function is_lead_post( \WP_Post $post ): bool {
		global $wp_query;

		if ( is_paged() || ! $wp_query instanceof \WP_Query || empty( $wp_query->posts ) ) {
			return false;
		}

		$first = $wp_query->posts[0];
		$id    = $first instanceof \WP_Post ? (int) $first->ID : (int) $first;

		return (int) $post->ID === $id;
	}
}
