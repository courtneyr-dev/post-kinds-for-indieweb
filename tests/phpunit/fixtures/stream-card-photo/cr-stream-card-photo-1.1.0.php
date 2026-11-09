<?php
/**
 * Plugin Name: CR Stream Card Photo Fallback
 * Description: Gives a Stream card its picture when the post has no featured image, using the first image attached to the post. Display only — no post data is changed.
 * Author: Courtney Robertson
 * Version: 1.1.0
 *
 * Site-only. Depends on post-kinds-for-indieweb's stream-card block.
 *
 * 1.1.0 (issue 263): the plugin's article Stream card no longer prints the
 * .pk-meta tail this filter anchored on. An article card with no picture takes
 * the fallback just before the end of its body instead, which is where .pk-meta
 * sat. A password-protected post never takes the fallback.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Put the post's own photo back into its Stream card.
 *
 * The card renderer reaches for get_the_post_thumbnail(), so a post with no
 * featured image gets a card with no picture. A photo posted from Outpost
 * keeps its image in the body as a core/image block and never sets a
 * thumbnail, so the polaroid the zine skin draws had nothing inside it.
 *
 * Setting a real featured image would have been the other way to fix this,
 * and it is the wrong one here: single.html renders wp:post-featured-image,
 * so every one of those posts would then show the same picture twice, once
 * at the top and once in the body. This stays in the card.
 *
 * @param string        $html  Rendered block HTML.
 * @param array         $block Parsed block.
 * @param \WP_Block|null $instance Block instance, carries the postId context.
 * @return string
 */
function cr_stream_card_photo_fallback( string $html, array $block, $instance = null ): string {
	if ( 'post-kinds-indieweb/stream-card' !== ( $block['blockName'] ?? '' ) ) {
		return $html;
	}
	// The renderer already found a featured image; leave its markup alone.
	if ( false !== strpos( $html, 'pk-media' ) ) {
		return $html;
	}
	// Only the generic card has a tail to insert before. Self-contained kind
	// cards (a watch card, a note that is nothing but card blocks) render their
	// own media and must not be touched.
	//
	// Most generic cards end in a .pk-meta div. The article card does not (the
	// plugin cuts Read more from it), so the one exact opening tag the plugin
	// prints for a generic article card marks it, and the picture goes before the
	// card's last "</div></article>", the end of .pk-body, where .pk-meta sat. A
	// password-protected card has a different opening tag (pk-card--protected), so
	// it never matches.
	$anchor      = '<div class="pk-meta">';
	$article_tag = '<article class="pk-card pk-card--stream k-article h-entry">';
	$pos         = strpos( $html, $anchor );
	if ( false === $pos && false !== strpos( $html, $article_tag ) ) {
		$pos = strrpos( $html, '</div></article>' );
	}
	if ( false === $pos ) {
		return $html;
	}

	$post_id = 0;
	if ( $instance instanceof \WP_Block && ! empty( $instance->context['postId'] ) ) {
		$post_id = (int) $instance->context['postId'];
	}
	if ( ! $post_id ) {
		return $html;
	}
	// A protected post's attached image must not reach its card.
	if ( post_password_required( $post_id ) ) {
		return $html;
	}

	// Prefer the image the post body actually shows, and carry its alt: Outpost and
	// Micropub uploads leave _wp_attachment_image_alt empty while the core/image
	// block in the body carries the real description.
	$img_id = 0;
	$alt    = '';
	foreach ( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) as $b ) {
		if ( 'core/image' !== ( $b['blockName'] ?? '' ) ) {
			continue;
		}
		$img_id = (int) ( $b['attrs']['id'] ?? 0 );
		$t      = new WP_HTML_Tag_Processor( (string) $b['innerHTML'] );
		if ( $t->next_tag( 'img' ) ) {
			$alt = (string) $t->get_attribute( 'alt' );
		}
		break;
	}
	if ( ! $img_id ) {
		$attachments = get_posts(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_parent'    => $post_id,
				'posts_per_page' => 1,
				'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
				'fields'         => 'ids',
				'post_status'    => 'inherit',
			]
		);
		if ( empty( $attachments ) ) {
			return $html;
		}
		$img_id = (int) $attachments[0];
	}
	if ( '' === $alt ) {
		$alt = (string) get_post_meta( $img_id, '_wp_attachment_image_alt', true );
	}

	$attr = [
		'class'   => 'u-photo',
		'loading' => 'lazy',
		'alt'     => $alt,
	];
	if ( '' === $alt ) {
		// Nothing describes it anywhere: name it after the post, which is what the
		// image illustrates. A <picture> wrapper or lazy-load pass may drop
		// aria-hidden/role, so a real alt is the robust choice here.
		$attr['alt'] = wp_strip_all_tags( (string) get_the_title( $post_id ) );
	}

	$img = wp_get_attachment_image( $img_id, 'medium', false, $attr );
	if ( '' === $img ) {
		return $html;
	}

	// wp_get_attachment_image() returns core-generated, escaped markup.
	$media = '<div class="pk-media pk-media--stream">' . $img . '</div>';

	// $pos is the card's own tail: its first .pk-meta, or the end of an article
	// card's body.
	return substr( $html, 0, $pos ) . $media . substr( $html, $pos );
}
add_filter( 'render_block', 'cr_stream_card_photo_fallback', 10, 3 );
