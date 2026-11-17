<?php
/**
 * Printable zine request validation.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validate the three public zine query variables. */
final class Zine_Request {
	// Public signatures below carry the callback and value types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/** Page-count query variable. */
	public const VAR_PAGES = 'pkiw_zine_pages';

	/** Inclusive start-date query variable. */
	public const VAR_FROM = 'pkiw_zine_from';

	/** Inclusive end-date query variable. */
	public const VAR_TO = 'pkiw_zine_to';

	/**
	 * Construct an immutable validated request.
	 *
	 * @param bool                  $present Whether any zine variable is present.
	 * @param int                   $pages   Requested page count.
	 * @param string                $from    Inclusive first day.
	 * @param string                $to      Inclusive last day.
	 * @param array<string, string> $errors  Validation errors keyed by field.
	 */
	private function __construct(
		private bool $present,
		private int $pages,
		private string $from,
		private string $to,
		private array $errors
	) {}

	/**
	 * Build a request from registered query variables only.
	 *
	 * @param \WP_Query $query Source query.
	 * @return self
	 */
	public static function from_query( \WP_Query $query ): self {
		$raw_pages = $query->get( self::VAR_PAGES, null );
		$raw_from  = $query->get( self::VAR_FROM, null );
		$raw_to    = $query->get( self::VAR_TO, null );
		$present   = null !== $raw_pages;
		$pages     = is_scalar( $raw_pages ) ? (int) $raw_pages : 0;
		$from      = is_scalar( $raw_from ) ? sanitize_text_field( (string) $raw_from ) : '';
		$to        = is_scalar( $raw_to ) ? sanitize_text_field( (string) $raw_to ) : '';
		$errors    = [];

		if ( $present && ! in_array( (string) $raw_pages, [ '8', '16' ], true ) ) {
			$errors['pages'] = __( 'Choose 8 or 16 pages.', 'post-kinds-for-indieweb-in-block-themes' );
		}
		if ( $present && '' !== $from && ! self::valid_date( $from ) ) {
			$errors['from'] = __( 'Enter a valid From date, like 2026-03-09.', 'post-kinds-for-indieweb-in-block-themes' );
		}
		if ( $present && '' !== $to && ! self::valid_date( $to ) ) {
			$errors['to'] = __( 'Enter a valid To date, like 2026-03-09.', 'post-kinds-for-indieweb-in-block-themes' );
		}
		if ( $present && ! isset( $errors['from'], $errors['to'] ) && '' !== $from && '' !== $to && $from > $to ) {
			$errors['from'] = __( 'From must not be later than To.', 'post-kinds-for-indieweb-in-block-themes' );
		}

		return new self( $present, $pages, $from, $to, $errors );
	}

	/** Return whether the request contains a zine variable. */
	public function is_present(): bool {
		return $this->present;
	}

	/** Return whether the request can produce a print view. */
	public function is_valid(): bool {
		return $this->present && [] === $this->errors;
	}

	/** Return the requested page count. */
	public function pages(): int {
		return $this->pages;
	}

	/** Return the inclusive first day. */
	public function from(): string {
		return $this->from;
	}

	/** Return the inclusive last day. */
	public function to(): string {
		return $this->to;
	}

	/** Return validation errors keyed by form field. */
	public function errors(): array {
		return $this->errors;
	}

	/** Return the first invalid field in form order. */
	public function first_invalid(): ?string {
		foreach ( [ 'pages', 'from', 'to' ] as $field ) {
			if ( isset( $this->errors[ $field ] ) ) {
				return $field;
			}
		}

		return null;
	}

	/** Return the entry capacity for this request. */
	public function capacity(): int {
		return 16 === $this->pages ? 14 : 6;
	}

	/** Return an inclusive local-calendar date query. */
	public function date_query(): array {
		$clause = [
			'inclusive' => true,
			'column'    => 'post_date',
		];
		if ( '' !== $this->from ) {
			$clause['after'] = $this->from . ' 00:00:00';
		}
		if ( '' !== $this->to ) {
			$clause['before'] = $this->to . ' 23:59:59';
		}

		return count( $clause ) > 2 ? [ $clause ] : [];
	}

	/** Determine whether text is one real ISO calendar day. */
	private static function valid_date( string $value ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}
}
