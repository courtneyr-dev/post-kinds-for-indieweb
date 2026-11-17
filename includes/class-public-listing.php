<?php
/**
 * Shared public-listing eligibility for cross-kind surfaces.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Apply the public-listing contract to SQL and PHP readers. */
final class Public_Listing {
	// Public method signatures carry the callback contract types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/** Hidden ActivityPub visibility values. */
	private const HIDDEN = [ 'quiet_public' ];

	/** Register the SQL predicate. */
	public static function register(): void {
		add_filter( 'posts_where', [ self::class, 'filter_where' ], 20, 2 );
	}

	/** Return the post-meta keys that decide public listing. */
	public static function meta_keys(): array {
		return [ 'activitypub_content_visibility' ];
	}

	/** Add the complete public-listing contract to query arguments. */
	public static function query_args( array $args ): array {
		if ( false === has_filter( 'posts_where', [ self::class, 'filter_where' ] ) ) {
			add_filter( 'posts_where', [ self::class, 'filter_where' ], 20, 2 );
		}

		return array_merge(
			$args,
			[
				'post_status'         => 'publish',
				'has_password'        => false,
				'suppress_filters'    => false,
				'pkiw_public_only'    => true,
				'pkiw_public_listing' => true,
			]
		);
	}

	/** Determine whether a post may appear on a public listing. */
	public static function is_listable( \WP_Post|int|null $post ): bool {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || ! Public_Eligibility::is_eligible( $post ) ) {
			return false;
		}

		foreach ( self::meta_keys() as $key ) {
			foreach ( get_post_meta( $post->ID, $key, false ) as $value ) {
				if ( in_array( (string) $value, self::HIDDEN, true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/** Remove non-listable IDs without changing input order. */
	public static function filter_ids( array $ids ): array {
		return array_values(
			array_filter(
				$ids,
				static fn( $id ): bool => self::is_listable( (int) $id )
			)
		);
	}

	/** Append the prepared visibility predicate to opted-in queries. */
	public static function filter_where( string $where, \WP_Query $query ): string {
		if ( true !== $query->get( 'pkiw_public_listing' ) ) {
			return $where;
		}

		global $wpdb;

		$keys   = self::meta_keys();
		$hidden = self::HIDDEN;

		$key_placeholders   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$value_placeholders = implode( ', ', array_fill( 0, count( $hidden ), '%s' ) );
		$prepared           = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder lists match the fixed key and hidden-value arrays.
			" AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pkiw_apv WHERE pkiw_apv.post_id = {$wpdb->posts}.ID AND pkiw_apv.meta_key IN ( {$key_placeholders} ) AND pkiw_apv.meta_value IN ( {$value_placeholders} ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Core table names and generated placeholder lists only.
			array_merge( $keys, $hidden )
		);

		return $where . $prepared;
	}
}
