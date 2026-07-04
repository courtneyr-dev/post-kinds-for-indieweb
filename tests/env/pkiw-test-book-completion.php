<?php
/**
 * wp-env test fixture: deterministic book completion service.
 *
 * tests/e2e/read-kindle-flow.spec.js exercises the full ISBN → completed
 * meta → Kindle iframe flow. The real Book_Completion service hits Open
 * Library (and, on fallback paths, Google Books/Hardcover) over live HTTP,
 * which is non-deterministic in CI (network availability, upstream
 * response drift) and would make the e2e flaky through no fault of the
 * plugin code. This mu-plugin (mapped only in .wp-env.json, never shipped)
 * stubs the `pkiw_book_completion_service` filter seam — see
 * Book_Completion_Controller::service() — with a fixed responder for the
 * one ISBN the e2e uses (Fourth Wing, 9781649374042), returning the same
 * derived print-ASIN (1649374046 = Isbn::to10() of that ISBN-13) that the
 * real cascade would eventually produce. This keeps the e2e deterministic
 * while still exercising the genuine meta-fill code path.
 *
 * Never ship this outside the test environment.
 *
 * @package PostKindsForIndieWeb
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pkiw_book_completion_service',
	static function ( $service ) {
		if ( null !== $service ) {
			return $service;
		}

		return new class() {
			/**
			 * Deterministic completion stub for the e2e fixture ISBN.
			 *
			 * @param array $book Partial book data (canonical keys).
			 * @return array Completed book data.
			 */
			public function complete( array $book ): array {
				if ( isset( $book['isbn'] ) && '9781649374042' === $book['isbn'] ) {
					return array_merge(
						array(
							'title'  => 'Fourth Wing',
							'author' => 'Rebecca Yarros',
							'isbn'   => '9781649374042',
							'asin'   => '1649374046',
						),
						$book
					);
				}

				return $book;
			}
		};
	},
	10
);
