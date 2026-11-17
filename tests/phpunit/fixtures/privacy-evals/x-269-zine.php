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
	$kind_terms = get_terms( [ 'taxonomy' => \PKIW\Taxonomy::TAXONOMY, 'hide_empty' => false, 'orderby' => 'term_id' ] );
	$probe_slug = '';
	foreach ( is_array( $kind_terms ) ? $kind_terms : [] as $kind_term ) {
		if ( $kind_term instanceof WP_Term && ! in_array( $kind_term->slug, \PKIW\Zine_Manifest::excluded_kinds(), true ) ) {
			$probe_slug = $kind_term->slug;
			break;
		}
	}
	$captured = [];
	$capture  = static function ( $sql, $query ) use ( &$captured ) {
		if ( $query instanceof WP_Query && true === $query->get( 'pkiw_public_listing' ) ) {
			$captured[] = (string) $sql;
		}
		return $sql;
	};
	add_filter( 'posts_request', $capture, PHP_INT_MAX, 2 );
	try {
		\PKIW\Zine_Manifest::build( [ 'source' => 'archive', 'kind' => $probe_slug, 'pages' => 8 ] );
	} finally {
		remove_filter( 'posts_request', $capture, PHP_INT_MAX );
	}
	$sql    = (string) ( $captured[0] ?? '' );
	$narrow = \PKIW\Zine_Manifest::narrowed_visibility();
	$checks = [ "post_status = 'publish'", "post_password = ''", 'NOT EXISTS', "'quiet_public'" ];
	foreach ( \PKIW\Public_Eligibility::KINDS as $governed_slug ) {
		// The forced governed-kind clause is empty until a governed kind term exists.
		if ( term_exists( $governed_slug, \PKIW\Taxonomy::TAXONOMY ) ) {
			$checks[] = 'pkiw_public_tr';
		}
	}
	foreach ( \PKIW\Public_Listing::meta_keys() as $key ) {
		$checks[] = "'" . $key . "'";
	}
	foreach ( $narrow as $value ) {
		$checks[] = "'" . $value . "'";
	}
	foreach ( $checks as $needle ) {
		if ( '' === $probe_slug || ! str_contains( $sql, $needle ) ) {
			$fail = 'query_unfiltered';
		}
	}
	if ( [] === $narrow && str_contains( $sql, "'private'" ) ) {
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
		} else {
			foreach ( $a['included'] as $id ) {
				if ( ! \PKIW\Public_Listing::is_listable( $id ) ) {
					$fail = 'stream_parity_rows:' . (int) $id;
					break;
				}
			}
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
	$terms    = get_terms( [ 'taxonomy' => \PKIW\Taxonomy::TAXONOMY, 'hide_empty' => true ] );
	$admins   = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] );
	$admin_id = (int) ( $admins[0] ?? 0 );
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
			// Render the first two entries per kind as user 0 and as the first administrator (bounded for the gate's eval timeout).
			if ( 0 === $admin_id && [] !== $actual['entries'] ) {
				$fail = 'rows_real:no_admin';
				break;
			}
			foreach ( array_slice( $actual['entries'], 0, 2 ) as $entry ) {
				$saved_user = get_current_user_id();
				try {
					wp_set_current_user( 0 );
					$anonymous = \PKIW\Zine_Export::render_entry( $entry, (int) $entry['page'] );
					wp_set_current_user( $admin_id );
					$privileged = \PKIW\Zine_Export::render_entry( $entry, (int) $entry['page'] );
				} finally {
					wp_set_current_user( $saved_user );
				}
				$leak = $anonymous !== $privileged;
				foreach ( [ '<iframe', '<script', '<video', '<audio', 'leaflet' ] as $needle ) {
					$leak = $leak || false !== stripos( $anonymous, $needle );
				}
				$view = \PKIW\Meta_Fields::get_public_location_view( (int) $entry['id'] );
				if ( empty( $view['visible']['coordinates'] ) ) {
					foreach ( [ '_pkiw_geo_latitude', '_pkiw_geo_longitude', '_pkiw_drink_geo_latitude', '_pkiw_drink_geo_longitude', '_pkiw_eat_geo_latitude', '_pkiw_eat_geo_longitude', 'geo_latitude', 'geo_longitude' ] as $coordinate_key ) {
						$coordinate = trim( (string) get_post_meta( (int) $entry['id'], $coordinate_key, true ) );
						$leak       = $leak || ( strlen( $coordinate ) >= 4 && str_contains( $anonymous, $coordinate ) );
					}
				}
				if ( $leak ) {
					$fail = 'rows_real:' . (int) $entry['id'];
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
