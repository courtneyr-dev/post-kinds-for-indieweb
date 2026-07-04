<?php
/**
 * Read With Kindle Preview Pattern
 *
 * A block pattern combining the Read card with a Kindle instant-preview
 * embed, marked so Kindle_Embed_Bridge rewrites it server-side from the
 * post's read_asin/read_isbn meta.
 *
 * @package PostKindsForIndieWeb
 * @since   1.2.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Patterns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Read With Kindle Preview pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/read-with-kindle-preview',
	[
		'title'       => __( 'Read post with Kindle preview', 'post-kinds-for-indieweb' ),
		'description' => __( 'A Read card followed by a Kindle instant-preview embed that follows the book.', 'post-kinds-for-indieweb' ),
		'categories'  => [ 'post-kinds-for-indieweb' ],
		'keywords'    => [ 'read', 'book', 'kindle', 'preview', 'indieweb' ],
		'blockTypes'  => [ 'post-kinds-indieweb/read-card' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:post-kinds-indieweb/read-card /-->' .
			// Same non-empty placeholder url the read-card's "Show Kindle
			// preview" inspector toggle inserts (src/blocks/read-card/edit.js
			// ~line 333), and the exact save()-generated inner markup for
			// those attrs — verified with wp.blocks.serialize() against a
			// live editor. A blank url serializes as a self-closing block
			// with no innerHTML, which core/embed's save() can never
			// reproduce, so the old hand-written wrapper tripped "invalid
			// content" block validation on load and recovery blew away the
			// preview. The placeholder is never shown: Kindle_Embed_Bridge
			// replaces the wrapper's contents with an iframe derived from
			// the post's ISBN/ASIN at render time.
			'<!-- wp:embed {"url":"https://read.amazon.com/kp/embed","type":"video","providerNameSlug":"amazon-kindle","className":"pkiw-kindle-preview"} -->' . "\n" .
			'<figure class="wp-block-embed is-type-video is-provider-amazon-kindle wp-block-embed-amazon-kindle pkiw-kindle-preview"><div class="wp-block-embed__wrapper">' . "\n" .
			'https://read.amazon.com/kp/embed' . "\n" .
			'</div></figure>' . "\n" .
			'<!-- /wp:embed -->' . "\n" .
			'<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
	]
);
