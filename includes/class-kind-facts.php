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
 * returns a plain array. A reader reads the meta Card_Meta_Sync writes
 * when its card has an ATTR_META_MAP entry. A kind whose card has no map
 * entry (bookmark, favorite, acquisition) reads its first card block, then
 * falls back to meta. Mood (issue 248) has no Card_Meta_Sync map, so its
 * reader takes label, key and emoji from its first mood-card block, then
 * meta. One exception: intensity reads _pkiw_mood_rating first (1-5), then
 * an intensity attribute the block comment actually serializes, never the
 * block.json default, because an explicit pick of 3 is stored only in meta.
 * Theme code reads neither and goes through
 * Kind_Facts::get() only, because issue 259's 0.7.91 leak came from theme
 * code that read block attributes. get() picks the reader by the post's
 * kind and applies what every kind shares:
 *
 * - No facts for a post the requester can't read, or one behind a password.
 * - Facts the reader names as location are cut to the post's public
 *   location tier (Meta_Fields::get_public_location_fields()), for editors
 *   too, so a page shows every visitor the same facts.
 * - Reply, like and bookmark readers return cite facts for hidden
 *   citations: `url`, `host`, `cited_title`, `picture`, `author` and
 *   `summary`. Bookmark adds `title`, `note`, `rel`, `date` and `drawer`.
 *   Each reader returns [] when can_show() is false and never returns
 *   location keys.
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
		if ( ! $post instanceof \WP_Post || Public_Eligibility::hides( $post ) || ! self::can_show( $post ) ) {
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
	 * The selected kind's public facts for a row of a mixed-kind archive.
	 *
	 * Valid only inside the like, repost, favorite or jam archive loop, for the
	 * row being rendered or a peer on its page, and only for a published post
	 * that carries the queried kind and Check-in. It runs the kind's reader with
	 * the same visibility guard, filter and location redaction as get(), with the
	 * shared cite metadata hidden from the reader so only the selected card's
	 * attributes and the kind's own meta keys supply a citation, then drops what
	 * the Check-in could have supplied: a featured or shared cover and a Jam URL
	 * scraped from the body.
	 *
	 * @since 1.10.3
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind    Queried kind slug.
	 * @return array<string, mixed> Facts, or [] when the post isn't an eligible mixed row.
	 */
	public static function get_for_archive_kind( int $post_id, string $kind ): array {
		$post  = get_post( $post_id );
		$entry = self::reader( $kind );
		if ( ! $post instanceof \WP_Post || null === $entry || ! in_array( $kind, Grouped_Archive::MIXED_ARCHIVE_KINDS, true )
			|| Grouped_Archive::current_archive_kind() !== $kind
			|| ! in_array( $post_id, array_merge( [ Grouped_Archive::current_archive_row() ], Grouped_Archive::current_page_ids() ), true )
			|| 'publish' !== $post->post_status
			|| ! has_term( $kind, Taxonomy::TAXONOMY, $post ) || ! has_term( 'checkin', Taxonomy::TAXONOMY, $post )
			|| Public_Eligibility::hides( $post ) || ! self::can_show( $post )
		) {
			return [];
		}

		$facts = self::read_without_shared_cite( $entry['reader'], $post->ID );
		if ( ! is_array( $facts ) ) {
			return [];
		}

		/** This filter is documented in Kind_Facts::get(). */
		$facts = (array) apply_filters( 'pkiw_kind_facts', self::own_kind_facts( $facts, $post, $kind ), $post->ID, $kind );

		return self::hide_location( $facts, $entry['location'], $post->ID );
	}

	/**
	 * Run a reader with the shared cite metadata hidden from it.
	 *
	 * The like, repost and favorite readers fall back to `_pkiw_cite_*` meta (url,
	 * name, author, summary, photo, host). On a mixed post that meta can come from
	 * the Check-in, so it can't back the selected kind's citation. With it hidden,
	 * every field the reader derives (url, host, cited_title, author, summary,
	 * card_image, name) comes from the card or the kind's own key.
	 *
	 * @param callable $reader  Kind reader.
	 * @param int      $post_id Post ID.
	 * @return mixed Reader result.
	 */
	private static function read_without_shared_cite( callable $reader, int $post_id ) {
		$prefix = Meta_Fields::PREFIX . 'cite_';
		$hide   = static function ( $value, $object_id, $key, $single ) use ( $post_id, $prefix ) {
			if ( null === $value && (int) $object_id === $post_id && is_string( $key ) && str_starts_with( $key, $prefix ) ) {
				return $single ? '' : [];
			}

			return $value;
		};

		add_filter( 'get_post_metadata', $hide, 10, 4 );
		try {
			return call_user_func( $reader, $post_id );
		} finally {
			remove_filter( 'get_post_metadata', $hide, 10 );
		}
	}

	/**
	 * Layer the selected kind's card over its reader facts and drop Check-in leftovers.
	 *
	 * @param array<string, mixed> $facts Reader facts.
	 * @param \WP_Post             $post  Post.
	 * @param string               $kind  Selected kind.
	 * @return array<string, mixed>
	 */
	private static function own_kind_facts( array $facts, \WP_Post $post, string $kind ): array {
		$card_name = 'post-kinds-indieweb/' . $kind . '-card';
		$card      = null;
		foreach ( flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( ( $block['blockName'] ?? '' ) === $card_name ) {
				$card = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
				break;
			}
		}
		$attr = static fn( string $key ): string => is_scalar( $card[ $key ] ?? null ) ? trim( (string) $card[ $key ] ) : '';

		if ( null !== $card && in_array( $kind, [ 'like', 'repost' ], true ) ) {
			$facts = self::authored_cite( $facts, $attr );
		}
		if ( 'content' === ( $facts['url_source'] ?? '' ) ) {
			$facts['url']        = '';
			$facts['url_source'] = '';
		}
		$source = $facts['picture']['source'] ?? '';
		if ( array_key_exists( 'picture', $facts ) && ( '' === $attr( 'jam' === $kind ? 'cover' : 'image' ) || ! in_array( $source, [ 'card', 'cover' ], true ) ) ) {
			$facts['picture'] = [
				'source'            => '',
				'attachment_id'     => 0,
				'url'               => '',
				'alt'               => '',
				'remote'            => false,
				'suppress_featured' => false,
			];
		}

		return $facts;
	}

	/**
	 * A like or repost card's own attributes over the shared cite metadata.
	 *
	 * @param array<string, mixed> $facts Reader facts.
	 * @param callable             $attr  Card attribute reader: key => trimmed string.
	 * @return array<string, mixed>
	 */
	private static function authored_cite( array $facts, callable $attr ): array {
		$url   = web_url( $attr( 'url' ) );
		$title = $attr( 'title' );
		if ( '' !== $url ) {
			$facts['url']  = $url;
			$facts['host'] = url_host( $url );
		}
		if ( '' !== $title && ! card_name_is_url( $title, $url ) ) {
			$facts['cited_title'] = $title;
			if ( isset( $facts['name'] ) ) {
				$facts['name']          = $title;
				$facts['name_is_title'] = false;
			}
		}
		foreach ( [
			'author'  => 'author',
			'summary' => 'description',
		] as $fact => $key ) {
			$facts[ $fact ] = '' !== $attr( $key ) ? $attr( $key ) : ( $facts[ $fact ] ?? '' );
		}
		$image = web_url( $attr( 'image' ) );
		if ( '' !== $image ) {
			$facts['picture'] = [
				'source'            => 'card',
				'attachment_id'     => 0,
				'url'               => $image,
				'alt'               => $attr( 'imageAlt' ),
				'remote'            => ! is_upload_url( $image ),
				'suppress_featured' => false,
			];
		}

		return $facts;
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
	 * A hidden fact becomes '' for text, [] for a list and null for a
	 * number, never 0, which is a real coordinate. A fact named with an
	 * unknown tier is hidden.
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
				$facts[ $key ] = null;
			} else {
				$facts[ $key ] = '';
			}
		}

		return $facts;
	}
}
