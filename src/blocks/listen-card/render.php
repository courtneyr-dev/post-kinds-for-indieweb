<?php
/**
 * Listen Card Block - Server-side Render
 *
 * Passes save.js output through and appends oEmbed player when the
 * listen URL matches a WordPress-whitelisted provider.
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (save.js output).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$listen_url = $attributes['listenUrl'] ?? '';

if ( empty( $listen_url ) ) {
	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}

$embed = wp_oembed_get( $listen_url );

if ( ! $embed ) {
	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}

// Insert embed before the block wrapper's closing </div>.
$embed_html = '<div class="post-kinds-card__embed">' . $embed . '</div>';
$pos        = strrpos( $content, '</div>' );

if ( false !== $pos ) {
	echo substr( $content, 0, $pos ) . $embed_html . substr( $content, $pos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} else {
	echo $content . $embed_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
