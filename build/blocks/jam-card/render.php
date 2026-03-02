<?php
/**
 * Jam Card Block - Server-side Render
 *
 * Passes save.js output through and appends oEmbed player when the
 * URL matches a WordPress-whitelisted provider.
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (save.js output).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pkiw_url = $attributes['url'] ?? '';

if ( empty( $pkiw_url ) ) {
	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}

$pkiw_embed = wp_oembed_get( $pkiw_url );

if ( ! $pkiw_embed ) {
	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}

// Insert embed before the block wrapper's closing </div>.
$pkiw_embed_html = '<div class="post-kinds-card__embed">' . $pkiw_embed . '</div>';
$pkiw_pos        = strrpos( $content, '</div>' );

if ( false !== $pkiw_pos ) {
	echo substr( $content, 0, $pkiw_pos ) . $pkiw_embed_html . substr( $content, $pkiw_pos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} else {
	echo $content . $pkiw_embed_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
