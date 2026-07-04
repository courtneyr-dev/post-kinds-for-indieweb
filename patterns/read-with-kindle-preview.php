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
			'<!-- wp:embed {"url":"","type":"video","providerNameSlug":"amazon-kindle","className":"pkiw-kindle-preview"} -->' .
			'<figure class="wp-block-embed is-provider-amazon-kindle pkiw-kindle-preview"><div class="wp-block-embed__wrapper"></div></figure>' .
			'<!-- /wp:embed -->' .
			'<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
	]
);
