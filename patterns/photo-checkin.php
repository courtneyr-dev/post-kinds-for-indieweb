<?php
/**
 * Photo Check-in Pattern
 *
 * A flexible check-in pattern with optional photo, food, and drink sections.
 * All sections except the location are optional and can be removed.
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
 * Register the Photo Check-in pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/photo-checkin',
	[
		'title'       => __( 'Photo Check-in', 'post-kinds-for-indieweb-in-block-themes' ),
		'description' => __( 'Check in at a location with optional photo, food, and drink. Remove any sections you don\'t need.', 'post-kinds-for-indieweb-in-block-themes' ),
		'categories'  => [ 'post-kinds-for-indieweb-in-block-themes' ],
		'keywords'    => [ 'photo', 'checkin', 'check-in', 'location', 'food', 'drink', 'meal', 'restaurant', 'bar', 'cafe', 'indieweb' ],
		'blockTypes'  => [ 'core/group' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:group {"className":"h-entry post-kinds-photo-checkin","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry post-kinds-photo-checkin">

	<!-- wp:post-kinds-indieweb/checkin-card /-->

	<!-- wp:group {"className":"post-kinds-photo-checkin__photo","layout":{"type":"constrained"}} -->
	<div class="wp-block-group post-kinds-photo-checkin__photo">
		<!-- wp:paragraph {"align":"center","className":"post-kinds-optional-hint","style":{"typography":{"fontSize":"13px"}}} -->
		<p class="has-text-align-center post-kinds-optional-hint" style="font-size:13px"><em>' . esc_html__( 'Optional: Add a photo below, or delete this section', 'post-kinds-for-indieweb-in-block-themes' ) . '</em></p>
		<!-- /wp:paragraph -->

		<!-- wp:image {"align":"center","className":"u-photo","sizeSlug":"large"} -->
		<figure class="wp-block-image aligncenter size-large u-photo"><img src="" alt=""/></figure>
		<!-- /wp:image -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"post-kinds-photo-checkin__consumption","layout":{"type":"constrained"}} -->
	<div class="wp-block-group post-kinds-photo-checkin__consumption">
		<!-- wp:paragraph {"align":"center","className":"post-kinds-optional-hint","style":{"typography":{"fontSize":"13px"}}} -->
		<p class="has-text-align-center post-kinds-optional-hint" style="font-size:13px"><em>' . esc_html__( 'Optional: Add food/drink details below, or delete this section', 'post-kinds-for-indieweb-in-block-themes' ) . '</em></p>
		<!-- /wp:paragraph -->

		<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"var:preset|spacing|20"}}}} -->
		<div class="wp-block-columns">

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:post-kinds-indieweb/eat-card /-->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:post-kinds-indieweb/drink-card /-->
			</div>
			<!-- /wp:column -->

		</div>
		<!-- /wp:columns -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"e-content","layout":{"type":"constrained"}} -->
	<div class="wp-block-group e-content">
		<!-- wp:paragraph {"placeholder":"' . esc_attr__( 'Add notes about your visit (optional)...', 'post-kinds-for-indieweb-in-block-themes' ) . '"} -->
		<p></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->',
	]
);
