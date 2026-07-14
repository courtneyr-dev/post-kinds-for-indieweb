<?php
/**
 * Book field completion service.
 *
 * @package PKIW
 * @since   1.2.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\APIs\GoogleBooks;
use PKIW\APIs\Hardcover;
use PKIW\APIs\OpenLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fills missing book fields from whichever identifier is present:
 * ISBN → Open Library → Google Books → Hardcover; Amazon URL → ASIN;
 * ISBN-13 → derived ISBN-10 as print ASIN. Never overwrites a value
 * the caller already has; never drops input on API failure.
 *
 * Canonical field keys: title, author, isbn, publisher, publish_date,
 * pages, cover, url, asin.
 *
 * @since 1.2.0
 */
class Book_Completion {

	/**
	 * Constructor.
	 *
	 * @param OpenLibrary $open_library Open Library API client.
	 * @param GoogleBooks $google_books Google Books API client.
	 * @param Hardcover   $hardcover    Hardcover API client.
	 */
	public function __construct(
		private OpenLibrary $open_library,
		private GoogleBooks $google_books,
		private Hardcover $hardcover
	) {}

	/**
	 * Fill in missing book fields from the ISBN/Amazon-URL cascade.
	 *
	 * @param array<string, string> $book Partial book data (canonical keys).
	 * @return array<string, string> Completed book data.
	 */
	public function complete( array $book ): array {
		$book = array_filter( $book, static fn( $v ) => null !== $v && '' !== $v );

		// 1. Amazon URL → ASIN (and nothing else — Amazon has no free metadata API).
		if ( empty( $book['asin'] ) && ! empty( $book['url'] ) ) {
			$asin = Isbn::asin_from_url( (string) $book['url'] );
			if ( null !== $asin ) {
				$book['asin'] = $asin;
			}
		}

		// 2. ISBN lookup cascade for bibliographic fields.
		if ( ! empty( $book['isbn'] ) && Isbn::validate( (string) $book['isbn'] ) ) {
			$found = $this->open_library->get_by_isbn( (string) $book['isbn'] );
			$book  = $this->merge( $book, $this->from_open_library( $found ) );
		}

		// 3. Title search fallback when ISBN still unknown.
		if ( empty( $book['isbn'] ) && ! empty( $book['title'] ) ) {
			$results = $this->google_books->search_by_title( (string) $book['title'] );
			$book    = $this->merge( $book, $this->from_google_books( $results[0] ?? null ) );
		}

		// 4. Hardcover enrichment, only when configured.
		if ( $this->hardcover->is_configured() && ! empty( $book['title'] ) && ( empty( $book['cover'] ) || empty( $book['pages'] ) ) ) {
			$results = $this->hardcover->search( (string) $book['title'] );
			$book    = $this->merge( $book, $this->from_hardcover( $results[0] ?? null ) );
		}

		// 5. Print-ASIN derivation from ISBN.
		if ( empty( $book['asin'] ) && ! empty( $book['isbn'] ) ) {
			$isbn10 = 10 === strlen( str_replace( '-', '', (string) $book['isbn'] ) )
				? str_replace( '-', '', (string) $book['isbn'] )
				: Isbn::to10( (string) $book['isbn'] );
			if ( null !== $isbn10 ) {
				$book['asin'] = $isbn10;
			}
		}

		return $book;
	}

	/**
	 * Merge API-found data into the book, without overwriting caller values.
	 *
	 * Caller data always wins; API data only fills blanks.
	 *
	 * @param array<string, string> $book  Book data so far.
	 * @param array<string, mixed>  $found Adapter-normalized data to fill blanks with.
	 * @return array<string, string> Merged book data.
	 */
	private function merge( array $book, array $found ): array {
		foreach ( $found as $key => $value ) {
			if ( empty( $book[ $key ] ) && null !== $value && '' !== $value ) {
				$book[ $key ] = (string) $value;
			}
		}
		return $book;
	}

	// Per-API adapters → canonical keys, matched to each class's actual
	// normalizer output (verified against includes/apis/class-openlibrary.php,
	// class-google-books.php, class-hardcover.php):
	//
	// OpenLibrary::get_by_isbn() returns either normalize_books_api() (primary,
	// books API path) or normalize_result() (search.json fallback). Both use
	// 'authors' as an array of name strings and 'number_of_pages' (not 'pages').
	// Publisher is an array under 'publishers' (books API) or 'publisher'
	// (search fallback, despite the singular name) — never a plain string.
	//
	// GoogleBooks::search_by_title() → normalize_volume(): 'publisher' is
	// already a singular string, 'published_date', 'page_count', 'isbn_13'/'isbn'.
	//
	// Hardcover::search() → normalize_book() (non-detailed): 'cover', 'pages'
	// are present; ISBN is only available on normalize_edition(), which
	// search() never returns, so no 'isbn' key is mapped here.
	/**
	 * Map an Open Library get_by_isbn() result to canonical keys.
	 *
	 * @param array<string, mixed>|null $r Normalized Open Library book, or null.
	 * @return array<string, mixed> Canonical-key data.
	 */
	private function from_open_library( ?array $r ): array {
		if ( null === $r ) {
			return [];
		}
		$publisher = $r['publishers'] ?? $r['publisher'] ?? null;
		return [
			'title'        => $r['title'] ?? null,
			'author'       => is_array( $r['authors'] ?? null ) ? implode( ', ', $r['authors'] ) : ( $r['authors'] ?? null ),
			'publisher'    => is_array( $publisher ) ? implode( ', ', $publisher ) : $publisher,
			'publish_date' => $r['publish_date'] ?? null,
			'pages'        => isset( $r['number_of_pages'] ) ? (string) $r['number_of_pages'] : null,
			'cover'        => $r['cover'] ?? null,
		];
	}

	/**
	 * Map a Google Books normalize_volume() result to canonical keys.
	 *
	 * @param array<string, mixed>|null $r Normalized Google Books volume, or null.
	 * @return array<string, mixed> Canonical-key data.
	 */
	private function from_google_books( ?array $r ): array {
		if ( null === $r ) {
			return [];
		}
		return [
			'title'        => $r['title'] ?? null,
			'author'       => is_array( $r['authors'] ?? null ) ? implode( ', ', $r['authors'] ) : null,
			'publisher'    => $r['publisher'] ?? null,
			'publish_date' => $r['published_date'] ?? null,
			'pages'        => isset( $r['page_count'] ) ? (string) $r['page_count'] : null,
			'cover'        => $r['cover'] ?? null,
			'isbn'         => $r['isbn_13'] ?? $r['isbn'] ?? null,
		];
	}

	/**
	 * Map a Hardcover normalize_book() result to canonical keys.
	 *
	 * @param array<string, mixed>|null $r Normalized Hardcover book, or null.
	 * @return array<string, mixed> Canonical-key data.
	 */
	private function from_hardcover( ?array $r ): array {
		if ( null === $r ) {
			return [];
		}
		return [
			'cover' => $r['cover'] ?? null,
			'pages' => isset( $r['pages'] ) ? (string) $r['pages'] : null,
		];
	}
}
