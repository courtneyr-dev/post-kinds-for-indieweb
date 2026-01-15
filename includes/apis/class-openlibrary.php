<?php
/**
 * Open Library API Integration
 *
 * Provides book metadata from Open Library (Internet Archive).
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\APIs;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Open Library API class.
 *
 * @since 1.0.0
 */
class OpenLibrary extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'openlibrary';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://openlibrary.org/';

	/**
	 * Covers base URL.
	 *
	 * @var string
	 */
	private string $covers_url = 'https://covers.openlibrary.org/';

	/**
	 * Rate limit: 100 requests per 5 minutes.
	 *
	 * @var float
	 */
	protected float $rate_limit = 3.0;

	/**
	 * Cache duration: 1 week.
	 *
	 * @var int
	 */
	protected int $cache_duration = WEEK_IN_SECONDS;

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		try {
			$this->get(
				'search.json',
				[
					'q'     => 'test',
					'limit' => 1,
				]
			);
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Search for books.
	 *
	 * @param string      $query  Search query.
	 * @param string|null $author Optional author filter.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		$author = $args[0] ?? null;

		$cache_key = 'search_' . md5( $query . ( $author ?? '' ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = [
				'q'     => $query,
				'limit' => 25,
			];

			if ( $author ) {
				$params['author'] = $author;
			}

			$response = $this->get( 'search.json', $params );

			$results = [];

			if ( isset( $response['docs'] ) && is_array( $response['docs'] ) ) {
				foreach ( $response['docs'] as $doc ) {
					$results[] = $this->normalize_result( $doc );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Search failed',
				[
					'query' => $query,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Search by title.
	 *
	 * @param string $title Book title.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_by_title( string $title ): array {
		$cache_key = 'title_' . md5( $title );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'search.json',
				[
					'title' => $title,
					'limit' => 25,
				]
			);

			$results = [];

			if ( isset( $response['docs'] ) ) {
				foreach ( $response['docs'] as $doc ) {
					$results[] = $this->normalize_result( $doc );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Search by title failed',
				[
					'title' => $title,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Search by author.
	 *
	 * @param string $author Author name.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_by_author( string $author ): array {
		$cache_key = 'author_search_' . md5( $author );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'search.json',
				[
					'author' => $author,
					'limit'  => 25,
				]
			);

			$results = [];

			if ( isset( $response['docs'] ) ) {
				foreach ( $response['docs'] as $doc ) {
					$results[] = $this->normalize_result( $doc );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Search by author failed',
				[
					'author' => $author,
					'error'  => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get book by Open Library ID or ISBN.
	 *
	 * @param string $id Open Library work/edition key or ISBN.
	 * @return array<string, mixed>|null Book data.
	 */
	public function get_by_id( string $id ): ?array {
		// Check if it's an ISBN.
		if ( preg_match( '/^[0-9X-]{10,17}$/', str_replace( '-', '', $id ) ) ) {
			return $this->get_by_isbn( $id );
		}

		// Check if it's a work key.
		if ( strpos( $id, '/works/' ) !== false || strpos( $id, 'OL' ) === 0 ) {
			return $this->get_work( $id );
		}

		return null;
	}

	/**
	 * Get book by ISBN.
	 *
	 * @param string $isbn ISBN-10 or ISBN-13.
	 * @return array<string, mixed>|null Book data.
	 */
	public function get_by_isbn( string $isbn ): ?array {
		$isbn = str_replace( [ '-', ' ' ], '', $isbn );

		$cache_key = 'isbn_' . $isbn;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			// Try the books API first.
			$response = $this->get(
				'api/books',
				[
					'bibkeys' => 'ISBN:' . $isbn,
					'format'  => 'json',
					'jscmd'   => 'data',
				]
			);

			$key = 'ISBN:' . $isbn;

			if ( isset( $response[ $key ] ) ) {
				$result = $this->normalize_books_api( $response[ $key ], $isbn );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			// Fallback to search.
			$search_response = $this->get(
				'search.json',
				[
					'isbn'  => $isbn,
					'limit' => 1,
				]
			);

			if ( isset( $search_response['docs'][0] ) ) {
				$result = $this->normalize_result( $search_response['docs'][0] );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get by ISBN failed',
				[
					'isbn'  => $isbn,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get work details.
	 *
	 * @param string $work_key Work key (e.g., OL45883W or /works/OL45883W).
	 * @return array<string, mixed>|null Work data.
	 */
	public function get_work( string $work_key ): ?array {
		// Normalize key.
		$work_key = preg_replace( '/^\/works\//', '', $work_key );

		$cache_key = 'work_' . $work_key;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "works/{$work_key}.json" );

			$result = $this->normalize_work( $response );

			// Get author details.
			if ( isset( $response['authors'] ) ) {
				$result['authors'] = [];

				foreach ( $response['authors'] as $author_ref ) {
					$author_key = $author_ref['author']['key'] ?? null;
					if ( $author_key ) {
						$author = $this->get_author( $author_key );
						if ( $author ) {
							$result['authors'][] = $author;
						}
					}
				}
			}

			// Get editions.
			$editions = $this->get_work_editions( $work_key );
			if ( ! empty( $editions ) ) {
				$result['editions'] = $editions;
				// Get cover from first edition with a cover.
				foreach ( $editions as $edition ) {
					if ( ! empty( $edition['cover'] ) ) {
						$result['cover'] = $edition['cover'];
						break;
					}
				}
			}

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get work failed',
				[
					'work_key' => $work_key,
					'error'    => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get edition details.
	 *
	 * @param string $edition_key Edition key (e.g., OL7353617M).
	 * @return array<string, mixed>|null Edition data.
	 */
	public function get_edition( string $edition_key ): ?array {
		$edition_key = preg_replace( '/^\/books\//', '', $edition_key );

		$cache_key = 'edition_' . $edition_key;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "books/{$edition_key}.json" );

			$result = $this->normalize_edition( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get edition failed',
				[
					'edition_key' => $edition_key,
					'error'       => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get work editions.
	 *
	 * @param string $work_key Work key.
	 * @param int    $limit    Max editions to fetch.
	 * @return array<int, array<string, mixed>> Editions.
	 */
	public function get_work_editions( string $work_key, int $limit = 10 ): array {
		$work_key = preg_replace( '/^\/works\//', '', $work_key );

		$cache_key = 'work_editions_' . $work_key;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "works/{$work_key}/editions.json", [ 'limit' => $limit ] );

			$editions = [];

			if ( isset( $response['entries'] ) ) {
				foreach ( $response['entries'] as $entry ) {
					$editions[] = $this->normalize_edition( $entry );
				}
			}

			$this->set_cache( $cache_key, $editions );

			return $editions;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get work editions failed',
				[
					'work_key' => $work_key,
					'error'    => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get author details.
	 *
	 * @param string $author_key Author key (e.g., OL34184A or /authors/OL34184A).
	 * @return array<string, mixed>|null Author data.
	 */
	public function get_author( string $author_key ): ?array {
		$author_key = preg_replace( '/^\/authors\//', '', $author_key );

		$cache_key = 'author_' . $author_key;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "authors/{$author_key}.json" );

			$result = [
				'key'        => $response['key'] ?? "/authors/{$author_key}",
				'ol_id'      => $author_key,
				'name'       => $response['name'] ?? '',
				'birth_date' => $response['birth_date'] ?? '',
				'death_date' => $response['death_date'] ?? '',
				'bio'        => is_array( $response['bio'] ?? '' ) ? ( $response['bio']['value'] ?? '' ) : ( $response['bio'] ?? '' ),
				'photo'      => $this->get_author_photo( $author_key ),
				'links'      => $response['links'] ?? [],
				'type'       => 'author',
				'source'     => 'openlibrary',
			];

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get author failed',
				[
					'author_key' => $author_key,
					'error'      => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get author's works.
	 *
	 * @param string $author_key Author key.
	 * @param int    $limit      Max works.
	 * @return array<int, array<string, mixed>> Works.
	 */
	public function get_author_works( string $author_key, int $limit = 25 ): array {
		$author_key = preg_replace( '/^\/authors\//', '', $author_key );

		$cache_key = 'author_works_' . $author_key;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "authors/{$author_key}/works.json", [ 'limit' => $limit ] );

			$works = [];

			if ( isset( $response['entries'] ) ) {
				foreach ( $response['entries'] as $entry ) {
					$works[] = $this->normalize_work( $entry );
				}
			}

			$this->set_cache( $cache_key, $works );

			return $works;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get author works failed',
				[
					'author_key' => $author_key,
					'error'      => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Search authors.
	 *
	 * @param string $query Author name query.
	 * @return array<int, array<string, mixed>> Authors.
	 */
	public function search_authors( string $query ): array {
		$cache_key = 'authors_' . md5( $query );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'search/authors.json',
				[
					'q'     => $query,
					'limit' => 25,
				]
			);

			$authors = [];

			if ( isset( $response['docs'] ) ) {
				foreach ( $response['docs'] as $doc ) {
					$key   = $doc['key'] ?? '';
					$ol_id = preg_replace( '/^\/authors\//', '', $key );

					$authors[] = [
						'key'        => $key,
						'ol_id'      => $ol_id,
						'name'       => $doc['name'] ?? '',
						'birth_date' => $doc['birth_date'] ?? '',
						'death_date' => $doc['death_date'] ?? '',
						'top_work'   => $doc['top_work'] ?? '',
						'work_count' => $doc['work_count'] ?? 0,
						'photo'      => $this->get_author_photo( $ol_id ),
						'type'       => 'author',
						'source'     => 'openlibrary',
					];
				}
			}

			$this->set_cache( $cache_key, $authors );

			return $authors;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Search authors failed',
				[
					'query' => $query,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get subjects.
	 *
	 * @param string $subject Subject name.
	 * @param int    $limit   Max works.
	 * @return array<string, mixed>|null Subject data with works.
	 */
	public function get_subject( string $subject, int $limit = 25 ): ?array {
		$subject = strtolower( str_replace( ' ', '_', $subject ) );

		$cache_key = 'subject_' . $subject;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "subjects/{$subject}.json", [ 'limit' => $limit ] );

			$result = [
				'name'       => $response['name'] ?? $subject,
				'work_count' => $response['work_count'] ?? 0,
				'works'      => [],
				'type'       => 'subject',
				'source'     => 'openlibrary',
			];

			if ( isset( $response['works'] ) ) {
				foreach ( $response['works'] as $work ) {
					$result['works'][] = [
						'key'                => $work['key'] ?? '',
						'title'              => $work['title'] ?? '',
						'edition_count'      => $work['edition_count'] ?? 0,
						'cover_id'           => $work['cover_id'] ?? null,
						'cover'              => $work['cover_id'] ? $this->get_cover_url( $work['cover_id'], 'M' ) : null,
						'first_publish_year' => $work['first_publish_year'] ?? null,
						'authors'            => array_map(
							function ( $a ) {
								return [
									'key'  => $a['key'] ?? '',
									'name' => $a['name'] ?? '',
								];
							},
							$work['authors'] ?? []
						),
					];
				}
			}

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get subject failed',
				[
					'subject' => $subject,
					'error'   => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get trending books.
	 *
	 * @param string $type   Type: now, daily, weekly, monthly, yearly.
	 * @param int    $limit  Max books.
	 * @return array<int, array<string, mixed>> Trending books.
	 */
	public function get_trending( string $type = 'daily', int $limit = 25 ): array {
		$cache_key = "trending_{$type}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "trending/{$type}.json", [ 'limit' => $limit ] );

			$books = [];

			if ( isset( $response['works'] ) ) {
				foreach ( $response['works'] as $work ) {
					$books[] = $this->normalize_trending_work( $work );
				}
			}

			// Cache trending for less time.
			$cache_duration = 'now' === $type ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
			$this->set_cache( $cache_key, $books, $cache_duration );

			return $books;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get trending failed',
				[
					'type'  => $type,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get recently changed works.
	 *
	 * @param int $limit Max works.
	 * @return array<int, array<string, mixed>> Recent changes.
	 */
	public function get_recent_changes( int $limit = 25 ): array {
		$cache_key = 'recent_changes';
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'recentchanges.json', [ 'limit' => $limit ] );

			$this->set_cache( $cache_key, $response, HOUR_IN_SECONDS );

			return $response;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get recent changes failed', [ 'error' => $e->getMessage() ] );
			return [];
		}
	}

	/**
	 * Normalize search result.
	 *
	 * @param array<string, mixed> $raw_result Raw result from search.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_result( array $raw_result ): array {
		$cover_id   = $raw_result['cover_i'] ?? null;
		$ol_work_id = str_replace( '/works/', '', $raw_result['key'] ?? '' );

		return [
			'key'                => $raw_result['key'] ?? '',
			'ol_work_id'         => $ol_work_id,
			'title'              => $raw_result['title'] ?? '',
			'subtitle'           => $raw_result['subtitle'] ?? '',
			'authors'            => $raw_result['author_name'] ?? [],
			'author_keys'        => $raw_result['author_key'] ?? [],
			'first_publish_year' => $raw_result['first_publish_year'] ?? null,
			'edition_count'      => $raw_result['edition_count'] ?? 0,
			'isbn'               => $raw_result['isbn'] ?? [],
			'publisher'          => $raw_result['publisher'] ?? [],
			'language'           => $raw_result['language'] ?? [],
			'subjects'           => $raw_result['subject'] ?? [],
			'cover_id'           => $cover_id,
			'cover'              => $cover_id ? $this->get_cover_url( $cover_id, 'M' ) : null,
			'cover_large'        => $cover_id ? $this->get_cover_url( $cover_id, 'L' ) : null,
			'number_of_pages'    => $raw_result['number_of_pages_median'] ?? null,
			'ratings_average'    => $raw_result['ratings_average'] ?? null,
			'ratings_count'      => $raw_result['ratings_count'] ?? null,
			'type'               => 'book',
			'source'             => 'openlibrary',
		];
	}

	/**
	 * Normalize work data.
	 *
	 * @param array<string, mixed> $work Work data.
	 * @return array<string, mixed> Normalized work.
	 */
	private function normalize_work( array $work ): array {
		$key   = $work['key'] ?? '';
		$ol_id = preg_replace( '/^\/works\//', '', $key );

		$description = '';
		if ( isset( $work['description'] ) ) {
			$description = is_array( $work['description'] ) ? ( $work['description']['value'] ?? '' ) : $work['description'];
		}

		$cover_id = null;
		if ( isset( $work['covers'][0] ) ) {
			$cover_id = $work['covers'][0];
		}

		return [
			'key'                => $key,
			'ol_work_id'         => $ol_id,
			'title'              => $work['title'] ?? '',
			'description'        => $description,
			'subjects'           => $work['subjects'] ?? [],
			'subject_places'     => $work['subject_places'] ?? [],
			'subject_times'      => $work['subject_times'] ?? [],
			'subject_people'     => $work['subject_people'] ?? [],
			'cover_id'           => $cover_id,
			'cover'              => $cover_id ? $this->get_cover_url( $cover_id, 'M' ) : null,
			'first_publish_date' => $work['first_publish_date'] ?? '',
			'type'               => 'work',
			'source'             => 'openlibrary',
		];
	}

	/**
	 * Normalize edition data.
	 *
	 * @param array<string, mixed> $edition Edition data.
	 * @return array<string, mixed> Normalized edition.
	 */
	private function normalize_edition( array $edition ): array {
		$key   = $edition['key'] ?? '';
		$ol_id = preg_replace( '/^\/books\//', '', $key );

		$cover_id = null;
		if ( isset( $edition['covers'][0] ) ) {
			$cover_id = $edition['covers'][0];
		}

		$isbn_10 = $edition['isbn_10'][0] ?? '';
		$isbn_13 = $edition['isbn_13'][0] ?? '';

		return [
			'key'             => $key,
			'ol_edition_id'   => $ol_id,
			'title'           => $edition['title'] ?? '',
			'subtitle'        => $edition['subtitle'] ?? '',
			'full_title'      => $edition['full_title'] ?? '',
			'authors'         => $edition['authors'] ?? [],
			'publishers'      => $edition['publishers'] ?? [],
			'publish_date'    => $edition['publish_date'] ?? '',
			'publish_places'  => $edition['publish_places'] ?? [],
			'number_of_pages' => $edition['number_of_pages'] ?? null,
			'isbn_10'         => $isbn_10,
			'isbn_13'         => $isbn_13,
			'isbn'            => $isbn_13 ?: $isbn_10,
			'languages'       => $edition['languages'] ?? [],
			'physical_format' => $edition['physical_format'] ?? '',
			'cover_id'        => $cover_id,
			'cover'           => $cover_id ? $this->get_cover_url( $cover_id, 'M' ) : null,
			'work_key'        => $edition['works'][0]['key'] ?? '',
			'type'            => 'edition',
			'source'          => 'openlibrary',
		];
	}

	/**
	 * Normalize books API response.
	 *
	 * @param array<string, mixed> $data Books API data.
	 * @param string               $isbn ISBN used for lookup.
	 * @return array<string, mixed> Normalized result.
	 */
	private function normalize_books_api( array $data, string $isbn ): array {
		$cover = null;
		if ( isset( $data['cover']['medium'] ) ) {
			$cover = $data['cover']['medium'];
		} elseif ( isset( $data['cover']['small'] ) ) {
			$cover = $data['cover']['small'];
		}

		$authors = [];
		if ( isset( $data['authors'] ) ) {
			foreach ( $data['authors'] as $author ) {
				$authors[] = $author['name'] ?? '';
			}
		}

		$subjects = [];
		if ( isset( $data['subjects'] ) ) {
			foreach ( $data['subjects'] as $subject ) {
				$subjects[] = $subject['name'] ?? '';
			}
		}

		return [
			'title'           => $data['title'] ?? '',
			'subtitle'        => $data['subtitle'] ?? '',
			'authors'         => $authors,
			'publishers'      => isset( $data['publishers'] ) ? array_column( $data['publishers'], 'name' ) : [],
			'publish_date'    => $data['publish_date'] ?? '',
			'number_of_pages' => $data['number_of_pages'] ?? null,
			'isbn'            => $isbn,
			'subjects'        => $subjects,
			'cover'           => $cover,
			'url'             => $data['url'] ?? '',
			'key'             => $data['key'] ?? '',
			'type'            => 'book',
			'source'          => 'openlibrary',
		];
	}

	/**
	 * Normalize trending work.
	 *
	 * @param array<string, mixed> $work Trending work data.
	 * @return array<string, mixed> Normalized work.
	 */
	private function normalize_trending_work( array $work ): array {
		$cover = null;
		if ( isset( $work['cover_i'] ) ) {
			$cover = $this->get_cover_url( $work['cover_i'], 'M' );
		}

		$authors = [];
		if ( isset( $work['author_name'] ) ) {
			$authors = $work['author_name'];
		}

		return [
			'key'                => $work['key'] ?? '',
			'title'              => $work['title'] ?? '',
			'authors'            => $authors,
			'author_keys'        => $work['author_key'] ?? [],
			'first_publish_year' => $work['first_publish_year'] ?? null,
			'cover'              => $cover,
			'availability'       => $work['availability'] ?? [],
			'type'               => 'book',
			'source'             => 'openlibrary',
		];
	}

	/**
	 * Get cover URL.
	 *
	 * @param int    $cover_id Cover ID.
	 * @param string $size     Size: S, M, L.
	 * @return string Cover URL.
	 */
	public function get_cover_url( int $cover_id, string $size = 'M' ): string {
		return $this->covers_url . "b/id/{$cover_id}-{$size}.jpg";
	}

	/**
	 * Get cover URL by ISBN.
	 *
	 * @param string $isbn ISBN.
	 * @param string $size Size: S, M, L.
	 * @return string Cover URL.
	 */
	public function get_cover_by_isbn( string $isbn, string $size = 'M' ): string {
		$isbn = str_replace( [ '-', ' ' ], '', $isbn );
		return $this->covers_url . "b/isbn/{$isbn}-{$size}.jpg";
	}

	/**
	 * Get cover URL by OLID.
	 *
	 * @param string $olid Open Library ID.
	 * @param string $size Size: S, M, L.
	 * @return string Cover URL.
	 */
	public function get_cover_by_olid( string $olid, string $size = 'M' ): string {
		return $this->covers_url . "b/olid/{$olid}-{$size}.jpg";
	}

	/**
	 * Get author photo URL.
	 *
	 * @param string $author_id Author OLID.
	 * @param string $size      Size: S, M, L.
	 * @return string Photo URL.
	 */
	public function get_author_photo( string $author_id, string $size = 'M' ): string {
		$author_id = preg_replace( '/^\/authors\//', '', $author_id );
		return $this->covers_url . "a/olid/{$author_id}-{$size}.jpg";
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://openlibrary.org/developers/api';
	}
}
