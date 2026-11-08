<?php
/**
 * Shared maps for trip and itinerary archives and trip cards.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Build privacy-filtered travel map data and markup. */
final class Travel_Map {

	public const BLOCK = 'post-kinds-indieweb/travel-map';

	private const SAME_PLACE_PRECISION = 4;

	/**
	 * Archive entry numbers for the current page.
	 *
	 * @var array<int, int>
	 */
	private static array $page_numbers = [];

	/**
	 * Most recently rendered travel map id.
	 *
	 * @var string
	 */
	private static string $map_id = '';

	/** Wire the dynamic archive map block. */
	public function register(): void {
		if ( did_action( 'init' ) ) {
			self::register_block();
		} else {
			add_action( 'init', [ self::class, 'register_block' ] );
		}
	}

	/** Register the dynamic archive map block. */
	public static function register_block(): void {
		Travel_Archive::register_editor_assets();
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}
		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Travel map', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render_block' ],
			'uses_context'    => [ 'query', 'queryId', 'templateSlug' ],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => Travel_Archive::EDITOR_SCRIPT,
			'style'           => Travel_Archive::STYLE,
		];
		register_block_type( self::BLOCK, $args );
	}

	/**
	 * Visible route points for one travel post.
	 *
	 * @param \WP_Post $post Travel post.
	 * @return array<int, array{lat:float,lng:float,entry:int,number:int,title:string}>
	 */
	public static function points_for( \WP_Post $post ): array {
		$kind  = Kind_Facts::kind_of( $post );
		$title = trim( visitor_post_title( $post ) );
		if ( '' === $title ) {
			$title = untitled_name( $post );
		}

		$points = [];
		if ( Travel::TRIP === $kind ) {
			foreach ( Travel::visible_stops( $post->ID ) as $stop ) {
				if ( null === ( $stop['lat'] ?? null ) || null === ( $stop['lng'] ?? null ) ) {
					continue;
				}
				$points[] = self::point( $post->ID, (int) $stop['number'], $title, (float) $stop['lat'], (float) $stop['lng'] );
			}
		} elseif ( Travel::ITINERARY === $kind ) {
			foreach ( Travel::visible_legs( $post->ID ) as $leg ) {
				foreach ( [ $leg['from'] ?? [], $leg['to'] ?? [] ] as $place ) {
					if ( null === ( $place['lat'] ?? null ) || null === ( $place['lng'] ?? null ) ) {
						continue;
					}
					$point = self::point( $post->ID, (int) $leg['number'], $title, (float) $place['lat'], (float) $place['lng'] );
					$last  = end( $points );
					if ( false !== $last && self::place_key( $last['lat'], $last['lng'] ) === self::place_key( $point['lat'], $point['lng'] ) ) {
						continue;
					}
					$points[] = $point;
				}
			}
		}

		return $points;
	}

	/**
	 * Merge points at the same four-decimal place into accessible pins.
	 *
	 * @param array<int, array<string, mixed>> $points Visible points.
	 * @return array<int, array{lat:float,lng:float,ids:int[],numbers:int[],label:string,targets?:string[]}>
	 */
	public static function pins( array $points ): array {
		$pins = [];
		foreach ( $points as $point ) {
			if ( ! isset( $point['lat'], $point['lng'], $point['entry'], $point['number'], $point['title'] ) ) {
				continue;
			}
			$key = self::place_key( (float) $point['lat'], (float) $point['lng'] );
			if ( ! isset( $pins[ $key ] ) ) {
				$pins[ $key ] = [
					'lat'     => (float) $point['lat'],
					'lng'     => (float) $point['lng'],
					'ids'     => [],
					'numbers' => [],
					'titles'  => [],
					'targets' => [],
				];
			}
			$id = (int) $point['entry'];
			if ( ! in_array( $id, $pins[ $key ]['ids'], true ) ) {
				$pins[ $key ]['ids'][] = $id;
			}
			$target = isset( $point['target'] ) && is_string( $point['target'] ) ? $point['target'] : '';
			if ( '' !== $target && ! in_array( $target, $pins[ $key ]['targets'], true ) ) {
				$pins[ $key ]['targets'][] = $target;
				$pins[ $key ]['titles'][]  = (string) $point['title'];
			} elseif ( '' === $target && ! in_array( (string) $point['title'], $pins[ $key ]['titles'], true ) ) {
				$pins[ $key ]['titles'][] = (string) $point['title'];
			}
			$number = (int) $point['number'];
			if ( ! in_array( $number, $pins[ $key ]['numbers'], true ) ) {
				$pins[ $key ]['numbers'][] = $number;
			}
		}

		foreach ( $pins as $key => $pin ) {
			$count = count( $pin['ids'] );
			$kind  = self::kind_for_entry( (int) $pin['ids'][0] );
			if ( [] !== $pin['targets'] ) {
				$pins[ $key ]['label'] = implode( ', ', $pin['titles'] );
			} elseif ( 1 === $count ) {
				$pins[ $key ]['label'] = sprintf(
					/* translators: 1: entry number, 2: post title */
					__( '%1$d: %2$s. Go to its list entry.', 'post-kinds-for-indieweb-in-block-themes' ),
					$pin['numbers'][0],
					$pin['titles'][0]
				);
			} else {
				if ( Travel::ITINERARY === $kind ) {
					/* translators: 1: number of itineraries, 2: their list numbers */
					$label = _n(
						'%1$d itinerary at this location: number %2$s. Go to the first in the list.',
						'%1$d itineraries at this location: numbers %2$s. Go to the first in the list.',
						$count,
						'post-kinds-for-indieweb-in-block-themes'
					);
				} else {
					/* translators: 1: number of trips, 2: their list numbers */
					$label = _n(
						'%1$d trip at this location: number %2$s. Go to the first in the list.',
						'%1$d trips at this location: numbers %2$s. Go to the first in the list.',
						$count,
						'post-kinds-for-indieweb-in-block-themes'
					);
				}
				$pins[ $key ]['label'] = sprintf(
					$label,
					$count,
					implode( ', ', $pin['numbers'] )
				);
			}
			unset( $pins[ $key ]['titles'] );
			if ( [] === $pins[ $key ]['targets'] ) {
				unset( $pins[ $key ]['targets'] );
			}
		}

		return array_values( $pins );
	}

	/**
	 * One route for each post with at least two visible points.
	 *
	 * @param \WP_Post[] $posts Posts in page order.
	 * @return array<int, array{entry:int,points:array<int, array{0:float,1:float}>}>
	 */
	public static function routes( array $posts ): array {
		$routes = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$points = self::points_for( $post );
			if ( count( $points ) < 2 ) {
				continue;
			}
			$routes[] = [
				'entry'  => $post->ID,
				'points' => array_map( static fn( array $point ): array => [ (float) $point['lat'], (float) $point['lng'] ], $points ),
			];
		}

		return $routes;
	}

	/**
	 * Assign archive list numbers only to posts that have a visible point.
	 *
	 * @param \WP_Post[] $posts Posts in page order.
	 * @return array<int, int>
	 */
	public static function page_numbers( array $posts ): array {
		self::$page_numbers = [];
		$number             = 0;
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && [] !== self::points_for( $post ) ) {
				self::$page_numbers[ $post->ID ] = ++$number;
			}
		}

		return self::$page_numbers;
	}

	/**
	 * "6 trips · 4 mapped", or the itinerary equivalent.
	 *
	 * @param string $kind   Travel kind.
	 * @param int    $total  Posts on the page.
	 * @param int    $mapped Posts with one or more visible points.
	 */
	public static function summary( string $kind, int $total, int $mapped ): string {
		$text = Travel::ITINERARY === $kind
			? sprintf(
				/* translators: %s: number of itineraries on the page */
				_n( '%s itinerary', '%s itineraries', $total, 'post-kinds-for-indieweb-in-block-themes' ),
				number_format_i18n( $total )
			)
			: sprintf(
				/* translators: %s: number of trips on the page */
				_n( '%s trip', '%s trips', $total, 'post-kinds-for-indieweb-in-block-themes' ),
				number_format_i18n( $total )
			);
		if ( $mapped > 0 ) {
			$text .= ' · ' . sprintf(
				/* translators: %s: number of mapped entries */
				__( '%s mapped', 'post-kinds-for-indieweb-in-block-themes' ),
				number_format_i18n( $mapped )
			);
		}

		return $text;
	}

	/**
	 * Render one hidden-until-drawn map figure.
	 *
	 * @param array<int, array<string, mixed>> $pins        Visible pins.
	 * @param array<int, array<string, mixed>> $routes      Visible routes.
	 * @param string                           $caption     Figure caption.
	 * @param string                           $describedby Summary element id.
	 */
	public static function figure( array $pins, array $routes, string $caption, string $describedby ): string {
		if ( [] === $pins ) {
			return '';
		}
		self::enqueue();
		self::$map_id = wp_unique_id( 'pkiw-travel-map-' );
		$caption_id   = self::$map_id . '-caption';
		$tiles        = Checkin_Map::tile_layer();
		$consent      = (bool) apply_filters( 'pkiw_checkin_map_requires_consent', false );
		$kind         = self::kind_for_entry( (int) ( $pins[0]['ids'][0] ?? 0 ) );
		if ( ! empty( $pins[0]['targets'] ) ) {
			/* translators: %d: number of stops in a map cluster */
			$cluster = __( '%d stops in this area. Zoom in.', 'post-kinds-for-indieweb-in-block-themes' );
		} else {
			$cluster = Travel::ITINERARY === $kind
				? /* translators: %d: number of itineraries in a map cluster */ __( '%d itineraries in this area. Zoom in.', 'post-kinds-for-indieweb-in-block-themes' )
				: /* translators: %d: number of trips in a map cluster */ __( '%d trips in this area. Zoom in.', 'post-kinds-for-indieweb-in-block-themes' );
		}

		return '<figure class="pkiw-travel-map" id="' . esc_attr( self::$map_id ) . '"'
			. ' aria-describedby="' . esc_attr( '' !== $describedby ? $describedby : $caption_id ) . '"'
			. ' data-pins="' . esc_attr( (string) wp_json_encode( $pins ) ) . '"'
			. ' data-routes="' . esc_attr( (string) wp_json_encode( $routes ) ) . '"'
			. ' data-tile-url="' . esc_attr( $tiles['url'] ) . '"'
			. ' data-attribution="' . esc_attr( $tiles['attribution'] ) . '"'
			. ' data-max-zoom="' . esc_attr( (string) $tiles['maxZoom'] ) . '"'
			. ' data-cluster-label="' . esc_attr( $cluster ) . '"'
			. ( $consent ? ' data-pkiw-consent="required"' : '' )
			. ' hidden><figcaption class="pkiw-travel-map__caption screen-reader-text" id="' . esc_attr( $caption_id ) . '">' . esc_html( $caption ) . '</figcaption></figure>';
	}

	/**
	 * Render a trip card's map from already-visible stops.
	 *
	 * @param array<int, array<string, mixed>> $stops   Visible stops.
	 * @param int                              $post_id Trip post ID.
	 */
	public static function single_figure( array $stops, int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$points = [];
		foreach ( $stops as $stop ) {
			if ( null !== ( $stop['lat'] ?? null ) && null !== ( $stop['lng'] ?? null ) ) {
				$point           = self::point( $post_id, (int) $stop['number'], (string) ( $stop['label'] ?? '' ), (float) $stop['lat'], (float) $stop['lng'] );
				$point['target'] = 'pkiw-stop-' . $post_id . '-' . (int) $stop['number'];
				$points[]        = $point;
			}
		}
		$routes = count( $points ) > 1 ? [
			[
				'entry'  => $post_id,
				'points' => array_map( static fn( array $point ): array => [ $point['lat'], $point['lng'] ], $points ),
			],
		] : [];

		return self::figure( self::pins( $points ), $routes, __( 'Map of the trip stops', 'post-kinds-for-indieweb-in-block-themes' ), '' );
	}

	/** Enqueue the O-M0 fallback's existing classic map handle. */
	public static function enqueue(): void {
		Checkin_Map::enqueue_assets();
	}

	/**
	 * Render the inherited page's archive map and summary.
	 *
	 * @param array<string, mixed> $attributes Unused block attributes.
	 * @param string               $content    Unused saved content.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$posts   = Travel_Archive::page_posts( $block );
		$kind    = Travel_Archive::kind_for_posts( $posts, $block );
		$numbers = self::page_numbers( $posts );
		$points  = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! isset( $numbers[ $post->ID ] ) ) {
				continue;
			}
			foreach ( self::points_for( $post ) as $point ) {
				$point['number'] = $numbers[ $post->ID ];
				$points[]        = $point;
			}
		}
		$pins = self::pins( $points );
		if ( [] === $pins ) {
			return '';
		}
		$summary_id = wp_unique_id( 'pkiw-travel-map-summary-' );
		$caption    = Travel::ITINERARY === $kind ? __( 'Map of the itineraries on this page', 'post-kinds-for-indieweb-in-block-themes' ) : __( 'Map of the trips on this page', 'post-kinds-for-indieweb-in-block-themes' );

		return self::figure( $pins, self::routes( $posts ), $caption, $summary_id )
			. '<p class="pkiw-travel-map__summary" id="' . esc_attr( $summary_id ) . '">' . esc_html( self::summary( $kind, count( $posts ), count( $numbers ) ) ) . '</p>';
	}

	/** Current archive map id, after its block has rendered. */
	public static function current_map_id(): string {
		return self::$map_id;
	}

	/**
	 * Current archive list number for one post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function number_for( int $post_id ): int {
		return self::$page_numbers[ $post_id ] ?? 0;
	}

	/**
	 * Build one point in the permanent client-data shape.
	 *
	 * @param int    $entry  Post ID.
	 * @param int    $number Visible list number.
	 * @param string $title  Visitor-safe post title.
	 * @param float  $lat    Latitude.
	 * @param float  $lng    Longitude.
	 * @return array{lat:float,lng:float,entry:int,number:int,title:string}
	 */
	private static function point( int $entry, int $number, string $title, float $lat, float $lng ): array {
		return [
			'lat'    => $lat,
			'lng'    => $lng,
			'entry'  => $entry,
			'number' => $number,
			'title'  => $title,
		];
	}

	/**
	 * Four-decimal coordinate key used for same-place merging.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 */
	private static function place_key( float $lat, float $lng ): string {
		return round( $lat, self::SAME_PLACE_PRECISION ) . ',' . round( $lng, self::SAME_PLACE_PRECISION );
	}

	/**
	 * Read the travel kind for a pin's first post.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function kind_for_entry( int $post_id ): string {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post ? Kind_Facts::kind_of( $post ) : Travel::TRIP;
	}
}
