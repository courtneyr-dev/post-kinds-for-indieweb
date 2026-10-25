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
 * Track nested Stream card renders.
 *
 * @param int $delta Amount to add to the current depth.
 * @return int Current render depth.
 */
function stream_card_render_depth( int $delta = 0 ): int {
	static $depth = 0;

	$depth = max( 0, $depth + $delta );

	return $depth;
}

/**
 * Whether execution is currently inside a Stream card render.
 *
 * @return bool True while a Stream card is rendering.
 */
function is_rendering_stream_card(): bool {
	return stream_card_render_depth() > 0;
}

/**
 * Render the Stream card for the current post in the loop.
 *
 * Runs as a dynamic block render_callback so it executes inside the Query
 * Loop with the correct post in scope. It reads the loop post from the
 * block's `postId` context, falling back to the global post.
 *
 * @param array          $attributes Block attributes. `headingLevel` (int, default 2,
 *                                   clamped 2–4) sets the card title's heading tag.
 * @param string         $content    Inner content (unused).
 * @param \WP_Block|null $block     Block instance, carries loop context.
 * @return string Card HTML.
 */
function render_stream_card( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string {
	stream_card_render_depth( 1 );
	try {
		$html = render_stream_card_inner( $attributes, $content, $block );
	} finally {
		stream_card_render_depth( -1 );
	}

	// 1.8.1: when the card carries its own h-entry root (the pk-card article,
	// possibly inside an authored wrapper), register it so
	// Microformats::add_post_classes() leaves the Query Loop <li> without a
	// second root. Cards rooted as h-cite / h-food keep the <li> root.
	$post_id     = ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();
	$post        = $post_id ? get_post( $post_id ) : null;
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

	// The card an adapter hands back decides the root, not the one the
	// renderer started with: an adapter that swaps the generic card for the
	// post's own h-cite card leaves no rooted article, and the Query Loop
	// <li> has to be the entry or read-of and friends attach to nothing.
	if ( $card_rooted ) {
		$GLOBALS['pkiw_stream_card_root_seen'][ $post->ID ] = true;
	} else {
		unset( $GLOBALS['pkiw_stream_card_root_seen'][ $post->ID ] );
	}

	return ensure_entry_properties( $html, $post, $card_rooted );
}
add_filter( 'render_block_post-kinds-indieweb/stream-card', __NAMESPACE__ . '\\ensure_entry_properties_filter', 99, 3 );

/**
 * Hidden `p-author h-card` for an entry (empty when no author identity).
 * Empty for a password-protected post (issue: protected reads hide the author).
 *
 * @since 1.8.1
 *
 * @param \WP_Post $post The post.
 * @return string
 */
function entry_author_html( \WP_Post $post ): string {
	if ( post_password_required( $post ) ) {
		return '';
	}

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
	// No <a>: a card's only link is its title, so the h-card carries its URL as <data>.
	$html = '<span class="p-author h-card"><span class="p-name">' . esc_html( (string) $author['name'] ) . '</span><data class="u-url" value="' . esc_url( (string) $author['url'] ) . '"></data>';
	if ( ! empty( $author['photo'] ) ) {
		// This markup only ever renders inside an already-`hidden` mf2
		// wrapper, so <data> (no rendered image, no meaningless empty alt)
		// carries the value without anything for assistive tech to trip on.
		$html .= '<data class="u-photo" value="' . esc_url( (string) $author['photo'] ) . '"></data>';
	}
	return $html . '</span>';
}

/**
 * Whether a media URL is under the uploads folder or on the site's own host.
 *
 * @since 1.9.0
 *
 * @param string $url Media URL.
 * @return bool
 */
function media_url_is_local( string $url ): bool {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return false;
	}

	$uploads = wp_get_upload_dir();
	$base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
	if ( '' !== $base ) {
		$base_host = wp_parse_url( $base, PHP_URL_HOST );
		$base_path = (string) wp_parse_url( $base, PHP_URL_PATH );
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $base_host ) && strtolower( $host ) === strtolower( $base_host ) && str_starts_with( $path, trailingslashit( $base_path ) ) ) {
			return true;
		}
	}

	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

	return is_string( $site_host ) && strtolower( $host ) === strtolower( $site_host );
}

/**
 * Hidden media property for the entry represented by a Stream card.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post Post represented by the card.
 * @return string Hidden u-video or u-audio data, or an empty string.
 */
function entry_media_property_html( \WP_Post $post ): string {
	if ( post_password_required( $post ) || ! Kind_Facts::can_show( $post ) ) {
		return '';
	}

	$kind = get_post_kind_slug( $post );
	if ( 'video' === $kind ) {
		$facts = Kind_Facts::get( $post->ID );
		$src   = is_string( $facts['src'] ?? null ) ? $facts['src'] : '';
		// Only a file the site holds becomes u-video; a remote source never does.
		$url = '' !== $src && ( ! empty( $facts['is_local'] ) || media_url_is_local( $src ) ) ? esc_url( $src ) : '';
		if ( '' === $url ) {
			return '';
		}

		return '<data class="u-video" value="' . $url . '"></data>';
	}

	if ( 'audio' !== $kind ) {
		return '';
	}

	$media = first_media_block( $post, 'audio' );
	if ( null === $media || ! in_array( $media['type'], [ 'attachment', 'url' ], true ) ) {
		return '';
	}
	$url = esc_url( $media['url'] );

	return '' === $url ? '' : '<data class="u-audio" value="' . $url . '"></data>';
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
	$media        = entry_media_property_html( $post );
	$kind         = get_post_kind_slug( $post );
	$media_class  = 'video' === $kind ? 'u-video' : ( 'audio' === $kind ? 'u-audio' : '' );
	$needs_url    = ! entry_has_own_property( $html, $card_rooted, 'u-url', $permalink );
	$needs_date   = ! entry_has_own_property( $html, $card_rooted, 'dt-published' );
	$needs_author = ! entry_has_own_property( $html, $card_rooted, 'p-author' ) && ! post_password_required( $post );
	$needs_media  = '' !== $media && '' !== $media_class && ! entry_has_own_property( $html, $card_rooted, $media_class );
	if ( ! $needs_url && ! $needs_date && ! $needs_author && ! $needs_media ) {
		return $html;
	}
	$extra = '';
	if ( $needs_author ) {
		$extra .= entry_author_html( $post );
	}
	if ( $needs_url ) {
		// The parent .pk-entry-props span is already `hidden`, so no
		// tabindex/aria-hidden is needed on an empty, non-interactive element.
		$extra .= '<data class="u-url" value="' . esc_url( (string) get_permalink( $post ) ) . '"></data>';
	}
	if ( $needs_date ) {
		$extra .= '<data class="dt-published" value="' . esc_attr( stream_card_published_datetime( $post ) ) . '"></data>';
	}
	if ( $needs_media ) {
		$extra .= $media;
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

	// Card blocks always render `<h2 class="pk-title …">`; clamp and apply
	// the Stream's own headingLevel so every card in one Stream — however
	// it got rendered — shares one heading level in the outline.
	$heading_level = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 2 ) ) );

	// A password-protected post shows its title link and nothing else. Its
	// card would otherwise print the review, rating, cover and links.
	if ( post_password_required( $post ) ) {
		return render_protected_stream_card( $post, $heading_level );
	}

	/**
	 * Filters a kind's Stream card before any built-in path renders it.
	 *
	 * A kind that draws its own card from its own class returns the markup
	 * here. A string replaces the card; null falls through to the built-in
	 * paths. A password-protected post never reaches this filter.
	 *
	 * @since 1.9.0
	 *
	 * @param string|null          $html          Card markup, null to fall through.
	 * @param \WP_Post             $post          Post.
	 * @param int                  $heading_level Clamped heading level (2-4).
	 * @param array<string, mixed> $attributes    Block attributes.
	 */
	$kind_card = apply_filters( 'pkiw_stream_card_kind_render', null, $post, $heading_level, $attributes );
	if ( is_string( $kind_card ) ) {
		return $kind_card;
	}

	if ( 'photo' === get_post_kind_slug( $post ) ) {
		return render_photo_stream_card( $post, $attributes );
	}

	if ( 'review' === get_post_kind_slug( $post ) ) {
		return render_generic_stream_card( $post, $attributes );
	}

	// A craft post always takes the generic card: its project sections add
	// headings, lists and step photos, so a post holding only the card would
	// otherwise print the card block's bare facts list with no title link.
	if ( 'craft' === get_post_kind_slug( $post ) ) {
		return render_generic_stream_card( $post, $attributes );
	}

	// Micro-post: the body is nothing but Post Kinds card block(s). Render
	// it exactly as it renders today — this is the Enola-Holmes shape.
	if ( content_is_kind_card_only( (string) $post->post_content ) ) {
		return apply_stream_heading_level(
			inject_post_date_into_card( link_title_to_post( do_blocks( $post->post_content ), $post ), $post ),
			$heading_level
		);
	}

	$own_card = stream_card_own_card( $post );
	if ( null !== $own_card ) {
		return apply_stream_heading_level(
			inject_post_date_into_card( link_title_to_post( render_block( $own_card ), $post ), $post ),
			$heading_level
		);
	}

	// Long-form watch post: show a watch card with the video from the body,
	// nothing else. A synthetic block reuses the card structure, the Able
	// Player embed path, and the on-demand style enqueue.
	if ( 'watch' === get_post_kind_slug( $post ) ) {
		$attrs = [ 'mediaTitle' => visitor_post_title( $post ) ];

		$video_url = extract_first_video_url( (string) $post->post_content );
		if ( '' !== $video_url ) {
			$attrs['watchUrl'] = $video_url;
		}

		return apply_stream_heading_level(
			inject_post_date_into_card(
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
			),
			$heading_level
		);
	}

	// Any other long-form post (an article, a note, a kind with a full body):
	// a compact card with the title, date, featured image, and excerpt —
	// never the full body. Every Stream item reads as a card.
	return render_generic_stream_card( $post, $attributes );
}

/**
 * Whether the stored title was generated from a location that is hidden.
 *
 * What a visitor reads in its place is a synthetic name, never the entry's
 * title, so it carries no p-name (X8). Judged on the object passed in, so a
 * title a preview rewrote in memory isn't read as hidden because the
 * database row differs.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post Post.
 * @return bool True when the safe title replaces the stored one.
 */
function post_title_is_hidden( \WP_Post $post ): bool {
	return Title_Privacy::hides_title( $post );
}

/**
 * A post's title as a visitor reads it, whichever screen asks.
 *
 * Title_Privacy hands back the stored title on an admin screen, so
 * get_the_title() would print a title generated from a hidden location
 * there. A hidden title is replaced by the safe one with its tags stripped.
 * A hidden title on a password-protected post gets core's
 * protected_title_format prefix on every screen, because the safe title
 * carries none; a hidden title on a private post gets no "Private:" prefix.
 * A visible title follows get_the_title(), which adds core's "Protected:" or
 * "Private:" prefix off admin screens only.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post Post.
 * @return string The title to print, which may be ''.
 */
function visitor_post_title( \WP_Post $post ): string {
	if ( ! post_title_is_hidden( $post ) ) {
		return get_the_title( $post );
	}

	$safe = Title_Privacy::safe_title( $post->ID );

	$name = trim( wp_strip_all_tags( $safe ) );
	if ( '' !== $name && '' !== (string) $post->post_password ) {
		/** This filter is documented in wp-includes/post-template.php */
		/* translators: %s: the post title. Core's string. */
		$format = (string) apply_filters( 'protected_title_format', __( 'Protected: %s', 'default' ), $post ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own string and hook.
		$name   = sprintf( $format, $name );
	}

	return $name;
}

/**
 * The Stream card for a password-protected post: its title, linked to the
 * post, and nothing from its body or its kind's facts.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post          Protected post.
 * @param int      $heading_level Clamped heading level (2–4).
 * @return string Card HTML.
 */
function render_protected_stream_card( \WP_Post $post, int $heading_level ): string {
	$kind_slug = get_post_kind_slug( $post );
	// get_the_title() prefixes "Protected: " even to an empty title, so the
	// stored title decides whether the post has a name of its own.
	$has_title = '' !== trim( $post->post_title );
	$is_real   = $has_title && ! post_title_is_hidden( $post );
	$title     = $has_title ? trim( visitor_post_title( $post ) ) : untitled_name( $post, false );

	return '<article class="pk-card pk-card--stream pk-card--protected k-' . esc_attr( '' !== $kind_slug ? $kind_slug : 'note' ) . ' h-entry">'
		. '<h' . $heading_level . ' class="' . ( $is_real ? 'pk-title p-name' : 'pk-title' ) . '">'
		. '<a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $title ) . '</a>'
		. '</h' . $heading_level . '></article>';
}

/**
 * Sanitise a Stream card caption.
 *
 * @param string $html Caption HTML.
 * @return string Sanitised caption HTML.
 */
function stream_card_caption_kses( string $html ): string {
	return wp_kses(
		$html,
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
	);
}

/**
 * Resolve an uploads URL to its attachment.
 *
 * @param string $src Image source URL.
 * @return int Attachment ID, or 0.
 */
function resolve_upload_src( string $src ): int {
	$src = trim( $src );
	if ( '' === $src || ! is_upload_url( $src ) ) {
		return 0;
	}

	$upload  = wp_get_upload_dir();
	$baseurl = (string) ( $upload['baseurl'] ?? '' );
	if ( '' === $baseurl ) {
		return 0;
	}

	$stripped = (string) preg_replace( '/[?#].*$/', '', $src );
	$base     = trailingslashit( set_url_scheme( $baseurl, 'https' ) );
	$haystack = set_url_scheme( $stripped, 'https' );
	if ( ! str_starts_with( $haystack, $base ) ) {
		return 0;
	}

	$relative = ltrim( substr( $haystack, strlen( $base ) ), '/' );
	if ( '' === $relative ) {
		return 0;
	}

	$without_size = (string) preg_replace( '/-\d+x\d+(?=\.[^\/.]+$)/', '', $relative );
	$candidates   = [];
	foreach ( [ $relative, $without_size ] as $candidate ) {
		if ( '' === $candidate || isset( $candidates[ $candidate ] ) ) {
			continue;
		}
		$candidates[ $candidate ] = true;
	}

	foreach ( [ $relative, $without_size ] as $candidate ) {
		if ( '' === $candidate ) {
			continue;
		}

		$scaled = preg_match( '/-scaled(\.[^\/.]+)$/', $candidate )
			? (string) preg_replace( '/-scaled(\.[^\/.]+)$/', '$1', $candidate )
			: (string) preg_replace( '/(\.[^\/.]+)$/', '-scaled$1', $candidate );
		if ( '' !== $scaled && ! isset( $candidates[ $scaled ] ) ) {
			$candidates[ $scaled ] = true;
		}
	}

	foreach ( array_keys( $candidates ) as $candidate ) {
		$id = attachment_url_to_postid( trailingslashit( $baseurl ) . $candidate );
		if ( $id > 0 ) {
			return (int) $id;
		}
	}

	return 0;
}

/**
 * Read the first img src from a fragment.
 *
 * @param string $html HTML fragment.
 * @return string Image source, or ''.
 */
function extract_first_img_src( string $html ): string {
	if ( '' === $html || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
		return '';
	}
	$processor = new \WP_HTML_Tag_Processor( $html );
	if ( ! $processor->next_tag( [ 'tag_name' => 'img' ] ) ) {
		return '';
	}

	return trim( (string) ( $processor->get_attribute( 'src' ) ?? '' ) );
}

/**
 * Caption from the first figcaption in a fragment.
 *
 * @param string $html HTML fragment.
 * @return string Sanitised caption HTML, or ''.
 */
function extract_first_figcaption( string $html ): string {
	if ( ! preg_match( '/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $html, $matches ) ) {
		return '';
	}

	return trim( stream_card_caption_kses( (string) $matches[1] ) );
}

/**
 * Resolve a core/image block to a local image attachment.
 *
 * @param array<string,mixed> $block Parsed image block.
 * @return int Attachment ID, or 0.
 */
function image_block_attachment_id( array $block ): int {
	$block_id = (int) ( $block['attrs']['id'] ?? 0 );
	if ( $block_id > 0 && wp_attachment_is_image( $block_id ) ) {
		return $block_id;
	}

	$html = (string) ( $block['innerHTML'] ?? '' );
	$src  = extract_first_img_src( $html );
	if ( '' === $src ) {
		$src = is_string( $block['attrs']['url'] ?? null ) ? (string) $block['attrs']['url'] : '';
	}

	return '' === $src ? 0 : resolve_upload_src( $src );
}

/**
 * A photo post's lead image.
 *
 * @param int $post_id Post ID.
 * @return array{attachment_id:int,alt:string,caption:string,source:string}
 */
function lead_image( int $post_id ): array {
	$empty = [
		'attachment_id' => 0,
		'alt'           => '',
		'caption'       => '',
		'source'        => '',
	];
	$post  = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
		return $empty;
	}

	foreach ( collect_image_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
		$id = image_block_attachment_id( $block );
		if ( $id <= 0 ) {
			continue;
		}
		$html    = (string) ( $block['innerHTML'] ?? '' );
		$caption = extract_first_figcaption( $html );
		if ( '' === trim( $caption ) ) {
			$caption = stream_card_caption_kses( trim( (string) get_post_field( 'post_excerpt', $id ) ) );
		}
		$alt = extract_first_img_alt( $html );

		return [
			'attachment_id' => $id,
			'alt'           => '' !== $alt ? $alt : attachment_alt( $id ),
			'caption'       => trim( $caption ),
			'source'        => 'block',
		];
	}

	foreach ( get_attached_media( 'image', $post ) as $image ) {
		$id = (int) $image->ID;
		if ( ! wp_attachment_is_image( $id ) ) {
			continue;
		}

		return [
			'attachment_id' => $id,
			'alt'           => attachment_alt( $id ),
			'caption'       => trim( stream_card_caption_kses( (string) $image->post_excerpt ) ),
			'source'        => 'attached',
		];
	}

	$thumbnail_id = (int) get_post_thumbnail_id( $post );
	if ( $thumbnail_id > 0 && wp_attachment_is_image( $thumbnail_id ) ) {
		return [
			'attachment_id' => $thumbnail_id,
			'alt'           => attachment_alt( $thumbnail_id ),
			'caption'       => trim( stream_card_caption_kses( (string) get_post_field( 'post_excerpt', $thumbnail_id ) ) ),
			'source'        => 'featured',
		];
	}

	return $empty;
}

/**
 * Whether the featured image already appears in post content.
 *
 * @param int $post_id Post ID.
 * @return bool True when the featured image is in the content.
 */
function featured_in_content( int $post_id ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
		return false;
	}

	$thumbnail_id = (int) get_post_thumbnail_id( $post );
	if ( $thumbnail_id <= 0 || ! wp_attachment_is_image( $thumbnail_id ) ) {
		return false;
	}

	foreach ( collect_image_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
		if ( image_block_attachment_id( $block ) === $thumbnail_id ) {
			return true;
		}
	}

	return false;
}

/**
 * Render a photo Stream card.
 *
 * @param \WP_Post            $post       Post to render.
 * @param array<string,mixed> $attributes Block attributes.
 * @return string Card HTML.
 */
function render_photo_stream_card( \WP_Post $post, array $attributes = [] ): string {
	$heading_level = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
	$permalink     = esc_url( (string) get_permalink( $post ) );
	$safe_title    = trim( Title_Privacy::safe_title( $post->ID ) );
	$titled        = '' !== trim( (string) $post->post_title ) && '' !== $safe_title;
	$date_display  = (string) get_the_date( '', $post );
	$date_time     = esc_attr( (string) get_post_time( 'c', true, $post ) );
	$lead          = lead_image( $post->ID );

	$out  = '<article class="pk-card pk-card--stream pk-card--photo k-photo h-entry">';
	$out .= '<div class="pk-body">';
	if ( $titled ) {
		$out .= '<span class="pk-kindlabel">' . esc_html( get_kind_label( stream_card_kind_label( $post ), 'photo', 'stream-card' ) ) . '</span>';
	}
	$out .= '<div class="pk-caption">';
	if ( $titled ) {
		// A hidden-location title is replaced by a synthetic name, which carries no p-name.
		$title_class = post_title_is_hidden( $post ) ? 'pk-title' : 'pk-title p-name';
		$out        .= '<h' . $heading_level . ' class="' . $title_class . '"><a class="u-url" href="' . $permalink . '">' . esc_html( $safe_title ) . '</a></h' . $heading_level . '>';
		$out        .= '<p class="pk-sub pk-stream-date"><time class="dt-published" datetime="' . $date_time . '">' . esc_html( $date_display ) . '</time></p>';
	} else {
		$fallback  = untitled_fallback_name( $post );
		$time_html = '<time class="dt-published" datetime="' . $date_time . '">' . esc_html( $date_display ) . '</time>';
		$out      .= '<h' . $heading_level . ' class="pk-title"><a class="u-url" href="' . $permalink . '">'
			. sprintf(
				/* translators: 1: kind name, 2: post date. */
				esc_html__( '%1$s, %2$s', 'post-kinds-for-indieweb-in-block-themes' ),
				esc_html( $fallback['kind'] ),
				$time_html
			)
			. '</a></h' . $heading_level . '>';
	}
	$out .= '</div>';

	if ( '' !== $lead['source'] ) {
		$image = wp_get_attachment_image(
			$lead['attachment_id'],
			'medium',
			false,
			[
				'class'   => 'u-photo',
				'alt'     => $titled ? '' : $lead['alt'],
				'loading' => 'lazy',
			]
		);
		if ( '' !== $image ) {
			$out .= '<div class="pk-media pk-media--stream">' . $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( '' !== $lead['caption'] ) {
				$out .= '<p class="pk-media__caption">' . $lead['caption'] . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised by lead_image().
			}
			$out .= '</div>';
		}
	}

	$out .= '</div></article>';

	return $out;
}

/**
 * The hidden citation each kind adds to its generic Stream card.
 *
 * A kind's card block carries its citation (`u-play-of`, `u-read-of`), but
 * a post whose body holds more than cards, or no card at all, renders the
 * generic card instead. These callbacks give that card the same citation
 * from the post's facts, so the entry still parses with it.
 *
 * @since 1.9.0
 *
 * @return array<string, callable> Kind slug => callback taking the post's kind_facts() and
 *                                 the post, and returning hidden h-cite HTML or ''.
 */
function stream_card_kind_cite_map(): array {
	$map = [
		'play'     => __NAMESPACE__ . '\\stream_card_play_cite',
		'read'     => __NAMESPACE__ . '\\stream_card_read_cite',
		'reply'    => __NAMESPACE__ . '\\stream_card_reply_cite',
		'like'     => __NAMESPACE__ . '\\stream_card_like_cite',
		'bookmark' => __NAMESPACE__ . '\\stream_card_bookmark_cite',
		'favorite' => __NAMESPACE__ . '\\stream_card_favorite_cite',
		'repost'   => __NAMESPACE__ . '\\stream_card_repost_cite',
	];

	/**
	 * Filters the hidden citation callbacks for generic Stream cards.
	 *
	 * Each callback receives the post's kind_facts() and the post, and
	 * returns hidden h-cite markup, or '' when the facts are empty.
	 * hidden_cite_html() builds that markup.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, callable> $map Kind slug => callback.
	 */
	return (array) apply_filters( 'pkiw_stream_card_kind_cite', $map );
}

/**
 * The hidden citation for a post's generic Stream card.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post Post.
 * @return string Hidden h-cite HTML, or '' when the kind has none or its facts are empty.
 */
function stream_card_kind_cite( \WP_Post $post ): string {
	$kind = get_post_kind_slug( $post );
	$map  = stream_card_kind_cite_map();
	if ( '' === $kind || ! isset( $map[ $kind ] ) || ! is_callable( $map[ $kind ] ) ) {
		return '';
	}

	return (string) call_user_func( $map[ $kind ], kind_facts( $post->ID ), $post );
}

/**
 * Hidden h-cite markup for an entry's citation property.
 *
 * Each part is a property class and its value or values. `p-author`
 * prints as a nested h-card with that name; every other class prints as
 * data, with `u-*` values escaped as URLs. A value that is empty, or
 * that esc_url() empties, is dropped.
 *
 * @since 1.9.0
 *
 * @param string                         $property Entry property class, e.g. `u-play-of`.
 * @param array<string, string|string[]> $parts    Property class => value or values.
 * @return string Hidden span, or '' when every value is empty.
 */
function hidden_cite_html( string $property, array $parts ): string {
	$inner = '';
	foreach ( $parts as $class => $values ) {
		foreach ( (array) $values as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			if ( 'p-author' === $class ) {
				$inner .= '<span class="p-author h-card"><data class="p-name" value="' . esc_attr( $value ) . '"></data></span>';
				continue;
			}
			// esc_url() empties a disallowed protocol, and php-mf2 reads an
			// empty u-* value as the page URL, so skip what escaping empties.
			$escaped = str_starts_with( $class, 'u-' ) ? esc_url( $value ) : esc_attr( $value );
			if ( '' === $escaped ) {
				continue;
			}
			$inner .= '<data class="' . esc_attr( $class ) . '" value="' . $escaped . '"></data>';
		}
	}

	return '' === $inner ? '' : '<span class="pk-kind-cite h-cite ' . esc_attr( $property ) . '" hidden>' . $inner . '</span>';
}

/**
 * A play's citation: the game's name, its URL and a uid per provider ID,
 * as the play-card prints them.
 *
 * @since 1.9.0
 *
 * @param array<string, mixed> $facts Play facts: `title`, `game_url`, `bgg_id`, `rawg_id`, `steam_id`.
 * @param \WP_Post             $post  Post. Unused.
 * @return string Hidden h-cite HTML, or ''.
 */
function stream_card_play_cite( array $facts, \WP_Post $post ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Map callback signature.
	$uids = [];
	foreach (
		[
			'bgg_id'   => 'https://boardgamegeek.com/boardgame/',
			'rawg_id'  => 'https://rawg.io/games/',
			'steam_id' => 'https://store.steampowered.com/app/',
		] as $key => $base
	) {
		$id = is_scalar( $facts[ $key ] ?? null ) ? trim( (string) $facts[ $key ] ) : '';
		if ( '' !== $id ) {
			$uids[] = $base . $id;
		}
	}

	return hidden_cite_html(
		'u-play-of',
		[
			'p-name' => is_scalar( $facts['title'] ?? null ) ? (string) $facts['title'] : '',
			'u-url'  => is_scalar( $facts['game_url'] ?? null ) ? (string) $facts['game_url'] : '',
			'u-uid'  => $uids,
		]
	);
}

/**
 * A read's citation: the book's name, author and URL, as the read-card
 * prints them.
 *
 * Kind_Facts has no read reader yet, so until one registers, the title,
 * author and URL the read card syncs to meta stand in, behind the same
 * visibility check kind_facts() applies.
 *
 * @since 1.9.0
 *
 * @param array<string, mixed> $facts Read facts: `title`, `author`, `url`.
 * @param \WP_Post             $post  Post.
 * @return string Hidden h-cite HTML, or ''.
 */
function stream_card_read_cite( array $facts, \WP_Post $post ): string {
	if ( [] === $facts && null === Kind_Facts::reader( 'read' ) && Kind_Facts::can_show( $post ) ) {
		$facts = [
			'title'  => get_post_meta( $post->ID, Meta_Fields::PREFIX . 'read_title', true ),
			'author' => get_post_meta( $post->ID, Meta_Fields::PREFIX . 'read_author', true ),
			'url'    => get_post_meta( $post->ID, Meta_Fields::PREFIX . 'read_url', true ),
		];
	}

	return hidden_cite_html(
		'u-read-of',
		[
			'p-name'   => is_scalar( $facts['title'] ?? null ) ? (string) $facts['title'] : '',
			'p-author' => is_scalar( $facts['author'] ?? null ) ? (string) $facts['author'] : '',
			'u-url'    => is_scalar( $facts['url'] ?? null ) ? (string) $facts['url'] : '',
		]
	);
}

/**
 * A reply's citation, as hidden data on its generic Stream card.
 *
 * @since 1.9.0
 *
 * @param array<string, mixed> $facts Reply facts.
 * @param \WP_Post             $post  Post.
 * @return string Hidden h-cite HTML, or ''.
 */
function stream_card_reply_cite( array $facts, \WP_Post $post ): string {
	return stream_card_response_cite( 'reply', 'u-in-reply-to', $facts, $post );
}

/**
 * A like's citation, as hidden data on its generic Stream card.
 *
 * @since 1.9.0
 *
 * @param array<string, mixed> $facts Like facts.
 * @param \WP_Post             $post  Post.
 * @return string Hidden h-cite HTML, or ''.
 */
function stream_card_like_cite( array $facts, \WP_Post $post ): string {
	return stream_card_response_cite( 'like', 'u-like-of', $facts, $post );
}

/**
 * A bookmark's citation, as hidden data on its generic Stream card.
 *
 * @since 1.9.0
 *
 * @param array<string, mixed> $facts Bookmark facts.
 * @param \WP_Post             $post  Post.
 * @return string Hidden h-cite HTML, or ''.
 */
function stream_card_bookmark_cite( array $facts, \WP_Post $post ): string {
	return stream_card_response_cite( 'bookmark', 'u-bookmark-of', $facts, $post );
}

/**
 * A favorite's citation, as hidden data on its generic Stream card.
 *
 * @since 1.9.0
 *
 * @param array<string, mixed> $facts Favorite facts.
 * @param \WP_Post             $post  Post.
 * @return string Hidden h-cite HTML, or ''.
 */
function stream_card_favorite_cite( array $facts, \WP_Post $post ): string {
	return stream_card_response_cite( 'favorite', 'u-favorite-of', $facts, $post, true );
}

/**
 * A repost's citation, as hidden data on its generic Stream card.
 *
 * @since 1.9.0
 *
 * @param array<string, mixed> $facts Repost facts.
 * @param \WP_Post             $post  Post.
 * @return string Hidden h-cite HTML, or ''.
 */
function stream_card_repost_cite( array $facts, \WP_Post $post ): string {
	return stream_card_response_cite( 'repost', 'u-repost-of', $facts, $post, true );
}

/**
 * A response citation, from reader facts or raw response meta.
 *
 * @since 1.9.0
 *
 * @param string               $kind     Kind slug.
 * @param string               $property Entry citation property class.
 * @param array<string, mixed> $facts    Facts: `url`, `cited_title`, `author`.
 * @param \WP_Post             $post     Post.
 * @param bool                 $require_url Whether an empty URL suppresses the citation.
 * @return string Hidden h-cite HTML, or ''.
 */
function stream_card_response_cite( string $kind, string $property, array $facts, \WP_Post $post, bool $require_url = false ): string {
	if ( [] === $facts && null === Kind_Facts::reader( $kind ) && Kind_Facts::can_show( $post ) ) {
		$target_suffix = [
			'reply'    => 'in_reply_to',
			'like'     => 'like_of',
			'bookmark' => 'bookmark_of',
			'repost'   => 'repost_of',
			'favorite' => 'favorite_url',
		][ $kind ] ?? '';
		$url           = '';
		foreach ( [ Meta_Fields::PREFIX . 'cite_url', Meta_Fields::PREFIX . $target_suffix ] as $key ) {
			$value = get_metadata_raw( 'post', $post->ID, $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$url = $value;
				break;
			}
		}
		$title = '';
		if ( 'favorite' === $kind ) {
			$favorite_title = get_metadata_raw( 'post', $post->ID, Meta_Fields::PREFIX . 'favorite_name', true );
			$title          = is_scalar( $favorite_title ) ? (string) $favorite_title : '';
		}
		if ( '' === trim( $title ) ) {
			$title = get_metadata_raw( 'post', $post->ID, Meta_Fields::PREFIX . 'cite_name', true );
		}
		$title = is_scalar( $title ) ? (string) $title : '';
		$facts = [
			'url'         => $url,
			'cited_title' => card_name_is_url( $title, $url ) ? '' : $title,
			'author'      => get_metadata_raw( 'post', $post->ID, Meta_Fields::PREFIX . 'cite_author', true ),
		];
	}

	$url = is_scalar( $facts['url'] ?? null ) ? trim( (string) $facts['url'] ) : '';
	if ( $require_url && ( '' === $url || '' === esc_url( $url ) ) ) {
		return '';
	}

	return hidden_cite_html(
		$property,
		[
			'p-name'   => is_scalar( $facts['cited_title'] ?? null ) ? (string) $facts['cited_title'] : '',
			'p-author' => is_scalar( $facts['author'] ?? null ) ? (string) $facts['author'] : '',
			'u-url'    => $url,
		]
	);
}

/**
 * The post's own response card when it has a usable title.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post Post.
 * @return array<string, mixed>|null Parsed block, or null.
 */
function stream_card_own_card( \WP_Post $post ): ?array {
	$kind = get_post_kind_slug( $post );
	if ( ! in_array( $kind, [ 'reply', 'like', 'bookmark' ], true ) ) {
		return null;
	}

	$block_name = 'post-kinds-indieweb/' . $kind . '-card';
	foreach ( flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
		if ( ( $block['blockName'] ?? '' ) !== $block_name ) {
			continue;
		}
		$title = $block['attrs']['title'] ?? null;
		if ( ! is_string( $title ) || '' === trim( $title ) ) {
			continue;
		}
		if ( card_name_is_url( $title, (string) ( $block['attrs']['url'] ?? '' ) ) ) {
			continue;
		}

		return $block;
	}

	return null;
}

/**
 * Whether a response post's title is its cited URL rather than a name.
 *
 * Micropub and some imports store post_title = url for a nameless
 * response. That title is synthetic: the card prints the cited name or
 * host instead, with no p-name (X8).
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post  Post.
 * @param string   $title Trimmed post title.
 * @return bool True for a cite kind whose title is its cited URL.
 */
function stream_card_title_is_cited_url( \WP_Post $post, string $title ): bool {
	$kind = get_post_kind_slug( $post );
	if ( ! in_array( $kind, [ 'like', 'reply', 'repost', 'bookmark', 'favorite', 'quote', 'follow' ], true ) ) {
		return false;
	}

	$cited_url  = '';
	$block_name = 'post-kinds-indieweb/' . $kind . '-card';
	foreach ( flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
		if ( ( $block['blockName'] ?? '' ) === $block_name ) {
			$cited_url = (string) ( $block['attrs']['url'] ?? '' );
			break;
		}
	}
	foreach ( '' === $cited_url ? cite_host_keys() : [] as $key ) {
		$value = get_post_meta( $post->ID, $key, true );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$cited_url = $value;
			break;
		}
	}

	return card_name_is_url( $title, $cited_url );
}

/**
 * Whether a generic Stream card omits feed action furniture: the thumbnail
 * strip, the "+N" link and "Read more". Articles and crafts are headline-only.
 *
 * @param string $kind_slug Kind slug.
 * @return bool True for headline-only generic cards.
 */
function generic_card_headline_only( string $kind_slug ): bool {
	return in_array( $kind_slug, [ 'article', 'craft' ], true );
}

/**
 * Generic Stream card regions a kind omits.
 *
 * @since 1.9.0
 *
 * @param string $kind_slug Kind slug.
 * @return string[] Known cut tokens.
 */
function generic_card_cuts( string $kind_slug ): array {
	if ( generic_card_headline_only( $kind_slug ) ) {
		$cuts = [ 'extras', 'meta' ];
	} elseif ( 'audio' === $kind_slug ) {
		$cuts = [ 'media', 'excerpt', 'meta', 'badge' ];
	} else {
		$cuts = [];
	}

	/**
	 * Filters the regions omitted from a kind's generic Stream card.
	 *
	 * @since 1.9.0
	 *
	 * @param string[] $cuts      Cut tokens.
	 * @param string   $kind_slug Kind slug.
	 */
	$cuts     = apply_filters( 'pkiw_generic_card_cuts', $cuts, $kind_slug );
	$cuts     = is_array( $cuts ) ? $cuts : [];
	$known    = [ 'extras', 'meta', 'media', 'excerpt', 'badge' ];
	$filtered = [];
	foreach ( $cuts as $cut ) {
		if ( ! is_string( $cut ) || ! in_array( $cut, $known, true ) || in_array( $cut, $filtered, true ) ) {
			continue;
		}
		$filtered[] = $cut;
	}

	return $filtered;
}

// phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh -- One branch per kind-specific line the generic card prints.
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
 * @param \WP_Post            $post       Post to render.
 * @param array<string,mixed> $attributes Block attributes. `headingLevel` (int,
 *                                        default 2, clamped 2–4) sets the title's
 *                                        heading tag, so the card matches the
 *                                        theme's heading outline wherever it sits.
 * @return string Card HTML.
 */
function render_generic_stream_card( \WP_Post $post, array $attributes = [] ): string {
	$heading_level = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
	$permalink     = esc_url( (string) get_permalink( $post ) );
	$kind_slug     = get_post_kind_slug( $post );
	$badge_kind    = '' !== $kind_slug ? $kind_slug : 'note';
	$kind_label    = stream_card_kind_label( $post );
	$excerpt       = trim( wp_strip_all_tags( get_the_excerpt( $post ) ) );
	$cuts          = generic_card_cuts( $kind_slug );

	$title     = trim( visitor_post_title( $post ) );
	$has_title = '' !== $title && ! stream_card_title_is_cited_url( $post, $title );
	if ( ! $has_title ) {
		$title = untitled_name( $post, false );
	}
	// A synthetic title is navigation, not the entry's name — no p-name, so
	// mf2 parsers fall back to the implied name / content as intended.
	// A hidden-location title is the safe name, which is synthetic too.
	$title_class = $has_title && ! post_title_is_hidden( $post ) ? 'pk-title p-name' : 'pk-title';

	// A recipe's facts come from the recipe plugin when one holds the recipe,
	// read here as the card renders.
	$recipe = 'recipe' === $kind_slug ? recipe_facts( $post->ID ) : null;

	$thumbnail_id = has_post_thumbnail( $post ) ? (int) get_post_thumbnail_id( $post ) : 0;
	$thumb_html   = $thumbnail_id > 0
		? get_the_post_thumbnail(
			$post,
			'medium',
			[
				'class'   => 'u-photo',
				'loading' => 'lazy',
				'alt'     => stream_card_thumbnail_alt( $post, $thumbnail_id ),
			]
		)
		: '';
	if ( '' === $thumb_html && null !== $recipe && $recipe['image_id'] > 0 ) {
		// No featured image: the recipe's own picture stands in.
		$thumb_html = wp_get_attachment_image(
			$recipe['image_id'],
			'medium',
			false,
			[
				'class'   => 'u-photo',
				'loading' => 'lazy',
				'alt'     => stream_card_thumbnail_alt( $post, $recipe['image_id'] ),
			]
		);
	}

	$out = '<article class="pk-card pk-card--stream k-' . esc_attr( $badge_kind ) . ' h-entry">';
	if ( ! in_array( 'badge', $cuts, true ) ) {
		// Badge SVG is a static, decorative glyph from get_kind_icon_svg().
		$out .= '<div class="pk-badge">' . get_kind_icon_svg( $badge_kind ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	$out .= '<div class="pk-body">';
	$out .= '<span class="pk-kindlabel">' . esc_html( get_kind_label( $kind_label, $badge_kind, 'stream-card' ) ) . '</span>';

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

	$title_html = esc_html( $title );
	if ( ! $has_title ) {
		// The card prints the date on its own line, so the "<Kind>, <date>"
		// name shows the kind and keeps the date in the link name (S7).
		$fallback = untitled_fallback_name( $post );
		if ( $title === $fallback['name'] && '' !== $fallback['kind'] && str_starts_with( $title, $fallback['kind'] ) ) {
			$title_html = esc_html( $fallback['kind'] )
				. '<span class="pk-sr-only">' . esc_html( substr( $title, strlen( $fallback['kind'] ) ) ) . '</span>';
		}
	}

	$out .= '<div class="pk-caption">';
	// $title_html is escaped above.
	$out .= '<h' . $heading_level . ' class="' . esc_attr( $title_class ) . '"><a class="u-url" href="' . $permalink . '">' . $title_html . '</a></h' . $heading_level . '>';
	/**
	 * Filters markup printed before the generic Stream card's date.
	 *
	 * Callbacks must return escaped markup.
	 *
	 * @since 1.9.0
	 *
	 * @param string   $html Markup to append before the date.
	 * @param \WP_Post $post Post.
	 */
	$out .= (string) apply_filters( 'pkiw_stream_card_before_date', '', $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callbacks return escaped markup.

	$date_display = get_the_date( '', $post );
	if ( '' !== $date_display ) {
		$out .= '<p class="pk-sub pk-stream-date"><time class="dt-published" datetime="'
			. esc_attr( stream_card_published_datetime( $post ) ) . '">'
			. esc_html( $date_display ) . '</time></p>';
	}
	/**
	 * Filters markup printed after the generic Stream card's date.
	 *
	 * Callbacks must return escaped markup.
	 *
	 * @since 1.9.0
	 *
	 * @param string   $html Markup to append after the date.
	 * @param \WP_Post $post Post.
	 */
	$out .= (string) apply_filters( 'pkiw_stream_card_after_date', '', $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callbacks return escaped markup.

	if ( null !== $recipe ) {
		$out .= recipe_stream_card_facts( $recipe );
	}

	if ( 'craft' === $kind_slug ) {
		$out .= craft_stream_card_facts( kind_facts( $post->ID ) );
	}

	$out .= '</div>';

	// Weather posts have no card block; show the observation Simple
	// Location stored, as the entry's p-weather. Empty when there's none
	// or the viewer can't see the post's location.
	if ( 'weather' === $kind_slug ) {
		$out .= Integrations\Simple_Location_Weather::render( $post->ID );
	}

	if ( '' !== $thumb_html && ! in_array( 'media', $cuts, true ) ) {
		// get_the_post_thumbnail() returns core-generated, escaped <img> markup.
		$out .= '<div class="pk-media pk-media--stream">' . $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		// An article keeps the hero's caption. A craft card prints the picture alone.
		if ( 'craft' !== $kind_slug ) {
			$out .= in_array( 'extras', $cuts, true ) ? stream_card_media_caption( $post ) : stream_card_media_extras( $post );
		}
		$out .= '</div>';
	}

	if ( '' !== $excerpt && ! in_array( 'excerpt', $cuts, true ) ) {
		$out .= '<p class="pk-excerpt p-summary">' . esc_html( $excerpt ) . '</p>';
	}

	// The kind's citation, which a card block would otherwise carry.
	$out .= stream_card_kind_cite( $post );

	if ( ! in_array( 'meta', $cuts, true ) ) {
		$out .= '<div class="pk-meta"><a class="pk-link" href="' . $permalink . '">'
			. esc_html__( 'Read more', 'post-kinds-for-indieweb-in-block-themes' )
			. '<span class="pk-sr-only">' . esc_html( ': ' . $title ) . '</span></a></div>';
	}
	$out .= '</div></article>';

	return $out;
}
// phpcs:enable Generic.Metrics.CyclomaticComplexity.TooHigh

/**
 * A recipe card's course and total time, each printed only when known.
 *
 * @since 1.9.0
 *
 * @param array{total_minutes: int, duration: string, courses: array<int, array{name: string, slug: string}>} $recipe Facts from recipe_facts().
 * @return string Markup, or '' when neither is known.
 */
function recipe_stream_card_facts( array $recipe ): string {
	$parts = [];

	if ( $recipe['courses'] ) {
		$parts[] = '<span class="pk-recipe-course">' . esc_html( implode( ', ', wp_list_pluck( $recipe['courses'], 'name' ) ) ) . '</span>';
	}
	if ( $recipe['total_minutes'] > 0 ) {
		$parts[] = '<time class="pk-recipe-time" datetime="' . esc_attr( $recipe['duration'] ) . '">' . esc_html( recipe_time_label( $recipe['total_minutes'] ) ) . '</time>';
	}

	return $parts ? '<p class="pk-sub pk-recipe-facts">' . implode( ' ', $parts ) . '</p>' : '';
}

/**
 * A craft post's type and time spent, each printed only when known.
 *
 * Reads the craft facts, so a theme and the generic card print one line:
 * the craft type as stored, then the time as a machine-readable duration
 * labeled `18 hr`. No status, no "Finished" and no link.
 *
 * @since 1.9.0
 *
 * @param array<string, mixed> $facts Facts from kind_facts() for a craft post.
 * @return string Markup, or '' when neither is known.
 */
function craft_stream_card_facts( array $facts ): string {
	$parts = [];

	$type = trim( (string) ( $facts['craft_type'] ?? '' ) );
	if ( '' !== $type ) {
		$parts[] = '<span class="pk-craft-type">' . esc_html( $type ) . '</span>';
	}

	$iso   = (string) ( $facts['duration_iso'] ?? '' );
	$label = (string) ( $facts['duration_label'] ?? '' );
	if ( '' !== $iso && '' !== $label ) {
		$parts[] = '<span class="pk-craft-time"><span class="pk-sr-only">' . esc_html__( 'Time spent', 'post-kinds-for-indieweb-in-block-themes' ) . ' </span><time datetime="' . esc_attr( $iso ) . '">' . esc_html( $label ) . '</time></span>';
	}

	return $parts ? '<p class="pk-sub pk-craft-facts">' . implode( ' ', $parts ) . '</p>' : '';
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

	// Everything except the hero, in attachment order.
	$rest = [];
	foreach ( $images as $image ) {
		if ( (int) $image->ID !== $hero_id ) {
			$rest[] = $image;
		}
	}

	// Only a strip swaps the caption, so only a card with one has a live region.
	$out = stream_card_media_caption( $post, [] !== $rest );
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
			. ' data-pk-caption="' . esc_attr( wp_strip_all_tags( $caption ) ) . '">'
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
 * The caption under a Stream card's hero image.
 *
 * Static by default. The thumbnail strip swaps the caption when a thumbnail
 * is chosen, so only that path asks for a live region. A caption nothing ever
 * swaps would add a dead live region to every card.
 *
 * @param \WP_Post $post Post being rendered.
 * @param bool     $live Whether the caption is a live region (aria-live polite).
 * @return string Caption markup, or ''.
 */
function stream_card_media_caption( \WP_Post $post, bool $live = false ): string {
	$hero_id      = (int) get_post_thumbnail_id( $post );
	$hero_caption = $hero_id > 0
		? trim( (string) get_post_field( 'post_excerpt', $hero_id ) )
		: '';

	if ( '' === $hero_caption ) {
		return '';
	}

	// Polite, not assertive: a caption changing must never interrupt.
	$live_attr = $live ? ' aria-live="polite"' : '';

	return '<p class="pk-media__caption"' . $live_attr . '>' . stream_card_caption_kses( $hero_caption ) . '</p>';
}


/**
 * Resolve alt text for the generic Stream card's featured image.
 *
 * The attachment's own `_wp_attachment_image_alt` is frequently empty —
 * Outpost and Micropub uploads set the featured image without touching the
 * attachment's alt meta, since the real alt text lives on the body's
 * core/image block instead. get_the_post_thumbnail() otherwise ships
 * alt="" with no aria-hidden, and the image sits outside any link, so an
 * accessibility checker flags it and a screen reader gets nothing from it.
 *
 * Falls back through: the body's core/image block alt (preferring the
 * block whose attrs.id matches the featured image, else the first image
 * block in the post), then the attachment's own alt meta, then the post
 * title. A real alt string is used rather than aria-hidden because a
 * <picture> wrapper (e.g. the Modern Image Formats plugin) drops
 * aria-hidden/role from the <img> it generates, so hiding the image that
 * way isn't reliable.
 *
 * @param \WP_Post $post         Post being rendered.
 * @param int      $thumbnail_id Featured image attachment ID.
 * @return string Alt text — never empty.
 */
function stream_card_thumbnail_alt( \WP_Post $post, int $thumbnail_id ): string {
	$image_blocks = collect_image_blocks( parse_blocks( (string) $post->post_content ) );

	$alt = '';
	foreach ( $image_blocks as $block ) {
		$block_id = (int) ( $block['attrs']['id'] ?? 0 );
		if ( $thumbnail_id > 0 && $block_id === $thumbnail_id ) {
			$alt = extract_first_img_alt( (string) ( $block['innerHTML'] ?? '' ) );
			break;
		}
	}
	if ( '' === $alt && ! empty( $image_blocks ) ) {
		$alt = extract_first_img_alt( (string) ( $image_blocks[0]['innerHTML'] ?? '' ) );
	}
	if ( '' !== $alt ) {
		return $alt;
	}

	$attachment_alt = $thumbnail_id > 0
		? trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) )
		: '';
	if ( '' !== $attachment_alt ) {
		return $attachment_alt;
	}

	return wp_strip_all_tags( visitor_post_title( $post ) );
}

/**
 * Flatten a parsed block tree into its core/image blocks, in document
 * order, including ones nested inside columns, groups, and galleries.
 *
 * @param array<int,array<string,mixed>> $blocks Parsed blocks (parse_blocks() output).
 * @return array<int,array<string,mixed>> The core/image blocks found.
 */
function collect_image_blocks( array $blocks ): array {
	$found = [];
	foreach ( $blocks as $block ) {
		if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
			$found[] = $block;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$found = array_merge( $found, collect_image_blocks( $block['innerBlocks'] ) );
		}
	}
	return $found;
}

/**
 * Read the `alt` attribute off the first `<img>` in a fragment of HTML.
 *
 * @param string $html HTML fragment (a core/image block's innerHTML).
 * @return string Alt text, or '' when there's no img or no non-empty alt.
 */
function extract_first_img_alt( string $html ): string {
	if ( '' === $html || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
		return '';
	}
	$processor = new \WP_HTML_Tag_Processor( $html );
	if ( ! $processor->next_tag( [ 'tag_name' => 'img' ] ) ) {
		return '';
	}
	return trim( (string) ( $processor->get_attribute( 'alt' ) ?? '' ) );
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
 * tab), or wraps a plain-text title in one. For an h-cite card, the original
 * URL and its u-* classes move to hidden data immediately before the title,
 * keeping the citation target separate from the post's own URL.
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

	$is_h_cite = static function ( int $heading_offset ) use ( $html ): bool {
		$before = substr( $html, 0, $heading_offset );
		if ( ! preg_match_all( '#<article\b[^>]*\bclass=(?:"([^"]*)"|\'([^\']*)\')[^>]*>#i', $before, $articles, PREG_SET_ORDER ) ) {
			return false;
		}

		$article = end( $articles );
		$classes = (string) ( $article[1] ?? '' );
		if ( '' === $classes ) {
			$classes = (string) ( $article[2] ?? '' );
		}
		$class_tokens = preg_split( '/\s+/', trim( $classes ) );

		return in_array( 'h-cite', false !== $class_tokens ? $class_tokens : [], true );
	};

	// Title already linked → repoint the anchor at the post.
	if ( preg_match( '#(<h[1-6] class="pk-title[^"]*">\s*)(<a\b[^>]*>)#s', $html, $linked, PREG_OFFSET_CAPTURE ) ) {
		$heading_offset = (int) $linked[1][1];
		$cite           = $is_h_cite( $heading_offset );
		$anchor         = $cite ? '<a href="' . $permalink . '">' : '<a class="u-url" href="' . $permalink . '">';
		$data           = '';
		$original_href  = '';

		if ( $cite ) {
			$processor = new \WP_HTML_Tag_Processor( $linked[2][0] );
			if ( $processor->next_tag( [ 'tag_name' => 'a' ] ) ) {
				$original_href = esc_url( (string) $processor->get_attribute( 'href' ) );
				$class_tokens  = preg_split( '/\s+/', trim( (string) $processor->get_attribute( 'class' ) ) );
				$class_tokens  = false !== $class_tokens ? $class_tokens : [];
				$url_classes   = array_values(
					array_filter(
						$class_tokens,
						static fn( string $class_token ): bool => str_starts_with( $class_token, 'u-' )
					)
				);

				if ( '' !== $original_href && $permalink !== $original_href && $url_classes ) {
					$data = '<data class="' . esc_attr( implode( ' ', $url_classes ) ) . '" value="' . $original_href . '" hidden></data>';
				}
			}
		}

		$replacement = $data . $linked[1][0] . $anchor;

		return substr_replace( $html, $replacement, (int) $linked[0][1], strlen( $linked[0][0] ) );
	}

	// Plain-text title → wrap it in a link to the post.
	if ( preg_match( '#(<h[1-6] class="pk-title[^"]*">)(\s*)([^<]+?)(\s*)(</h[1-6]>)#s', $html, $plain, PREG_OFFSET_CAPTURE ) ) {
		$cite        = $is_h_cite( (int) $plain[1][1] );
		$anchor      = $cite ? '<a href="' . $permalink . '">' : '<a class="u-url" href="' . $permalink . '">';
		$replacement = $plain[1][0] . $plain[2][0] . $anchor . $plain[3][0] . '</a>' . $plain[4][0] . $plain[5][0];

		return substr_replace( $html, $replacement, (int) $plain[0][1], strlen( $plain[0][0] ) );
	}

	return $html;
}

/**
 * Re-level a rendered card's title heading.
 *
 * Every card render.php hardcodes `<h2 class="pk-title …">` since a card
 * rendered on its own (single post view, a non-Stream block) is the page's
 * first heading under the title. On the Stream, though, the surrounding
 * theme may already be at h2 or h3, and every card in one Stream should
 * share a level regardless of which branch rendered it — a card block
 * rendered via do_blocks(), a synthetic watch-card block, or the generic
 * stream card (which sets its own level directly).
 *
 * The play card's Review heading (`pk-scorepad__heading`) moves by the same
 * offset, capped at h6, so it stays one level below the card title.
 *
 * @param string $html  Rendered card HTML.
 * @param int    $level Clamped heading level (2–4).
 * @return string HTML with the title heading re-leveled.
 */
function apply_stream_heading_level( string $html, int $level ): string {
	if ( 2 === $level ) {
		return $html;
	}

	$out = preg_replace_callback(
		'#<h2( class="pk-title[^"]*")>(.*?)</h2>#s',
		static function ( $matches ) use ( $level ) {
			return '<h' . $level . $matches[1] . '>' . $matches[2] . '</h' . $level . '>';
		},
		$html,
		1,
		$count
	);

	$html = ( null !== $out && $count > 0 ) ? $out : $html;

	$offset = $level - 2;
	$out    = preg_replace_callback(
		'#<h([2-6])( class="pk-scorepad__heading")>(.*?)</h\1>#s',
		static function ( $matches ) use ( $offset ) {
			$to = min( 6, (int) $matches[1] + $offset );
			return '<h' . $to . $matches[2] . '>' . $matches[3] . '</h' . $to . '>';
		},
		$html
	);

	return null !== $out ? $out : $html;
}

/**
 * Whether the card's first title sits inside a nested microformats object.
 *
 * A cite card's title belongs to its h-cite (or h-event, h-food...), so a
 * property placed beside it attaches to that object, not to the entry. A
 * title whose nearest root is an h-entry, or that has no root in the card,
 * belongs to the entry.
 *
 * @param string $html Rendered card HTML.
 * @return bool
 */
function card_title_in_nested_object( string $html ): bool {
	if ( ! preg_match( '/\bh-[a-z]+/', $html ) ) {
		return false;
	}
	$doc      = new \DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="pkiw-title-scope">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$title = ( new \DOMXPath( $doc ) )->query( '//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][starts-with(@class, "pk-title")]' )->item( 0 );
	if ( ! $title instanceof \DOMElement ) {
		return false;
	}
	for ( $p = $title->parentNode; $p instanceof \DOMElement; $p = $p->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
		if ( 'pkiw-title-scope' === $p->getAttribute( 'id' ) ) {
			return false;
		}
		$classes = ' ' . $p->getAttribute( 'class' ) . ' ';
		if ( preg_match( '/\sh-[a-z]+(-[a-z]+)*\s/', $classes ) ) {
			return ! str_contains( $classes, ' h-entry ' );
		}
	}
	return false;
}

/**
 * The datetime a Stream card prints for a post's publish date.
 *
 * Exercise and sleep print the local date alone: a clock time would say when
 * a workout ended or when someone woke up (Courtney's answer on the health
 * data question). Every other kind keeps the full UTC timestamp. The
 * `pkiw_stream_date_only_kinds` filter names the date-only kinds.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post Post.
 * @return string `Y-m-d` for a date-only kind, else an ISO 8601 timestamp.
 */
function stream_card_published_datetime( \WP_Post $post ): string {
	/**
	 * Filters the kinds whose Stream card prints a date, not a timestamp.
	 *
	 * @since 1.9.0
	 *
	 * @param string[] $kinds Kind slugs. Default exercise and sleep.
	 */
	$date_only = (array) apply_filters( 'pkiw_stream_date_only_kinds', [ 'exercise', 'sleep' ] );

	if ( in_array( get_post_kind_slug( $post ), $date_only, true ) ) {
		return (string) get_post_time( 'Y-m-d', false, $post );
	}

	return (string) get_post_time( 'c', true, $post );
}

/**
 * Insert the post's published date inside the card, under the title.
 *
 * On the Stream the date reads inside each card rather than floating above
 * it, so the Post Template no longer renders its own post-date block. The
 * date is the entry's: inside a nested object (a like card's h-cite) it
 * prints without dt-published, and ensure_entry_properties() gives the
 * entry its hidden date.
 *
 * @param string   $html Rendered card HTML.
 * @param \WP_Post $post Post the card represents.
 * @return string HTML with the post date under the title.
 */
function inject_post_date_into_card( string $html, \WP_Post $post ): string {
	$display = get_the_date( '', $post );
	if ( '' === $display ) {
		return $html;
	}

	$date_html = '<p class="pk-sub pk-stream-date"><time'
		. ( card_title_in_nested_object( $html ) ? '' : ' class="dt-published"' )
		. ' datetime="' . esc_attr( stream_card_published_datetime( $post ) ) . '">'
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
			'attributes'      => [
				// Clamped 2–4 at render time so a theme's own heading outline
				// (the home page in particular) stays intact regardless of
				// where the Stream sits in it.
				'headingLevel' => [
					'type'    => 'integer',
					'default' => 2,
				],
			],
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
