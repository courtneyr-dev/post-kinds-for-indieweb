<?php
/**
 * The reply kind archive: facts for hidden citation metadata.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and reads the reply archive's public facts.
 *
 * Reply grouping comes from the shared cite-host source registered by
 * Kind_Archive_Layouts. This class only registers the facts reader themes
 * use to print reply citation details without reading card attributes.
 *
 * @since 1.9.0
 */
final class Reply_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'reply';

	/**
	 * Register the facts reader.
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
	}

	/**
	 * The public facts for a reply.
	 *
	 * @param int $post_id Post ID.
	 * @return array{url: string, host: string, cited_title: string, author: string, summary: string, picture: array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool}, name: string}|array{}
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
			return [];
		}

		$meta = static function ( string $key ) use ( $post_id ): string {
			$value = get_post_meta( $post_id, $key, true );

			return is_string( $value ) ? trim( $value ) : '';
		};

		$raw_cite  = $meta( Meta_Fields::PREFIX . 'cite_url' );
		$raw_reply = $meta( Meta_Fields::PREFIX . 'in_reply_to' );
		$url       = web_url( $raw_cite );
		if ( '' === $url ) {
			$url = web_url( $raw_reply );
		}

		$host = $meta( CITE_HOST_META );
		if ( '' === $host ) {
			$host = url_host( $url );
		}

		$raw   = '' !== $raw_cite ? $raw_cite : $raw_reply;
		$name  = $meta( Meta_Fields::PREFIX . 'cite_name' );
		$title = card_name_is_url( $name, $raw ) ? '' : $name;

		return [
			'url'         => $url,
			'host'        => $host,
			'cited_title' => $title,
			'author'      => $meta( Meta_Fields::PREFIX . 'cite_author' ),
			'summary'     => $meta( Meta_Fields::PREFIX . 'cite_summary' ),
			'picture'     => kind_picture( $post_id ),
			'name'        => '' !== trim( (string) $post->post_title ) ? trim( (string) $post->post_title ) : untitled_name( $post ),
		];
	}
}
