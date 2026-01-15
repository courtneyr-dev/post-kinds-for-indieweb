<?php
/**
 * Listen Log Pattern
 *
 * A block pattern for logging music or podcast listens (scrobbling).
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
 * Register the Listen Log pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/listen-log',
	[
		'title'       => __( 'Listen Log', 'post-kinds-for-indieweb' ),
		'description' => __( 'Log a music track or podcast episode you listened to.', 'post-kinds-for-indieweb' ),
		'categories'  => [ 'post-kinds-for-indieweb' ],
		'keywords'    => [ 'listen', 'music', 'scrobble', 'podcast', 'audio', 'indieweb', 'reaction' ],
		'blockTypes'  => [ 'core/group' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:group {"className":"h-entry post-kinds-listen","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry post-kinds-listen">

	<!-- wp:columns {"className":"h-cite u-listen-of"} -->
	<div class="wp-block-columns h-cite u-listen-of">

		<!-- wp:column {"width":"120px"} -->
		<div class="wp-block-column" style="flex-basis:120px">

			<!-- wp:image {"className":"u-photo post-kinds-listen-cover","width":"120px","height":"120px","scale":"cover","metadata":{"bindings":{"url":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"listen_cover"}},"alt":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"listen_album"}}}}} -->
			<figure class="wp-block-image is-resized u-photo post-kinds-listen-cover"><img src="" alt="" style="object-fit:cover;width:120px;height:120px"/></figure>
			<!-- /wp:image -->

		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">

			<!-- wp:heading {"level":2,"className":"p-name","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"listen_track"}}}}} -->
			<h2 class="wp-block-heading p-name"></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"className":"p-author h-card","fontSize":"medium","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"listen_artist"}}}}} -->
			<p class="p-author h-card has-medium-font-size"></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"className":"post-kinds-listen-album","style":{"typography":{"fontStyle":"italic","fontWeight":"400"}},"textColor":"secondary","fontSize":"small","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"listen_album"}}}}} -->
			<p class="post-kinds-listen-album has-secondary-color has-text-color has-small-font-size" style="font-style:italic;font-weight:400"></p>
			<!-- /wp:paragraph -->

		</div>
		<!-- /wp:column -->

	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"className":"e-content","layout":{"type":"constrained"}} -->
	<div class="wp-block-group e-content">

		<!-- wp:paragraph {"placeholder":"Add your thoughts about this track (optional)..."} -->
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
