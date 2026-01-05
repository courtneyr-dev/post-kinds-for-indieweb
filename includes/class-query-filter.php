<?php
/**
 * Query Filter
 *
 * Filters imported posts from main blog queries when hidden mode is enabled.
 *
 * @package ReactionsForIndieWeb
 * @since   1.1.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query Filter class.
 *
 * @since 1.1.0
 */
class Query_Filter {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'pre_get_posts', array( $this, 'filter_main_query' ) );
	}

	/**
	 * Filter imported posts from main blog query.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return void
	 */
	public function filter_main_query( \WP_Query $query ): void {
		// Only apply in hidden mode.
		if ( ! Post_Type::is_hidden_mode() ) {
			return;
		}

		// Don't filter admin queries.
		if ( is_admin() ) {
			return;
		}

		// Only filter main query.
		if ( ! $query->is_main_query() ) {
			return;
		}

		// Allow explicit inclusion of imported posts via query var.
		if ( $query->get( 'reactions_include_imported' ) ) {
			return;
		}

		// Don't filter kind taxonomy archives - these should show all posts.
		if ( $query->is_tax( 'kind' ) ) {
			return;
		}

		// Don't filter single post views.
		if ( $query->is_singular() ) {
			return;
		}

		// Only filter home (blog), main archive, date archives, category, tag queries.
		$should_filter = $query->is_home()
			|| $query->is_front_page()
			|| ( $query->is_archive() && ! $query->is_tax( 'kind' ) )
			|| $query->is_search();

		if ( ! $should_filter ) {
			return;
		}

		// Don't filter if already filtering by kind taxonomy.
		$tax_query = $query->get( 'tax_query' );
		if ( ! empty( $tax_query ) && is_array( $tax_query ) ) {
			foreach ( $tax_query as $clause ) {
				if ( is_array( $clause ) && ( $clause['taxonomy'] ?? '' ) === 'kind' ) {
					return;
				}
			}
		}

		// Exclude posts with the imported meta key.
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		// Add exclusion for imported posts.
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_reactions_imported_from',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_reactions_imported_from',
				'value'   => '',
				'compare' => '=',
			),
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Check if a post was imported.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_imported_post( int $post_id ): bool {
		$imported_from = get_post_meta( $post_id, '_reactions_imported_from', true );
		return ! empty( $imported_from );
	}
}
