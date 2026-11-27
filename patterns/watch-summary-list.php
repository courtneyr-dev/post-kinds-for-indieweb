<?php
/**
 * Watch Summary List Pattern
 *
 * Three List Item rows bound to Watch kind meta through the
 * post-kinds-indieweb/kind-meta source. Needs WordPress 7.1+, the first
 * core that binds List Item content.
 *
 * @package PKIW
 * @since   1.11.0
 */

declare(strict_types=1);

namespace PKIW\Patterns;

use PKIW\Block_Bindings;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Block_Bindings::list_items_bindable() ) {
	return;
}

$pkiw_rows = [
	'watch_title'  => __( 'Watch title', 'post-kinds-for-indieweb-in-block-themes' ),
	'watch_year'   => __( 'Watch year', 'post-kinds-for-indieweb-in-block-themes' ),
	'watch_status' => __( 'Watch status', 'post-kinds-for-indieweb-in-block-themes' ),
];

$pkiw_items = '';
foreach ( $pkiw_rows as $pkiw_key => $pkiw_fallback ) {
	$pkiw_attrs  = [
		'metadata' => [
			'bindings' => [
				'content' => [
					'source' => Block_Bindings::SOURCE_NAME,
					'args'   => [ 'key' => $pkiw_key ],
				],
			],
		],
	];
	$pkiw_items .= '<!-- wp:list-item ' . wp_json_encode( $pkiw_attrs, JSON_UNESCAPED_SLASHES ) . " -->\n"
		. '<li>' . esc_html( $pkiw_fallback ) . "</li>\n"
		. "<!-- /wp:list-item -->\n\n";
}

register_block_pattern(
	'post-kinds-indieweb/watch-summary-list',
	[
		'title'       => __( 'Watch Summary List', 'post-kinds-for-indieweb-in-block-themes' ),
		'description' => __( 'Title, year and watch status as a list, filled from the post’s Watch details.', 'post-kinds-for-indieweb-in-block-themes' ),
		'categories'  => [ 'post-kinds-for-indieweb-in-block-themes' ],
		'keywords'    => [ 'watch', 'film', 'summary', 'list', 'bindings' ],
		'blockTypes'  => [ 'core/list' ],
		'content'     => "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . rtrim( $pkiw_items ) . "</ul>\n<!-- /wp:list -->",
	]
);
