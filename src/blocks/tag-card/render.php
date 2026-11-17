<?php
/**
 * Dynamic Tag Card render.
 *
 * @package PKIW
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \PKIW\Tag_Archive::render_card( $attributes, \PKIW\Tag_Archive::resolve_owner( $block ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes each field.
