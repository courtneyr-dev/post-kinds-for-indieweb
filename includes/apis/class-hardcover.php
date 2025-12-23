<?php
/**
 * Hardcover API Integration
 *
 * Provides book tracking and reading history from Hardcover.app.
 * Uses GraphQL API.
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
 * Hardcover API class.
 *
 * @since 1.0.0
 */
class Hardcover extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'hardcover';

	/**
	 * Base URL (GraphQL endpoint).
	 *
	 * @var string
	 */
	protected string $base_url = 'https://api.hardcover.app/v1/graphql';

	/**
	 * Rate limit.
	 *
	 * @var float
	 */
	protected float $rate_limit = 1.0;

	/**
	 * Cache duration: 1 hour.
	 *
	 * @var int
	 */
	protected int $cache_duration = HOUR_IN_SECONDS;

	/**
	 * API token.
	 *
	 * @var string|null
	 */
	private ?string $api_token = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->api_token = $this->get_option( 'hardcover_api_token' );
	}

	/**
	 * Get default headers.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( $this->api_token ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_token;
		}

		return $headers;
	}

	/**
	 * Execute GraphQL query.
	 *
	 * @param string               $query     GraphQL query.
	 * @param array<string, mixed> $variables Variables.
	 * @return array<string, mixed> Response data.
	 * @throws \Exception On error.
	 */
	private function graphql( string $query, array $variables = array() ): array {
		$response = wp_remote_post(
			$this->base_url,
			array(
				'timeout' => 30,
				'headers' => $this->get_default_headers(),
				'body'    => wp_json_encode(
					array(
						'query'     => $query,
						'variables' => $variables,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			$message = $data['errors'][0]['message'] ?? 'API error';
			throw new \Exception( $message, $code );
		}

		if ( isset( $data['errors'] ) ) {
			throw new \Exception( $data['errors'][0]['message'] ?? 'GraphQL error' );
		}

		return $data['data'] ?? array();
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		if ( ! $this->api_token ) {
			return false;
		}

		try {
			$query = 'query { me { id username } }';
			$this->graphql( $query );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Check if authenticated.
	 *
	 * @return bool
	 */
	public function is_authenticated(): bool {
		return ! empty( $this->api_token );
	}

	/**
	 * Get current user.
	 *
	 * @return array<string, mixed>|null User data.
	 */
	public function get_me(): ?array {
		if ( ! $this->is_authenticated() ) {
			return null;
		}

		$cache_key = 'me';
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$query = '
				query {
					me {
						id
						username
						name
						bio
						image
						books_count
						followers_count
						following_count
						created_at
					}
				}
			';

			$response = $this->graphql( $query );

			if ( isset( $response['me'] ) ) {
				$user = $this->normalize_user( $response['me'] );
				$this->set_cache( $cache_key, $user );
				return $user;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get me failed', array( 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Search for books.
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
			$gql = '
				query SearchBooks($query: String!) {
					search_books(query: $query, limit: 25) {
						id
						title
						slug
						description
						release_date
						pages
						image {
							url
						}
						contributions {
							author {
								id
								name
							}
						}
						editions_count
						ratings_count
						ratings_average
					}
				}
			';

			$response = $this->graphql( $gql, array( 'query' => $query ) );

			$results = array();

			if ( isset( $response['search_books'] ) ) {
				foreach ( $response['search_books'] as $book ) {
					$results[] = $this->normalize_result( $book );
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
	 * Get book by ID or slug.
	 *
	 * @param string $id Hardcover book ID or slug.
	 * @return array<string, mixed>|null Book data.
	 */
	public function get_by_id( string $id ): ?array {
		return $this->get_book( $id );
	}

	/**
	 * Get book details.
	 *
	 * @param string $id_or_slug Book ID or slug.
	 * @return array<string, mixed>|null Book data.
	 */
	public function get_book( string $id_or_slug ): ?array {
		$cache_key = 'book_' . $id_or_slug;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$gql = '
				query GetBook($id: ID, $slug: String) {
					book(id: $id, slug: $slug) {
						id
						title
						slug
						description
						release_date
						pages
						image {
							url
						}
						contributions {
							author {
								id
								name
								slug
							}
							role
						}
						editions {
							id
							title
							isbn_10
							isbn_13
							pages
							format
							publisher
							release_date
							image {
								url
							}
						}
						series {
							id
							name
							slug
						}
						genres {
							genre {
								id
								name
							}
						}
						ratings_count
						ratings_average
						reviews_count
					}
				}
			';

			$variables = array();
			if ( is_numeric( $id_or_slug ) ) {
				$variables['id'] = $id_or_slug;
			} else {
				$variables['slug'] = $id_or_slug;
			}

			$response = $this->graphql( $gql, $variables );

			if ( isset( $response['book'] ) ) {
				$result = $this->normalize_book( $response['book'], true );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get book failed', array( 'id' => $id_or_slug, 'error' => $e->getMessage() ) );
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
			$gql = '
				query GetBookByISBN($isbn: String!) {
					edition(isbn: $isbn) {
						id
						title
						isbn_10
						isbn_13
						pages
						format
						publisher
						release_date
						image {
							url
						}
						book {
							id
							title
							slug
							description
							contributions {
								author {
									id
									name
								}
							}
							ratings_count
							ratings_average
						}
					}
				}
			';

			$response = $this->graphql( $gql, array( 'isbn' => $isbn ) );

			if ( isset( $response['edition'] ) ) {
				$edition = $response['edition'];
				$book = $edition['book'] ?? array();

				$result = $this->normalize_book( $book );
				$result['edition'] = $this->normalize_edition( $edition );
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
	 * Get user's reading list.
	 *
	 * @param string $status Status: reading, want_to_read, read, did_not_finish.
	 * @param int    $limit  Max books.
	 * @return array<int, array<string, mixed>> Books.
	 */
	public function get_reading_list( string $status = 'reading', int $limit = 50 ): array {
		if ( ! $this->is_authenticated() ) {
			return array();
		}

		$cache_key = "reading_list_{$status}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$gql = '
				query GetReadingList($status: String!, $limit: Int!) {
					me {
						user_books(status: $status, limit: $limit) {
							id
							status
							rating
							started_at
							finished_at
							created_at
							updated_at
							progress
							book {
								id
								title
								slug
								description
								pages
								image {
									url
								}
								contributions {
									author {
										id
										name
									}
								}
							}
							edition {
								id
								title
								isbn_13
								pages
								format
							}
						}
					}
				}
			';

			$response = $this->graphql( $gql, array( 'status' => $status, 'limit' => $limit ) );

			$books = array();

			if ( isset( $response['me']['user_books'] ) ) {
				foreach ( $response['me']['user_books'] as $user_book ) {
					$book = $this->normalize_book( $user_book['book'] ?? array() );
					$book['user_status']  = $user_book['status'] ?? '';
					$book['user_rating']  = $user_book['rating'] ?? null;
					$book['started_at']   = $user_book['started_at'] ?? '';
					$book['finished_at']  = $user_book['finished_at'] ?? '';
					$book['progress']     = $user_book['progress'] ?? 0;

					if ( isset( $user_book['edition'] ) ) {
						$book['edition'] = $this->normalize_edition( $user_book['edition'] );
					}

					$books[] = $book;
				}
			}

			$this->set_cache( $cache_key, $books, 300 ); // 5 min cache.

			return $books;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get reading list failed', array( 'status' => $status, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get user's currently reading books.
	 *
	 * @return array<int, array<string, mixed>> Currently reading books.
	 */
	public function get_currently_reading(): array {
		return $this->get_reading_list( 'reading', 25 );
	}

	/**
	 * Get user's want to read list.
	 *
	 * @param int $limit Max books.
	 * @return array<int, array<string, mixed>> Want to read books.
	 */
	public function get_want_to_read( int $limit = 50 ): array {
		return $this->get_reading_list( 'want_to_read', $limit );
	}

	/**
	 * Get user's read books.
	 *
	 * @param int $limit Max books.
	 * @return array<int, array<string, mixed>> Read books.
	 */
	public function get_read_books( int $limit = 50 ): array {
		return $this->get_reading_list( 'read', $limit );
	}

	/**
	 * Get user's reading activity/history.
	 *
	 * @param int $limit Max entries.
	 * @return array<int, array<string, mixed>> Activity entries.
	 */
	public function get_reading_activity( int $limit = 50 ): array {
		if ( ! $this->is_authenticated() ) {
			return array();
		}

		try {
			$gql = '
				query GetActivity($limit: Int!) {
					me {
						activities(limit: $limit) {
							id
							action
							created_at
							book {
								id
								title
								slug
								image {
									url
								}
								contributions {
									author {
										name
									}
								}
							}
							edition {
								id
								isbn_13
							}
							progress
							rating
						}
					}
				}
			';

			$response = $this->graphql( $gql, array( 'limit' => $limit ) );

			$activities = array();

			if ( isset( $response['me']['activities'] ) ) {
				foreach ( $response['me']['activities'] as $activity ) {
					$activities[] = array(
						'id'         => $activity['id'] ?? 0,
						'action'     => $activity['action'] ?? '',
						'created_at' => $activity['created_at'] ?? '',
						'book'       => isset( $activity['book'] ) ? $this->normalize_book( $activity['book'] ) : null,
						'edition'    => isset( $activity['edition'] ) ? $this->normalize_edition( $activity['edition'] ) : null,
						'progress'   => $activity['progress'] ?? null,
						'rating'     => $activity['rating'] ?? null,
					);
				}
			}

			return $activities;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get reading activity failed', array( 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Update reading progress.
	 *
	 * @param int $book_id  Hardcover book ID.
	 * @param int $progress Progress percentage or page number.
	 * @param string $type  Progress type: percent, page.
	 * @return bool Success.
	 */
	public function update_progress( int $book_id, int $progress, string $type = 'percent' ): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}

		try {
			$gql = '
				mutation UpdateProgress($bookId: ID!, $progress: Int!, $progressType: String!) {
					update_user_book(
						book_id: $bookId
						progress: $progress
						progress_type: $progressType
					) {
						id
						progress
					}
				}
			';

			$this->graphql(
				$gql,
				array(
					'bookId'       => $book_id,
					'progress'     => $progress,
					'progressType' => $type,
				)
			);

			$this->delete_cache( 'reading_list_reading' );

			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Update progress failed', array( 'book_id' => $book_id, 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Add book to list.
	 *
	 * @param int    $book_id Hardcover book ID.
	 * @param string $status  Status: reading, want_to_read, read, did_not_finish.
	 * @return bool Success.
	 */
	public function add_to_list( int $book_id, string $status = 'want_to_read' ): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}

		try {
			$gql = '
				mutation AddToList($bookId: ID!, $status: String!) {
					create_user_book(book_id: $bookId, status: $status) {
						id
						status
					}
				}
			';

			$this->graphql( $gql, array( 'bookId' => $book_id, 'status' => $status ) );

			$this->delete_cache( "reading_list_{$status}" );

			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Add to list failed', array( 'book_id' => $book_id, 'status' => $status, 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Rate a book.
	 *
	 * @param int   $book_id Hardcover book ID.
	 * @param float $rating  Rating (0.5 to 5 in 0.5 increments).
	 * @return bool Success.
	 */
	public function rate_book( int $book_id, float $rating ): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}

		try {
			$gql = '
				mutation RateBook($bookId: ID!, $rating: Float!) {
					update_user_book(book_id: $bookId, rating: $rating) {
						id
						rating
					}
				}
			';

			$this->graphql( $gql, array( 'bookId' => $book_id, 'rating' => $rating ) );

			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Rate book failed', array( 'book_id' => $book_id, 'rating' => $rating, 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Get author details.
	 *
	 * @param string $id_or_slug Author ID or slug.
	 * @return array<string, mixed>|null Author data.
	 */
	public function get_author( string $id_or_slug ): ?array {
		$cache_key = 'author_' . $id_or_slug;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$gql = '
				query GetAuthor($id: ID, $slug: String) {
					author(id: $id, slug: $slug) {
						id
						name
						slug
						bio
						image {
							url
						}
						books_count
						books {
							id
							title
							slug
							image {
								url
							}
							ratings_average
						}
					}
				}
			';

			$variables = array();
			if ( is_numeric( $id_or_slug ) ) {
				$variables['id'] = $id_or_slug;
			} else {
				$variables['slug'] = $id_or_slug;
			}

			$response = $this->graphql( $gql, $variables );

			if ( isset( $response['author'] ) ) {
				$result = $this->normalize_author( $response['author'] );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get author failed', array( 'id' => $id_or_slug, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get series details.
	 *
	 * @param string $id_or_slug Series ID or slug.
	 * @return array<string, mixed>|null Series data.
	 */
	public function get_series( string $id_or_slug ): ?array {
		$cache_key = 'series_' . $id_or_slug;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$gql = '
				query GetSeries($id: ID, $slug: String) {
					series(id: $id, slug: $slug) {
						id
						name
						slug
						description
						books_count
						books {
							id
							title
							slug
							position
							image {
								url
							}
							contributions {
								author {
									name
								}
							}
						}
					}
				}
			';

			$variables = array();
			if ( is_numeric( $id_or_slug ) ) {
				$variables['id'] = $id_or_slug;
			} else {
				$variables['slug'] = $id_or_slug;
			}

			$response = $this->graphql( $gql, $variables );

			if ( isset( $response['series'] ) ) {
				$series = $response['series'];

				$result = array(
					'id'          => $series['id'] ?? 0,
					'name'        => $series['name'] ?? '',
					'slug'        => $series['slug'] ?? '',
					'description' => $series['description'] ?? '',
					'books_count' => $series['books_count'] ?? 0,
					'books'       => array(),
					'type'        => 'series',
					'source'      => 'hardcover',
				);

				if ( isset( $series['books'] ) ) {
					foreach ( $series['books'] as $book ) {
						$normalized = $this->normalize_book( $book );
						$normalized['position'] = $book['position'] ?? null;
						$result['books'][] = $normalized;
					}
				}

				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get series failed', array( 'id' => $id_or_slug, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get trending books.
	 *
	 * @param int $limit Max books.
	 * @return array<int, array<string, mixed>> Trending books.
	 */
	public function get_trending( int $limit = 25 ): array {
		$cache_key = 'trending';
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$gql = '
				query GetTrending($limit: Int!) {
					trending_books(limit: $limit) {
						id
						title
						slug
						image {
							url
						}
						contributions {
							author {
								id
								name
							}
						}
						ratings_average
						ratings_count
					}
				}
			';

			$response = $this->graphql( $gql, array( 'limit' => $limit ) );

			$books = array();

			if ( isset( $response['trending_books'] ) ) {
				foreach ( $response['trending_books'] as $book ) {
					$books[] = $this->normalize_book( $book );
				}
			}

			$this->set_cache( $cache_key, $books, DAY_IN_SECONDS );

			return $books;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get trending failed', array( 'error' => $e->getMessage() ) );
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
		return $this->normalize_book( $raw_result );
	}

	/**
	 * Normalize book data.
	 *
	 * @param array<string, mixed> $book     Book data.
	 * @param bool                 $detailed Whether this is detailed data.
	 * @return array<string, mixed> Normalized book.
	 */
	private function normalize_book( array $book, bool $detailed = false ): array {
		$authors = array();
		if ( isset( $book['contributions'] ) ) {
			foreach ( $book['contributions'] as $contribution ) {
				if ( isset( $contribution['author'] ) ) {
					$authors[] = array(
						'id'   => $contribution['author']['id'] ?? 0,
						'name' => $contribution['author']['name'] ?? '',
						'slug' => $contribution['author']['slug'] ?? '',
						'role' => $contribution['role'] ?? 'author',
					);
				}
			}
		}

		$result = array(
			'id'              => $book['id'] ?? 0,
			'hardcover_id'    => $book['id'] ?? 0,
			'title'           => $book['title'] ?? '',
			'slug'            => $book['slug'] ?? '',
			'description'     => $book['description'] ?? '',
			'cover'           => $book['image']['url'] ?? null,
			'release_date'    => $book['release_date'] ?? '',
			'pages'           => $book['pages'] ?? null,
			'authors'         => $authors,
			'ratings_average' => $book['ratings_average'] ?? null,
			'ratings_count'   => $book['ratings_count'] ?? 0,
			'type'            => 'book',
			'source'          => 'hardcover',
		);

		if ( $detailed ) {
			$result['editions_count'] = $book['editions_count'] ?? 0;
			$result['reviews_count']  = $book['reviews_count'] ?? 0;

			// Genres.
			$result['genres'] = array();
			if ( isset( $book['genres'] ) ) {
				foreach ( $book['genres'] as $genre_item ) {
					if ( isset( $genre_item['genre'] ) ) {
						$result['genres'][] = $genre_item['genre']['name'] ?? '';
					}
				}
			}

			// Series.
			if ( isset( $book['series'] ) ) {
				$result['series'] = array(
					'id'   => $book['series']['id'] ?? 0,
					'name' => $book['series']['name'] ?? '',
					'slug' => $book['series']['slug'] ?? '',
				);
			}

			// Editions.
			$result['editions'] = array();
			if ( isset( $book['editions'] ) ) {
				foreach ( $book['editions'] as $edition ) {
					$result['editions'][] = $this->normalize_edition( $edition );
				}
			}
		}

		return $result;
	}

	/**
	 * Normalize edition data.
	 *
	 * @param array<string, mixed> $edition Edition data.
	 * @return array<string, mixed> Normalized edition.
	 */
	private function normalize_edition( array $edition ): array {
		return array(
			'id'           => $edition['id'] ?? 0,
			'title'        => $edition['title'] ?? '',
			'isbn_10'      => $edition['isbn_10'] ?? '',
			'isbn_13'      => $edition['isbn_13'] ?? '',
			'isbn'         => $edition['isbn_13'] ?? $edition['isbn_10'] ?? '',
			'pages'        => $edition['pages'] ?? null,
			'format'       => $edition['format'] ?? '',
			'publisher'    => $edition['publisher'] ?? '',
			'release_date' => $edition['release_date'] ?? '',
			'cover'        => $edition['image']['url'] ?? null,
			'type'         => 'edition',
			'source'       => 'hardcover',
		);
	}

	/**
	 * Normalize author data.
	 *
	 * @param array<string, mixed> $author Author data.
	 * @return array<string, mixed> Normalized author.
	 */
	private function normalize_author( array $author ): array {
		$result = array(
			'id'          => $author['id'] ?? 0,
			'name'        => $author['name'] ?? '',
			'slug'        => $author['slug'] ?? '',
			'bio'         => $author['bio'] ?? '',
			'image'       => $author['image']['url'] ?? null,
			'books_count' => $author['books_count'] ?? 0,
			'books'       => array(),
			'type'        => 'author',
			'source'      => 'hardcover',
		);

		if ( isset( $author['books'] ) ) {
			foreach ( $author['books'] as $book ) {
				$result['books'][] = $this->normalize_book( $book );
			}
		}

		return $result;
	}

	/**
	 * Normalize user data.
	 *
	 * @param array<string, mixed> $user User data.
	 * @return array<string, mixed> Normalized user.
	 */
	private function normalize_user( array $user ): array {
		return array(
			'id'              => $user['id'] ?? 0,
			'username'        => $user['username'] ?? '',
			'name'            => $user['name'] ?? '',
			'bio'             => $user['bio'] ?? '',
			'image'           => $user['image'] ?? null,
			'books_count'     => $user['books_count'] ?? 0,
			'followers_count' => $user['followers_count'] ?? 0,
			'following_count' => $user['following_count'] ?? 0,
			'created_at'      => $user['created_at'] ?? '',
			'type'            => 'user',
			'source'          => 'hardcover',
		);
	}

	/**
	 * Set API token.
	 *
	 * @param string $token API token.
	 * @return void
	 */
	public function set_token( string $token ): void {
		$this->api_token = $token;
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://hardcover.app/api';
	}
}
