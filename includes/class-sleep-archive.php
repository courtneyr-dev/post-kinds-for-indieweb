<?php
/**
 * Sleep archive: the facts reader, the night entry and the monthly sheet (issue 247).
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Archive_Group;
use PKIW\Grouping\Date_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The sleep kind's public reader and its archive.
 *
 * Courtney's answer A on the health data question: duration, the night and
 * the moon are public. Bedtime and wake time print only when the post opts
 * in. Opting in takes two things at once, the sleep card's own
 * showTimesPublicly attribute and the post's toggle row, because
 * Meta_Fields::health_details_visible() reads only the post row and a stale
 * or hand-written row must not open a card that says no.
 *
 * Every value here comes from the post's first sleep card, the way
 * Card_Meta_Sync reads it, so the card is the single source. The archive
 * never prints a bedtime or a wake time, even when the post opts in.
 *
 * A protected night's facts are [] for everyone. The sheet still lists it as
 * a cell built from frame(): the date, the moon and the phase, no title, no
 * duration and nothing from the card. The sleep kind's own feed leaves
 * protected nights out.
 *
 * @since 1.9.0
 */
final class Sleep_Archive {

	/**
	 * Kind slug.
	 *
	 * @var string
	 */
	public const KIND = 'sleep';

	/**
	 * The sleep card block.
	 *
	 * @var string
	 */
	public const CARD = 'post-kinds-indieweb/sleep-card';

	/**
	 * The night entry block.
	 *
	 * @var string
	 */
	public const ENTRY = 'post-kinds-indieweb/sleep-night';

	/**
	 * The month source the archive groups by: months newest first, nights
	 * oldest first inside a month.
	 *
	 * @var string
	 */
	public const SOURCE = 'sleep_month';

	/**
	 * Longest duration a bar shows in full, in minutes.
	 *
	 * @var int
	 */
	private const BAR_MINUTES = 720;

	/**
	 * Wire the reader, the group source, the entry block and the hooks. Safe
	 * to call more than once.
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		Grouped_Archive::register_source( new Date_Source( self::SOURCE, 'month', 'asc' ), self::KIND );

		add_filter( 'the_content', [ self::class, 'prepend_record' ], 8 );
		add_filter( 'posts_where', [ self::class, 'exclude_protected' ], 10, 2 );

		if ( did_action( 'init' ) ) {
			self::register_entry_block();
		} else {
			add_action( 'init', [ self::class, 'register_entry_block' ] );
		}
	}

	/**
	 * Register the editor script, the stylesheet and the dynamic entry block.
	 *
	 * @return void
	 */
	public static function register_entry_block(): void {
		wp_register_script(
			'pkiw-sleep-night-editor',
			\PKIW_URL . 'assets/js/sleep-night-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-sleep-night-editor', 'post-kinds-for-indieweb-in-block-themes' );

		wp_register_style( 'pkiw-sleep-night', \PKIW_URL . 'styles/sleep-night.css', [], \PKIW_VERSION );

		Grouped_Archive::register_entry_block(
			self::ENTRY,
			[
				'shape'        => Grouped_Archive::ENTRY_LINE,
				'source'       => self::SOURCE,
				'heading_ids'  => true,
				'section_body' => [ self::class, 'sheet' ],
				'classes'      => [
					'wrapper'     => 'pkiw-grouped pkiw-sleep-archive',
					'section'     => 'pkiw-group pkiw-sleep-archive__month',
					'heading'     => 'pkiw-group__heading pkiw-sleep-archive__month-label',
					'items'       => 'pkiw-group__items pkiw-sleep-sheet',
					'placeholder' => 'pkiw-group-placeholder pkiw-sleep-archive__continued',
				],
			]
		);

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY ) ) {
			return;
		}

		/**
		 * Block type arguments. No block.json, so apiVersion 3 is declared here.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Sleep nights', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render' ],
			'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			'attributes'      => Grouped_Archive::entry_attributes(
				[
					'linesPerPage' => [
						'default' => 31,
					],
				]
			),
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'ancestor'        => [ 'core/post-template' ],
			'editor_script'   => 'pkiw-sleep-night-editor',
			'style'           => 'pkiw-sleep-night',
		];
		register_block_type( self::ENTRY, $args );
	}

	/**
	 * The public facts for a sleep post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Empty for a post that isn't a sleep post, one the requester can't read or one behind a password.
	 */
	public static function facts( int $post_id ): array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || self::KIND !== Kind_Facts::kind_of( $post ) ) {
			return [];
		}

		$card    = self::first_card( $post );
		$times   = self::card_times( $card, $post->ID );
		$minutes = $times['minutes'];
		$stored  = trim( wp_strip_all_tags( $post->post_title ) );
		$night   = class_exists( __NAMESPACE__ . '\\Sleep_Night' ) ? Sleep_Night::night_date( $post ) : self::night_of_post( $post );
		$label   = self::night_label( $night );
		$moon    = self::moon( $night );
		$bedtime = self::time_parts( $times['bedtime'], $times['visible'] );
		$wake    = self::time_parts( $times['wake'], $times['visible'] );

		return [
			'name'             => '' !== $stored ? trim( wp_strip_all_tags( get_the_title( $post ) ) ) : untitled_name( $post, false ),
			'name_is_title'    => '' !== $stored,
			'name_kind'        => stream_card_kind_label( $post ),
			'has_card'         => null !== $card,
			'night'            => $night,
			'night_display'    => $label['display'],
			'night_short'      => $label['short'],
			'duration_minutes' => $minutes,
			'duration_iso'     => card_minutes_iso( $minutes ),
			'duration_label'   => card_minutes_label( $minutes ),
			'duration_ratio'   => $minutes > 0 ? round( min( $minutes, self::BAR_MINUTES ) / self::BAR_MINUTES, 4 ) : 0.0,
			'phase'            => $moon['slug'],
			'phase_label'      => $moon['label'],
			'phase_principal'  => $moon['principal'],
			'bedtime_iso'      => $bedtime['iso'],
			'bedtime_label'    => $bedtime['label'],
			'wake_iso'         => $wake['iso'],
			'wake_label'       => $wake['label'],
		];
	}

	/**
	 * The public date frame of a sleep post: the night and the moon, read from
	 * the local post date only, never from meta or the card. It shows nothing a
	 * dated permalink doesn't, so a cell can print it when facts() is [].
	 *
	 * @param \WP_Post $post Post.
	 * @return array{night: string, night_short: string, night_display: string, phase: string, phase_label: string, phase_principal: bool}
	 */
	public static function frame( \WP_Post $post ): array {
		$night = self::night_of_post( $post );
		$label = self::night_label( $night );
		$moon  = self::moon( $night );

		return [
			'night'           => $night,
			'night_short'     => $label['short'],
			'night_display'   => $label['display'],
			'phase'           => $moon['slug'],
			'phase_label'     => $moon['label'],
			'phase_principal' => $moon['principal'],
		];
	}

	/**
	 * Print the record on a sleep single that holds no sleep card.
	 *
	 * A sleep post made without a card (a kind chosen in the sidebar, say)
	 * still gets its moon phase and date ahead of its own text. A feed and an
	 * archive keep the stored content, a REST request is never a singular one,
	 * and an auto-excerpt never shows the record, because core unhooks
	 * do_blocks() while it builds one.
	 *
	 * @internal Hooked to `the_content` at 8, before do_blocks().
	 *
	 * @param mixed $content Post content.
	 * @return mixed Content, with the card ahead of it when the post needs one.
	 */
	public static function prepend_record( $content ) {
		if ( ! is_string( $content ) || ! is_singular( 'post' ) || is_feed() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post || get_queried_object_id() !== $post->ID || self::KIND !== Kind_Facts::kind_of( $post ) ) {
			return $content;
		}
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::CARD ) || str_contains( $content, 'wp:' . self::CARD ) ) {
			return $content;
		}

		return '<!-- wp:' . self::CARD . ' /-->' . "\n\n" . $content;
	}

	/**
	 * Keep protected sleep posts out of the sleep kind's own feed.
	 *
	 * The sleep sheet lists a protected night as a date, a moon and a phase
	 * and nothing else (plan 247, X01), so its HTML archive keeps the post.
	 * The plan is silent on feeds, so the kind feed leaves protected nights
	 * out, and the site feed, category and tag feeds behave as core does for
	 * every kind: a protected item is title-only, with no card content.
	 *
	 * @internal Hooked to `posts_where`.
	 *
	 * @param mixed $where SQL WHERE clause.
	 * @param mixed $query The query.
	 * @return mixed
	 */
	public static function exclude_protected( $where, $query ) {
		if ( ! is_string( $where ) || ! $query instanceof \WP_Query || ! $query->is_main_query() || is_admin() ) {
			return $where;
		}
		if ( ! $query->is_feed() || ! $query->is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return $where;
		}

		global $wpdb;

		return $where . " AND LENGTH({$wpdb->posts}.post_password) = 0";
	}

	/**
	 * The moon drawing slot's markup, from the theme.
	 *
	 * The plugin prints no drawing. A theme or site returns trusted markup
	 * (an inline SVG, say) from the `pkiw_sleep_moon_html` filter.
	 *
	 * @param string $phase   Phase slug.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function moon_html( string $phase, int $post_id ): string {
		/**
		 * Filters the moon drawing a sleep night prints.
		 *
		 * @since 1.9.0
		 *
		 * @param string $html    Markup. Default ''.
		 * @param string $phase   Phase slug: new, waxing-crescent, first-quarter, waxing-gibbous, full, waning-gibbous, last-quarter or waning-crescent.
		 * @param int    $post_id Post ID.
		 */
		return (string) apply_filters( 'pkiw_sleep_moon_html', '', $phase, $post_id );
	}

	/**
	 * Render the sleep night entry block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string Markup.
	 */
	public static function render( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Grouped_Archive::render_entry(
			self::ENTRY,
			$attributes,
			$block,
			static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::entry_html( $post, $attributes, $in_section ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		);
	}

	/**
	 * One month's body: an ordered list of the engine's own items.
	 *
	 * The engine already wraps each night in `li.h-entry`, so this adds a
	 * class to it and prints no list item of its own. A second h-entry item
	 * would nest one entry inside another.
	 *
	 * @param Archive_Group        $group Group. Unused.
	 * @param array<int, string>   $items The month's `li` strings by post ID, in query order.
	 * @param array<string, mixed> $settings Entry settings. Unused.
	 * @return string
	 */
	public static function sheet( Archive_Group $group, array $items, array $settings ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$html = '';
		foreach ( $items as $item ) {
			$processor = new \WP_HTML_Tag_Processor( $item );
			if ( $processor->next_tag( [ 'tag_name' => 'li' ] ) ) {
				$processor->add_class( 'pkiw-sleep-night' );
				$item = $processor->get_updated_html();
			}
			$html .= $item;
		}

		return '<ol class="pkiw-sleep-sheet">' . $html . '</ol>';
	}

	/**
	 * One night, inside the engine's list item.
	 *
	 * DOM order: the stored title if there is one, the date, the duration, the
	 * bar, the moon and the phase. One link, named by those parts. A night with
	 * no public duration prints its date, moon and phase only. Never a bedtime
	 * or a wake time, opted in or not.
	 *
	 * @param \WP_Post             $post       Post.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether a month heading stands above the entry.
	 * @return string
	 */
	private static function entry_html( \WP_Post $post, array $attributes, bool $in_section ): string {
		$facts = self::facts( $post->ID );
		$day   = [] === $facts ? self::frame( $post ) : $facts;
		$id    = $post->ID;

		$base_level = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$level      = max( 2, min( 6, $base_level + ( $in_section ? 1 : 0 ) ) );
		// A night with no facts (a protected one) prints its date, moon and phase only: no stored title either.
		$titled    = [] !== $facts && '' !== trim( wp_strip_all_tags( $post->post_title ) );
		$title_id  = 'pkiw-sleep-t-' . $id;
		$date_id   = 'pkiw-sleep-d-' . $id;
		$length_id = 'pkiw-sleep-h-' . $id;
		$phase_id  = 'pkiw-sleep-p-' . $id;
		$minutes   = (int) ( $facts['duration_minutes'] ?? 0 );
		$separator = '<span class="pk-sr-only">, </span>';
		$permalink = esc_url( (string) get_permalink( $post ) );

		$named_by = array_filter(
			[
				$titled ? $title_id : '',
				$date_id,
				$minutes > 0 ? $length_id : '',
				'' !== (string) $day['phase_label'] ? $phase_id : '',
			]
		);
		$link     = sprintf( '<a class="u-url" href="%1$s" aria-labelledby="%2$s">', $permalink, esc_attr( implode( ' ', $named_by ) ) );

		$date = sprintf(
			'<time class="dt-published pkiw-sleep-night__date" id="%1$s" datetime="%2$s">%3$s%4$s</time>',
			esc_attr( $date_id ),
			esc_attr( (string) $day['night'] ),
			esc_html( (string) $day['night_short'] ),
			$separator
		);

		if ( $titled ) {
			$head = sprintf(
				'<h%1$d class="pkiw-sleep-night__title p-name" id="%2$s">%3$s%4$s</a></h%1$d>%5$s',
				$level,
				esc_attr( $title_id ),
				$link,
				esc_html( trim( wp_strip_all_tags( get_the_title( $post ) ) ) ),
				$date
			);
		} else {
			$head = $link . $date . '</a>';
		}

		$length = '';
		$bar    = '';
		if ( $minutes > 0 ) {
			$length = sprintf(
				'<span class="pkiw-sleep-night__duration" id="%1$s"><span class="p-sleep"><time datetime="%2$s">%3$s</time></span>%4$s</span>',
				esc_attr( $length_id ),
				esc_attr( (string) $facts['duration_iso'] ),
				esc_html( (string) $facts['duration_label'] ),
				$separator
			);
			$bar    = sprintf(
				'<span class="pkiw-sleep-night__bar" aria-hidden="true" style="--pkiw-sleep-ratio:%s"></span>',
				esc_attr( self::ratio_text( (float) $facts['duration_ratio'] ) )
			);
		}

		$moon  = '<span class="pkiw-sleep-night__moon" aria-hidden="true">' . self::moon_html( (string) $day['phase'], $id ) . '</span>';
		$phase = sprintf( '<span class="pkiw-sleep-night__phase" id="%1$s">%2$s</span>', esc_attr( $phase_id ), esc_html( (string) $day['phase_label'] ) );
		$by    = function_exists( __NAMESPACE__ . '\\entry_author_html' ) ? entry_author_html( $post ) : '';

		return $head . $length . $bar . $moon . $phase . ( '' !== $by ? '<span hidden>' . $by . '</span>' : '' );
	}

	/**
	 * The first sleep card's attributes, in document order, inner blocks included.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>|null Null when the post holds no sleep card.
	 */
	private static function first_card( \WP_Post $post ): ?array {
		if ( ! str_contains( $post->post_content, 'wp:' . self::CARD ) ) {
			return null;
		}

		foreach ( flatten_blocks( parse_blocks( $post->post_content ) ) as $block ) {
			if ( self::CARD === ( $block['blockName'] ?? '' ) ) {
				return (array) ( $block['attrs'] ?? [] );
			}
		}

		return null;
	}

	/**
	 * What the first card says about the night's length and clock times, and
	 * whether the times may print.
	 *
	 * The duration comes from the card, else from wake minus bedtime when that
	 * span is 1 to 1440 minutes. The times may print only when the card's own
	 * showTimesPublicly is on AND the post's toggle row is '1': either half
	 * alone opens nothing, because health_details_visible() reads only the row.
	 *
	 * @param array<string, mixed>|null $card    The first sleep card's attributes.
	 * @param int                       $post_id Post ID.
	 * @return array{minutes: int, bedtime: \DateTimeImmutable|null, wake: \DateTimeImmutable|null, visible: bool}
	 */
	private static function card_times( ?array $card, int $post_id ): array {
		if ( null === $card ) {
			return [
				'minutes' => 0,
				'bedtime' => null,
				'wake'    => null,
				'visible' => false,
			];
		}

		$bedtime = self::wall_time( $card['bedtime'] ?? null );
		$wake    = self::wall_time( $card['wakeTime'] ?? null );
		$minutes = self::whole_minutes( $card['durationMinutes'] ?? null );
		if ( 0 === $minutes && null !== $bedtime && null !== $wake ) {
			$span    = intdiv( $wake->getTimestamp() - $bedtime->getTimestamp(), 60 );
			$minutes = $span >= 1 && $span <= 1440 ? $span : 0;
		}

		return [
			'minutes' => $minutes,
			'bedtime' => $bedtime,
			'wake'    => $wake,
			'visible' => true === ( $card['showTimesPublicly'] ?? null ) && Meta_Fields::health_details_visible( $post_id ),
		];
	}

	/**
	 * A wall time's ISO and label, or two empty strings when it may not print.
	 *
	 * @param \DateTimeImmutable|null $time    Time.
	 * @param bool                    $visible Whether the times may print.
	 * @return array{iso: string, label: string}
	 */
	private static function time_parts( ?\DateTimeImmutable $time, bool $visible ): array {
		if ( ! $visible || null === $time ) {
			return [
				'iso'   => '',
				'label' => '',
			];
		}

		return [
			'iso'   => $time->format( 'Y-m-d\TH:i' ),
			'label' => (string) wp_date( (string) get_option( 'time_format' ), $time->getTimestamp() ),
		];
	}

	/**
	 * The moon for a night.
	 *
	 * @param string $night Y-m-d, or '' for none.
	 * @return array{slug: string, label: string, principal: bool}
	 */
	private static function moon( string $night ): array {
		return '' === $night
			? [
				'slug'      => '',
				'label'     => '',
				'principal' => false,
			]
			: Moon_Phase::for_night( $night, wp_timezone() );
	}

	/**
	 * A whole number of minutes from 1 to 1440, as Card_Meta_Sync accepts it.
	 *
	 * @param mixed $value Attribute value.
	 * @return int 0 for anything else.
	 */
	private static function whole_minutes( $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^\d{1,9}$/', trim( $value ) ) ) {
			$value = (int) $value;
		}

		return is_int( $value ) && $value >= 1 && $value <= 1440 ? $value : 0;
	}

	/**
	 * A card's site-local wall time, `YYYY-MM-DDTHH:MM`.
	 *
	 * @param mixed $value Attribute value.
	 * @return \DateTimeImmutable|null Null when it isn't a real date and time.
	 */
	private static function wall_time( $value ): ?\DateTimeImmutable {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ) {
			return null;
		}

		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );

		return $time instanceof \DateTimeImmutable && $time->format( 'Y-m-d\TH:i' ) === $value ? $time : null;
	}

	/**
	 * The night a post's own local date files under: its date minus twelve hours.
	 *
	 * @param \WP_Post $post Post.
	 * @return string Y-m-d, or '' for a post with no date.
	 */
	private static function night_of_post( \WP_Post $post ): string {
		$local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $post->post_date, wp_timezone() );

		return $local instanceof \DateTimeImmutable ? $local->modify( '-12 hours' )->format( 'Y-m-d' ) : '';
	}

	/**
	 * A night's long and short labels, read as a calendar day so a site west
	 * of UTC prints the day that was stored.
	 *
	 * @param string $night Y-m-d.
	 * @return array{display: string, short: string}
	 */
	private static function night_label( string $night ): array {
		[ $ymd, $display ] = card_calendar_date( $night );
		if ( '' === $ymd ) {
			return [
				'display' => '',
				'short'   => '',
			];
		}

		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $ymd ) );

		return [
			'display' => $display,
			'short'   => (string) wp_date( _x( 'M j', 'Short sleep night date', 'post-kinds-for-indieweb-in-block-themes' ), gmmktime( 12, 0, 0, $month, $day, $year ), new \DateTimeZone( 'UTC' ) ),
		];
	}

	/**
	 * A bar ratio as a CSS number: 0.6417, 0.5, 1.
	 *
	 * @param float $ratio Ratio from 0 to 1.
	 * @return string
	 */
	private static function ratio_text( float $ratio ): string {
		$text = rtrim( rtrim( number_format( $ratio, 4, '.', '' ), '0' ), '.' );

		return '' === $text ? '0' : $text;
	}
}
