<?php
/**
 * Tag archive grouping, facts, cards and entries.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Own the tag-post public contract. */
final class Tag_Archive {
	// Method signatures carry the public contract types; omit duplicate parameter tags.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	public const KIND        = 'tag';
	public const SOURCE      = 'tag_label';
	public const CARD_BLOCK  = 'post-kinds-indieweb/tag-card';
	public const ENTRY_BLOCK = 'post-kinds-indieweb/tag-entry';
	public const LABEL_KEY   = '_pkiw_tag_label';
	public const PER_PAGE    = 12;

	/** Wire grouping, facts, blocks, Stream rendering and label repair. */
	public function register(): void {
		Grouped_Archive::register_source( self::source(), self::KIND );
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_filter( 'pkiw_kind_archive_preview_per_page', [ self::class, 'preview_per_page' ], 10, 2 );
		add_filter( 'pkiw_stream_card_kind_render', [ self::class, 'stream_card' ], 10, 4 );
		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', [ self::class, 'register_blocks' ] );
		}
		add_action( 'pkiw_card_meta_backfill_post', [ self::class, 'backfill_post' ], 10, 2 );
	}

	/** First-label group source. */
	public static function source(): Meta_Source {
		return new Meta_Source( self::SOURCE, [ self::LABEL_KEY ], [], Label_Mode::verbatim(), '', true );
	}

	/** Use twelve archive items per page. */
	public static function preview_per_page( int $per_page, string $kind ): int {
		return self::KIND === $kind ? self::PER_PAGE : $per_page;
	}

	/** Sanitize ordered labels from a card. @return list<string> */
	public static function labels_from_card( array $attrs ): array {
		$labels = [];
		$seen   = [];
		foreach ( (array) ( $attrs['labels'] ?? [] ) as $value ) {
			$label = trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
			if ( '' === $label ) {
				continue;
			}
			$label = mb_substr( $label, 0, 100 );
			$key   = mb_strtolower( $label );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$labels[]     = $label;
			if ( 20 === count( $labels ) ) {
				break;
			}
		}
		return $labels;
	}

	/** Synchronize the first tag card's labels to ordered protected rows. */
	public static function sync_labels( int $post_id, array $blocks, bool $on_save ): void {
		$card   = self::first_card( $blocks );
		$labels = null === $card ? [] : self::labels_from_card( (array) ( $card['attrs'] ?? [] ) );
		if ( ! $on_save ) {
			foreach ( self::backfill_plan( $post_id ) as $op ) {
				add_post_meta( $post_id, $op['key'], $op['value'] );
			}
			return;
		}

		$stored = array_map( 'strval', get_post_meta( $post_id, self::LABEL_KEY, false ) );
		if ( $stored === $labels ) {
			return;
		}
		delete_post_meta( $post_id, self::LABEL_KEY );
		foreach ( $labels as $label ) {
			add_post_meta( $post_id, self::LABEL_KEY, $label );
		}
	}

	/** Plan absent label rows without mutating the post. */
	public static function backfill_plan( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || metadata_exists( 'post', $post_id, self::LABEL_KEY ) ) {
			return [];
		}
		$card = self::first_card( parse_blocks( (string) $post->post_content ) );
		if ( null === $card ) {
			return [];
		}
		$plan = [];
		foreach ( self::labels_from_card( (array) ( $card['attrs'] ?? [] ) ) as $label ) {
			$plan[] = [
				'op'      => 'insert_meta',
				'post_id' => $post_id,
				'key'     => self::LABEL_KEY,
				'value'   => $label,
			];
		}
		return $plan;
	}

	/** Read tag source data without a public-read gate. */
	public static function raw( \WP_Post|int $post ): array {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		$card  = self::first_card( parse_blocks( (string) $post->post_content ) );
		$attrs = null === $card ? [] : (array) ( $card['attrs'] ?? [] );
		$type  = in_array( (string) ( $attrs['targetType'] ?? 'content' ), [ 'content', 'person' ], true ) ? (string) ( $attrs['targetType'] ?? 'content' ) : 'content';
		$url   = null === $card ? web_url( (string) get_post_meta( $post->ID, '_pkiw_cite_url', true ) ) : web_url( (string) ( $attrs['url'] ?? '' ) );
		$title = null === $card ? sanitize_text_field( (string) get_post_meta( $post->ID, '_pkiw_cite_name', true ) ) : sanitize_text_field( (string) ( $attrs['title'] ?? '' ) );

		$labels = null === $card ? [] : self::labels_from_card( $attrs );
		if ( [] === $labels ) {
			$labels = array_map( 'strval', get_post_meta( $post->ID, self::LABEL_KEY, false ) );
		}

		return [
			'target_type' => $type,
			'url'         => $url,
			'title'       => $title,
			'description' => 'content' === $type ? sanitize_text_field( null === $card ? (string) get_post_meta( $post->ID, '_pkiw_cite_summary', true ) : (string) ( $attrs['description'] ?? '' ) ) : '',
			'image_id'    => max( 0, (int) ( $attrs['imageId'] ?? 0 ) ),
			'image_alt'   => sanitize_text_field( (string) ( $attrs['imageAlt'] ?? '' ) ),
			'tagged_at'   => sanitize_text_field( (string) ( $attrs['taggedAt'] ?? '' ) ),
			'labels'      => $labels,
			'has_card'    => null !== $card,
		];
	}

	/** Return public tag facts for every renderer. */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) || ! Public_Eligibility::is_eligible( $post ) ) {
			return [];
		}
		$raw           = self::raw( $post );
		$image_id      = Public_Eligibility::public_image_id( (int) $raw['image_id'] );
		$url_host      = url_host( (string) $raw['url'] );
		$safe_title    = trim( (string) \pkiw_get_safe_title( $post_id ) );
		$display_title = '' !== $raw['title'] ? (string) $raw['title'] : $safe_title;
		if ( '' === $display_title ) {
			$display_title = '' !== $url_host ? $url_host : untitled_name( $post, false );
		}
		[ $tagged_iso, $tagged_display ] = card_calendar_date( (string) $raw['tagged_at'] );
		if ( '' === $tagged_iso ) {
			$tagged_iso     = get_post_time( 'Y-m-d', true, $post );
			$tagged_display = get_the_date( '', $post );
		}
		$initial = '';
		if ( 'person' === $raw['target_type'] && 0 === $image_id ) {
			$seed    = '' !== $raw['title'] ? (string) $raw['title'] : $url_host;
			$initial = mb_strtoupper( mb_substr( trim( $seed ), 0, 1 ) );
		}
		$labels = array_values( array_map( 'strval', $raw['labels'] ) );
		return [
			'target_type'     => (string) $raw['target_type'],
			'url'             => (string) $raw['url'],
			'url_host'        => $url_host,
			'title'           => (string) $raw['title'],
			'display_title'   => $display_title,
			'title_is_target' => '' !== $raw['title'],
			'labels'          => $labels,
			'label'           => $labels[0] ?? '',
			'description'     => (string) $raw['description'],
			'image'           => $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'medium' ) : '',
			'image_id'        => $image_id,
			'image_alt'       => (string) $raw['image_alt'],
			'initial'         => $initial,
			'tagged_iso'      => $tagged_iso,
			'tagged_display'  => $tagged_display,
			'date_iso'        => get_post_time( 'c', true, $post ),
			'has_card'        => (bool) $raw['has_card'],
		];
	}

	/** Resolve a dynamic block's real owner without consulting the global post. */
	public static function resolve_owner( \WP_Block $block ): ?\WP_Post {
		$post_id = $block->context['postId'] ?? null;
		if ( ! is_int( $post_id ) || $post_id < 1 ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		static $parsed = [];
		$key           = md5( (string) $post->post_content );
		if ( ! isset( $parsed[ $key ] ) ) {
			$parsed[ $key ] = flatten_blocks( parse_blocks( (string) $post->post_content ) );
		}
		$needle = self::sorted_attrs( (array) ( $block->parsed_block['attrs'] ?? [] ) );
		foreach ( $parsed[ $key ] as $candidate ) {
			if ( self::CARD_BLOCK === ( $candidate['blockName'] ?? '' ) && self::sorted_attrs( (array) ( $candidate['attrs'] ?? [] ) ) === $needle ) {
				return $post;
			}
		}
		return null;
	}

	/** Render the stored Tag Card. */
	public static function render_card( array $attributes, ?\WP_Post $owner ): string {
		$type        = 'person' === ( $attributes['targetType'] ?? 'content' ) ? 'person' : 'content';
		$url         = web_url( (string) ( $attributes['url'] ?? '' ) );
		$title       = sanitize_text_field( (string) ( $attributes['title'] ?? '' ) );
		$description = sanitize_text_field( (string) ( $attributes['description'] ?? '' ) );
		$labels      = self::labels_from_card( $attributes );
		if ( '' === $url && '' === $title && [] === $labels ) {
			return '';
		}

		$host     = url_host( $url );
		$image_id = Public_Eligibility::public_image_id( max( 0, (int) ( $attributes['imageId'] ?? 0 ) ) );
		$html     = '<article class="pk-card k-tag pkiw-tag-card pkiw-tag-card--' . esc_attr( $type ) . '"><div class="pk-badge">' . get_kind_icon_svg( self::KIND ) . '</div><div class="pk-body"><span class="pk-kindlabel">' . esc_html( get_kind_label( __( 'Tag', 'post-kinds-for-indieweb-in-block-themes' ), self::KIND, 'tag-card' ) ) . '</span>';
		if ( 'content' === $type ) {
			$link_text = '' !== $title ? $title : $host;
			if ( '' !== $link_text ) {
				$html .= '<h2 class="pk-title">';
				$html .= '' !== $url ? '<a class="u-tag-of" href="' . esc_url( $url ) . '" rel="noopener noreferrer" target="_blank"' . pkiw_new_tab_label_attr( $link_text ) . '>' . esc_html( $link_text ) . '</a>' : esc_html( $link_text );
				$html .= '</h2>';
			}
			if ( '' !== $host ) {
				$html .= '<p class="pk-sub pkiw-tag-card__host">' . esc_html( $host ) . '</p>';
			}
			if ( '' !== $description ) {
				$html .= '<p class="pk-note pkiw-tag-card__description">' . esc_html( $description ) . '</p>';
			}
			if ( $image_id > 0 ) {
				$html .= '<figure class="pk-embed pk-embed--photo">' . wp_get_attachment_image( $image_id, 'medium', false, [ 'alt' => sanitize_text_field( (string) ( $attributes['imageAlt'] ?? '' ) ) ] ) . '</figure>';
			}
		} elseif ( $owner instanceof \WP_Post && Public_Eligibility::is_eligible( $owner ) && ( '' !== $title || '' !== $url ) ) {
			$initial = mb_strtoupper( mb_substr( '' !== $title ? $title : $host, 0, 1 ) );
			$html   .= '<p class="pk-title pkiw-tag-card__person"><span class="u-tag-of h-card">';
			$html   .= $image_id > 0 ? wp_get_attachment_image(
				$image_id,
				'thumbnail',
				false,
				[
					'class' => 'u-photo',
					'alt'   => '',
				]
			) : '<span class="pkiw-tag-card__initial" aria-hidden="true">' . esc_html( $initial ) . '</span>';
			if ( '' !== $url ) {
				$html .= '<a class="p-name u-url" href="' . esc_url( $url ) . '" rel="noopener noreferrer">' . esc_html( '' !== $title ? $title : $host ) . '</a>';
			} elseif ( '' !== $title ) {
				$html .= '<span class="p-name">' . esc_html( $title ) . '</span>';
			}
			$html .= '</span></p>';
			if ( '' !== $host ) {
				$html .= '<p class="pk-sub pkiw-tag-card__host">' . esc_html( $host ) . '</p>';
			}
		}
		if ( [] !== $labels ) {
			$prefix = 1 === count( $labels ) ? __( 'Label:', 'post-kinds-for-indieweb-in-block-themes' ) : __( 'Labels:', 'post-kinds-for-indieweb-in-block-themes' );
			$html  .= '<p class="pkiw-tag-card__labels"><span class="screen-reader-text">' . esc_html( $prefix ) . '</span>';
			foreach ( $labels as $label ) {
				$html .= '<span class="p-category">' . esc_html( $label ) . '</span>';
			}
			$html .= '</p>';
		}
		[ $iso, $display ] = card_calendar_date( (string) ( $attributes['taggedAt'] ?? '' ) );
		if ( '' !== $iso ) {
			$html .= '<div class="pk-meta"><time datetime="' . esc_attr( $iso ) . '">' . esc_html( $display ) . '</time></div>';
		}
		return $html . '</div></article>';
	}

	/** Register the dynamic archive-entry block. */
	public static function register_blocks(): void {
		wp_register_script( 'pkiw-tag-entry-editor', PKIW_URL . 'assets/js/tag-entry-editor.js', [ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ], PKIW_VERSION, true );
		wp_set_script_translations( 'pkiw-tag-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );
		Grouped_Archive::register_entry_block(
			self::ENTRY_BLOCK,
			[
				'shape'       => Grouped_Archive::ENTRY_LINE,
				'heading_ids' => true,
				'expose_key'  => true,
				'list'        => 'ul',
				'classes'     => [
					'wrapper'     => 'pkiw-tag--sectioned',
					'section'     => 'pkiw-tag-section',
					'heading'     => 'pkiw-tag-section__heading',
					'items'       => 'pkiw-tag-section__items',
					'placeholder' => 'pkiw-tag-entry pkiw-tag-entry--continued',
				],
			]
		);
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY_BLOCK ) ) {
			return;
		}
		// Core stores and compares the numeric API version; the WordPress stubs type this field as a string.
		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Tag entry', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render_entry_block' ],
			'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			'attributes'      => Grouped_Archive::entry_attributes(
				[
					'linesPerPage' => [ 'default' => 12 ],
					'headingLevel' => [ 'default' => 2 ],
					'emptyLabel'   => [ 'role' => 'content' ],
				]
			),
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-tag-entry-editor',
			'style'           => 'pkiw-kind-layouts',
			'example'         => [ 'attributes' => [ 'linesPerPage' => 12 ] ],
		];
		register_block_type( self::ENTRY_BLOCK, $args );
	}

	/** Render a grouped tag entry. */
	public static function render_entry_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Grouped_Archive::render_entry( self::ENTRY_BLOCK, $attributes, $block, static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::entry_html( $post, $attributes, $in_section ) ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	}

	/** Render one line inside a tag sheet. */
	public static function entry_html( \WP_Post $post, array $attributes, bool $in_section ): string {
		$facts = self::facts( $post->ID );
		if ( [] === $facts ) {
			return '';
		}
		$level    = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) + ( $in_section ? 1 : 0 ) ) );
		$title_id = 'pkiw-tag-' . $post->ID . '-title';
		$host_id  = 'pkiw-tag-' . $post->ID . '-host';
		$labelled = self::title_repeats( (string) $facts['display_title'], $post->ID ) && '' !== $facts['url_host'] ? ' aria-labelledby="' . esc_attr( $title_id . ' ' . $host_id ) . '"' : '';
		$html     = '<article class="pkiw-tag-entry pkiw-tag-entry--' . esc_attr( (string) $facts['target_type'] ) . ' h-entry"><h' . $level . ' class="pkiw-tag-entry__title"><a class="u-url' . ( $facts['title_is_target'] ? '' : ' p-name' ) . '" id="' . esc_attr( $title_id ) . '" href="' . esc_url( (string) get_permalink( $post ) ) . '"' . $labelled . '>' . esc_html( (string) $facts['display_title'] ) . '</a></h' . $level . '>';
		if ( '' !== $facts['url_host'] ) {
			$html .= '<p class="pkiw-tag-entry__host" id="' . esc_attr( $host_id ) . '">' . esc_html( (string) $facts['url_host'] ) . '</p>';
		}
		$html  .= '<time class="dt-published" datetime="' . esc_attr( (string) $facts['date_iso'] ) . '" hidden></time>';
		$author = entry_author_html( $post );
		if ( '' !== $author ) {
			$html .= '<span hidden>' . $author . '</span>';
		}
		return $html . '<span hidden>' . self::hidden_properties( $facts ) . '</span></article>';
	}

	/** Render the plugin Stream fallback for every tag post. */
	public static function stream_card( ?string $html, \WP_Post $post, int $heading_level, array $attributes ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( null !== $html || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return $html;
		}
		$facts = self::facts( $post->ID );
		if ( [] === $facts ) {
			$raw   = self::raw( $post );
			$title = visitor_post_title( $post );
			return '<article class="pk-card pk-card--stream k-tag h-entry"><span class="pk-kindlabel">' . esc_html( __( 'Tag', 'post-kinds-for-indieweb-in-block-themes' ) ) . '</span><h' . $heading_level . ' class="pk-title"><a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $title ) . '</a></h' . $heading_level . '>' . self::labels_html( (array) ( $raw['labels'] ?? [] ) ) . '</article>';
		}
		$html  = '<article class="pk-card pk-card--stream k-tag h-entry"><span class="pk-kindlabel">' . esc_html( get_kind_label( __( 'Tag', 'post-kinds-for-indieweb-in-block-themes' ), self::KIND, 'stream-card' ) ) . '</span>';
		$html .= '' !== $facts['label'] ? '<p class="pkiw-tag-stream__label"><span class="p-category">' . esc_html( (string) $facts['label'] ) . '</span></p>' : '';
		$html .= '<h' . $heading_level . ' class="pk-title"><a class="u-url' . ( $facts['title_is_target'] ? '' : ' p-name' ) . '" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( (string) $facts['display_title'] ) . '</a></h' . $heading_level . '>';
		$html .= '' !== $facts['url_host'] ? '<p class="pk-sub">' . esc_html( (string) $facts['url_host'] ) . '</p>' : '';
		$html .= '<time class="dt-published" datetime="' . esc_attr( (string) $facts['date_iso'] ) . '">' . esc_html( (string) $facts['tagged_display'] ) . '</time><span hidden>' . entry_author_html( $post ) . self::hidden_properties( $facts ) . '</span></article>';
		return $html;
	}

	/** Fill absent label rows during a later shared replay. */
	public static function backfill_post( int $post_id, \WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		self::sync_labels( $post_id, parse_blocks( (string) $post->post_content ), false );
	}

	/** Find the first tag card in a parsed block tree. @return array<string, mixed>|null */
	private static function first_card( array $blocks ): ?array {
		foreach ( flatten_blocks( $blocks ) as $block ) {
			if ( self::CARD_BLOCK === ( $block['blockName'] ?? '' ) ) {
				return $block;
			}
		}
		return null;
	}

	/** Stable recursive attribute comparison. */
	private static function sorted_attrs( array $attrs ): array {
		foreach ( $attrs as &$value ) {
			if ( is_array( $value ) ) {
				$value = self::sorted_attrs( $value );
			}
		}
		unset( $value );
		ksort( $attrs );
		return $attrs;
	}

	/** Hidden mf2 properties shared by archive and Stream items. */
	private static function hidden_properties( array $facts ): string {
		$html = '';
		if ( 'person' === $facts['target_type'] ) {
			$html .= '<span class="u-tag-of h-card">';
			$html .= '' !== $facts['title'] ? '<data class="p-name" value="' . esc_attr( (string) $facts['title'] ) . '"></data>' : '';
			$html .= '' !== $facts['url'] ? '<data class="u-url" value="' . esc_url( (string) $facts['url'] ) . '"></data>' : '';
			$html .= '</span>';
		} elseif ( '' !== $facts['url'] ) {
			$html .= '<data class="u-tag-of" value="' . esc_url( (string) $facts['url'] ) . '"></data>';
		}
		foreach ( (array) $facts['labels'] as $label ) {
			$html .= '<data class="p-category" value="' . esc_attr( (string) $label ) . '"></data>';
		}
		return $html;
	}

	/** Render label microformats for a fallback card. @return string */
	private static function labels_html( array $labels ): string {
		$html = '';
		foreach ( $labels as $label ) {
			$html .= '<span class="p-category">' . esc_html( (string) $label ) . '</span>';
		}
		return $html;
	}

	/** Whether a display title repeats on the current grouped page. */
	private static function title_repeats( string $title, int $post_id ): bool {
		$ids = Grouped_Archive::current_page_ids();
		if ( [] === $ids ) {
			$ids = [ $post_id ];
		}
		$count = 0;
		foreach ( $ids as $id ) {
			$facts = self::facts( (int) $id );
			if ( [] !== $facts && mb_strtolower( (string) $facts['display_title'] ) === mb_strtolower( $title ) && ++$count > 1 ) {
				return true;
			}
		}
		return false;
	}
}
