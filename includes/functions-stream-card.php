<?php
/**
 * Stream card renderer.
 *
 * The Stream surfaces two very different shapes of post under one Query
 * Loop: short Post Kinds "micro-posts" whose whole body is a single card
 * block (a movie watched, a track listened to), and full-length articles
 * that happen to carry a kind (a long write-up about a video). Rendering
 * `core/post-content` for both dumps the entire article for the second
 * shape.
 *
 * `[pk_stream_card]` replaces `core/post-content` in the Stream's Post
 * Template and renders a compact, glanceable card per post:
 *
 *   - Micro-post (body is only Post Kinds card blocks) → render as-is.
 *   - Long-form watch post → a watch card showing just badge, title, and
 *     the video pulled from the body — none of the article text.
 *   - Anything else → a plain title link, so a long article never dumps
 *     its full body into the feed.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Stream card for the current post in the loop.
 *
 * @return string Card HTML.
 */
function render_stream_card(): string {
	$post = get_post();

	if ( ! $post instanceof \WP_Post ) {
		return '';
	}

	// Micro-post: the body is nothing but Post Kinds card block(s). Render
	// it exactly as it renders today — this is the Enola-Holmes shape.
	if ( content_is_kind_card_only( (string) $post->post_content ) ) {
		return do_blocks( $post->post_content );
	}

	// Long-form watch post: show a watch card with the video from the body,
	// nothing else. A synthetic block reuses the card structure, the Able
	// Player embed path, and the on-demand style enqueue.
	if ( 'watch' === get_post_kind_slug( $post ) ) {
		$attrs = [ 'mediaTitle' => get_the_title( $post ) ];

		$video_url = extract_first_video_url( (string) $post->post_content );
		if ( '' !== $video_url ) {
			$attrs['watchUrl'] = $video_url;
		}

		return render_block(
			[
				'blockName'    => 'post-kinds-indieweb/watch-card',
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	// Any other long-form post: a plain title link, never the full body.
	return sprintf(
		'<p class="pk-stream-fallback"><a href="%s">%s</a></p>',
		esc_url( (string) get_permalink( $post ) ),
		esc_html( get_the_title( $post ) )
	);
}

/**
 * Whether a post body is made up solely of Post Kinds card blocks.
 *
 * Empty freeform gaps between blocks are ignored; any real paragraph,
 * heading, or non-card block makes it long-form.
 *
 * @param string $content Post content.
 * @return bool True when the only substantive blocks are Post Kinds cards.
 */
function content_is_kind_card_only( string $content ): bool {
	if ( '' === trim( $content ) ) {
		return false;
	}

	$has_card = false;

	foreach ( parse_blocks( $content ) as $block ) {
		$name = $block['blockName'] ?? null;

		if ( null === $name ) {
			// Freeform chunk — a bare newline is fine, real HTML is not.
			if ( '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
			return false;
		}

		if ( str_starts_with( $name, 'post-kinds-indieweb/' ) ) {
			$has_card = true;
			continue;
		}

		return false;
	}

	return $has_card;
}

/**
 * The post's primary kind slug from the `kind` taxonomy.
 *
 * @param \WP_Post $post Post object.
 * @return string Kind slug, or '' when none is assigned.
 */
function get_post_kind_slug( \WP_Post $post ): string {
	$terms = get_the_terms( $post, 'kind' );

	if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
		return $terms[0]->slug;
	}

	return '';
}

/**
 * Pull the first playable video URL out of a post body.
 *
 * Checks, in order: an `[ableplayer]` shortcode's `youtube-id`, the first
 * YouTube `core/embed` block, then the first bare YouTube URL.
 *
 * @param string $content Post content.
 * @return string A YouTube URL, or '' when none is found.
 */
function extract_first_video_url( string $content ): string {
	if ( preg_match( '/\[ableplayer\b[^\]]*\byoutube-id=(["\'])([A-Za-z0-9_-]{6,})\1/', $content, $matches ) ) {
		return 'https://www.youtube.com/watch?v=' . $matches[2];
	}

	foreach ( flatten_blocks( parse_blocks( $content ) ) as $block ) {
		if ( 'core/embed' !== ( $block['blockName'] ?? '' ) ) {
			continue;
		}

		$url = (string) ( $block['attrs']['url'] ?? '' );
		if ( '' !== $url && preg_match( '#youtu\.?be#i', $url ) ) {
			return $url;
		}
	}

	if ( preg_match( '#https?://(?:www\.)?(?:youtube\.com/watch\?[^\s"<]+|youtu\.be/[A-Za-z0-9_-]{6,})#i', $content, $matches ) ) {
		return $matches[0];
	}

	return '';
}

/**
 * Flatten a nested block tree into a single depth-first list.
 *
 * @param array $blocks Parsed blocks.
 * @return array Flat list of blocks.
 */
function flatten_blocks( array $blocks ): array {
	$flat = [];

	foreach ( $blocks as $block ) {
		$flat[] = $block;

		if ( ! empty( $block['innerBlocks'] ) ) {
			$flat = array_merge( $flat, flatten_blocks( $block['innerBlocks'] ) );
		}
	}

	return $flat;
}

add_shortcode( 'pk_stream_card', __NAMESPACE__ . '\\render_stream_card' );
