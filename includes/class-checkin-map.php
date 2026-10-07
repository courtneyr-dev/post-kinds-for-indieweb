<?php
/**
 * The check-in archive: one page of check-ins as a list and a map.
 *
 * Builds the list entries and the map pins for a set of posts behind
 * Meta_Fields::get_visible_location_fields(), so nothing a visitor may not
 * see reaches the markup or the pin data. The Check-ins Feed block prints
 * it when it inherits the archive's query, and the Check-in Dashboard reads
 * the same tile settings.
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
 * Check-in archive list and map.
 */
class Checkin_Map {

	/**
	 * Check-ins per archive page when the template doesn't say.
	 */
	public const DEFAULT_PER_PAGE = 24;

	/**
	 * Decimal places two check-ins must share to count as one location.
	 * Four places is about 11 metres.
	 */
	private const SAME_PLACE_PRECISION = 4;

	/**
	 * Tile layer settings for every Leaflet map the plugin draws.
	 *
	 * @return array{url: string, attribution: string, maxZoom: int}
	 */
	public static function tile_layer(): array {
		/**
		 * Filters the map tile URL template.
		 *
		 * The default is the OpenStreetMap standard tile service, which
		 * sees each visitor's IP address and the page's origin. Return
		 * another provider's template, or your own tile cache.
		 *
		 * @since 1.9.0
		 *
		 * @param string $url Leaflet tile URL template.
		 */
		$url = (string) apply_filters( 'pkiw_map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' );

		/**
		 * Filters the map attribution HTML. It stays visible on the map.
		 *
		 * @since 1.9.0
		 *
		 * @param string $attribution Attribution HTML.
		 */
		$attribution = (string) apply_filters(
			'pkiw_map_tile_attribution',
			'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		);

		return [
			'url'         => $url,
			'attribution' => wp_kses(
				$attribution,
				[
					'a' => [
						'href' => true,
						'rel'  => true,
					],
				]
			),
			'maxZoom'     => 19,
		];
	}

	/**
	 * Posts per page a template asks for, from its inheriting Check-ins Feed.
	 *
	 * @param string $template_content Block template markup.
	 * @return int Zero when the template has no inheriting feed.
	 */
	public static function template_per_page( string $template_content ): int {
		if ( ! preg_match_all( '/<!-- wp:post-kinds-indieweb\/checkins-feed (\{.*?\}) \/-->/', $template_content, $matches ) ) {
			return 0;
		}

		foreach ( $matches[1] as $json ) {
			$attrs = json_decode( $json, true );
			if ( is_array( $attrs ) && ! empty( $attrs['inherit'] ) ) {
				return max( 1, min( 100, (int) ( $attrs['count'] ?? self::DEFAULT_PER_PAGE ) ) );
			}
		}

		return 0;
	}

	/**
	 * One list entry per post, holding only what the viewer may see.
	 *
	 * @param \WP_Post[] $posts Posts, in list order.
	 * @return array<int, array{id: int, title: string, url: string, date_iso: string, date: string, name: string, place: array<string, string>, lat: float|null, lng: float|null, number: int}>
	 */
	public static function entries( array $posts ): array {
		$entries = [];
		$number  = 0;

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$visible = Meta_Fields::get_visible_location_fields( $post->ID );
			$meta    = static fn( string $key ): string => trim( (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . $key, true ) );

			$place = [];
			foreach ( [ 'locality', 'region', 'country' ] as $part ) {
				if ( $visible[ $part ] && '' !== $meta( 'checkin_' . $part ) ) {
					$place[ $part ] = $meta( 'checkin_' . $part );
				}
			}

			$lat = null;
			$lng = null;
			if ( $visible['coordinates'] && $visible['map'] && is_numeric( $meta( 'geo_latitude' ) ) && is_numeric( $meta( 'geo_longitude' ) ) ) {
				$lat = (float) $meta( 'geo_latitude' );
				$lng = (float) $meta( 'geo_longitude' );
			}

			// An untitled check-in still needs link text.
			$title = trim( get_the_title( $post ) );
			if ( '' === $title ) {
				$title = sprintf(
					/* translators: %s: the post's date */
					__( 'Check-in, %s', 'post-kinds-for-indieweb-in-block-themes' ),
					(string) get_the_date( '', $post )
				);
			}

			$entries[] = [
				'id'       => $post->ID,
				'title'    => $title,
				'url'      => (string) get_permalink( $post ),
				'date_iso' => (string) get_the_date( 'c', $post ),
				'date'     => (string) get_the_date( '', $post ),
				'name'     => $visible['name'] ? $meta( 'checkin_name' ) : '',
				'place'    => $place,
				'lat'      => $lat,
				'lng'      => $lng,
				'number'   => null === $lat ? 0 : ++$number,
			];
		}

		return $entries;
	}

	/**
	 * Map pins for a set of entries. Check-ins at one location share a pin.
	 *
	 * @param array<int, array<string, mixed>> $entries Result of entries().
	 * @return array<int, array{lat: float, lng: float, ids: int[], numbers: int[], label: string}>
	 */
	public static function pins( array $entries ): array {
		$pins = [];

		foreach ( $entries as $entry ) {
			if ( null === $entry['lat'] || null === $entry['lng'] ) {
				continue;
			}

			$key = round( $entry['lat'], self::SAME_PLACE_PRECISION ) . ',' . round( $entry['lng'], self::SAME_PLACE_PRECISION );

			if ( ! isset( $pins[ $key ] ) ) {
				$pins[ $key ] = [
					'lat'     => $entry['lat'],
					'lng'     => $entry['lng'],
					'ids'     => [],
					'numbers' => [],
					'titles'  => [],
				];
			}

			$pins[ $key ]['ids'][]     = $entry['id'];
			$pins[ $key ]['numbers'][] = $entry['number'];
			$pins[ $key ]['titles'][]  = $entry['title'];
		}

		foreach ( $pins as $key => $pin ) {
			$count = count( $pin['ids'] );

			$pins[ $key ]['label'] = 1 === $count
				? sprintf(
					/* translators: 1: the entry's number in the list, 2: post title */
					__( '%1$d: %2$s. Go to its list entry.', 'post-kinds-for-indieweb-in-block-themes' ),
					$pin['numbers'][0],
					$pin['titles'][0]
				)
				: sprintf(
					/* translators: 1: number of check-ins, 2: their numbers in the list, comma separated */
					_n(
						'%1$d check-in at this location: number %2$s. Go to the first in the list.',
						'%1$d check-ins at this location: numbers %2$s. Go to the first in the list.',
						$count,
						'post-kinds-for-indieweb-in-block-themes'
					),
					$count,
					implode( ', ', $pin['numbers'] )
				);

			unset( $pins[ $key ]['titles'] );
		}

		return array_values( $pins );
	}

	/**
	 * "6 check-ins · 4 mapped", or "3 check-ins" when nothing is mapped.
	 *
	 * @param int $total  Entries on the page.
	 * @param int $mapped Entries with a pin.
	 */
	public static function summary( int $total, int $mapped ): string {
		$text = sprintf(
			/* translators: %s: number of check-ins on the page */
			_n( '%s check-in', '%s check-ins', $total, 'post-kinds-for-indieweb-in-block-themes' ),
			number_format_i18n( $total )
		);

		if ( $mapped > 0 ) {
			$text .= ' · ' . sprintf(
				/* translators: %s: number of check-ins that have a map pin */
				__( '%s mapped', 'post-kinds-for-indieweb-in-block-themes' ),
				number_format_i18n( $mapped )
			);
		}

		return $text;
	}

	/**
	 * Load Leaflet and the archive map script. Called only when a map renders.
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style( 'leaflet', PKIW_URL . 'assets/vendor/leaflet/leaflet.css', [], '1.9.4' );
		wp_enqueue_script( 'leaflet', PKIW_URL . 'assets/vendor/leaflet/leaflet.js', [], '1.9.4', true );
		wp_enqueue_style( 'leaflet-markercluster', PKIW_URL . 'assets/vendor/leaflet-markercluster/MarkerCluster.css', [ 'leaflet' ], '1.4.1' );
		wp_enqueue_script( 'leaflet-markercluster', PKIW_URL . 'assets/vendor/leaflet-markercluster/leaflet.markercluster.js', [ 'leaflet' ], '1.4.1', true );
		wp_enqueue_script( 'pkiw-checkin-map', PKIW_URL . 'assets/js/checkin-map.js', [ 'leaflet', 'leaflet-markercluster' ], PKIW_VERSION, true );
	}

	/**
	 * The archive: a map of the page's public check-ins and the full list.
	 *
	 * The list is complete without JavaScript. The map element stays
	 * `hidden` until the script draws it.
	 *
	 * @param \WP_Post[] $posts              Posts on this page, in order.
	 * @param string     $wrapper_attributes Block wrapper attributes.
	 * @param int        $heading_level      Heading level for entry titles.
	 * @return string
	 */
	public static function render_archive( array $posts, string $wrapper_attributes, int $heading_level = 2 ): string {
		$entries = self::entries( $posts );
		if ( ! $entries ) {
			return '';
		}

		$pins    = self::pins( $entries );
		$mapped  = count( array_filter( $entries, static fn( array $entry ): bool => $entry['number'] > 0 ) );
		$map_id  = wp_unique_id( 'pkiw-checkin-map-' );
		$sum_id  = $map_id . '-summary';
		$tag     = 'h' . max( 2, min( 6, $heading_level ) );
		$has_map = (bool) $pins;

		if ( $has_map ) {
			self::enqueue_assets();
		}

		/**
		 * Filters whether the check-in archive map waits for consent.
		 *
		 * When true, the map container carries data-pkiw-consent="required"
		 * and the map script loads no tiles until the page dispatches a
		 * `pkiw:map-consent` event on `document`, or sets
		 * `window.pkiwMapConsent = true` before the script runs. The list
		 * prints in full either way.
		 *
		 * @since 1.9.0
		 *
		 * @param bool $requires_consent Default false: the map draws on page load.
		 */
		$requires_consent = $has_map && (bool) apply_filters( 'pkiw_checkin_map_requires_consent', false );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $has_map ) : ?>
				<?php $tiles = self::tile_layer(); ?>
				<div
					class="pkiw-checkin-archive__map"
					id="<?php echo esc_attr( $map_id ); ?>"
					role="region"
					aria-label="<?php esc_attr_e( 'Map of the check-ins on this page', 'post-kinds-for-indieweb-in-block-themes' ); ?>"
					aria-describedby="<?php echo esc_attr( $sum_id ); ?>"
					data-pins="<?php echo esc_attr( (string) wp_json_encode( $pins ) ); ?>"
					data-tile-url="<?php echo esc_attr( $tiles['url'] ); ?>"
					data-attribution="<?php echo esc_attr( $tiles['attribution'] ); ?>"
					data-max-zoom="<?php echo esc_attr( (string) $tiles['maxZoom'] ); ?>"
					data-cluster-label="<?php /* translators: %d: number of check-ins */ esc_attr_e( '%d check-ins in this area. Zoom in.', 'post-kinds-for-indieweb-in-block-themes' ); ?>"
					<?php if ( $requires_consent ) : ?>
						data-pkiw-consent="required"
					<?php endif; ?>
					hidden
				></div>
			<?php endif; ?>

			<div class="pkiw-checkin-archive__list">
				<p class="pkiw-checkin-archive__summary" id="<?php echo esc_attr( $sum_id ); ?>"><?php echo esc_html( self::summary( count( $entries ), $mapped ) ); ?></p>

				<ul class="pkiw-checkin-archive__entries h-feed" role="list">
					<?php foreach ( $entries as $entry ) : ?>
						<li class="pkiw-checkin-archive__entry h-entry<?php echo $entry['number'] ? ' is-mapped' : ''; ?>" id="<?php echo esc_attr( 'pkiw-checkin-entry-' . $entry['id'] ); ?>">
							<?php if ( $entry['number'] ) : ?>
								<span
									class="pkiw-checkin-archive__num"
									data-map="<?php echo esc_attr( $map_id ); ?>"
									data-entry="<?php echo esc_attr( (string) $entry['id'] ); ?>"
									data-label="<?php echo esc_attr( sprintf( /* translators: %s: post title */ __( 'Show %s on map', 'post-kinds-for-indieweb-in-block-themes' ), $entry['title'] ) ); ?>"
								><?php echo esc_html( (string) $entry['number'] ); ?></span>
							<?php endif; ?>

							<div class="pkiw-checkin-archive__body">
								<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is h2 to h6. ?>
								<<?php echo $tag; ?> class="pkiw-checkin-archive__title p-name"><a class="u-url" href="<?php echo esc_url( $entry['url'] ); ?>"><?php echo esc_html( $entry['title'] ); ?></a></<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

								<?php if ( '' !== $entry['name'] || $entry['place'] ) : ?>
									<p class="pkiw-checkin-archive__place p-location h-card">
										<?php
										$parts = [];
										if ( '' !== $entry['name'] ) {
											$parts[] = '<span class="p-name">' . esc_html( $entry['name'] ) . '</span>';
										}
										$town = [];
										$classes = [
											'locality' => 'p-locality',
											'region'   => 'p-region',
											'country'  => 'p-country-name',
										];
										foreach ( $classes as $part => $class ) {
											if ( isset( $entry['place'][ $part ] ) ) {
												$town[] = '<span class="' . $class . '">' . esc_html( $entry['place'][ $part ] ) . '</span>';
											}
										}
										if ( $town ) {
											$parts[] = implode( ', ', $town );
										}
										// Each part is escaped above.
										echo implode( ' · ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										?>
										<?php if ( null !== $entry['lat'] ) : ?>
											<data class="p-geo h-geo" value="<?php echo esc_attr( sprintf( 'geo:%F,%F', $entry['lat'], $entry['lng'] ) ); ?>"><data class="p-latitude" value="<?php echo esc_attr( (string) $entry['lat'] ); ?>" hidden></data><data class="p-longitude" value="<?php echo esc_attr( (string) $entry['lng'] ); ?>" hidden></data></data>
										<?php endif; ?>
									</p>
								<?php endif; ?>

								<time class="pkiw-checkin-archive__date dt-published" datetime="<?php echo esc_attr( $entry['date_iso'] ); ?>"><?php echo esc_html( $entry['date'] ); ?></time>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
