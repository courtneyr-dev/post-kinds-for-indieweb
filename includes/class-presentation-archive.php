<?php
/**
 * Presentation archive grouping and entry block.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Date_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Build the year-grouped presentation archive. */
final class Presentation_Archive {

	public const KIND        = 'presentation';
	public const SOURCE      = 'presentation_year';
	public const ENTRY_BLOCK = 'post-kinds-indieweb/presentation-entry';
	public const PER_PAGE    = 12;

	/** Wire the date source, page size and entry block. */
	public function register(): void {
		Grouped_Archive::register_source( new Date_Source( self::SOURCE, 'year' ), self::KIND );
		add_filter( 'pkiw_kind_archive_preview_per_page', [ self::class, 'preview_per_page' ], 10, 2 );
		if ( did_action( 'init' ) ) {
			self::register_entry_block();
		} else {
			add_action( 'init', [ self::class, 'register_entry_block' ] );
		}
	}

	/**
	 * Set the presentation archive's preview page size.
	 *
	 * @param int    $per_page Current size.
	 * @param string $kind     Kind slug.
	 */
	public static function preview_per_page( int $per_page, string $kind ): int {
		return self::KIND === $kind ? self::PER_PAGE : $per_page;
	}

	/** Register the plain editor script, style and dynamic entry block. */
	public static function register_entry_block(): void {
		wp_register_script( 'pkiw-presentation-entry-editor', \PKIW_URL . 'assets/js/presentation-entry-editor.js', [ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ], \PKIW_VERSION, true );
		wp_set_script_translations( 'pkiw-presentation-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );
		wp_register_style( 'pkiw-presentation', \PKIW_URL . 'styles/presentation.css', [], \PKIW_VERSION );
		Grouped_Archive::register_entry_block(
			self::ENTRY_BLOCK,
			[
				'shape'       => Grouped_Archive::ENTRY_LINE,
				'source'      => self::SOURCE,
				'heading_ids' => true,
				'expose_key'  => true,
				'classes'     => [
					'wrapper'     => 'pkiw-grouped pkiw-presentation-wall',
					'section'     => 'pkiw-group pkiw-presentation-wall__year',
					'heading'     => 'pkiw-group__heading pkiw-presentation-wall__year-label',
					'items'       => 'pkiw-group__items pkiw-presentation-wall__badges',
					'placeholder' => 'pkiw-group-placeholder pkiw-presentation-wall__continued',
				],
			]
		);
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY_BLOCK ) ) {
			return;
		}
		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Presentation entry', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render' ],
			'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			'ancestor'        => [ 'core/post-template' ],
			'attributes'      => Grouped_Archive::entry_attributes(
				[
					'linesPerPage' => [ 'default' => self::PER_PAGE ],
					'headingLevel' => [ 'default' => 2 ],
					'sectionOrder' => [ 'default' => 'desc' ],
				]
			),
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-presentation-entry-editor',
			'style'           => 'pkiw-presentation',
			'example'         => [ 'attributes' => [ 'linesPerPage' => 3 ] ],
		];
		register_block_type( self::ENTRY_BLOCK, $args );
	}

	/**
	 * Render through the shared grouped archive engine.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused saved content.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Grouped_Archive::render_entry( self::ENTRY_BLOCK, $attributes, $block, static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::entry_html( $post, $attributes, $in_section ) ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	}

	/**
	 * Render one public or password-protected badge.
	 *
	 * @param \WP_Post             $post       Post.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether the badge follows a section heading.
	 */
	public static function entry_html( \WP_Post $post, array $attributes, bool $in_section ): string {
		$level    = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) + ( $in_section ? 1 : 0 ) ) );
		$title_id = 'pkiw-presentation-' . $post->ID . '-title';
		$date_iso = get_post_time( 'c', true, $post );
		if ( '' !== (string) $post->post_password ) {
			// The badge is the entry root, so the Query Loop <li> must not be one too.
			$GLOBALS['pkiw_entry_root_seen'][ $post->ID ] = true;

			$title = '' !== trim( (string) $post->post_title ) ? visitor_post_title( $post ) : untitled_name( $post, false );
			return '<div class="pkiw-presentation-badge pkiw-presentation-badge--protected h-entry" data-pkiw-event-type=""><h' . $level . ' class="pkiw-presentation-badge__title" id="' . esc_attr( $title_id ) . '"><a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $title ) . '</a></h' . $level . '><time class="dt-published" datetime="' . esc_attr( $date_iso ) . '" hidden></time></div>';
		}
		$facts = Presentation::facts( $post->ID );
		if ( [] === $facts ) {
			return '';
		}
		$GLOBALS['pkiw_entry_root_seen'][ $post->ID ] = true;

		$event_id = 'pkiw-presentation-' . $post->ID . '-event';
		$labelled = self::title_repeats( (string) $facts['title'], $post->ID ) && '' !== $facts['event_name'] ? ' aria-labelledby="' . esc_attr( $title_id . ' ' . $event_id ) . '"' : '';
		$html     = '<div class="pkiw-presentation-badge h-entry" data-pkiw-event-type="' . esc_attr( (string) $facts['event_type'] ) . '"><h' . $level . ' class="pkiw-presentation-badge__title" id="' . esc_attr( $title_id ) . '"><a class="u-url p-name pkiw-presentation-badge__link" href="' . esc_url( (string) get_permalink( $post ) ) . '"' . $labelled . '>' . esc_html( (string) $facts['title'] ) . '</a></h' . $level . '>';
		if ( '' !== $facts['event_name'] ) {
			$html .= '<p class="pkiw-presentation-badge__event" id="' . esc_attr( $event_id ) . '">' . esc_html( (string) $facts['event_name'] ) . '</p>';
		}
		if ( '' !== $facts['event_type_label'] ) {
			$html .= '<p class="pkiw-presentation-badge__type">' . esc_html( (string) $facts['event_type_label'] ) . '</p>';
		}
		$html  .= '<time class="dt-published" datetime="' . esc_attr( (string) $facts['date_iso'] ) . '" hidden></time>';
		$author = entry_author_html( $post );
		if ( '' !== $author ) {
			$html .= '<span hidden>' . $author . '</span>';
		}
		return $html . '</div>';
	}

	/**
	 * Whether another readable item on this grouped page has the same title.
	 *
	 * @param string $title   Presentation title.
	 * @param int    $post_id Current post ID.
	 */
	private static function title_repeats( string $title, int $post_id ): bool {
		$needle = mb_strtolower( trim( $title ) );
		if ( '' === $needle ) {
			return false;
		}
		foreach ( Grouped_Archive::current_page_ids() as $id ) {
			if ( $post_id === $id ) {
				continue;
			}
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && '' === (string) $post->post_password && Kind_Facts::can_show( $post ) && mb_strtolower( trim( visitor_post_title( $post ) ) ) === $needle ) {
				return true;
			}
		}
		return false;
	}
}
