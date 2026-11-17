$reason = '';
$set_reason = static function ( string $next ) use ( &$reason ): void {
	if ( '' === $reason ) {
		$reason = $next;
	}
};
$write_attempts = 0;
$write_guard = static function ( string $query ) use ( &$write_attempts ): string {
	if ( ! preg_match( '/^(SELECT|SHOW)\b/i', ltrim( $query ) ) ) {
		++$write_attempts;
		return '';
	}
	return $query;
};
add_filter( 'query', $write_guard, PHP_INT_MIN );

try {
	$otd_exists = class_exists( 'PKIW\On_This_Day' );
	$listing_exists = class_exists( 'PKIW\Public_Listing' );
	$edge_switch = (bool) apply_filters( 'pkiw_otd_edge_purge', false );
	$apc_paths = function_exists( 'pantheon_wp_clear_edge_paths' );
	$platform_paths = function_exists( 'pantheon_clear_edge_paths' );
	$apc_keys = function_exists( 'pantheon_wp_clear_edge_keys' );
	$platform_keys = function_exists( 'pantheon_clear_edge_keys' );
	$can_purge = $otd_exists && method_exists( 'PKIW\On_This_Day', 'edge_can_purge' ) && \PKIW\On_This_Day::edge_can_purge();
	echo 'INFO edge switch=' . (int) $edge_switch . ' apc_paths=' . (int) $apc_paths . ' platform_paths=' . (int) $platform_paths . ' apc_keys=' . (int) $apc_keys . ' platform_keys=' . (int) $platform_keys . ' can_purge=' . (int) $can_purge . "\n";
	if ( $can_purge ) {
		$set_reason( 'edge_branch_a' );
	}

	$seams = [
		'PKIW\On_This_Day' => $otd_exists,
		'PKIW\Public_Listing' => $listing_exists,
	];
	if ( $otd_exists ) {
		foreach ( [ 'today', 'override_now', 'seconds_until_midnight', 'kinds', 'query_args', 'build_rows', 'served_rows', 'row_visible', 'display', 'single_pick', 'stream_pick', 'edge_can_purge', 'is_day_dependent', 'send_day_headers', 'is_uncached', 'override_headers_for_tests', 'reset' ] as $method ) {
			$seams[ 'method:' . $method ] = method_exists( 'PKIW\On_This_Day', $method );
		}
		foreach ( [
			'request' => 'filter_request',
			'pre_get_posts' => 'filter_main_query',
			'archive_template_hierarchy' => 'template_hierarchy',
			'pre_handle_404' => 'pre_handle_404',
			'render_block_core/post-template' => 'relocate_stream_card',
			'send_headers' => 'send_day_headers',
		] as $hook => $method ) {
			$seams[ 'hook:' . $hook ] = false !== has_filter( $hook, [ 'PKIW\On_This_Day', $method ] );
		}
	}
	if ( $listing_exists ) {
		foreach ( [ 'query_args', 'is_listable', 'filter_ids', 'meta_keys' ] as $method ) {
			$seams[ 'public-method:' . $method ] = method_exists( 'PKIW\Public_Listing', $method );
		}
	}
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'post-kinds/get-on-this-day' ) : null;
	$seams['ability'] = null !== $ability;
	$seams['mcp'] = class_exists( 'PKIW\Abilities_Manager' ) && in_array( 'post-kinds/get-on-this-day', \PKIW\Abilities_Manager::MCP_PUBLIC_ABILITIES, true );
	$rules = (array) get_option( 'rewrite_rules' );
	$seams['rewrite:^on-this-day/?$'] = isset( $rules['^on-this-day/?$'] );
	$seams['rewrite:^on-this-day/page/([0-9]{1,})/?$'] = isset( $rules['^on-this-day/page/([0-9]{1,})/?$'] );
	$registry = \WP_Block_Type_Registry::get_instance();
	$seams['block:post-kinds-indieweb/on-this-day'] = $registry->is_registered( 'post-kinds-indieweb/on-this-day' );
	$seams['block:post-kinds-indieweb/on-this-day-entry'] = $registry->is_registered( 'post-kinds-indieweb/on-this-day-entry' );
	foreach ( $seams as $name => $present ) {
		if ( ! $present ) {
			$set_reason( 'seam_missing:' . $name );
			break;
		}
	}

	if ( '' === $reason && $otd_exists && $listing_exists ) {
		$base = PHP_INT_MAX - 2689;
		$hidden_case = 'quiet_public';
		$cases = [
			'control' => [ 'publish', '' ],
			'private' => [ 'private', '' ],
			'draft' => [ 'draft', '' ],
			'pending' => [ 'pending', '' ],
			'future' => [ 'future', '' ],
			'password' => [ 'publish', 'secret' ],
			'trash' => [ 'trash', '' ],
			$hidden_case => [ 'publish', '' ],
			'local' => [ 'publish', '' ],
		];
		$posts = [];
		$fake_ids = [];
		foreach ( $cases as $name => [ $status, $password ] ) {
			$id = ++$base;
			if ( null !== get_post( $id ) ) {
				$set_reason( 'checker_blind' );
			}
			$posts[ $name ] = new \WP_Post( (object) [
				'ID' => $id,
				'post_author' => 0,
				'post_date' => '2025-11-17 10:00:00',
				'post_date_gmt' => '2025-11-17 10:00:00',
				'post_content' => '',
				'post_title' => 'control' === $name ? 'OTD268 Eval control' : 'OTD268 Eval case',
				'post_excerpt' => '',
				'post_status' => $status,
				'comment_status' => 'closed',
				'ping_status' => 'closed',
				'post_password' => $password,
				'post_name' => 'otd268-eval-' . $id,
				'to_ping' => '',
				'pinged' => '',
				'post_modified' => '2025-11-17 10:00:00',
				'post_modified_gmt' => '2025-11-17 10:00:00',
				'post_content_filtered' => '',
				'post_parent' => 0,
				'guid' => home_url( '/?p=' . $id ),
				'menu_order' => 0,
				'post_type' => 'post',
				'post_mime_type' => '',
				'comment_count' => '0',
				'filter' => 'raw',
			] );
			$fake_ids[ $id ] = $name;
		}
		$meta_key = \PKIW\Public_Listing::meta_keys()[0];
		$meta_filter = static function ( $value, int $object_id, string $key, bool $single ) use ( $fake_ids, $hidden_case, $meta_key ) {
			if ( $meta_key === $key && isset( $fake_ids[ $object_id ] ) && $hidden_case === $fake_ids[ $object_id ] ) {
				return $single ? $hidden_case : [ $hidden_case ];
			}
			return $value;
		};
		add_filter( 'get_post_metadata', $meta_filter, 10, 4 );
		try {
			$wrong = [];
			foreach ( $posts as $name => $post ) {
				$actual = \PKIW\On_This_Day::row_visible( $post, [ '__none__' ] );
				$expected = in_array( $name, [ 'control', 'local' ], true );
				if ( $actual !== $expected ) {
					$wrong[] = $name;
				}
			}
			if ( $wrong ) {
				$set_reason( 'predicate:' . implode( ',', $wrong ) );
			}
			if ( 'OTD268 Eval control' !== \PKIW\On_This_Day::display( $posts['control'] )['title'] ) {
				$set_reason( 'checker_blind' );
			}

			$day = \PKIW\On_This_Day::today();
			$args = /*X268:Q*/ \PKIW\On_This_Day::query_args( $day ) /*X268:/Q*/;
			$year_bound = 0;
			$year_compare = '';
			foreach ( (array) ( $args['date_query'] ?? [] ) as $clause ) {
				if ( is_array( $clause ) && isset( $clause['year'] ) ) {
					$year_bound = (int) $clause['year'];
					$year_compare = (string) ( $clause['compare'] ?? '' );
				}
			}
			$query = new \WP_Query();
			foreach ( $args as $key => $value ) {
				$query->set( $key, $value );
			}
			$where = \PKIW\Public_Listing::filter_where( ' AND 1=1', $query );
			$query_ok = true === ( $args['pkiw_public_only'] ?? null )
				&& true === ( $args['pkiw_public_listing'] ?? null )
				&& 'publish' === ( $args['post_status'] ?? null )
				&& (int) $day->format( 'Y' ) === $year_bound
				&& '<' === $year_compare
				&& str_contains( $where, 'NOT EXISTS ( SELECT 1 FROM' )
				&& str_contains( $where, $meta_key );
			if ( term_exists( 'follow', 'kind' ) || term_exists( 'tag', 'kind' ) ) {
				global $wpdb;
				$governed = \PKIW\Public_Eligibility::filter_where( ' AND 1=1', $query );
				$query_ok = $query_ok && str_contains( $governed, \PKIW\Public_Eligibility::sql( $wpdb->posts ) );
			} else {
				echo "INFO no-governed-term\n";
			}
			if ( ! $query_ok ) {
				$set_reason( 'query_unfiltered' );
			}

			$visible = /*X268:F*/ [ 'PKIW\On_This_Day', 'row_visible' ] /*X268:/F*/;
			$leaks = [];
			foreach ( $posts as $name => $post ) {
				if ( ! in_array( $name, [ 'control', 'local' ], true ) && $visible( $post, [ '__none__' ] ) ) {
					$leaks[] = $name;
				}
			}
			if ( $leaks ) {
				$set_reason( 'row_leak:' . implode( ',', $leaks ) );
			}
		} finally {
			remove_filter( 'get_post_metadata', $meta_filter, 10 );
		}

		$today = \PKIW\On_This_Day::today();
		$kinds = \PKIW\On_This_Day::kinds();
		$rows = \PKIW\On_This_Day::build_rows( $today );
		$bad_ids = [];
		$row_ids = array_map( 'intval', array_column( $rows, 'id' ) );
		foreach ( $rows as $row ) {
			$post = get_post( $row['id'] );
			if ( ! $post instanceof \WP_Post || ! \PKIW\Public_Listing::is_listable( $post ) || ! \PKIW\On_This_Day::row_visible( $post, $kinds ) ) {
				$bad_ids[] = (int) $row['id'];
			}
		}
		global $wpdb;
		$month_day = $today->format( 'm-d' );
		$candidates = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND DATE_FORMAT(post_date, '%%m-%%d') = %s", 'post', $month_day ) );
		foreach ( array_map( 'intval', $candidates ) as $candidate_id ) {
			if ( ! \PKIW\Public_Listing::is_listable( $candidate_id ) && in_array( $candidate_id, $row_ids, true ) ) {
				$bad_ids[] = $candidate_id;
			}
		}
		$years = array_unique( array_map( 'intval', array_column( $rows, 'year' ) ) );
		$kinds_hash = hash( 'sha256', (string) wp_json_encode( $kinds ) );
		echo 'INFO otd rows=' . count( $rows ) . ' years=' . count( $years ) . ' kinds=' . $kinds_hash . "\n";
		$bad_ids = array_values( array_unique( $bad_ids ) );
		if ( $bad_ids ) {
			$set_reason( 'rows_real:' . implode( ',', $bad_ids ) );
		}

		$fixed_zone = wp_timezone();
		$fixed = new \DateTimeImmutable( '2026-11-17 23:59:30', $fixed_zone );
		$midnight = \PKIW\On_This_Day::seconds_until_midnight( new \DateTimeImmutable( '2026-11-17 00:00:00', $fixed_zone ) );
		if ( \PKIW\On_This_Day::seconds_until_midnight() < 1 || \PKIW\On_This_Day::seconds_until_midnight() > 90000 || 30 !== \PKIW\On_This_Day::seconds_until_midnight( $fixed ) || ! in_array( $midnight, [ 82800, 86400, 90000 ], true ) ) {
			$set_reason( 'midnight' );
		}

		if ( null === $ability ) {
			$set_reason( 'ability_schema' );
		} else {
			$input = $ability->get_input_schema()['properties'] ?? [];
			$item = $ability->get_output_schema()['properties']['groups']['items']['properties']['items']['items']['properties'] ?? [];
			foreach ( [ 'excerpt', 'content', 'meta', 'author' ] as $private ) {
				if ( array_key_exists( $private, $item ) ) {
					$set_reason( 'ability_schema' );
				}
			}
			if ( array_key_exists( 'kinds', $input ) ) {
				$set_reason( 'ability_schema' );
			}
		}

		$excluded = apply_filters( 'perfmatters_rucss_excluded_stylesheets', [] );
		foreach ( [ 'cr-on-this-day.css', 'styles/on-this-day.css' ] as $file ) {
			if ( ! in_array( $file, $excluded, true ) ) {
				$set_reason( 'rucss_exclusion:' . $file );
				break;
			}
		}
	}
} finally {
	remove_filter( 'query', $write_guard, PHP_INT_MIN );
}

if ( $write_attempts > 0 ) {
	$reason = 'write_attempted:' . $write_attempts;
}
echo '' === $reason ? 'RESULT PASS' : 'RESULT FAIL ' . $reason;
