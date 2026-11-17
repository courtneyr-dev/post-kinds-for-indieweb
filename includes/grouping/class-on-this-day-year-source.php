<?php
/**
 * On This Day year grouping source.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW\Grouping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Group On This Day posts by their stored local publication year. */
final class On_This_Day_Year_Source implements Group_Source {
	// Public method signatures carry the interface contract types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	use Source_Basics;

	/** Build the source. */
	public function __construct() {
		$this->init_basics( 'on-this-day-year', '' );
	}

	/** Use the grouped engine's date and ID order. */
	public function sql( \WP_Query $query ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return null;
	}

	/** Return the post's local-year group. */
	public function group_of( \WP_Post $post, ?\WP_Query $query = null ): Archive_Group { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $post->post_date, wp_timezone() );
		$date   = $date instanceof \DateTimeImmutable ? $date : new \DateTimeImmutable( (string) $post->post_date, wp_timezone() );
		$bucket = Date_Source::unit_key( $date, 'year' );

		return new Archive_Group( $bucket['key'], $bucket['key'], $bucket['key'] );
	}

	/** Return the relative-year heading. */
	public function label( Archive_Group $group, array $settings ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$year = (int) $group->key();
		$age  = max( 1, (int) \PKIW\On_This_Day::today()->format( 'Y' ) - $year );

		return sprintf(
			/* translators: 1: Number of years ago. 2: Four-digit year. */
			_n( '%1$d year ago · %2$s', '%1$d years ago · %2$s', $age, 'post-kinds-for-indieweb-in-block-themes' ),
			$age,
			(string) $year
		);
	}

	/** Return no label for an empty group. */
	public function empty_label(): string {
		return '';
	}
}
