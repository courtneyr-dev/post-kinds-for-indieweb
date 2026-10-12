<?php
/**
 * The photo archive facts reader.
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
 * Registers the photo facts reader and front-end integrations for issue 241.
 */
final class Photo_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'photo';

	/**
	 * Register the facts reader and photo single integrations.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ], [] );
		( new Photo_Projector() )->register();
		( new Photo_Location() )->register();
	}

	/**
	 * A photo's public display facts.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array{has_title:bool,title:string,name:string,date:string,date_iso:string,lead:array{attachment_id:int,alt:string,caption:string,source:string},featured_in_content:bool}|array{} Facts, or [] when the post can't be shown.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
			return [];
		}

		$has_title = '' !== trim( (string) $post->post_title );
		$title     = $has_title ? Title_Privacy::safe_title( $post->ID ) : '';

		return [
			'has_title'           => $has_title,
			'title'               => $title,
			'name'                => '' !== $title ? $title : untitled_name( $post, false ),
			'date'                => (string) get_the_date( '', $post ),
			'date_iso'            => (string) get_post_time( 'c', true, $post ),
			'lead'                => lead_image( $post->ID ),
			'featured_in_content' => featured_in_content( $post->ID ),
		];
	}
}
