<?php
/**
 * Exercise archive: the facts reader, the week training log and its paging.
 *
 * @package PKIW
 * @since 1.9.0
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
 * Everything a theme or a render path reads about an exercise post (issue 245).
 *
 * Courtney's answer on the health data question sets the tiers. Activity and
 * the date are public. Distance, duration, splits and the personal best
 * print only while the card's own `showDetailsPublicly` attribute AND the
 * post's `_pkiw_exercise_details_public` row both say so: the row is the
 * health rule's answer (Meta_Fields::health_details_visible() reads the row
 * alone), the card is the author's. Either one off hides all of them, for
 * every requester alike. Post Kinds stores no heart rate, calories, other
 * biometrics or start and end clock times, so no key here names one, and the
 * card's `origin` never leaves the database.
 *
 * The archive pages by whole weeks (Date_Source 'post_week', paged through
 * the `pkiw_calendar_paging` filter) and prints each week as seven day cells.
 *
 * @since 1.9.0
 */
final class Exercise_Archive {

	/**
	 * Kind slug.
	 *
	 * @var string
	 */
	public const KIND = 'exercise';

	/**
	 * Week training log entry block.
	 *
	 * @var string
	 */
	public const ENTRY = 'post-kinds-indieweb/exercise-entry';

	/**
	 * The exercise card block.
	 *
	 * @var string
	 */
	public const CARD = 'post-kinds-indieweb/exercise-card';

	/**
	 * Weeks to a page when the entry block names none.
	 *
	 * @var int
	 */
	private const DEFAULT_WEEKS = 4;

	/**
	 * Kilometers in a mile.
	 *
	 * @var float
	 */
	private const KM_PER_MILE = 1.609344;

	/**
	 * Wire hooks and registrations.
	 *
	 * Every callback is static, so a second call adds nothing.
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register(
			self::KIND,
			[ self::class, 'facts' ],
			[
				'place_name'     => 'name',
				'place_locality' => 'locality',
				'place_region'   => 'region',
				'place_country'  => 'country',
				'place_street'   => 'street',
			]
		);

		if ( did_action( 'init' ) ) {
			$this->register_entry_block();
		} else {
			add_action( 'init', [ $this, 'register_entry_block' ] );
		}

		add_filter( 'pkiw_calendar_paging', [ self::class, 'calendar_paging' ], 10, 3 );
		add_action( 'pre_get_posts', [ self::class, 'flag_kind_feed' ] );
		add_filter( 'posts_where', [ self::class, 'kind_feed_where' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'print_editor_data' ] );
	}

	/**
	 * Register the entry block's editor script and stylesheet, and the block.
	 *
	 * No block.json, so apiVersion 3 is declared here (the Staff Picks shape).
	 *
	 * @return void
	 */
	public function register_entry_block(): void {
		wp_register_script(
			'pkiw-exercise-entry-editor',
			\PKIW_URL . 'assets/js/exercise-entry-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-exercise-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );

		$css = \PKIW_PATH . 'styles/exercise-entry.css';
		wp_register_style( 'pkiw-exercise-entry', \PKIW_URL . 'styles/exercise-entry.css', [], file_exists( $css ) ? (string) filemtime( $css ) : \PKIW_VERSION );

		$entry = [
			'shape'        => Grouped_Archive::ENTRY_LINE,
			'heading_ids'  => true,
			'section_body' => [ self::class, 'week_body' ],
			'classes'      => [
				'wrapper'     => 'pkiw-grouped pkiw-exercise-log',
				'section'     => 'pkiw-group pkiw-exercise-week',
				'heading'     => 'pkiw-group__heading pkiw-exercise-week__heading',
				'items'       => 'pkiw-group__items pkiw-exercise-week__items',
				'placeholder' => 'pkiw-group-placeholder pkiw-exercise-week__continued',
			],
		];
		// The week source belongs to Kind_Archive_Layouts. The engine throws for an unregistered one, and a throw on init takes the site down.
		if ( Grouped_Archive::source( 'post_week' ) instanceof Date_Source ) {
			$entry['source'] = 'post_week';
		}
		Grouped_Archive::register_entry_block( self::ENTRY, $entry );

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY ) ) {
			return;
		}

		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Exercise entries', 'post-kinds-for-indieweb-in-block-themes' ),
			'description'     => __( 'Whole weeks of workouts, one day cell for each day.', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render' ],
			'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			'attributes'      => Grouped_Archive::entry_attributes(
				[
					'weeksPerPage' => [
						'type'    => 'integer',
						'default' => self::DEFAULT_WEEKS,
					],
				]
			),
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'ancestor'        => [ 'core/post-template' ],
			'editor_script'   => 'pkiw-exercise-entry-editor',
			'style'           => 'pkiw-exercise-entry',
		];
		register_block_type( self::ENTRY, $args );
	}

	/**
	 * Print the editor's activity and personal best lists on the blocks script.
	 *
	 * The card's edit view reads `window.pkiwExercise`; it falls back to its
	 * own values when this is missing.
	 *
	 * @return void
	 */
	public static function print_editor_data(): void {
		wp_add_inline_script(
			'post-kinds-indieweb-blocks',
			'window.pkiwExercise = ' . wp_json_encode(
				[
					'activities'   => self::activity_labels(),
					'pbCategories' => self::pb_categories(),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Activity names the editor suggests. Any text is allowed.
	 *
	 * @return string[]
	 */
	public static function activity_labels(): array {
		return [
			__( 'Walk', 'post-kinds-for-indieweb-in-block-themes' ),
			__( 'Run', 'post-kinds-for-indieweb-in-block-themes' ),
			__( 'Cycle', 'post-kinds-for-indieweb-in-block-themes' ),
			__( 'Hike', 'post-kinds-for-indieweb-in-block-themes' ),
			__( 'Yoga', 'post-kinds-for-indieweb-in-block-themes' ),
			__( 'Swim', 'post-kinds-for-indieweb-in-block-themes' ),
		];
	}

	/**
	 * Personal best categories: stored key => label.
	 *
	 * @return array<string, string>
	 */
	public static function pb_categories(): array {
		return [
			'1mi'      => __( '1 mile', 'post-kinds-for-indieweb-in-block-themes' ),
			'5k'       => __( '5K', 'post-kinds-for-indieweb-in-block-themes' ),
			'10k'      => __( '10K', 'post-kinds-for-indieweb-in-block-themes' ),
			'half'     => __( 'Half marathon', 'post-kinds-for-indieweb-in-block-themes' ),
			'marathon' => __( 'Marathon', 'post-kinds-for-indieweb-in-block-themes' ),
		];
	}

	/**
	 * The unit week totals print in: 'mi' on an imperial site, else 'km'.
	 *
	 * Neither dev nor live stores a `sloc_measurements` row. 'imperial' comes
	 * from Simple Location's register_setting() default on plugins_loaded 11
	 * for locale en_US, so with Simple Location inactive, or another locale
	 * (default 'si'), totals switch to km with no change here.
	 *
	 * @return string 'mi' or 'km'.
	 */
	public static function total_unit(): string {
		$unit = 'imperial' === get_option( 'sloc_measurements' ) ? 'mi' : 'km';

		/**
		 * Filters the unit exercise week totals print in.
		 *
		 * @since 1.9.0
		 *
		 * @param string $unit 'mi' or 'km'.
		 */
		$filtered = apply_filters( 'pkiw_exercise_total_unit', $unit );

		return is_string( $filtered ) && in_array( $filtered, [ 'km', 'mi' ], true ) ? $filtered : $unit;
	}

	/**
	 * A personal best category as its label: the list's label, else the text as typed.
	 *
	 * @param string $category Stored category.
	 * @return string
	 */
	public static function pb_label( string $category ): string {
		$category = trim( $category );
		$labels   = self::pb_categories();

		return $labels[ strtolower( $category ) ] ?? $category;
	}

	/**
	 * The public exercise facts for a post.
	 *
	 * Reads the meta Card_Meta_Sync writes from the card, and the card's own
	 * toggle. Every opt-in value (distance, unit, duration, splits, personal
	 * best and its category) is empty unless Meta_Fields::get_visible_health_fields()
	 * AND the card's `showDetailsPublicly` allow it, and a hidden value
	 * returns exactly what a never-stored one returns. Never returns the
	 * origin, a start or end, or any biometric. Place keys are cut to the
	 * public location tier by Kind_Facts.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Facts, or [] for another kind or a post the requester can't read.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}

		$stored  = trim( wp_strip_all_tags( (string) apply_filters( 'the_title', $post->post_title, $post->ID ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter; get_the_title() would prefix a private post with "Private:".
		$named   = '' !== $stored;
		$parts   = untitled_fallback_name( $post );
		$visible = Meta_Fields::get_visible_health_fields( $post->ID );
		$more    = self::opt_in_values( $post, $visible );

		$activity = ! empty( $visible['exercise_activity'] ) ? trim( (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . 'exercise_activity', true ) ) : '';
		$day      = card_calendar_date( substr( (string) $post->post_date, 0, 10 ) );
		$distance = $more['distance'];
		$seconds  = $more['seconds'];
		$category = $more['category'];

		$place = get_checkin_location( $post );

		return [
			'name'                => $named ? $stored : untitled_name( $post, false ),
			'name_is_title'       => $named,
			'name_kind'           => $named ? '' : $parts['kind'],
			'name_date'           => $named ? '' : $parts['date'],
			'activity'            => $activity,
			'activity_in_content' => self::activity_in_content( $post ),
			'date_iso'            => $day[0],
			'date_display'        => $day[1],
			'distance'            => $distance,
			'distance_unit'       => $more['unit'],
			'distance_label'      => null === $distance ? '' : self::distance_label( $distance, $more['unit'] ),
			'duration_seconds'    => $seconds,
			'duration_iso'        => card_duration_iso( $seconds ),
			'duration_label'      => card_clock_duration( $seconds ),
			'splits'              => $more['splits'],
			'personal_best'       => $more['best'],
			'pb_category'         => $category,
			'pb_label'            => '' === $category ? '' : self::pb_label( $category ),
			'place_name'          => (string) ( $place['name'] ?? '' ),
			'place_locality'      => (string) ( $place['city'] ?? '' ),
			'place_region'        => (string) ( $place['region'] ?? '' ),
			'place_country'       => (string) ( $place['country'] ?? '' ),
			'place_street'        => (string) ( $place['address'] ?? '' ),
		];
	}

	/**
	 * The opt-in values a post may print, or their empty forms.
	 *
	 * Every value needs the card's own toggle AND the health rule's visible
	 * field for it. A hidden value is the empty form a never-stored one has,
	 * so the two can't be told apart.
	 *
	 * @param \WP_Post            $post    Post.
	 * @param array<string, bool> $visible Meta_Fields::get_visible_health_fields() for the post.
	 * @return array{distance:?float, unit:string, seconds:int, splits:array<int, array{index:int, unit:string, label:string, iso:string}>, best:bool, category:string}
	 */
	private static function opt_in_values( \WP_Post $post, array $visible ): array {
		$open = self::card_details_public( $post );
		$may  = static fn( string $key ): bool => $open && ! empty( $visible[ $key ] );
		$meta = static fn( string $key ) => get_post_meta( $post->ID, Meta_Fields::PREFIX . $key, true );

		// The unit row is 'km' on every card (the block default the editor drops), so it means nothing without a distance.
		$card_unit = 'mi' === $meta( 'exercise_distance_unit' ) ? 'mi' : 'km';
		$distance  = null;
		if ( $may( 'exercise_distance' ) && is_numeric( $meta( 'exercise_distance' ) ) && (float) $meta( 'exercise_distance' ) > 0 ) {
			$distance = (float) $meta( 'exercise_distance' );
		}

		$splits = [];
		if ( $may( 'exercise_splits' ) ) {
			$rows = [];
			foreach ( (array) json_decode( (string) $meta( 'exercise_splits' ), true ) as $split ) {
				if ( is_array( $split ) && isset( $split['i'], $split['t'] ) ) {
					$rows[] = [
						'i' => (int) $split['i'],
						't' => card_duration_seconds( (string) $split['t'] ),
					];
				}
			}
			$splits = self::split_rows( $rows, $card_unit );
		}

		return [
			'distance' => $distance,
			'unit'     => null === $distance ? '' : $card_unit,
			'seconds'  => $may( 'exercise_duration' ) ? card_duration_seconds( (string) $meta( 'exercise_duration' ) ) : 0,
			'splits'   => $splits,
			'best'     => $may( 'exercise_pb' ) && '1' === (string) $meta( 'exercise_pb' ),
			'category' => $may( 'exercise_pb_category' ) ? trim( (string) $meta( 'exercise_pb_category' ) ) : '',
		];
	}

	/**
	 * A distance as the label a card prints: '5.00 km'.
	 *
	 * @param float  $distance Distance.
	 * @param string $unit     'km' or 'mi'.
	 * @return string
	 */
	public static function distance_label( float $distance, string $unit ): string {
		return number_format_i18n( $distance, 2 ) . ' ' . ( 'mi' === $unit ? 'mi' : 'km' );
	}

	/**
	 * Split rows as facts and cards print them.
	 *
	 * @param array<int, array{i:int, t:int}> $splits Index and seconds, in stored order.
	 * @param string                          $unit   'km' or 'mi'.
	 * @return array<int, array{index:int, unit:string, label:string, iso:string}>
	 */
	public static function split_rows( array $splits, string $unit ): array {
		$rows = [];
		foreach ( $splits as $split ) {
			if ( $split['t'] <= 0 ) {
				continue;
			}
			$rows[] = [
				'index' => $split['i'],
				'unit'  => $unit,
				'label' => card_clock_duration( $split['t'] ),
				'iso'   => card_duration_iso( $split['t'] ),
			];
		}

		return $rows;
	}

	/**
	 * Whether the post's first exercise card has its own "Show details publicly" on.
	 *
	 * Meta_Fields::health_details_visible() reads only the post row, which a
	 * stale write (an import, WP-CLI) can set without the card. The card is
	 * the author's decision, so an opt-in value prints only when both agree.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function card_details_public( \WP_Post $post ): bool {
		if ( ! str_contains( (string) $post->post_content, 'wp:' . self::CARD ) ) {
			return false;
		}

		foreach ( flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( self::CARD === ( $block['blockName'] ?? '' ) ) {
				return true === ( $block['attrs']['showDetailsPublicly'] ?? false );
			}
		}

		return false;
	}

	/**
	 * Whether the post's body prints the activity as a p-exercise of its own.
	 *
	 * True for a legacy body (a paragraph with a p-exercise span, written
	 * before the card existed), which the page prints as written. The card
	 * then adds no second p-exercise.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function activity_in_content( \WP_Post $post ): bool {
		$content = (string) preg_replace( '#<!--\s+wp:' . preg_quote( self::CARD, '#' ) . '\b.*?(?:/-->|<!--\s+/wp:' . preg_quote( self::CARD, '#' ) . '\s+-->)#s', '', (string) $post->post_content );

		return 1 === preg_match( '/\bp-exercise\b/', $content );
	}

	/**
	 * The attributes of an exercise card, validated the way Card_Meta_Sync validates them.
	 *
	 * The card's edit preview renders from attributes the author hasn't saved
	 * yet, so render.php can't read facts. This returns each value, or its
	 * empty form for one that is absent or invalid, and `open`, the card's own
	 * "Show details publicly".
	 *
	 * @param array<string, mixed> $attributes Card attributes.
	 * @return array{activity:string, distance:?float, unit:string, seconds:int, splits:array<int, array{i:int, t:int}>, best:bool, category:string, open:bool}
	 */
	public static function card_values( array $attributes ): array {
		$activity = is_scalar( $attributes['activity'] ?? null ) ? sanitize_text_field( trim( (string) $attributes['activity'] ) ) : '';
		$raw      = $attributes['distance'] ?? null;
		$distance = is_numeric( $raw ) && (float) $raw > 0 && (float) $raw < 100000 ? (float) $raw : null;
		$unit     = 'mi' === strtolower( trim( is_scalar( $attributes['distanceUnit'] ?? null ) ? (string) $attributes['distanceUnit'] : '' ) ) ? 'mi' : 'km';
		$seconds  = is_string( $attributes['duration'] ?? null ) ? card_duration_seconds( strtoupper( trim( $attributes['duration'] ) ) ) : 0;
		$category = is_scalar( $attributes['pbCategory'] ?? null ) ? sanitize_text_field( trim( (string) $attributes['pbCategory'] ) ) : '';

		return [
			'activity' => $activity,
			'distance' => $distance,
			'unit'     => $unit,
			'seconds'  => $seconds,
			'splits'   => self::card_splits( $attributes['splits'] ?? null ),
			'best'     => true === ( $attributes['personalBest'] ?? false ),
			'category' => $category,
			'open'     => true === ( $attributes['showDetailsPublicly'] ?? false ),
		];
	}

	/**
	 * A card's splits, the entries Card_Meta_Sync would keep: a whole index above 0 and an ISO time above 0 seconds.
	 *
	 * @param mixed $value The `splits` attribute.
	 * @return array<int, array{i:int, t:int}>
	 */
	private static function card_splits( $value ): array {
		$splits = [];
		foreach ( is_array( $value ) ? $value : [] as $split ) {
			if ( ! is_array( $split ) || ! is_numeric( $split['i'] ?? null ) || ! is_string( $split['t'] ?? null ) ) {
				continue;
			}
			$time = card_duration_seconds( strtoupper( trim( $split['t'] ) ) );
			if ( $time > 0 && (int) $split['i'] > 0 ) {
				$splits[] = [
					'i' => (int) $split['i'],
					't' => $time,
				];
			}
		}

		return $splits;
	}

	/**
	 * Page the kind archive by whole weeks when the template holds the exercise entry.
	 *
	 * The weeks to a page come from the entry's `weeksPerPage`, which
	 * Grouped_Archive::main_query_plan() reads. Newest week first, and the
	 * posts newest first inside the query; week_body() sorts a week's days.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $plan    Plan so far.
	 * @param mixed $term    Kind term.
	 * @param mixed $content Resolved template content, patterns expanded.
	 * @return mixed A week plan for the exercise template, else $plan.
	 */
	public static function calendar_paging( $plan, $term, $content ) {
		if ( ! $term instanceof \WP_Term || self::KIND !== $term->slug || ! is_string( $content ) || ! str_contains( $content, 'wp:' . self::ENTRY ) ) {
			return $plan;
		}

		$query = $GLOBALS['wp_query'] ?? null;
		$entry = Grouped_Archive::main_query_plan( $query instanceof \WP_Query ? $query : new \WP_Query(), self::KIND, $content );
		if ( null === $entry ) {
			// The entry sits outside the inheriting Query Loop, so it configures nothing.
			return $plan;
		}

		return [
			'unit'  => 'week',
			'count' => $entry['weeks'] > 0 ? $entry['weeks'] : self::DEFAULT_WEEKS,
			'order' => 'DESC',
		];
	}

	/**
	 * Mark the exercise kind's feed so kind_feed_where() keeps protected posts out of it.
	 *
	 * Core lists a protected post in a feed with its title and a no-excerpt
	 * note. The calendar pages leave protected posts out, so the kind's own
	 * feed does too: a feed reader never learns a protected workout exists.
	 *
	 * @internal Hooked to `pre_get_posts`.
	 *
	 * @param mixed $query Query.
	 * @return void
	 */
	public static function flag_kind_feed( $query ): void {
		if ( $query instanceof \WP_Query && $query->is_main_query() && $query->is_feed() && $query->is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			$query->set( 'pkiw_exercise_feed', 1 );
		}
	}

	/**
	 * Leave password-protected posts out of the exercise kind's feed.
	 *
	 * `LENGTH()` tells an empty password from one of spaces, which the
	 * column's collation compares equal.
	 *
	 * @internal Hooked to `posts_where`.
	 *
	 * @param mixed $where WHERE clause.
	 * @param mixed $query Query.
	 * @return mixed
	 */
	public static function kind_feed_where( $where, $query ) {
		if ( ! $query instanceof \WP_Query || 1 !== (int) $query->get( 'pkiw_exercise_feed' ) ) {
			return $where;
		}

		global $wpdb;

		return $where . " AND LENGTH( {$wpdb->posts}.post_password ) = 0";
	}

	/**
	 * Render the exercise entry block.
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
			static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::bib_html( $post, $attributes, $in_section ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		);
	}

	/**
	 * One workout on a day: a title link, the activity and the entry's hidden properties.
	 *
	 * Nothing else prints: no distance, duration, date line, route, kind
	 * label, excerpt, image, arrow or visibility marker, and no clock time
	 * (the hidden dt-published is the local day). A protected post prints its
	 * "Protected: ..." title link and nothing from its card.
	 *
	 * @param \WP_Post             $post       Post.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether a section heading stands above the entry.
	 * @return string
	 */
	private static function bib_html( \WP_Post $post, array $attributes, bool $in_section ): string {
		$facts = Kind_Facts::get( $post->ID );
		$level = max( 2, min( 6, max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) ) ) + ( $in_section ? 1 : 0 ) ) );
		$href  = esc_url( (string) get_permalink( $post ) );

		if ( [] === $facts ) {
			$link = sprintf( '<a class="pkiw-exercise-bib__link u-url" href="%1$s">%2$s</a>', $href, esc_html( wp_strip_all_tags( get_the_title( $post ) ) ) );

			return ensure_entry_properties( sprintf( '<h%1$d class="pkiw-exercise-bib__title">%2$s</h%1$d>', $level, $link ), $post, false );
		}

		if ( ! empty( $facts['name_is_title'] ) ) {
			$link = sprintf( '<a class="pkiw-exercise-bib__link u-url p-name" href="%1$s">%2$s</a>', $href, esc_html( (string) $facts['name'] ) );
		} else {
			// An untitled workout shows its kind; the date is in the link's name only.
			$link = sprintf(
				'<a class="pkiw-exercise-bib__link u-url" href="%1$s">%2$s<span class="pk-sr-only">, %3$s</span></a>',
				$href,
				esc_html( (string) $facts['name_kind'] ),
				esc_html( (string) $facts['name_date'] )
			);
		}

		$html = sprintf( '<h%1$d class="pkiw-exercise-bib__title">%2$s</h%1$d>', $level, $link );
		if ( '' !== (string) $facts['activity'] ) {
			$classes = $facts['activity_in_content'] ? 'pkiw-exercise-bib__activity' : 'pkiw-exercise-bib__activity p-exercise';
			$html   .= sprintf( '<p class="%1$s">%2$s</p>', $classes, esc_html( (string) $facts['activity'] ) );
		}
		// The local day: no clock time appears anywhere on the archive.
		$html .= sprintf( '<data class="dt-published" value="%s" hidden></data>', esc_attr( (string) $facts['date_iso'] ) );

		return ensure_entry_properties( $html, $post, false );
	}

	/**
	 * A week's body: seven day cells and a one-line summary.
	 *
	 * @internal Registered as the entry's `section_body`.
	 *
	 * @param Archive_Group      $group Week group; its raw value is the week's first day.
	 * @param array<int, string> $items The engine's own `li` strings, keyed by post ID.
	 * @return string
	 */
	public static function week_body( Archive_Group $group, array $items ): string {
		$posts = [];
		foreach ( $items as $post_id => $html ) {
			$post = get_post( (int) $post_id );
			if ( $post instanceof \WP_Post ) {
				$posts[] = [
					'post' => $post,
					'html' => $html,
				];
			}
		}
		// Oldest first inside the week, whatever order the query ran in.
		usort(
			$posts,
			static fn( array $a, array $b ): int => [ (string) $a['post']->post_date, $a['post']->ID ] <=> [ (string) $b['post']->post_date, $b['post']->ID ]
		);

		$by_day = [];
		foreach ( $posts as $entry ) {
			$by_day[ substr( (string) $entry['post']->post_date, 0, 10 ) ][] = $entry['html'];
		}

		$utc   = new \DateTimeZone( 'UTC' );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $group->raw(), $utc );
		if ( ! $start instanceof \DateTimeImmutable ) {
			$first = array_key_first( $by_day );
			$start = null === $first ? false : \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $first, $utc );
		}
		if ( ! $start instanceof \DateTimeImmutable ) {
			return '';
		}

		return sprintf(
			'<ol class="pkiw-exercise-week__days pkiw-cal pkiw-cal--week">%1$s</ol>%2$s',
			self::day_cells( $start, $by_day ),
			self::summary( array_column( $posts, 'post' ) )
		);
	}

	/**
	 * The seven day cells of a week, each with its workouts or empty.
	 *
	 * @param \DateTimeImmutable                $start  The week's first day (UTC midnight).
	 * @param array<string, array<int, string>> $by_day Items by local day, oldest first.
	 * @return string
	 */
	private static function day_cells( \DateTimeImmutable $start, array $by_day ): string {
		$utc  = new \DateTimeZone( 'UTC' );
		$days = [];
		for ( $at = 0; $at < 7; $at++ ) {
			$days[] = $start->modify( "+{$at} days" )->format( 'Y-m-d' );
		}
		foreach ( array_keys( $by_day ) as $day ) {
			// A post outside the week (the week start changed under a cached page) still prints.
			if ( ! in_array( $day, $days, true ) ) {
				$days[] = (string) $day;
			}
		}
		sort( $days );

		$cells = '';
		foreach ( $days as $day ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day, $utc );
			if ( ! $date instanceof \DateTimeImmutable ) {
				continue;
			}
			$dow = (int) $date->format( 'w' );
			if ( empty( $by_day[ $day ] ) ) {
				$cells .= sprintf( '<li class="pkiw-exercise-day pkiw-exercise-day--empty pkiw-cal__day" data-pkiw-dow="%d" aria-hidden="true"></li>', $dow );
				continue;
			}
			// Noon UTC from the stored day, as card_calendar_date() does, so no zone shifts the label.
			$label  = (string) wp_date( _x( 'D, M j', 'Exercise week day label', 'post-kinds-for-indieweb-in-block-themes' ), $date->setTime( 12, 0 )->getTimestamp(), $utc );
			$cells .= sprintf(
				'<li class="pkiw-exercise-day pkiw-cal__day" data-pkiw-dow="%1$d"><time datetime="%2$s">%3$s</time><ul class="pkiw-exercise-day__bibs">%4$s</ul></li>',
				$dow,
				esc_attr( $day ),
				esc_html( $label ),
				implode( '', $by_day[ $day ] )
			);
		}

		return $cells;
	}

	/**
	 * A week's one-line summary: the workout count, then the distance and time totals.
	 *
	 * Totals read facts only, so a post whose details are hidden, and a
	 * protected one, adds nothing.
	 *
	 * @param array<int, \WP_Post> $posts The week's posts.
	 * @return string
	 */
	private static function summary( array $posts ): string {
		$count = count( $posts );
		if ( 0 === $count ) {
			return '';
		}

		$km      = 0.0;
		$seconds = 0;
		foreach ( $posts as $post ) {
			$facts = Kind_Facts::get( $post->ID );
			if ( [] === $facts ) {
				continue;
			}
			if ( null !== $facts['distance'] ) {
				$km += (float) $facts['distance'] * ( 'mi' === $facts['distance_unit'] ? self::KM_PER_MILE : 1.0 );
			}
			$seconds += (int) $facts['duration_seconds'];
		}

		$parts = [
			sprintf(
				/* translators: %s: number of workouts in a week. */
				_n( '%s workout', '%s workouts', $count, 'post-kinds-for-indieweb-in-block-themes' ),
				number_format_i18n( $count )
			),
		];
		if ( $km > 0 ) {
			$unit    = self::total_unit();
			$parts[] = number_format_i18n( 'mi' === $unit ? $km / self::KM_PER_MILE : $km, 1 ) . ' ' . $unit;
		}
		$minutes = intdiv( $seconds + 30, 60 );
		if ( $minutes > 0 ) {
			$parts[] = card_minutes_label( $minutes );
		}

		$html = '';
		foreach ( $parts as $at => $part ) {
			$html .= ( 0 < $at ? '<span aria-hidden="true"> · </span>' : '' ) . '<span>' . esc_html( $part ) . '</span>';
		}

		return '<p class="pkiw-exercise-week__summary">' . $html . '</p>';
	}
}
