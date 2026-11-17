$page_id = 37724;
$fail = '';
$writes = 0;
$guard = static function ( $query ) use ( &$writes ) {
	if ( ! preg_match( '/^\s*(SELECT|SHOW)\b/i', (string) $query ) ) {
		++$writes;
		return '';
	}
	return $query;
};
/*X269:0*/ add_filter( 'query', $guard, PHP_INT_MIN ); /*X269:/0*/

/*X269:1*/
if ( ! class_exists( '\PKIW\Zine_Manifest' ) || ! class_exists( '\PKIW\Zine_Request' ) || ! WP_Block_Type_Registry::get_instance()->is_registered( 'post-kinds-indieweb/zine-export' ) ) {
	$fail = 'seam_missing:zine';
}
/*X269:/1*/

/*X269:2*/
if ( '' === $fail ) {
	\PKIW\Zine_Manifest::override_narrowed_visibility_for_tests( [] );
	$query_a = new WP_Query( \PKIW\Public_Listing::query_args( [ 'fields' => 'ids', 'posts_per_page' => 1, 'pkiw_zine_narrow_visibility' => true ] ) );
	\PKIW\Zine_Manifest::override_narrowed_visibility_for_tests( [ 'private' ] );
	$query_b = new WP_Query( \PKIW\Public_Listing::query_args( [ 'fields' => 'ids', 'posts_per_page' => 1, 'pkiw_zine_narrow_visibility' => true ] ) );
	\PKIW\Zine_Manifest::override_narrowed_visibility_for_tests( null );
	$sql = (string) $query_a->request;
	$sql_b = (string) $query_b->request;
	foreach ( \PKIW\Public_Listing::meta_keys() as $key ) {
		if ( ! str_contains( $sql, $key ) || ! str_contains( $sql_b, $key ) ) {
			$fail = 'query_unfiltered';
		}
	}
	if ( ! str_contains( $sql, 'NOT EXISTS' ) || ! str_contains( $sql, 'quiet_public' ) || str_contains( $sql, 'private' ) || ! str_contains( $sql, "post_status = 'publish'" ) || ! str_contains( $sql, "post_password = ''" ) || ! str_contains( $sql_b, 'quiet_public' ) || ! str_contains( $sql_b, 'private' ) ) {
		$fail = 'query_unfiltered';
	}
}
/*X269:/2*/

/*X269:3*/
if ( '' === $fail && get_post( $page_id ) instanceof WP_Post ) {
	$prior = $GLOBALS['wp_query'] ?? null;
	try {
		$GLOBALS['wp_query'] = new WP_Query( [ 'page_id' => $page_id ] );
		$front = \PKIW\Zine_Manifest::stream_query_vars( get_post( $page_id ) );
		$GLOBALS['wp_query'] = new WP_Query();
		$empty = \PKIW\Zine_Manifest::stream_query_vars( get_post( $page_id ) );
		if ( $front !== $empty ) {
			$fail = 'stream_parity';
		}
	} finally {
		$GLOBALS['wp_query'] = $prior;
	}
}
/*X269:/3*/

/*X269:4*/
if ( '' === $fail && get_post( $page_id ) instanceof WP_Post && \PKIW\Zine_Manifest::has_stream_block( get_post( $page_id ) ) ) {
	$prior = $GLOBALS['wp_query'] ?? null;
	try {
		$GLOBALS['wp_query'] = new WP_Query( [ 'page_id' => $page_id ] );
		$a = \PKIW\Zine_Manifest::build( [ 'source' => 'stream', 'page_id' => $page_id, 'pages' => 8 ] );
		$GLOBALS['wp_query'] = new WP_Query();
		$b = \PKIW\Zine_Manifest::build( [ 'source' => 'stream', 'page_id' => $page_id, 'pages' => 8 ] );
		if ( is_wp_error( $a ) || is_wp_error( $b ) || $a['included'] !== $b['included'] ) {
			$fail = 'stream_parity_rows';
		}
	} finally {
		$GLOBALS['wp_query'] = $prior;
	}
}
/*X269:/4*/

/*X269:5*/
if ( '' === $fail ) {
	global $wpdb;
	$keys = \PKIW\Public_Listing::meta_keys();
	$key_marks = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
	$terms = get_terms( [ 'taxonomy' => \PKIW\Taxonomy::TAXONOMY, 'hide_empty' => true ] );
	if ( is_array( $terms ) ) {
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || in_array( $term->slug, \PKIW\Zine_Manifest::excluded_kinds(), true ) ) {
				continue;
			}
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE p.post_status = %s AND p.post_password = %s AND tt.taxonomy = %s AND t.slug = %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ( {$key_marks} ) AND m.meta_value = %s )",
				array_merge( [ 'publish', '', \PKIW\Taxonomy::TAXONOMY, $term->slug ], $keys, [ 'quiet_public' ] )
			);
			$oracle = \PKIW\Public_Listing::filter_ids( array_map( 'intval', $wpdb->get_col( $sql ) ) );
			$oracle = array_values( array_filter( $oracle, [ \PKIW\Zine_Manifest::class, 'passes_narrowing' ] ) );
			$excluded = \PKIW\Zine_Manifest::excluded_kinds();
			$oracle = array_values( array_filter( $oracle, static fn( int $id ): bool => [] === array_intersect( $excluded, wp_get_object_terms( $id, \PKIW\Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ) ) ) );
			$actual = \PKIW\Zine_Manifest::build( [ 'source' => 'archive', 'kind' => $term->slug, 'pages' => 16 ] );
			if ( is_wp_error( $actual ) || count( $oracle ) !== $actual['total'] || array_diff( $actual['included'], $oracle ) ) {
				$fail = 'rows_real:' . implode( ',', array_map( 'intval', is_array( $actual ) ? $actual['included'] : [] ) );
				break;
			}
			foreach ( $actual['included'] as $id ) {
				if ( ! \PKIW\Public_Listing::is_listable( $id ) || ! \PKIW\Zine_Manifest::passes_narrowing( $id ) ) {
					$fail = 'rows_real:' . $id;
					break 2;
				}
			}
		}
	}
}
/*X269:/5*/

remove_filter( 'query', $guard, PHP_INT_MIN );
if ( $writes > 0 ) {
	$fail = 'write_attempted:' . $writes;
}
echo '' === $fail ? "RESULT PASS\n" : 'RESULT FAIL ' . $fail . "\n";
