<?php
/**
 * The recipe kind archive: course filter, stable title order and its heading.
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
 * Keeps the recipe archive a native WordPress query.
 *
 * A course filter is a public query var on the archive URL, and A-Z is
 * WordPress's own `orderby=title`. Both stay in the URL, so the pager
 * carries them from page to page with no extra state.
 */
final class Recipe_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'recipe';

	/**
	 * Public query var holding a course slug, as in `/kind/recipe/?pkiw_recipe_course=soup`.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'pkiw_recipe_course';

	/**
	 * The kind term's name as this plugin seeds it.
	 *
	 * @var string
	 */
	private const DEFAULT_TERM_NAME = 'Recipe';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_main_query' ] );
		add_filter( 'get_the_archive_title', [ $this, 'archive_title' ], 10, 2 );
	}

	/**
	 * Make the course query var public.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Apply the course filter and a stable title order to the recipe archive.
	 *
	 * @param \WP_Query $query Query about to run.
	 * @return void
	 */
	public function filter_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return;
		}

		$course = sanitize_title( (string) $query->get( self::QUERY_VAR ) );
		if ( '' !== $course ) {
			if ( WP_Recipe_Maker::is_supported_plugin_active() ) {
				// Courses live on the recipe post type, so the filter is a list of the posts that embed those recipes.
				$post_ids = WP_Recipe_Maker::post_ids_in_course( $course );
				$query->set( 'post__in', $post_ids ? $post_ids : [ 0 ] );
			} else {
				$tax_query   = (array) $query->get( 'tax_query' );
				$tax_query[] = [
					'taxonomy' => recipe_fallback_course_taxonomy(),
					'field'    => 'slug',
					'terms'    => $course,
				];
				$query->set( 'tax_query', $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the course filter is a taxonomy lookup.
			}
		}

		// Two recipes can share a title; without a tie-break they could swap pages.
		if ( 'title' === $query->get( 'orderby' ) ) {
			$order = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
			$query->set(
				'orderby',
				[
					'title' => $order,
					'ID'    => $order,
				]
			);
		}
	}

	/**
	 * Head the recipe archive "Recipes".
	 *
	 * The term stays "Recipe": one post is a recipe, and the kind picker
	 * and the term's slug read that way. The archive lists many. A site
	 * that renamed the term keeps its own name.
	 *
	 * @param string $title          Archive title, with any prefix.
	 * @param string $original_title Archive title without the prefix.
	 * @return string
	 */
	public function archive_title( $title, $original_title = '' ) {
		if ( ! is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return $title;
		}

		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || self::DEFAULT_TERM_NAME !== $term->name ) {
			return $title;
		}

		$plural   = __( 'Recipes', 'post-kinds-for-indieweb-in-block-themes' );
		$singular = '' !== (string) $original_title ? (string) $original_title : $term->name;
		$position = strrpos( (string) $title, $singular );

		return false === $position ? $title : substr_replace( (string) $title, $plural, $position, strlen( $singular ) );
	}
}
