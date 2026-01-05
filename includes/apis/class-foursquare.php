<?php
/**
 * Foursquare API Integration
 *
 * Provides venue search and checkin data from Foursquare (Places API).
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
 * Foursquare API class.
 *
 * @since 1.0.0
 */
class Foursquare extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'foursquare';

	/**
	 * Base URL (Places API v3).
	 *
	 * @var string
	 */
	protected string $base_url = 'https://api.foursquare.com/v3/';

	/**
	 * Rate limit.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.1;

	/**
	 * Cache duration: 1 week for venue data.
	 *
	 * @var int
	 */
	protected int $cache_duration = WEEK_IN_SECONDS;

	/**
	 * API key.
	 *
	 * @var string|null
	 */
	private ?string $api_key = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$credentials   = get_option( 'reactions_indieweb_api_credentials', array() );
		$fs_creds      = $credentials['foursquare'] ?? array();
		$this->api_key = $fs_creds['api_key'] ?? '';
	}

	/**
	 * Get default headers.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		return array(
			'Accept'        => 'application/json',
			'Authorization' => $this->api_key ?? '',
		);
	}

	/**
	 * Make API request.
	 *
	 * @param string               $endpoint Endpoint.
	 * @param array<string, mixed> $params   Parameters.
	 * @return array<string, mixed> Response.
	 * @throws \Exception On error.
	 */
	private function api_get( string $endpoint, array $params = array() ): array {
		$url = $this->base_url . ltrim( $endpoint, '/' );

		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $this->get_default_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			$message = $data['message'] ?? 'API error';
			throw new \Exception( $message, $code );
		}

		return $data ?? array();
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		if ( ! $this->api_key ) {
			return false;
		}

		try {
			$this->api_get( 'places/search', array( 'query' => 'coffee', 'limit' => 1 ) );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Search for places/venues.
	 *
	 * @param string      $query    Search query.
	 * @param string|null $near     Location (city, address, etc).
	 * @param float|null  $lat      Latitude.
	 * @param float|null  $lng      Longitude.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		$near = $args[0] ?? null;
		$lat  = $args[1] ?? null;
		$lng  = $args[2] ?? null;

		$cache_key = 'search_' . md5( $query . ( $near ?? '' ) . ( $lat ?? '' ) . ( $lng ?? '' ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = array(
				'query' => $query,
				'limit' => 25,
			);

			if ( $lat && $lng ) {
				$params['ll'] = "{$lat},{$lng}";
			} elseif ( $near ) {
				$params['near'] = $near;
			}

			$response = $this->api_get( 'places/search', $params );

			$results = array();

			if ( isset( $response['results'] ) && is_array( $response['results'] ) ) {
				foreach ( $response['results'] as $place ) {
					$results[] = $this->normalize_result( $place );
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
	 * Search nearby places.
	 *
	 * @param float  $lat      Latitude.
	 * @param float  $lng      Longitude.
	 * @param string $query    Optional search query.
	 * @param int    $radius   Search radius in meters.
	 * @param int    $limit    Max results.
	 * @return array<int, array<string, mixed>> Nearby places.
	 */
	public function search_nearby( float $lat, float $lng, string $query = '', int $radius = 1000, int $limit = 25 ): array {
		$cache_key = 'nearby_' . md5( "{$lat},{$lng},{$query},{$radius}" );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = array(
				'll'     => "{$lat},{$lng}",
				'radius' => min( $radius, 100000 ),
				'limit'  => min( $limit, 50 ),
			);

			if ( $query ) {
				$params['query'] = $query;
			}

			$response = $this->api_get( 'places/nearby', $params );

			$results = array();

			if ( isset( $response['results'] ) ) {
				foreach ( $response['results'] as $place ) {
					$results[] = $this->normalize_place( $place );
				}
			}

			$this->set_cache( $cache_key, $results, HOUR_IN_SECONDS );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Search nearby failed', array( 'lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get place by Foursquare ID.
	 *
	 * @param string $id Foursquare place ID (fsq_id).
	 * @return array<string, mixed>|null Place data.
	 */
	public function get_by_id( string $id ): ?array {
		return $this->get_place( $id );
	}

	/**
	 * Get place details.
	 *
	 * @param string $fsq_id Foursquare place ID.
	 * @return array<string, mixed>|null Place data.
	 */
	public function get_place( string $fsq_id ): ?array {
		$cache_key = 'place_' . $fsq_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get(
				"places/{$fsq_id}",
				array( 'fields' => 'fsq_id,name,location,categories,chains,closed_bucket,date_closed,description,email,features,geocodes,hours,hours_popular,link,menu,photos,popularity,price,rating,related_places,social_media,stats,tastes,tel,timezone,tips,verified,website' )
			);

			$result = $this->normalize_place( $response, true );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get place failed', array( 'fsq_id' => $fsq_id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get place photos.
	 *
	 * @param string $fsq_id Foursquare place ID.
	 * @param int    $limit  Max photos.
	 * @return array<int, array<string, mixed>> Photos.
	 */
	public function get_photos( string $fsq_id, int $limit = 10 ): array {
		$cache_key = "photos_{$fsq_id}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get(
				"places/{$fsq_id}/photos",
				array( 'limit' => min( $limit, 50 ) )
			);

			$photos = array();

			if ( is_array( $response ) ) {
				foreach ( $response as $photo ) {
					$photos[] = $this->normalize_photo( $photo );
				}
			}

			$this->set_cache( $cache_key, $photos );

			return $photos;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get photos failed', array( 'fsq_id' => $fsq_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get place tips.
	 *
	 * @param string $fsq_id Foursquare place ID.
	 * @param int    $limit  Max tips.
	 * @return array<int, array<string, mixed>> Tips.
	 */
	public function get_tips( string $fsq_id, int $limit = 10 ): array {
		$cache_key = "tips_{$fsq_id}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get(
				"places/{$fsq_id}/tips",
				array( 'limit' => min( $limit, 50 ) )
			);

			$tips = array();

			if ( is_array( $response ) ) {
				foreach ( $response as $tip ) {
					$tips[] = array(
						'id'         => $tip['id'] ?? '',
						'text'       => $tip['text'] ?? '',
						'created_at' => $tip['created_at'] ?? '',
						'agree_count'=> $tip['agree_count'] ?? 0,
					);
				}
			}

			$this->set_cache( $cache_key, $tips );

			return $tips;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get tips failed', array( 'fsq_id' => $fsq_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Autocomplete places.
	 *
	 * @param string     $query Search query.
	 * @param float|null $lat   Latitude.
	 * @param float|null $lng   Longitude.
	 * @return array<int, array<string, mixed>> Suggestions.
	 */
	public function autocomplete( string $query, ?float $lat = null, ?float $lng = null ): array {
		try {
			$params = array(
				'query' => $query,
				'limit' => 10,
			);

			if ( $lat && $lng ) {
				$params['ll'] = "{$lat},{$lng}";
			}

			$response = $this->api_get( 'autocomplete', $params );

			$results = array();

			if ( isset( $response['results'] ) ) {
				foreach ( $response['results'] as $item ) {
					if ( 'place' === ( $item['type'] ?? '' ) && isset( $item['place'] ) ) {
						$results[] = $this->normalize_place( $item['place'] );
					}
				}
			}

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Autocomplete failed', array( 'query' => $query, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Match a place by name and location.
	 *
	 * @param string $name    Place name.
	 * @param string $address Address.
	 * @param string $city    City.
	 * @param string $state   State/region.
	 * @param string $country Country.
	 * @return array<string, mixed>|null Matched place.
	 */
	public function match_place( string $name, string $address = '', string $city = '', string $state = '', string $country = '' ): ?array {
		try {
			$params = array( 'name' => $name );

			if ( $address ) {
				$params['address'] = $address;
			}

			if ( $city ) {
				$params['city'] = $city;
			}

			if ( $state ) {
				$params['state'] = $state;
			}

			if ( $country ) {
				$params['country'] = $country;
			}

			$response = $this->api_get( 'places/match', $params );

			if ( isset( $response['place'] ) ) {
				return $this->normalize_place( $response['place'], true );
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Match place failed', array( 'name' => $name, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Search by category.
	 *
	 * @param array<int, string> $categories Category IDs.
	 * @param float              $lat        Latitude.
	 * @param float              $lng        Longitude.
	 * @param int                $radius     Radius in meters.
	 * @return array<int, array<string, mixed>> Places.
	 */
	public function search_by_category( array $categories, float $lat, float $lng, int $radius = 1000 ): array {
		$cache_key = 'category_' . md5( implode( ',', $categories ) . "{$lat},{$lng},{$radius}" );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get(
				'places/search',
				array(
					'll'         => "{$lat},{$lng}",
					'categories' => implode( ',', $categories ),
					'radius'     => min( $radius, 100000 ),
					'limit'      => 50,
				)
			);

			$results = array();

			if ( isset( $response['results'] ) ) {
				foreach ( $response['results'] as $place ) {
					$results[] = $this->normalize_place( $place );
				}
			}

			$this->set_cache( $cache_key, $results, HOUR_IN_SECONDS );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Search by category failed', array( 'categories' => $categories, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get popular categories.
	 *
	 * @return array<int, array<string, mixed>> Categories.
	 */
	public function get_categories(): array {
		// Common Foursquare categories - could be expanded.
		return array(
			array( 'id' => '13000', 'name' => 'Dining & Drinking', 'icon' => 'food' ),
			array( 'id' => '13065', 'name' => 'Restaurant', 'icon' => 'food' ),
			array( 'id' => '13003', 'name' => 'Bar', 'icon' => 'nightlife' ),
			array( 'id' => '13032', 'name' => 'Cafe', 'icon' => 'coffee' ),
			array( 'id' => '13035', 'name' => 'Coffee Shop', 'icon' => 'coffee' ),
			array( 'id' => '17000', 'name' => 'Retail', 'icon' => 'shops' ),
			array( 'id' => '18000', 'name' => 'Sports & Recreation', 'icon' => 'outdoors' ),
			array( 'id' => '19000', 'name' => 'Travel & Transportation', 'icon' => 'travel' ),
			array( 'id' => '10000', 'name' => 'Arts & Entertainment', 'icon' => 'arts' ),
			array( 'id' => '12000', 'name' => 'Community & Government', 'icon' => 'building' ),
			array( 'id' => '15000', 'name' => 'Health & Medicine', 'icon' => 'medical' ),
			array( 'id' => '11000', 'name' => 'Business & Professional', 'icon' => 'office' ),
		);
	}

	/**
	 * Normalize search result.
	 *
	 * @param array<string, mixed> $raw_result Raw result.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_result( array $raw_result ): array {
		return $this->normalize_place( $raw_result );
	}

	/**
	 * Normalize place data.
	 *
	 * @param array<string, mixed> $place    Place data.
	 * @param bool                 $detailed Whether this is detailed data.
	 * @return array<string, mixed> Normalized place.
	 */
	private function normalize_place( array $place, bool $detailed = false ): array {
		$location = $place['location'] ?? array();
		$geocodes = $place['geocodes'] ?? array();
		$main_geocode = $geocodes['main'] ?? array();

		// Get primary category.
		$categories = $place['categories'] ?? array();
		$primary_category = ! empty( $categories ) ? $categories[0] : array();

		// Build address string.
		$address_parts = array();
		if ( ! empty( $location['address'] ) ) {
			$address_parts[] = $location['address'];
		}
		if ( ! empty( $location['locality'] ) ) {
			$address_parts[] = $location['locality'];
		}
		if ( ! empty( $location['region'] ) ) {
			$address_parts[] = $location['region'];
		}
		if ( ! empty( $location['country'] ) ) {
			$address_parts[] = $location['country'];
		}

		$result = array(
			'id'             => $place['fsq_id'] ?? '',
			'fsq_id'         => $place['fsq_id'] ?? '',
			'name'           => $place['name'] ?? '',
			'address'        => $location['address'] ?? '',
			'address_extended' => $location['address_extended'] ?? '',
			'cross_street'   => $location['cross_street'] ?? '',
			'locality'       => $location['locality'] ?? '',
			'region'         => $location['region'] ?? '',
			'postcode'       => $location['postcode'] ?? '',
			'country'        => $location['country'] ?? '',
			'formatted_address' => $location['formatted_address'] ?? implode( ', ', $address_parts ),
			'latitude'       => $main_geocode['latitude'] ?? null,
			'longitude'      => $main_geocode['longitude'] ?? null,
			'distance'       => $place['distance'] ?? null,
			'category'       => $primary_category['name'] ?? '',
			'category_id'    => $primary_category['id'] ?? '',
			'category_icon'  => $this->get_category_icon( $primary_category ),
			'categories'     => array_map(
				function ( $cat ) {
					return array(
						'id'   => $cat['id'] ?? '',
						'name' => $cat['name'] ?? '',
						'icon' => $this->get_category_icon( $cat ),
					);
				},
				$categories
			),
			'timezone'       => $place['timezone'] ?? '',
			'type'           => 'venue',
			'source'         => 'foursquare',
		);

		if ( $detailed ) {
			$result['description'] = $place['description'] ?? '';
			$result['website']     = $place['website'] ?? '';
			$result['tel']         = $place['tel'] ?? '';
			$result['email']       = $place['email'] ?? '';
			$result['rating']      = $place['rating'] ?? null;
			$result['price']       = $place['price'] ?? null;
			$result['popularity']  = $place['popularity'] ?? null;
			$result['verified']    = $place['verified'] ?? false;
			$result['closed']      = $place['closed_bucket'] ?? 'VeryLikelyOpen';

			// Hours.
			if ( isset( $place['hours'] ) ) {
				$result['hours'] = $place['hours'];
			}

			// Social media.
			if ( isset( $place['social_media'] ) ) {
				$result['social_media'] = $place['social_media'];
			}

			// Photos.
			$result['photos'] = array();
			if ( isset( $place['photos'] ) ) {
				foreach ( $place['photos'] as $photo ) {
					$result['photos'][] = $this->normalize_photo( $photo );
				}
			}

			// Features.
			if ( isset( $place['features'] ) ) {
				$result['features'] = $place['features'];
			}

			// Stats.
			if ( isset( $place['stats'] ) ) {
				$result['stats'] = array(
					'total_photos' => $place['stats']['total_photos'] ?? 0,
					'total_tips'   => $place['stats']['total_tips'] ?? 0,
					'total_ratings'=> $place['stats']['total_ratings'] ?? 0,
				);
			}

			// Tastes/tags.
			if ( isset( $place['tastes'] ) ) {
				$result['tastes'] = $place['tastes'];
			}

			// Related places.
			if ( isset( $place['related_places'] ) ) {
				$result['related_places'] = $place['related_places'];
			}

			// Chains.
			if ( isset( $place['chains'] ) ) {
				$result['chains'] = array_map(
					function ( $chain ) {
						return array(
							'id'   => $chain['id'] ?? '',
							'name' => $chain['name'] ?? '',
						);
					},
					$place['chains']
				);
			}
		}

		return $result;
	}

	/**
	 * Normalize photo data.
	 *
	 * @param array<string, mixed> $photo Photo data.
	 * @return array<string, mixed> Normalized photo.
	 */
	private function normalize_photo( array $photo ): array {
		$prefix = $photo['prefix'] ?? '';
		$suffix = $photo['suffix'] ?? '';

		return array(
			'id'         => $photo['id'] ?? '',
			'created_at' => $photo['created_at'] ?? '',
			'prefix'     => $prefix,
			'suffix'     => $suffix,
			'width'      => $photo['width'] ?? 0,
			'height'     => $photo['height'] ?? 0,
			'url_small'  => $prefix . '100x100' . $suffix,
			'url_medium' => $prefix . '300x300' . $suffix,
			'url_large'  => $prefix . '500x500' . $suffix,
			'url_original' => $prefix . 'original' . $suffix,
		);
	}

	/**
	 * Get category icon URL.
	 *
	 * @param array<string, mixed> $category Category data.
	 * @param int                  $size     Icon size.
	 * @return string|null Icon URL.
	 */
	private function get_category_icon( array $category, int $size = 64 ): ?string {
		if ( ! isset( $category['icon'] ) ) {
			return null;
		}

		$icon = $category['icon'];
		$prefix = $icon['prefix'] ?? '';
		$suffix = $icon['suffix'] ?? '';

		if ( $prefix && $suffix ) {
			return $prefix . $size . $suffix;
		}

		return null;
	}

	/**
	 * Set API key.
	 *
	 * @param string $key API key.
	 * @return void
	 */
	public function set_api_key( string $key ): void {
		$this->api_key = $key;
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://location.foursquare.com/developer/reference/places-api-overview';
	}
}
