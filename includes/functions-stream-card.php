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
 * How many thumbnails a Stream card shows before collapsing the rest into a
 * +N link. Four keeps every card the same height so the feed stays scannable.
 */
const PK_STREAM_THUMB_LIMIT = 4;

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
	$html = render_stream_card_inner( $attributes, $content, $block );

	// 1.8.1: when the card carries its own h-entry root (the pk-card article,
	// possibly inside an authored wrapper), register it so
	// Microformats::add_post_classes() leaves the Query Loop <li> without a
	// second root. Cards rooted as h-cite / h-food keep the <li> root.
	$post_id = ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();
	$post    = $post_id ? get_post( $post_id ) : null;
	$card_rooted = (bool) preg_match( '/<article\b[^>]*\bclass="[^"]*\bpk-card\b[^"]*\bh-entry\b/i', $html );
	if ( $post_id && $card_rooted ) {
		$GLOBALS['pkiw_stream_card_root_seen'][ $post_id ] = true;
	}

	return $html;
}

/**
 * The entry itself needs a permalink and a publication date that parsers can
 * attribute to it, not to a nested h-cite / h-food object. Runs late on the
 * block's render filter so theme adapters that rebuild the card have finished.
 *
 * @param string    $html     Rendered card (after adapters).
 * @param array     $block    Parsed block.
 * @param \WP_Block $instance Block instance.
 * @return string
 */
function ensure_entry_properties_filter( string $html, array $block, $instance ): string {
	$post_id = ( $instance instanceof \WP_Block && ! empty( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : (int) get_the_ID();
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post instanceof \WP_Post || '' === $html ) {
		return $html;
	}
	$card_rooted = (bool) preg_match( '/<article\b[^>]*\bclass="[^"]*\bpk-card\b[^"]*\bh-entry\b/i', $html );
	return ensure_entry_properties( $html, $post, $card_rooted );
}
add_filter( 'render_block_post-kinds-indieweb/stream-card', __NAMESPACE__ . '\\ensure_entry_properties_filter', 99, 3 );

/**
 * Hidden `p-author h-card` for an entry (empty when no author identity).
 *
 * @since 1.8.1
 *
 * @param \WP_Post $post The post.
 * @return string
 */
function entry_author_html( \WP_Post $post ): string {
	/**
	 * Filters the author identity emitted as the entry's hidden `p-author h-card`.
	 *
	 * @since 1.8.1
	 *
	 * @param array{name: string, url: string, photo: string} $author Name, URL, photo URL.
	 * @param \WP_Post                                          $post   The post.
	 */
	$author = apply_filters(
		'pkiw_entry_author',
		[
			'name'  => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'url'   => (string) get_author_posts_url( (int) $post->post_author ),
			'photo' => (string) get_avatar_url( (int) $post->post_author, [ 'size' => 96 ] ),
		],
		$post
	);
	if ( ! is_array( $author ) || empty( $author['name'] ) || empty( $author['url'] ) ) {
		return '';
	}
	$html = '<span class="p-author h-card"><a class="u-url p-name" href="' . esc_url( (string) $author['url'] ) . '" tabindex="-1">' . esc_html( (string) $author['name'] ) . '</a>';
	if ( ! empty( $author['photo'] ) ) {
		$html .= '<img class="u-photo" src="' . esc_url( (string) $author['photo'] ) . '" alt="" loading="lazy" />';
	}
	return $html . '</span>';
}

/**
 * Whether the entry itself (not a nested microformats object) carries a property.
 *
 * With a rooted card the entry is the `pk-card` article; otherwise the entry is
 * the surrounding list item and everything inside the article is nested.
 *
 * @since 1.8.1
 *
 * @param string $html        Rendered card.
 * @param bool   $card_rooted Whether the pk-card article is the h-entry root.
 * @param string $class       Property class, e.g. `u-url`.
 * @param string $href        Optional href the property must point at.
 * @return bool
 */
function entry_has_own_property( string $html, bool $card_rooted, string $class, string $href = '' ): bool {
	if ( false === strpos( $html, $class ) ) {
		return false;
	}
	$doc      = new \DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="pkiw-entry-scope">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$xpath = new \DOMXPath( $doc );
	$has   = static function ( \DOMElement $el, string $pattern ): bool {
		return (bool) preg_match( $pattern, ' ' . $el->getAttribute( 'class' ) . ' ' );
	};
	foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]' ) as $node ) {
		if ( ! $node instanceof \DOMElement ) {
			continue;
		}
		if ( '' !== $href && untrailingslashit( $node->getAttribute( 'href' ) ) !== untrailingslashit( $href ) ) {
			continue;
		}
		// Nearest microformats root above the property.
		$owner = null;
		for ( $p = $node->parentNode; $p instanceof \DOMElement; $p = $p->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
			if ( 'pkiw-entry-scope' === $p->getAttribute( 'id' ) ) {
				break;
			}
			if ( $has( $p, '/\sh-[a-z]+(-[a-z]+)*\s/' ) ) {
				$owner = $p;
				break;
			}
		}
		$owner_is_card = $owner instanceof \DOMElement && 'article' === strtolower( $owner->tagName ) && $has( $owner, '/\spk-card\s/' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
		if ( $card_rooted ? $owner_is_card : null === $owner ) {
			return true;
		}
	}
	return false;
}

/**
 * Append hidden `u-url` / `dt-published` for the entry when the card does not
 * expose them outside a nested object.
 *
 * @param string   $html        Rendered card.
 * @param \WP_Post $post        The post.
 * @param bool     $card_rooted Whether the pk-card article is the h-entry root.
 * @return string
 */
function ensure_entry_properties( string $html, \WP_Post $post, bool $card_rooted ): string {
	// Only properties whose nearest microformats root is the entry count: a
	// nested object (h-cite, h-event, h-card, h-food) owns its own u-url,
	// dt-published and p-author even when they point at this post.
	$permalink    = (string) get_permalink( $post );
	$needs_url    = ! entry_has_own_property( $html, $card_rooted, 'u-url', $permalink );
	$needs_date   = ! entry_has_own_property( $html, $card_rooted, 'dt-published' );
	$needs_author = ! entry_has_own_property( $html, $card_rooted, 'p-author' );
	if ( ! $needs_url && ! $needs_date && ! $needs_author ) {
		return $html;
	}
	$extra = '';
	if ( $needs_author ) {
		$extra .= entry_author_html( $post );
	}
	if ( $needs_url ) {
		$extra .= '<a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '" tabindex="-1" aria-hidden="true"></a>';
	}
	if ( $needs_date ) {
		$extra .= '<time class="dt-published" datetime="' . esc_attr( (string) get_post_time( 'c', true, $post ) ) . '" aria-hidden="true"></time>';
	}
	$extra = '<span class="pk-entry-props" hidden>' . $extra . '</span>';
	if ( $card_rooted ) {
		// Inside the rooted article, before its closing tag.
		$pos = strrpos( $html, '</article>' );
		return false === $pos ? $html . $extra : substr( $html, 0, $pos ) . $extra . substr( $html, $pos );
	}
	return $html . $extra;
}

/**
 * The card body; see render_stream_card() for the root bookkeeping.
 *
 * @param array<string,mixed> $attributes Block attributes.
 * @param string              $content    Block content.
 * @param \WP_Block|null      $block      Parsed block instance.
 * @return string
 */
function render_stream_card_inner( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string {
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
 * the full body). This is the catch-all for every kind, known or future:
 * a kind slug with no dedicated card block still renders here. Title-less
 * posts (most experimental kinds — a weather report, a follow, a quote —
 * are title-less by IndieWeb convention) show the kind label as the linked
 * title instead of vanishing from the feed.
 *
 * @param \WP_Post $post Post to render.
 * @return string Card HTML.
 */
function render_generic_stream_card( \WP_Post $post ): string {
	$permalink  = esc_url( (string) get_permalink( $post ) );
	$kind_slug  = get_post_kind_slug( $post );
	$badge_kind = '' !== $kind_slug ? $kind_slug : 'note';
	$kind_label = stream_card_kind_label( $post );
	$excerpt    = trim( wp_strip_all_tags( get_the_excerpt( $post ) ) );

	$title     = trim( get_the_title( $post ) );
	$has_title = '' !== $title;
	if ( ! $has_title ) {
		$title = $kind_label;
	}
	// A synthetic title is navigation, not the entry's name — no p-name, so
	// mf2 parsers fall back to the implied name / content as intended.
	$title_class = $has_title ? 'pk-title p-name' : 'pk-title';

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
	$out .= '<p class="pk-kindlabel">' . esc_html( get_kind_label( $kind_label, $badge_kind, 'stream-card' ) ) . '</p>';

	// A long-form mood post falls through to this card, which would otherwise
	// drop the mood-card block's emoji — carry it over as the mood pin.
	if ( 'mood' === $kind_slug ) {
		$emoji = extract_mood_card_emoji( (string) $post->post_content );
		if ( '' !== $emoji ) {
			// The emoji IS the mood — expose it so assistive tech announces it.
			// role="img" takes its name from aria-label, never from its text.
			$out .= '<span class="pk-mood__emoji" role="img" aria-label="' . esc_attr( mood_card_accessible_name( (string) $post->post_content ) ) . '">' . esc_html( $emoji ) . '</span>';
		}
	}

	$out .= '<div class="pk-caption">';
	$out .= '<h2 class="' . esc_attr( $title_class ) . '"><a class="u-url" href="' . $permalink . '">' . esc_html( $title ) . '</a></h2>';

	$date_display = get_the_date( '', $post );
	if ( '' !== $date_display ) {
		$out .= '<p class="pk-sub pk-stream-date"><time class="dt-published" datetime="'
			. esc_attr( (string) get_post_time( 'c', true, $post ) ) . '">'
			. esc_html( $date_display ) . '</time></p>';
	}

	$out .= '</div>';

	if ( '' !== $thumb_html ) {
		// get_the_post_thumbnail() returns core-generated, escaped <img> markup.
		$out .= '<div class="pk-media pk-media--stream">' . $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$out .= stream_card_media_extras( $post );
		$out .= '</div>';
	}

	if ( '' !== $excerpt ) {
		$out .= '<p class="pk-excerpt p-summary">' . esc_html( $excerpt ) . '</p>';
	}

	$out .= '<div class="pk-meta"><a class="pk-link" href="' . $permalink . '">'
		. esc_html__( 'Read more', 'post-kinds-for-indieweb-in-block-themes' )
		. '<span class="pk-sr-only">' . esc_html( ': ' . $title ) . '</span></a></div>';
	$out .= '</div></article>';

	return $out;
}

/**
 * The caption under a Stream card's hero image, plus a thumbnail row when the
 * post carries more than one image.
 *
 * A caption lives on the attachment, which is where WordPress keeps one and
 * where Outpost's Micropub bridge writes it. The card renders the hero's
 * caption and, past a single image, a row of thumbnails so a six-photo post
 * stops looking identical to a one-photo post in the feed.
 *
 * Everything here is server-rendered and correct with no JavaScript: that is
 * what a feed reader or an unfurler parsing the h-entry receives. Swapping the
 * caption when a thumbnail is chosen is enhancement layered on top, never the
 * reason a caption exists.
 *
 * @param \WP_Post $post Post being rendered.
 * @return string
 */
function stream_card_media_extras( \WP_Post $post ): string {
	$hero_id = (int) get_post_thumbnail_id( $post );
	$images  = get_attached_media( 'image', $post );

	$ids = array_filter( array_merge( [ $hero_id ], wp_list_pluck( $images, 'ID' ) ) );
	if ( $ids ) {
		_prime_post_caches( array_map( 'intval', $ids ), false, true );
	}

	$hero_caption = $hero_id > 0
		? trim( (string) get_post_field( 'post_excerpt', $hero_id ) )
		: '';

	$out = '';
	if ( '' !== $hero_caption ) {
		// Polite, not assertive: a caption changing must never interrupt.
		$out .= '<p class="pk-media__caption" aria-live="polite">'
			. wp_kses(
				$hero_caption,
				[
					'a'      => [
						'href'  => [],
						'rel'   => [],
						'title' => [],
					],
					'em'     => [],
					'strong' => [],
					'br'     => [],
				]
			) . '</p>';
	}

	// Everything except the hero, in attachment order.
	$rest = [];
	foreach ( $images as $image ) {
		if ( (int) $image->ID !== $hero_id ) {
			$rest[] = $image;
		}
	}
	if ( empty( $rest ) ) {
		return $out;
	}

	$visible   = array_slice( $rest, 0, PK_STREAM_THUMB_LIMIT );
	$remaining = count( $rest ) - count( $visible );

	$out  .= '<ul class="pk-media__thumbs">';
	$total = count( $visible );
	$n     = 0;
	foreach ( $visible as $image ) {
		++$n;
		$id      = (int) $image->ID;
		$alt     = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$caption = trim( (string) get_post_field( 'post_excerpt', $id ) );
		$src     = (string) wp_get_attachment_image_url( $id, 'medium' );
		$thumb   = (string) wp_get_attachment_image_url( $id, 'thumbnail' );

		// A button, not a bare image: reachable by Tab and activated by Enter
		// or Space, so this works on a phone and by keyboard rather than only
		// on hover. Its accessible name is the image's own alt text; an image
		// without alt text still needs a name (axe button-name), so the button
		// then says which image it shows.
		$name = '' !== $alt ? $alt : sprintf(
			/* translators: 1: image position, 2: number of thumbnails. */
			__( 'Show image %1$d of %2$d', 'post-kinds-for-indieweb-in-block-themes' ),
			$n,
			$total
		);
		$out .= '<li class="pk-media__thumb">'
			. '<button type="button" class="pk-media__thumb-button"'
			. ' aria-label="' . esc_attr( $name ) . '"'
			. ' data-pk-full="' . esc_url( $src ) . '"'
			. ' data-pk-alt="' . esc_attr( $alt ) . '"'
			. ' data-pk-caption="' . esc_attr( $caption ) . '">'
			. '<img src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />'
			. '</button></li>';
	}
	if ( $remaining > 0 ) {
		$out .= '<li class="pk-media__thumb pk-media__thumb--more">'
			. '<a class="pk-media__thumb-more-link" href="' . esc_url( (string) get_permalink( $post ) ) . '">'
			/* translators: %d: number of further images on the post. */
			. esc_html( sprintf( __( '+%d', 'post-kinds-for-indieweb-in-block-themes' ), $remaining ) )
			. '<span class="pk-sr-only">'
			. esc_html__( ' more images on this post', 'post-kinds-for-indieweb-in-block-themes' )
			. '</span></a></li>';
	}
	$out .= '</ul>';

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

	return __( 'Note', 'post-kinds-for-indieweb-in-block-themes' );
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
			return $matches[1] . '<a class="u-url" href="' . $permalink . '">';
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
			return $matches[1] . $matches[2] . '<a class="u-url" href="' . $permalink . '">' . $matches[3] . '</a>' . $matches[4] . $matches[5];
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
 * The emoji attribute of the first mood-card block in a post body.
 *
 * Saved block comments carry raw attributes — parse_blocks() applies no
 * block.json defaults — so a mood-card saved without an explicit emoji has
 * no `emoji` key at all. Mirror the block renderer's own 😊 fallback in
 * that case; a body with no mood-card block yields '' so the card renders
 * without a pin rather than inventing one.
 *
 * @param string $content Post content.
 * @return string The emoji, or '' when the body has no mood-card block.
 */
function extract_mood_card_emoji( string $content ): string {
	if ( ! str_contains( $content, 'post-kinds-indieweb/mood-card' ) ) {
		return '';
	}

	foreach ( flatten_blocks( parse_blocks( $content ) ) as $block ) {
		if ( 'post-kinds-indieweb/mood-card' !== ( $block['blockName'] ?? '' ) ) {
			continue;
		}

		$emoji = trim( (string) ( $block['attrs']['emoji'] ?? '' ) );
		return '' !== $emoji ? $emoji : '😊';
	}

	return '';
}

/**
 * Accessible name for a mood emoji: the first mood-card block's mood label.
 *
 * A label picked from the mood vocabulary follows the mood spelling
 * setting (Mood_Vocabulary::display_label()); typed labels stay as saved.
 *
 * @since 1.8.1
 * @since 1.9.0 Resolves vocabulary labels through Mood_Vocabulary.
 *
 * @param string $content Post content.
 * @return string The mood label, or "Mood" when the block has none.
 */
function mood_card_accessible_name( string $content ): string {
	foreach ( flatten_blocks( parse_blocks( $content ) ) as $block ) {
		if ( 'post-kinds-indieweb/mood-card' !== ( $block['blockName'] ?? '' ) ) {
			continue;
		}
		$label = trim(
			wp_strip_all_tags(
				Mood_Vocabulary::display_label( (string) ( $block['attrs']['mood'] ?? '' ), (string) ( $block['attrs']['moodKey'] ?? '' ) )
			)
		);
		if ( '' !== $label ) {
			return $label;
		}
		break;
	}
	return __( 'Mood', 'post-kinds-for-indieweb-in-block-themes' );
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
	// Editor representation: a ServerSideRender edit component (plain script,
	// no build) so the canvas shows the real card for each loop post instead
	// of the "doesn't include support" fallback. See assets/js/stream-card-editor.js.
	wp_register_script(
		'pkiw-stream-card-editor',
		\PKIW_URL . 'assets/js/stream-card-editor.js',
		[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-server-side-render' ],
		\PKIW_VERSION,
		true
	);
	wp_set_script_translations( 'pkiw-stream-card-editor', 'post-kinds-for-indieweb-in-block-themes' );

	register_block_type(
		'post-kinds-indieweb/stream-card',
		[
			// No block.json for this block, so declare the API version here —
			// register_block_type() otherwise defaults it to 1.
			'api_version'     => 3,
			'render_callback' => __NAMESPACE__ . '\\render_stream_card',
			'uses_context'    => [ 'postId', 'postType' ],
			'supports'        => [
				'inserter' => true,
				'html'     => false,
				'reusable' => false,
			],
			'ancestor'        => [ 'core/post-template' ],
			'editor_script'   => 'pkiw-stream-card-editor',
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_stream_card_block' );
