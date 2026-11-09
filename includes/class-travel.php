<?php
/**
 * Trip and itinerary storage, privacy, facts, and cards.
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical travel data and its visitor-safe projections. */
final class Travel {

	public const STOPS_KEY     = '_pkiw_trip_stops';
	public const LEGS_KEY      = '_pkiw_itinerary_legs';
	public const TRIP          = 'trip';
	public const ITINERARY     = 'itinerary';
	public const MAX_ITEMS     = 50;
	public const PLACE_FIELDS  = [ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'url', 'osm_id', 'venue_id' ];
	public const REVEAL_OFFSET = '-12:00';
	public const STOP_BLOCK    = 'post-kinds-indieweb/trip-card';
	public const LEG_BLOCK     = 'post-kinds-indieweb/itinerary-card';

	/** Register fact readers and the two dynamic card blocks. */
	public function register(): void {
		Kind_Facts::register( self::TRIP, [ self::class, 'trip_facts' ] );
		Kind_Facts::register( self::ITINERARY, [ self::class, 'itinerary_facts' ] );
		add_action( 'init', [ self::class, 'register_blocks' ] );
	}

	/**
	 * Sanitize the trip stop JSON.
	 *
	 * @param mixed $value Stored meta value.
	 */
	public static function sanitize_stops( mixed $value ): string {
		$items = self::decode_list( $value );
		if ( null === $items ) {
			return '';
		}

		$clean = [];
		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			[ $place, $present ] = self::sanitize_place( $item );
			if ( ! $present ) {
				continue;
			}
			$clean[] = $place + [
				'date'    => self::sanitize_day( $item['date'] ?? '' ),
				'privacy' => self::sanitize_privacy( $item['privacy'] ?? '' ),
			];
		}

		return self::encode_list( $clean );
	}

	/**
	 * Sanitize the itinerary leg JSON.
	 *
	 * @param mixed $value Stored meta value.
	 */
	public static function sanitize_legs( mixed $value ): string {
		$items = self::decode_list( $value );
		if ( null === $items ) {
			return '';
		}

		$clean = [];
		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			[ $from, $from_present ] = self::sanitize_place( is_array( $item['from'] ?? null ) ? $item['from'] : [] );
			[ $to, $to_present ]     = self::sanitize_place( is_array( $item['to'] ?? null ) ? $item['to'] : [] );
			if ( ! $from_present && ! $to_present ) {
				continue;
			}
			$departure = self::sanitize_datetime( $item['departure'] ?? '' );
			$arrival   = self::sanitize_datetime( $item['arrival'] ?? '' );
			$clean[]   = [
				'from'      => $from,
				'to'        => $to,
				'departure' => $departure,
				'arrival'   => $arrival,
				'carrier'   => self::sanitize_text( $item['carrier'] ?? '' ),
				'privacy'   => self::sanitize_privacy( $item['privacy'] ?? '' ),
			];
		}

		return self::encode_list( $clean );
	}

	/**
	 * Combine an item's tier with the post's visitor-visible fields.
	 *
	 * @param string              $privacy     Item privacy tier.
	 * @param array<string, bool> $post_fields Post-level fields.
	 * @return array<string, bool>
	 */
	public static function item_fields( string $privacy, array $post_fields ): array {
		$all         = [ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'coordinates', 'map', 'url', 'osm_id', 'venue_id' ];
		$item_fields = array_fill_keys( $all, false );
		if ( 'public' === $privacy ) {
			$item_fields = array_fill_keys( $all, true );
		} elseif ( 'approximate' === $privacy ) {
			foreach ( [ 'name', 'locality', 'region', 'country' ] as $key ) {
				$item_fields[ $key ] = true;
			}
		}
		foreach ( $item_fields as $key => $visible ) {
			$item_fields[ $key ] = $visible && ! empty( $post_fields[ $key ] );
		}

		return $item_fields;
	}

	/**
	 * Return the last calendar day that governs an item.
	 *
	 * @param string               $type         Travel kind.
	 * @param array<string, mixed> $item         Canonical item.
	 * @param string               $fallback_day Post publication day.
	 */
	public static function last_day( string $type, array $item, string $fallback_day ): string {
		if ( self::TRIP === $type ) {
			$day = self::sanitize_day( $item['date'] ?? '' );
			return '' !== $day ? $day : $fallback_day;
		}

		$days = [];
		foreach ( [ 'departure', 'arrival' ] as $key ) {
			$date = card_datetime( is_string( $item[ $key ] ?? null ) ? $item[ $key ] : '', true );
			if ( $date instanceof \DateTimeImmutable ) {
				$days[] = $date->format( 'Y-m-d' );
			}
		}
		return [] === $days ? $fallback_day : max( $days );
	}

	/**
	 * Return the first instant after a day has ended everywhere on Earth.
	 *
	 * @param string $day Calendar day.
	 */
	public static function reveal_at( string $day ): int {
		return ( new \DateTimeImmutable( $day . 'T00:00:00' . self::REVEAL_OFFSET ) )->modify( '+1 day' )->getTimestamp();
	}

	/**
	 * Whether one item remains under its date embargo.
	 *
	 * @param string               $type         Travel kind.
	 * @param array<string, mixed> $item         Canonical item.
	 * @param string               $fallback_day Post publication day.
	 * @param int                  $now          Current timestamp.
	 */
	public static function is_embargoed( string $type, array $item, string $fallback_day, int $now ): bool {
		return $now < self::reveal_at( self::last_day( $type, $item, $fallback_day ) );
	}

	/**
	 * Turn canonical stored items into the only shapes public consumers use.
	 *
	 * @param string                           $type         Travel kind.
	 * @param array<int, array<string, mixed>> $raw         Stored items.
	 * @param array<string, bool>              $post_fields Post-level visible fields.
	 * @param string                           $fallback_day Post publication day.
	 * @param int                              $now          Current timestamp.
	 * @param array{date:string,time:string}   $formats     Explicit display formats.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_items( string $type, array $raw, array $post_fields, string $fallback_day, int $now, array $formats ): array {
		$visible = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$fields = self::item_fields( is_string( $item['privacy'] ?? null ) ? $item['privacy'] : 'approximate', $post_fields );
			if ( ! in_array( true, $fields, true ) || self::is_embargoed( $type, $item, $fallback_day, $now ) ) {
				continue;
			}

			$number = count( $visible ) + 1;
			if ( self::TRIP === $type ) {
				$place     = self::visible_place( $item, $fields );
				$day       = self::sanitize_day( $item['date'] ?? '' );
				$visible[] = [
					'number'      => $number,
					'day'         => $day,
					'day_display' => self::display_day( $day, $formats['date'] ),
					'place'       => $place['place'],
					'label'       => $place['label'],
					'lat'         => $place['lat'],
					'lng'         => $place['lng'],
				];
				continue;
			}

			$visible[] = [
				'number'    => $number,
				'from'      => self::visible_place( is_array( $item['from'] ?? null ) ? $item['from'] : [], $fields ),
				'to'        => self::visible_place( is_array( $item['to'] ?? null ) ? $item['to'] : [], $fields ),
				'departure' => self::visible_datetime( $item['departure'] ?? '', $formats ),
				'arrival'   => self::visible_datetime( $item['arrival'] ?? '', $formats ),
				'carrier'   => is_string( $item['carrier'] ?? null ) ? $item['carrier'] : '',
			];
		}

		return $visible;
	}

	/**
	 * Return visitor-visible stops for a post.
	 *
	 * @param int      $post_id Post ID.
	 * @param int|null $now     Current timestamp override.
	 */
	public static function visible_stops( int $post_id, ?int $now = null ): array {
		return self::visible_items( $post_id, self::TRIP, self::STOPS_KEY, $now );
	}

	/**
	 * Return visitor-visible legs for a post.
	 *
	 * @param int      $post_id Post ID.
	 * @param int|null $now     Current timestamp override.
	 */
	public static function visible_legs( int $post_id, ?int $now = null ): array {
		return self::visible_items( $post_id, self::ITINERARY, self::LEGS_KEY, $now );
	}

	/**
	 * Return one meta list as visitor-safe JSON.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Canonical meta key.
	 */
	public static function public_json( int $post_id, string $key ): string {
		$items = self::STOPS_KEY === $key ? self::visible_stops( $post_id ) : ( self::LEGS_KEY === $key ? self::visible_legs( $post_id ) : [] );
		$json  = wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * First printable name for a place.
	 *
	 * @param array<string, string> $place Visible place.
	 */
	public static function place_label( array $place ): string {
		foreach ( [ 'name', 'locality', 'region', 'country' ] as $key ) {
			if ( is_string( $place[ $key ] ?? null ) && '' !== $place[ $key ] ) {
				return $place[ $key ];
			}
		}
		return '';
	}

	/**
	 * Derive the inclusive range over visible calendar days.
	 *
	 * @param array<int, mixed> $days Calendar days.
	 * @return array{start:string,end:string,display:string}
	 */
	public static function date_range( array $days ): array {
		$days = array_values( array_filter( array_map( static fn( mixed $day ): string => self::sanitize_day( $day ), $days ) ) );
		if ( [] === $days ) {
			return [
				'start'   => '',
				'end'     => '',
				'display' => '',
			];
		}
		$start  = min( $days );
		$end    = max( $days );
		$format = (string) get_option( 'date_format' );
		if ( $start === $end ) {
			$display = self::display_day( $start, $format );
		} elseif ( 'F j, Y' === $format && substr( $start, 0, 7 ) === substr( $end, 0, 7 ) ) {
			$display = self::display_day( $start, 'F j' ) . '–' . self::display_day( $end, 'j, Y' );
		} elseif ( 'F j, Y' === $format && substr( $start, 0, 4 ) === substr( $end, 0, 4 ) ) {
			$display = self::display_day( $start, 'F j' ) . ' – ' . self::display_day( $end, 'F j, Y' );
		} else {
			$display = self::display_day( $start, $format ) . ' – ' . self::display_day( $end, $format );
		}
		return compact( 'start', 'end', 'display' );
	}

	/**
	 * Print a route with an announced connector.
	 *
	 * @param string $from Origin label.
	 * @param string $to   Destination label.
	 */
	public static function route_html( string $from, string $to ): string {
		if ( '' === $from || '' === $to ) {
			return '';
		}
		return '<span class="pkiw-route"><span class="pkiw-route__from">' . esc_html( $from ) . '</span> <span aria-hidden="true">→</span><span class="screen-reader-text"> to </span> <span class="pkiw-route__to">' . esc_html( $to ) . '</span></span>';
	}

	/**
	 * Public trip facts for themes and abilities.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function trip_facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || self::TRIP !== Kind_Facts::kind_of( $post ) ) {
			return [];
		}
		$stops = self::visible_stops( $post_id );
		$count = count( $stops );
		return [
			'stops'       => $stops,
			'count'       => $count,
			'range'       => self::date_range( array_column( $stops, 'day' ) ),
			'origin'      => $stops[0]['label'] ?? '',
			'destination' => $count >= 2 ? (string) $stops[ $count - 1 ]['label'] : '',
			'mapped'      => count( array_filter( $stops, static fn( array $stop ): bool => null !== $stop['lat'] && null !== $stop['lng'] ) ),
		];
	}

	/**
	 * Public itinerary facts for themes and abilities.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function itinerary_facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || self::ITINERARY !== Kind_Facts::kind_of( $post ) ) {
			return [];
		}
		$legs  = self::visible_legs( $post_id );
		$count = count( $legs );
		$days  = [];
		if ( $count > 0 ) {
			$days[] = self::visible_leg_day( $legs[0], true );
			$days[] = self::visible_leg_day( $legs[ $count - 1 ], false );
		}
		$mapped = 0;
		foreach ( $legs as $leg ) {
			foreach ( [ 'from', 'to' ] as $endpoint ) {
				$mapped += null !== $leg[ $endpoint ]['lat'] && null !== $leg[ $endpoint ]['lng'] ? 1 : 0;
			}
		}
		return [
			'legs'        => $legs,
			'count'       => $count,
			'range'       => self::date_range( $days ),
			'origin'      => $legs[0]['from']['label'] ?? '',
			'destination' => $count > 0 ? (string) $legs[ $count - 1 ]['to']['label'] : '',
			'mapped'      => $mapped,
		];
	}

	/**
	 * Render an already-filtered stop list.
	 *
	 * @param array<int, array<string, mixed>> $stops   Visible stops.
	 * @param int                              $post_id Post ID.
	 */
	public static function render_stop_list( array $stops, int $post_id ): string {
		if ( [] === $stops ) {
			return '';
		}
		$range = self::date_range( array_column( $stops, 'day' ) );
		$html  = '<div class="wp-block-post-kinds-indieweb-trip-card pkiw-trip"><p class="pkiw-trip__summary"><span class="pkiw-trip__count">';
		/* translators: %s: Number of stops. */
		$html .= esc_html( sprintf( _n( '%s stop', '%s stops', count( $stops ), 'post-kinds-for-indieweb-in-block-themes' ), number_format_i18n( count( $stops ) ) ) ) . '</span>';
		if ( '' !== $range['display'] ) {
			$html .= ' <span class="pkiw-trip__range" data-start="' . esc_attr( $range['start'] ) . '" data-end="' . esc_attr( $range['end'] ) . '">' . esc_html( $range['display'] ) . '</span>';
		}
		$html .= '</p><ol class="pkiw-trip__stops">';
		foreach ( $stops as $stop ) {
			$number = (int) $stop['number'];
			$html  .= '<li class="pkiw-trip__stop" id="pkiw-stop-' . esc_attr( (string) $post_id ) . '-' . esc_attr( (string) $number ) . '" data-pkiw-stop="' . esc_attr( (string) $number ) . '">';
			$html  .= (string) apply_filters( 'pkiw_trip_stop_stamp_html', '', $stop, $post_id );
			$html  .= self::place_html( $stop, 'pkiw-trip' );
			if ( '' !== (string) $stop['day'] ) {
				$html .= '<time class="pkiw-trip__date" datetime="' . esc_attr( (string) $stop['day'] ) . '">' . esc_html( (string) $stop['day_display'] ) . '</time>';
			}
			$html .= '</li>';
		}
		$html .= '</ol>';
		if ( class_exists( __NAMESPACE__ . '\\Travel_Map' ) ) {
			$html .= Travel_Map::single_figure( $stops, $post_id );
		}
		return $html . '</div>';
	}

	/**
	 * Render an already-filtered itinerary list.
	 *
	 * @param array<int, array<string, mixed>> $legs    Visible legs.
	 * @param int                              $post_id Post ID.
	 */
	public static function render_leg_list( array $legs, int $post_id ): string {
		unset( $post_id );
		if ( [] === $legs ) {
			return '';
		}
		$range = self::itinerary_range( $legs );
		$html  = '<div class="wp-block-post-kinds-indieweb-itinerary-card pkiw-itinerary"><p class="pkiw-itinerary__summary"><span class="pkiw-itinerary__count">';
		/* translators: %s: Number of legs. */
		$html .= esc_html( sprintf( _n( '%s leg', '%s legs', count( $legs ), 'post-kinds-for-indieweb-in-block-themes' ), number_format_i18n( count( $legs ) ) ) ) . '</span>';
		if ( '' !== $range['display'] ) {
			$html .= ' <span class="pkiw-itinerary__range" data-start="' . esc_attr( $range['start'] ) . '" data-end="' . esc_attr( $range['end'] ) . '">' . esc_html( $range['display'] ) . '</span>';
		}
		$html .= '</p><ol class="pkiw-itinerary__legs">';
		foreach ( $legs as $leg ) {
			$number = (int) $leg['number'];
			/* translators: %d: Leg number. */
			$html .= '<li class="pkiw-itinerary__leg" data-pkiw-leg="' . esc_attr( (string) $number ) . '"><p class="pkiw-itinerary__leg-label">' . esc_html( sprintf( __( 'Leg %d', 'post-kinds-for-indieweb-in-block-themes' ), $number ) ) . '</p><dl class="pkiw-itinerary__pass">';
			$html .= self::definition_row( __( 'From', 'post-kinds-for-indieweb-in-block-themes' ), self::place_html( $leg['from'], 'pkiw-itinerary' ) );
			$html .= self::definition_row( __( 'To', 'post-kinds-for-indieweb-in-block-themes' ), self::place_html( $leg['to'], 'pkiw-itinerary' ) );
			foreach ( [
				'departure' => __( 'Departure', 'post-kinds-for-indieweb-in-block-themes' ),
				'arrival'   => __( 'Arrival', 'post-kinds-for-indieweb-in-block-themes' ),
			] as $key => $label ) {
				if ( is_array( $leg[ $key ] ) ) {
					$html .= self::definition_row( $label, '<time datetime="' . esc_attr( (string) $leg[ $key ]['iso'] ) . '">' . esc_html( (string) $leg[ $key ]['display'] ) . '</time>' );
				}
			}
			if ( '' !== (string) $leg['carrier'] ) {
				$html .= self::definition_row( __( 'Carrier', 'post-kinds-for-indieweb-in-block-themes' ), esc_html( (string) $leg['carrier'] ) );
			}
			$html .= '</dl></li>';
		}
		return $html . '</ol></div>';
	}

	/** Register both dynamic blocks, their editor, and shared style. */
	public static function register_blocks(): void {
		wp_register_script( 'pkiw-travel-card-editor', PKIW_URL . 'assets/js/travel-card-editor.js', [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-core-data', 'wp-data', 'wp-i18n' ], PKIW_VERSION, true );
		$offset = wp_timezone()->getOffset( new \DateTimeImmutable( 'now', wp_timezone() ) );
		wp_localize_script( 'pkiw-travel-card-editor', 'pkiwTravelCard', [ 'siteOffset' => self::format_offset( $offset ) ] );
		wp_set_script_translations( 'pkiw-travel-card-editor', 'post-kinds-for-indieweb-in-block-themes', PKIW_PATH . 'languages' );
		wp_register_style( 'pkiw-travel-card', PKIW_URL . 'styles/travel-card.css', [], PKIW_VERSION );

		foreach ( [
			self::STOP_BLOCK => [ self::class, 'render_trip_block' ],
			self::LEG_BLOCK  => [ self::class, 'render_itinerary_block' ],
		] as $name => $callback ) {
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}
			/**
			 * Block registration arguments.
			 *
			 * @var array<string, mixed> $args
			 */
			$args = [
				'api_version'     => 3,
				'title'           => self::STOP_BLOCK === $name ? __( 'Trip card', 'post-kinds-for-indieweb-in-block-themes' ) : __( 'Itinerary card', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'attributes'      => [],
				'supports'        => [
					'html'      => false,
					'multiple'  => false,
					'reusable'  => false,
					'className' => true,
				],
				'uses_context'    => [ 'postId', 'postType' ],
				'editor_script'   => 'pkiw-travel-card-editor',
				'style'           => 'pkiw-travel-card',
				'render_callback' => $callback,
			];
			register_block_type( $name, $args );
		}
	}

	/**
	 * Dynamic trip-card callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Inner content.
	 * @param \WP_Block            $block      Block instance.
	 */
	public static function render_trip_block( array $attributes, string $content, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		return self::render_stop_list( self::visible_stops( $post_id ), $post_id );
	}

	/**
	 * Dynamic itinerary-card callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Inner content.
	 * @param \WP_Block            $block      Block instance.
	 */
	public static function render_itinerary_block( array $attributes, string $content, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		return self::render_leg_list( self::visible_legs( $post_id ), $post_id );
	}

	/**
	 * Decode a canonical JSON list.
	 *
	 * @param mixed $value Stored meta value.
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function decode_list( mixed $value ): ?array {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) && array_is_list( $decoded ) ? $decoded : null;
	}

	/**
	 * Encode a canonical JSON list.
	 *
	 * @param array<int, array<string, mixed>> $items Canonical items.
	 */
	private static function encode_list( array $items ): string {
		if ( [] === $items ) {
			return '';
		}
		$json = wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * Sanitize one place dictionary.
	 *
	 * @param array<string, mixed> $place Raw place.
	 * @return array{0:array<string, mixed>,1:bool}
	 */
	private static function sanitize_place( array $place ): array {
		$clean = [];
		foreach ( self::PLACE_FIELDS as $key ) {
			$clean[ $key ] = 'url' === $key ? self::sanitize_url( $place[ $key ] ?? '' ) : self::sanitize_text( $place[ $key ] ?? '' );
		}
		$lat = self::coordinate( $place['lat'] ?? null, -90.0, 90.0 );
		$lng = self::coordinate( $place['lng'] ?? null, -180.0, 180.0 );
		if ( null === $lat || null === $lng ) {
			$lat = null;
			$lng = null;
		}
		$clean['lat'] = $lat;
		$clean['lng'] = $lng;
		$present      = null !== $lat || null !== $lng || [] !== array_filter( array_intersect_key( $clean, array_flip( self::PLACE_FIELDS ) ), static fn( mixed $value ): bool => '' !== $value );
		return [ $clean, $present ];
	}

	/**
	 * Sanitize a bounded text value.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function sanitize_text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, 200 );
	}

	/**
	 * Sanitize an HTTP or HTTPS URL.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function sanitize_url( mixed $value ): string {
		$url    = is_scalar( $value ) ? esc_url_raw( trim( (string) $value ), [ 'http', 'https' ] ) : '';
		$scheme = is_string( $url ) ? wp_parse_url( $url, PHP_URL_SCHEME ) : '';
		return in_array( $scheme, [ 'http', 'https' ], true ) ? (string) $url : '';
	}

	/**
	 * Sanitize one coordinate.
	 *
	 * @param mixed $value Raw value.
	 * @param float $min   Minimum value.
	 * @param float $max   Maximum value.
	 */
	private static function coordinate( mixed $value, float $min, float $max ): ?float {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;
		return is_finite( $number ) && $number >= $min && $number <= $max ? round( $number, 6 ) : null;
	}

	/**
	 * Sanitize an ISO calendar day.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function sanitize_day( mixed $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $value ), $parts ) ) {
			return '';
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? trim( $value ) : '';
	}

	/**
	 * Sanitize a wall-clock datetime.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function sanitize_datetime( mixed $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return card_datetime( $value ) instanceof \DateTimeImmutable ? $value : '';
	}

	/**
	 * Sanitize an item privacy tier.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function sanitize_privacy( mixed $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, [ 'private', 'approximate', 'public' ], true ) ? $value : 'approximate';
	}

	/**
	 * Project one place through its visible fields.
	 *
	 * @param array<string, mixed> $place  Canonical place.
	 * @param array<string, bool>  $fields Visible fields.
	 * @return array{place:array<string,string>,label:string,lat:?float,lng:?float}
	 */
	private static function visible_place( array $place, array $fields ): array {
		$visible = [];
		foreach ( self::PLACE_FIELDS as $key ) {
			if ( ! empty( $fields[ $key ] ) && is_string( $place[ $key ] ?? null ) && '' !== $place[ $key ] ) {
				$visible[ $key ] = $place[ $key ];
			}
		}
		$coordinates = ! empty( $fields['coordinates'] ) && ! empty( $fields['map'] ) && is_numeric( $place['lat'] ?? null ) && is_numeric( $place['lng'] ?? null );
		return [
			'place' => $visible,
			'label' => self::place_label( $visible ),
			'lat'   => $coordinates ? (float) $place['lat'] : null,
			'lng'   => $coordinates ? (float) $place['lng'] : null,
		];
	}

	/**
	 * Project a wall-clock datetime for visitors.
	 *
	 * @param mixed                          $value   Canonical value.
	 * @param array{date:string,time:string} $formats Display formats.
	 */
	private static function visible_datetime( mixed $value, array $formats ): ?array {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		[ $iso, $display ] = card_wall_clock( $value, $formats['date'] . ' · ' . $formats['time'], true );
		return '' === $iso ? null : [
			'iso'     => $iso,
			'display' => $display,
		];
	}

	/**
	 * Format one calendar day without timezone drift.
	 *
	 * @param string $day    Calendar day.
	 * @param string $format Display format.
	 */
	private static function display_day( string $day, string $format ): string {
		if ( '' === $day || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts ) ) {
			return '';
		}
		return (string) wp_date( $format, gmmktime( 12, 0, 0, (int) $parts[2], (int) $parts[3], (int) $parts[1] ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Return one canonical list after visitor filtering.
	 *
	 * @param int      $post_id Post ID.
	 * @param string   $type    Travel kind.
	 * @param string   $key     Canonical meta key.
	 * @param int|null $now     Current timestamp override.
	 */
	private static function visible_items( int $post_id, string $type, string $key, ?int $now ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return [];
		}
		$raw          = json_decode( (string) get_post_meta( $post_id, $key, true ), true );
		$fallback_day = get_the_date( 'Y-m-d', $post );
		$fallback_day = is_string( $fallback_day ) ? $fallback_day : '';
		if ( '' === $fallback_day ) {
			$fallback_day = substr( $post->post_date, 0, 10 );
		}
		return self::filter_items(
			$type,
			is_array( $raw ) && array_is_list( $raw ) ? $raw : [],
			Meta_Fields::get_public_location_fields( $post_id ),
			$fallback_day,
			$now ?? time(),
			[
				'date' => (string) get_option( 'date_format' ),
				'time' => (string) get_option( 'time_format' ),
			]
		);
	}

	/**
	 * Return one visible endpoint day.
	 *
	 * @param array<string, mixed> $leg   Visible leg.
	 * @param bool                 $first Whether to prefer departure.
	 */
	private static function visible_leg_day( array $leg, bool $first ): string {
		$keys = $first ? [ 'departure', 'arrival' ] : [ 'arrival', 'departure' ];
		foreach ( $keys as $key ) {
			if ( is_array( $leg[ $key ] ?? null ) && is_string( $leg[ $key ]['iso'] ?? null ) ) {
				return substr( $leg[ $key ]['iso'], 0, 10 );
			}
		}
		return '';
	}

	/**
	 * Derive the range for visible legs.
	 *
	 * @param array<int, array<string, mixed>> $legs Visible legs.
	 */
	private static function itinerary_range( array $legs ): array {
		return [] === $legs ? self::date_range( [] ) : self::date_range( [ self::visible_leg_day( $legs[0], true ), self::visible_leg_day( $legs[ array_key_last( $legs ) ], false ) ] );
	}

	/**
	 * Render a visible place or endpoint.
	 *
	 * @param array<string, mixed> $item   Visible item.
	 * @param string               $prefix CSS prefix.
	 */
	private static function place_html( array $item, string $prefix ): string {
		$place = is_array( $item['place'] ?? null ) ? $item['place'] : [];
		$html  = '';
		if ( '' !== (string) ( $place['name'] ?? '' ) ) {
			$html .= '<span class="' . esc_attr( $prefix ) . '__name">' . esc_html( (string) $place['name'] ) . '</span>';
		}
		$parts   = [];
		$classes = [
			'street'      => 'p-street-address',
			'locality'    => 'p-locality',
			'region'      => 'p-region',
			'country'     => 'p-country-name',
			'postal_code' => 'p-postal-code',
		];
		$street  = '' !== (string) ( $place['street'] ?? '' ) ? '<span class="' . $classes['street'] . '">' . esc_html( (string) $place['street'] ) . '</span>' : '';
		$postal  = '' !== (string) ( $place['postal_code'] ?? '' ) ? '<span class="' . $classes['postal_code'] . '">' . esc_html( (string) $place['postal_code'] ) . '</span>' : '';
		$local   = [];
		foreach ( [ 'locality', 'region', 'country' ] as $key ) {
			if ( '' !== (string) ( $place[ $key ] ?? '' ) ) {
				$local[] = '<span class="' . $classes[ $key ] . '">' . esc_html( (string) $place[ $key ] ) . '</span>';
			}
		}
		if ( '' !== $street ) {
			$parts[] = $street;
		}
		if ( [] !== $local ) {
			$parts[] = implode( ', ', $local );
		}
		if ( '' !== $postal ) {
			$parts[] = $postal;
		}
		$geo = '';
		if ( null !== ( $item['lat'] ?? null ) && null !== ( $item['lng'] ?? null ) ) {
			$lat = (string) $item['lat'];
			$lng = (string) $item['lng'];
			$geo = '<data class="p-geo h-geo" value="geo:' . esc_attr( $lat . ',' . $lng ) . '"><data class="p-latitude" value="' . esc_attr( $lat ) . '"></data><data class="p-longitude" value="' . esc_attr( $lng ) . '"></data></data>';
		}
		if ( [] !== $parts || '' !== $geo ) {
			$html .= '<span class="' . esc_attr( $prefix ) . '__place h-adr">' . implode( ' ', $parts ) . $geo . '</span>';
		}
		return $html;
	}

	/**
	 * Render one non-empty definition pair.
	 *
	 * @param string $label Term label.
	 * @param string $value Definition markup.
	 */
	private static function definition_row( string $label, string $value ): string {
		return '' === $value ? '' : '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
	}

	/**
	 * Format a timezone offset.
	 *
	 * @param int $seconds Offset seconds.
	 */
	private static function format_offset( int $seconds ): string {
		$sign    = $seconds < 0 ? '-' : '+';
		$seconds = abs( $seconds );
		return sprintf( '%s%02d:%02d', $sign, intdiv( $seconds, HOUR_IN_SECONDS ), intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS ) );
	}
}
