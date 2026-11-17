<?php
/**
 * Dynamic Wrapped strip render.
 *
 * @package PKIW
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \PKIW\Wrapped_Blocks::render_strip( $attributes, $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes default output.
