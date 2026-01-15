<?php
/**
 * RSVP Response Pattern
 *
 * A block pattern for responding to events with RSVP status.
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
 * Register the RSVP Response pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/rsvp-response',
	[
		'title'       => __( 'RSVP Response', 'post-kinds-for-indieweb' ),
		'description' => __( 'Respond to an event with yes, no, maybe, or interested.', 'post-kinds-for-indieweb' ),
		'categories'  => [ 'post-kinds-for-indieweb' ],
		'keywords'    => [ 'rsvp', 'event', 'response', 'indieweb', 'reaction' ],
		'blockTypes'  => [ 'core/group' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:group {"className":"h-entry post-kinds-rsvp","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry post-kinds-rsvp">

	<!-- wp:group {"className":"p-rsvp-context","layout":{"type":"constrained"}} -->
	<div class="wp-block-group p-rsvp-context">

		<!-- wp:heading {"level":2,"className":"p-name"} -->
		<h2 class="wp-block-heading p-name">RSVP</h2>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"className":"u-in-reply-to h-cite"} -->
		<p class="u-in-reply-to h-cite">In response to: <a class="u-url p-name" href="">Event Name</a></p>
		<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"post-kinds-rsvp-status","style":{"spacing":{"padding":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}},"border":{"radius":"4px"}},"backgroundColor":"tertiary","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"center"}} -->
	<div class="wp-block-group post-kinds-rsvp-status has-tertiary-background-color has-background" style="border-radius:4px;padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30)">

		<!-- wp:paragraph {"className":"p-rsvp","fontSize":"large","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"rsvp_status"}}}}} -->
		<p class="p-rsvp has-large-font-size"></p>
		<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"e-content p-summary","layout":{"type":"constrained"}} -->
	<div class="wp-block-group e-content p-summary">

		<!-- wp:paragraph {"placeholder":"Add your note about this event (optional)..."} -->
		<p></p>
		<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"post-kinds-meta","style":{"spacing":{"margin":{"top":"var:preset|spacing|20"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group post-kinds-meta" style="margin-top:var(--wp--preset--spacing--20)">

		<!-- wp:post-date {"className":"dt-published","fontSize":"small"} /-->

		<!-- wp:paragraph {"className":"p-author h-card","fontSize":"small"} -->
		<p class="p-author h-card has-small-font-size">by <a class="u-url p-name" href="">Author</a></p>
		<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->',
	]
);
