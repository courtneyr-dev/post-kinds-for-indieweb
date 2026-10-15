<?php
/**
 * The acquisition kind archive facts reader for issue 239.
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
 * Normalizes acquisition card facts for templates and Stream citations.
 *
 * Acquisition archives are grouped by Kind_Archive_Layouts' shared month
 * source. This class registers only the facts reader and hidden Stream cite
 * callback for issue 239.
 *
 * @since 1.9.0
 */
final class Acquisition_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const KIND = 'acquisition';

	/**
	 * Acquisition card block name.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const CARD = 'post-kinds-indieweb/acquisition-card';

	/**
	 * Register the facts reader and Stream citation callback.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_filter( 'pkiw_stream_card_kind_cite', [ self::class, 'cite_map' ] );
	}

	/**
	 * Acquisition type labels in display order.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return [
			'purchase'  => __( 'Purchase', 'post-kinds-for-indieweb-in-block-themes' ),
			'gift'      => __( 'Gift', 'post-kinds-for-indieweb-in-block-themes' ),
			'found'     => __( 'Found', 'post-kinds-for-indieweb-in-block-themes' ),
			'won'       => __( 'Won', 'post-kinds-for-indieweb-in-block-themes' ),
			'trade'     => __( 'Trade', 'post-kinds-for-indieweb-in-block-themes' ),
			'free'      => __( 'Free', 'post-kinds-for-indieweb-in-block-themes' ),
			'inherited' => __( 'Inherited', 'post-kinds-for-indieweb-in-block-themes' ),
			'other'     => __( 'Other', 'post-kinds-for-indieweb-in-block-themes' ),
		];
	}

	/**
	 * Attributes from the first acquisition card in a post.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null Card attributes with block defaults, or null.
	 */
	public static function card_attrs( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		foreach ( \PKIW\flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( self::CARD !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			return array_merge(
				[
					'acquisitionType'  => 'purchase',
					'showCostPublicly' => false,
				],
				$attrs
			);
		}

		return null;
	}

	/**
	 * Strip stored RichText down to plain text.
	 *
	 * @since 1.9.0
	 *
	 * @param string $raw       Stored raw value.
	 * @param bool   $multiline Whether line breaks become newlines before tags are stripped.
	 * @return string Plain text.
	 */
	public static function plain( string $raw, bool $multiline = false ): string {
		if ( $multiline ) {
			$raw = (string) preg_replace( '~<br\s*/?>~i', "\n", $raw );
		}

		return trim( html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * The facts a theme prints for an acquisition.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Acquisition facts, or [] when hidden.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
			return [];
		}

		$card     = self::card_attrs( $post_id );
		$has_card = null !== $card;
		$title    = $has_card ? self::plain( self::string_attr( $card, 'title' ) ) : '';
		if ( '' === $title ) {
			$title = self::plain( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'acquisition_name', true ) );
		}

		$type       = $has_card ? self::string_attr( $card, 'acquisitionType' ) : '';
		$type_label = '';
		if ( $has_card ) {
			$label      = self::type_labels()[ $type ] ?? ( '' !== $type ? $type : __( 'Acquired', 'post-kinds-for-indieweb-in-block-themes' ) );
			$type_label = \PKIW\get_kind_label( $label, self::KIND, 'acquisition-card' );
		}

		[ $acquired_at, $acquired_display ] = $has_card
			? \PKIW\card_calendar_date( self::string_attr( $card, 'acquiredAt' ) )
			: [ '', '' ];

		$cost = '';
		if ( $has_card && ! empty( $card['showCostPublicly'] ) && \PKIW\Meta_Fields::acquisition_cost_visible( $post_id ) ) {
			$cost = trim( self::string_attr( $card, 'cost' ) );
		}

		$name          = trim( (string) $post->post_title );
		$name_is_title = '' !== $name;
		if ( $name_is_title ) {
			$name = get_the_title( $post );
		} elseif ( '' !== $title ) {
			$name = $title;
		} else {
			$name = \PKIW\untitled_name( $post, false );
		}

		return [
			'has_card'         => $has_card,
			'title'            => $title,
			'type'             => $type,
			'type_label'       => $type_label,
			'where'            => $has_card ? self::plain( self::string_attr( $card, 'where' ) ) : '',
			'where_url'        => $has_card ? \PKIW\web_url( self::string_attr( $card, 'whereUrl' ) ) : '',
			'notes'            => $has_card ? self::plain( self::string_attr( $card, 'notes' ), true ) : '',
			'acquired_at'      => $acquired_at,
			'acquired_display' => $acquired_display,
			'cost'             => $cost,
			'picture'          => self::picture( $post_id, $card ),
			'name'             => $name,
			'name_is_title'    => $name_is_title,
		];
	}

	/**
	 * Add the acquisition Stream citation callback.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $map Existing map.
	 * @return array<string, callable>
	 */
	public static function cite_map( $map ): array {
		$map               = is_array( $map ) ? $map : [];
		$map[ self::KIND ] = [ self::class, 'stream_cite' ];

		return $map;
	}

	/**
	 * Hidden acquisition citation for a generic Stream card.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $facts Acquisition facts.
	 * @param \WP_Post             $post  Post. Unused.
	 * @return string Hidden h-cite HTML, or ''.
	 */
	public static function stream_cite( array $facts, \WP_Post $post ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Map callback signature.
		$title = is_scalar( $facts['title'] ?? null ) ? trim( (string) $facts['title'] ) : '';
		if ( [] === $facts || '' === $title ) {
			return '';
		}

		return \PKIW\hidden_cite_html( 'u-acquisition-of', [ 'p-name' => $title ] );
	}

	/**
	 * String value of a card attribute.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $card Card attributes.
	 * @param string               $key  Attribute key.
	 * @return string Scalar value, or ''.
	 */
	private static function string_attr( array $card, string $key ): string {
		return is_scalar( $card[ $key ] ?? null ) ? (string) $card[ $key ] : '';
	}

	/**
	 * The acquisition picture in featured, card photo, meta photo order.
	 *
	 * @since 1.9.0
	 *
	 * @param int                       $post_id Post ID.
	 * @param array<string, mixed>|null $card    Card attributes.
	 * @return array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool, width: int, height: int}
	 */
	private static function picture( int $post_id, ?array $card ): array {
		$picture = \PKIW\kind_picture( $post_id );
		if ( 'featured' === $picture['source'] ) {
			return self::with_dimensions( $picture );
		}

		$url = null === $card ? '' : \PKIW\web_url( self::string_attr( $card, 'photo' ) );
		if ( '' !== $url ) {
			$attachment_id = \PKIW\cover_local_copy( $post_id, $url );
			$alt           = trim( self::string_attr( $card, 'photoAlt' ) );
			if ( $attachment_id > 0 ) {
				$picture = [
					'source'            => 'cover',
					'attachment_id'     => $attachment_id,
					'url'               => (string) wp_get_attachment_url( $attachment_id ),
					'alt'               => '' !== $alt ? $alt : \PKIW\attachment_alt( $attachment_id ),
					'remote'            => false,
					'suppress_featured' => false,
				];

				return self::with_dimensions( $picture );
			}

			return [
				'source'            => 'cover',
				'attachment_id'     => 0,
				'url'               => $url,
				'alt'               => $alt,
				'remote'            => ! \PKIW\is_upload_url( $url ),
				'suppress_featured' => false,
				'width'             => 0,
				'height'            => 0,
			];
		}

		return self::with_dimensions( $picture );
	}

	/**
	 * Add full-size image dimensions to a kind picture shape.
	 *
	 * @since 1.9.0
	 *
	 * @param array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool} $picture Picture.
	 * @return array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool, width: int, height: int}
	 */
	private static function with_dimensions( array $picture ): array {
		$width  = 0;
		$height = 0;
		if ( (int) $picture['attachment_id'] > 0 ) {
			$image = wp_get_attachment_image_src( (int) $picture['attachment_id'], 'full' );
			if ( is_array( $image ) ) {
				$width  = (int) ( $image[1] ?? 0 );
				$height = (int) ( $image[2] ?? 0 );
			}
		}

		$picture['width']  = $width;
		$picture['height'] = $height;

		return $picture;
	}
}
