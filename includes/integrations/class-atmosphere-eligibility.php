<?php
/**
 * Post Kind eligibility policy for ATmosphere publishing.
 *
 * Rides ATmosphere's own per-post control instead of inventing a second
 * toggle: the integration supplies a *default* for the `atmosphere_disabled`
 * meta through WordPress's `default_post_metadata` filter. Reaction and
 * consumption kinds become opt-in without a single database write, the
 * author's explicit choice in ATmosphere's editor panel always wins (a
 * stored value bypasses metadata defaults entirely), and posts that already
 * carry a published record are never default-retracted.
 *
 * @package PKIW
 * @since   1.6.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

use PKIW\Taxonomy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kind eligibility defaults for ATmosphere.
 *
 * @since 1.6.0
 */
class Atmosphere_Eligibility {

	/**
	 * Option holding the integration's settings.
	 *
	 * Shape: [ 'eligible_kinds' => string[] ].
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const OPTION = 'pkiw_atmosphere';

	/**
	 * ATmosphere's per-post opt-out meta key.
	 *
	 * Mirrors the ATMOSPHERE_META_DISABLED constant; kept as a literal so
	 * this class stays loadable when ATmosphere is absent. The integration
	 * test suite asserts the literal matches the constant.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	private const META_DISABLED = 'atmosphere_disabled';

	/**
	 * Published-record meta keys that exempt a post from kind defaults.
	 *
	 * Mirrors \Atmosphere\Transformer\Document::META_URI and
	 * \Atmosphere\Transformer\Post::META_TID (the same pair ATmosphere's
	 * own has_post_records() checks); literals for the same reason as
	 * META_DISABLED, asserted against the constants in integration tests.
	 *
	 * @since 1.6.0
	 *
	 * @var string[]
	 */
	private const PUBLISHED_RECORD_KEYS = [ '_atmosphere_doc_uri', '_atmosphere_bsky_tid' ];

	/**
	 * Kinds whose posts publish to Standard.site by default.
	 *
	 * Self-contained content whose page is the canonical artifact. Reaction
	 * and consumption kinds (likes, listens, check-ins, …) default to
	 * opt-in: their pages are thin, high-volume, and — for check-ins —
	 * privacy-sensitive. The lexicon would accept them; this is product
	 * judgment, and both the site setting and the filters can widen it.
	 *
	 * @since 1.6.0
	 *
	 * @var string[]
	 */
	private const DEFAULT_ELIGIBLE = [
		'note',
		'article',
		'photo',
		'video',
		'audio',
		'review',
		'recipe',
		'event',
		'jam',
		'quote',
		'question',
		'craft',
	];

	/**
	 * Register the metadata-default filter.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Priority 20: after core's registered-default resolution (10), so
		// the kind default wins over ATmosphere's registered `false`.
		add_filter( 'default_post_metadata', [ $this, 'filter_default_disabled' ], 20, 4 );
	}

	/**
	 * Remove the metadata-default filter.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_filter( 'default_post_metadata', [ $this, 'filter_default_disabled' ], 20 );
	}

	/**
	 * The kinds currently eligible by default.
	 *
	 * @since 1.6.0
	 *
	 * @return string[] Kind slugs.
	 */
	public function get_eligible_kinds(): array {
		$settings = get_option( self::OPTION, [] );
		$kinds    = self::DEFAULT_ELIGIBLE;

		if ( is_array( $settings ) && isset( $settings['eligible_kinds'] ) && is_array( $settings['eligible_kinds'] ) ) {
			$kinds = array_values( array_filter( array_map( 'sanitize_key', $settings['eligible_kinds'] ) ) );
		}

		/**
		 * Filters which kinds publish to Standard.site by default.
		 *
		 * The result feeds the `atmosphere_disabled` metadata default; an
		 * author's explicit per-post choice always wins over it.
		 *
		 * @since 1.6.0
		 *
		 * @param string[] $kinds Eligible kind slugs.
		 */
		$kinds = apply_filters( 'pkiw_atmosphere_default_eligible_kinds', $kinds );

		return is_array( $kinds ) ? $kinds : self::DEFAULT_ELIGIBLE;
	}

	/**
	 * Default `atmosphere_disabled` to on for non-eligible kinds.
	 *
	 * Fires only when a post has no stored value for the key, so an
	 * author's explicit toggle (stored meta) always bypasses this.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed  $value     The metadata default.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key being read.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed '1' (disabled) for non-eligible kinds, else untouched.
	 */
	public function filter_default_disabled( $value, $object_id, $meta_key, $single ) {
		if ( self::META_DISABLED !== $meta_key ) {
			return $value;
		}

		$post = get_post( (int) $object_id );
		if ( ! $post instanceof \WP_Post ) {
			return $value;
		}

		// A post with a published record is never default-retracted: the
		// kind default governs new posts, not history. Reading other meta
		// keys here re-enters this filter, which bails on the key check.
		foreach ( self::PUBLISHED_RECORD_KEYS as $record_key ) {
			if ( '' !== (string) get_post_meta( $post->ID, $record_key, true ) ) {
				return $value;
			}
		}

		$kind = $this->kind_slug( $post );
		if ( null === $kind ) {
			return $value;
		}

		$disabled = ! in_array( $kind, $this->get_eligible_kinds(), true );

		/**
		 * Filters whether a post's sharing defaults to disabled.
		 *
		 * The final say on the *default* only — a stored per-post value
		 * never reaches this filter.
		 *
		 * @since 1.6.0
		 *
		 * @param bool     $disabled Whether sharing defaults to off.
		 * @param \WP_Post $post     The post.
		 * @param string   $kind     The post's kind slug.
		 */
		$disabled = (bool) apply_filters( 'pkiw_atmosphere_post_default_disabled', $disabled, $post, $kind );

		if ( ! $disabled ) {
			return $value;
		}

		return $single ? '1' : [ '1' ];
	}

	/**
	 * The post's kind slug.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string|null
	 */
	private function kind_slug( \WP_Post $post ): ?string {
		$terms = get_the_terms( $post->ID, Taxonomy::TAXONOMY );

		if ( is_array( $terms ) && isset( $terms[0]->slug ) ) {
			return (string) $terms[0]->slug;
		}

		return null;
	}
}
