<?php
/**
 * Simple Location weather integration (read-only).
 *
 * Simple Location saves one weather observation per post as `weather_<prop>`
 * post meta, in metric units, at capture time. This adapter reads that
 * snapshot for display. It never fetches weather, never calls a Simple
 * Location provider, and never writes, migrates, or deletes weather meta.
 *
 * Verified against Simple Location 5.0.25:
 * - Meta registration: includes/class-weather-data.php:84-163
 *   (`show_in_rest => false`).
 * - Read API: get_post_weatherdata() (includes/data-functions.php:171) →
 *   Sloc_Weather_Data::get_object_weatherdata() (class-weather-data.php:300),
 *   which first calls migrate_weather() (:339). migrate_weather() (:363-383)
 *   returns without writing when the post has no legacy `geo_weather` row,
 *   and otherwise rewrites it into `weather_*` rows and deletes it. This
 *   adapter only calls the getter for posts with no `geo_weather` row, and
 *   parses a legacy row itself, read-only.
 * - Labels and icons: weather_condition_codes() / weather_condition_icons()
 *   (trait-weather-info.php:342, :440), applied the way get_the_weather()
 *   does (class-weather-data.php:442-445).
 * - Display units: `sloc_units` query var, else the `sloc_measurements`
 *   option (class-weather-data.php:477, :497), converted by
 *   Weather_Provider::metric_to_imperial() (class-weather-provider.php:639).
 *
 * On a weather post the gate (gate_content()) removes Simple Location's
 * location line on every the_content pass, so its inline weather never
 * prints there. The issue 209 helpers (filter_display_defaults(),
 * note_content_weather() and the printed-weather bookkeeping behind them)
 * remain only for the hooks that still read them, such as a Simple Location
 * shortcode or block rendered inside a weather post's content; they have
 * nothing left to switch off in the line itself.
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only facade over Simple Location's stored weather observation.
 *
 * Issue 246 adds the weather kind's reading, facts, Stream card and hidden
 * p-weather, and a Simple Location privacy gate for weather posts: the map,
 * the location line, GeoRSS, JSON Feed geo and head meta are held back for
 * the render pass that needs it and put back after it (gate_content() and
 * its neighbors). The line never prints on a weather post, so a located
 * post's observation reaches visitors only through reading().
 *
 * @since 1.9.0
 */
final class Simple_Location_Weather {

	/**
	 * Numeric Simple Location properties the observation exposes.
	 *
	 * @var array<int, string>
	 */
	private const NUMERIC_FIELDS = [
		'temperature',
		'humidity',
		'pressure',
		'windspeed',
		'winddegree',
		'windgust',
		'cloudiness',
		'rain',
		'snow',
		'visibility',
		'uv',
	];

	/**
	 * The Stream card block, whose render prints Post Kinds' weather line
	 * for a weather post (functions-stream-card.php:404-406).
	 */
	private const STREAM_CARD_BLOCK = 'post-kinds-indieweb/stream-card';

	/**
	 * The kind this class serves.
	 */
	private const KIND = 'weather';

	/**
	 * The map callback Simple Location adds to the_content at 11.
	 *
	 * @var array{0:string,1:string}
	 */
	private const CONTENT_MAP = [ 'Geo_Data', 'content_map' ];

	/**
	 * The location line callback Simple Location adds to the_content at 12.
	 *
	 * @var array{0:string,1:string}
	 */
	private const LOCATION_CONTENT = [ 'Geo_Data', 'location_content' ];

	/**
	 * The head meta callback Simple Location adds to wp_head at 10.
	 *
	 * @var array{0:string,1:string}
	 */
	private const META_TAGS = [ 'Geo_Base', 'meta_tags' ];

	/**
	 * The GeoRSS callback Simple Location adds to the feed item hooks at 10.
	 *
	 * @var array{0:string,1:string}
	 */
	private const GEORSS_ITEM = [ 'Geo_Base', 'georss_item' ];

	/**
	 * The feed item hooks Simple Location prints GeoRSS on.
	 *
	 * @var array<int, string>
	 */
	private const FEED_HOOKS = [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ];

	/**
	 * Weather rows of the station log, in print order: observation field,
	 * then the binding that names it.
	 *
	 * @var array<string, string>
	 */
	private const LOG_FIELDS = [
		'summary'     => 'Weather Summary',
		'temperature' => 'Temperature',
		'windspeed'   => 'Wind Speed',
		'winddegree'  => 'Wind Direction',
		'humidity'    => 'Humidity',
		'pressure'    => 'Atmospheric Pressure',
		'cloudiness'  => 'Cloudiness',
		'rain'        => 'Rainfall',
	];

	/**
	 * Removed the_content callbacks, one frame per pass, so a nested pass
	 * (a shortcode rendering another post) can't clear the outer list.
	 *
	 * @var array<int, array<int, array{callable:array{0:string,1:string},priority:int}>>
	 */
	private static array $content_stack = [];

	/**
	 * Whether the priority-9 call removed GeoRSS for this feed item, one entry per item.
	 *
	 * @var array<int, bool>
	 */
	private static array $feed_stack = [];

	/**
	 * The current the_content pass: its post and whether its content
	 * carries a p-weather. Set at priority 11, just before Simple Location
	 * appends its line at 12.
	 *
	 * @var array{post: int, weather: bool}|null
	 */
	private static ?array $content_pass = null;

	/**
	 * Posts whose weather a kind-meta binding printed during this request.
	 *
	 * @var array<int, true>
	 */
	private static array $bound = [];

	/**
	 * Hook Simple Location's display defaults.
	 *
	 * @return void
	 */
	public static function register(): void {
		Kind_Facts::register(
			self::KIND,
			[ self::class, 'facts' ],
			[
				'place_name'     => 'name',
				'place_locality' => 'locality',
				'place_region'   => 'region',
				'place_country'  => 'country',
			]
		);

		add_filter( 'the_content', [ self::class, 'note_content_weather' ], 11 );
		add_filter( 'simple_location_display_defaults', [ self::class, 'filter_display_defaults' ] );

		// The Simple Location gate (issue 246): remove at 10, restore at 13.
		add_filter( 'the_content', [ self::class, 'gate_content' ], 10 );
		add_filter( 'the_content', [ self::class, 'restore_content' ], 13 );
		foreach ( self::FEED_HOOKS as $hook ) {
			add_action( $hook, [ self::class, 'gate_feed_item' ], 9 );
			add_action( $hook, [ self::class, 'restore_feed_item' ], 11 );
		}
		add_filter( 'json_feed_item', [ self::class, 'gate_json_feed_item' ], 11, 2 );
		add_action( 'template_redirect', [ self::class, 'gate_head' ], 10 );
		add_filter( 'rest_prepare_post', [ self::class, 'strip_rest_location' ], 30, 3 );

		// The weather Stream card and the single's hidden p-weather.
		add_filter( 'pkiw_stream_card_kind_render', [ self::class, 'stream_card' ], 10, 4 );
		add_filter( 'the_content', [ self::class, 'single_hidden_weather' ], 98 );
	}

	/**
	 * Clear the render-pass stacks.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$content_stack = [];
		self::$feed_stack    = [];
	}

	/**
	 * Record whether this the_content pass carries weather of its own.
	 *
	 * A p-weather class in the content is Micropub's authored weather
	 * paragraph (class-micropub-content-builder.php:932-938) or Post Kinds'
	 * own render() markup. The content passes through unchanged.
	 *
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	public static function note_content_weather( $content ) {
		self::$content_pass = [
			'post'    => (int) get_the_ID(),
			'weather' => is_string( $content ) && self::content_has_p_weather( $content ),
		];

		return $content;
	}

	/**
	 * Whether HTML carries a p-weather class token.
	 *
	 * @param string $html Post content.
	 * @return bool
	 */
	private static function content_has_p_weather( string $html ): bool {
		return 1 === preg_match( '/\bclass\s*=\s*["\'][^"\']*(?<![\w-])p-weather(?![\w-])/i', $html );
	}

	/**
	 * Turn off Simple Location's inline weather when it would repeat a
	 * weather post's own.
	 *
	 * Simple Location appends its location line, weather included, to
	 * the_content at priority 12 for get_the_ID() (class-geo-data.php:49-51,
	 * :799-805) and reads its display defaults from this filter (:991). Its
	 * weather goes only when the render already shows the post's weather;
	 * on a weather post with no weather of its own, Simple Location's line
	 * is the only reading and stays. The location part always stays.
	 *
	 * @param mixed $defaults Simple Location display defaults.
	 * @return mixed
	 */
	public static function filter_display_defaults( $defaults ) {
		if ( ! is_array( $defaults ) || ! doing_filter( 'the_content' ) || ! self::is_active() ) {
			return $defaults;
		}

		$post_id = (int) get_the_ID();
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'weather' !== \PKIW\get_post_kind_slug( $post ) ) {
			return $defaults;
		}

		if ( self::render_shows_weather( $post_id ) ) {
			$defaults['weather'] = false;
		}

		return $defaults;
	}

	/**
	 * Whether the current render already shows the post's own weather.
	 *
	 * True when this the_content pass carries a p-weather, when a
	 * kind-meta weather binding printed the post's weather earlier in the
	 * request (in the content, which do_blocks renders at priority 9, or in
	 * a template part above it), or when the_content runs inside the Stream
	 * card (the excerpt, functions-stream-card.php:327) and the card prints
	 * Post Kinds' weather line.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function render_shows_weather( int $post_id ): bool {
		if ( null !== self::$content_pass && $post_id === self::$content_pass['post'] && self::$content_pass['weather'] ) {
			return true;
		}

		if ( isset( self::$bound[ $post_id ] ) ) {
			return true;
		}

		// Core holds the block whose render callback is running
		// (WP_Block::render(), class-wp-block.php:592-598).
		$block = class_exists( 'WP_Block_Supports' ) ? \WP_Block_Supports::$block_to_render : null;
		if ( is_array( $block ) && self::STREAM_CARD_BLOCK === ( $block['blockName'] ?? '' ) ) {
			return '' !== self::render( $post_id );
		}

		return false;
	}

	/**
	 * Whether Simple Location's weather API is loaded.
	 *
	 * Checked at call time so load order doesn't matter.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		$active = function_exists( 'get_post_weatherdata' )
			&& class_exists( 'Sloc_Weather_Data' )
			&& class_exists( 'Weather_Provider' );

		/**
		 * Filters whether Simple Location is treated as the weather source.
		 *
		 * Return false to hide every Simple Location weather value Post
		 * Kinds would show.
		 *
		 * @since 1.9.0
		 *
		 * @param bool $active Whether Simple Location's weather API is loaded.
		 */
		return (bool) apply_filters( 'pkiw_weather_source_active', $active );
	}

	/**
	 * Whether the current viewer may see a post's weather.
	 *
	 * A reading tied to the post's timestamp narrows location to a forecast
	 * grid cell, so it gets the same city-level visibility as the
	 * `locality` tier of Meta_Fields::get_visible_location_fields(). On top
	 * of that, Simple Location's own visibility must not resolve to
	 * private: an empty per-post `geo_public` falls back to the site's
	 * `geo_public` option the way Geo_Data::get_default_visibility() does
	 * (class-geo-data.php:77-89, :494-500), and an unset option is private.
	 * Someone who can edit the post sees it where they edit it, per
	 * Meta_Fields::can_see_full_location(); on the front end they see what
	 * a visitor sees.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_view( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( Meta_Fields::can_see_full_location( $post_id ) ) {
			return true;
		}

		$visible = Meta_Fields::get_visible_location_fields( $post_id );
		if ( empty( $visible['locality'] ) ) {
			return false;
		}

		$geo_public = (string) get_post_meta( $post_id, 'geo_public', true );
		if ( '' === $geo_public ) {
			$geo_public = (string) (int) get_option( 'geo_public' );
		}

		return in_array( $geo_public, [ '1', '2' ], true );
	}

	/**
	 * The post's stored observation, normalized for display.
	 *
	 * Keys appear only when Simple Location stored a value: summary,
	 * condition_code, condition_label, icon, and the numeric fields in
	 * NUMERIC_FIELDS (converted to the display units). When temperature is
	 * present, temperature_unit (°C or °F) comes with it. `units` is always
	 * present on a non-null result.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string|float>|null Null when Simple Location is
	 *         inactive, the post is behind a password, the viewer may not see
	 *         the post's location, or no observation is stored.
	 */
	public static function get_observation( int $post_id ): ?array {
		// A protected post shows no weather, to an editor before the password too.
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return null;
		}

		if ( ! self::is_active() || ! self::can_view( $post_id ) ) {
			return null;
		}

		$raw = self::read_stored( $post_id );
		if ( [] === $raw ) {
			return null;
		}

		$imperial = self::uses_imperial();
		$numeric  = [];
		foreach ( self::NUMERIC_FIELDS as $field ) {
			if ( isset( $raw[ $field ] ) && is_numeric( $raw[ $field ] ) ) {
				$numeric[ $field ] = (float) $raw[ $field ];
			}
		}
		if ( $imperial && [] !== $numeric ) {
			$converted = \Weather_Provider::metric_to_imperial( $numeric );
			$numeric   = is_array( $converted ) ? array_map( 'floatval', $converted ) : $numeric;
		}

		$observation = [];

		$code = isset( $raw['code'] ) && is_numeric( $raw['code'] ) ? (string) (int) $raw['code'] : '';
		if ( '' !== $code ) {
			$observation['condition_code'] = $code;
			$label                         = \Sloc_Weather_Data::weather_condition_codes( $code );
			if ( is_string( $label ) && '' !== $label ) {
				$observation['condition_label'] = $label;
			}
		}

		$summary = isset( $raw['summary'] ) && is_scalar( $raw['summary'] ) ? trim( sanitize_text_field( (string) $raw['summary'] ) ) : '';
		if ( '' === $summary && isset( $observation['condition_label'] ) ) {
			$summary = $observation['condition_label'];
		}
		if ( '' !== $summary ) {
			$observation['summary'] = $summary;
		}

		$icon = '' !== $code ? \Sloc_Weather_Data::weather_condition_icons( $code ) : ( $raw['icon'] ?? '' );
		$icon = is_string( $icon ) ? sanitize_html_class( $icon ) : '';
		if ( '' !== $icon ) {
			$observation['icon'] = $icon;
		}

		foreach ( $numeric as $field => $value ) {
			$observation[ $field ] = $value;
			if ( 'temperature' === $field ) {
				$observation['temperature_unit'] = $imperial ? '°F' : '°C';
			}
		}

		if ( [] === $observation ) {
			return null;
		}

		$observation['units'] = $imperial ? 'imperial' : 'metric';

		return $observation;
	}

	/**
	 * One observation field formatted for display, with its unit.
	 *
	 * Temperature rounds to a whole degree, as Simple Location's own
	 * display does (class-weather-data.php:483-490); other values keep up
	 * to two decimals (Weather_Provider::markup_value()'s default).
	 *
	 * The kind-meta weather bindings call this (class-block-bindings.php:565),
	 * so a value returned here is the post's weather printed on the page,
	 * which filter_display_defaults() reads.
	 *
	 * @param string $field   summary, condition, code, or a numeric field.
	 * @param int    $post_id Post ID.
	 * @return string|null Null when the value isn't available to this viewer.
	 */
	public static function format( string $field, int $post_id ): ?string {
		$observation = self::get_observation( $post_id );
		if ( null === $observation ) {
			return null;
		}

		$value = self::format_field( $field, $observation );
		if ( null !== $value ) {
			self::$bound[ $post_id ] = true;
		}

		return $value;
	}

	/**
	 * A `p-weather` paragraph for the post's observation, or ''.
	 *
	 * Text only: the condition and temperature, e.g. "Clear Sky, 27 °C".
	 * The icon slug stays in get_observation() for themes that want it.
	 *
	 * @param int $post_id Post ID.
	 * @return string Escaped HTML.
	 */
	public static function render( int $post_id ): string {
		$observation = self::get_observation( $post_id );
		if ( null === $observation ) {
			return '';
		}

		$parts = array_filter(
			[
				self::format_field( 'summary', $observation ),
				self::format_field( 'temperature', $observation ),
			]
		);
		if ( [] === $parts ) {
			return '';
		}

		return '<p class="pk-weather p-weather">' . esc_html( implode( ', ', $parts ) ) . '</p>';
	}

	/**
	 * The one reading a weather post prints: the observation when the viewer
	 * may see it, else the author's own words. Never both.
	 *
	 * - `observation`: Simple Location stored a summary or a temperature and
	 *   can_view() allows it. `text` is render()'s "summary, temperature" with
	 *   the tags stripped and the entities decoded. `code` is the stored
	 *   condition code or ''. `icon` is set only when a code is stored, because
	 *   get_observation() falls back to a stored icon row (a mark needs a code).
	 * - `authored`: the text of the first element in the post content whose
	 *   class list holds p-weather, which is what Micropub writes.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array{source:string, text:string, icon:string, code:string, temperature_visible:bool}|null
	 *         Null for a missing post, a protected post, or no reading.
	 */
	public static function reading( int $post_id ): ?array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return null;
		}

		$observation = self::get_observation( $post_id );
		if ( null !== $observation ) {
			$text = html_entity_decode( wp_strip_all_tags( self::render( $post_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( '' !== $text ) {
				$code = (string) ( $observation['condition_code'] ?? '' );

				return [
					'source'              => 'observation',
					'text'                => $text,
					'icon'                => '' !== $code ? (string) ( $observation['icon'] ?? '' ) : '',
					'code'                => $code,
					'temperature_visible' => isset( $observation['temperature'] ),
				];
			}
		}

		$authored = self::authored_text( (string) $post->post_content );
		if ( '' === $authored ) {
			return null;
		}

		return [
			'source'              => 'authored',
			'text'                => $authored,
			'icon'                => '',
			'code'                => '',
			'temperature_visible' => false,
		];
	}

	/**
	 * The text of the first p-weather element in post content.
	 *
	 * @param string $html Post content.
	 * @return string Text with markup removed and whitespace collapsed, or ''.
	 */
	private static function authored_text( string $html ): string {
		if ( ! self::content_has_p_weather( $html ) ) {
			return '';
		}

		$processor = \WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return '';
		}

		while ( $processor->next_tag() ) {
			if ( ! $processor->has_class( 'p-weather' ) ) {
				continue;
			}

			// A void element has no text; skip it rather than read its siblings.
			if ( \WP_HTML_Processor::is_void( (string) $processor->get_tag() ) ) {
				continue;
			}

			// Children sit deeper than the opener, a child's closer sits at the
			// opener's depth, and the opener's own closer sits above it.
			$depth = $processor->get_current_depth();
			$text  = '';
			while ( $processor->next_token() && $processor->get_current_depth() >= $depth ) {
				if ( '#text' === $processor->get_token_name() ) {
					$text .= $processor->get_modifiable_text();
				}
			}

			return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		}

		return '';
	}

	/**
	 * The weather facts a theme prints: the station log and the public-tier place.
	 *
	 * Registered for the weather kind with its place keys mapped to location
	 * tiers, so Kind_Facts::get() blanks what the post's public tier hides,
	 * editors included. Read it through Kind_Facts::get(), never directly.
	 * There is no street, postal code, coordinate, map or URL key.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Facts, or [] when the post can't be shown or isn't weather.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}

		$reading = self::reading( $post_id );
		$text    = null !== $reading ? $reading['text'] : '';
		$titled  = '' !== trim( $post->post_title );

		// The title, else the reading, else the kind. A synthetic name never claims to be the title.
		if ( $titled ) {
			$name = trim( wp_strip_all_tags( \PKIW\visitor_post_title( $post ) ) );
		} elseif ( '' !== $text ) {
			$name = $text;
		} else {
			$name = \PKIW\stream_card_kind_label( $post );
		}

		$place = [];
		foreach (
			[
				'place_name'     => 'geo_venue',
				'place_locality' => 'geo_locality',
				'place_region'   => 'geo_region',
				'place_country'  => 'geo_country_name',
			] as $fact => $meta_key
		) {
			$value          = get_post_meta( $post_id, $meta_key, true );
			$place[ $fact ] = is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
		}

		return array_merge(
			[
				'name'                => $name,
				'name_is_title'       => $titled && ! \PKIW\post_title_is_hidden( $post ),
				'name_is_reading'     => ! $titled && '' !== $text,
				'reading'             => $text,
				'reading_source'      => null !== $reading ? $reading['source'] : '',
				'reading_in_content'  => self::content_has_p_weather( (string) $post->post_content ),
				'icon'                => null !== $reading ? $reading['icon'] : '',
				'code'                => null !== $reading ? $reading['code'] : '',
				'temperature_visible' => null !== $reading && $reading['temperature_visible'],
				'log'                 => 'observation' === ( $reading['source'] ?? '' ) ? self::log_rows( $post_id ) : [],
				'date_iso'            => (string) get_post_time( 'c', true, $post ),
				'calendar_day'        => \PKIW\card_calendar_date( $post->post_date )[0],
			],
			$place
		);
	}

	/**
	 * The station log's rows: only the values the viewer may see, in print order.
	 *
	 * Values come from format_field(), not format(), which would mark the
	 * weather as printed and switch off Simple Location's own.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{key:string, label:string, value:string}>
	 */
	private static function log_rows( int $post_id ): array {
		$observation = self::get_observation( $post_id );
		if ( null === $observation ) {
			return [];
		}

		$rows = [];
		foreach ( self::LOG_FIELDS as $field => $fallback ) {
			$value = self::format_field( $field, $observation );
			if ( null === $value || '' === $value ) {
				continue;
			}
			$rows[] = [
				'key'   => $field,
				'label' => self::binding_label( 'weather_' . $field, $fallback ),
				'value' => $value,
			];
		}

		return $rows;
	}

	/**
	 * A weather binding's label, verbatim, so the log reads like the bindings do.
	 *
	 * @param string $binding  Binding key, such as weather_temperature.
	 * @param string $fallback Label when the bindings aren't loaded yet.
	 * @return string
	 */
	private static function binding_label( string $binding, string $fallback ): string {
		$bindings = \PKIW\Plugin::get_instance()->get_block_bindings();
		$label    = null !== $bindings ? ( $bindings->get_bindings()[ $binding ]['label'] ?? '' ) : '';

		return is_string( $label ) && '' !== $label ? $label : $fallback;
	}

	/**
	 * Whether a post is a weather post for the privacy gate: any weather kind term.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function is_weather_post( \WP_Post $post ): bool {
		return has_term( self::KIND, Taxonomy::TAXONOMY, $post );
	}

	/**
	 * Whether a weather post's Simple Location geo may leave in feeds and the head.
	 *
	 * True only for a post with no password whose public tier shows both the
	 * street and the coordinates. Simple Location's own feed and head hooks
	 * check only geo_public, so without this an approximate or protected
	 * weather post would print its point and street.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function keep_geo( \WP_Post $post ): bool {
		if ( 0 !== strlen( $post->post_password ) ) {
			return false;
		}

		$visible = Meta_Fields::get_public_location_fields( $post->ID );

		return $visible['street'] && $visible['coordinates'];
	}

	/**
	 * Remove Simple Location's map and location line for this the_content pass.
	 *
	 * Runs at 10, ahead of Simple Location's 11 and 12. Only the callables
	 * remove_filter() reports it removed go on this pass's frame, and the
	 * frame is one entry per pass, so a nested pass can't clear the outer list.
	 * Every pass with a weather post as the global post is covered: the
	 * single, a Blog or Stream excerpt, feeds, REST, and ATmosphere's copy.
	 *
	 * @internal Hooked to `the_content` at 10.
	 *
	 * @param mixed $content Post content.
	 * @return mixed Content unchanged.
	 */
	public static function gate_content( $content ) {
		$removed = [];
		$post    = get_post();

		if ( $post instanceof \WP_Post && self::is_weather_post( $post ) ) {
			foreach (
				[
					[ self::CONTENT_MAP, 11 ],
					[ self::LOCATION_CONTENT, 12 ],
				] as [ $callable, $priority ]
			) {
				if ( remove_filter( 'the_content', $callable, $priority ) ) {
					$removed[] = [
						'callable' => $callable,
						'priority' => $priority,
					];
				}
			}
		}

		self::$content_stack[] = $removed;

		return $content;
	}

	/**
	 * Put back what gate_content() removed for this pass, and only that.
	 *
	 * Runs at 13. WP_Hook skips priorities below the running one in the
	 * current pass, so a callable re-added at 11 or 12 from here runs on the
	 * next pass, not this one.
	 *
	 * @internal Hooked to `the_content` at 13.
	 *
	 * @param mixed $content Post content.
	 * @return mixed Content unchanged.
	 */
	public static function restore_content( $content ) {
		$removed = array_pop( self::$content_stack );

		foreach ( is_array( $removed ) ? $removed : [] as $item ) {
			add_filter( 'the_content', $item['callable'], $item['priority'] );
		}

		return $content;
	}

	/**
	 * Remove GeoRSS for a weather feed item that may not keep its geo.
	 *
	 * @internal Hooked to the feed item actions at 9.
	 *
	 * @return void
	 */
	public static function gate_feed_item(): void {
		$removed = false;
		$post    = get_post();

		if ( $post instanceof \WP_Post && self::is_weather_post( $post ) && ! self::keep_geo( $post ) ) {
			$removed = remove_action( current_action(), self::GEORSS_ITEM, 10 );
		}

		self::$feed_stack[] = $removed;
	}

	/**
	 * Put GeoRSS back after a feed item, when gate_feed_item() removed it for that item.
	 *
	 * @internal Hooked to the feed item actions at 11.
	 *
	 * @return void
	 */
	public static function restore_feed_item(): void {
		if ( true === array_pop( self::$feed_stack ) ) {
			add_action( current_action(), self::GEORSS_ITEM, 10 );
		}
	}

	/**
	 * Remove JSON Feed geo from a weather item that may not keep it.
	 *
	 * @internal Hooked to `json_feed_item` at 11.
	 *
	 * @param mixed $item JSON Feed item.
	 * @param mixed $post Feed post, or null.
	 * @return mixed Item.
	 */
	public static function gate_json_feed_item( $item, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post();

		if ( is_array( $item ) && $post instanceof \WP_Post && self::is_weather_post( $post ) && ! self::keep_geo( $post ) ) {
			unset( $item['geo'] );
		}

		return $item;
	}

	/**
	 * Strip Simple Location's REST location fields from a weather post's response.
	 *
	 * Simple Location adds top-level latitude, longitude and geo_address (and
	 * a timezone, and a UTC offset on date and modified) to post responses,
	 * gated only on geo_public and blind to the Post Kinds tier. A weather
	 * post that may not keep its geo (keep_geo()) loses them in every context
	 * but edit, which core already limits to people who can edit the post.
	 *
	 * @internal Hooked to `rest_prepare_post` at 30, after Simple Location's own.
	 *
	 * @param mixed $response Response.
	 * @param mixed $post     Post.
	 * @param mixed $request  Request.
	 * @return mixed Response.
	 */
	public static function strip_rest_location( $response, $post = null, $request = null ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 'edit' === $request->get_param( 'context' ) || ! self::is_weather_post( $post ) || self::keep_geo( $post ) ) {
			return $response;
		}

		$data = $response->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address', 'timezone' ] as $key ) {
			unset( $data[ $key ] );
		}
		// A UTC offset on date or modified places the post.
		foreach (
			[
				'date'     => 'post_date',
				'modified' => 'post_modified',
			] as $key => $column
		) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = mysql_to_rfc3339( $post->$column );
			}
		}
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Remove Simple Location's head meta on a weather single that may not keep its geo.
	 *
	 * A request has one head, so nothing is restored.
	 *
	 * @internal Hooked to `template_redirect` at 10.
	 *
	 * @return void
	 */
	public static function gate_head(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post( get_queried_object_id() );
		if ( $post instanceof \WP_Post && self::is_weather_post( $post ) && ! self::keep_geo( $post ) ) {
			remove_action( 'wp_head', self::META_TAGS, 10 );
		}
	}

	/**
	 * The weather post's Stream card: one link, the reading once, no excerpt.
	 *
	 * An untitled post's link text is the reading (a post with neither reading
	 * nor title says "Weather"), with the full date and time hidden in the
	 * link name so same-day cards read differently. A titled post links its
	 * title and prints the reading below. The card runs no excerpt, so no
	 * the_content pass reaches it. Protected posts never get here: the caller
	 * returns the protected card first.
	 *
	 * @internal Hooked to `pkiw_stream_card_kind_render`.
	 *
	 * @param mixed $html          Card HTML so far, null to fall through.
	 * @param mixed $post          Post.
	 * @param mixed $heading_level Heading level, 2 to 4.
	 * @param mixed $attributes    Block attributes (unused).
	 * @return mixed Card HTML for a weather post, else $html unchanged.
	 */
	public static function stream_card( $html, $post = null, $heading_level = 2, $attributes = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $post instanceof \WP_Post || self::KIND !== \PKIW\get_post_kind_slug( $post ) ) {
			return $html;
		}

		$level   = max( 2, min( 4, (int) $heading_level ) );
		$reading = self::reading( $post->ID );
		$text    = null !== $reading ? $reading['text'] : '';
		$title   = '' !== trim( $post->post_title ) ? trim( wp_strip_all_tags( \PKIW\visitor_post_title( $post ) ) ) : '';
		$label   = \PKIW\get_kind_label( \PKIW\stream_card_kind_label( $post ), self::KIND, 'stream-card' );

		// A hidden location title prints its safe name without p-name (X8).
		if ( '' !== $title && ! \PKIW\post_title_is_hidden( $post ) ) {
			$link_text = '<span class="p-name">' . esc_html( $title ) . '</span>';
		} elseif ( '' !== $title ) {
			$link_text = esc_html( $title );
		} elseif ( '' !== $text ) {
			$link_text = '<span class="p-weather">' . esc_html( $text ) . '</span>';
		} else {
			$link_text = esc_html( $label );
		}

		$when = sprintf(
			/* translators: 1: date, 2: time of day. */
			__( '%1$s at %2$s', 'post-kinds-for-indieweb-in-block-themes' ),
			get_the_date( '', $post ),
			get_the_time( '', $post )
		);

		$out = '<article class="pk-card pk-card--stream k-weather h-entry">';
		if ( '' !== $title || '' !== $text ) {
			$out .= '<span class="pk-kindlabel">' . esc_html( $label ) . '</span>';
		}
		$out .= '<h' . $level . ' class="pk-title"><a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">'
			. $link_text // Escaped above.
			. '<span class="pk-sr-only">' . esc_html( ', ' . $when ) . '</span></a></h' . $level . '>';

		if ( null !== $reading && 'observation' === $reading['source'] && '' !== $reading['icon'] ) {
			$out .= '<span class="pk-weather-mark" data-pkiw-weather-icon="' . esc_attr( $reading['icon'] ) . '" aria-hidden="true"></span>';
		}
		if ( '' !== $title && '' !== $text ) {
			$out .= '<p class="pk-weather p-weather">' . esc_html( $text ) . '</p>';
		}

		$day  = \PKIW\card_calendar_date( $post->post_date )[1];
		$out .= '<p class="pk-sub pk-stream-date"><time class="dt-published" datetime="'
			. esc_attr( \PKIW\stream_card_published_datetime( $post ) ) . '">'
			. esc_html( '' !== $day ? $day : (string) get_the_date( '', $post ) ) . '</time></p>';

		return $out . '</article>';
	}

	/**
	 * Add a hidden p-weather to a weather single when its reading is the observation.
	 *
	 * The single's h-entry root is Post Kinds' the_content wrapper, so the
	 * station log the theme prints outside it can't carry the value. This
	 * appends it inside the root, ahead of the wrapper at 100 and the hidden
	 * mf2 block at 99. When the content already holds a p-weather, that is
	 * the entry's one weather value and nothing is added. Feeds, REST, an
	 * ATmosphere preview, other posts and protected posts get nothing.
	 *
	 * @internal Hooked to `the_content` at 98.
	 *
	 * @param mixed $content Post content.
	 * @return mixed Content, with the hidden value when it applies.
	 */
	public static function single_hidden_weather( $content ) {
		if ( ! is_string( $content ) || null !== get_query_var( 'atproto', null ) || is_feed() || ! is_singular() ) {
			return $content;
		}

		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 || get_queried_object_id() !== $post_id ) {
			return $content;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || self::KIND !== \PKIW\get_post_kind_slug( $post ) || self::content_has_p_weather( $content ) ) {
			return $content;
		}

		$reading = self::reading( $post_id );
		if ( null === $reading || 'observation' !== $reading['source'] ) {
			return $content;
		}

		return $content . '<data class="p-weather" value="' . esc_attr( $reading['text'] ) . '" hidden></data>';
	}

	/**
	 * Format one field from a normalized observation.
	 *
	 * @param string                      $field       Field name.
	 * @param array<string, string|float> $observation Result of get_observation().
	 * @return string|null
	 */
	private static function format_field( string $field, array $observation ): ?string {
		$imperial = 'imperial' === ( $observation['units'] ?? 'metric' );

		switch ( $field ) {
			case 'summary':
				return isset( $observation['summary'] ) ? (string) $observation['summary'] : null;
			case 'condition':
				return isset( $observation['condition_label'] ) ? (string) $observation['condition_label'] : null;
			case 'code':
				return isset( $observation['condition_code'] ) ? (string) $observation['condition_code'] : null;
		}

		if ( ! in_array( $field, self::NUMERIC_FIELDS, true ) || ! isset( $observation[ $field ] ) ) {
			return null;
		}

		$value = (float) $observation[ $field ];

		switch ( $field ) {
			case 'temperature':
				return self::number( round( $value ) ) . ' ' . ( $imperial ? '°F' : '°C' );
			case 'humidity':
			case 'cloudiness':
				return self::number( $value ) . '%';
			case 'winddegree':
				return self::number( $value ) . '°';
			case 'pressure':
				return self::number( $value ) . ' ' . ( $imperial ? 'inHg' : 'hPa' );
			case 'windspeed':
			case 'windgust':
				return self::number( $value ) . ' ' . ( $imperial ? 'mph' : 'm/s' );
			case 'rain':
			case 'snow':
				return self::number( $value ) . ' ' . ( $imperial ? 'in' : 'mm' );
			case 'visibility':
				return self::number( $value ) . ' ' . ( $imperial ? 'mi' : 'm' );
			default:
				return self::number( $value );
		}
	}

	/**
	 * A number rounded to two decimals, without trailing zeros.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function number( float $value ): string {
		return (string) round( $value, 2 );
	}

	/**
	 * Whether Simple Location displays imperial units for this request.
	 *
	 * @return bool
	 */
	private static function uses_imperial(): bool {
		return 'imperial' === get_query_var( 'sloc_units', get_option( 'sloc_measurements' ) );
	}

	/**
	 * The stored weather properties, without triggering any write.
	 *
	 * A post still holding Simple Location's legacy `geo_weather` array is
	 * parsed here, the way migrate_weather() maps it, so Simple Location's
	 * getter never runs its migration from a Post Kinds read.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private static function read_stored( int $post_id ): array {
		$legacy = get_post_meta( $post_id, 'geo_weather', true );
		if ( ! empty( $legacy ) ) {
			return is_array( $legacy ) ? self::read_legacy( $post_id, $legacy ) : [];
		}

		$stored = get_post_weatherdata( $post_id );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Merge a legacy `geo_weather` array over the `weather_*` rows, as
	 * migrate_weather() would, without writing.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $legacy  Legacy weather array.
	 * @return array<string, mixed>
	 */
	private static function read_legacy( int $post_id, array $legacy ): array {
		$properties = \Sloc_Weather_Data::$properties;

		$stored = [];
		foreach ( $properties as $prop ) {
			$value = get_post_meta( $post_id, 'weather_' . $prop, true );
			if ( ! empty( $value ) ) {
				$stored[ $prop ] = $value;
			}
		}

		if ( isset( $legacy['wind'] ) && is_array( $legacy['wind'] ) ) {
			$wind = array_filter(
				[
					'windgust'   => $legacy['wind']['gust'] ?? false,
					'winddegree' => $legacy['wind']['degree'] ?? false,
					'windspeed'  => $legacy['wind']['speed'] ?? false,
				]
			);
			unset( $legacy['wind'] );
			$legacy = array_merge( $wind, $legacy );
		}

		foreach ( wp_array_slice_assoc( $legacy, $properties ) as $prop => $value ) {
			if ( ! empty( $value ) ) {
				$stored[ $prop ] = $value;
			} else {
				unset( $stored[ $prop ] );
			}
		}

		return $stored;
	}
}
