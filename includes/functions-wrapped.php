<?php
/**
 * Wrapped theme facts.
 *
 * @package PKIW
 */

declare(strict_types=1);
namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return display-ready facts for one listable Wrapped post.
 *
 * @param int $post_id Post ID.
 */
function wrapped_facts( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! Wrapped_Blocks::is_on_site_render() || 'publish' !== $post->post_status || '' !== $post->post_password || ! Public_Listing::is_listable( $post ) ) {
		return [];
	}
	$kind = (string) get_post_meta( $post_id, Wrapped_Post::KIND_META, true );
	$year = (int) get_post_meta( $post_id, Wrapped_Post::YEAR_META, true );
	$data = Wrapped_Stats::for_kind_year( $kind, $year );
	if ( empty( $data['metrics'] ) ) {
		return [];
	}
	$block   = Wrapped_Post::find_block( parse_blocks( $post->post_content ) );
	$wanted  = is_array( $block['attrs']['metrics'] ?? null ) ? $block['attrs']['metrics'] : [];
	$metrics = Wrapped_Stats::ordered_metrics( $data['metrics'], $wanted );
	$term    = get_term_by( 'slug', $kind, 'kind' );
	$line    = static fn( array $items ): string => implode( ' · ', array_map( static fn( array $m ): string => $m['display'] . ' ' . $m['label'], $items ) );
	return [
		'kind'         => $kind,
		'kind_label'   => $term instanceof \WP_Term ? $term->name : '',
		'year'         => $year,
		'title'        => get_the_title( $post ),
		'permalink'    => get_permalink( $post ),
		'metrics'      => $metrics,
		'count_line'   => $line( array_slice( $metrics, 0, 2 ) ),
		'card_metrics' => array_slice( $metrics, 0, 3 ),
	];
}
