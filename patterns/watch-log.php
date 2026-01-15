<?php
/**
 * Watch Log Pattern
 *
 * A block pattern for logging films or TV shows watched.
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
 * Register the Watch Log pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/watch-log',
	[
		'title'       => __( 'Watch Log', 'post-kinds-for-indieweb' ),
		'description' => __( 'Log a film or TV show you watched.', 'post-kinds-for-indieweb' ),
		'categories'  => [ 'post-kinds-for-indieweb' ],
		'keywords'    => [ 'watch', 'movie', 'film', 'tv', 'show', 'video', 'indieweb', 'reaction' ],
		'blockTypes'  => [ 'core/group' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:group {"className":"h-entry post-kinds-watch","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry post-kinds-watch">

	<!-- wp:columns {"className":"h-cite u-watch-of"} -->
	<div class="wp-block-columns h-cite u-watch-of">

		<!-- wp:column {"width":"150px"} -->
		<div class="wp-block-column" style="flex-basis:150px">

			<!-- wp:image {"className":"u-photo post-kinds-watch-poster","width":"150px","height":"225px","scale":"cover","metadata":{"bindings":{"url":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"watch_poster"}},"alt":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"watch_title"}}}}} -->
			<figure class="wp-block-image is-resized u-photo post-kinds-watch-poster"><img src="" alt="" style="object-fit:cover;width:150px;height:225px"/></figure>
			<!-- /wp:image -->

		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">

			<!-- wp:heading {"level":2,"className":"p-name","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"watch_title"}}}}} -->
			<h2 class="wp-block-heading p-name"></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"className":"dt-published post-kinds-watch-year","fontSize":"medium","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"watch_year"}}}}} -->
			<p class="dt-published post-kinds-watch-year has-medium-font-size"></p>
			<!-- /wp:paragraph -->

			<!-- wp:group {"className":"post-kinds-watch-status-badge","style":{"spacing":{"padding":{"top":"4px","bottom":"4px","left":"12px","right":"12px"}},"border":{"radius":"4px"}},"backgroundColor":"tertiary","layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group post-kinds-watch-status-badge has-tertiary-background-color has-background" style="border-radius:4px;padding-top:4px;padding-right:12px;padding-bottom:4px;padding-left:12px">

				<!-- wp:paragraph {"fontSize":"small","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"watch_status"}}}}} -->
				<p class="has-small-font-size"></p>
				<!-- /wp:paragraph -->

			</div>
			<!-- /wp:group -->

		</div>
		<!-- /wp:column -->

	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"className":"post-kinds-spoiler-warning","style":{"spacing":{"padding":{"top":"var:preset|spacing|10","bottom":"var:preset|spacing|10","left":"var:preset|spacing|20","right":"var:preset|spacing|20"}},"border":{"radius":"4px"}},"backgroundColor":"vivid-red","textColor":"white","layout":{"type":"constrained"}} -->
	<div class="wp-block-group post-kinds-spoiler-warning has-white-color has-vivid-red-background-color has-text-color has-background" style="border-radius:4px;padding-top:var(--wp--preset--spacing--10);padding-right:var(--wp--preset--spacing--20);padding-bottom:var(--wp--preset--spacing--10);padding-left:var(--wp--preset--spacing--20)">

		<!-- wp:paragraph {"align":"center","fontSize":"small"} -->
		<p class="has-text-align-center has-small-font-size"><strong>Warning:</strong> This post may contain spoilers.</p>
		<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"e-content","layout":{"type":"constrained"}} -->
	<div class="wp-block-group e-content">

		<!-- wp:paragraph {"placeholder":"Add your review or thoughts about this film/show..."} -->
		<p></p>
		<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"post-kinds-meta","style":{"spacing":{"margin":{"top":"var:preset|spacing|20"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group post-kinds-meta" style="margin-top:var(--wp--preset--spacing--20)">

		<!-- wp:post-date {"className":"dt-published","fontSize":"small"} /-->

	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->',
	]
);
