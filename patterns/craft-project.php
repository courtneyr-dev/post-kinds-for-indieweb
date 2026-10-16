<?php
/**
 * Craft Project Pattern
 *
 * A block pattern for a craft project: the craft card, a Materials list, a
 * Tools list and the numbered Steps. Every block stays editable and
 * deletable in place; headings are the only copy.
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW\Patterns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pkiw_materials = esc_html__( 'Materials', 'post-kinds-for-indieweb-in-block-themes' );
$pkiw_tools     = esc_html__( 'Tools', 'post-kinds-for-indieweb-in-block-themes' );
$pkiw_steps     = esc_html__( 'Steps', 'post-kinds-for-indieweb-in-block-themes' );

/**
 * Register the Craft project pattern.
 */
register_block_pattern(
	'post-kinds-indieweb/craft-project',
	[
		'title'       => __( 'Craft project', 'post-kinds-for-indieweb-in-block-themes' ),
		'description' => __( 'A craft card with Materials, Tools and numbered Steps.', 'post-kinds-for-indieweb-in-block-themes' ),
		'categories'  => [ 'post-kinds-for-indieweb-in-block-themes' ],
		'keywords'    => [ 'craft', 'make', 'project', 'steps', 'materials', 'tools', 'indieweb' ],
		'postTypes'   => [ 'post' ],
		'content'     => '<!-- wp:post-kinds-indieweb/craft-card /-->

<!-- wp:group {"className":"pkiw-craft-section pkiw-craft-section--materials"} -->
<div class="wp-block-group pkiw-craft-section pkiw-craft-section--materials"><!-- wp:heading -->
<h2 class="wp-block-heading">' . $pkiw_materials . '</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"pkiw-craft-section pkiw-craft-section--tools"} -->
<div class="wp-block-group pkiw-craft-section pkiw-craft-section--tools"><!-- wp:heading -->
<h2 class="wp-block-heading">' . $pkiw_tools . '</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"pkiw-craft-section pkiw-craft-section--steps"} -->
<div class="wp-block-group pkiw-craft-section pkiw-craft-section--steps"><!-- wp:heading -->
<h2 class="wp-block-heading">' . $pkiw_steps . '</h2>
<!-- /wp:heading -->

<!-- wp:post-kinds-indieweb/steps -->
<ol class="wp-block-post-kinds-indieweb-steps pkiw-craft-steps"><!-- wp:post-kinds-indieweb/step -->
<li class="wp-block-post-kinds-indieweb-step pkiw-craft-step"></li>
<!-- /wp:post-kinds-indieweb/step --></ol>
<!-- /wp:post-kinds-indieweb/steps --></div>
<!-- /wp:group -->',
	]
);
