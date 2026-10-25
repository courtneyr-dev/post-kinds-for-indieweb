<?php
/**
 * Collection item resolution, rendering, facts, and ability.
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
 * Resolve public collection items and present them on every collection surface.
 *
 * @since 1.9.0
 */
final class Collection_Items {

	/** Collection kind slug. */
	public const KIND = 'collection';

	/** Collection item block name. */
	public const ITEM_BLOCK = 'post-kinds-indieweb/collection-item';

	/** Collection cabinet class token. */
	public const CABINET_CLASS = 'pkiw-collection';

	/**
	 * Visible item cache by collection post ID.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private static array $items_cache = [];

	/**
	 * Register collection facts, rendering, styles, cache hooks, and ability.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_filter( 'pkiw_stream_card_kind_render', [ self::class, 'stream_card' ], 10, 4 );
		add_filter( 'render_block_core/group', [ self::class, 'drop_empty_cabinet' ], 10, 2 );
		add_filter( 'get_block_type_variations', [ self::class, 'cabinet_variation' ], 10, 2 );

		if ( did_action( 'init' ) ) {
			self::register_style();
		} else {
			add_action(
				'init',
				static function (): void {
					self::register_style();
				}
			);
		}

		add_action( 'clean_post_cache', [ self::class, 'reset_cache' ], 10, 0 );
		add_action( 'transition_post_status', [ self::class, 'reset_cache' ], 10, 0 );
		add_action( 'deleted_post', [ self::class, 'reset_cache' ], 10, 0 );
		add_action( 'added_post_meta', [ self::class, 'reset_cache' ], 10, 0 );
		add_action( 'updated_post_meta', [ self::class, 'reset_cache' ], 10, 0 );
		add_action( 'deleted_post_meta', [ self::class, 'reset_cache' ], 10, 0 );

		if ( Feature_Flags::has_abilities_api() ) {
			add_action( 'wp_abilities_api_init', [ self::class, 'register_ability' ] );
		}
	}

	/**
	 * Register the structural collection-item stylesheet for both canvases.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	private static function register_style(): void {
		$file = \PKIW_PATH . 'styles/collection-item.css';
		if ( ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_block_style(
			self::ITEM_BLOCK,
			[
				'handle' => 'pkiw-collection-item',
				'src'    => \PKIW_URL . 'styles/collection-item.css',
				'path'   => $file,
				'ver'    => (string) filemtime( $file ),
			]
		);
	}

	/**
	 * Whether a referenced post can print on a public collection surface.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Referenced post ID.
	 * @return bool
	 */
	public static function is_public_reference( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& is_post_publicly_viewable( $post );
	}

	/**
	 * An attachment ID that may print: an image whose parent post is none or a public reference.
	 *
	 * An attachment inherits its parent's visibility, so a picture uploaded to a draft,
	 * private, password-protected or trashed post never crosses into a collection surface.
	 *
	 * @since 1.9.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int The ID, or 0 when it is not a public image.
	 */
	private static function public_image_id( int $attachment_id ): int {
		if ( $attachment_id < 1 || ! wp_attachment_is_image( $attachment_id ) ) {
			return 0;
		}

		$parent = (int) wp_get_post_parent_id( $attachment_id );

		return 0 === $parent || self::is_public_reference( $parent ) ? $attachment_id : 0;
	}

	/**
	 * Resolve one stored item into public display fields.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Stored block attributes.
	 * @return array<string, mixed>|null Public item, or null when empty or hidden.
	 */
	public static function resolve_item( array $attributes ): ?array {
		$post_id       = max( 0, (int) ( $attributes['postId'] ?? 0 ) );
		$source        = self::plain( $attributes['source'] ?? '' );
		$added_at      = self::valid_added_at( $attributes['addedAt'] ?? '' );
		$heading       = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$title         = '';
		$title_is_name = false;
		$url           = '';
		$date_iso      = '';
		$date_display  = '';
		$media_id      = 0;

		if ( $post_id > 0 ) {
			if ( ! self::is_public_reference( $post_id ) ) {
				return null;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}

			$title = \pkiw_get_safe_title( $post_id );
			if ( '' === $title ) {
				$title = \PKIW\untitled_name( $post );
			} else {
				$title_is_name = true;
			}
			$url                         = (string) get_permalink( $post );
			[ $date_iso, $date_display ] = \PKIW\card_calendar_date( (string) get_the_date( 'Y-m-d', $post ) );
			$thumbnail                   = (int) get_post_thumbnail_id( $post );
			$media_id                    = self::public_image_id( $thumbnail );
		} else {
			$title                       = self::plain( $attributes['title'] ?? '' );
			$title_is_name               = '' !== $title;
			[ $date_iso, $date_display ] = \PKIW\card_calendar_date( self::string_value( $attributes['date'] ?? '' ) );
			$url                         = \PKIW\web_url( self::string_value( $attributes['url'] ?? '' ) );
			$media_id                    = self::public_image_id( (int) ( $attributes['mediaId'] ?? 0 ) );

			if ( '' === $title && '' === $date_iso && '' === $source && '' === $url && 0 === $media_id ) {
				return null;
			}
		}

		return [
			'post_id'       => $post_id,
			'title'         => $title,
			'title_is_name' => $title_is_name,
			'url'           => $url,
			'date_iso'      => $date_iso,
			'date_display'  => $date_display,
			'source'        => $source,
			'media_id'      => $media_id,
			'added_at'      => $added_at,
			'heading_level' => $heading,
		];
	}

	/**
	 * Return visible collection items in document order.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Collection post ID.
	 * @return array<int, array<string, mixed>> Visible items.
	 */
	public static function items( int $post_id ): array {
		if ( isset( self::$items_cache[ $post_id ] ) ) {
			return self::$items_cache[ $post_id ];
		}

		$blocks      = \PKIW\flatten_blocks( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) );
		$item_blocks = [];
		$references  = [];
		foreach ( $blocks as $block ) {
			if ( self::ITEM_BLOCK !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			$attributes    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$item_blocks[] = $attributes;
			$reference     = (int) ( $attributes['postId'] ?? 0 );
			if ( $reference > 0 ) {
				$references[] = $reference;
			}
		}

		if ( [] !== $references ) {
			_prime_post_caches( array_values( array_unique( $references ) ), false, false );
		}

		$items = [];
		foreach ( $item_blocks as $attributes ) {
			$item = self::resolve_item( $attributes );
			if ( null === $item ) {
				continue;
			}
			$item['position'] = count( $items ) + 1;
			$items[]          = $item;
		}

		self::$items_cache[ $post_id ] = $items;
		return $items;
	}

	/**
	 * Empty the request-local visible-item cache.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$items_cache = [];
	}

	/**
	 * Count visible items for a collection.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Collection post ID.
	 * @return int
	 */
	public static function visible_count( int $post_id ): int {
		return count( self::items( $post_id ) );
	}

	/**
	 * Return media IDs from the first visible items that have media.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Collection post ID.
	 * @param int $limit   Maximum number of IDs.
	 * @return int[]
	 */
	public static function preview_media( int $post_id, int $limit = 4 ): array {
		if ( $limit < 1 ) {
			return [];
		}

		$media = [];
		foreach ( self::items( $post_id ) as $item ) {
			if ( (int) $item['media_id'] < 1 ) {
				continue;
			}
			$media[] = (int) $item['media_id'];
			if ( count( $media ) >= $limit ) {
				break;
			}
		}
		return $media;
	}

	/**
	 * Return the latest visible item by addedAt, breaking ties by position.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Collection post ID.
	 * @return array<string, mixed>|null Newest item, last visible fallback, or null.
	 */
	public static function newest( int $post_id ): ?array {
		$items = self::items( $post_id );
		if ( [] === $items ) {
			return null;
		}

		$newest = null;
		$latest = null;
		foreach ( $items as $item ) {
			if ( '' === (string) $item['added_at'] ) {
				continue;
			}
			$instant = new \DateTimeImmutable( (string) $item['added_at'] );
			if ( null === $latest || $instant >= $latest ) {
				$latest = $instant;
				$newest = $item;
			}
		}

		return $newest ?? $items[ count( $items ) - 1 ];
	}

	/**
	 * Public collection facts for cards and bindings.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Collection post ID.
	 * @return array<string, mixed> Collection facts, or an empty array when hidden.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
			return [];
		}

		// A hidden location title reads as its safe name, a synthetic one (X8).
		$titled        = '' !== trim( (string) $post->post_title );
		$name          = $titled ? \PKIW\visitor_post_title( $post ) : \PKIW\untitled_name( $post, false );
		$name_is_title = $titled && ! \PKIW\post_title_is_hidden( $post );
		$count         = self::visible_count( $post_id );
		$newest        = self::newest( $post_id );
		/* translators: %s: localized number of visible collection items. */
		$count_label = $count > 0 ? sprintf( _n( '%s item', '%s items', $count, 'post-kinds-for-indieweb-in-block-themes' ), number_format_i18n( $count ) ) : '';

		return [
			'name'              => $name,
			'name_is_title'     => $name_is_title,
			'kind_label'        => \PKIW\get_kind_label( __( 'Collection', 'post-kinds-for-indieweb-in-block-themes' ), self::KIND, 'collection-card' ),
			'item_count'        => $count,
			'item_count_label'  => $count_label,
			'preview_media_ids' => self::preview_media( $post_id ),
			'newest'            => null === $newest ? null : [
				'title'        => (string) $newest['title'],
				'date_iso'     => (string) $newest['date_iso'],
				'date_display' => (string) $newest['date_display'],
				'source'       => (string) $newest['source'],
				'media_id'     => (int) $newest['media_id'],
			],
			'published_iso'     => \PKIW\stream_card_published_datetime( $post ),
		];
	}

	/**
	 * Render one collection item as an h-cite case.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Saved block content. Unused.
	 * @param \WP_Block|null       $block      Block instance. Unused.
	 * @return string Item markup, or an empty string when hidden.
	 */
	public static function render_item( array $attributes, string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$item = self::resolve_item( $attributes );
		if ( null === $item ) {
			return '';
		}

		$classes = [ 'wp-block-post-kinds-indieweb-collection-item', 'pkiw-collection-item', 'h-cite' ];
		if ( (int) $item['media_id'] > 0 ) {
			$classes[] = 'pkiw-collection-item--has-media';
		}
		if ( '' !== (string) $item['url'] ) {
			$classes[] = 'pkiw-collection-item--linked';
		}

		$out = '<div ' . self::wrapper_attributes( $attributes, $classes ) . '>';
		if ( (int) $item['media_id'] > 0 ) {
			$alt   = '' !== (string) $item['url'] ? '' : \PKIW\attachment_alt( (int) $item['media_id'] );
			$image = wp_get_attachment_image(
				(int) $item['media_id'],
				'medium_large',
				false,
				[
					'class' => 'pkiw-collection-item__img u-photo',
					'alt'   => $alt,
				]
			);
			if ( '' !== $image ) {
				$out .= '<div class="pkiw-collection-item__media">' . $image . '</div>';
			}
		}

		$has_label = '' !== (string) $item['title'] || '' !== (string) $item['date_iso'] || '' !== (string) $item['source'] || '' !== (string) $item['url'];
		if ( $has_label ) {
			$out .= '<div class="pkiw-collection-item__label">';
			$out .= self::item_title_html( $item );
			if ( '' !== (string) $item['date_iso'] ) {
				$out .= '<p class="pkiw-collection-item__date"><time class="dt-published" datetime="' . esc_attr( (string) $item['date_iso'] ) . '">' . esc_html( (string) $item['date_display'] ) . '</time></p>';
			}
			if ( '' !== (string) $item['source'] ) {
				$out .= '<p class="pkiw-collection-item__source">' . esc_html( (string) $item['source'] ) . '</p>';
			}
			$out .= '</div>';
		}

		return $out . '</div>';
	}

	/**
	 * Remove an empty collection cabinet while preserving every unrelated group byte-for-byte.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $html  Rendered group HTML.
	 * @param mixed $block Parsed group block.
	 * @return string
	 */
	public static function drop_empty_cabinet( $html, $block ): string {
		$html = is_string( $html ) ? $html : '';
		if ( ! is_array( $block ) || 'core/group' !== ( $block['blockName'] ?? '' ) ) {
			return $html;
		}

		$class_name = is_scalar( $block['attrs']['className'] ?? null ) ? (string) $block['attrs']['className'] : '';
		if ( ! in_array( self::CABINET_CLASS, explode( ' ', $class_name ), true ) ) {
			return $html;
		}

		foreach ( \PKIW\flatten_blocks( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [] ) as $inner ) {
			if ( self::ITEM_BLOCK === ( $inner['blockName'] ?? '' ) && null !== self::resolve_item( is_array( $inner['attrs'] ?? null ) ? $inner['attrs'] : [] ) ) {
				return $html;
			}
		}

		return '';
	}

	/**
	 * Append the Collection cabinet variation to core/group.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $variations Existing variations.
	 * @param mixed $block_type Block type object.
	 * @return array<int, mixed>
	 */
	public static function cabinet_variation( $variations, $block_type ): array {
		$variations = is_array( $variations ) ? $variations : [];
		if ( ! $block_type instanceof \WP_Block_Type || 'core/group' !== $block_type->name ) {
			return $variations;
		}

		$variations[] = [
			'name'        => 'pkiw-collection',
			'title'       => __( 'Collection cabinet', 'post-kinds-for-indieweb-in-block-themes' ),
			'description' => __( 'A cabinet of ordered internal or external collection items.', 'post-kinds-for-indieweb-in-block-themes' ),
			'icon'        => 'archive',
			'attributes'  => [
				'className' => self::CABINET_CLASS,
				'metadata'  => [ 'name' => __( 'Collection', 'post-kinds-for-indieweb-in-block-themes' ) ],
			],
			'innerBlocks' => [ [ self::ITEM_BLOCK, [] ] ],
			'scope'       => [ 'inserter', 'transform' ],
			'isActive'    => [ 'className' ],
		];

		return $variations;
	}

	/**
	 * Render a collection card for archive and Stream surfaces.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $html          Earlier card HTML.
	 * @param mixed $post          Post being rendered.
	 * @param mixed $heading_level Requested heading level.
	 * @param mixed $attributes    Stream block attributes. Unused.
	 * @return mixed Collection card HTML, else the original value.
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
		$listing = is_tax( 'kind', self::KIND ) || Kind_Archive_Layouts::is_block_preview();

		return $listing
			? self::listing_card_html( $post, $facts, $level )
			: self::stream_card_html( $post, $facts, $level );
	}

	/**
	 * Register the public collection-items read ability.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$item_properties = [
			'position' => [ 'type' => 'integer' ],
			'post_id'  => [ 'type' => 'integer' ],
			'title'    => [ 'type' => 'string' ],
			'url'      => [ 'type' => 'string' ],
			'date'     => [ 'type' => 'string' ],
			'source'   => [ 'type' => 'string' ],
			'media_id' => [ 'type' => 'integer' ],
			'added_at' => [ 'type' => 'string' ],
		];

		wp_register_ability(
			'post-kinds/get-collection-items',
			[
				'label'               => __( 'Get collection items', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'         => __( 'Return the visible ordered items in a collection.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [ 'post_id' => [ 'type' => 'integer' ] ],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'         => [ 'type' => 'integer' ],
						'count'           => [ 'type' => 'integer' ],
						'newest_position' => [ 'type' => [ 'integer', 'null' ] ],
						'items'           => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => $item_properties,
								'required'   => array_keys( $item_properties ),
							],
						],
					],
					'required'   => [ 'post_id', 'count', 'newest_position', 'items' ],
				],
				'execute_callback'    => static fn( $input ): array => self::execute_ability( $input ),
				'permission_callback' => static fn( $input ): bool => self::ability_permission( $input ),
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);
	}

	/**
	 * Execute the collection-items ability.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>
	 */
	private static function execute_ability( $input ): array {
		$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;
		$empty   = [
			'post_id'         => $post_id,
			'count'           => 0,
			'newest_position' => null,
			'items'           => [],
		];
		$post    = get_post( $post_id );
		if (
			! $post instanceof \WP_Post
			|| self::KIND !== Kind_Facts::kind_of( $post )
			|| ! Kind_Facts::can_show( $post )
			|| Meta_Fields::hides_kind_meta( $post )
		) {
			return $empty;
		}

		$items  = self::items( $post_id );
		$newest = self::newest( $post_id );
		$output = [];
		foreach ( $items as $item ) {
			$output[] = [
				'position' => (int) $item['position'],
				'post_id'  => (int) $item['post_id'],
				'title'    => (string) $item['title'],
				'url'      => (string) $item['url'],
				'date'     => (string) $item['date_iso'],
				'source'   => (string) $item['source'],
				'media_id' => (int) $item['media_id'],
				'added_at' => (string) $item['added_at'],
			];
		}

		return [
			'post_id'         => $post_id,
			'count'           => count( $output ),
			'newest_position' => null === $newest ? null : (int) $newest['position'],
			'items'           => $output,
		];
	}

	/**
	 * Check whether the current user may read a collection through the ability.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	private static function ability_permission( $input ): bool {
		$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', $post_id ) ) {
			return false;
		}

		return '' === (string) $post->post_password || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Build wrapper attributes inside and outside the block-support render pass.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string[]             $classes    Required class tokens.
	 * @return string Escaped HTML attributes.
	 */
	private static function wrapper_attributes( array $attributes, array $classes ): string {
		if ( is_array( \WP_Block_Supports::$block_to_render ) && self::ITEM_BLOCK === ( \WP_Block_Supports::$block_to_render['blockName'] ?? '' ) ) {
			return get_block_wrapper_attributes( [ 'class' => implode( ' ', $classes ) ] );
		}

		$custom = self::string_value( $attributes['className'] ?? '' );
		foreach ( explode( ' ', $custom ) as $token ) {
			$token = sanitize_html_class( $token );
			if ( '' !== $token ) {
				$classes[] = $token;
			}
		}

		return 'class="' . esc_attr( implode( ' ', array_values( array_unique( $classes ) ) ) ) . '"';
	}

	/**
	 * Build an item's visible title or link-host fallback.
	 *
	 * @param array<string, mixed> $item Resolved item.
	 * @return string
	 */
	private static function item_title_html( array $item ): string {
		$title = (string) $item['title'];
		$url   = (string) $item['url'];
		if ( '' === $title && '' === $url ) {
			return '';
		}

		if ( '' === $title ) {
			return '<p class="pkiw-collection-item__title"><a class="pkiw-collection-item__link u-url" href="' . esc_url( $url ) . '">' . esc_html( \PKIW\url_host( $url ) ) . '</a></p>';
		}

		$level   = (int) $item['heading_level'];
		$classes = 'pkiw-collection-item__title' . ( $item['title_is_name'] ? ' p-name' : '' );
		$text    = esc_html( $title );
		if ( '' !== $url ) {
			$text = '<a class="pkiw-collection-item__link u-url" href="' . esc_url( $url ) . '">' . $text . '</a>';
		}

		return '<h' . $level . ' class="' . esc_attr( $classes ) . '">' . $text . '</h' . $level . '>';
	}

	/**
	 * Build the collection archive listing card.
	 *
	 * @param \WP_Post             $post  Collection post.
	 * @param array<string, mixed> $facts Collection facts.
	 * @param int                  $level Heading level.
	 * @return string
	 */
	private static function listing_card_html( \WP_Post $post, array $facts, int $level ): string {
		$out  = '<article class="pk-card pk-card--stream pk-card--collection pk-card--collection-listing k-collection h-entry">';
		$out .= '<div class="pk-collection__bay">';
		foreach ( (array) $facts['preview_media_ids'] as $media_id ) {
			$out .= wp_get_attachment_image(
				(int) $media_id,
				'medium',
				false,
				[
					'class' => 'pk-collection__img',
					'alt'   => '',
				]
			);
		}
		$out        .= '</div>';
		$title_class = 'pk-title' . ( $facts['name_is_title'] ? ' p-name' : '' );
		$out        .= '<h' . $level . ' class="' . esc_attr( $title_class ) . '"><a class="pk-collection__link u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( (string) $facts['name'] ) . '</a></h' . $level . '>';
		if ( '' !== (string) $facts['item_count_label'] ) {
			$out .= '<p class="pk-collection__count">' . esc_html( (string) $facts['item_count_label'] ) . '</p>';
		}

		return $out . '</article>';
	}

	/**
	 * Build the normal Stream collection card.
	 *
	 * @param \WP_Post             $post  Collection post.
	 * @param array<string, mixed> $facts Collection facts.
	 * @param int                  $level Heading level.
	 * @return string
	 */
	private static function stream_card_html( \WP_Post $post, array $facts, int $level ): string {
		$newest = is_array( $facts['newest'] ?? null ) ? $facts['newest'] : null;
		$out    = '<article class="pk-card pk-card--stream pk-card--collection pk-card--collection-stream k-collection h-entry">';
		if ( null !== $newest && (int) $newest['media_id'] > 0 ) {
			$out .= '<div class="pk-media">' . wp_get_attachment_image(
				(int) $newest['media_id'],
				'medium',
				false,
				[
					'class' => 'pk-cover pk-collection__newest-img',
					'alt'   => '',
				]
			) . '</div>';
		}
		$out        .= '<span class="pk-kindlabel">' . esc_html( (string) $facts['kind_label'] ) . '</span>';
		$title_class = 'pk-title' . ( $facts['name_is_title'] ? ' p-name' : '' );
		$out        .= '<h' . $level . ' class="' . esc_attr( $title_class ) . '"><a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( (string) $facts['name'] ) . '</a></h' . $level . '>';
		if ( null !== $newest ) {
			$out .= '<div class="pk-collection__newest"><p class="pk-collection__newest-label">' . esc_html__( 'Newest item', 'post-kinds-for-indieweb-in-block-themes' ) . '</p>';
			if ( '' !== (string) $newest['title'] ) {
				$out .= '<p class="pk-collection__newest-title">' . esc_html( (string) $newest['title'] ) . '</p>';
			}
			if ( '' !== (string) $newest['date_iso'] ) {
				$out .= '<p class="pk-collection__newest-date"><time datetime="' . esc_attr( (string) $newest['date_iso'] ) . '">' . esc_html( (string) $newest['date_display'] ) . '</time></p>';
			}
			if ( '' !== (string) $newest['source'] ) {
				$out .= '<p class="pk-collection__newest-source">' . esc_html( (string) $newest['source'] ) . '</p>';
			}
			$out .= '</div>';
		}

		return $out . '</article>';
	}

	/**
	 * Strip tags and surrounding whitespace from stored plain text.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function plain( $value ): string {
		return trim( wp_strip_all_tags( self::string_value( $value ) ) );
	}

	/**
	 * Convert a scalar stored value to text.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function string_value( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Keep a parseable stored addedAt value without printing it.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function valid_added_at( $value ): string {
		$value = trim( self::string_value( $value ) );
		if ( '' === $value ) {
			return '';
		}

		try {
			new \DateTimeImmutable( $value );
		} catch ( \Exception $exception ) {
			return '';
		}

		return $value;
	}
}
