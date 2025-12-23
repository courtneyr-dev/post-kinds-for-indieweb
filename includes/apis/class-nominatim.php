<?php
/**
 * Nominatim API Integration
 *
 * Provides geocoding and reverse geocoding using OpenStreetMap's Nominatim.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb\APIs;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nominatim API class.
 *
 * @since 1.0.0
 */
class Nominatim extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'nominatim';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://nominatim.openstreetmap.org/';

	/**
	 * Rate limit: 1 request per second (OSM policy).
	 *
	 * @var float
	 */
	protected float $rate_limit = 1.0;

	/**
	 * Cache duration: 1 month (coordinates don't change often).
	 *
	 * @var int
	 */
	protected int $cache_duration = MONTH_IN_SECONDS;

	/**
	 * Custom Nominatim server URL.
	 *
	 * @var string|null
	 */
	private ?string $custom_server = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->custom_server = $this->get_option( 'nominatim_server' );

		if ( $this->custom_server ) {
			$this->base_url = rtrim( $this->custom_server, '/' ) . '/';
		}
	}

	/**
	 * Get default headers.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		return array(
			'Accept'     => 'application/json',
			'User-Agent' => $this->user_agent,
		);
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		try {
			$this->get( 'search', array( 'q' => 'New York', 'format' => 'json', 'limit' => 1 ) );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Search for locations (geocoding).
	 *
	 * @param string $query Search query.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		$cache_key = 'search_' . md5( $query );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'search',
				array(
					'q'              => $query,
					'format'         => 'json',
					'addressdetails' => 1,
					'extratags'      => 1,
					'limit'          => 25,
				)
			);

			$results = array();

			if ( is_array( $response ) ) {
				foreach ( $response as $item ) {
					$results[] = $this->normalize_result( $item );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Search failed', array( 'query' => $query, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Structured search with individual address components.
	 *
	 * @param array<string, string> $params Search parameters.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function structured_search( array $params ): array {
		$allowed_params = array( 'street', 'city', 'county', 'state', 'country', 'postalcode' );
		$search_params = array_intersect_key( $params, array_flip( $allowed_params ) );

		if ( empty( $search_params ) ) {
			return array();
		}

		$cache_key = 'structured_' . md5( wp_json_encode( $search_params ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$search_params['format']         = 'json';
			$search_params['addressdetails'] = 1;
			$search_params['limit']          = 25;

			$response = $this->get( 'search', $search_params );

			$results = array();

			if ( is_array( $response ) ) {
				foreach ( $response as $item ) {
					$results[] = $this->normalize_location( $item );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Structured search failed', array( 'params' => $search_params, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Geocode an address.
	 *
	 * @param string $address Full address.
	 * @return array<string, mixed>|null Geocoded location.
	 */
	public function geocode( string $address ): ?array {
		$results = $this->search( $address );

		if ( ! empty( $results ) ) {
			return $results[0];
		}

		return null;
	}

	/**
	 * Reverse geocode coordinates.
	 *
	 * @param float $lat  Latitude.
	 * @param float $lng  Longitude.
	 * @param int   $zoom Zoom level (0-18, affects detail level).
	 * @return array<string, mixed>|null Location data.
	 */
	public function reverse( float $lat, float $lng, int $zoom = 18 ): ?array {
		$cache_key = "reverse_{$lat}_{$lng}_{$zoom}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'reverse',
				array(
					'lat'            => $lat,
					'lon'            => $lng,
					'format'         => 'json',
					'addressdetails' => 1,
					'extratags'      => 1,
					'zoom'           => min( 18, max( 0, $zoom ) ),
				)
			);

			if ( isset( $response['lat'] ) ) {
				$result = $this->normalize_location( $response );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Reverse geocode failed', array( 'lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get by OSM ID.
	 *
	 * @param string $id OSM ID in format "N123", "W456", or "R789".
	 * @return array<string, mixed>|null Location data.
	 */
	public function get_by_id( string $id ): ?array {
		return $this->lookup( $id );
	}

	/**
	 * Lookup by OSM ID.
	 *
	 * @param string $osm_id OSM ID with type prefix (N/W/R).
	 * @return array<string, mixed>|null Location data.
	 */
	public function lookup( string $osm_id ): ?array {
		$cache_key = 'lookup_' . $osm_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'lookup',
				array(
					'osm_ids'        => $osm_id,
					'format'         => 'json',
					'addressdetails' => 1,
					'extratags'      => 1,
				)
			);

			if ( ! empty( $response ) && is_array( $response ) ) {
				$result = $this->normalize_location( $response[0] );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Lookup failed', array( 'osm_id' => $osm_id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Lookup multiple OSM IDs.
	 *
	 * @param array<int, string> $osm_ids OSM IDs with type prefixes.
	 * @return array<int, array<string, mixed>> Locations.
	 */
	public function lookup_multiple( array $osm_ids ): array {
		if ( empty( $osm_ids ) ) {
			return array();
		}

		$ids_string = implode( ',', $osm_ids );
		$cache_key = 'lookup_multi_' . md5( $ids_string );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'lookup',
				array(
					'osm_ids'        => $ids_string,
					'format'         => 'json',
					'addressdetails' => 1,
				)
			);

			$results = array();

			if ( is_array( $response ) ) {
				foreach ( $response as $item ) {
					$results[] = $this->normalize_location( $item );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Lookup multiple failed', array( 'osm_ids' => $osm_ids, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Search with bounding box.
	 *
	 * @param string $query         Search query.
	 * @param float  $min_lat       Minimum latitude.
	 * @param float  $max_lat       Maximum latitude.
	 * @param float  $min_lng       Minimum longitude.
	 * @param float  $max_lng       Maximum longitude.
	 * @param bool   $bounded       Whether to strictly limit to bounding box.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_bounded( string $query, float $min_lat, float $max_lat, float $min_lng, float $max_lng, bool $bounded = true ): array {
		$viewbox = "{$min_lng},{$min_lat},{$max_lng},{$max_lat}";

		$cache_key = 'bounded_' . md5( $query . $viewbox . ( $bounded ? '1' : '0' ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'search',
				array(
					'q'              => $query,
					'format'         => 'json',
					'viewbox'        => $viewbox,
					'bounded'        => $bounded ? 1 : 0,
					'addressdetails' => 1,
					'limit'          => 25,
				)
			);

			$results = array();

			if ( is_array( $response ) ) {
				foreach ( $response as $item ) {
					$results[] = $this->normalize_location( $item );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Bounded search failed', array( 'query' => $query, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Search by type (amenity, place, etc).
	 *
	 * @param string     $type  Place type (e.g., 'restaurant', 'hotel', 'city').
	 * @param string     $near  Near location.
	 * @param float|null $lat   Optional latitude for proximity.
	 * @param float|null $lng   Optional longitude for proximity.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_by_type( string $type, string $near = '', ?float $lat = null, ?float $lng = null ): array {
		$query = $type;

		if ( $near ) {
			$query .= ' near ' . $near;
		}

		$cache_key = 'type_' . md5( $query . ( $lat ?? '' ) . ( $lng ?? '' ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = array(
				'q'              => $query,
				'format'         => 'json',
				'addressdetails' => 1,
				'limit'          => 25,
			);

			$response = $this->get( 'search', $params );

			$results = array();

			if ( is_array( $response ) ) {
				foreach ( $response as $item ) {
					$normalized = $this->normalize_location( $item );

					// Calculate distance if coordinates provided.
					if ( $lat && $lng ) {
						$normalized['distance'] = $this->calculate_distance(
							$lat,
							$lng,
							(float) $normalized['latitude'],
							(float) $normalized['longitude']
						);
					}

					$results[] = $normalized;
				}

				// Sort by distance if calculated.
				if ( $lat && $lng ) {
					usort(
						$results,
						function ( $a, $b ) {
							return ( $a['distance'] ?? 0 ) <=> ( $b['distance'] ?? 0 );
						}
					);
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Search by type failed', array( 'type' => $type, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Normalize search result.
	 *
	 * @param array<string, mixed> $raw_result Raw result.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_result( array $raw_result ): array {
		return $this->normalize_location( $raw_result );
	}

	/**
	 * Normalize location data.
	 *
	 * @param array<string, mixed> $location Raw location data.
	 * @return array<string, mixed> Normalized location.
	 */
	private function normalize_location( array $location ): array {
		$address = $location['address'] ?? array();
		$extratags = $location['extratags'] ?? array();

		// Build display name from address parts.
		$address_parts = array();

		// Street-level.
		if ( ! empty( $address['house_number'] ) ) {
			$address_parts[] = $address['house_number'];
		}
		if ( ! empty( $address['road'] ) ) {
			$address_parts[] = $address['road'];
		}

		// Locality.
		$locality = $address['city']
			?? $address['town']
			?? $address['village']
			?? $address['municipality']
			?? $address['hamlet']
			?? '';

		// Region.
		$region = $address['state']
			?? $address['province']
			?? $address['region']
			?? '';

		// Get OSM type prefix.
		$osm_type = $location['osm_type'] ?? '';
		$osm_id   = $location['osm_id'] ?? '';
		$osm_full_id = '';

		if ( $osm_type && $osm_id ) {
			$prefix = strtoupper( substr( $osm_type, 0, 1 ) );
			$osm_full_id = $prefix . $osm_id;
		}

		// Parse bounding box.
		$bbox = null;
		if ( isset( $location['boundingbox'] ) && count( $location['boundingbox'] ) === 4 ) {
			$bbox = array(
				'min_lat' => (float) $location['boundingbox'][0],
				'max_lat' => (float) $location['boundingbox'][1],
				'min_lng' => (float) $location['boundingbox'][2],
				'max_lng' => (float) $location['boundingbox'][3],
			);
		}

		return array(
			'place_id'         => $location['place_id'] ?? 0,
			'osm_type'         => $osm_type,
			'osm_id'           => $osm_id,
			'osm_full_id'      => $osm_full_id,
			'latitude'         => (float) ( $location['lat'] ?? 0 ),
			'longitude'        => (float) ( $location['lon'] ?? 0 ),
			'display_name'     => $location['display_name'] ?? '',
			'name'             => $location['name'] ?? $location['namedetails']['name'] ?? '',
			'class'            => $location['class'] ?? '',
			'type'             => $location['type'] ?? '',
			'importance'       => $location['importance'] ?? 0,
			'place_rank'       => $location['place_rank'] ?? 0,
			'address'          => array(
				'house_number'   => $address['house_number'] ?? '',
				'road'           => $address['road'] ?? '',
				'neighbourhood'  => $address['neighbourhood'] ?? $address['suburb'] ?? '',
				'locality'       => $locality,
				'county'         => $address['county'] ?? '',
				'region'         => $region,
				'postcode'       => $address['postcode'] ?? '',
				'country'        => $address['country'] ?? '',
				'country_code'   => $address['country_code'] ?? '',
			),
			'formatted_address'=> implode(
				', ',
				array_filter(
					array(
						implode( ' ', $address_parts ),
						$locality,
						$region,
						$address['country'] ?? '',
					)
				)
			),
			'bounding_box'     => $bbox,
			'category'         => $location['category'] ?? '',
			'icon'             => $location['icon'] ?? '',
			'extra'            => array(
				'wikipedia'    => $extratags['wikipedia'] ?? '',
				'wikidata'     => $extratags['wikidata'] ?? '',
				'website'      => $extratags['website'] ?? '',
				'phone'        => $extratags['phone'] ?? '',
				'opening_hours'=> $extratags['opening_hours'] ?? '',
			),
			'source'           => 'nominatim',
		);
	}

	/**
	 * Calculate distance between two points (Haversine formula).
	 *
	 * @param float $lat1 Point 1 latitude.
	 * @param float $lng1 Point 1 longitude.
	 * @param float $lat2 Point 2 latitude.
	 * @param float $lng2 Point 2 longitude.
	 * @return float Distance in meters.
	 */
	private function calculate_distance( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$earth_radius = 6371000; // meters.

		$lat1_rad = deg2rad( $lat1 );
		$lat2_rad = deg2rad( $lat2 );
		$delta_lat = deg2rad( $lat2 - $lat1 );
		$delta_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $delta_lat / 2 ) * sin( $delta_lat / 2 ) +
			cos( $lat1_rad ) * cos( $lat2_rad ) *
			sin( $delta_lng / 2 ) * sin( $delta_lng / 2 );

		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return $earth_radius * $c;
	}

	/**
	 * Set custom server URL.
	 *
	 * @param string $server Server URL.
	 * @return void
	 */
	public function set_server( string $server ): void {
		$this->custom_server = $server;
		$this->base_url = rtrim( $server, '/' ) . '/';
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://nominatim.org/release-docs/latest/api/Overview/';
	}
}
