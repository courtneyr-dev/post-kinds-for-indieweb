<?php
/**
 * Read Progress Pattern
 *
 * A block pattern for tracking book reading progress.
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
 * Register the Read Progress pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/read-progress',
	[
		'title'       => __( 'Read Progress', 'post-kinds-for-indieweb' ),
		'description' => __( 'Track your reading progress on a book.', 'post-kinds-for-indieweb' ),
		'categories'  => [ 'post-kinds-for-indieweb' ],
		'keywords'    => [ 'read', 'book', 'reading', 'progress', 'library', 'indieweb', 'reaction' ],
		'blockTypes'  => [ 'core/group' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:group {"className":"h-entry post-kinds-read","layout":{"type":"constrained"}} -->
<div class="wp-block-group h-entry post-kinds-read">

	<!-- wp:columns {"className":"h-cite u-read-of"} -->
	<div class="wp-block-columns h-cite u-read-of">

		<!-- wp:column {"width":"120px"} -->
		<div class="wp-block-column" style="flex-basis:120px">

			<!-- wp:image {"className":"u-photo post-kinds-read-cover","width":"120px","height":"180px","scale":"cover","metadata":{"bindings":{"url":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_cover"}},"alt":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_title"}}}}} -->
			<figure class="wp-block-image is-resized u-photo post-kinds-read-cover"><img src="" alt="" style="object-fit:cover;width:120px;height:180px"/></figure>
			<!-- /wp:image -->

		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">

			<!-- wp:heading {"level":2,"className":"p-name","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_title"}}}}} -->
			<h2 class="wp-block-heading p-name"></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"className":"p-author","fontSize":"medium","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_author"}}}}} -->
			<p class="p-author has-medium-font-size"></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"className":"u-uid post-kinds-read-isbn","fontSize":"small","textColor":"secondary"} -->
			<p class="u-uid post-kinds-read-isbn has-secondary-color has-text-color has-small-font-size">ISBN: <span data-binding="read_isbn"></span></p>
			<!-- /wp:paragraph -->

			<!-- wp:group {"className":"post-kinds-read-status-badge","style":{"spacing":{"padding":{"top":"4px","bottom":"4px","left":"12px","right":"12px"}},"border":{"radius":"4px"}},"backgroundColor":"tertiary","layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group post-kinds-read-status-badge has-tertiary-background-color has-background" style="border-radius:4px;padding-top:4px;padding-right:12px;padding-bottom:4px;padding-left:12px">

				<!-- wp:paragraph {"fontSize":"small","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_status"}}}}} -->
				<p class="has-small-font-size"></p>
				<!-- /wp:paragraph -->

			</div>
			<!-- /wp:group -->

		</div>
		<!-- /wp:column -->

	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"className":"post-kinds-read-progress","style":{"spacing":{"padding":{"top":"var:preset|spacing|10","bottom":"var:preset|spacing|10"}}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group post-kinds-read-progress" style="padding-top:var(--wp--preset--spacing--10);padding-bottom:var(--wp--preset--spacing--10)">

		<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
		<div class="wp-block-group">

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><strong>Progress:</strong></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"className":"p-reading-progress","fontSize":"small","metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_progress_display"}}}}} -->
			<p class="p-reading-progress has-small-font-size"></p>
			<!-- /wp:paragraph -->

		</div>
		<!-- /wp:group -->

		<!-- wp:separator {"className":"post-kinds-progress-bar","style":{"color":{"background":"#007cba"}},"opacity":"css"} -->
		<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background post-kinds-progress-bar" style="background-color:#007cba;color:#007cba"/>
		<!-- /wp:separator -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"e-content","layout":{"type":"constrained"}} -->
	<div class="wp-block-group e-content">

		<!-- wp:paragraph {"placeholder":"Add your reading notes or thoughts..."} -->
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
