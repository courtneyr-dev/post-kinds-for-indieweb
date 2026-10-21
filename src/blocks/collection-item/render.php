<?php
/**
 * Collection item dynamic block render.
 *
 * @package PKIW
 */

echo \PKIW\Collection_Items::render_item( $attributes, $content, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The renderer escapes each stored value.
