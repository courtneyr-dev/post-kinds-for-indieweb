<?php
/**
 * Kind facts: one reader per kind, read through one door.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The facts a theme prints for a kind post, on the recipe_facts() model.
 *
 * Each kind registers one reader: a function that takes a post ID and
 * returns a plain array read from the meta the card syncs (never from
 * block attributes, whose stale copies leaked in 0.7.91). get() picks the
 * reader by the post's kind and applies what every kind shares:
 *
 * - No facts for a post the requester can't read, or one behind a password.
 * - Facts the reader names as location are cut to the post's public
 *   location tier (Meta_Fields::get_public_location_fields()), for editors
 *   too, so a page shows every visitor the same facts.
 *
 * @since 1.9.0
 */
final class Kind_Facts {

	/**
	 * Built-in readers: kind slug => reader and its location facts.
	 *
	 * `location` maps a fact key to the tier that governs it (`name`,
	 * `locality`, `region`, `country`, `street`, `postal_code`,
	 * `coordinates`, `map`, `url`, `osm_id`, `venue_id`).
	 *
	 * @var array<string, array{reader: callable-string, location: array<string, string>}>
	 */
	public const READERS = [
		'recipe' => [
			'reader'   => __NAMESPACE__ . '\\recipe_facts',
			'location' => [],
		],
	];

	/**
	 * Readers registered at runtime, which win over READERS.
	 *
	 * @var array<string, array{reader: callable, location: array<string, string>}>
	 */
	private static array $registered = [];

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Register the reader for a kind.
	 *
	 * @since 1.9.0
	 *
	 * @param string                $kind     Kind slug.
	 * @param callable              $reader   Takes a post ID, returns an array of facts.
	 * @param array<string, string> $location Fact key => location tier for facts that place the post.
	 * @return void
	 */
	public static function register( string $kind, callable $reader, array $location = [] ): void {
		self::$registered[ $kind ] = [
			'reader'   => $reader,
			'location' => $location,
		];
	}

	/**
	 * The reader for a kind, if it has one.
	 *
	 * @since 1.9.0
	 *
	 * @param string $kind Kind slug.
	 * @return array{reader: callable, location: array<string, string>}|null
	 */
	public static function reader( string $kind ): ?array {
		return self::$registered[ $kind ] ?? self::READERS[ $kind ] ?? null;
	}

	/**
	 * The public facts for a post, from its kind's reader.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Facts, or [] when the post has no reader or can't be shown.
	 */
	public static function get( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::can_show( $post ) ) {
			return [];
		}

		$kind  = self::kind_of( $post );
		$entry = '' === $kind ? null : self::reader( $kind );
		if ( null === $entry ) {
			return [];
		}

		$facts = call_user_func( $entry['reader'], $post->ID );
		if ( ! is_array( $facts ) ) {
			return [];
		}

		/**
		 * Filters a kind post's facts before location privacy applies.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string, mixed> $facts   Facts from the kind's reader.
		 * @param int                  $post_id Post ID.
		 * @param string               $kind    Kind slug.
		 */
		$facts = (array) apply_filters( 'pkiw_kind_facts', $facts, $post->ID, $kind );

		return self::hide_location( $facts, $entry['location'], $post->ID );
	}

	/**
	 * A post's kind slug: its first kind term, as Kind_Artwork reads it.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post Post.
	 * @return string Kind slug, or '' for none.
	 */
	public static function kind_of( \WP_Post $post ): string {
		$kinds = get_the_terms( $post, Taxonomy::TAXONOMY );

		return is_array( $kinds ) && ! empty( $kinds ) ? $kinds[0]->slug : '';
	}

	/**
	 * Whether the current request may see facts about a post.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function can_show( \WP_Post $post ): bool {
		if ( post_password_required( $post ) ) {
			return false;
		}

		return is_post_publicly_viewable( $post ) || current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Blank the location facts the post's public tier hides.
	 *
	 * A fact named with an unknown tier is hidden.
	 *
	 * @param array<string, mixed>  $facts    Facts.
	 * @param array<string, string> $location Fact key => tier.
	 * @param int                   $post_id  Post ID.
	 * @return array<string, mixed>
	 */
	private static function hide_location( array $facts, array $location, int $post_id ): array {
		if ( empty( $location ) ) {
			return $facts;
		}

		$visible = Meta_Fields::get_public_location_fields( $post_id );
		foreach ( $location as $key => $tier ) {
			if ( ! array_key_exists( $key, $facts ) || ! empty( $visible[ $tier ] ) ) {
				continue;
			}
			if ( is_array( $facts[ $key ] ) ) {
				$facts[ $key ] = [];
			} elseif ( is_int( $facts[ $key ] ) || is_float( $facts[ $key ] ) ) {
				$facts[ $key ] = 0;
			} else {
				$facts[ $key ] = '';
			}
		}

		return $facts;
	}
}
