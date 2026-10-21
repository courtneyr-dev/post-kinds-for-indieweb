<?php
/**
 * Quote archive grouping, index blocks and archive entries.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Archive_Group;
use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own the public quote commonplace-book contract.
 *
 * @since 1.9.0
 */
final class Quote_Archive {

	/** Kind slug. */
	public const KIND = 'quote';

	/** Group source ID. */
	public const SOURCE = 'quote_author';

	/** Entry block name. */
	public const ENTRY_BLOCK = 'post-kinds-indieweb/quote-entry';

	/** Author index block name. */
	public const AUTHORS_BLOCK = 'post-kinds-indieweb/quote-authors';

	/** Source-work index block name. */
	public const WORKS_BLOCK = 'post-kinds-indieweb/quote-works';

	/** Public author query variable. */
	public const VAR_AUTHOR = 'pkiw_quote_author';

	/** Public work query variable. */
	public const VAR_WORK = 'pkiw_quote_work';

	/** Public author-initial query variable. */
	public const VAR_INITIAL = 'pkiw_quote_initial';

	/** Archive entries per page. */
	public const PER_PAGE = 8;

	/** Cached aggregate key. */
	public const INDEX_TRANSIENT = 'pkiw_quote_index_v1';

	/** Query flag for the exact empty-password SQL guard. */
	private const PUBLIC_QUERY_VAR = 'pkiw_quote_public';

	/** Citation meta keys that affect the index. */
	private const CITE_KEYS = [ '_pkiw_cite_author', '_pkiw_cite_name', '_pkiw_cite_url' ];

	/**
	 * Register quote grouping, filters, blocks and cache invalidation.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Grouped_Archive::register_source( self::source(), self::KIND );
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );

		add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
		add_action( 'pre_get_posts', [ self::class, 'filter_main_query' ] );
		add_filter( 'posts_where', [ self::class, 'public_where' ], 10, 2 );
		add_filter( 'pkiw_kind_archive_preview_per_page', [ self::class, 'preview_per_page' ], 10, 2 );

		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', [ self::class, 'register_blocks' ] );
		}

		foreach ( [ 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'transition_post_status' ] as $hook ) {
			add_action( $hook, [ self::class, 'flush_index' ] );
		}
		add_action( 'set_object_terms', [ self::class, 'terms_changed' ], 10, 6 );
		add_action( 'deleted_term_relationships', [ self::class, 'term_relationships_deleted' ], 10, 3 );
		add_action( 'added_post_meta', [ self::class, 'meta_changed' ], 10, 4 );
		add_action( 'updated_post_meta', [ self::class, 'meta_changed' ], 10, 4 );
		add_action( 'deleted_post_meta', [ self::class, 'meta_changed' ], 10, 4 );
	}

	/**
	 * Quote author group source.
	 *
	 * @since 1.9.0
	 *
	 * @return Meta_Source
	 */
	public static function source(): Meta_Source {
		return new Meta_Source( self::SOURCE, [ '_pkiw_cite_author' ], [], Label_Mode::verbatim(), '', true );
	}

	/**
	 * Add the public quote filter variables.
	 *
	 * @param string[] $vars Query variables.
	 * @return string[]
	 */
	public static function add_query_vars( array $vars ): array {
		return array_values( array_unique( array_merge( $vars, [ self::VAR_AUTHOR, self::VAR_WORK, self::VAR_INITIAL ] ) ) );
	}

	/**
	 * Apply quote filters to the front-end main quote archive only.
	 *
	 * @param \WP_Query $query Query about to run.
	 * @return void
	 */
	public static function filter_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return;
		}

		$author  = self::sanitize_query_value( $query->get( self::VAR_AUTHOR ) );
		$work    = self::sanitize_query_value( $query->get( self::VAR_WORK ) );
		$initial = mb_strtoupper( mb_substr( self::sanitize_query_value( $query->get( self::VAR_INITIAL ) ), 0, 1 ) );
		if ( '' === $author && '' === $work && '' === $initial ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );
		if ( '' !== $author ) {
			$meta_query[] = [
				'key'     => '_pkiw_cite_author',
				'value'   => $author,
				'compare' => '=',
			];
		}
		if ( '' !== $work ) {
			$meta_query[] = [
				'key'     => '_pkiw_cite_name',
				'value'   => $work,
				'compare' => '=',
			];
		}
		if ( [] !== $meta_query ) {
			$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Public archive filters match registered citation fields.
		}
		if ( '' !== $initial ) {
			$query->set( 'post__in', self::index()['initials'][ $initial ]['ids'] ?? [ 0 ] );
		}
		$query->set( self::PUBLIC_QUERY_VAR, 1 );
	}

	/**
	 * Require a byte-empty password for quote index and filtered queries.
	 *
	 * @internal Hooked to posts_where.
	 *
	 * @param string          $where WHERE clause.
	 * @param \WP_Query|mixed $query Query.
	 * @return string
	 */
	public static function public_where( $where, $query ): string {
		if ( ! $query instanceof \WP_Query || ! $query->get( self::PUBLIC_QUERY_VAR ) ) {
			return (string) $where;
		}

		global $wpdb;

		return (string) $where . " AND LENGTH( {$wpdb->posts}.post_password ) = 0";
	}

	/**
	 * Quote archive preview page size.
	 *
	 * @param int    $per_page Current size.
	 * @param string $kind     Kind slug.
	 * @return int
	 */
	public static function preview_per_page( int $per_page, string $kind ): int {
		return self::KIND === $kind ? self::PER_PAGE : $per_page;
	}

	/**
	 * Aggregate public quotes once for every quote surface.
	 *
	 * @since 1.9.0
	 *
	 * @return array{authors:list<array{name:string, initial:string, count:int}>, initials:array<string, array{count:int, ids:int[]}>, works:list<array{name:string, author:string, count:int, shared_title:bool}>, total:int}
	 */
	public static function index(): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- One pass builds the three correlated aggregate views.
		$cached = get_transient( self::INDEX_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		$query    = new \WP_Query(
			[
				'post_type'            => $taxonomy ? $taxonomy->object_type : 'post',
				'post_status'          => 'publish',
				'fields'               => 'ids',
				'no_found_rows'        => true,
				'posts_per_page'       => -1,
				'ignore_sticky_posts'  => true,
				'suppress_filters'     => false,
				'orderby'              => [
					'date' => 'DESC',
					'ID'   => 'DESC',
				],
				// The archive's own group order, so the tabs run in the order the sections do on this database's collation.
				'pkiw_group_source'    => self::SOURCE,
				'pkiw_group_order'     => 'ASC',
				'pkiw_group_empty'     => 'last',
				self::PUBLIC_QUERY_VAR => 1,
				'tax_query'            => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The aggregate is defined by the quote kind term.
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => self::KIND,
					],
				],
			]
		);

		/**
		 * Authors keyed by normalized name.
		 *
		 * @var array<string, array{name:string, initial:string, count:int, ids:int[]}> $authors
		 */
		$authors = [];
		/**
		 * Works keyed by normalized work and author.
		 *
		 * @var array<string, array{name:string, author:string, count:int}> $works
		 */
		$works = [];
		/**
		 * Distinct normalized authors for each work title.
		 *
		 * @var array<string, array<string, true>> $title_authors
		 */
		$title_authors = [];

		foreach ( array_map( 'intval', $query->posts ) as $post_id ) {
			$author = self::stored_text( get_metadata_raw( 'post', $post_id, '_pkiw_cite_author', true ) );
			$work   = self::stored_text( get_metadata_raw( 'post', $post_id, '_pkiw_cite_name', true ) );
			if ( '' !== $author ) {
				$key = mb_strtolower( $author );
				if ( ! isset( $authors[ $key ] ) ) {
					$authors[ $key ] = [
						'name'    => $author,
						'initial' => mb_strtoupper( mb_substr( trim( $author ), 0, 1 ) ),
						'count'   => 0,
						'ids'     => [],
					];
				}
				++$authors[ $key ]['count'];
				$authors[ $key ]['ids'][] = $post_id;
			}
			if ( '' === $work ) {
				continue;
			}

			$work_key   = mb_strtolower( $work );
			$author_key = mb_strtolower( $author );
			$pair_key   = $work_key . "\0" . $author_key;
			if ( ! isset( $works[ $pair_key ] ) ) {
				$works[ $pair_key ] = [
					'name'   => $work,
					'author' => $author,
					'count'  => 0,
				];
			}
			++$works[ $pair_key ]['count'];
			$title_authors[ $work_key ][ $author_key ] = true;
		}

		$authors        = array_values( $authors );
		$initials       = [];
		$public_authors = [];
		foreach ( $authors as $author ) {
			$initial = $author['initial'];
			if ( ! isset( $initials[ $initial ] ) ) {
				$initials[ $initial ] = [
					'count' => 0,
					'ids'   => [],
				];
			}
			$initials[ $initial ]['count'] += $author['count'];
			$initials[ $initial ]['ids']    = array_values( array_unique( array_merge( $initials[ $initial ]['ids'], $author['ids'] ) ) );
			$public_authors[]               = [
				'name'    => $author['name'],
				'initial' => $author['initial'],
				'count'   => $author['count'],
			];
		}

		$public_works = [];
		foreach ( $works as $work ) {
			$public_works[] = [
				'name'         => $work['name'],
				'author'       => $work['author'],
				'count'        => $work['count'],
				'shared_title' => count( $title_authors[ mb_strtolower( $work['name'] ) ] ?? [] ) > 1,
			];
		}
		usort(
			$public_works,
			static function ( array $a, array $b ): int {
				$by_name = strcmp( mb_strtolower( $a['name'] ), mb_strtolower( $b['name'] ) );

				return 0 !== $by_name ? $by_name : strcmp( mb_strtolower( $a['author'] ), mb_strtolower( $b['author'] ) );
			}
		);

		$index = [
			'authors'  => $public_authors,
			'initials' => $initials,
			'works'    => $public_works,
			'total'    => count( $query->posts ),
		];
		set_transient( self::INDEX_TRANSIENT, $index );

		return $index;
	}

	/**
	 * Public facts shared by quote archive entries and singles.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}

		$text       = '';
		$block_cite = '';
		$match      = first_block_named( $post, [ 'core/quote', 'core/pullquote' ] );
		if ( null !== $match ) {
			[ $text, $block_cite ] = self::quote_block_text( (array) $match['block'] );
		} else {
			$text = self::collapse_text( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
		}

		$work       = self::stored_text( get_metadata_raw( 'post', $post_id, '_pkiw_cite_name', true ) );
		$author     = self::stored_text( get_metadata_raw( 'post', $post_id, '_pkiw_cite_author', true ) );
		$stored_url = self::stored_text( get_metadata_raw( 'post', $post_id, '_pkiw_cite_url', true ) );
		$trimmed    = self::word_count( $text ) > 40;

		return [
			'text'       => $text,
			'excerpt'    => wp_trim_words( $text, 40, '…' ),
			'is_trimmed' => $trimmed,
			'work'       => $work,
			'author'     => $author,
			'url'        => web_url( $stored_url ),
			'block_cite' => $block_cite,
			'cite'       => '' !== $work ? $work : $block_cite,
			'date_iso'   => get_post_time( 'c', true, $post ),
		];
	}

	/**
	 * Register quote editor assets and dynamic blocks.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		wp_register_script(
			'pkiw-quote-index-editor',
			\PKIW_URL . 'assets/js/quote-index-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-quote-index-editor', 'post-kinds-for-indieweb-in-block-themes' );

		$style_path = \PKIW_PATH . 'styles/quote-archive.css';
		$deps       = wp_style_is( 'pkiw-kind-tokens', 'registered' ) ? [ 'pkiw-kind-tokens' ] : [];
		wp_register_style(
			'pkiw-quote-archive',
			\PKIW_URL . 'styles/quote-archive.css',
			$deps,
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : \PKIW_VERSION
		);

		Grouped_Archive::register_entry_block(
			self::ENTRY_BLOCK,
			[
				'shape'        => Grouped_Archive::ENTRY_LINE,
				'heading_ids'  => true,
				'expose_key'   => true,
				'heading_link' => [ self::class, 'author_url' ],
				'classes'      => [
					'wrapper'     => 'pkiw-quote--sectioned',
					'section'     => 'pkiw-quote-section',
					'heading'     => 'pkiw-quote-section__heading',
					'items'       => 'pkiw-quote-section__items',
					'placeholder' => 'pkiw-quote-entry pkiw-quote-entry--continued',
				],
			]
		);

		self::register_entry_block_type();
		self::register_index_block_type( self::AUTHORS_BLOCK, __( 'Quote authors', 'post-kinds-for-indieweb-in-block-themes' ), [ self::class, 'render_authors_block' ], __( 'By author', 'post-kinds-for-indieweb-in-block-themes' ) );
		self::register_index_block_type( self::WORKS_BLOCK, __( 'Quote source works', 'post-kinds-for-indieweb-in-block-themes' ), [ self::class, 'render_works_block' ], __( 'By source work', 'post-kinds-for-indieweb-in-block-themes' ), true );
	}

	/**
	 * URL for an author group heading.
	 *
	 * @param Archive_Group $group Author group.
	 * @return string
	 */
	public static function author_url( Archive_Group $group ): string {
		if ( $group->is_empty() ) {
			return '';
		}
		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			return '';
		}

		return add_query_arg( self::VAR_AUTHOR, rawurlencode( $group->raw() ), $link );
	}

	/**
	 * Render the quote entry block through the grouped archive engine.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string
	 */
	public static function render_entry_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Grouped_Archive::render_entry(
			self::ENTRY_BLOCK,
			$attributes,
			$block,
			static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::entry_html( $post ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		);
	}

	/**
	 * One quote archive card.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function entry_html( \WP_Post $post ): string {
		$protected = post_password_required( $post );
		$date_iso  = get_post_time( 'c', true, $post );
		if ( $protected ) {
			$titled = '' !== trim( (string) $post->post_title );
			$name   = $titled ? visitor_post_title( $post ) : untitled_name( $post, false );
			$html   = '<div class="pkiw-quote-entry pkiw-quote-entry--protected h-entry">';
			$html  .= '<a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $name ) . '</a>';
			$html  .= '<time class="dt-published" datetime="' . esc_attr( $date_iso ) . '" hidden></time>';

			return $html . '</div>';
		}

		$facts    = self::facts( $post->ID );
		$text_id  = 'pkiw-quote-' . $post->ID . '-text';
		$cite_id  = 'pkiw-quote-' . $post->ID . '-cite';
		$cite     = (string) ( $facts['cite'] ?? '' );
		$labelled = $text_id . ( '' !== $cite ? ' ' . $cite_id : '' );

		$html  = '<div class="pkiw-quote-entry h-entry">';
		$html .= '<blockquote class="pkiw-quote-entry__text ' . ( ! empty( $facts['is_trimmed'] ) ? 'p-summary' : 'e-content' ) . '" id="' . esc_attr( $text_id ) . '"><p>' . esc_html( (string) ( $facts['excerpt'] ?? '' ) ) . '</p></blockquote>';
		if ( '' !== $cite ) {
			$html .= '<cite class="pkiw-quote-entry__cite" id="' . esc_attr( $cite_id ) . '">' . esc_html( $cite ) . '</cite>';
		}
		$html .= '<a class="u-url pkiw-quote-entry__link" href="' . esc_url( (string) get_permalink( $post ) ) . '" aria-labelledby="' . esc_attr( $labelled ) . '"></a>';
		$html .= '<time class="dt-published" datetime="' . esc_attr( (string) ( $facts['date_iso'] ?? $date_iso ) ) . '" hidden></time>';

		$author_html = function_exists( __NAMESPACE__ . '\\entry_author_html' ) ? entry_author_html( $post ) : '';
		if ( '' !== $author_html ) {
			$html .= '<span hidden>' . $author_html . '</span>';
		}

		$work   = (string) ( $facts['work'] ?? '' );
		$author = (string) ( $facts['author'] ?? '' );
		$url    = (string) ( $facts['url'] ?? '' );
		if ( '' !== $work || '' !== $author || '' !== $url ) {
			$html .= '<span class="u-quotation-of h-cite" hidden>';
			$html .= '' !== $work ? '<span class="p-name">' . esc_html( $work ) . '</span>' : '';
			$html .= '' !== $author ? '<span class="p-author">' . esc_html( $author ) . '</span>' : '';
			$html .= '' !== $url ? '<data class="u-url" value="' . esc_url( $url ) . '"></data>' : '';
			$html .= '</span>';
		}

		return $html . '</div>';
	}

	/**
	 * Render author-initial tabs.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_authors_block( array $attributes = [] ): string {
		$heading = self::attribute_text( $attributes, 'heading', __( 'By author', 'post-kinds-for-indieweb-in-block-themes' ) );
		$level   = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$html    = '<div class="pkiw-quote-authors"><h' . $level . ' id="pkiw-quote-by-author" class="pkiw-quote-authors__heading">' . esc_html( $heading ) . '</h' . $level . '>';
		$index   = self::index();
		if ( [] === $index['initials'] ) {
			return $html . '</div>';
		}

		$base = self::archive_url();
		if ( '' === $base ) {
			return $html . '</div>';
		}
		// The editor preview shows the archive as it opens, so no filter is active there.
		$preview = Grouped_Archive::is_block_preview();
		$author  = $preview ? '' : self::current_query_value( self::VAR_AUTHOR );
		$work    = $preview ? '' : self::current_query_value( self::VAR_WORK );
		$initial = $preview ? '' : mb_strtoupper( mb_substr( self::current_query_value( self::VAR_INITIAL ), 0, 1 ) );
		$all     = '' === $author && '' === $work && '' === $initial;

		$html .= '<nav class="pkiw-quote-tabs" aria-labelledby="pkiw-quote-by-author"><ul>';
		$html .= '<li><a href="' . esc_url( $base ) . '"' . ( $all ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'All', 'post-kinds-for-indieweb-in-block-themes' ) . '</a></li>';
		foreach ( $index['initials'] as $letter => $data ) {
			$url   = add_query_arg( self::VAR_INITIAL, rawurlencode( (string) $letter ), $base );
			$html .= '<li><a href="' . esc_url( $url ) . '"' . ( $letter === $initial ? ' aria-current="page"' : '' ) . '>' . esc_html( $letter ) . '</a></li>';
		}

		return $html . '</ul></nav></div>';
	}

	/**
	 * Render the source-work index.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_works_block( array $attributes = [] ): string {
		$works = self::index()['works'];
		if ( [] === $works ) {
			return '';
		}
		$base = self::archive_url();
		if ( '' === $base ) {
			return '';
		}

		$heading       = self::attribute_text( $attributes, 'heading', __( 'By source work', 'post-kinds-for-indieweb-in-block-themes' ) );
		$level         = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$max           = max( 0, (int) ( $attributes['twoPageMax'] ?? 16 ) );
		$active_work   = self::current_query_value( self::VAR_WORK );
		$active_author = self::current_query_value( self::VAR_AUTHOR );
		$html          = '<section class="pkiw-quote-works' . ( count( $works ) > $max ? ' is-long' : '' ) . '" data-pkiw-works="' . count( $works ) . '">';
		$html         .= '<h' . $level . ' id="pkiw-quote-by-work">' . esc_html( $heading ) . '</h' . $level . '><nav aria-labelledby="pkiw-quote-by-work"><ul>';
		foreach ( $works as $at => $work ) {
			$args = [ self::VAR_WORK => rawurlencode( $work['name'] ) ];
			if ( $work['shared_title'] ) {
				$args[ self::VAR_AUTHOR ] = rawurlencode( $work['author'] );
			}
			$url       = add_query_arg( $args, $base );
			$author_id = 'pkiw-quote-work-author-' . ( $at + 1 );
			$current   = 0 === strcasecmp( $active_work, $work['name'] ) && ( ! $work['shared_title'] || 0 === strcasecmp( $active_author, $work['author'] ) );
			$count     = (int) $work['count'];
			/* translators: %s: number of quotations. */
			$count_text = sprintf( _n( '%s quote', '%s quotes', $count, 'post-kinds-for-indieweb-in-block-themes' ), number_format_i18n( $count ) );

			$html .= '<li class="pkiw-quote-work" data-pkiw-work="' . esc_attr( sanitize_title( $work['name'] ) ) . '">';
			$html .= '<a class="pkiw-quote-work__title" href="' . esc_url( $url ) . '"' . ( $work['shared_title'] && '' !== $work['author'] ? ' aria-describedby="' . esc_attr( $author_id ) . '"' : '' ) . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $work['name'] ) . '</a>';
			if ( '' !== $work['author'] ) {
				$html .= '<span class="pkiw-quote-work__author" id="' . esc_attr( $author_id ) . '">' . esc_html( $work['author'] ) . '</span>';
			}
			$html .= '<span class="pkiw-quote-work__count">' . esc_html( $count_text ) . '</span></li>';
		}

		return $html . '</ul></nav></section>';
	}

	/** Delete the cached aggregate. */
	public static function flush_index(): void {
		delete_transient( self::INDEX_TRANSIENT );
	}

	/**
	 * Invalidate when kind terms change.
	 *
	 * @param int    $object_id  Object ID.
	 * @param mixed  $terms      Terms.
	 * @param int[]  $tt_ids     Term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append flag.
	 * @param int[]  $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public static function terms_changed( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Shared hook signature.
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::flush_index();
		}
	}

	/**
	 * Invalidate when kind relationships are deleted.
	 *
	 * @param int    $object_id Object ID.
	 * @param int[]  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy.
	 * @return void
	 */
	public static function term_relationships_deleted( int $object_id, array $tt_ids, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Shared hook signature.
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::flush_index();
		}
	}

	/**
	 * Invalidate for citation metadata only.
	 *
	 * @param mixed  $meta_id    Meta row ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function meta_changed( $meta_id, int $object_id, string $meta_key, $meta_value = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Three metadata hooks share this signature.
		if ( in_array( $meta_key, self::CITE_KEYS, true ) ) {
			self::flush_index();
		}
	}

	/** Register the quote entry block type. */
	private static function register_entry_block_type(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY_BLOCK ) ) {
			return;
		}
		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Quote entry', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render_entry_block' ],
			'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			'attributes'      => Grouped_Archive::entry_attributes(
				[
					'linesPerPage' => [ 'default' => self::PER_PAGE ],
					'headingLevel' => [ 'default' => 3 ],
					'emptyLabel'   => [ 'role' => 'content' ],
				]
			),
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-quote-index-editor',
			'style'           => 'pkiw-quote-archive',
		];
		register_block_type( self::ENTRY_BLOCK, $args );
	}

	/**
	 * Register one quote index block type.
	 *
	 * @param string   $name       Block name.
	 * @param string   $title      Block title.
	 * @param callable $callback   Render callback.
	 * @param string   $heading    Default heading.
	 * @param bool     $with_limit Add the works length threshold.
	 * @return void
	 */
	private static function register_index_block_type( string $name, string $title, callable $callback, string $heading, bool $with_limit = false ): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			return;
		}
		$attributes = [
			'heading'      => [
				'type'    => 'string',
				'default' => $heading,
				'role'    => 'content',
			],
			'headingLevel' => [
				'type'    => 'integer',
				'default' => 2,
			],
		];
		if ( $with_limit ) {
			$attributes['twoPageMax'] = [
				'type'    => 'integer',
				'default' => 16,
			];
		}
		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => $title,
			'category'        => 'post-kinds-indieweb',
			'render_callback' => $callback,
			'attributes'      => $attributes,
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-quote-index-editor',
			'style'           => 'pkiw-quote-archive',
		];
		register_block_type( $name, $args );
	}

	/**
	 * Rebuild a parsed block's inner HTML with nested blocks in place.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	private static function block_inner_html( array $block ): string {
		$html   = '';
		$inside = array_values( array_filter( (array) ( $block['innerBlocks'] ?? [] ), 'is_array' ) );
		$at     = 0;
		foreach ( (array) ( $block['innerContent'] ?? [] ) as $fragment ) {
			if ( is_string( $fragment ) ) {
				$html .= $fragment;
			} elseif ( isset( $inside[ $at ] ) ) {
				$html .= self::block_inner_html( $inside[ $at ] );
				++$at;
			}
		}

		return '' !== $html ? $html : (string) ( $block['innerHTML'] ?? '' );
	}

	/**
	 * Extract quote text and cite text from stored block HTML.
	 *
	 * @param array<string, mixed> $block Parsed quote block.
	 * @return array{string, string}
	 */
	private static function quote_block_text( array $block ): array {
		$processor = new \WP_HTML_Tag_Processor( self::block_inner_html( $block ) );
		$text      = [];
		$cite      = [];
		$in_cite   = 0;
		while ( $processor->next_token() ) {
			$name = $processor->get_token_name();
			if ( 'CITE' === $name ) {
				$in_cite += $processor->is_tag_closer() ? -1 : 1;
				$in_cite  = max( 0, $in_cite );
				continue;
			}
			if ( '#text' !== $name ) {
				continue;
			}
			$value = $processor->get_modifiable_text();
			if ( $in_cite > 0 ) {
				$cite[] = $value;
			} else {
				$text[] = $value;
			}
		}

		return [ self::collapse_text( implode( ' ', $text ) ), self::collapse_text( implode( ' ', $cite ) ) ];
	}

	/**
	 * Normalize plain text without changing display spelling.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function collapse_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	/**
	 * Convert a raw first metadata row to display text.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return string
	 */
	private static function stored_text( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value, ' ' ) : '';
	}

	/**
	 * Count whitespace-separated words in normalized plain text.
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	private static function word_count( string $text ): int {
		$words = preg_split( '/[\s\x{00A0}]+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Sanitize a value read from WP_Query.
	 *
	 * @param mixed $value Query value.
	 * @return string
	 */
	private static function sanitize_query_value( $value ): string {
		return sanitize_text_field( wp_unslash( is_scalar( $value ) ? (string) $value : '' ) );
	}

	/**
	 * Read one public query value from the main query.
	 *
	 * @param string $name Query variable name.
	 * @return string
	 */
	private static function current_query_value( string $name ): string {
		global $wp_query;

		return $wp_query instanceof \WP_Query ? self::sanitize_query_value( $wp_query->get( $name ) ) : '';
	}

	/** Resolve the quote archive URL. */
	private static function archive_url(): string {
		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$link = get_term_link( $term );

		return is_wp_error( $link ) ? '' : $link;
	}

	/**
	 * Read a block heading attribute as plain text.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $key        Attribute key.
	 * @param string               $fallback   Default heading.
	 * @return string
	 */
	private static function attribute_text( array $attributes, string $key, string $fallback ): string {
		$value = sanitize_text_field( (string) ( $attributes[ $key ] ?? $fallback ) );

		return '' !== $value ? $value : $fallback;
	}
}
