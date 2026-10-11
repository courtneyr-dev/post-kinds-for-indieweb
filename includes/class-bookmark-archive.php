<?php
/**
 * The bookmark kind archive: tag drawers, tag-filter requests and facts.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Term_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shelves the bookmark archive by post tags.
 *
 * The drawers are the grouped archive engine's post_tag source, so bookmarks
 * file under the first tag A to Z and untagged or protected posts file under
 * Other. On `/kind/bookmark/?tag=alpha`, the request keeps the bookmark kind
 * as the queried object while leaving the tag clause for the drawer source to
 * pin. Bookmark facts come from the first bookmark-card block, then cite meta
 * fallbacks for Quick Post and imports.
 *
 * @since 1.9.0
 */
final class Bookmark_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const KIND = 'bookmark';

	/**
	 * Group source id.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const SOURCE = 'bookmark_tags';

	/**
	 * Bookmark card block name.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const CARD_BLOCK = 'post-kinds-indieweb/bookmark-card';

	/**
	 * Register the source, facts reader and query hook.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Grouped_Archive::register_source( self::source(), self::KIND );
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_action( 'parse_query', [ $this, 'keep_kind_queried' ] );
	}

	/**
	 * The tag source the bookmark archive shelves by.
	 *
	 * @since 1.9.0
	 *
	 * @return Term_Source
	 */
	public static function source(): Term_Source {
		return new Term_Source( self::SOURCE, 'post_tag', null, '', true );
	}

	/**
	 * Keep `/kind/bookmark/?tag=x` as a bookmark archive, not a tag archive.
	 *
	 * @since 1.9.0
	 *
	 * @internal Hooked to `parse_query`.
	 *
	 * @param mixed $query Query.
	 * @return void
	 */
	public function keep_kind_queried( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || true !== $query->is_tag || true !== $query->is_tax ) {
			return;
		}
		if ( self::KIND !== (string) $query->get( Taxonomy::TAXONOMY ) ) {
			return;
		}

		$query->is_tag = false;
	}

	/**
	 * The facts a theme prints for a bookmark.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array{url: string, host: string, title: string, cited_title: string, author: string, note: string, rel: string, date: array{0: string, 1: string}, picture: array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool}, drawer: array{key: string, label: string}}
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return [
				'url'         => '',
				'host'        => '',
				'title'       => '',
				'cited_title' => '',
				'author'      => '',
				'note'        => '',
				'rel'         => '',
				'date'        => [ '', '' ],
				'picture'     => self::empty_picture(),
				'drawer'      => [
					'key'   => '',
					'label' => '',
				],
			];
		}

		$card        = self::card_attrs( $post );
		$url         = self::url( $post_id, $card );
		$cited_title = self::cited_title( $post_id, $card, $url['url'] );

		return [
			'url'         => $url['url'],
			'host'        => self::host( $post_id, $url ),
			'title'       => self::title( $post, $cited_title, $url['url'] ),
			'cited_title' => $cited_title,
			'author'      => self::card_or_meta( $post_id, $card, 'author', 'cite_author' ),
			'note'        => self::card_or_meta( $post_id, $card, 'description', 'cite_summary' ),
			'rel'         => self::rel( $card ),
			'date'        => self::date( $post, $card ),
			'picture'     => self::picture( $post_id, $card ),
			'drawer'      => self::drawer( $post_id ),
		];
	}

	/**
	 * The first bookmark-card block's string attributes.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, string>
	 */
	private static function card_attrs( \WP_Post $post ): array {
		foreach ( \PKIW\flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( self::CARD_BLOCK !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$out   = [];
			foreach ( [ 'title', 'url', 'description', 'image', 'imageAlt', 'author', 'bookmarkedAt', 'rel', 'layout' ] as $key ) {
				$out[ $key ] = is_string( $attrs[ $key ] ?? null ) ? (string) $attrs[ $key ] : '';
			}

			return $out;
		}

		return [];
	}

	/**
	 * The first accepted URL and where it came from.
	 *
	 * @since 1.9.0
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $card    Card attributes.
	 * @return array{url: string, source: string}
	 */
	private static function url( int $post_id, array $card ): array {
		$card_url = \PKIW\web_url( $card['url'] ?? '' );
		if ( '' !== $card_url ) {
			return [
				'url'    => $card_url,
				'source' => 'card',
			];
		}

		foreach ( [ 'bookmark_of', 'cite_url' ] as $suffix ) {
			$url = \PKIW\web_url( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, true ) );
			if ( '' !== $url ) {
				return [
					'url'    => $url,
					'source' => 'meta',
				];
			}
		}

		return [
			'url'    => '',
			'source' => '',
		];
	}

	/**
	 * The host for a bookmark URL.
	 *
	 * @since 1.9.0
	 *
	 * @param int                             $post_id Post ID.
	 * @param array{url:string,source:string} $url URL and source.
	 * @return string
	 */
	private static function host( int $post_id, array $url ): string {
		if ( '' === $url['url'] ) {
			return '';
		}

		// A card URL never syncs to cite host meta, so it must read its own host.
		if ( 'meta' === $url['source'] ) {
			$stored = get_post_meta( $post_id, CITE_HOST_META, true );
			if ( is_string( $stored ) && '' !== $stored ) {
				return $stored;
			}
		}

		return \PKIW\url_host( $url['url'] );
	}

	/**
	 * The citation title, excluding URL-shaped names.
	 *
	 * @since 1.9.0
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $card    Card attributes.
	 * @param string                $url     Bookmark URL.
	 * @return string
	 */
	private static function cited_title( int $post_id, array $card, string $url ): string {
		foreach ( [ $card['title'] ?? '', (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_name', true ) ] as $name ) {
			$name = trim( $name );
			if ( '' !== $name && ! \PKIW\card_name_is_url( $name, $url ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * The display title for the bookmark.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post        Post.
	 * @param string   $cited_title Citation title.
	 * @param string   $url         Bookmark URL.
	 * @return string
	 */
	private static function title( \WP_Post $post, string $cited_title, string $url ): string {
		if ( '' !== $cited_title ) {
			return $cited_title;
		}

		$title = trim( (string) $post->post_title );
		if ( '' !== $title && ! \PKIW\card_name_is_url( $title, $url ) ) {
			return $title;
		}

		return \PKIW\untitled_name( $post );
	}

	/**
	 * A trimmed card value, else a trimmed cite meta value.
	 *
	 * @since 1.9.0
	 *
	 * @param int                   $post_id     Post ID.
	 * @param array<string, string> $card        Card attributes.
	 * @param string                $card_key    Card attribute key.
	 * @param string                $meta_suffix Meta key suffix.
	 * @return string
	 */
	private static function card_or_meta( int $post_id, array $card, string $card_key, string $meta_suffix ): string {
		$value = trim( $card[ $card_key ] ?? '' );
		if ( '' !== $value ) {
			return $value;
		}

		return trim( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . $meta_suffix, true ) );
	}

	/**
	 * Rel tokens from the card.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, string> $card Card attributes.
	 * @return string
	 */
	private static function rel( array $card ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $card['rel'] ?? '' ) );
	}

	/**
	 * Bookmark date, from the card date or the post date.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post              $post Post.
	 * @param array<string, string> $card Card attributes.
	 * @return array{0: string, 1: string}
	 */
	private static function date( \WP_Post $post, array $card ): array {
		$date = \PKIW\card_calendar_date( $card['bookmarkedAt'] ?? '' );
		if ( '' !== $date[0] ) {
			return $date;
		}

		return [
			(string) mysql2date( 'Y-m-d', $post->post_date ),
			(string) mysql2date( (string) get_option( 'date_format' ), $post->post_date ),
		];
	}

	/**
	 * The bookmark picture in featured, card image, cite photo order.
	 *
	 * @since 1.9.0
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $card    Card attributes.
	 * @return array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool}
	 */
	private static function picture( int $post_id, array $card ): array {
		$picture = \PKIW\kind_picture( $post_id );
		if ( 'featured' === $picture['source'] ) {
			return $picture;
		}

		$url = \PKIW\web_url( $card['image'] ?? '' );
		if ( '' !== $url ) {
			$attachment_id = \PKIW\cover_local_copy( $post_id, $url );
			if ( $attachment_id > 0 ) {
				return [
					'source'            => 'cover',
					'attachment_id'     => $attachment_id,
					'url'               => (string) wp_get_attachment_url( $attachment_id ),
					'alt'               => $card['imageAlt'] ?? '',
					'remote'            => false,
					'suppress_featured' => false,
				];
			}

			return [
				'source'            => 'cover',
				'attachment_id'     => 0,
				'url'               => $url,
				'alt'               => $card['imageAlt'] ?? '',
				'remote'            => ! \PKIW\is_upload_url( $url ),
				'suppress_featured' => false,
			];
		}

		$picture['alt'] = '';

		return $picture;
	}

	/**
	 * Empty picture shape.
	 *
	 * @since 1.9.0
	 *
	 * @return array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool}
	 */
	private static function empty_picture(): array {
		return [
			'source'            => '',
			'attachment_id'     => 0,
			'url'               => '',
			'alt'               => '',
			'remote'            => false,
			'suppress_featured' => false,
		];
	}

	/**
	 * Bookmark drawer key and label.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array{key: string, label: string}
	 */
	private static function drawer( int $post_id ): array {
		$group = Grouped_Archive::group_of_post( self::KIND, $post_id );

		return [
			'key'   => null === $group ? '' : $group->key(),
			'label' => Grouped_Archive::group_label_of_post( self::KIND, $post_id ),
		];
	}
}
