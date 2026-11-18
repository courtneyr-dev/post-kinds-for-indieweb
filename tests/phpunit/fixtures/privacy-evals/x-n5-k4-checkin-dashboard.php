$x_n5_k4_result     = 'RESULT PASS';
$x_n5_k4_writes     = 0;
$x_n5_k4_write_verbs = [];
$x_n5_k4_user_id    = get_current_user_id();
$x_n5_k4_query_guard = static function ( $sql ) use ( &$x_n5_k4_writes, &$x_n5_k4_write_verbs ) {
	$verb = strtoupper( strtok( ltrim( (string) $sql ), " \t\r\n" ) ?: '' );
	if ( ! in_array( $verb, [ 'SELECT', 'SHOW' ], true ) ) {
		++$x_n5_k4_writes;
		$x_n5_k4_write_verbs[] = $verb;
		return '';
	}
	return $sql;
};
add_filter( 'query', $x_n5_k4_query_guard, PHP_INT_MIN );

$x_n5_k4_where         = '';
$x_n5_k4_capture_where = static function ( $where, $query ) use ( &$x_n5_k4_where ) {
	$tax_query = (array) $query->get( 'tax_query' );
	foreach ( $tax_query as $clause ) {
		if ( is_array( $clause ) && 'kind' === ( $clause['taxonomy'] ?? '' ) && in_array( 'checkin', (array) ( $clause['terms'] ?? [] ), true ) ) {
			$x_n5_k4_where = (string) $where;
			break;
		}
	}
	return $where;
};
$x_n5_k4_query_mutator = /*X-N5:U*/ null /*X-N5:/U*/;
$x_n5_k4_excerpt_removed = false;

try {
	global $wpdb;

	if ( null === wp_script_modules()->get_registered( 'post-kinds-indieweb-checkin-dashboard-view-script-module' ) ) {
		$x_n5_k4_result = 'RESULT FAIL module_missing';
	} elseif ( wp_script_is( 'post-kinds-indieweb-checkin-dashboard-view-script', 'registered' ) ) {
		$x_n5_k4_result = 'RESULT FAIL classic_handle_registered';
	}

	if ( 'RESULT PASS' === $x_n5_k4_result ) {
		wp_set_current_user( 0 );
		if ( has_filter( 'get_the_excerpt', 'wp_trim_excerpt' ) ) {
			remove_filter( 'get_the_excerpt', 'wp_trim_excerpt' );
			$x_n5_k4_excerpt_removed = true;
		}
		if ( is_callable( $x_n5_k4_query_mutator ) ) {
			add_action( 'pre_get_posts', $x_n5_k4_query_mutator, PHP_INT_MAX );
		}
		add_filter( 'posts_where', $x_n5_k4_capture_where, PHP_INT_MAX, 2 );
		$x_n5_k4_html = do_blocks( '<!-- wp:post-kinds-indieweb/checkin-dashboard {"showFilters":true} /-->' );
		remove_filter( 'posts_where', $x_n5_k4_capture_where, PHP_INT_MAX );
		if ( is_callable( $x_n5_k4_query_mutator ) ) {
			remove_action( 'pre_get_posts', $x_n5_k4_query_mutator, PHP_INT_MAX );
		}

		$x_n5_k4_markup = [
			'wrapper'  => false,
			'grid'     => false,
			'map'      => false,
			'timeline' => false,
			'map_hidden' => false,
			'timeline_hidden' => false,
		];
		$x_n5_k4_tags = new \WP_HTML_Tag_Processor( $x_n5_k4_html );
		while ( $x_n5_k4_tags->next_tag() ) {
			if ( 'post-kinds-indieweb/checkin-dashboard' === $x_n5_k4_tags->get_attribute( 'data-wp-interactive' ) ) {
				$x_n5_k4_markup['wrapper'] = true;
			}
			$x_n5_k4_view = (string) $x_n5_k4_tags->get_attribute( 'data-view' );
			if ( isset( $x_n5_k4_markup[ $x_n5_k4_view ] ) ) {
				$x_n5_k4_markup[ $x_n5_k4_view ] = ( 'grid' === $x_n5_k4_view ? 'true' : 'false' ) === $x_n5_k4_tags->get_attribute( 'aria-pressed' );
			}
			if ( $x_n5_k4_tags->has_class( 'checkin-view-map' ) ) {
				$x_n5_k4_markup['map_hidden'] = null !== $x_n5_k4_tags->get_attribute( 'hidden' );
			}
			if ( $x_n5_k4_tags->has_class( 'checkin-view-timeline' ) ) {
				$x_n5_k4_markup['timeline_hidden'] = null !== $x_n5_k4_tags->get_attribute( 'hidden' );
			}
		}
		foreach ( $x_n5_k4_markup as $x_n5_k4_item => $x_n5_k4_present ) {
			if ( ! $x_n5_k4_present ) {
				$x_n5_k4_result = 'RESULT FAIL markup:' . $x_n5_k4_item;
				break;
			}
		}
	}

	if ( 'RESULT PASS' === $x_n5_k4_result ) {
		$x_n5_k4_has_governed_terms = false !== get_term_by( 'slug', 'follow', 'kind' ) || false !== get_term_by( 'slug', 'tag', 'kind' );
		if ( ! $x_n5_k4_has_governed_terms ) {
			echo "INFO no-governed-terms\n";
		} elseif ( false === strpos( $x_n5_k4_where, \PKIW\Public_Eligibility::sql( $wpdb->posts ) ) ) {
			$x_n5_k4_result = 'RESULT FAIL clause_missing';
		}
	}

	if ( 'RESULT PASS' === $x_n5_k4_result ) {
		$x_n5_k4_args = [
			'post_type'       => 'post',
			'posts_per_page'  => 12,
			'post_status'     => 'publish',
			'tax_query'       => [
				[
					'taxonomy' => 'kind',
					'field'    => 'slug',
					'terms'    => 'checkin',
				],
			],
			'orderby'         => 'date',
			'order'           => 'DESC',
			'pkiw_public_only' => true,
		];
		$x_n5_k4_query = new \WP_Query( $x_n5_k4_args );
		$x_n5_k4_kept  = [];
		foreach ( $x_n5_k4_query->posts as $x_n5_k4_post ) {
			if ( \PKIW\Public_Eligibility::is_eligible( $x_n5_k4_post ) ) {
				$x_n5_k4_kept[] = $x_n5_k4_post;
			}
		}

		$x_n5_k4_rendered_count = 0;
		$x_n5_k4_urls           = [];
		$x_n5_k4_tags           = new \WP_HTML_Tag_Processor( $x_n5_k4_html );
		while ( $x_n5_k4_tags->next_tag() ) {
			if ( 'ARTICLE' === $x_n5_k4_tags->get_tag() && $x_n5_k4_tags->has_class( 'checkin-card' ) ) {
				++$x_n5_k4_rendered_count;
			}
			if ( 'A' === $x_n5_k4_tags->get_tag() && $x_n5_k4_tags->has_class( 'u-url' ) ) {
				$x_n5_k4_urls[] = (string) $x_n5_k4_tags->get_attribute( 'href' );
			}
		}
		$x_n5_k4_expected_count = count( $x_n5_k4_kept );
		if ( $x_n5_k4_rendered_count !== $x_n5_k4_expected_count ) {
			$x_n5_k4_result = 'RESULT FAIL count:' . $x_n5_k4_rendered_count . '!=' . $x_n5_k4_expected_count;
		}
		echo 'INFO checkins=' . $x_n5_k4_expected_count . "\n";
	}

	if ( 'RESULT PASS' === $x_n5_k4_result ) {
		$x_n5_k4_permalink_ids = [];
		foreach ( $x_n5_k4_kept as $x_n5_k4_post ) {
			$x_n5_k4_key = html_entity_decode( esc_url( get_permalink( $x_n5_k4_post ) ), ENT_QUOTES | ENT_HTML5 );
			$x_n5_k4_permalink_ids[ $x_n5_k4_key ] = (int) $x_n5_k4_post->ID;
		}
		$x_n5_k4_unknown = 0;
		$x_n5_k4_bad_ids = [];
		foreach ( $x_n5_k4_urls as $x_n5_k4_url ) {
			if ( ! isset( $x_n5_k4_permalink_ids[ $x_n5_k4_url ] ) ) {
				++$x_n5_k4_unknown;
				continue;
			}
			$x_n5_k4_id = $x_n5_k4_permalink_ids[ $x_n5_k4_url ];
			if ( ! \PKIW\Public_Eligibility::is_eligible( $x_n5_k4_id ) ) {
				$x_n5_k4_bad_ids[] = $x_n5_k4_id;
			}
		}
		if ( $x_n5_k4_unknown > 0 ) {
			$x_n5_k4_result = 'RESULT FAIL unknown_entry:' . $x_n5_k4_unknown;
		} elseif ( [] !== $x_n5_k4_bad_ids ) {
			$x_n5_k4_result = 'RESULT FAIL ineligible:' . implode( ',', array_values( array_unique( $x_n5_k4_bad_ids ) ) );
		}
	}
} catch ( \Throwable $x_n5_k4_error ) {
	$x_n5_k4_result = 'RESULT FAIL exception:' . get_class( $x_n5_k4_error );
} finally {
	remove_filter( 'posts_where', $x_n5_k4_capture_where, PHP_INT_MAX );
	if ( is_callable( $x_n5_k4_query_mutator ) ) {
		remove_action( 'pre_get_posts', $x_n5_k4_query_mutator, PHP_INT_MAX );
	}
	if ( $x_n5_k4_excerpt_removed ) {
		add_filter( 'get_the_excerpt', 'wp_trim_excerpt', 10, 2 );
	}
	remove_filter( 'query', $x_n5_k4_query_guard, PHP_INT_MIN );
	wp_set_current_user( $x_n5_k4_user_id );
}

if ( $x_n5_k4_writes > 0 ) {
	$x_n5_k4_write_verbs = array_values( array_unique( array_filter( $x_n5_k4_write_verbs ) ) );
	echo 'INFO write-verbs=' . implode( ',', $x_n5_k4_write_verbs ) . "\n";
	$x_n5_k4_result = 'RESULT FAIL write_attempted:' . $x_n5_k4_writes;
}
echo $x_n5_k4_result . "\n";
