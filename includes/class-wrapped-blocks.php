<?php
/**
 * Wrapped blocks and binding source.
 *
 * @package PKIW
 */

declare(strict_types=1);
namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Render Wrapped surfaces from the shared stats contract. */
final class Wrapped_Blocks {
	// Public callback signatures document their input types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag
	/** Register the binding source. */
	public function register(): void {
		add_action( 'init', [ self::class, 'register_binding_source' ] );
	}

	/** Return whether the current render may contain on-site Wrapped data. */
	public static function is_on_site_render(): bool {
		$rest = wp_is_serving_rest_request() || ( isset( $GLOBALS['wp_rest_server'] ) && $GLOBALS['wp_rest_server'] instanceof \WP_REST_Server && $GLOBALS['wp_rest_server']->is_dispatching() );
		return ! is_feed() && ! ( function_exists( '\\Activitypub\\is_activitypub_request' ) && \Activitypub\is_activitypub_request() ) && ! Title_Privacy::is_saving() && ! self::in_status_transition() && ! wp_doing_cron() && ! ( $rest && ! Grouped_Archive::is_block_preview() ) && null === get_query_var( 'atproto', null ) && '' === (string) get_query_var( 'markdown_request', '' ) && 'markdown' !== get_query_var( 'format', '' );
	}

	/**
	 * Whether a post status transition's own actions are running.
	 *
	 * Title_Privacy::is_saving() counts transition_post_status, but core then fires
	 * {old}_to_{new} and {new}_post as separate actions (wp_transition_post_status()),
	 * where syndication code can render the post outside any counted save hook.
	 */
	private static function in_status_transition(): bool {
		global $wp_current_filter;
		if ( empty( $wp_current_filter ) ) {
			return false;
		}
		$stati = array_merge( [ 'new' ], array_keys( get_post_stati() ) );
		foreach ( $wp_current_filter as $hook ) {
			foreach ( $stati as $status ) {
				if ( $status . '_post' === $hook ) {
					return true;
				}
				if ( str_starts_with( (string) $hook, $status . '_to_' ) && in_array( substr( (string) $hook, strlen( $status ) + 4 ), $stati, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** Render the main Wrapped block. */
	public static function render_wrapped( array $attributes, \WP_Block $block ): string {
		$host = (int) ( $block->context['postId'] ?? get_the_ID() );
		if ( ! self::is_on_site_render() || '' === (string) get_post_meta( $host, Wrapped_Post::KIND_META, true ) || ! Public_Listing::is_listable( $host ) ) {
			return Grouped_Archive::is_block_preview() ? '<p>' . esc_html__( 'Numbers show once this post is published and public.', 'post-kinds-for-indieweb-in-block-themes' ) . '</p>' : '';
		}
		$kind = sanitize_key( (string) ( $attributes['kind'] ?? '' ) );
		$year = isset( $attributes['year'] ) && is_int( $attributes['year'] ) ? $attributes['year'] : 0;
		$data = Wrapped_Stats::for_kind_year( $kind, $year );
		if ( [] === $data ) {
			return Grouped_Archive::is_block_preview() ? '<p>' . esc_html__( 'Numbers show once this post is published and public.', 'post-kinds-for-indieweb-in-block-themes' ) . '</p>' : '';
		}
		$wanted  = is_array( $attributes['metrics'] ?? null ) ? $attributes['metrics'] : [];
		$metrics = Wrapped_Stats::ordered_metrics( $data['metrics'], $wanted );
		$html    = '<section ' . get_block_wrapper_attributes( [ 'class' => 'pkiw-wrapped' ] ) . '><ul class="pkiw-wrapped__metrics">';
		foreach ( $metrics as $metric ) {
			$html .= '<li><span class="pkiw-wrapped__value">' . esc_html( $metric['display'] ) . '</span> <span class="pkiw-wrapped__label">' . esc_html( $metric['label'] ) . '</span></li>';
		}
		$html     .= '</ul>';
		$top_count = min( Wrapped_Stats::TOP_MAX, max( 0, (int) ( $attributes['topCount'] ?? 3 ) ) );
		$top       = array_values( array_filter( $data['top'], static fn( array $row ): bool => Public_Listing::is_listable( (int) $row['post_id'] ) ) );
		if ( 'read' === $kind && $top_count && $top ) {
			$level   = min( 4, max( 2, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
			$heading = (string) ( $attributes['heading'] ?? '' );
			if ( '' === $heading ) {
				$heading = __( 'Top reads', 'post-kinds-for-indieweb-in-block-themes' );
			}
			$html .= '<h' . $level . '>' . esc_html( $heading ) . '</h' . $level . '><ul class="pkiw-wrapped__top">';
			foreach ( array_slice( $top, 0, $top_count ) as $row ) {
				$id     = (int) $row['post_id'];
				$cover  = (string) get_post_meta( $id, '_pkiw_read_cover', true );
				$author = (string) get_post_meta( $id, '_pkiw_read_author', true );
				$stars  = card_star_counts( (float) $row['rating'] );
				$rating = card_rating_label( (float) $row['rating'] );
				$html  .= '<li>';
				if ( '' !== $cover ) {
					$html .= '<img src="' . esc_url( $cover ) . '" alt="">';
				}
				$html .= '<a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
				if ( '' !== $author ) {
					$html .= '<span>' . esc_html( $author ) . '</span>';
				}
				if ( (float) $row['rating'] > 0 ) {
						/* translators: 1: rating value, 2: maximum rating. */
					$html .= '<span aria-hidden="true">' . esc_html( str_repeat( '★', $stars['full'] ) . str_repeat( '☆', $stars['empty'] ) ) . '</span><span>' . esc_html( $rating ) . '</span>';
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}

		return $html . '</section>';
	}

	/** Render the archive strip without touching the main query. */
	public static function render_strip( array $attributes, string $content ): string {
		if ( ! self::is_on_site_render() || is_paged() || ( isset( $attributes['year'] ) && ( ! is_int( $attributes['year'] ) || $attributes['year'] < 2000 || $attributes['year'] > (int) wp_date( 'Y' ) ) ) ) {
			return '';
		}
		$kind = sanitize_key( (string) ( $attributes['kind'] ?? '' ) );
		if ( '' === $kind && is_tax( 'kind' ) ) {
			$term = get_queried_object();
			$kind = $term instanceof \WP_Term ? $term->slug : '';
		}
		if ( '' === $kind ) {
			return '';
		}
		$post = Wrapped_Post::latest( $kind, isset( $attributes['year'] ) ? (int) $attributes['year'] : null );
		if ( ! $post ) {
			return '';
		}
		if ( '' !== trim( $content ) ) {
			return '<section ' . get_block_wrapper_attributes( [ 'class' => 'pkiw-wrapped-strip' ] ) . '>' . $content . '</section>';
		}
		$facts = wrapped_facts( $post->ID );
		return '<section ' . get_block_wrapper_attributes( [ 'class' => 'pkiw-wrapped-strip' ] ) . '><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a><p class="pkiw-wrapped-strip__count">' . esc_html( (string) ( $facts['count_line'] ?? '' ) ) . '</p></section>';
	}

	/** Register the server-side Block Bindings source. */
	public static function register_binding_source(): void {
		if ( null !== get_block_bindings_source( 'post-kinds/wrapped' ) ) {
			return;
		}

		register_block_bindings_source(
			'post-kinds/wrapped',
			[
				'label'              => __( 'Wrapped', 'post-kinds-for-indieweb-in-block-themes' ),
				'get_value_callback' => [ self::class, 'binding_value' ],
				'uses_context'       => [ 'postId', 'postType', 'post-kinds-indieweb/wrappedKind', 'post-kinds-indieweb/wrappedYear' ],
			]
		);
	}

	/** Resolve one binding value from the first matching target. */
	public static function binding_value( array $args, \WP_Block $block, string $attribute_name ) {
		$key = (string) ( $args['key'] ?? '' );
		if ( ! self::is_on_site_render() ) {
			return '';
		}
		$post = null;
		if ( isset( $args['post_id'] ) && get_post_meta( (int) $args['post_id'], Wrapped_Post::KIND_META, true ) ) {
			$post = get_post( (int) $args['post_id'] );
		} elseif ( ! empty( $args['kind'] ) ) {
			$post = Wrapped_Post::latest( sanitize_key( (string) $args['kind'] ), isset( $args['year'] ) ? (int) $args['year'] : null );
		} elseif ( ! empty( $block->context['post-kinds-indieweb/wrappedKind'] ) ) {
			$post = Wrapped_Post::latest( sanitize_key( (string) $block->context['post-kinds-indieweb/wrappedKind'] ), isset( $block->context['post-kinds-indieweb/wrappedYear'] ) ? (int) $block->context['post-kinds-indieweb/wrappedYear'] : null );
		} elseif ( ! empty( $block->context['postId'] ) && get_post_meta( (int) $block->context['postId'], Wrapped_Post::KIND_META, true ) ) {
			$post = get_post( (int) $block->context['postId'] );
		} elseif ( is_tax( 'kind' ) && ! is_paged() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$post = Wrapped_Post::latest( $term->slug, isset( $block->context['post-kinds-indieweb/wrappedYear'] ) ? (int) $block->context['post-kinds-indieweb/wrappedYear'] : null );
			}
		}
		if ( ! $post instanceof \WP_Post || ! Public_Listing::is_listable( $post ) ) {
			return '';
		}
		$facts = wrapped_facts( $post->ID );
		if ( [] === $facts ) {
			return '';
		}
		if ( 'title' === $key ) {
			return esc_html( get_the_title( $post ) );
		}
		if ( 'url' === $key ) {
			return esc_url_raw( get_permalink( $post ) );
		}
		if ( 'year' === $key ) {
			return (string) $facts['year'];
		}
		if ( 'kind_label' === $key ) {
			return (string) $facts['kind_label'];
		}
		if ( 'count_line' === $key ) {
			return (string) $facts['count_line'];
		}
		$label      = str_starts_with( $key, 'label:' );
		$metric_key = $label ? substr( $key, 6 ) : $key;
		foreach ( $facts['metrics'] as $metric ) {
			if ( $metric_key === $metric['key'] ) {
				return (string) $metric[ $label ? 'label' : 'display' ];
			}
		}
		$known = [ 'finished', 'pages', 'streak', 'average_rating', 'listens', 'artists', 'watched', 'rewatched', 'drawn', 'completed', 'hours', 'duration', 'posts' ];

		return in_array( $metric_key, $known, true ) ? '' : null;
	}
}
