<?php
/**
 * Sleep posts publish at a neutral noon (issue 247).
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives every sleep post the publish time noon of its night.
 *
 * Courtney's answer A on the health data question: a sleep post's publish
 * time must not say when she went to bed or woke up, so it is noon of the
 * night, on every surface that prints post_date. The window runs noon to
 * noon. A bedtime of 23:30 on Aug 19 and one of 00:40 on Aug 20 both file
 * under the night of Aug 19. A post with no bedtime files under the night
 * its own time falls in. The noon never reads later than the time the post
 * carried in, so a post can't publish ahead of itself.
 *
 * Three hooks do the work:
 *
 * - wp_insert_post_data rewrites post_date, post_date_gmt and, on the first
 *   publish, post_modified. It also recomputes a publish or future status
 *   from the new date with core's own 60 second test, because core chose
 *   the status before this filter ran.
 * - transition_post_status at priority 1 repairs the paths that skip that
 *   filter. wp_publish_post() writes only post_status, and cron publishes
 *   scheduled posts through it.
 * - rest_pre_insert_post records the kind terms of a REST request, because
 *   core's controller sets terms after the insert.
 *
 * @since 1.9.0
 */
final class Sleep_Night {

	/**
	 * The kind this class acts on.
	 */
	public const KIND = 'sleep';

	/**
	 * The sleep card block.
	 */
	private const CARD = 'post-kinds-indieweb/sleep-card';

	/**
	 * Post meta row holding the card's bedtime, written by Card_Meta_Sync.
	 */
	private const BEDTIME_KEY = '_pkiw_sleep_bedtime';

	/**
	 * Night the Micropub builder gave the next insert, as Y-m-d.
	 *
	 * @var string|null
	 */
	private static ?string $pending_night = null;

	/**
	 * What rest_pre_insert_post saw: the post ID being saved (0 for a create)
	 * and whether the request's kind terms include sleep.
	 *
	 * @var array{id: int, sleep: bool}|null
	 */
	private static ?array $rest_kind = null;

	/**
	 * Posts that first became published in this request, by ID. Every later
	 * save in the same request keeps their modified time at noon.
	 *
	 * @var array<int, true>
	 */
	private static array $first_published = [];

	/**
	 * Local noon the last create wrote, so the transition that follows can
	 * tell which post it belongs to once the post has an ID.
	 *
	 * @var string|null
	 */
	private static ?string $created_noon = null;

	/**
	 * Hook the date rewrite, the publish repair and the REST recorder. Safe to
	 * call more than once.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_insert_post_data', [ self::class, 'neutral_post_date' ], 20, 2 );
		add_action( 'transition_post_status', [ self::class, 'repair_on_publish' ], 1, 3 );
		add_filter( 'rest_pre_insert_post', [ self::class, 'record_rest_kind' ], 10, 2 );
	}

	/**
	 * Forget everything a request recorded. A web request ends and does this
	 * on its own; tests and long-running CLI work call it between saves.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$pending_night   = null;
		self::$rest_kind       = null;
		self::$first_published = [];
		self::$created_noon    = null;
	}

	/**
	 * The night a local time belongs to, as Y-m-d: the date twelve hours
	 * earlier, in the site timezone.
	 *
	 * @param \DateTimeImmutable $local A time in any timezone.
	 * @return string
	 */
	public static function night_of( \DateTimeImmutable $local ): string {
		return $local->setTimezone( wp_timezone() )->modify( '-12 hours' )->format( 'Y-m-d' );
	}

	/**
	 * The night a post belongs to: the night of its stored bedtime when that
	 * row is valid, even when the bedtime is hidden, else the night of its
	 * own local post date. A post at noon returns its own date.
	 *
	 * @param \WP_Post $post Post.
	 * @return string Y-m-d.
	 */
	public static function night_date( \WP_Post $post ): string {
		$bedtime = self::parse_bedtime( (string) get_metadata_raw( 'post', $post->ID, self::BEDTIME_KEY, true ) );
		if ( null !== $bedtime ) {
			return self::night_of( $bedtime );
		}

		$date = self::parse_local( $post->post_date );

		return null !== $date ? self::night_of( $date ) : '';
	}

	/**
	 * Record the night the next insert files under. The Micropub builder
	 * calls this for a sleep request, with the night of its start, else its
	 * published time, else now.
	 *
	 * @param string $ymd A date, Y-m-d. Anything else is ignored.
	 * @return void
	 */
	public static function set_pending_night( string $ymd ): void {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		if ( $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d' ) === $ymd ) {
			self::$pending_night = $ymd;
		}
	}

	/**
	 * Record whether a REST request's kind terms include sleep. Core's
	 * controller sets terms after the insert, so wp_insert_post_data can't
	 * see them.
	 *
	 * @param mixed $prepared_post The prepared post object, returned untouched.
	 * @param mixed $request       The REST request.
	 * @return mixed
	 */
	public static function record_rest_kind( $prepared_post, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $prepared_post;
		}

		$term  = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		$terms = $request->get_param( Taxonomy::TAXONOMY );
		$terms = is_array( $terms ) ? $terms : [];

		self::$rest_kind = [
			'id'    => is_object( $prepared_post ) ? (int) ( $prepared_post->ID ?? 0 ) : 0,
			'sleep' => $term instanceof \WP_Term && in_array( $term->term_id, array_map( 'intval', $terms ), true ),
		];

		return $prepared_post;
	}

	/**
	 * Give a sleep post the noon of its night as its date.
	 *
	 * Applies to posts with an incoming status of publish, future or private.
	 * Drafts and pending posts keep their time until they publish.
	 *
	 * @param mixed $data    Slashed post data.
	 * @param mixed $postarr Post array as wp_insert_post() received it.
	 * @return mixed The data, with the new dates.
	 */
	public static function neutral_post_date( $data, $postarr ) {
		if ( ! is_array( $data ) || ! is_array( $postarr ) || 'post' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$status = (string) ( $data['post_status'] ?? '' );
		if ( ! in_array( $status, [ 'publish', 'future', 'private' ], true ) ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$content = wp_unslash( (string) ( $data['post_content'] ?? '' ) );
		if ( ! self::is_sleep_request( $content, $postarr, $post_id ) ) {
			return $data;
		}

		$incoming = self::parse_local( (string) ( $data['post_date'] ?? '' ) );
		if ( null === $incoming ) {
			return $data;
		}

		// Spend what this insert used, so a later create in the same request
		// (a REST batch, another plugin) doesn't read as sleep.
		$hint                = self::$pending_night;
		self::$pending_night = null;
		self::$rest_kind     = null;
		$night               = self::night_for( $content, $incoming, $hint );
		$noon                = self::noon( $night );

		$data['post_date']     = $noon->format( 'Y-m-d H:i:s' );
		$data['post_date_gmt'] = $noon->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		// Core picked publish or future from the incoming date, before this
		// filter. Pick again from the new one, by core's own 60 second test.
		if ( 'private' !== $status ) {
			$ahead = strtotime( $data['post_date_gmt'] . ' UTC' ) - time();
			if ( 'publish' === $status && $ahead >= MINUTE_IN_SECONDS ) {
				$data['post_status'] = 'future';
			} elseif ( 'future' === $status && $ahead < MINUTE_IN_SECONDS ) {
				$data['post_status'] = 'publish';
			}
		}

		$stored = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $stored instanceof \WP_Post ) {
			$stored = null;
		}

		$first_publish = in_array( $status, [ 'publish', 'private' ], true )
			&& ( null === $stored || ! in_array( $stored->post_status, [ 'publish', 'private', 'future' ], true ) );
		if ( null === $stored ) {
			self::$created_noon = $data['post_date'];
		}

		if ( $first_publish || ( $post_id > 0 && isset( self::$first_published[ $post_id ] ) ) ) {
			// The publishing request, and every save after it in that request.
			$data['post_modified']     = $data['post_date'];
			$data['post_modified_gmt'] = $data['post_date_gmt'];
		} elseif ( null !== $stored && self::saves_nothing_new( $stored, $data, $content ) ) {
			// Gutenberg's meta box save after a publish sends no content
			// fields, so core would write the real time over the noon.
			$data['post_modified']     = $stored->post_modified;
			$data['post_modified_gmt'] = $stored->post_modified_gmt;
		}

		return $data;
	}

	/**
	 * Repair a sleep post that published without passing through
	 * wp_insert_post_data: wp_publish_post() writes only post_status, and cron
	 * publishes a scheduled post through it. Runs before Webmention, the
	 * publish hook and the senders read the post.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 * @return void
	 */
	public static function repair_on_publish( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return;
		}

		$created = in_array( $old_status, [ 'new', 'auto-draft' ], true ) && null !== self::$created_noon && self::$created_noon === $post->post_date;
		if ( ! $created && ! self::is_sleep_post( $post ) ) {
			return;
		}

		if ( ! in_array( $old_status, [ 'private', 'future' ], true ) ) {
			self::$first_published[ (int) $post->ID ] = true;
		}

		$incoming = self::parse_local( $post->post_date );
		if ( null === $incoming ) {
			return;
		}

		$noon = self::noon( self::night_for( (string) $post->post_content, $incoming, null ) );
		if ( $noon->format( 'Y-m-d H:i:s' ) === $post->post_date ) {
			return;
		}

		global $wpdb;
		$local = $noon->format( 'Y-m-d H:i:s' );
		$gmt   = $noon->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wp_publish_post() writes the status the same way, and no API writes post_date for a published post without a save.
		$wpdb->update(
			$wpdb->posts,
			[
				'post_date'         => $local,
				'post_date_gmt'     => $gmt,
				'post_modified'     => $local,
				'post_modified_gmt' => $gmt,
			],
			[ 'ID' => (int) $post->ID ]
		);
		clean_post_cache( (int) $post->ID );

		// Later hooks on this transition read the same object.
		$post->post_date         = $local;
		$post->post_date_gmt     = $gmt;
		$post->post_modified     = $local;
		$post->post_modified_gmt = $gmt;
	}

	/**
	 * The night a post files under, as Y-m-d: the night of the first sleep
	 * card's bedtime, else the pending hint, else the night of the incoming
	 * time. The noon of that night never reads later than the incoming time.
	 *
	 * @param string             $content  Post content.
	 * @param \DateTimeImmutable $incoming The post date the save carried in.
	 * @param string|null        $hint     Night the Micropub builder recorded.
	 * @return string Y-m-d.
	 */
	private static function night_for( string $content, \DateTimeImmutable $incoming, ?string $hint ): string {
		$bedtime = self::card_bedtime( $content );
		if ( null !== $bedtime ) {
			$night = self::night_of( $bedtime );
		} elseif ( null !== $hint ) {
			$night = $hint;
		} else {
			$night = self::night_of( $incoming );
		}

		return self::noon( $night ) > $incoming ? self::night_of( $incoming ) : $night;
	}

	/**
	 * Noon on a night's date, in the site timezone.
	 *
	 * @param string $night Y-m-d.
	 * @return \DateTimeImmutable
	 */
	private static function noon( string $night ): \DateTimeImmutable {
		return new \DateTimeImmutable( $night . ' 12:00:00', wp_timezone() );
	}

	/**
	 * Whether an insert is a sleep post's.
	 *
	 * The first kind card decides, as Taxonomy's kind sync decides: a sleep
	 * card first is sleep, and another kind's card first is not, so a read
	 * post with a sleep card behind its read card keeps its time. With no
	 * kind card first, a sleep term decides: the request's tax_input, the
	 * post's stored term, a REST request's terms, or a pending Micropub hint.
	 *
	 * @param string               $content Unslashed post content.
	 * @param array<string, mixed> $postarr Post array.
	 * @param int                  $post_id Post ID, 0 for a create.
	 * @return bool
	 */
	private static function is_sleep_request( string $content, array $postarr, int $post_id ): bool {
		$first = Taxonomy::get_first_block_kind( $content );
		if ( null !== $first ) {
			return self::KIND === $first;
		}

		if ( null !== self::$pending_night ) {
			return true;
		}

		if ( null !== self::$rest_kind && self::$rest_kind['sleep'] && self::$rest_kind['id'] === $post_id ) {
			return true;
		}

		if ( self::tax_input_has_sleep( $postarr['tax_input'][ Taxonomy::TAXONOMY ] ?? null ) ) {
			return true;
		}

		return $post_id > 0 && has_term( self::KIND, Taxonomy::TAXONOMY, $post_id );
	}

	/**
	 * Whether a stored post is a sleep post, by the same first-card rule.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function is_sleep_post( \WP_Post $post ): bool {
		$first = Taxonomy::get_first_block_kind( (string) $post->post_content );

		return null === $first ? has_term( self::KIND, Taxonomy::TAXONOMY, $post ) : self::KIND === $first;
	}

	/**
	 * Whether a tax_input entry for the kind taxonomy names sleep, by term
	 * ID, slug or name.
	 *
	 * @param mixed $input The entry: an array of terms, or a comma list.
	 * @return bool
	 */
	private static function tax_input_has_sleep( $input ): bool {
		if ( is_string( $input ) ) {
			$input = explode( ',', $input );
		}
		if ( ! is_array( $input ) ) {
			return false;
		}

		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		foreach ( $input as $item ) {
			if ( is_numeric( $item ) && $term instanceof \WP_Term && (int) $item === $term->term_id ) {
				return true;
			}
			if ( is_string( $item ) && self::KIND === sanitize_title( trim( $item ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a save changes none of the fields the block editor sends, which
	 * are also the fields that say a real edit happened.
	 *
	 * @param \WP_Post             $stored  The stored post.
	 * @param array<string, mixed> $data    Slashed data about to be written, with the noon date.
	 * @param string               $content Unslashed incoming content.
	 * @return bool
	 */
	private static function saves_nothing_new( \WP_Post $stored, array $data, string $content ): bool {
		$same = [
			[ $stored->post_content, $content ],
			[ $stored->post_title, wp_unslash( (string) ( $data['post_title'] ?? '' ) ) ],
			[ $stored->post_excerpt, wp_unslash( (string) ( $data['post_excerpt'] ?? '' ) ) ],
			[ $stored->post_status, (string) ( $data['post_status'] ?? '' ) ],
			[ $stored->post_password, wp_unslash( (string) ( $data['post_password'] ?? '' ) ) ],
			[ $stored->post_date, (string) ( $data['post_date'] ?? '' ) ],
		];

		foreach ( $same as [ $before, $after ] ) {
			if ( $before !== $after ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The bedtime attribute of the first sleep card in some content.
	 *
	 * @param string $content Post content.
	 * @return \DateTimeImmutable|null Null when there is no card or no valid bedtime.
	 */
	private static function card_bedtime( string $content ): ?\DateTimeImmutable {
		if ( ! str_contains( $content, 'wp:' . self::CARD ) ) {
			return null;
		}

		foreach ( flatten_blocks( parse_blocks( $content ) ) as $block ) {
			if ( self::CARD === ( $block['blockName'] ?? '' ) ) {
				return self::parse_bedtime( (string) ( $block['attrs']['bedtime'] ?? '' ) );
			}
		}

		return null;
	}

	/**
	 * A card's `YYYY-MM-DDTHH:MM` wall time in the site timezone.
	 *
	 * A time the clock skips, such as 02:30 on the night daylight saving
	 * starts, is a real bedtime to the person who wrote it, so it reads as the
	 * instant PHP moves it to.
	 *
	 * @param string $value Stored bedtime.
	 * @return \DateTimeImmutable|null Null for anything that isn't a date and a time.
	 */
	private static function parse_bedtime( string $value ): ?\DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ) {
			return null;
		}

		$real = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $real instanceof \DateTimeImmutable || $real->format( 'Y-m-d\TH:i' ) !== $value ) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );

		return $parsed instanceof \DateTimeImmutable ? $parsed : null;
	}

	/**
	 * A post_date in the site timezone.
	 *
	 * @param string $value A `Y-m-d H:i:s` post date.
	 * @return \DateTimeImmutable|null Null for a zero or malformed date.
	 */
	private static function parse_local( string $value ): ?\DateTimeImmutable {
		if ( '' === $value || str_starts_with( $value, '0000' ) ) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );

		return $parsed instanceof \DateTimeImmutable ? $parsed : null;
	}
}
