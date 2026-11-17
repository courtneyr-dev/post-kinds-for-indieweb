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
/*N4K2:0*/ add_filter( 'query', $guard, PHP_INT_MIN ); /*N4K2:/0*/

/*N4K2:1*/
$ability = null;
if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'post-kinds/zine-manifest' ) ) {
	$fail = 'seam_missing:post-kinds/zine-manifest';
} else {
	$ability = wp_get_ability( 'post-kinds/zine-manifest' );
	$meta = $ability instanceof WP_Ability ? $ability->get_meta() : [];
	if (
		! $ability instanceof WP_Ability
		|| ! in_array( 'post-kinds/zine-manifest', \PKIW\Abilities_Manager::MCP_PUBLIC_ABILITIES, true )
		|| ! in_array( 'post-kinds/zine-manifest', \PKIW\Abilities_Manager::get_ability_names(), true )
		|| true !== ( $meta['mcp']['public'] ?? false )
	) {
		$fail = 'seam_missing:post-kinds/zine-manifest';
	}
}
/*N4K2:/1*/

/*N4K2:2*/
$run = null;
if ( '' === $fail ) {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] );
	$admin_id = (int) ( $admins[0] ?? 0 );
	if ( 0 === $admin_id ) {
		$readers  = get_users( [ 'capability' => 'read', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] );
		$admin_id = (int) ( $readers[0] ?? 0 );
	}
	// With nobody able to call the ability, cases 2 to 4 have nothing to run; cases 0 and 1 still ran.
	if ( 0 !== $admin_id ) {
		$run = static function ( array $input ) use ( $ability, $admin_id ) {
			$prior_user = get_current_user_id();
			try {
				wp_set_current_user( $admin_id );
				return $ability->execute( $input );
			} finally {
				wp_set_current_user( $prior_user );
			}
		};
		$unknown = $run( [ 'source' => 'archive', 'kind' => 'n4-k2-unknown-kind', 'pages' => 8 ] );
		$missing = $run( [ 'source' => 'stream', 'page_id' => 987654321, 'pages' => 8 ] );
		$error_shape = static function ( $value ): array {
			if ( ! is_wp_error( $value ) ) {
				return [ '', '', null ];
			}
			return [ $value->get_error_code(), $value->get_error_message(), $value->get_error_data() ];
		};
		if ( [ 'not_found', 'Zine source not found.', [ 'status' => 404 ] ] !== $error_shape( $unknown ) || $error_shape( $unknown ) !== $error_shape( $missing ) ) {
			$fail = 'ability_shape';
		}
		$terms = get_terms( [ 'taxonomy' => \PKIW\Taxonomy::TAXONOMY, 'hide_empty' => true, 'orderby' => 'term_id', 'order' => 'ASC' ] );
		$probe = '';
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( ! $term instanceof WP_Term || in_array( $term->slug, \PKIW\Zine_Manifest::excluded_kinds(), true ) ) {
				continue;
			}
			// A kind whose posts are all hidden answers not_found by design, so it can't serve as the shape probe.
			$candidate = \PKIW\Zine_Manifest::build( [ 'source' => 'archive', 'kind' => $term->slug, 'pages' => 8 ] );
			if ( is_array( $candidate ) && 0 < $candidate['total'] ) {
				$probe = $term->slug;
				break;
			}
		}
		if ( '' !== $probe ) {
			$valid = $run( [ 'source' => 'archive', 'kind' => $probe, 'pages' => 8 ] );
			if ( ! is_array( $valid ) ) {
				$fail = 'ability_shape';
			} else {
				$forbidden = [ 'author', 'content', 'excerpt', 'image', 'location', 'latitude', 'longitude', 'lat', 'lng', 'address', 'venue', 'geo' ];
				$walk = static function ( array $value ) use ( &$walk, $forbidden, &$fail ): void {
					foreach ( $value as $key => $item ) {
						if ( is_string( $key ) && in_array( strtolower( $key ), $forbidden, true ) ) {
							$fail = 'ability_shape';
						}
						if ( is_array( $item ) ) {
							$walk( $item );
						}
					}
				};
				$walk( $valid );
			}
		}
	}
}
/*N4K2:/2*/

/*N4K2:3*/
if ( '' === $fail && null !== $run ) {
	$page = get_post( $page_id );
	if ( ! $page instanceof WP_Post || ! \PKIW\Zine_Manifest::has_stream_block( $page ) ) {
		$gate = $run( [ 'source' => 'stream', 'page_id' => $page_id, 'pages' => 8 ] );
		if ( ! is_wp_error( $gate ) || 'not_found' !== $gate->get_error_code() || [ 'status' => 404 ] !== $gate->get_error_data() ) {
			$fail = 'stream_gate';
		}
	} else {
		$prior_query = $GLOBALS['wp_query'] ?? null;
		try {
			$GLOBALS['wp_query'] = new WP_Query( [ 'page_id' => $page_id ] );
			$expected = \PKIW\Zine_Manifest::build( [ 'source' => 'stream', 'page_id' => $page_id, 'pages' => 8 ] );
		} finally {
			if ( $prior_query instanceof WP_Query ) {
				$GLOBALS['wp_query'] = $prior_query;
			} else {
				unset( $GLOBALS['wp_query'] );
			}
		}
		$actual = $run( [ 'source' => 'stream', 'page_id' => $page_id, 'pages' => 8 ] );
		if ( is_wp_error( $expected ) ) {
			if ( ! is_wp_error( $actual ) || 'not_found' !== $actual->get_error_code() ) {
				$fail = 'stream_gate';
			}
		} elseif ( ! is_array( $actual ) || $expected['included'] !== $actual['included'] ) {
			$fail = 'stream_parity_rows';
		} else {
			foreach ( $actual['included'] as $id ) {
				if ( ! \PKIW\Public_Listing::is_listable( $id ) ) {
					$fail = 'stream_parity_rows';
					break;
				}
			}
		}
	}
}
/*N4K2:/3*/

/*N4K2:4*/
if ( '' === $fail && null !== $run ) {
	$terms = get_terms( [ 'taxonomy' => \PKIW\Taxonomy::TAXONOMY, 'hide_empty' => true, 'orderby' => 'term_id', 'order' => 'ASC' ] );
	foreach ( is_array( $terms ) ? $terms : [] as $term ) {
		if ( ! $term instanceof WP_Term || in_array( $term->slug, \PKIW\Zine_Manifest::excluded_kinds(), true ) ) {
			continue;
		}
		$expected = \PKIW\Zine_Manifest::build( [ 'source' => 'archive', 'kind' => $term->slug, 'pages' => 8 ] );
		$actual = $run( [ 'source' => 'archive', 'kind' => $term->slug, 'pages' => 8 ] );
		if ( is_array( $expected ) && 0 === $expected['total'] && is_wp_error( $actual ) && 'not_found' === $actual->get_error_code() ) {
			continue;
		}
		$actual_ids = is_array( $actual ) ? $actual['included'] : [];
		if ( ! is_array( $expected ) || ! is_array( $actual ) || $expected['included'] !== $actual_ids ) {
			$fail = 'ability_rows:' . implode( ',', array_map( 'intval', $actual_ids ) );
			break;
		}
		foreach ( $actual_ids as $id ) {
			if ( ! \PKIW\Public_Listing::is_listable( $id ) ) {
				$fail = 'ability_rows:' . implode( ',', array_map( 'intval', $actual_ids ) );
				break 2;
			}
		}
	}
}
/*N4K2:/4*/

remove_filter( 'query', $guard, PHP_INT_MIN );
if ( $writes > 0 ) {
	$fail = 'write_attempted:' . $writes;
}
echo '' === $fail ? "RESULT PASS\n" : 'RESULT FAIL ' . $fail . "\n";
