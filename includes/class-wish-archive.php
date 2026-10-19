<?php
/**
 * The wish kind archive facts, grouping and Stream citation.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sections wishes by type, orders them by priority and normalizes public facts.
 *
 * @since 1.9.0
 */
final class Wish_Archive {

	/**
	 * Kind slug.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const KIND = 'wish';

	/**
	 * Group source ID.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const SOURCE = 'wish_type';

	/**
	 * Wish card block name.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const CARD_BLOCK = 'post-kinds-indieweb/wish-card';

	/**
	 * Stored wish type key.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const TYPE_META = '_pkiw_wish_type';

	/**
	 * Stored wish priority key.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	public const PRIORITY_META = '_pkiw_wish_priority';

	/**
	 * Priority order inside each type.
	 *
	 * @since 1.9.0
	 *
	 * @var string[]
	 */
	public const PRIORITIES = [ 'high', 'medium', 'low' ];

	/**
	 * Stored type to public group key map.
	 *
	 * Unknown values deliberately fall through to the empty group.
	 *
	 * @since 1.9.0
	 *
	 * @var array<string, string>
	 */
	public const VALUE_MAP = [
		'item'       => 'item',
		'experience' => 'experience',
		'book'       => 'book',
		'game'       => 'game',
		'movie'      => 'movie',
		'media'      => 'movie',
		'music'      => 'music',
		'product'    => 'product',
		'travel'     => 'travel',
		'tech'       => 'tech',
		'food'       => 'food',
	];

	/**
	 * Register grouping, facts, section labels and Stream citation.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		Grouped_Archive::register_source( self::source(), self::KIND );
		add_filter( 'pkiw_archive_sections_groups', [ self::class, 'sections_groups' ] );
		add_filter( 'pkiw_stream_card_kind_cite', [ self::class, 'cite_map' ] );
	}

	/**
	 * Wish type labels in display order.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, string> Group key to translated label.
	 */
	public static function type_labels(): array {
		return [
			'item'       => __( 'Item', 'post-kinds-for-indieweb-in-block-themes' ),
			'experience' => __( 'Experience', 'post-kinds-for-indieweb-in-block-themes' ),
			'book'       => __( 'Book', 'post-kinds-for-indieweb-in-block-themes' ),
			'game'       => __( 'Game', 'post-kinds-for-indieweb-in-block-themes' ),
			'movie'      => __( 'Movie/Show', 'post-kinds-for-indieweb-in-block-themes' ),
			'music'      => __( 'Music', 'post-kinds-for-indieweb-in-block-themes' ),
			'product'    => __( 'Product', 'post-kinds-for-indieweb-in-block-themes' ),
			'travel'     => __( 'Travel', 'post-kinds-for-indieweb-in-block-themes' ),
			'tech'       => __( 'Tech', 'post-kinds-for-indieweb-in-block-themes' ),
			'food'       => __( 'Food', 'post-kinds-for-indieweb-in-block-themes' ),
		];
	}

	/**
	 * The meta source that groups wishes by type and ranks priority within it.
	 *
	 * @since 1.9.0
	 *
	 * @return Meta_Source Wish archive source.
	 */
	public static function source(): Meta_Source {
		return new Meta_Source(
			self::SOURCE,
			[ self::TYPE_META ],
			[],
			Label_Mode::map( static fn(): array => self::type_labels() ),
			'',
			true,
			self::VALUE_MAP,
			[
				'key'     => self::PRIORITY_META,
				'order'   => self::PRIORITIES,
				'default' => 'medium',
			]
		);
	}

	/**
	 * Normalize stored type and priority text the same way as Meta_Source.
	 *
	 * @since 1.9.0
	 *
	 * @param string $stored Stored value.
	 * @return string Lowercase value trimmed of spaces.
	 */
	public static function normalize( string $stored ): string {
		return mb_strtolower( trim( $stored, ' ' ) );
	}

	/**
	 * Public group key for a stored type.
	 *
	 * @since 1.9.0
	 *
	 * @param string $stored Stored type.
	 * @return string Group key, or '' for Other.
	 */
	public static function group_key( string $stored ): string {
		return self::VALUE_MAP[ self::normalize( $stored ) ] ?? '';
	}

	/**
	 * Public label for a stored type.
	 *
	 * @since 1.9.0
	 *
	 * @param string $stored Stored type.
	 * @return string Translated type label.
	 */
	public static function type_label( string $stored ): string {
		$key = self::group_key( $stored );

		return '' === $key
			? __( 'Other', 'post-kinds-for-indieweb-in-block-themes' )
			: self::type_labels()[ $key ];
	}

	/**
	 * Normalize a stored priority to the three public ranks.
	 *
	 * @since 1.9.0
	 *
	 * @param string $stored Stored priority.
	 * @return string high, medium or low.
	 */
	public static function priority( string $stored ): string {
		$priority = self::normalize( $stored );

		return in_array( $priority, self::PRIORITIES, true ) ? $priority : 'medium';
	}

	/**
	 * Short translated priority label.
	 *
	 * @since 1.9.0
	 *
	 * @param string $stored Stored priority.
	 * @return string High, Medium or Low.
	 */
	public static function priority_label( string $stored ): string {
		$labels = [
			'high'   => __( 'High', 'post-kinds-for-indieweb-in-block-themes' ),
			'medium' => __( 'Medium', 'post-kinds-for-indieweb-in-block-themes' ),
			'low'    => __( 'Low', 'post-kinds-for-indieweb-in-block-themes' ),
		];

		return $labels[ self::priority( $stored ) ];
	}

	/**
	 * Translated priority text for a compact Stream chip.
	 *
	 * @since 1.9.0
	 *
	 * @param string $stored Stored priority.
	 * @return string Priority text.
	 */
	public static function priority_text( string $stored ): string {
		$labels = [
			'high'   => __( 'High priority', 'post-kinds-for-indieweb-in-block-themes' ),
			'medium' => __( 'Medium priority', 'post-kinds-for-indieweb-in-block-themes' ),
			'low'    => __( 'Low priority', 'post-kinds-for-indieweb-in-block-themes' ),
		];

		return $labels[ self::priority( $stored ) ];
	}

	/**
	 * A public HTTP URL whose host was not created from escaped prose.
	 *
	 * @since 1.9.0
	 *
	 * @param string $url Stored URL.
	 * @return string Safe web URL, or ''.
	 */
	public static function link_url( string $url ): string {
		$url = \PKIW\web_url( $url );
		if ( '' === $url ) {
			return '';
		}

		$host = \PKIW\url_host( $url );

		return 1 === preg_match( '/[%\s]/', $host ) ? '' : $url;
	}

	/**
	 * Offer type labels to the archive-sections editor.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $groups Existing kind label maps.
	 * @return array<string, array<string, string>> Kind label maps.
	 */
	public static function sections_groups( $groups ): array {
		$groups               = is_array( $groups ) ? $groups : [];
		$groups[ self::KIND ] = self::type_labels();

		return $groups;
	}

	/**
	 * Add the wish callback to the Stream citation map.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $map Existing callback map.
	 * @return array<string, callable> Callback map.
	 */
	public static function cite_map( $map ): array {
		$map               = is_array( $map ) ? $map : [];
		$map[ self::KIND ] = [ self::class, 'stream_cite' ];

		return $map;
	}

	/**
	 * Hidden wish citation for a generic Stream card.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $facts Wish facts.
	 * @param \WP_Post             $post  Post. Unused.
	 * @return string Hidden h-cite HTML, or ''.
	 */
	public static function stream_cite( array $facts, \WP_Post $post ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Map callback signature.
		$url = is_string( $facts['url'] ?? null ) ? $facts['url'] : '';
		if ( [] === $facts || '' === $url ) {
			return '';
		}

		$name = 'untitled' !== ( $facts['name_kind'] ?? '' ) && is_string( $facts['name'] ?? null )
			? $facts['name']
			: '';

		return \PKIW\hidden_cite_html(
			'u-wish-of',
			[
				'p-name' => $name,
				'u-url'  => $url,
			]
		);
	}

	/**
	 * Public facts for one wish post.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Wish facts, or [] when hidden or not a wish.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || self::KIND !== Kind_Facts::kind_of( $post ) ) {
			return [];
		}

		$card     = self::card_attrs( $post );
		$has_card = [] !== $card;
		$title    = trim( $card['title'] ?? '' );
		if ( '' === $title ) {
			$title = trim( (string) get_post_meta( $post_id, '_pkiw_wish_name', true ) );
		}

		$titled = '' !== trim( (string) $post->post_title );
		if ( $titled ) {
			$name          = \PKIW\visitor_post_title( $post );
			$name_is_title = ! \PKIW\post_title_is_hidden( $post );
			$name_kind     = 'title';
		} elseif ( '' !== $title ) {
			$name          = $title;
			$name_is_title = false;
			$name_kind     = 'card';
		} else {
			$name          = \PKIW\untitled_name( $post, false );
			$name_is_title = false;
			$name_kind     = 'untitled';
		}

		$stored_type = get_metadata_raw( 'post', $post_id, self::TYPE_META, true );
		$type        = is_string( $stored_type ) ? self::normalize( $stored_type ) : '';
		if ( '' === $type ) {
			$type = self::normalize( $card['wishType'] ?? '' );
		}
		if ( '' === $type && $has_card ) {
			$type = 'item';
		}

		$stored_priority = get_metadata_raw( 'post', $post_id, self::PRIORITY_META, true );
		$stored_priority = is_string( $stored_priority ) ? $stored_priority : '';
		$priority        = self::priority( '' !== self::normalize( $stored_priority ) ? $stored_priority : ( $card['priority'] ?? '' ) );

		$card_url = self::link_url( $card['url'] ?? '' );
		$url      = '' !== $card_url ? $card_url : self::link_url( (string) get_post_meta( $post_id, '_pkiw_wish_url', true ) );
		$rel      = trim( (string) preg_replace( '/\s+/', ' ', $card['rel'] ?? '' ) );

		[ $wished_iso, $wished_display ] = \PKIW\card_calendar_date( $card['wishedAt'] ?? '' );

		return [
			'has_card'       => $has_card,
			'title'          => $title,
			'name'           => $name,
			'name_is_title'  => $name_is_title,
			'name_kind'      => $name_kind,
			'type'           => $type,
			'type_key'       => self::group_key( $type ),
			'type_label'     => self::type_label( $type ),
			'priority'       => $priority,
			'priority_label' => self::priority_label( $priority ),
			'priority_text'  => self::priority_text( $priority ),
			'price'          => trim( $card['price'] ?? '' ),
			'reason'         => trim( $card['reason'] ?? '' ),
			'url'            => $url,
			'rel'            => $rel,
			'wished_iso'     => $wished_iso,
			'wished_display' => $wished_display,
			'picture'        => self::picture(
				$post_id,
				[
					'image'    => $card['image'] ?? '',
					'imageAlt' => $card['imageAlt'] ?? '',
				]
			),
		];
	}

	/**
	 * Resolve a wish picture in featured, card image, then wish-photo order.
	 *
	 * @since 1.9.0
	 *
	 * @param int                  $post_id Post ID, or 0 outside a post.
	 * @param array<string, mixed> $card    Card image attributes.
	 * @return array{source:string, attachment_id:int, url:string, alt:string, remote:bool, suppress_featured:bool} Picture shape.
	 */
	public static function picture( int $post_id, array $card ): array {
		$empty   = [
			'source'            => '',
			'attachment_id'     => 0,
			'url'               => '',
			'alt'               => '',
			'remote'            => false,
			'suppress_featured' => false,
		];
		$picture = $empty;
		if ( $post_id > 0 ) {
			$picture = \PKIW\kind_picture( $post_id );
			if ( 'featured' === $picture['source'] ) {
				return $picture;
			}
		}

		$image = is_string( $card['image'] ?? null ) ? $card['image'] : '';
		$alt   = is_string( $card['imageAlt'] ?? null ) ? $card['imageAlt'] : '';
		$url   = \PKIW\web_url( $image );
		if ( '' !== $url ) {
			$attachment_id = $post_id > 0 ? \PKIW\cover_local_copy( $post_id, $url ) : 0;
			if ( $attachment_id > 0 ) {
				return [
					'source'            => 'cover',
					'attachment_id'     => $attachment_id,
					'url'               => (string) wp_get_attachment_url( $attachment_id ),
					'alt'               => $alt,
					'remote'            => false,
					'suppress_featured' => false,
				];
			}

			return [
				'source'            => 'cover',
				'attachment_id'     => 0,
				'url'               => $url,
				'alt'               => $alt,
				'remote'            => ! \PKIW\is_upload_url( $url ),
				'suppress_featured' => false,
			];
		}

		return $post_id > 0 ? $picture : $empty;
	}

	/**
	 * String attributes from the first wish card in the post.
	 *
	 * Non-string values are ignored so malformed block comments cannot alter
	 * the scalar facts contract.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, string> Card attributes, or [].
	 */
	private static function card_attrs( \WP_Post $post ): array {
		foreach ( \PKIW\flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( self::CARD_BLOCK !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$card  = [];
			foreach ( [ 'title', 'wishType', 'url', 'image', 'imageAlt', 'price', 'reason', 'priority', 'wishedAt', 'rel', 'layout' ] as $key ) {
				$card[ $key ] = is_string( $attrs[ $key ] ?? null ) ? $attrs[ $key ] : '';
			}

			return $card;
		}

		return [];
	}
}
