<?php
/**
 * The repost archive facts reader.
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
 * Registers the repost facts reader for issue 255.
 *
 * The post_month group source is registered once by Kind_Archive_Layouts.
 */
final class Repost_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'repost';

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
	 * A repost's public citation facts.
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

		$meta = static function ( string $suffix ) use ( $post_id ): string {
			$value = get_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, true );

			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};

		$raw = '';
		foreach ( [ 'cite_url', 'repost_of' ] as $suffix ) {
			$value = $meta( $suffix );
			if ( '' !== $value ) {
				$raw = $value;
				break;
			}
		}

		$url           = web_url( $raw );
		$name          = $meta( 'cite_name' );
		$cited_title   = card_name_is_url( $name, $raw ) ? '' : $name;
		$host          = get_post_meta( $post_id, CITE_HOST_META, true );
		$host          = is_scalar( $host ) ? trim( (string) $host ) : '';
		$card_image    = web_url( $meta( 'cite_photo' ) );
		$archive_name  = '';
		$name_is_title = false;

		if ( '' === $host ) {
			$host = url_host( $url );
		}

		if ( '' !== $cited_title ) {
			$archive_name = $cited_title;
		} elseif ( '' !== trim( $post->post_title ) ) {
			$archive_name  = get_the_title( $post );
			$name_is_title = true;
		} elseif ( '' !== $host ) {
			$archive_name = $host;
		} else {
			$archive_name = untitled_name( $post, false );
		}

		return [
			'url'           => $url,
			'host'          => $host,
			'cited_title'   => $cited_title,
			'author'        => $meta( 'cite_author' ),
			'summary'       => $meta( 'cite_summary' ),
			'picture'       => kind_picture( $post_id ),
			'card_image'    => $card_image,
			'name'          => $archive_name,
			'name_is_title' => $name_is_title,
		];
	}
}
