<?php
/**
 * Quick Photo Check-in Pattern
 *
 * A simple check-in pattern with just location and optional photo.
 * Perfect for scenic spots, interesting places, or quick location shares.
 *
 * @package PKIW
 * @since   1.2.0
 */

declare(strict_types=1);

namespace PKIW\Patterns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Quick Photo Check-in pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/quick-photo-checkin',
	[
		'title'       => __( 'Quick Photo Check-in', 'post-kinds-for-indieweb' ),
		'description' => __( 'Simple check-in with location and optional photo. Great for scenic spots and quick shares.', 'post-kinds-for-indieweb' ),
		'categories'  => [ 'post-kinds-for-indieweb' ],
		'keywords'    => [ 'photo', 'checkin', 'check-in', 'location', 'scenic', 'quick', 'simple', 'indieweb' ],
		'blockTypes'  => [ 'core/group' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:group {"className":"h-entry post-kinds-quick-checkin","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry post-kinds-quick-checkin">

	<!-- wp:post-kinds-indieweb/checkin-card /-->

	<!-- wp:image {"align":"center","className":"u-photo","sizeSlug":"large"} -->
	<figure class="wp-block-image aligncenter size-large u-photo"><img src="" alt=""/></figure>
	<!-- /wp:image -->

	<!-- wp:group {"className":"e-content","layout":{"type":"constrained"}} -->
	<div class="wp-block-group e-content">
		<!-- wp:paragraph {"placeholder":"' . esc_attr__( 'Add a caption or notes (optional)...', 'post-kinds-for-indieweb' ) . '"} -->
		<p></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->',
	]
);
