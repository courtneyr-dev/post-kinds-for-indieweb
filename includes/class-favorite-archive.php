<?php
/**
 * The favorite archive facts reader for issue 259.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the favorite facts reader for issue 259.
 *
 * The month Date_Source is registered by the shared month archive wiring.
 *
 * @since 1.9.0
 */
final class Favorite_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const KIND = 'favorite';

	/**
	 * Favorite card block name.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const CARD_BLOCK = 'post-kinds-indieweb/favorite-card';

	/**
	 * Register the facts reader.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
	}

	/**
	 * The facts a theme prints for a favorite.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Facts, or [] when the post can't be shown.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
			return [];
		}

		$card        = self::card_attrs( $post );
		$url         = self::url( $post_id, $card );
		$cited_title = self::cited_title( $post_id, $card, $url );
		$title       = trim( Title_Privacy::safe_title( $post_id ) );
		if ( '' !== $title && ! card_name_is_url( $title, $url ) ) {
			$name          = $title;
			$name_is_title = true;
		} else {
			$name          = untitled_name( $post );
			$name_is_title = false;
		}

		return [
			'has_card'      => $card['has_card'],
			'cited_title'   => $cited_title,
			'url'           => $url,
			'host'          => url_host( $url ),
			'author'        => self::author( $post_id, $card ),
			'summary'       => trim( $card['description'] ),
			'rel'           => self::rel( $card ),
			'favorited'     => card_calendar_date( $card['favoritedAt'] ),
			'picture'       => self::picture( $post_id, $card ),
			'name'          => $name,
			'name_is_title' => $name_is_title,
		];
	}

	/**
	 * The first favorite-card block's string attributes.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post Post.
	 * @return array{has_card: bool, title: string, url: string, description: string, image: string, imageAlt: string, author: string, favoritedAt: string, rel: string}
	 */
	private static function card_attrs( \WP_Post $post ): array {
		$empty = [
			'has_card'    => false,
			'title'       => '',
			'url'         => '',
			'description' => '',
			'image'       => '',
			'imageAlt'    => '',
			'author'      => '',
			'favoritedAt' => '',
			'rel'         => '',
		];

		foreach ( \PKIW\flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( self::CARD_BLOCK !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$out   = $empty;
			foreach ( [ 'title', 'url', 'description', 'image', 'imageAlt', 'author', 'favoritedAt', 'rel' ] as $key ) {
				$out[ $key ] = is_string( $attrs[ $key ] ?? null ) ? (string) $attrs[ $key ] : '';
			}
			$out['has_card'] = true;

			return $out;
		}

		return $empty;
	}

	/**
	 * The first accepted target URL.
	 *
	 * @since 1.9.0
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $card    Card attributes.
	 * @return string URL, or ''.
	 */
	private static function url( int $post_id, array $card ): string {
		$card_url = web_url( $card['url'] );
		if ( '' !== $card_url ) {
			return $card_url;
		}

		foreach ( [ 'favorite_url', 'cite_url' ] as $suffix ) {
			$url = web_url( self::meta( $post_id, $suffix ) );
			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * The citation title, excluding URL-shaped names.
	 *
	 * @since 1.9.0
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $card    Card attributes.
	 * @param string               $url     Favorite URL.
	 * @return string Citation title, or ''.
	 */
	private static function cited_title( int $post_id, array $card, string $url ): string {
		foreach ( [ $card['title'], self::meta( $post_id, 'favorite_name' ), self::meta( $post_id, 'cite_name' ) ] as $name ) {
			$name = trim( $name );
			if ( '' !== $name && ! card_name_is_url( $name, $url ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * The favorite's cited author.
	 *
	 * @since 1.9.0
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $card    Card attributes.
	 * @return string Author, or ''.
	 */
	private static function author( int $post_id, array $card ): string {
		$author = trim( $card['author'] );
		if ( '' !== $author ) {
			return $author;
		}

		return trim( self::meta( $post_id, 'cite_author' ) );
	}

	/**
	 * Safe rel tokens from the card.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $card Card attributes.
	 * @return string Safe rel tokens.
	 */
	private static function rel( array $card ): string {
		$tokens = preg_split( '/\s+/', trim( $card['rel'] ) );
		if ( false === $tokens ) {
			return '';
		}

		$out = [];
		foreach ( $tokens as $token ) {
			$token = strtolower( $token );
			if ( 1 !== preg_match( '/^[a-z][a-z0-9-]*$/', $token ) || isset( $out[ $token ] ) ) {
				continue;
			}
			$out[ $token ] = true;
		}

		return implode( ' ', array_keys( $out ) );
	}

	/**
	 * The favorite picture in featured, card image order.
	 *
	 * @since 1.9.0
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $card    Card attributes.
	 * @return array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool}
	 */
	private static function picture( int $post_id, array $card ): array {
		$picture = kind_picture( $post_id );
		if ( 'featured' === $picture['source'] ) {
			return $picture;
		}

		$image = web_url( $card['image'] );
		if ( '' === $image ) {
			return $picture;
		}

		$is_upload     = is_upload_url( $image );
		$attachment_id = $is_upload ? upload_attachment_id( $image ) : 0;
		if ( $attachment_id > 0 && ! wp_attachment_is_image( $attachment_id ) ) {
			$attachment_id = 0;
		}

		return [
			'source'            => 'card',
			'attachment_id'     => $attachment_id,
			'url'               => $attachment_id > 0 ? (string) wp_get_attachment_url( $attachment_id ) : $image,
			'alt'               => $card['imageAlt'],
			'remote'            => ! $is_upload,
			'suppress_featured' => false,
		];
	}

	/**
	 * A trimmed scalar post meta value.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $suffix  Meta key suffix.
	 * @return string Meta value, or ''.
	 */
	private static function meta( int $post_id, string $suffix ): string {
		$value = get_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
