<?php
/**
 * Readwise API Integration
 *
 * Provides integration with Readwise for importing highlights from various sources
 * including books, articles, podcasts, tweets, and more.
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
 * Readwise API class.
 *
 * @since 1.0.0
 */
class Readwise extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'readwise';

	/**
	 * Base URL for Readwise API.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://readwise.io/api/v2/';

	/**
	 * Rate limit (requests per second).
	 * Readwise allows 20 requests per minute.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.33;

	/**
	 * API access token.
	 *
	 * @var string
	 */
	private string $access_token = '';

	/**
	 * Valid source categories from Readwise.
	 *
	 * @var array<string, string>
	 */
	public const SOURCE_CATEGORIES = [
		'books'         => 'Books',
		'articles'      => 'Articles',
		'tweets'        => 'Tweets',
		'podcasts'      => 'Podcasts',
		'supplementals' => 'Supplementals',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$credentials        = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$readwise_creds     = $credentials['readwise'] ?? [];
		$this->access_token = $readwise_creds['access_token'] ?? '';
	}

	/**
	 * Get default headers.
	 *
	 * @return array<string, string> Headers.
	 */
	protected function get_default_headers(): array {
		$headers = parent::get_default_headers();

		if ( ! empty( $this->access_token ) ) {
			$headers['Authorization'] = 'Token ' . $this->access_token;
		}

		return $headers;
	}

	/**
	 * Check if API is configured.
	 *
	 * @return bool True if configured.
	 */
	public function is_configured(): bool {
		return ! empty( $this->access_token );
	}

	/**
	 * Test API connection.
	 *
	 * @return bool True if connection is successful.
	 */
	public function test_connection(): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}

		try {
			$result = $this->get( 'auth/' );
			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Connection test failed', [ 'error' => $e->getMessage() ] );
			return false;
		}
	}

	/**
	 * Get all books/sources from the library.
	 *
	 * @param string|null $category      Filter by category (books, articles, tweets, podcasts, supplementals).
	 * @param int         $limit         Maximum items to return.
	 * @param string|null $updated_after Only return items updated after this date (ISO 8601).
	 * @return array<int, array<string, mixed>> List of books/sources.
	 */
	public function get_books( ?string $category = null, int $limit = 100, ?string $updated_after = null ): array {
		if ( ! $this->is_configured() ) {
			return [];
		}

		$all_books   = [];
		$page_size   = min( $limit, 1000 ); // Readwise max page size.
		$next_cursor = null;

		try {
			do {
				$params = [
					'page_size' => $page_size,
				];

				if ( $category && isset( self::SOURCE_CATEGORIES[ $category ] ) ) {
					$params['category'] = $category;
				}

				// Filter by updated date - Readwise uses 'updated__gt' parameter.
				if ( $updated_after ) {
					$params['updated__gt'] = $updated_after;
				}

				if ( $next_cursor ) {
					$params['pageCursor'] = $next_cursor;
				}

				$response = $this->get( 'books/', $params );

				$books = $response['results'] ?? [];
				foreach ( $books as $book ) {
					$all_books[] = $this->normalize_book( $book );

					if ( count( $all_books ) >= $limit ) {
						break 2;
					}
				}

				$next_cursor = $response['nextPageCursor'] ?? null;

			} while ( $next_cursor && count( $all_books ) < $limit );

		} catch ( \Exception $e ) {
			$this->log_error( 'Failed to fetch books', [ 'error' => $e->getMessage() ] );
		}

		return $all_books;
	}

	/**
	 * Get highlights, optionally filtered by book/source ID or category.
	 *
	 * @param int|null    $book_id  Filter by book ID.
	 * @param string|null $category Filter by category.
	 * @param int         $limit    Maximum highlights to return.
	 * @return array<int, array<string, mixed>> List of highlights.
	 */
	public function get_highlights( ?int $book_id = null, ?string $category = null, int $limit = 100 ): array {
		if ( ! $this->is_configured() ) {
			return [];
		}

		$all_highlights = [];
		$page_size      = min( $limit, 1000 );
		$next_cursor    = null;

		try {
			do {
				$params = [
					'page_size' => $page_size,
				];

				if ( $book_id ) {
					$params['book_id'] = $book_id;
				}

				if ( $next_cursor ) {
					$params['pageCursor'] = $next_cursor;
				}

				$response = $this->get( 'highlights/', $params );

				$highlights = $response['results'] ?? [];
				foreach ( $highlights as $highlight ) {
					// Filter by category if specified (need to check book category).
					if ( $category ) {
						$book_category = $highlight['book']['category'] ?? '';
						if ( $book_category !== $category ) {
							continue;
						}
					}

					$all_highlights[] = $this->normalize_highlight( $highlight );

					if ( count( $all_highlights ) >= $limit ) {
						break 2;
					}
				}

				$next_cursor = $response['nextPageCursor'] ?? null;

			} while ( $next_cursor && count( $all_highlights ) < $limit );

		} catch ( \Exception $e ) {
			$this->log_error( 'Failed to fetch highlights', [ 'error' => $e->getMessage() ] );
		}

		return $all_highlights;
	}

	/**
	 * Get podcast episodes with highlights (from Snipd or other podcast sources).
	 *
	 * @param int         $limit             Maximum episodes to return.
	 * @param bool        $include_highlights Whether to fetch highlights for each episode.
	 * @param string|null $updated_after     Only return items updated after this date (ISO 8601).
	 * @return array<int, array<string, mixed>> List of podcast episodes.
	 */
	public function get_podcast_episodes( int $limit = 100, bool $include_highlights = true, ?string $updated_after = null ): array {
		// Get all books in the podcasts category.
		$podcasts = $this->get_books( 'podcasts', $limit, $updated_after );

		// Each "book" in the podcasts category is actually an episode.
		return array_map(
			function ( $podcast ) use ( $include_highlights ) {
				$episode = [
					'id'              => $podcast['id'],
					'episode_title'   => $podcast['title'],
					'show_name'       => $podcast['author'],
					'source_url'      => $podcast['source_url'],
					'cover_image'     => $podcast['cover_image'],
					'highlight_count' => $podcast['highlight_count'],
					'last_highlight'  => $podcast['last_highlight_at'],
					'source'          => $podcast['source'],
					'highlights'      => [],
				];

				// Fetch highlights for this episode if requested.
				if ( $include_highlights && $podcast['highlight_count'] > 0 ) {
					$highlights            = $this->get_highlights( (int) $podcast['id'], null, $podcast['highlight_count'] );
					$episode['highlights'] = array_map(
						function ( $h ) {
							return [
								'text' => $h['text'] ?? '',
								'note' => $h['note'] ?? '',
							];
						},
						$highlights
					);
				}

				return $episode;
			},
			$podcasts
		);
	}

	/**
	 * Get articles with highlights.
	 *
	 * @param int         $limit         Maximum articles to return.
	 * @param string|null $updated_after Only return items updated after this date (ISO 8601).
	 * @return array<int, array<string, mixed>> List of articles.
	 */
	public function get_articles( int $limit = 100, ?string $updated_after = null ): array {
		return $this->get_books( 'articles', $limit, $updated_after );
	}

	/**
	 * Get tweet threads with highlights.
	 *
	 * @param int         $limit         Maximum tweet threads to return.
	 * @param string|null $updated_after Only return items updated after this date (ISO 8601).
	 * @return array<int, array<string, mixed>> List of tweet threads.
	 */
	public function get_tweets( int $limit = 100, ?string $updated_after = null ): array {
		return $this->get_books( 'tweets', $limit, $updated_after );
	}

	/**
	 * Get book highlights.
	 *
	 * @param int         $limit         Maximum books to return.
	 * @param string|null $updated_after Only return items updated after this date (ISO 8601).
	 * @return array<int, array<string, mixed>> List of books.
	 */
	public function get_book_highlights( int $limit = 100, ?string $updated_after = null ): array {
		return $this->get_books( 'books', $limit, $updated_after );
	}

	/**
	 * Get books with their highlights for import.
	 *
	 * @param int         $limit             Maximum books to return.
	 * @param bool        $include_highlights Whether to fetch highlights for each book.
	 * @param string|null $updated_after     Only return items updated after this date (ISO 8601).
	 * @return array<int, array<string, mixed>> List of books with highlights.
	 */
	public function get_books_with_highlights( int $limit = 100, bool $include_highlights = true, ?string $updated_after = null ): array {
		$books = $this->get_books( 'books', $limit, $updated_after );

		return array_map(
			function ( $book ) use ( $include_highlights ) {
				$result = [
					'id'                => $book['id'],
					'title'             => $book['title'],
					'author'            => $book['author'],
					'cover_image'       => $book['cover_image'],
					'source_url'        => $book['source_url'],
					'highlight_count'   => $book['highlight_count'],
					'last_highlight_at' => $book['last_highlight_at'],
					'source'            => $book['source'],
					'asin'              => $book['asin'],
					'highlights'        => [],
				];

				// Fetch highlights for this book if requested.
				if ( $include_highlights && $book['highlight_count'] > 0 ) {
					$highlights           = $this->get_highlights( (int) $book['id'], null, $book['highlight_count'] );
					$result['highlights'] = array_map(
						function ( $h ) {
							return [
								'text'     => $h['text'] ?? '',
								'note'     => $h['note'] ?? '',
								'location' => $h['location'] ?? 0,
								'color'    => $h['color'] ?? '',
							];
						},
						$highlights
					);
				}

				return $result;
			},
			$books
		);
	}

	/**
	 * Normalize a book/source from the API response.
	 *
	 * @param array<string, mixed> $book Raw book data.
	 * @return array<string, mixed> Normalized book data.
	 */
	private function normalize_book( array $book ): array {
		return [
			'id'                => $book['id'] ?? 0,
			'title'             => $book['title'] ?? '',
			'author'            => $book['author'] ?? '',
			'category'          => $book['category'] ?? '',
			'source'            => $book['source'] ?? '',
			'source_url'        => $book['source_url'] ?? '',
			'cover_image'       => $book['cover_image_url'] ?? '',
			'highlight_count'   => $book['num_highlights'] ?? 0,
			'last_highlight_at' => $book['last_highlight_at'] ?? '',
			'updated_at'        => $book['updated'] ?? '',
			'asin'              => $book['asin'] ?? '',
			'tags'              => $book['tags'] ?? [],
			'document_note'     => $book['document_note'] ?? '',
		];
	}

	/**
	 * Normalize a highlight from the API response.
	 *
	 * @param array<string, mixed> $highlight Raw highlight data.
	 * @return array<string, mixed> Normalized highlight data.
	 */
	private function normalize_highlight( array $highlight ): array {
		return [
			'id'            => $highlight['id'] ?? 0,
			'text'          => $highlight['text'] ?? '',
			'note'          => $highlight['note'] ?? '',
			'location'      => $highlight['location'] ?? 0,
			'location_type' => $highlight['location_type'] ?? '',
			'url'           => $highlight['url'] ?? '',
			'color'         => $highlight['color'] ?? '',
			'created_at'    => $highlight['created_at'] ?? '',
			'updated_at'    => $highlight['updated'] ?? '',
			'book_id'       => $highlight['book_id'] ?? 0,
			'book'          => [
				'id'       => $highlight['book']['id'] ?? 0,
				'title'    => $highlight['book']['title'] ?? '',
				'author'   => $highlight['book']['author'] ?? '',
				'category' => $highlight['book']['category'] ?? '',
			],
			'tags'          => $highlight['tags'] ?? [],
		];
	}

	/**
	 * Search the API (not fully supported by Readwise).
	 *
	 * @param string $query Search query.
	 * @param mixed  ...$args Additional arguments.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		// Readwise doesn't have a search endpoint.
		// We could implement client-side filtering of get_books results.
		$all_books = $this->get_books( null, 1000 );

		$query_lower = strtolower( $query );

		return array_filter(
			$all_books,
			function ( $book ) use ( $query_lower ) {
				return str_contains( strtolower( $book['title'] ), $query_lower )
				|| str_contains( strtolower( $book['author'] ), $query_lower );
			}
		);
	}

	/**
	 * Get item by ID.
	 *
	 * @param string $id Book ID.
	 * @return array<string, mixed>|null Book data or null.
	 */
	public function get_by_id( string $id ): ?array {
		if ( ! $this->is_configured() ) {
			return null;
		}

		try {
			$response = $this->get( 'books/' . $id . '/' );
			return $this->normalize_book( $response );
		} catch ( \Exception $e ) {
			$this->log_error(
				'Failed to fetch book by ID',
				[
					'id'    => $id,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Normalize result to standard format.
	 *
	 * @param array<string, mixed> $raw_result Raw API result.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_result( array $raw_result ): array {
		return $this->normalize_book( $raw_result );
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string Documentation URL.
	 */
	public function get_docs_url(): string {
		return 'https://readwise.io/api_deets';
	}
}
