<?php
/**
 * Medal row: the newest personal bests, above the exercise archive.
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
 * The medal row block (issue 245).
 *
 * Lists the newest personal bests from published exercise posts that share
 * their details: no password, a post row of `_pkiw_exercise_pb` and
 * `_pkiw_exercise_details_public` both '1', and facts that hold a personal
 * best with a category (the facts reader also needs the card's own toggle,
 * so a stale row can't open a card). Each medal prints the category and the
 * time as one link and carries no microformats root: the archive item below
 * is the post's entry on the page. Past the first page, or when nothing
 * qualifies, the block prints nothing, heading included.
 *
 * Modelled on Staff_Picks: registered in PHP, no block.json, a plain editor
 * script.
 *
 * @since 1.9.0
 */
final class Exercise_Medals {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	public const BLOCK = 'post-kinds-indieweb/medal-row';

	/**
	 * Fewest rows each query reads. Posts the card toggle hides take rows
	 * without adding medals, so the read continues in batches until the row fills.
	 *
	 * @var int
	 */
	private const BATCH = 24;

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( did_action( 'init' ) ) {
			$this->register_block();
		} else {
			add_action( 'init', [ $this, 'register_block' ] );
		}
	}

	/**
	 * Register the stylesheet, the editor script and the block.
	 *
	 * @return void
	 */
	public function register_block(): void {
		$css = \PKIW_PATH . 'styles/medal-row.css';
		if ( file_exists( $css ) ) {
			wp_register_style( 'pkiw-medal-row', \PKIW_URL . 'styles/medal-row.css', [], (string) filemtime( $css ) );
			wp_style_add_data( 'pkiw-medal-row', 'path', $css );
		}

		wp_register_script(
			'pkiw-medal-row-editor',
			\PKIW_URL . 'assets/js/medal-row-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-medal-row-editor', 'post-kinds-for-indieweb-in-block-themes' );

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments. No block.json, so apiVersion 3 is declared here.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Medal row', 'post-kinds-for-indieweb-in-block-themes' ),
			'description'     => __( 'The newest personal bests, on the first page of the exercise archive.', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render' ],
			'attributes'      => [
				'count'        => [
					'type'    => 'integer',
					'default' => 3,
				],
				'headingLevel' => [
					'type'    => 'integer',
					'default' => 2,
				],
				'heading'      => [
					'type'    => 'string',
					'default' => '',
					'role'    => 'content',
				],
			],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-medal-row-editor',
			'style'           => 'pkiw-medal-row',
		];
		register_block_type( self::BLOCK, $args );
	}

	/**
	 * Render the medal row.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Markup, or '' past the first page or when nothing qualifies.
	 */
	public static function render( array $attributes = [] ): string {
		if ( is_paged() ) {
			return '';
		}

		$medals = self::medals( max( 1, min( 6, (int) ( $attributes['count'] ?? 3 ) ) ) );
		if ( [] === $medals ) {
			return '';
		}

		$level   = max( 2, min( 5, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$heading = trim( wp_strip_all_tags( (string) ( $attributes['heading'] ?? '' ) ) );
		if ( '' === $heading ) {
			$heading = __( 'Personal bests', 'post-kinds-for-indieweb-in-block-themes' );
		}

		$items = '';
		foreach ( $medals as $medal ) {
			$items .= self::item( $medal['post'], $medal['facts'] );
		}

		$heading_id = wp_unique_id( 'pkiw-medal-row-' );

		return sprintf(
			'<section %1$s><h%2$d id="%3$s" class="pkiw-medal-row__heading">%4$s</h%2$d><ul class="pkiw-medal-row__list">%5$s</ul></section>',
			get_block_wrapper_attributes(
				[
					'class'           => 'pkiw-medal-row',
					'aria-labelledby' => $heading_id,
				]
			),
			$level,
			esc_attr( $heading_id ),
			esc_html( $heading ),
			$items
		);
	}

	/**
	 * The newest personal bests whose facts show one.
	 *
	 * @param int $count How many medals, 1 to 6.
	 * @return array<int, array{post: \WP_Post, facts: array<string, mixed>}>
	 */
	private static function medals( int $count ): array {
		global $wpdb;

		$term = get_term_by( 'slug', Exercise_Archive::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return [];
		}

		// Only types visitors can view: publish alone doesn't make a post public.
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		$types    = $taxonomy ? array_values( array_filter( array_map( 'strval', (array) $taxonomy->object_type ), 'is_post_type_viewable' ) ) : [];
		if ( [] === $types ) {
			return [];
		}

		$batch  = max( self::BATCH, $count * 4 );
		$offset = 0;
		$medals = [];

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d
			WHERE p.post_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ")
				AND p.post_status = 'publish'
				AND LENGTH( p.post_password ) = 0
				AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} b WHERE b.post_id = p.ID AND b.meta_key = %s AND b.meta_value = '1' )
				AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} o WHERE o.post_id = p.ID AND o.meta_key = %s AND o.meta_value = '1' )
			ORDER BY p.post_date DESC, p.ID DESC
			LIMIT %d OFFSET %d";

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A page of ordered IDs; WP_Query can't join two meta rows to a term this cheaply. Every value is a placeholder.
			$ids = (array) $wpdb->get_col(
				$wpdb->prepare(
					$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders only; the IN list is one %s per post type.
					...array_merge(
						[ (int) $term->term_taxonomy_id ],
						$types,
						[ Meta_Fields::PREFIX . 'exercise_pb', Meta_Fields::EXERCISE_PUBLIC_KEY, $batch, $offset ]
					)
				)
			);

			foreach ( $ids as $id ) {
				$post  = get_post( (int) $id );
				$facts = $post instanceof \WP_Post ? Kind_Facts::get( $post->ID ) : [];
				if ( ! $post instanceof \WP_Post || [] === $facts || empty( $facts['personal_best'] ) || '' === (string) $facts['pb_label'] ) {
					continue;
				}
				$medals[] = [
					'post'  => $post,
					'facts' => $facts,
				];
				if ( count( $medals ) >= $count ) {
					return $medals;
				}
			}
			$offset += $batch;
			$more    = count( $ids ) === $batch;
		} while ( $more );

		return $medals;
	}

	/**
	 * One medal: the category, the time and a link to the workout.
	 *
	 * No date, image or place. The workout's name is for screen readers; an
	 * untitled workout reads as its kind alone, so no date or year prints.
	 *
	 * @param \WP_Post             $post  Post.
	 * @param array<string, mixed> $facts Its facts.
	 * @return string
	 */
	private static function item( \WP_Post $post, array $facts ): string {
		$name = ! empty( $facts['name_is_title'] ) ? (string) $facts['name'] : (string) ( '' !== (string) $facts['name_kind'] ? $facts['name_kind'] : __( 'Exercise', 'post-kinds-for-indieweb-in-block-themes' ) );
		$time = (int) $facts['duration_seconds'] > 0
			? sprintf( ' <time class="pkiw-medal__time" datetime="%1$s">%2$s</time>', esc_attr( (string) $facts['duration_iso'] ), esc_html( (string) $facts['duration_label'] ) )
			: '';

		return sprintf(
			'<li class="pkiw-medal"><a class="pkiw-medal__link" href="%1$s"><span class="pkiw-medal__category">%2$s</span>%3$s<span class="pk-sr-only">, %4$s</span></a></li>',
			esc_url( (string) get_permalink( $post ) ),
			esc_html( (string) $facts['pb_label'] ),
			$time,
			esc_html( $name )
		);
	}
}
