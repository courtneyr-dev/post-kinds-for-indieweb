<?php
/**
 * Pattern Overrides for the Reply Card.
 *
 * @package PKIW
 * @since   1.11.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Splits a synced Reply pattern's card between three owners.
 *
 * The pattern owns layout and style. Each pattern instance owns the
 * display text in DISPLAY_TEXT, stored in its core/block `content`. Each
 * post owns its kind term and the reply target and author in POST_DATA,
 * so two posts that insert the same pattern cite their own targets.
 *
 * @since 1.11.0
 */
final class Reply_Pattern_Overrides {

	public const BLOCK = 'post-kinds-indieweb/reply-card';

	/**
	 * Attributes a pattern instance may override.
	 *
	 * @var string[]
	 */
	public const DISPLAY_TEXT = [ 'title', 'description', 'quotation' ];

	/**
	 * Attributes the post supplies, keyed to their Meta_Fields suffix.
	 *
	 * @var array<string, string>
	 */
	public const POST_DATA = [
		'url'    => 'cite_url',
		'author' => 'cite_author',
	];

	/**
	 * Offer the display text to Block Bindings, which Pattern Overrides use
	 * on the server and in the editor.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'block_bindings_supported_attributes_' . self::BLOCK, [ self::class, 'supported_attributes' ] );
	}

	/**
	 * Add the display text to the card's bindable attributes.
	 *
	 * @param string[] $attributes Bindable attribute names.
	 * @return string[]
	 */
	public static function supported_attributes( $attributes ): array {
		return array_values( array_unique( array_merge( (array) $attributes, self::DISPLAY_TEXT ) ) );
	}

	/**
	 * The attributes a card renders. A non-string bound value falls back to
	 * the schema-validated attribute. A card with Pattern Overrides inside a
	 * pattern takes POST_DATA from the post that renders it, never from the
	 * pattern, and blank when the viewer may not see that post.
	 *
	 * @param array<string, mixed> $attributes Block attributes after bindings.
	 * @param \WP_Block            $block      Block instance.
	 * @return array<string, mixed>
	 */
	public static function render_attributes( array $attributes, \WP_Block $block ): array {
		foreach ( self::DISPLAY_TEXT as $attribute ) {
			if ( isset( $attributes[ $attribute ] ) && ! is_string( $attributes[ $attribute ] ) ) {
				$attributes[ $attribute ] = $block->block_type->prepare_attributes_for_render( $block->parsed_block['attrs'] )[ $attribute ] ?? '';
			}
		}

		$bindings = $attributes['metadata']['bindings'] ?? [];
		if (
			! is_array( $bindings )
			|| ! in_array( 'core/pattern-overrides', array_column( $bindings, 'source' ), true )
			|| ! array_key_exists( 'pattern/overrides', $block->context )
		) {
			return $attributes;
		}

		$post    = get_post( (int) ( $block->context['postId'] ?? get_the_ID() ) );
		$visible = $post instanceof \WP_Post && Kind_Facts::can_show( $post );
		foreach ( self::POST_DATA as $attribute => $suffix ) {
			$attributes[ $attribute ] = $visible ? (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . $suffix, true ) : '';
		}

		return $attributes;
	}
}
