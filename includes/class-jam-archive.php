<?php
/**
 * The jam kind archive facts reader for issue 235.
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
 * Normalizes jam facts and renders jam Stream cards.
 *
 * @since 1.9.0
 */
final class Jam_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const KIND = 'jam';

	/**
	 * Jam card block name.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const CARD = 'post-kinds-indieweb/jam-card';

	/**
	 * Register the facts reader and Stream card callback.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_filter( 'pkiw_stream_card_kind_render', [ self::class, 'stream_card' ], 10, 4 );
	}

	/**
	 * The public facts for a jam.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Jam facts, or [] when hidden.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
			return [];
		}

		$card     = self::first_card( $post );
		$has_card = null !== $card;
		$card     = $card ?? [];

		$title = self::plain( self::string_attr( $card, 'title' ) );
		if ( '' === $title ) {
			$title = self::plain( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'jam_track', true ) );
		}

		$artist = self::plain( self::string_attr( $card, 'artist' ) );
		if ( '' === $artist ) {
			$artist = self::plain( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'jam_artist', true ) );
		}

		$album = self::plain( self::string_attr( $card, 'album' ) );
		if ( '' === $album ) {
			$album = self::plain( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'jam_album', true ) );
		}

		$url        = \PKIW\web_url( self::string_attr( $card, 'url' ) );
		$url_source = '' !== $url ? 'card' : '';
		if ( '' === $url ) {
			$url        = \PKIW\web_url( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'jam_url', true ) );
			$url_source = '' !== $url ? 'meta' : '';
		}
		if ( '' === $url ) {
			$url        = self::content_url( (string) $post->post_content );
			$url_source = '' !== $url ? 'content' : '';
		}

		$cover_alt = self::plain( self::string_attr( $card, 'coverAlt' ) );
		if ( '' !== $title && '' !== $artist ) {
			$cover_alt_fallback = $title . ' by ' . $artist;
		} elseif ( '' !== $title ) {
			$cover_alt_fallback = $title;
		} else {
			$cover_alt_fallback = $artist;
		}

		[ $jammed, $jammed_display ] = \PKIW\card_calendar_date( self::string_attr( $card, 'jammedAt' ) );

		$date = $jammed;
		if ( '' === $date ) {
			$post_date = get_post_datetime( $post, 'date', 'local' );
			$date      = $post_date instanceof \DateTimeInterface ? $post_date->format( 'Y-m-d' ) : '';
		}
		$date_display = \PKIW\card_calendar_date( $date )[1];

		if ( '' !== $title ) {
			$name        = $title;
			$name_source = 'track';
		} elseif ( '' !== trim( (string) $post->post_title ) ) {
			$name        = \PKIW\visitor_post_title( $post );
			$name_source = 'title';
		} else {
			$name        = \PKIW\untitled_name( $post, false );
			$name_source = 'untitled';
		}

		$post_title = self::plain( \PKIW\visitor_post_title( $post ) );

		return [
			'has_card'           => $has_card,
			'title'              => $title,
			'artist'             => $artist,
			'album'              => $album,
			'url'                => $url,
			'url_source'         => $url_source,
			'note'               => self::plain( self::string_attr( $card, 'note' ) ),
			'rel'                => self::rel( self::string_attr( $card, 'rel' ) ),
			'cover_alt'          => $cover_alt,
			'cover_alt_fallback' => $cover_alt_fallback,
			'picture'            => self::picture( $post_id, $card, $cover_alt ),
			'jammed'             => $jammed,
			'jammed_display'     => $jammed_display,
			'date'               => $date,
			'date_display'       => $date_display,
			'date_is_stored'     => '' !== $jammed,
			'name'               => $name,
			'name_source'        => $name_source,
			// A hidden location title reads as its safe name, a synthetic one (X8).
			'name_is_title'      => 'title' === $name_source && ! \PKIW\post_title_is_hidden( $post ),
			'track_is_h1'        => '' !== $title && strtolower( trim( $title ) ) === strtolower( trim( $post_title ) ),
		];
	}

	/**
	 * Render a jam card for Stream and archive surfaces.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $html          Card HTML so far, null to fall through.
	 * @param mixed $post          Post.
	 * @param mixed $heading_level Heading level, 2 to 4.
	 * @param mixed $attributes    Block attributes. Unused.
	 * @return mixed Jam card HTML, else $html unchanged.
	 */
	public static function stream_card( $html, $post = null, $heading_level = 2, $attributes = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $post instanceof \WP_Post || self::KIND !== \PKIW\get_post_kind_slug( $post ) ) {
			return $html;
		}

		$facts = self::facts( $post->ID );
		if ( [] === $facts ) {
			return $html;
		}

		$level   = max( 2, min( 4, (int) $heading_level ) );
		$listing = Grouped_Archive::is_sectioning()
			|| Grouped_Archive::is_block_preview()
			|| is_tax( Taxonomy::TAXONOMY, self::KIND );
		$shape   = $listing ? 'pk-card--jam-listing' : 'pk-card--jam-stream';
		$name    = (string) $facts['name'];
		$artist  = (string) $facts['artist'];
		$link    = 'u-url' . ( 'track' === $facts['name_source'] || $facts['name_is_title'] ? ' p-name' : '' );
		$cite    = \PKIW\hidden_cite_html(
			'u-jam-of',
			[
				'p-name'   => (string) $facts['title'],
				'p-author' => $artist,
				'u-url'    => (string) $facts['url'],
			]
		);

		$out = '<article class="pk-card pk-card--stream pk-card--jam k-jam h-entry ' . $shape . '">';
		if ( $listing ) {
			$out .= self::listing_html( $post, $facts, $level, $link, $name, $artist );
		} else {
			$label = \PKIW\get_kind_label( __( 'Jam', 'post-kinds-for-indieweb-in-block-themes' ), self::KIND, 'jam-card' );
			$out  .= '<span class="pk-kindlabel">' . esc_html( $label ) . '</span>';
			$out  .= '<h' . $level . ' class="pk-title"><a class="' . esc_attr( $link ) . '" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $name ) . '</a></h' . $level . '>';
			if ( '' !== $artist ) {
				$out .= '<p class="pk-sub">' . esc_html( $artist ) . '</p>';
			}
			$out .= '<p class="pk-sub pk-stream-date"><time datetime="' . esc_attr( (string) $facts['date'] ) . '">' . esc_html( (string) $facts['date_display'] ) . '</time></p>';
		}

		return $out . $cite . '</article>';
	}

	/**
	 * Attributes from the first jam card in a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>|null Card attributes, or null.
	 */
	private static function first_card( \WP_Post $post ): ?array {
		foreach ( \PKIW\flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( self::CARD === ( $block['blockName'] ?? '' ) ) {
				return is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			}
		}

		return null;
	}

	/**
	 * Strip stored RichText down to plain text.
	 *
	 * @param string $raw       Stored raw value.
	 * @param bool   $multiline Whether line breaks become newlines before tags are stripped.
	 * @return string Plain text.
	 */
	private static function plain( string $raw, bool $multiline = false ): string {
		if ( $multiline ) {
			$raw = (string) preg_replace( '~<br\s*/?>~i', "\n", $raw );
		}

		return trim( html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * String value of a card attribute.
	 *
	 * @param array<string, mixed> $card Card attributes.
	 * @param string               $key  Attribute key.
	 * @return string Scalar value, or ''.
	 */
	private static function string_attr( array $card, string $key ): string {
		return is_scalar( $card[ $key ] ?? null ) ? (string) $card[ $key ] : '';
	}

	/**
	 * First safe Micropub jam URL in post content.
	 *
	 * @param string $content Post content.
	 * @return string URL, or ''.
	 */
	private static function content_url( string $content ): string {
		$processor = new \WP_HTML_Tag_Processor( $content );
		while ( $processor->next_tag( [ 'tag_name' => 'a' ] ) ) {
			if ( ! $processor->has_class( 'u-jam-of' ) ) {
				continue;
			}
			$url = \PKIW\web_url( (string) ( $processor->get_attribute( 'href' ) ?? '' ) );
			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Normalize stored link relation tokens.
	 *
	 * @param string $raw Stored relation string.
	 * @return string Safe unique relation tokens.
	 */
	private static function rel( string $raw ): string {
		$tokens = [];
		$parts  = preg_split( '/\s+/', strtolower( trim( $raw ) ) );
		foreach ( is_array( $parts ) ? $parts : [] as $token ) {
			$token = (string) preg_replace( '/[^a-z0-9-]/', '', $token );
			if ( '' !== $token ) {
				$tokens[ $token ] = true;
			}
		}

		return implode( ' ', array_keys( $tokens ) );
	}

	/**
	 * The jam picture in featured, card cover, then meta cover order.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $card     Card attributes.
	 * @param string               $cover_alt Stored card cover alt.
	 * @return array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool}
	 */
	private static function picture( int $post_id, array $card, string $cover_alt ): array {
		$picture = \PKIW\kind_picture( $post_id );
		if ( 'featured' === $picture['source'] ) {
			return $picture;
		}

		$url = \PKIW\web_url( self::string_attr( $card, 'cover' ) );
		if ( '' === $url ) {
			return $picture;
		}

		$attachment_id = \PKIW\cover_local_copy( $post_id, $url );
		if ( $attachment_id > 0 ) {
			return [
				'source'            => 'cover',
				'attachment_id'     => $attachment_id,
				'url'               => (string) wp_get_attachment_url( $attachment_id ),
				'alt'               => '' !== $cover_alt ? $cover_alt : \PKIW\attachment_alt( $attachment_id ),
				'remote'            => false,
				'suppress_featured' => false,
			];
		}

		return [
			'source'            => 'cover',
			'attachment_id'     => 0,
			'url'               => $url,
			'alt'               => $cover_alt,
			'remote'            => ! \PKIW\is_upload_url( $url ),
			'suppress_featured' => false,
		];
	}

	/**
	 * Listing-only jam markup.
	 *
	 * @param \WP_Post             $post   Post.
	 * @param array<string, mixed> $facts  Jam facts.
	 * @param int                  $level  Heading level.
	 * @param string               $link   Link classes.
	 * @param string               $name   Display name.
	 * @param string               $artist Artist name.
	 * @return string Listing markup.
	 */
	private static function listing_html( \WP_Post $post, array $facts, int $level, string $link, string $name, string $artist ): string {
		$out     = '';
		$picture = is_array( $facts['picture'] ?? null ) ? $facts['picture'] : [];
		$url     = is_scalar( $picture['url'] ?? null ) ? (string) $picture['url'] : '';
		if ( '' !== $url ) {
			$out .= '<div class="pk-media"><img class="pk-cover" src="' . esc_url( $url ) . '" alt="" loading="lazy" decoding="async" /></div>';
		}

		$title_id   = 'pkiw-jam-t-' . $post->ID;
		$artist_id  = 'pkiw-jam-a-' . $post->ID;
		$labelledby = $title_id . ( '' !== $artist ? ' ' . $artist_id : '' );
		$out       .= '<h' . $level . ' class="pk-title"><a class="' . esc_attr( $link ) . '" href="' . esc_url( (string) get_permalink( $post ) ) . '" aria-labelledby="' . esc_attr( $labelledby ) . '"><span id="' . esc_attr( $title_id ) . '">' . esc_html( $name ) . '</span></a></h' . $level . '>';
		if ( '' !== $artist ) {
			$out .= '<p class="pk-sub" id="' . esc_attr( $artist_id ) . '">' . esc_html( $artist ) . '</p>';
		}

		return $out;
	}
}
