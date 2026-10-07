<?php
/**
 * Group by the week, month or year of the post date.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW\Grouping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buckets posts by post_date in the site timezone (what get_the_date()
 * prints). It adds no SQL: a grouped query with a date source is ordered
 * post_date DESC, ID DESC, so buckets run newest first and stay contiguous.
 * A week starts on the site's start_of_week.
 */
final class Date_Source implements Group_Source {

	use Source_Basics;

	/**
	 * Bucket unit: week, month or year.
	 *
	 * @var string
	 */
	private string $unit;

	/**
	 * Constructor.
	 *
	 * @param string $id   Source id.
	 * @param string $unit 'week', 'month' or 'year'.
	 * @throws \InvalidArgumentException On a malformed id or an unknown unit.
	 */
	public function __construct( string $id, string $unit ) {
		$this->init_basics( $id, '' );
		if ( ! in_array( $unit, [ 'week', 'month', 'year' ], true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Date group unit "%s" must be week, month or year.', esc_html( $unit ) ) );
		}
		$this->unit = $unit;
	}

	/**
	 * No SQL: the engine orders by date and ID.
	 *
	 * @param \WP_Query $query Query.
	 * @return null
	 */
	public function sql( \WP_Query $query ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return null;
	}

	/**
	 * A post's bucket. The raw value is the bucket's first day, Y-m-d.
	 *
	 * @param \WP_Post       $post  Post.
	 * @param \WP_Query|null $query Unused.
	 * @return Archive_Group
	 */
	public function group_of( \WP_Post $post, ?\WP_Query $query = null ): Archive_Group { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$date = get_post_datetime( $post, 'date', 'local' );
		if ( ! $date instanceof \DateTimeImmutable ) {
			return new Archive_Group( '', '', '' );
		}

		$day = $date->setTime( 0, 0 );
		if ( 'year' === $this->unit ) {
			$start = $day->setDate( (int) $day->format( 'Y' ), 1, 1 );
			$key   = $start->format( 'Y' );
		} elseif ( 'month' === $this->unit ) {
			$start = $day->setDate( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), 1 );
			$key   = $start->format( 'Y-m' );
		} else {
			$back  = ( (int) $day->format( 'w' ) - (int) get_option( 'start_of_week', 0 ) + 7 ) % 7;
			$start = $day->modify( "-{$back} days" );
			$key   = $start->format( 'Y-m-d' );
		}

		return new Archive_Group( $key, $start->format( 'Y-m-d' ), $key );
	}

	/**
	 * Heading text: the month ("F Y") or year ("Y") in the entry's date format
	 * when it sets one, or the week as a date range.
	 *
	 * @param Archive_Group        $group    Group.
	 * @param array<string, mixed> $settings Entry settings; `date_format` is a date format or ''.
	 * @return string
	 */
	public function label( Archive_Group $group, array $settings ): string {
		$start = date_create_immutable_from_format( '!Y-m-d', $group->raw(), wp_timezone() );
		if ( ! $start instanceof \DateTimeImmutable ) {
			return $group->raw();
		}

		$format = (string) ( $settings['date_format'] ?? '' );
		if ( 'month' === $this->unit ) {
			return (string) wp_date( '' !== $format ? $format : 'F Y', $start->getTimestamp() );
		}
		if ( 'year' === $this->unit ) {
			return (string) wp_date( '' !== $format ? $format : 'Y', $start->getTimestamp() );
		}

		$end = $start->modify( '+6 days' );
		$s   = $start->getTimestamp();
		$e   = $end->getTimestamp();
		if ( $start->format( 'Y-m' ) === $end->format( 'Y-m' ) ) {
			return sprintf(
				/* translators: 1: first day of a week, as "September 21"; 2: last day, as "27, 2026". */
				_x( '%1$s–%2$s', 'week within one month', 'post-kinds-for-indieweb-in-block-themes' ),
				(string) wp_date( _x( 'F j', 'first day of a week within one month', 'post-kinds-for-indieweb-in-block-themes' ), $s ),
				(string) wp_date( _x( 'j, Y', 'last day of a week within one month', 'post-kinds-for-indieweb-in-block-themes' ), $e )
			);
		}
		if ( $start->format( 'Y' ) === $end->format( 'Y' ) ) {
			return sprintf(
				/* translators: 1: first day of a week, as "September 28"; 2: last day, as "October 4, 2026". */
				_x( '%1$s – %2$s', 'week across two months', 'post-kinds-for-indieweb-in-block-themes' ),
				(string) wp_date( _x( 'F j', 'first day of a week across two months', 'post-kinds-for-indieweb-in-block-themes' ), $s ),
				(string) wp_date( _x( 'F j, Y', 'last day of a week across two months', 'post-kinds-for-indieweb-in-block-themes' ), $e )
			);
		}

		return sprintf(
			/* translators: 1: first day of a week, as "December 28, 2026"; 2: last day, as "January 3, 2027". */
			_x( '%1$s – %2$s', 'week across two years', 'post-kinds-for-indieweb-in-block-themes' ),
			(string) wp_date( _x( 'F j, Y', 'first day of a week across two years', 'post-kinds-for-indieweb-in-block-themes' ), $s ),
			(string) wp_date( _x( 'F j, Y', 'last day of a week across two years', 'post-kinds-for-indieweb-in-block-themes' ), $e )
		);
	}

	/**
	 * No empty-group label of its own: every post has a date.
	 *
	 * @return string
	 */
	public function empty_label(): string {
		return '';
	}
}
