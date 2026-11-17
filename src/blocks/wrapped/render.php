<?php
/**
 * Dynamic Wrapped block render.
 *
 * @package PKIW
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \PKIW\Wrapped_Blocks::render_wrapped( $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes output.
