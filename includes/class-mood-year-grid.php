<?php
/**
 * The mood year grid: one calendar year of moods on a grid.
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Date_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// family() and families() are this plugin's mood methods, not the removed fam extension's functions.
// phpcs:disable PHPCompatibility.Extensions.RemovedExtensions.famRemoved

/**
 * The mood year grid block (issue 248).
 *
 * Twelve month columns of days, one cell per real day. A day with one mood
 * is one link to it; a day with two is a diagonal cell, and a day with more
 * stripes, each linking to a card below the grid that lists that day's
 * moods. Only the date, a family code with the intensity digit, the label
 * and the intensity print. The note, emoji, mood time, kind label, excerpt,
 * author and location never do. A family code rides in every filled cell, so
 * color is never the only signal.
 *
 * The block draws the main query's posts: the archive's calendar paging
 * (pkiw_calendar_paging, answered here when the resolved template holds the
 * block) hands it one year per page. Private, draft, scheduled and
 * password-protected moods are out of that query, and the block drops them
 * again. In the editor's block-renderer preview it draws the newest year.
 *
 * Plain PHP registration with a plain editor script, as Staff_Picks does:
 * the bundle is not involved.
 *
 * @since 1.9.0
 */
final class Mood_Year_Grid {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	public const BLOCK = 'post-kinds-indieweb/mood-year-grid';

	/**
	 * Kind the grid draws.
	 *
	 * @var string
	 */
	private const KIND = 'mood';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pkiw_calendar_paging', [ self::class, 'calendar_paging' ], 10, 3 );

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
		$css = \PKIW_PATH . 'styles/mood-year-grid.css';
		if ( file_exists( $css ) ) {
			wp_register_style( 'pkiw-mood-year-grid', \PKIW_URL . 'styles/mood-year-grid.css', [], (string) filemtime( $css ) );
			wp_style_add_data( 'pkiw-mood-year-grid', 'path', $css );
		}

		wp_register_script(
			'pkiw-mood-year-grid-editor',
			\PKIW_URL . 'assets/js/mood-year-grid-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-mood-year-grid-editor', 'post-kinds-for-indieweb-in-block-themes' );

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
			'title'           => __( 'Mood Year Grid', 'post-kinds-for-indieweb-in-block-themes' ),
			'description'     => __( 'A year of moods on a grid, one calendar year per page of the mood archive.', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render' ],
			'attributes'      => [
				'headingLevel' => [
					'type'    => 'integer',
					'default' => 2,
				],
				'showLegend'   => [
					'type'    => 'boolean',
					'default' => true,
				],
			],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-mood-year-grid-editor',
			'style'           => 'pkiw-mood-year-grid',
		];
		register_block_type( self::BLOCK, $args );
	}

	/**
	 * Whether a template's markup holds the grid.
	 *
	 * @param string $template_content Block template markup, patterns expanded.
	 * @return bool
	 */
	public static function template_has_grid( string $template_content ): bool {
		return 1 === preg_match( '#<!--\s*wp:post-kinds-indieweb/mood-year-grid[\s/}]#', $template_content );
	}

	/**
	 * Page the mood archive by year when its template holds the grid.
	 *
	 * @internal Hooked to `pkiw_calendar_paging`.
	 *
	 * @param array<string, mixed>|null $plan    Plan an earlier callback chose.
	 * @param \WP_Term                  $term    Kind term.
	 * @param string                    $content Resolved template content.
	 * @return array<string, mixed>|null
	 */
	public static function calendar_paging( ?array $plan, \WP_Term $term, string $content ): ?array {
		if ( self::KIND === $term->slug && self::template_has_grid( $content ) ) {
			return [ 'unit' => 'year' ];
		}

		return $plan;
	}

	/**
	 * Render the grid.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Markup, or '' when no mood is visible, so the template's no-results block prints.
	 */
	public static function render( array $attributes = [] ): string {
		$level  = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$legend = ! array_key_exists( 'showLegend', $attributes ) || ! empty( $attributes['showLegend'] );

		[ $year, $posts ] = self::source();
		$days             = self::days( $posts, $year );
		if ( [] === $days ) {
			return '';
		}

		$families = Mood_Vocabulary::families();
		$present  = [];
		foreach ( $days as $entries ) {
			foreach ( $entries as $entry ) {
				$present[ $entry['facts']['family'] ] = true;
			}
		}

		$html = sprintf( '<h%1$d class="pkiw-mood-year__year">%2$s</h%1$d>', $level, esc_html( $year ) );
		if ( $legend ) {
			$html .= self::legend( array_intersect_key( $families, $present ) );
		}
		$html .= self::grid( $year, $days, $level + 1 );
		$html .= self::day_cards( $days, $level + 1 );

		return sprintf(
			'<div %1$s>%2$s</div>',
			get_block_wrapper_attributes( [ 'class' => 'pkiw-mood-year pkiw-cal pkiw-cal--year' ] ),
			$html
		);
	}

	/**
	 * The year to draw and the posts that may be on it.
	 *
	 * The served page reads the main query, which calendar paging has
	 * narrowed to one year. The block-renderer preview has no such query and
	 * runs one for the newest year with a visible mood.
	 *
	 * @return array{0: string, 1: list<\WP_Post>} Year (YYYY) and posts, '' and [] for none.
	 */
	private static function source(): array {
		if ( Kind_Archive_Layouts::is_block_preview() ) {
			return self::newest_year();
		}

		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query ) {
			return [ '', [] ];
		}

		$posts    = array_values( array_filter( (array) $wp_query->posts, static fn( $post ): bool => $post instanceof \WP_Post ) );
		$calendar = $wp_query->get( 'pkiw_calendar' );
		$year     = is_array( $calendar ) ? (string) ( $calendar['key'] ?? '' ) : '';
		if ( 1 !== preg_match( '/^\d{4}$/', $year ) ) {
			$first = [] === $posts ? null : get_post_datetime( $posts[0], 'date', 'local' );
			$year  = $first instanceof \DateTimeImmutable ? $first->format( 'Y' ) : '';
		}

		return [ $year, $posts ];
	}

	/**
	 * The newest year with a visible mood, and that year's posts.
	 *
	 * @return array{0: string, 1: list<\WP_Post>}
	 */
	private static function newest_year(): array {
		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return [ '', [] ];
		}

		$years = Date_Source::units( $term, 'year' );
		if ( [] === $years ) {
			return [ '', [] ];
		}

		$types = Date_Source::viewable_types();
		if ( [] === $types ) {
			return [ '', [] ];
		}

		// The pkiw_calendar var brings the engine's password guard into the SQL.
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
				'date_query'          => [ [ 'year' => (int) $years[0] ] ],
				'tax_query'           => [
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => [ (int) $term->term_id ],
					],
				],
				'pkiw_calendar'       => [
					'unit'  => 'year',
					'key'   => $years[0],
					'keys'  => [ $years[0] ],
					'page'  => 1,
					'pages' => count( $years ),
				],
			]
		);

		return [ $years[0], array_values( array_filter( $query->posts, static fn( $post ): bool => $post instanceof \WP_Post ) ) ];
	}

	/**
	 * The visible moods of a year, by local day.
	 *
	 * Only published, unprotected posts of the mood kind whose facts exist
	 * count. Within a day the order is post_date, then ID.
	 *
	 * @param \WP_Post[] $posts Candidates.
	 * @param string     $year  Year (YYYY).
	 * @return array<string, list<array{post: \WP_Post, facts: array<string, mixed>}>> Day (Y-m-d) => entries, in date order.
	 */
	private static function days( array $posts, string $year ): array {
		if ( '' === $year ) {
			return [];
		}

		$days = [];
		foreach ( $posts as $post ) {
			if ( 'publish' !== $post->post_status || '' !== $post->post_password || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
				continue;
			}

			$facts = kind_facts( $post->ID );
			$day   = (string) ( $facts['day'] ?? '' );
			if ( [] === $facts || ! str_starts_with( $day, $year . '-' ) ) {
				continue;
			}

			$days[ $day ][] = [
				'post'  => $post,
				'facts' => $facts,
			];
		}

		foreach ( $days as &$entries ) {
			usort(
				$entries,
				static fn( array $a, array $b ): int => [ $a['post']->post_date, $a['post']->ID ] <=> [ $b['post']->post_date, $b['post']->ID ]
			);
		}
		unset( $entries );
		ksort( $days );

		return $days;
	}

	/**
	 * The legend: the families on the page, the intensity line and the split key.
	 *
	 * @param array<string, array{key: string, label: string, code: string}> $families Families on the page, in vocabulary order.
	 * @return string
	 */
	private static function legend( array $families ): string {
		$items = '';
		foreach ( $families as $family ) {
			$items .= sprintf(
				'<li data-family="%1$s"><span class="pkiw-mood-legend__swatch" aria-hidden="true"></span><span class="pkiw-mood-legend__code">%2$s</span> <span class="pkiw-mood-legend__label">%3$s</span></li>',
				esc_attr( $family['key'] ),
				esc_html( $family['code'] ),
				esc_html( $family['label'] )
			);
		}

		return sprintf(
			'<div class="pkiw-mood-legend" role="group" aria-label="%1$s"><ul class="pkiw-mood-legend__families">%2$s</ul><p class="pkiw-mood-legend__intensity">%3$s</p><p class="pkiw-mood-legend__split">%4$s</p></div>',
			esc_attr__( 'Legend', 'post-kinds-for-indieweb-in-block-themes' ),
			$items,
			esc_html__( 'Intensity: 1 (low) to 5 (high)', 'post-kinds-for-indieweb-in-block-themes' ),
			esc_html(
				sprintf(
					/* translators: 1: key for a day with two moods; 2: key for a day with three or more. */
					_x( '%1$s. %2$s.', 'mood grid split-cell key', 'post-kinds-for-indieweb-in-block-themes' ),
					__( 'Diagonal: two moods that day', 'post-kinds-for-indieweb-in-block-themes' ),
					__( 'Stripes: three or more moods that day', 'post-kinds-for-indieweb-in-block-themes' )
				)
			)
		);
	}

	/**
	 * The month columns and the day gutter.
	 *
	 * @param string                                                                  $year  Year (YYYY).
	 * @param array<string, list<array{post: \WP_Post, facts: array<string, mixed>}>> $days  Entries by day.
	 * @param int                                                                     $level Month heading level.
	 * @return string
	 */
	private static function grid( string $year, array $days, int $level ): string {
		$gutter = '';
		for ( $day = 1; $day <= 31; $day++ ) {
			$gutter .= '<li>' . (int) $day . '</li>';
		}

		$months = '';
		for ( $month = 1; $month <= 12; $month++ ) {
			$first = date_create_immutable_from_format( '!Y-n-j', "{$year}-{$month}-1", wp_timezone() );
			if ( ! $first instanceof \DateTimeImmutable ) {
				continue;
			}

			$cells = '';
			$total = (int) $first->format( 't' );
			for ( $day = 1; $day <= $total; $day++ ) {
				$ymd    = sprintf( '%s-%02d-%02d', $year, $month, $day );
				$cells .= self::cell( $ymd, $days[ $ymd ] ?? [] );
			}

			$months .= sprintf(
				'<li class="pkiw-mood-month"><h%1$d class="pkiw-mood-month__name"><span aria-hidden="true">%2$s</span><span class="pkiw-mood-year__sr">%3$s</span></h%1$d><div class="pkiw-mood-month__days">%4$s</div></li>',
				$level,
				esc_html( (string) wp_date( 'M', $first->getTimestamp() ) ),
				esc_html( (string) wp_date( 'F', $first->getTimestamp() ) ),
				$cells
			);
		}

		return '<div class="pkiw-mood-year__grid"><ol class="pkiw-mood-year__gutter" aria-hidden="true">' . $gutter . '</ol><ol class="pkiw-mood-year__months">' . $months . '</ol></div>';
	}

	/**
	 * One day cell.
	 *
	 * @param string                                                   $ymd     Day, Y-m-d.
	 * @param list<array{post: \WP_Post, facts: array<string, mixed>}> $entries That day's moods, in order.
	 * @return string
	 */
	private static function cell( string $ymd, array $entries ): string {
		$class = 'pkiw-mood-day pkiw-cal__day';
		if ( [] === $entries ) {
			return sprintf( '<div class="%1$s" data-day="%2$s"></div>', esc_attr( $class ), esc_attr( $ymd ) );
		}

		$date = self::date_label( $ymd );
		if ( 1 === count( $entries ) ) {
			$entry = $entries[0];
			$code  = self::code( $entry['facts'] );

			return sprintf(
				'<div class="%1$s h-entry" data-day="%2$s" data-family="%3$s"><a class="pkiw-mood-day__link pkiw-cal__link u-url" href="%4$s" aria-label="%5$s"><span class="pkiw-mood-day__code" aria-hidden="true">%6$s</span></a>%7$s</div>',
				esc_attr( $class ),
				esc_attr( $ymd ),
				esc_attr( (string) $entry['facts']['family'] ),
				esc_url( (string) get_permalink( $entry['post'] ) ),
				esc_attr(
					sprintf(
						/* translators: 1: date, such as "September 3, 2026"; 2: the mood and its intensity; 3: the code printed in the cell, such as "J4". */
						_x( '%1$s: %2$s (%3$s)', 'mood grid cell name', 'post-kinds-for-indieweb-in-block-themes' ),
						$date,
						self::entry_text( $entry['facts'] ),
						$code
					)
				),
				esc_html( $code ),
				self::props( $entry['post'], $entry['facts'] )
			);
		}

		$names     = [];
		$codes     = [];
		$segments  = '';
		$with_text = 2 === count( $entries );
		foreach ( $entries as $entry ) {
			$names[]   = self::entry_text( $entry['facts'] );
			$codes[]   = self::code( $entry['facts'] );
			$segments .= sprintf(
				'<span class="pkiw-mood-day__seg" data-family="%1$s">%2$s</span>',
				esc_attr( (string) $entry['facts']['family'] ),
				$with_text ? '<span class="pkiw-mood-day__code" aria-hidden="true">' . esc_html( end( $codes ) ) . '</span>' : ''
			);
		}

		$name = sprintf(
			/* translators: 1: date, such as "September 8, 2026"; 2: every mood that day, oldest first. */
			_x( '%1$s: %2$s', 'mood grid multi-mood cell name', 'post-kinds-for-indieweb-in-block-themes' ),
			$date,
			implode( _x( '; ', 'separator between the moods in a cell name', 'post-kinds-for-indieweb-in-block-themes' ), $names )
		);
		if ( $with_text ) {
			// The visible text of a split cell is part of its name (WCAG 2.5.3).
			$name = sprintf(
				/* translators: 1: a cell name; 2: the codes printed in the cell, such as "J4, P2". */
				_x( '%1$s (%2$s)', 'mood grid cell name with codes', 'post-kinds-for-indieweb-in-block-themes' ),
				$name,
				implode( ', ', $codes )
			);
		}

		return sprintf(
			'<div class="%1$s %2$s" data-day="%3$s" data-count="%4$d"><a class="pkiw-mood-day__link pkiw-cal__link" href="#mood-day-%3$s" aria-label="%5$s">%6$s</a></div>',
			esc_attr( $class ),
			$with_text ? 'pkiw-mood-day--split' : 'pkiw-mood-day--stripes',
			esc_attr( $ymd ),
			count( $entries ),
			esc_attr( $name ),
			$segments
		);
	}

	/**
	 * The cards for the days with several moods, in date order.
	 *
	 * @param array<string, list<array{post: \WP_Post, facts: array<string, mixed>}>> $days  Entries by day.
	 * @param int                                                                     $level Card heading level.
	 * @return string '' when no day has several moods.
	 */
	private static function day_cards( array $days, int $level ): string {
		$cards = '';
		foreach ( $days as $ymd => $entries ) {
			if ( count( $entries ) < 2 ) {
				continue;
			}

			// Entries that read the same get their time, so every link has a name of its own.
			$counts = array_count_values( array_map( static fn( array $entry ): string => self::entry_text( $entry['facts'] ), $entries ) );
			$items  = '';
			foreach ( $entries as $entry ) {
				$text = self::entry_text( $entry['facts'] );
				if ( $counts[ $text ] > 1 ) {
					$when = get_post_datetime( $entry['post'], 'date', 'local' );
					if ( $when instanceof \DateTimeImmutable ) {
						$text = sprintf(
							/* translators: 1: a mood and its intensity; 2: the time of day, which tells two identical entries apart. */
							_x( '%1$s, %2$s', 'mood day card entry with time', 'post-kinds-for-indieweb-in-block-themes' ),
							$text,
							(string) wp_date( (string) get_option( 'time_format' ), $when->getTimestamp() )
						);
					}
				}

				$items .= sprintf(
					'<li class="h-entry" data-family="%1$s"><a class="pkiw-mood-daycard__link u-url" href="%2$s" aria-labelledby="mood-day-%3$s-h mood-entry-%4$d"><span id="mood-entry-%4$d">%5$s</span></a>%6$s</li>',
					esc_attr( (string) $entry['facts']['family'] ),
					esc_url( (string) get_permalink( $entry['post'] ) ),
					esc_attr( (string) $ymd ),
					(int) $entry['post']->ID,
					esc_html( $text ),
					self::props( $entry['post'], $entry['facts'] )
				);
			}

			$cards .= sprintf(
				'<li id="mood-day-%1$s" class="pkiw-mood-daycard"><h%2$d id="mood-day-%1$s-h" class="pkiw-mood-daycard__date">%3$s</h%2$d><ul class="pkiw-mood-daycard__entries">%4$s</ul></li>',
				esc_attr( (string) $ymd ),
				$level,
				esc_html( self::date_label( (string) $ymd ) ),
				$items
			);
		}

		return '' === $cards ? '' : '<ol class="pkiw-mood-daycards">' . $cards . '</ol>';
	}

	/**
	 * The hidden microformats properties of one entry, beside its link.
	 *
	 * The label is the p-name only when the author gave one: a synthetic
	 * "Mood" is never a name (X8). A sibling of the link, because a nested
	 * link inside the cell's own would be invalid.
	 *
	 * @param \WP_Post             $post  Post.
	 * @param array<string, mixed> $facts Mood facts.
	 * @return string
	 */
	private static function props( \WP_Post $post, array $facts ): string {
		$html = '';
		if ( ! empty( $facts['has_label'] ) ) {
			$html .= '<data class="p-name" value="' . esc_attr( (string) $facts['label'] ) . '"></data>';
		}
		$html .= '<data class="dt-published" value="' . esc_attr( stream_card_published_datetime( $post ) ) . '"></data>';

		return '<span class="pk-entry-props" hidden>' . $html . entry_author_html( $post ) . '</span>';
	}

	/**
	 * The text a mood prints in a cell: its family code and, with one, its intensity digit.
	 *
	 * @param array<string, mixed> $facts Mood facts.
	 * @return string Such as "J4", or "J" with no intensity.
	 */
	private static function code( array $facts ): string {
		return (string) $facts['family_code'] . ( null === $facts['intensity'] ? '' : (string) (int) $facts['intensity'] );
	}

	/**
	 * A mood and its intensity, such as "Happy, intensity 4 of 5".
	 *
	 * @param array<string, mixed> $facts Mood facts.
	 * @return string The name alone with no intensity.
	 */
	private static function entry_text( array $facts ): string {
		if ( null === $facts['intensity'] ) {
			return (string) $facts['name'];
		}

		return sprintf(
			/* translators: 1: a mood, such as "Happy"; 2: its intensity from 1 to 5; 3: the highest intensity, 5. */
			__( '%1$s, intensity %2$d of %3$d', 'post-kinds-for-indieweb-in-block-themes' ),
			(string) $facts['name'],
			(int) $facts['intensity'],
			Mood_Vocabulary::MAX_INTENSITY
		);
	}

	/**
	 * A day in the site's date style, such as "September 3, 2026".
	 *
	 * @param string $ymd Day, Y-m-d.
	 * @return string
	 */
	private static function date_label( string $ymd ): string {
		$day = date_create_immutable_from_format( '!Y-m-d', $ymd, wp_timezone() );

		return $day instanceof \DateTimeImmutable
			? (string) wp_date( _x( 'F j, Y', 'mood grid day name', 'post-kinds-for-indieweb-in-block-themes' ), $day->getTimestamp() )
			: $ymd;
	}
}
