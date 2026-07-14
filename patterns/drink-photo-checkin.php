<?php
/**
 * Drink Photo Check-in Pattern
 *
 * A check-in pattern focused on drink photography at a bar or cafe.
 * Includes location, photo, and drink details.
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
 * Register the Drink Photo Check-in pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/drink-photo-checkin',
	[
		'title'       => __( 'Drink Photo Check-in', 'post-kinds-for-indieweb-in-block-themes' ),
		'description' => __( 'Check in at a bar or cafe with drink photo and details.', 'post-kinds-for-indieweb-in-block-themes' ),
		'categories'  => [ 'post-kinds-for-indieweb-in-block-themes' ],
		'keywords'    => [ 'drink', 'photo', 'checkin', 'bar', 'cafe', 'coffee', 'beer', 'cocktail', 'indieweb' ],
		'blockTypes'  => [ 'core/group' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:group {"className":"h-entry post-kinds-drink-checkin","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry post-kinds-drink-checkin">

	<!-- wp:post-kinds-indieweb/checkin-card /-->

	<!-- wp:image {"align":"center","className":"u-photo","sizeSlug":"large"} -->
	<figure class="wp-block-image aligncenter size-large u-photo"><img src="" alt=""/></figure>
	<!-- /wp:image -->

	<!-- wp:post-kinds-indieweb/drink-card /-->

	<!-- wp:group {"className":"e-content","layout":{"type":"constrained"}} -->
	<div class="wp-block-group e-content">
		<!-- wp:paragraph {"placeholder":"' . esc_attr__( 'How was the drink? (optional)...', 'post-kinds-for-indieweb-in-block-themes' ) . '"} -->
		<p></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->',
	]
);
