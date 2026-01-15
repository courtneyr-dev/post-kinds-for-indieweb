<?php
/**
 * Meal Check-in Pattern
 *
 * A compound pattern combining food, drink, and venue for restaurant visits.
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Patterns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Meal Check-in pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/meal-checkin',
	[
		'title'       => __( 'Meal Check-in', 'post-kinds-for-indieweb' ),
		'description' => __( 'Log a restaurant visit with food, drink, and location.', 'post-kinds-for-indieweb' ),
		'categories'  => [ 'post-kinds-for-indieweb' ],
		'keywords'    => [ 'meal', 'food', 'drink', 'restaurant', 'checkin', 'dining', 'indieweb' ],
		'blockTypes'  => [ 'core/group' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:group {"className":"h-entry post-kinds-meal-checkin","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry post-kinds-meal-checkin">

	<!-- wp:post-kinds-indieweb/checkin-card /-->

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

	<!-- wp:group {"className":"e-content","layout":{"type":"constrained"}} -->
	<div class="wp-block-group e-content">
		<!-- wp:paragraph {"placeholder":"Share your dining experience..."} -->
		<p></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->',
	]
);
