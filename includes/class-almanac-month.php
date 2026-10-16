<?php
/**
 * The almanac month block: one calendar month of weather posts per page.
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Date_Source;
use PKIW\Integrations\Simple_Location_Weather;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `post-kinds-indieweb/almanac-month` from PHP and draws the
 * weather archive's month sheet from the inherited main query.
 *
 * The block asks the engine for month paging (`pkiw_calendar_paging`), so
 * page N of /kind/weather/ holds the Nth newest month with a published
 * weather post, oldest day first. It prints one link per entry, the reading
 * once, and a strip of the year's months. It never runs the_content, so
 * nothing Simple Location appends to a post can reach it.
 *
 * Issue 246.
 *
 * @since 1.9.0
 */
final class Almanac_Month {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	public const BLOCK = 'post-kinds-indieweb/almanac-month';

	/**
	 * Kind slug the block belongs to.
	 *
	 * @var string
	 */
	private const KIND = 'weather';

	/**
	 * Wire hooks and the block.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pkiw_calendar_paging', [ $this, 'calendar_paging' ], 10, 3 );

		if ( did_action( 'init' ) ) {
			$this->register_block();
		} else {
			add_action( 'init', [ $this, 'register_block' ] );
		}
	}

	/**
	 * Register the editor script and the block, with no block.json.
	 *
	 * @return void
	 */
	public function register_block(): void {
		wp_register_script(
			'pkiw-almanac-month-editor',
			\PKIW_URL . 'assets/js/almanac-month-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-almanac-month-editor', 'post-kinds-for-indieweb-in-block-themes' );

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
			'title'           => __( 'Almanac month', 'post-kinds-for-indieweb-in-block-themes' ),
			'description'     => __( 'One calendar month of weather posts, a day at a time, with a strip of the year’s months.', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render' ],
			'uses_context'    => [ 'templateSlug' ],
			'attributes'      => [
				'headingLevel'  => [
					'type'    => 'integer',
					'default' => 2,
				],
				'showYearStrip' => [
					'type'    => 'boolean',
					'default' => true,
				],
			],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-almanac-month-editor',
		];
		register_block_type( self::BLOCK, $args );
	}

	/**
	 * Answer the engine's calendar paging filter for the weather archive.
	 *
	 * Only when the resolved template holds this block: otherwise the
	 * archive pages by post count like any other kind.
	 *
	 * @internal Hooked to `pkiw_calendar_paging`.
	 *
	 * @param mixed $plan    Plan so far.
	 * @param mixed $term    Kind term.
	 * @param mixed $content Resolved template content, patterns expanded.
	 * @return mixed A month plan for the weather archive, else the plan unchanged.
	 */
	public function calendar_paging( $plan, $term = null, $content = '' ) {
		if ( ! $term instanceof \WP_Term || self::KIND !== $term->slug || ! is_string( $content ) ) {
			return $plan;
		}

		if ( 1 !== preg_match( '/<!--\s*wp:' . preg_quote( self::BLOCK, '/' ) . '(?![\w-])/', $content ) ) {
			return $plan;
		}

		return [
			'unit'  => 'month',
			'order' => 'ASC',
		];
	}

	/**
	 * Render the month sheet.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused.
	 * @param \WP_Block|null       $block      Unused.
	 * @return string Markup, or '' outside the weather archive, on an empty archive and past the last page.
	 */
	public static function render( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		$month = '';
		if ( Kind_Archive_Layouts::is_block_preview() ) {
			// The editor previews the newest month, in place of a page of the archive.
			$units = Date_Source::units( $term, 'month' );
			$month = $units[0] ?? '';
			$posts = '' === $month ? [] : self::month_posts( $term, $month );
		} else {
			global $wp_query;
			if ( ! $wp_query instanceof \WP_Query || ! $wp_query->is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
				return '';
			}
			$plan = $wp_query->get( 'pkiw_calendar' );
			if ( ! is_array( $plan ) || 'month' !== ( $plan['unit'] ?? '' ) ) {
				return '';
			}
			$month = (string) ( $plan['key'] ?? '' );
			$posts = $wp_query->posts;
		}

		$posts = self::visible_posts( $posts, $month );
		if ( [] === $posts ) {
			return '';
		}
		if ( '' === $month ) {
			$month = substr( $posts[0]->post_date, 0, 7 );
		}

		$level    = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$strip    = (bool) ( $attributes['showYearStrip'] ?? true );
		$heading  = wp_unique_id( 'pkiw-almanac-' );
		$days     = self::days( $posts );
		$sheet    = '';
		$timezone = new \DateTimeZone( 'UTC' );

		foreach ( $days as $day => $entries ) {
			$items = '';
			foreach ( $entries as $post ) {
				$items .= self::entry( $post, $level + 1 );
			}
			$sheet .= sprintf(
				'<li class="pkiw-almanac__day pkiw-cal__day" data-pkiw-date="%1$s"><span class="pkiw-almanac__daynum" aria-hidden="true">%2$d</span><ul class="pkiw-almanac__entries">%3$s</ul></li>',
				esc_attr( $day ),
				(int) substr( $day, 8, 2 ),
				$items
			);
		}

		$first = gmmktime( 12, 0, 0, (int) substr( $month, 5, 2 ), 1, (int) substr( $month, 0, 4 ) );

		return sprintf(
			'<section %1$s><h%2$d id="%3$s" class="pkiw-almanac__heading">%4$s</h%2$d><ol class="pkiw-almanac__days">%5$s</ol>%6$s</section>',
			get_block_wrapper_attributes(
				[
					'class'           => 'pkiw-almanac pkiw-cal pkiw-cal--month',
					'data-pkiw-month' => $month,
					'aria-labelledby' => $heading,
				]
			),
			$level,
			esc_attr( $heading ),
			esc_html( (string) wp_date( 'F Y', $first, $timezone ) ),
			$sheet,
			$strip ? self::year_strip( $term, $month ) : ''
		);
	}

	/**
	 * The month's published, unprotected weather posts, for the editor preview.
	 *
	 * @param \WP_Term $term  Weather term.
	 * @param string   $month Month key, Y-m.
	 * @return \WP_Post[]
	 */
	private static function month_posts( \WP_Term $term, string $month ): array {
		$types = Date_Source::viewable_types();
		if ( [] === $types ) {
			return [];
		}

		$query = new \WP_Query(
			[
				'post_type'           => $types,
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'orderby'             => [
					'date' => 'ASC',
					'ID'   => 'ASC',
				],
				'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One kind term, one request.
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => [ $term->term_id ],
					],
				],
				'date_query'          => [
					[
						'year'  => (int) substr( $month, 0, 4 ),
						'month' => (int) substr( $month, 5, 2 ),
					],
				],
			]
		);

		return array_values( array_filter( $query->posts, static fn( $post ): bool => $post instanceof \WP_Post ) );
	}

	/**
	 * The posts the sheet may print: published, unprotected, weather, in the month, oldest first.
	 *
	 * The engine's SQL already leaves the others out; this is a second guard
	 * behind it, for a query that holds a post it should not.
	 *
	 * @param array<int, mixed> $posts Posts.
	 * @param string            $month Month key, Y-m, or '' for any.
	 * @return \WP_Post[]
	 */
	private static function visible_posts( array $posts, string $month ): array {
		$kept = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post
				|| 'publish' !== $post->post_status
				|| 0 !== strlen( $post->post_password )
				|| ! has_term( self::KIND, Taxonomy::TAXONOMY, $post )
				|| ( '' !== $month && ! str_starts_with( $post->post_date, $month . '-' ) )
			) {
				continue;
			}
			$kept[] = $post;
		}

		usort(
			$kept,
			static fn( \WP_Post $a, \WP_Post $b ): int => [ $a->post_date, $a->ID ] <=> [ $b->post_date, $b->ID ]
		);

		return $kept;
	}

	/**
	 * Posts by their stored local day, days ascending.
	 *
	 * @param \WP_Post[] $posts Posts, oldest first.
	 * @return array<string, \WP_Post[]> Day (Y-m-d) => posts.
	 */
	private static function days( array $posts ): array {
		$days = [];
		foreach ( $posts as $post ) {
			$day = card_calendar_date( $post->post_date )[0];
			if ( '' === $day ) {
				continue;
			}
			$days[ $day ][] = $post;
		}
		ksort( $days );

		return $days;
	}

	/**
	 * One entry: the mark, the single link, a titled post's reading, and the hidden properties.
	 *
	 * @param \WP_Post $post          Weather post.
	 * @param int      $heading_level Heading level for the entry.
	 * @return string Markup.
	 */
	private static function entry( \WP_Post $post, int $heading_level ): string {
		$reading = Simple_Location_Weather::reading( $post->ID );
		$text    = null !== $reading ? $reading['text'] : '';
		$title   = '' !== trim( $post->post_title ) ? trim( wp_strip_all_tags( get_the_title( $post ) ) ) : '';

		$mark = '';
		if ( null !== $reading && 'observation' === $reading['source'] && '' !== $reading['icon'] ) {
			$mark = '<span class="pkiw-almanac__mark" data-pkiw-weather-icon="' . esc_attr( $reading['icon'] ) . '" aria-hidden="true"></span>';
		}

		// A real title is the entry's name. A reading names an untitled post but
		// is not a name, and a post with neither is called by its kind (X8).
		if ( '' !== $title ) {
			$label = '<span class="p-name">' . esc_html( $title ) . '</span>';
		} elseif ( '' !== $text ) {
			$label = '<span class="p-weather">' . esc_html( $text ) . '</span>';
		} else {
			$label = esc_html( get_kind_label( stream_card_kind_label( $post ), self::KIND, 'almanac-month' ) );
		}

		$when = sprintf(
			/* translators: 1: date, 2: time of day. */
			__( '%1$s at %2$s', 'post-kinds-for-indieweb-in-block-themes' ),
			get_the_date( '', $post ),
			get_the_time( '', $post )
		);

		$beside = '' !== $title && '' !== $text ? '<p class="p-weather">' . esc_html( $text ) . '</p>' : '';

		return sprintf(
			'<li class="pkiw-almanac__entry h-entry">%1$s<h%2$d class="pkiw-almanac__title"><a class="u-url pkiw-cal__link" href="%3$s">%4$s<span class="screen-reader-text">%5$s</span></a></h%2$d>%6$s<span class="pkiw-almanac__props" hidden><time class="dt-published" datetime="%7$s"></time>%8$s</span></li>',
			$mark,
			$heading_level,
			esc_url( (string) get_permalink( $post ) ),
			$label, // Escaped above.
			esc_html( ', ' . $when ),
			$beside, // Escaped above.
			esc_attr( (string) get_post_time( 'c', true, $post ) ),
			entry_author_html( $post ) // Escaped by entry_author_html().
		);
	}

	/**
	 * The strip of the displayed year's twelve months.
	 *
	 * A month with a published weather post links to its page, and the
	 * displayed month is the current one. The rest are plain text.
	 *
	 * @param \WP_Term $term  Weather term.
	 * @param string   $month Displayed month, Y-m.
	 * @return string Markup.
	 */
	private static function year_strip( \WP_Term $term, string $month ): string {
		$year  = (int) substr( $month, 0, 4 );
		$units = Date_Source::units( $term, 'month' );
		$utc   = new \DateTimeZone( 'UTC' );
		$base  = get_term_link( $term );
		$items = '';

		for ( $number = 1; $number <= 12; $number++ ) {
			$key   = sprintf( '%04d-%02d', $year, $number );
			$stamp = gmmktime( 12, 0, 0, $number, 1, $year );
			$short = esc_html( (string) wp_date( 'M', $stamp, $utc ) );
			$page  = array_search( $key, $units, true );

			if ( false === $page || ! is_string( $base ) ) {
				$items .= '<li class="pkiw-year-strip__item"><span class="pkiw-year-strip__month">' . $short . '</span></li>';
				continue;
			}

			$items .= sprintf(
				'<li class="pkiw-year-strip__item"><a class="pkiw-year-strip__link"%1$s href="%2$s"><span>%3$s</span><span class="screen-reader-text">%4$s</span></a></li>',
				$key === $month ? ' aria-current="page"' : '',
				esc_url( self::page_url( $base, (int) $page + 1 ) ),
				$short,
				esc_html( (string) wp_date( 'F Y', $stamp, $utc ) )
			);
		}

		return sprintf(
			'<nav class="pkiw-year-strip" aria-label="%1$s"><ul class="pkiw-year-strip__months">%2$s</ul></nav>',
			/* translators: %d: year. */
			esc_attr( sprintf( __( 'Months of %d', 'post-kinds-for-indieweb-in-block-themes' ), $year ) ),
			$items
		);
	}

	/**
	 * The URL of a page of the archive.
	 *
	 * @param string $base Term link, which is page 1.
	 * @param int    $page Page number.
	 * @return string URL.
	 */
	private static function page_url( string $base, int $page ): string {
		if ( $page <= 1 ) {
			return $base;
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'paged', $page, $base );
		}

		return trailingslashit( $base ) . user_trailingslashit( 'page/' . $page, 'paged' );
	}
}
