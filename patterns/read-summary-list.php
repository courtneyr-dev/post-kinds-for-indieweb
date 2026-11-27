<?php
/**
 * Read Summary List Pattern
 *
 * Three List Item rows bound to Read kind meta through the
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
	'read_title'  => __( 'Book title', 'post-kinds-for-indieweb-in-block-themes' ),
	'read_author' => __( 'Book author', 'post-kinds-for-indieweb-in-block-themes' ),
	'read_status' => __( 'Reading status', 'post-kinds-for-indieweb-in-block-themes' ),
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
	'post-kinds-indieweb/read-summary-list',
	[
		'title'       => __( 'Read Summary List', 'post-kinds-for-indieweb-in-block-themes' ),
		'description' => __( 'Book title, author and reading status as a list, filled from the post’s Read details.', 'post-kinds-for-indieweb-in-block-themes' ),
		'categories'  => [ 'post-kinds-for-indieweb-in-block-themes' ],
		'keywords'    => [ 'read', 'book', 'summary', 'list', 'bindings' ],
		'blockTypes'  => [ 'core/list' ],
		'content'     => "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . rtrim( $pkiw_items ) . "</ul>\n<!-- /wp:list -->",
	]
);
