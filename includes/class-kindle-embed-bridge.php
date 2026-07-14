<?php
/**
 * Core/embed opt-in + server render bridge for Kindle previews.
 *
 * @package PKIW
 * @since   1.2.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the core amazon-kindle embed variation follow post meta.
 *
 * 1. Opens the Block Bindings allowlist so core/embed's url attribute
 *    can bind to post-kinds/kind-meta (editor-side affordance).
 * 2. render_block bridge: any core/embed carrying the
 *    pkiw-kindle-preview class has its wrapper replaced by an iframe
 *    whose src is the computed kindle_embed_url — saved markup never
 *    goes stale when completion later fills the ISBN/ASIN.
 *
 * @since 1.2.0
 */
class Kindle_Embed_Bridge {

	public const MARKER = 'pkiw-kindle-preview';

	/**
	 * Constructor. Hooks the bindings allowlist and the render bridge.
	 */
	public function __construct() {
		add_filter( 'block_bindings_supported_attributes', [ $this, 'allow_embed_url' ], 10, 2 );
		add_filter( 'render_block_core/embed', [ $this, 'render' ], 10, 3 );
	}

	/**
	 * Add core/embed's url attribute to the Block Bindings allowlist.
	 *
	 * @param string[] $attrs      Supported attribute names.
	 * @param string   $block_type Block type being filtered.
	 * @return string[]
	 */
	public function allow_embed_url( $attrs, $block_type ) {
		if ( 'core/embed' === $block_type && ! in_array( 'url', (array) $attrs, true ) ) {
			$attrs[] = 'url';
		}
		return $attrs;
	}

	/**
	 * Rewrite a marked core/embed's wrapper to an iframe sourced from meta.
	 *
	 * @param string    $content Rendered embed HTML.
	 * @param array     $block   Parsed block.
	 * @param \WP_Block $instance Block instance with context.
	 * @return string
	 */
	public function render( $content, $block, $instance ) {
		$class = $block['attrs']['className'] ?? '';
		if ( ! is_string( $class ) || ! preg_match( '/(?:^|\s)' . preg_quote( self::MARKER, '/' ) . '(?:\s|$)/', $class ) ) {
			return $content;
		}

		$post_id = $instance->context['postId'] ?? get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$asin = get_post_meta( (int) $post_id, Meta_Fields::PREFIX . 'read_asin', true );
		if ( ! is_string( $asin ) || '' === $asin ) {
			$isbn = get_post_meta( (int) $post_id, Meta_Fields::PREFIX . 'read_isbn', true );
			$asin = is_string( $isbn ) && '' !== $isbn ? (string) Isbn::to10( $isbn ) : '';
		}
		if ( '' === $asin ) {
			return $content; // Nothing derivable — leave the saved embed alone.
		}

		$iframe = sprintf(
			'<iframe src="%s" title="%s" width="336" height="550" loading="lazy" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>',
			esc_url( Isbn::kindle_embed_url( $asin ) ),
			esc_attr__( 'Kindle book preview', 'post-kinds-for-indieweb-in-block-themes' )
		);

		$replaced = preg_replace(
			'#<div class="wp-block-embed__wrapper">.*?</div>#s',
			'<div class="wp-block-embed__wrapper">' . $iframe . '</div>',
			$content,
			1
		);

		return $replaced ?? $content;
	}
}
