<?php
/**
 * Kind facts and the kind picture, for themes.
 *
 * A theme's kind adapters read these, never block attributes or raw meta.
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
 * The public facts for a kind post.
 *
 * @since 1.9.0
 *
 * @see Kind_Facts::get()
 *
 * @param int $post_id Post ID.
 * @return array<string, mixed> Facts, or [] when the post's kind has no reader or the post can't be shown.
 */
function kind_facts( int $post_id ): array {
	return Kind_Facts::get( $post_id );
}

/**
 * Where each kind keeps its picture: kind slug => meta suffixes.
 *
 * `url` holds the cover or photo URL and `alt` its stored alt text ('' when
 * the kind stores none). Only meta a card, Quick Post or an import writes
 * belongs here; a kind with no entry has no cover.
 *
 * @since 1.9.0
 *
 * @return array<string, array{url: string, alt: string}>
 */
function kind_picture_sources(): array {
	$sources = [];
	foreach ( Kind_Artwork::KIND_IMAGE_META as $kind => $suffix ) {
		$sources[ $kind ] = [
			'url' => $suffix,
			'alt' => '',
		];
	}

	$sources += [
		'comics'      => [
			'url' => 'comic_cover',
			'alt' => 'comic_cover_alt',
		],
		'wish'        => [
			'url' => 'wish_photo',
			'alt' => '',
		],
		'acquisition' => [
			'url' => 'acquisition_photo',
			'alt' => '',
		],
		'eat'         => [
			'url' => 'eat_photo',
			'alt' => '',
		],
		'drink'       => [
			'url' => 'drink_photo',
			'alt' => '',
		],
		'bookmark'    => [
			'url' => 'cite_photo',
			'alt' => '',
		],
		'like'        => [
			'url' => 'cite_photo',
			'alt' => '',
		],
		'reply'       => [
			'url' => 'cite_photo',
			'alt' => '',
		],
		'repost'      => [
			'url' => 'cite_photo',
			'alt' => '',
		],
	];

	/**
	 * Filters where each kind keeps its picture.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, array{url: string, alt: string}> $sources Kind slug => meta suffixes.
	 */
	return (array) apply_filters( 'pkiw_kind_picture_sources', $sources );
}

/**
 * The one picture a kind post shows, featured image first.
 *
 * The featured image when the post has one, which is often the local copy
 * Featured_Artwork made of the cover. Otherwise the cover URL the card
 * synced to meta: local when it is in the media library or Featured_Artwork
 * sideloaded that same URL, remote only when there's no local copy.
 *
 * `suppress_featured` is true when the picture is the featured image, so a
 * template's Featured Image block next to an object that prints this
 * picture would print it twice. Alt is the stored text verbatim (the card's
 * stored alt, else the attachment's), or '' when none is stored.
 *
 * @since 1.9.0
 *
 * @param int $post_id Post ID.
 * @return array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool}
 *         `source` is 'featured', 'cover' or '' for no picture.
 */
function kind_picture( int $post_id ): array {
	$picture = [
		'source'            => '',
		'attachment_id'     => 0,
		'url'               => '',
		'alt'               => '',
		'remote'            => false,
		'suppress_featured' => false,
	];

	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
		return $picture;
	}

	$thumbnail_id = (int) get_post_thumbnail_id( $post );
	if ( $thumbnail_id > 0 && wp_attachment_is_image( $thumbnail_id ) ) {
		return [
			'source'            => 'featured',
			'attachment_id'     => $thumbnail_id,
			'url'               => (string) wp_get_attachment_url( $thumbnail_id ),
			'alt'               => attachment_alt( $thumbnail_id ),
			'remote'            => false,
			'suppress_featured' => true,
		];
	}

	$source = kind_picture_sources()[ Kind_Facts::kind_of( $post ) ] ?? null;
	if ( null === $source ) {
		return $picture;
	}

	$url = web_url( (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . $source['url'], true ) );
	if ( '' === $url ) {
		return $picture;
	}

	$alt = '' === $source['alt'] ? '' : trim( (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . $source['alt'], true ) );

	$local_id = cover_local_copy( $post->ID, $url );
	if ( $local_id > 0 ) {
		return array_merge(
			$picture,
			[
				'source'        => 'cover',
				'attachment_id' => $local_id,
				'url'           => (string) wp_get_attachment_url( $local_id ),
				'alt'           => '' !== $alt ? $alt : attachment_alt( $local_id ),
			]
		);
	}

	return array_merge(
		$picture,
		[
			'source' => 'cover',
			'url'    => $url,
			'alt'    => $alt,
			'remote' => ! is_upload_url( $url ),
		]
	);
}

/**
 * The media library image that holds a cover, if any.
 *
 * Either the cover URL is an upload on this site, or Featured_Artwork
 * sideloaded this same URL for the post (its copy stays in the library
 * after the featured image is removed).
 *
 * @since 1.9.0
 *
 * @param int    $post_id Post ID.
 * @param string $url     Cover URL.
 * @return int Attachment ID, or 0.
 */
function cover_local_copy( int $post_id, string $url ): int {
	if ( is_upload_url( $url ) ) {
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
			return $attachment_id;
		}
	}

	if ( (string) get_post_meta( $post_id, Featured_Artwork::SOURCE_META, true ) === $url ) {
		$attachment_id = (int) get_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META, true );
		if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
			return $attachment_id;
		}
	}

	return 0;
}

/**
 * An absolute http(s) URL with a host, else ''.
 *
 * Kind_Artwork::validate_image_url() also resolves the host in DNS, which
 * guards a server-side fetch; a page only prints this URL, so it skips that.
 *
 * @since 1.9.0
 *
 * @param string $url Stored URL.
 * @return string
 */
function web_url( string $url ): string {
	$url = esc_url_raw( trim( $url ), [ 'http', 'https' ] );

	return '' !== $url && '' !== url_host( $url ) ? $url : '';
}

/**
 * Whether a URL points into this site's uploads directory.
 *
 * @since 1.9.0
 *
 * @param string $url URL.
 * @return bool
 */
function is_upload_url( string $url ): bool {
	$base = (string) ( wp_get_upload_dir()['baseurl'] ?? '' );
	if ( '' === $base ) {
		return false;
	}

	return str_starts_with( set_url_scheme( $url, 'https' ), trailingslashit( set_url_scheme( $base, 'https' ) ) );
}

/**
 * An attachment's stored alt text.
 *
 * @since 1.9.0
 *
 * @param int $attachment_id Attachment ID.
 * @return string Alt text, or ''.
 */
function attachment_alt( int $attachment_id ): string {
	return trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
}
