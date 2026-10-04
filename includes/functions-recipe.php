<?php
/**
 * Recipe facts for the kind archive and the Stream card.
 *
 * A supported recipe plugin owns recipe data: when one is running and a
 * post embeds a recipe, every value here is read from that plugin as the
 * page renders. The plugin's own yield and duration fields serve sites
 * with no supported recipe plugin, and a recipe post with no recipe card.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Integrations\WP_Recipe_Maker;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What is known about a recipe post, read when called.
 *
 * Unknown values are empty, never guessed: `0` for the picture and the
 * time, `''` for the duration and the yield, `[]` for the courses.
 *
 * @since 1.9.0
 *
 * @param int $post_id Post ID.
 * @return array{source: string, recipe_id: int, image_id: int, total_minutes: int, duration: string, yield: string, courses: array<int, array{name: string, slug: string}>}
 */
function recipe_facts( int $post_id ): array {
	$recipe = WP_Recipe_Maker::get_post_recipe( $post_id );

	if ( null !== $recipe ) {
		$minutes = (int) $recipe['total_time'];
		if ( $minutes <= 0 ) {
			$minutes = (int) $recipe['prep_time'] + (int) $recipe['cook_time'];
		}
		$minutes  = max( 0, $minutes );
		$image_id = (int) $recipe['image_id'];
		$servings = trim( (string) $recipe['servings'] );

		return [
			'source'        => 'wp-recipe-maker',
			'recipe_id'     => (int) $recipe['id'],
			'image_id'      => $image_id > 0 ? $image_id : (int) get_post_thumbnail_id( $post_id ),
			'total_minutes' => $minutes,
			'duration'      => recipe_duration_from_minutes( $minutes ),
			'yield'         => '' === $servings || '0' === $servings ? '' : trim( $servings . ' ' . (string) $recipe['servings_unit'] ),
			'courses'       => $recipe['courses'],
		];
	}

	$minutes = recipe_minutes_from_duration( (string) get_post_meta( $post_id, '_pkiw_recipe_duration', true ) );

	return [
		'source'        => 'post',
		'recipe_id'     => 0,
		'image_id'      => (int) get_post_thumbnail_id( $post_id ),
		'total_minutes' => $minutes,
		'duration'      => recipe_duration_from_minutes( $minutes ),
		'yield'         => trim( (string) get_post_meta( $post_id, '_pkiw_recipe_yield', true ) ),
		// With a recipe plugin running, a post it holds no recipe for has no course.
		'courses'       => WP_Recipe_Maker::is_supported_plugin_active() ? [] : recipe_fallback_courses( $post_id ),
	];
}

/**
 * Courses for a site with no supported recipe plugin: terms the site already assigns.
 *
 * Categories by default, minus the default category, which says nothing
 * about a recipe.
 *
 * @since 1.9.0
 *
 * @param int $post_id Post ID.
 * @return array<int, array{name: string, slug: string}>
 */
function recipe_fallback_courses( int $post_id ): array {
	$taxonomy = recipe_fallback_course_taxonomy();
	$terms    = get_the_terms( $post_id, $taxonomy );
	if ( ! is_array( $terms ) ) {
		return [];
	}

	$default = 'category' === $taxonomy ? (int) get_option( 'default_category' ) : 0;
	$courses = [];
	foreach ( $terms as $term ) {
		if ( $term->term_id === $default ) {
			continue;
		}
		$courses[] = [
			'name' => $term->name,
			'slug' => $term->slug,
		];
	}
	usort( $courses, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );

	return $courses;
}

/**
 * The taxonomy that stands in for courses when no recipe plugin is running.
 *
 * @since 1.9.0
 *
 * @return string Taxonomy name.
 */
function recipe_fallback_course_taxonomy(): string {
	/**
	 * Filters the taxonomy used as recipe courses on a site with no supported recipe plugin.
	 *
	 * @since 1.9.0
	 *
	 * @param string $taxonomy Taxonomy name. Default 'category'.
	 */
	$taxonomy = (string) apply_filters( 'pkiw_recipe_course_taxonomy', 'category' );

	return taxonomy_exists( $taxonomy ) ? $taxonomy : 'category';
}

/**
 * Minutes as an ISO 8601 duration: 45 is `PT45M`, 150 is `PT2H30M`.
 *
 * @since 1.9.0
 *
 * @param int $minutes Minutes.
 * @return string Duration, or '' for no time.
 */
function recipe_duration_from_minutes( int $minutes ): string {
	if ( $minutes <= 0 ) {
		return '';
	}

	$hours    = intdiv( $minutes, 60 );
	$rest     = $minutes % 60;
	$duration = 'PT';
	if ( $hours > 0 ) {
		$duration .= $hours . 'H';
	}
	if ( $rest > 0 ) {
		$duration .= $rest . 'M';
	}

	return $duration;
}

/**
 * Minutes in a stored ISO 8601 duration.
 *
 * @since 1.9.0
 *
 * @param string $duration Duration such as `PT1H30M`.
 * @return int Minutes, or 0 when the text is not a duration.
 */
function recipe_minutes_from_duration( string $duration ): int {
	if ( 1 !== preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:\d+S)?)?$/i', trim( $duration ), $parts ) ) {
		return 0;
	}

	return (int) ( $parts[1] ?? 0 ) * 1440 + (int) ( $parts[2] ?? 0 ) * 60 + (int) ( $parts[3] ?? 0 );
}

/**
 * A time for people: "45 min", "1 hr", "2 hr 30 min".
 *
 * @since 1.9.0
 *
 * @param int $minutes Minutes.
 * @return string Label, or '' for no time.
 */
function recipe_time_label( int $minutes ): string {
	if ( $minutes <= 0 ) {
		return '';
	}

	$hours = intdiv( $minutes, 60 );
	$rest  = $minutes % 60;

	if ( 0 === $hours ) {
		/* translators: %d: number of minutes. */
		return sprintf( __( '%d min', 'post-kinds-for-indieweb-in-block-themes' ), $rest );
	}
	if ( 0 === $rest ) {
		/* translators: %d: number of hours. */
		return sprintf( __( '%d hr', 'post-kinds-for-indieweb-in-block-themes' ), $hours );
	}

	/* translators: 1: number of hours, 2: number of minutes. */
	return sprintf( __( '%1$d hr %2$d min', 'post-kinds-for-indieweb-in-block-themes' ), $hours, $rest );
}

/**
 * The courses in use on the recipe archive, each with the link that filters to it.
 *
 * Only courses a published recipe post uses are listed. The links are
 * ordinary archive URLs carrying the course query var, so WordPress
 * paginates the filtered view itself.
 *
 * @since 1.9.0
 *
 * @return array<int, array{name: string, slug: string, url: string, count: int, current: bool}>
 */
function recipe_archive_courses(): array {
	$term = get_term_by( 'slug', Recipe_Archive::KIND, Taxonomy::TAXONOMY );
	if ( ! $term instanceof \WP_Term ) {
		return [];
	}
	$base = get_term_link( $term );
	if ( is_wp_error( $base ) ) {
		return [];
	}

	$kind_taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
	$post_ids      = get_posts(
		[
			'post_type'      => $kind_taxonomy ? $kind_taxonomy->object_type : 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the archive is a taxonomy query.
				[
					'taxonomy' => Taxonomy::TAXONOMY,
					'terms'    => $term->term_id,
				],
			],
		]
	);

	$courses = [];
	foreach ( $post_ids as $post_id ) {
		foreach ( recipe_facts( (int) $post_id )['courses'] as $course ) {
			if ( ! isset( $courses[ $course['slug'] ] ) ) {
				$courses[ $course['slug'] ] = [
					'name'  => $course['name'],
					'slug'  => $course['slug'],
					'count' => 0,
				];
			}
			++$courses[ $course['slug'] ]['count'];
		}
	}
	uasort( $courses, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );

	$current = is_tax( Taxonomy::TAXONOMY, Recipe_Archive::KIND ) ? sanitize_title( (string) get_query_var( Recipe_Archive::QUERY_VAR ) ) : '';
	$links   = [];
	foreach ( $courses as $course ) {
		$links[] = [
			'name'    => $course['name'],
			'slug'    => $course['slug'],
			'url'     => add_query_arg( Recipe_Archive::QUERY_VAR, $course['slug'], $base ),
			'count'   => $course['count'],
			'current' => '' !== $current && $current === $course['slug'],
		];
	}

	return $links;
}
