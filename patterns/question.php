<?php
/**
 * Question pattern.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW\Patterns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

register_block_pattern(
	'post-kinds-indieweb/question',
	[
		'title'       => __( 'Question', 'post-kinds-for-indieweb-in-block-themes' ),
		'description' => __( 'A salutation, question body and answer settings.', 'post-kinds-for-indieweb-in-block-themes' ),
		'categories'  => [ 'post-kinds-for-indieweb-in-block-themes' ],
		'keywords'    => [ 'question', 'ask', 'answers', 'indieweb' ],
		'blockTypes'  => [ 'core/group' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:group {"className":"pkiw-question","layout":{"type":"constrained"}} -->
<div class="wp-block-group pkiw-question"><!-- wp:paragraph {"className":"is-style-cr-salutation"} -->
<p class="is-style-cr-salutation">' . esc_html__( 'Dear readers,', 'post-kinds-for-indieweb-in-block-themes' ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p></p>
<!-- /wp:paragraph -->

<!-- wp:post-kinds-indieweb/answers /--></div>
<!-- /wp:group -->',
	]
);
