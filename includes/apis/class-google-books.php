<?php
/**
 * Google Books API Integration
 *
 * Provides book metadata from Google Books API.
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
 * Google Books API class.
 *
 * @since 1.0.0
 */
class GoogleBooks extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'googlebooks';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://www.googleapis.com/books/v1/';

	/**
	 * Rate limit.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.1;

	/**
	 * Cache duration: 1 week.
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
		$gb_creds      = $credentials['google_books'] ?? array();
		$this->api_key = $gb_creds['api_key'] ?? '';
	}

	/**
	 * Build URL with API key.
	 *
	 * @param string               $endpoint Endpoint.
	 * @param array<string, mixed> $params   Parameters.
	 * @return string Full URL.
	 */
	private function build_url( string $endpoint, array $params = array() ): string {
		if ( $this->api_key ) {
			$params['key'] = $this->api_key;
		}

		$url = $this->base_url . ltrim( $endpoint, '/' );

		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		return $url;
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
		$url = $this->build_url( $endpoint, $params );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			$message = $data['error']['message'] ?? 'API error';
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
		try {
			$this->api_get( 'volumes', array( 'q' => 'test', 'maxResults' => 1 ) );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Search for books.
	 *
	 * @param string      $query  Search query.
	 * @param string|null $filter Optional filter: partial, full, free-ebooks, paid-ebooks, ebooks.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		$filter = $args[0] ?? null;

		$cache_key = 'search_' . md5( $query . ( $filter ?? '' ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = array(
				'q'          => $query,
				'maxResults' => 40,
			);

			if ( $filter ) {
				$params['filter'] = $filter;
			}

			$response = $this->api_get( 'volumes', $params );

			$results = array();

			if ( isset( $response['items'] ) && is_array( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
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
	 * Search by title.
	 *
	 * @param string $title Book title.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_by_title( string $title ): array {
		return $this->search( 'intitle:' . $title );
	}

	/**
	 * Search by author.
	 *
	 * @param string $author Author name.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_by_author( string $author ): array {
		return $this->search( 'inauthor:' . $author );
	}

	/**
	 * Search by subject.
	 *
	 * @param string $subject Subject/category.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_by_subject( string $subject ): array {
		return $this->search( 'subject:' . $subject );
	}

	/**
	 * Search by publisher.
	 *
	 * @param string $publisher Publisher name.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_by_publisher( string $publisher ): array {
		return $this->search( 'inpublisher:' . $publisher );
	}

	/**
	 * Get book by Google Books volume ID.
	 *
	 * @param string $id Volume ID.
	 * @return array<string, mixed>|null Book data.
	 */
	public function get_by_id( string $id ): ?array {
		return $this->get_volume( $id );
	}

	/**
	 * Get volume details.
	 *
	 * @param string $volume_id Google Books volume ID.
	 * @return array<string, mixed>|null Volume data.
	 */
	public function get_volume( string $volume_id ): ?array {
		$cache_key = 'volume_' . $volume_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get( "volumes/{$volume_id}" );

			$result = $this->normalize_volume( $response, true );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get volume failed', array( 'id' => $volume_id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get book by ISBN.
	 *
	 * @param string $isbn ISBN-10 or ISBN-13.
	 * @return array<string, mixed>|null Book data.
	 */
	public function get_by_isbn( string $isbn ): ?array {
		$isbn = str_replace( array( '-', ' ' ), '', $isbn );

		$cache_key = 'isbn_' . $isbn;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get( 'volumes', array( 'q' => 'isbn:' . $isbn ) );

			if ( isset( $response['items'][0] ) ) {
				$result = $this->normalize_volume( $response['items'][0], true );
				$result['isbn'] = $isbn;

				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get by ISBN failed', array( 'isbn' => $isbn, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get new releases.
	 *
	 * @param string $category Category.
	 * @param int    $limit    Max books.
	 * @return array<int, array<string, mixed>> New releases.
	 */
	public function get_new_releases( string $category = 'fiction', int $limit = 25 ): array {
		$cache_key = "new_releases_{$category}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get(
				'volumes',
				array(
					'q'          => 'subject:' . $category,
					'orderBy'    => 'newest',
					'maxResults' => min( $limit, 40 ),
				)
			);

			$results = array();

			if ( isset( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
					$results[] = $this->normalize_volume( $item );
				}
			}

			$this->set_cache( $cache_key, $results, DAY_IN_SECONDS );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get new releases failed', array( 'category' => $category, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Advanced search with multiple parameters.
	 *
	 * @param array<string, mixed> $params Search parameters.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function advanced_search( array $params ): array {
		$query_parts = array();

		if ( ! empty( $params['title'] ) ) {
			$query_parts[] = 'intitle:' . $params['title'];
		}

		if ( ! empty( $params['author'] ) ) {
			$query_parts[] = 'inauthor:' . $params['author'];
		}

		if ( ! empty( $params['publisher'] ) ) {
			$query_parts[] = 'inpublisher:' . $params['publisher'];
		}

		if ( ! empty( $params['subject'] ) ) {
			$query_parts[] = 'subject:' . $params['subject'];
		}

		if ( ! empty( $params['isbn'] ) ) {
			$query_parts[] = 'isbn:' . $params['isbn'];
		}

		if ( ! empty( $params['query'] ) ) {
			$query_parts[] = $params['query'];
		}

		if ( empty( $query_parts ) ) {
			return array();
		}

		$query = implode( '+', $query_parts );

		$cache_key = 'adv_search_' . md5( $query );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$api_params = array(
				'q'          => $query,
				'maxResults' => min( $params['limit'] ?? 25, 40 ),
			);

			if ( ! empty( $params['orderBy'] ) ) {
				$api_params['orderBy'] = $params['orderBy'];
			}

			if ( ! empty( $params['filter'] ) ) {
				$api_params['filter'] = $params['filter'];
			}

			if ( ! empty( $params['langRestrict'] ) ) {
				$api_params['langRestrict'] = $params['langRestrict'];
			}

			if ( ! empty( $params['printType'] ) ) {
				$api_params['printType'] = $params['printType'];
			}

			$response = $this->api_get( 'volumes', $api_params );

			$results = array();

			if ( isset( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
					$results[] = $this->normalize_volume( $item );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Advanced search failed', array( 'params' => $params, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get bookshelves for authenticated user.
	 *
	 * @param string $user_id User ID or 'me'.
	 * @return array<int, array<string, mixed>> Bookshelves.
	 */
	public function get_bookshelves( string $user_id = 'me' ): array {
		try {
			$response = $this->api_get( "users/{$user_id}/bookshelves" );

			$shelves = array();

			if ( isset( $response['items'] ) ) {
				foreach ( $response['items'] as $shelf ) {
					$shelves[] = array(
						'id'          => $shelf['id'] ?? 0,
						'title'       => $shelf['title'] ?? '',
						'description' => $shelf['description'] ?? '',
						'access'      => $shelf['access'] ?? 'PRIVATE',
						'volume_count'=> $shelf['volumeCount'] ?? 0,
						'updated'     => $shelf['updated'] ?? '',
					);
				}
			}

			return $shelves;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get bookshelves failed', array( 'user_id' => $user_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get volumes in a bookshelf.
	 *
	 * @param string $user_id   User ID or 'me'.
	 * @param int    $shelf_id  Bookshelf ID.
	 * @param int    $max       Max volumes.
	 * @return array<int, array<string, mixed>> Volumes.
	 */
	public function get_bookshelf_volumes( string $user_id, int $shelf_id, int $max = 40 ): array {
		try {
			$response = $this->api_get(
				"users/{$user_id}/bookshelves/{$shelf_id}/volumes",
				array( 'maxResults' => min( $max, 40 ) )
			);

			$volumes = array();

			if ( isset( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
					$volumes[] = $this->normalize_volume( $item );
				}
			}

			return $volumes;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get bookshelf volumes failed', array( 'shelf_id' => $shelf_id, 'error' => $e->getMessage() ) );
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
		return $this->normalize_volume( $raw_result );
	}

	/**
	 * Normalize volume data.
	 *
	 * @param array<string, mixed> $volume   Volume data.
	 * @param bool                 $detailed Whether this is detailed data.
	 * @return array<string, mixed> Normalized volume.
	 */
	private function normalize_volume( array $volume, bool $detailed = false ): array {
		$info = $volume['volumeInfo'] ?? array();

		// Get best image.
		$cover = null;
		if ( isset( $info['imageLinks'] ) ) {
			$cover = $info['imageLinks']['large']
				?? $info['imageLinks']['medium']
				?? $info['imageLinks']['thumbnail']
				?? $info['imageLinks']['smallThumbnail']
				?? null;

			// Convert to HTTPS.
			if ( $cover ) {
				$cover = str_replace( 'http://', 'https://', $cover );
			}
		}

		// Get ISBNs.
		$isbn_10 = '';
		$isbn_13 = '';
		if ( isset( $info['industryIdentifiers'] ) ) {
			foreach ( $info['industryIdentifiers'] as $identifier ) {
				if ( 'ISBN_10' === ( $identifier['type'] ?? '' ) ) {
					$isbn_10 = $identifier['identifier'];
				} elseif ( 'ISBN_13' === ( $identifier['type'] ?? '' ) ) {
					$isbn_13 = $identifier['identifier'];
				}
			}
		}

		$result = array(
			'id'             => $volume['id'] ?? '',
			'google_id'      => $volume['id'] ?? '',
			'title'          => $info['title'] ?? '',
			'subtitle'       => $info['subtitle'] ?? '',
			'authors'        => $info['authors'] ?? array(),
			'publisher'      => $info['publisher'] ?? '',
			'published_date' => $info['publishedDate'] ?? '',
			'description'    => $info['description'] ?? '',
			'isbn_10'        => $isbn_10,
			'isbn_13'        => $isbn_13,
			'isbn'           => $isbn_13 ?: $isbn_10,
			'page_count'     => $info['pageCount'] ?? null,
			'categories'     => $info['categories'] ?? array(),
			'average_rating' => $info['averageRating'] ?? null,
			'ratings_count'  => $info['ratingsCount'] ?? 0,
			'language'       => $info['language'] ?? '',
			'cover'          => $cover,
			'preview_link'   => $info['previewLink'] ?? '',
			'info_link'      => $info['infoLink'] ?? '',
			'type'           => 'book',
			'source'         => 'googlebooks',
		);

		if ( $detailed ) {
			// Additional detailed info.
			$result['maturity_rating']   = $info['maturityRating'] ?? '';
			$result['content_version']   = $info['contentVersion'] ?? '';
			$result['print_type']        = $info['printType'] ?? '';
			$result['dimensions']        = $info['dimensions'] ?? array();

			// Sale info.
			if ( isset( $volume['saleInfo'] ) ) {
				$sale = $volume['saleInfo'];
				$result['is_ebook']      = ( 'EBOOK' === ( $sale['saleability'] ?? '' ) );
				$result['for_sale']      = ( 'FOR_SALE' === ( $sale['saleability'] ?? '' ) );
				$result['list_price']    = $sale['listPrice'] ?? null;
				$result['retail_price']  = $sale['retailPrice'] ?? null;
				$result['buy_link']      = $sale['buyLink'] ?? '';
			}

			// Access info.
			if ( isset( $volume['accessInfo'] ) ) {
				$access = $volume['accessInfo'];
				$result['embeddable']    = $access['embeddable'] ?? false;
				$result['public_domain'] = $access['publicDomain'] ?? false;
				$result['viewability']   = $access['viewability'] ?? '';
				$result['epub_available']= $access['epub']['isAvailable'] ?? false;
				$result['pdf_available'] = $access['pdf']['isAvailable'] ?? false;
				$result['web_reader']    = $access['webReaderLink'] ?? '';
			}
		}

		return $result;
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
		return 'https://developers.google.com/books/docs/v1/using';
	}
}
