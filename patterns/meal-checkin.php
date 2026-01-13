<?php
/**
 * Meal Check-in Pattern
 *
 * A compound pattern combining food, drink, and venue for restaurant visits.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb\Patterns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Meal Check-in pattern.
 */
register_block_pattern(
	'reactions-indieweb/meal-checkin',
	array(
		'title'       => __( 'Meal Check-in', 'reactions-for-indieweb' ),
		'description' => __( 'Log a restaurant visit with food, drink, and location.', 'reactions-for-indieweb' ),
		'categories'  => array( 'reactions-for-indieweb' ),
		'keywords'    => array( 'meal', 'food', 'drink', 'restaurant', 'checkin', 'dining', 'indieweb' ),
		'blockTypes'  => array( 'core/group' ),
		'postTypes'   => array( 'post' ),
		'content'     => '<!-- wp:group {"className":"h-entry reactions-meal-checkin","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry reactions-meal-checkin">

	<!-- wp:reactions-indieweb/checkin-card /-->

	<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"var:preset|spacing|20"}}}} -->
	<div class="wp-block-columns">

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:reactions-indieweb/eat-card /-->
		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:reactions-indieweb/drink-card /-->
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
	)
);
