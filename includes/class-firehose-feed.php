<?php
/**
 * Firehose Feed
 *
 * Registers an RSS feed that includes imported posts.
 *
 * @package PKIW
 * @since   1.1.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Firehose Feed class.
 */
final class Firehose_Feed {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_feed' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'pre_get_posts', [ $this, 'include_imported_posts' ], 5 );
	}

	/**
	 * Register the feed and its root-level rewrite rule.
	 *
	 * @return void
	 */
	public function register_feed(): void {
		add_feed( 'firehose', [ $this, 'render' ] );
		add_rewrite_rule( '^firehose/?$', 'index.php?feed=firehose&pkiw_include_imported=1', 'top' );
	}

	/**
	 * Register the imported-post escape hatch as a public query variable.
	 *
	 * @param string[] $query_vars Public query variables.
	 * @return string[] Public query variables.
	 */
	public function register_query_var( array $query_vars ): array {
		$query_vars[] = 'pkiw_include_imported';
		return $query_vars;
	}

	/**
	 * Include imported posts in every firehose feed entry point.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return void
	 */
	public function include_imported_posts( \WP_Query $query ): void {
		if ( $query->is_main_query() && $query->is_feed( 'firehose' ) ) {
			$query->set( 'pkiw_include_imported', true );
		}
	}

	/**
	 * Render the core RSS2 feed template.
	 *
	 * @return void
	 */
	public function render(): void {
		load_template( ABSPATH . constant( 'WPINC' ) . '/feed-rss2.php' );
	}
}
