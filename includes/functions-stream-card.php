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
 * The `post-kinds-indieweb/stream-card` block replaces `core/post-content`
 * in the Stream's Post Template and renders a compact, glanceable card per
 * post:
 *
 *   - Micro-post (body is only Post Kinds card blocks) → render as-is.
 *   - Long-form watch post → a watch card showing just badge, title, and
 *     the video pulled from the body — none of the article text.
 *   - Anything else (article, note, any kind with a full body) → a compact
 *     card with badge, title, date, featured image, and excerpt — never the
 *     full body. Every Stream item reads as a card.
 *
 * @package PKIW
 */

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Stream card for the current post in the loop.
 *
 * Runs as a dynamic block render_callback so it executes inside the Query
 * Loop with the correct post in scope. It reads the loop post from the
 * block's `postId` context, falling back to the global post.
 *
 * @param array          $attributes Block attributes (unused).
 * @param string         $content    Inner content (unused).
 * @param \WP_Block|null $block     Block instance, carries loop context.
 * @return string Card HTML.
 */
function render_stream_card( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string {
	$post_id = ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) )
		? (int) $block->context['postId']
		: 0;

	$post = $post_id ? get_post( $post_id ) : get_post();

	if ( ! $post instanceof \WP_Post ) {
		return '';
	}

	// Micro-post: the body is nothing but Post Kinds card block(s). Render
	// it exactly as it renders today — this is the Enola-Holmes shape.
	if ( content_is_kind_card_only( (string) $post->post_content ) ) {
		return inject_post_date_into_card( link_title_to_post( do_blocks( $post->post_content ), $post ), $post );
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

		return inject_post_date_into_card(
			link_title_to_post(
				render_block(
					[
						'blockName'    => 'post-kinds-indieweb/watch-card',
						'attrs'        => $attrs,
						'innerBlocks'  => [],
						'innerHTML'    => '',
						'innerContent' => [],
					]
				),
				$post
			),
			$post
		);
	}

	// Any other long-form post (an article, a note, a kind with a full body):
	// a compact card with the title, date, featured image, and excerpt —
	// never the full body. Every Stream item reads as a card.
	return render_generic_stream_card( $post );
}

/**
 * Render a compact card for a post that isn't a self-contained kind card.
 *
 * Articles, notes, and any long-form kind get a glanceable card — badge,
 * kind label, linked title, date, featured image, and the excerpt (never
 * the full body). Returns '' for a title-less post so the feed shows no
 * empty card.
 *
 * @param \WP_Post $post Post to render.
 * @return string Card HTML, or '' when the post has no title.
 */
function render_generic_stream_card( \WP_Post $post ): string {
	$title = get_the_title( $post );
	if ( '' === trim( $title ) ) {
		return '';
	}

	$permalink  = esc_url( (string) get_permalink( $post ) );
	$kind_slug  = get_post_kind_slug( $post );
	$badge_kind = '' !== $kind_slug ? $kind_slug : 'note';
	$kind_label = stream_card_kind_label( $post );
	$excerpt    = trim( wp_strip_all_tags( get_the_excerpt( $post ) ) );

	$thumb_html = has_post_thumbnail( $post )
		? get_the_post_thumbnail(
			$post,
			'medium',
			[
				'class'   => 'u-photo',
				'loading' => 'lazy',
			]
		)
		: '';

	$out = '<article class="pk-card pk-card--stream k-' . esc_attr( $badge_kind ) . ' h-entry">';
	// Badge SVG is a static, decorative glyph from get_kind_icon_svg().
	$out .= '<div class="pk-badge">' . get_kind_icon_svg( $badge_kind ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$out .= '<div class="pk-body">';
	$out .= '<p class="pk-kindlabel">' . esc_html( $kind_label ) . '</p>';
	$out .= '<h2 class="pk-title p-name"><a href="' . $permalink . '">' . esc_html( $title ) . '</a></h2>';

	$date_display = get_the_date( '', $post );
	if ( '' !== $date_display ) {
		$out .= '<p class="pk-sub pk-stream-date"><time class="dt-published" datetime="'
			. esc_attr( (string) get_post_time( 'c', true, $post ) ) . '">'
			. esc_html( $date_display ) . '</time></p>';
	}

	if ( '' !== $thumb_html ) {
		// get_the_post_thumbnail() returns core-generated, escaped <img> markup.
		$out .= '<div class="pk-media pk-media--stream">' . $thumb_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	if ( '' !== $excerpt ) {
		$out .= '<p class="pk-excerpt p-summary">' . esc_html( $excerpt ) . '</p>';
	}

	$out .= '<div class="pk-meta"><a class="pk-link" href="' . $permalink . '">'
		. esc_html__( 'Read more', 'post-kinds-for-indieweb' ) . '</a></div>';
	$out .= '</div></article>';

	return $out;
}

/**
 * The display label for a post's kind, or a neutral default when it has none.
 *
 * @param \WP_Post $post Post object.
 * @return string Kind term name (e.g. "Article", "Note"), or "Note" when unkinded.
 */
function stream_card_kind_label( \WP_Post $post ): string {
	$terms = get_the_terms( $post, 'kind' );

	if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
		return $terms[0]->name;
	}

	return __( 'Note', 'post-kinds-for-indieweb' );
}

/**
 * Layout wrapper blocks that may hold a card without making a post
 * long-form. Micro-posts often wrap their card in a group.
 */
const STREAM_CARD_WRAPPERS = [ 'core/group', 'core/columns', 'core/column' ];

/**
 * Whether a post body is made up solely of Post Kinds card blocks.
 *
 * The card may sit inside layout wrappers (a group, columns) — the whole
 * tree is flattened first. Empty freeform gaps are ignored; any real
 * paragraph, heading, or other non-card block makes it long-form.
 *
 * @param string $content Post content.
 * @return bool True when the only substantive blocks are Post Kinds cards.
 */
function content_is_kind_card_only( string $content ): bool {
	if ( '' === trim( $content ) ) {
		return false;
	}

	$has_card = false;

	foreach ( flatten_blocks( parse_blocks( $content ) ) as $block ) {
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

		// Layout wrappers are fine; their children are flattened alongside.
		if ( in_array( $name, STREAM_CARD_WRAPPERS, true ) ) {
			continue;
		}

		// Empty paragraph / spacer blocks are editor cruft — a trailing empty
		// paragraph shouldn't stop a single-card post from reading as one.
		if (
			in_array( $name, [ 'core/paragraph', 'core/spacer' ], true )
			&& '' === trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) )
		) {
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
 * Point a rendered card's title at the post permalink.
 *
 * On the Stream a card title should click through to the post, not to the
 * watched/listened URL. Repoints the `.pk-title` anchor at the post (same
 * tab), or wraps a plain-text title in one. The card's hidden `u-*-of` data
 * still carries the external URL for microformats.
 *
 * @param string   $html Rendered card HTML.
 * @param \WP_Post $post Post the card represents.
 * @return string HTML with the title linked to the post.
 */
function link_title_to_post( string $html, \WP_Post $post ): string {
	$permalink = esc_url( (string) get_permalink( $post ) );
	if ( '' === $permalink ) {
		return $html;
	}

	// Title already linked → repoint the anchor at the post.
	$relinked = preg_replace_callback(
		'#(<h[1-6] class="pk-title[^"]*">\s*)<a\b[^>]*>#s',
		static function ( $matches ) use ( $permalink ) {
			return $matches[1] . '<a href="' . $permalink . '">';
		},
		$html,
		1,
		$count
	);
	if ( null !== $relinked && $count > 0 ) {
		return $relinked;
	}

	// Plain-text title → wrap it in a link to the post.
	$wrapped = preg_replace_callback(
		'#(<h[1-6] class="pk-title[^"]*">)(\s*)([^<]+?)(\s*)(</h[1-6]>)#s',
		static function ( $matches ) use ( $permalink ) {
			return $matches[1] . $matches[2] . '<a href="' . $permalink . '">' . $matches[3] . '</a>' . $matches[4] . $matches[5];
		},
		$html,
		1
	);
	return null !== $wrapped ? $wrapped : $html;
}

/**
 * Insert the post's published date inside the card, under the title.
 *
 * On the Stream the date reads inside each card rather than floating above
 * it, so the Post Template no longer renders its own post-date block.
 *
 * @param string   $html Rendered card HTML.
 * @param \WP_Post $post Post the card represents.
 * @return string HTML with a dt-published date under the title.
 */
function inject_post_date_into_card( string $html, \WP_Post $post ): string {
	$display = get_the_date( '', $post );
	if ( '' === $display ) {
		return $html;
	}

	$date_html = '<p class="pk-sub pk-stream-date"><time class="dt-published" datetime="'
		. esc_attr( (string) get_post_time( 'c', true, $post ) ) . '">'
		. esc_html( $display ) . '</time></p>';

	$out = preg_replace_callback(
		'#<h[1-6] class="pk-title[^"]*">.*?</h[1-6]>#s',
		static function ( $matches ) use ( $date_html ) {
			return $matches[0] . $date_html;
		},
		$html,
		1,
		$count
	);
	return ( null !== $out && $count > 0 ) ? $out : $html;
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

/**
 * Register the Stream card as a dynamic block.
 *
 * A block (not a shortcode) so the render_callback runs inside the Query
 * Loop with the loop post in `postId` context. A shortcode would expand
 * after the loop, against the page's global post, and every item would
 * collapse to the same fallback.
 */
function register_stream_card_block(): void {
	register_block_type(
		'post-kinds-indieweb/stream-card',
		[
			'render_callback' => __NAMESPACE__ . '\\render_stream_card',
			'uses_context'    => [ 'postId', 'postType' ],
			'supports'        => [ 'inserter' => false ],
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_stream_card_block' );
