<?php
/**
 * Check-in Card Pattern
 *
 * A block pattern for location check-ins at venues.
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
 * Register the Check-in Card pattern.
 */
register_block_pattern(
	'reactions-indieweb/checkin-card',
	array(
		'title'       => __( 'Check-in Card', 'reactions-indieweb' ),
		'description' => __( 'Check in at a location or venue.', 'reactions-indieweb' ),
		'categories'  => array( 'reactions-indieweb' ),
		'keywords'    => array( 'checkin', 'check-in', 'location', 'venue', 'place', 'geo', 'indieweb', 'reaction' ),
		'blockTypes'  => array( 'core/group' ),
		'postTypes'   => array( 'post' ),
		'content'     => '<!-- wp:group {"className":"h-entry reactions-checkin","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry reactions-checkin">

	<!-- wp:group {"className":"p-location h-card u-checkin","style":{"spacing":{"padding":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}},"border":{"radius":"8px"}},"backgroundColor":"tertiary","layout":{"type":"constrained"}} -->
	<div class="wp-block-group p-location h-card u-checkin has-tertiary-background-color has-background" style="border-radius:8px;padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30)">

		<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"flex-start"}} -->
		<div class="wp-block-group">

			<!-- wp:paragraph {"style":{"typography":{"fontSize":"24px"}}} -->
			<p style="font-size:24px">📍</p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":2,"className":"p-name","metadata":{"bindings":{"content":{"source":"reactions-indieweb/kind-meta","args":{"key":"checkin_name"}}}}} -->
			<h2 class="wp-block-heading p-name"></h2>
			<!-- /wp:heading -->

		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"p-adr h-adr","layout":{"type":"constrained"}} -->
		<div class="wp-block-group p-adr h-adr">

			<!-- wp:paragraph {"className":"p-street-address","textColor":"secondary","fontSize":"small","metadata":{"bindings":{"content":{"source":"reactions-indieweb/kind-meta","args":{"key":"checkin_address"}}}}} -->
			<p class="p-street-address has-secondary-color has-text-color has-small-font-size"></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"className":"reactions-checkin-locality","textColor":"secondary","fontSize":"small","metadata":{"bindings":{"content":{"source":"reactions-indieweb/kind-meta","args":{"key":"checkin_full_address"}}}}} -->
			<p class="reactions-checkin-locality has-secondary-color has-text-color has-small-font-size"></p>
			<!-- /wp:paragraph -->

		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"p-geo h-geo","style":{"display":"none"},"layout":{"type":"constrained"}} -->
		<div class="wp-block-group p-geo h-geo" style="display:none">

			<!-- wp:html -->
			<data class="p-latitude" value=""></data>
			<data class="p-longitude" value=""></data>
			<!-- /wp:html -->

		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"e-content","layout":{"type":"constrained"}} -->
	<div class="wp-block-group e-content">

		<!-- wp:paragraph {"placeholder":"Add a note about your check-in (optional)..."} -->
		<p></p>
		<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"reactions-meta","style":{"spacing":{"margin":{"top":"var:preset|spacing|20"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group reactions-meta" style="margin-top:var(--wp--preset--spacing--20)">

		<!-- wp:post-date {"className":"dt-published","fontSize":"small"} /-->

		<!-- wp:paragraph {"className":"p-author h-card","fontSize":"small"} -->
		<p class="p-author h-card has-small-font-size">by <a class="u-url p-name" href="">Author</a></p>
		<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->',
	)
);
