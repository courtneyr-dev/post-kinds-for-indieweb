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
 * prints). group_of() adds no SQL: a grouped query with a date source is
 * ordered post_date DESC, ID DESC, so buckets run newest first and stay
 * contiguous. A month source built with `$within` 'asc' keeps the months
 * newest first and runs the days inside a month oldest first (Grouped_Archive
 * builds that ORDER BY). units() runs one DISTINCT query for calendar paging.
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
	 * Order of the days inside a bucket: 'desc' (newest first, the default) or 'asc'.
	 *
	 * @var string
	 */
	private string $within;

	/**
	 * Units of a term, remembered for one request.
	 *
	 * @var array<string, string[]>
	 */
	private static array $units = [];

	/**
	 * Constructor.
	 *
	 * @param string $id     Source id.
	 * @param string $unit   'week', 'month' or 'year'.
	 * @param string $within 'desc' (default) or 'asc': the order of the days inside a month. Only a month source honors 'asc'.
	 * @throws \InvalidArgumentException On a malformed id, an unknown unit or an unknown order.
	 */
	public function __construct( string $id, string $unit, string $within = 'desc' ) {
		$this->init_basics( $id, '' );
		if ( ! in_array( $unit, [ 'week', 'month', 'year' ], true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Date group unit "%s" must be week, month or year.', esc_html( $unit ) ) );
		}
		if ( ! in_array( $within, [ 'desc', 'asc' ], true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Date group order "%s" must be desc or asc.', esc_html( $within ) ) );
		}
		$this->unit   = $unit;
		$this->within = $within;
	}

	/**
	 * The bucket unit.
	 *
	 * @return string 'week', 'month' or 'year'.
	 */
	public function unit(): string {
		return $this->unit;
	}

	/**
	 * The order of the days inside a bucket.
	 *
	 * @return string 'desc' or 'asc'.
	 */
	public function within(): string {
		return $this->within;
	}

	/**
	 * Whether this source runs the days of a month oldest first.
	 *
	 * @return bool
	 */
	public function runs_month_ascending(): bool {
		return 'month' === $this->unit && 'asc' === $this->within;
	}

	/**
	 * The key and first day of the bucket a local day falls in.
	 *
	 * The one bucketing rule: group_of() files a post by it and calendar
	 * paging pages by it, so a week page and a week section never disagree.
	 *
	 * @param \DateTimeImmutable $local_day A day in the site timezone.
	 * @param string             $unit      'week', 'month' or 'year'.
	 * @return array{key:string, start:string} The bucket key and its first day, Y-m-d.
	 * @throws \InvalidArgumentException On an unknown unit.
	 */
	public static function unit_key( \DateTimeImmutable $local_day, string $unit ): array {
		$day = $local_day->setTime( 0, 0 );
		if ( 'year' === $unit ) {
			$start = $day->setDate( (int) $day->format( 'Y' ), 1, 1 );
			$key   = $start->format( 'Y' );
		} elseif ( 'month' === $unit ) {
			$start = $day->setDate( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), 1 );
			$key   = $start->format( 'Y-m' );
		} elseif ( 'week' === $unit ) {
			$back  = ( (int) $day->format( 'w' ) - (int) get_option( 'start_of_week', 0 ) + 7 ) % 7;
			$start = $day->modify( "-{$back} days" );
			$key   = $start->format( 'Y-m-d' );
		} else {
			throw new \InvalidArgumentException( sprintf( 'Date group unit "%s" must be week, month or year.', esc_html( $unit ) ) );
		}

		return [
			'key'   => $key,
			'start' => $start->format( 'Y-m-d' ),
		];
	}

	/**
	 * The post types a kind's calendar pages hold: those the kind taxonomy
	 * covers that visitors can view.
	 *
	 * @return string[]
	 */
	public static function viewable_types(): array {
		$taxonomy = get_taxonomy( 'kind' );

		return array_values(
			array_filter(
				$taxonomy ? (array) $taxonomy->object_type : [],
				static fn( $type ): bool => is_string( $type ) && is_post_type_viewable( $type )
			)
		);
	}

	/**
	 * Every unit a kind has a visible post in, newest first.
	 *
	 * One prepared DISTINCT query over the term's published, unprotected
	 * posts of the viewable types. Private, draft, future and password posts
	 * add no unit, whoever asks; an empty password and a password of spaces
	 * are told apart by LENGTH(), because the column compares them equal.
	 * Cached for the request and in the object cache under the term, the
	 * unit, the week start (week only) and the last-changed stamps of posts
	 * and terms, so a re-kinded post shows on the next request.
	 *
	 * @param \WP_Term $term Kind term.
	 * @param string   $unit 'week', 'month' or 'year'.
	 * @return string[] Unit keys, as unit_key() names them.
	 * @throws \InvalidArgumentException On an unknown unit.
	 */
	public static function units( \WP_Term $term, string $unit ): array {
		if ( ! in_array( $unit, [ 'week', 'month', 'year' ], true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Date group unit "%s" must be week, month or year.', esc_html( $unit ) ) );
		}

		$cache_key = implode(
			'|',
			[
				(int) $term->term_taxonomy_id,
				$unit,
				'week' === $unit ? (string) get_option( 'start_of_week', 0 ) : '-',
				wp_cache_get_last_changed( 'posts' ),
				wp_cache_get_last_changed( 'terms' ),
			]
		);
		if ( isset( self::$units[ $cache_key ] ) ) {
			return self::$units[ $cache_key ];
		}
		$cached = wp_cache_get( $cache_key, 'pkiw_calendar_units' );
		if ( is_array( $cached ) ) {
			self::$units[ $cache_key ] = $cached;
			return $cached;
		}

		$types = self::viewable_types();
		$keys  = [];
		if ( [] !== $types ) {
			global $wpdb;
			$marks = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
			$days  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Cached below; $marks is a run of %s placeholders.
				$wpdb->prepare(
					"SELECT DISTINCT DATE( p.post_date ) AS post_day FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE tr.term_taxonomy_id = %d AND p.post_status = 'publish' AND LENGTH( p.post_password ) = 0 AND p.post_type IN ( {$marks} ) ORDER BY post_day DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( [ (int) $term->term_taxonomy_id ], $types )
				)
			);
			foreach ( (array) $days as $day ) {
				$date = date_create_immutable_from_format( '!Y-m-d', (string) $day, wp_timezone() );
				if ( $date instanceof \DateTimeImmutable ) {
					$keys[ self::unit_key( $date, $unit )['key'] ] = true;
				}
			}
		}

		$keys = array_map( 'strval', array_keys( $keys ) );
		wp_cache_set( $cache_key, $keys, 'pkiw_calendar_units' );
		self::$units[ $cache_key ] = $keys;

		return $keys;
	}

	/**
	 * No SQL: the engine orders by date and ID (and by month first when
	 * the days of a month run oldest first).
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

		$bucket = self::unit_key( $date, $this->unit );

		return new Archive_Group( $bucket['key'], $bucket['start'], $bucket['key'] );
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
