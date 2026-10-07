<?php
/**
 * Staff Picks: the top-rated board game plays, above the play archive.
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
 * The Staff Picks block (issue 232).
 *
 * Lists the best-rated board game plays: published play posts of a
 * viewable post type with no password, a BGG ID and no RAWG or Steam ID (a play with both files
 * under video), rated above 0. Ranked by rating, where a stored rating
 * above 5 counts as 5, then newest, then highest ID, with one pick per
 * game. Each pick prints its box, a title link and "Rated N of 5", and
 * carries no microformats root: the archive item below is the post's
 * entry on the page. Past the first page, or when no play qualifies, the
 * block prints nothing, heading included.
 *
 * @since 1.9.0
 */
final class Staff_Picks {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	public const BLOCK = 'post-kinds-indieweb/staff-picks';

	/**
	 * Kind the picks come from.
	 *
	 * @var string
	 */
	private const KIND = 'play';

	/**
	 * Fewest rows each query reads. Repeats of one game take rows without
	 * adding picks, so the read continues in batches until the picks fill.
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
		$css = \PKIW_PATH . 'styles/staff-picks.css';
		if ( file_exists( $css ) ) {
			wp_register_style( 'pkiw-staff-picks', \PKIW_URL . 'styles/staff-picks.css', [], (string) filemtime( $css ) );
			wp_style_add_data( 'pkiw-staff-picks', 'path', $css );
		}

		wp_register_script(
			'pkiw-staff-picks-editor',
			\PKIW_URL . 'assets/js/staff-picks-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-staff-picks-editor', 'post-kinds-for-indieweb-in-block-themes' );

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
			'title'           => __( 'Staff Picks', 'post-kinds-for-indieweb-in-block-themes' ),
			'description'     => __( 'The top-rated board game plays, one per game, on the first page of an archive.', 'post-kinds-for-indieweb-in-block-themes' ),
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
			'editor_script'   => 'pkiw-staff-picks-editor',
			'style'           => 'pkiw-staff-picks',
		];
		register_block_type( self::BLOCK, $args );
	}

	/**
	 * Render the picks.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Markup, or '' past the first page or when no play qualifies.
	 */
	public static function render( array $attributes = [] ): string {
		if ( is_paged() ) {
			return '';
		}

		$picks = self::picks( max( 1, min( 6, (int) ( $attributes['count'] ?? 3 ) ) ) );
		if ( [] === $picks ) {
			return '';
		}

		$level   = max( 2, min( 5, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$heading = trim( (string) ( $attributes['heading'] ?? '' ) );
		if ( '' === $heading ) {
			$heading = __( 'Staff Picks', 'post-kinds-for-indieweb-in-block-themes' );
		}

		$items = '';
		foreach ( $picks as $at => $pick ) {
			$items .= self::item( $pick['post'], $pick['rating'], $level + 1, 0 === $at );
		}

		$heading_id = wp_unique_id( 'pkiw-staff-picks-' );

		return sprintf(
			'<section %1$s><h%2$d id="%3$s" class="pkiw-staff-picks__heading">%4$s</h%2$d><ul class="pkiw-staff-picks__list">%5$s</ul></section>',
			get_block_wrapper_attributes(
				[
					'class'           => 'pkiw-staff-picks',
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
	 * The picks, best first, one per BGG ID.
	 *
	 * Reads in batches, so many plays of one game can't crowd out the
	 * next game.
	 *
	 * @param int $count How many picks, 1 to 6.
	 * @return array<int, array{post: \WP_Post, rating: float}>
	 */
	private static function picks( int $count ): array {
		global $wpdb;

		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return [];
		}

		// Only types visitors can view: publish alone doesn't make a post public.
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		$types    = $taxonomy ? array_values( array_filter( array_map( 'strval', (array) $taxonomy->object_type ), 'is_post_type_viewable' ) ) : [];
		if ( [] === $types ) {
			return [];
		}

		$prefix = Meta_Fields::PREFIX . self::KIND . '_';
		$batch  = max( self::BATCH, $count * 4 );
		$picks  = [];
		$games  = [];
		$offset = 0;

		// The ranked candidates: board plays (a non-blank BGG row and no
		// non-blank RAWG or Steam row, the engine's blank rule) rated above 0.
		$sql = "SELECT picks.ID, picks.bgg_id, picks.rating FROM (
				SELECT p.ID, p.post_date,
					( SELECT TRIM( b.meta_value ) FROM {$wpdb->postmeta} b WHERE b.post_id = p.ID AND b.meta_key = %s AND TRIM( b.meta_value ) <> '' ORDER BY b.meta_id LIMIT 1 ) AS bgg_id,
					( SELECT CAST( r.meta_value AS DECIMAL(4,1) ) FROM {$wpdb->postmeta} r WHERE r.post_id = p.ID AND r.meta_key = %s ORDER BY r.meta_id LIMIT 1 ) AS rating
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d
				WHERE p.post_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ")
					AND p.post_status = 'publish'
					AND LENGTH( p.post_password ) = 0
					AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} v WHERE v.post_id = p.ID AND v.meta_key IN ( %s, %s ) AND TRIM( v.meta_value ) <> '' )
			) AS picks
			WHERE picks.bgg_id IS NOT NULL AND picks.rating > 0
			ORDER BY LEAST( picks.rating, 5 ) DESC, picks.post_date DESC, picks.ID DESC
			LIMIT %d OFFSET %d";

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- WP_Query can't order by a capped rating; every value is a placeholder.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders only; the IN list is one %s per post type.
					...array_merge(
						[ $prefix . 'bgg_id', $prefix . 'rating', (int) $term->term_taxonomy_id ],
						$types,
						[ $prefix . 'rawg_id', $prefix . 'steam_id', $batch, $offset ]
					)
				)
			);

			foreach ( $rows as $row ) {
				$game = (string) $row->bgg_id;
				$post = get_post( (int) $row->ID );
				if ( isset( $games[ $game ] ) || ! $post instanceof \WP_Post ) {
					continue;
				}
				$games[ $game ] = true;
				$picks[]        = [
					'post'   => $post,
					'rating' => (float) $row->rating,
				];
				if ( count( $picks ) >= $count ) {
					return $picks;
				}
			}
			$offset += $batch;
			$more    = count( $rows ) === $batch;
		} while ( $more );

		return $picks;
	}

	/**
	 * One pick: its box, a title link and the rating as text.
	 *
	 * @param \WP_Post $post   Play post.
	 * @param float    $rating Stored rating; the label caps it at 5.
	 * @param int      $level  Heading level for the title.
	 * @param bool     $first  Whether this is the first pick.
	 * @return string
	 */
	private static function item( \WP_Post $post, float $rating, int $level, bool $first ): string {
		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );
		if ( '' === $title ) {
			$title = untitled_name( $post );
		}

		$cover = self::cover( kind_picture( $post->ID ), $first );
		$box   = '' !== $cover
			? '<div class="pkiw-staff-picks__box">' . $cover . '</div>'
			: '<div class="pkiw-staff-picks__box pkiw-staff-picks__box--text" aria-hidden="true">' . esc_html( $title ) . '</div>';

		return sprintf(
			'<li class="pkiw-staff-picks__item">%1$s<h%2$d class="pkiw-staff-picks__title"><a class="pkiw-staff-picks__link" href="%3$s">%4$s</a></h%2$d><p class="pkiw-staff-picks__rating">%5$s</p></li>',
			$box,
			$level,
			esc_url( (string) get_permalink( $post ) ),
			esc_html( $title ),
			esc_html( card_rating_label( $rating ) )
		);
	}

	/**
	 * A pick's cover: decorative, eager and high priority on the first pick only.
	 *
	 * @param array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool} $picture From kind_picture().
	 * @param bool                                                                                                       $first   Whether this is the first pick.
	 * @return string Image markup, or '' with no picture.
	 */
	private static function cover( array $picture, bool $first ): string {
		if ( '' === $picture['url'] ) {
			return '';
		}

		$loading = $first
			? [
				'loading'       => 'eager',
				'fetchpriority' => 'high',
			]
			: [ 'loading' => 'lazy' ];

		$img = $picture['attachment_id'] > 0
			? wp_get_attachment_image(
				$picture['attachment_id'],
				'medium_large',
				false,
				array_merge(
					[
						'class' => 'pkiw-staff-picks__cover',
						'alt'   => '',
					],
					$loading
				)
			)
			: '';
		if ( '' === $img ) {
			$img = sprintf( '<img class="pkiw-staff-picks__cover" src="%s" alt="" />', esc_url( $picture['url'] ) );
		}

		// Core's loading heuristics can add or drop these; set them outright.
		$tags = new \WP_HTML_Tag_Processor( $img );
		if ( ! $tags->next_tag( [ 'tag_name' => 'img' ] ) ) {
			return '';
		}
		$tags->set_attribute( 'alt', '' );
		$tags->set_attribute( 'decoding', 'async' );
		$tags->set_attribute( 'loading', $loading['loading'] );
		if ( $first ) {
			$tags->set_attribute( 'fetchpriority', 'high' );
		} else {
			$tags->remove_attribute( 'fetchpriority' );
		}

		return $tags->get_updated_html();
	}
}
