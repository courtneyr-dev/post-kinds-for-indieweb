<?php
/**
 * The read kind archive: status shelves, the A to Z view and its links.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shelves the read archive by reading status and offers an author order.
 *
 * The shelves are the grouped archive engine's: a meta source on
 * `_pkiw_read_status` in the order reading, to-read, finished, abandoned,
 * labelled by read_status_labels(). The archive-sections marker in the
 * template's loop turns them on and sizes the page.
 *
 * `?pkiw_read_order=author` is the A to Z view: no shelves, reads by author,
 * then book title. It stays in the URL, so the pager carries it, and the
 * Read order block links both views. A feed ignores it.
 */
final class Read_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'read';

	/**
	 * Group source id.
	 *
	 * @var string
	 */
	public const SOURCE = 'read-status';

	/**
	 * Statuses in shelf order. A status not listed shelves after them, A to Z.
	 *
	 * @var string[]
	 */
	public const STATUSES = [ 'reading', 'to-read', 'finished', 'abandoned' ];

	/**
	 * Public query var for the archive's order, as in `/kind/read/?pkiw_read_order=author`.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'pkiw_read_order';

	/**
	 * The only order the var takes: by author, then title.
	 *
	 * @var string
	 */
	public const BY_AUTHOR = 'author';

	/**
	 * Block that links the archive's two orders.
	 *
	 * @var string
	 */
	public const ORDER_BLOCK = 'post-kinds-indieweb/read-order';

	/**
	 * Register the source and hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		Grouped_Archive::register_source( self::source(), self::KIND );

		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_filter( 'pkiw_archive_group_source', [ $this, 'group_source' ], 10, 3 );
		add_filter( 'posts_orderby', [ $this, 'author_orderby' ], 10, 2 );
		add_filter( 'pkiw_archive_sections_groups', [ $this, 'sections_groups' ] );
		if ( did_action( 'init' ) ) {
			$this->register_order_block();
		} else {
			add_action( 'init', [ $this, 'register_order_block' ] );
		}
	}

	/**
	 * The status source the read archive shelves by.
	 *
	 * @return Meta_Source
	 */
	public static function source(): Meta_Source {
		return new Meta_Source(
			self::SOURCE,
			[ Meta_Fields::PREFIX . 'read_status' ],
			self::STATUSES,
			Label_Mode::map( static fn(): array => read_status_labels() )
		);
	}

	/**
	 * Make the order query var public.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Whether a query is the read archive's A to Z view.
	 *
	 * @param \WP_Query $query Query.
	 * @return bool
	 */
	public static function is_author_view( \WP_Query $query ): bool {
		return self::BY_AUTHOR === $query->get( self::QUERY_VAR ) && $query->is_tax( Taxonomy::TAXONOMY, self::KIND );
	}

	/**
	 * Turn the shelves off in the A to Z view.
	 *
	 * The marker's linesPerPage still sizes the page: the engine keeps a
	 * page size when the source is null.
	 *
	 * @internal Hooked to `pkiw_archive_group_source`.
	 *
	 * @param string|null    $id    Source id.
	 * @param string         $kind  Kind slug.
	 * @param \WP_Query|null $query Query being grouped; null for the editor preview and REST.
	 * @return string|null
	 */
	public function group_source( $id, $kind = '', $query = null ) {
		return self::KIND === $kind && $query instanceof \WP_Query && self::is_author_view( $query ) ? null : $id;
	}

	/**
	 * Order the A to Z view: author, then book title, then newest first.
	 *
	 * Reads with no author sort last. A read with no book title sorts by its
	 * post title. A password-protected read sorts as one with no author, by
	 * its post title: the archive shows only its title link, so its place
	 * mustn't give away the author or book title it hides. Any non-empty
	 * password counts, spaces included, as in post_password_required().
	 * Values are the first stored row of each key, trimmed and compared
	 * case-insensitively. Each is a scalar subquery used only in ORDER BY, so
	 * the query keeps one row per post and the counts stay put. A feed, an
	 * explicit `?orderby=` and any other query are left alone.
	 *
	 * @internal Hooked to `posts_orderby`.
	 *
	 * @param string    $orderby ORDER BY clause.
	 * @param \WP_Query $query   Query.
	 * @return string
	 */
	public function author_orderby( $orderby, $query ) {
		if ( ! $query instanceof \WP_Query || is_admin() || ! $query->is_main_query() || $query->is_feed() || ! self::is_author_view( $query ) ) {
			return $orderby;
		}
		if ( '' !== ( $query->query['orderby'] ?? '' ) ) {
			return $orderby;
		}

		global $wpdb;
		$first  = static fn( string $suffix ): string => 'NULLIF(TRIM(' . $wpdb->prepare(
			"(SELECT pkiw_r.meta_value FROM {$wpdb->postmeta} pkiw_r WHERE pkiw_r.post_id = {$wpdb->posts}.ID AND pkiw_r.meta_key = %s ORDER BY pkiw_r.meta_id ASC LIMIT 1)",
			Meta_Fields::PREFIX . $suffix
		) . "), '')";
		// LENGTH, not <> '': under a PAD SPACE collation such as
		// utf8mb4_unicode_520_ci, a password of spaces equals ''.
		$hidden = "LENGTH({$wpdb->posts}.post_password) > 0";
		$author = "(CASE WHEN {$hidden} THEN '' ELSE COALESCE(" . $first( 'read_author' ) . ", '') END)";
		$title  = "(CASE WHEN {$hidden} THEN {$wpdb->posts}.post_title ELSE COALESCE(" . $first( 'read_title' ) . ", {$wpdb->posts}.post_title) END)";

		return "({$author} = '') ASC, LOWER({$author}) ASC, LOWER({$title}) ASC, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
	}

	/**
	 * Offer a heading field for each status in the archive sections editor.
	 *
	 * @internal Hooked to `pkiw_archive_sections_groups`.
	 *
	 * @param array<string, array<string, string>> $groups Kind slug => group key => default label.
	 * @return array<string, array<string, string>>
	 */
	public function sections_groups( $groups ) {
		$groups               = is_array( $groups ) ? $groups : [];
		$groups[ self::KIND ] = read_status_labels();

		return $groups;
	}

	/**
	 * Register the Read order block.
	 *
	 * Server-rendered, so the Site Editor prints the links the archive
	 * prints. A theme places it in its read archive template and styles it;
	 * the block ships a plain list of links.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register_order_block(): void {
		wp_register_script(
			'pkiw-read-order-editor',
			\PKIW_URL . 'assets/js/read-order-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-read-order-editor', 'post-kinds-for-indieweb-in-block-themes' );

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::ORDER_BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments. No block.json, so apiVersion 3 is declared here.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Read order', 'post-kinds-for-indieweb-in-block-themes' ),
			'description'     => __( 'Links that show the read archive on shelves by status, or A–Z by author.', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render_order_block' ],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-read-order-editor',
		];
		register_block_type( self::ORDER_BLOCK, $args );
	}

	/**
	 * Render the Read order block: All, then A–Z by author.
	 *
	 * Each link is the read archive's own URL, so the order works without
	 * JavaScript and the pager keeps it. The link for the view on screen
	 * gets `aria-current="page"`; away from the archive neither does. The
	 * editor preview shows the archive as it opens, with All current.
	 *
	 * @since 1.9.0
	 *
	 * @return string
	 */
	public static function render_order_block(): string {
		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$base = get_term_link( $term );
		if ( is_wp_error( $base ) ) {
			return '';
		}

		$on_archive = is_tax( Taxonomy::TAXONOMY, self::KIND );
		$by_author  = $on_archive && self::BY_AUTHOR === get_query_var( self::QUERY_VAR );
		$items      = [
			[
				'label'   => __( 'All', 'post-kinds-for-indieweb-in-block-themes' ),
				'url'     => $base,
				'current' => ( $on_archive || Grouped_Archive::is_block_preview() ) && ! $by_author,
			],
			[
				'label'   => __( 'A–Z by author', 'post-kinds-for-indieweb-in-block-themes' ),
				'url'     => add_query_arg( self::QUERY_VAR, self::BY_AUTHOR, $base ),
				'current' => $by_author,
			],
		];

		$links = '';
		foreach ( $items as $item ) {
			$links .= sprintf(
				'<li class="pk-read-order__item"><a class="pk-read-order__link" href="%1$s"%2$s>%3$s</a></li>',
				esc_url( $item['url'] ),
				$item['current'] ? ' aria-current="page"' : '',
				esc_html( $item['label'] )
			);
		}

		return sprintf(
			'<nav %1$s><ul class="pk-read-order__list">%2$s</ul></nav>',
			get_block_wrapper_attributes( [ 'aria-label' => __( 'Read order', 'post-kinds-for-indieweb-in-block-themes' ) ] ),
			$links
		);
	}
}
