$reason = '';
$set_reason = static function ( string $next ) use ( &$reason ): void {
	if ( '' === $reason ) {
		$reason = $next;
	}
};

if ( \PKIW\On_This_Day::edge_can_purge() ) {
	$set_reason( 'edge_branch_a' );
}

$seams = [
	'class:on-this-day' => class_exists( 'PKIW\On_This_Day' ),
	'class:public-listing' => class_exists( 'PKIW\Public_Listing' ),
	'method:query-args' => method_exists( 'PKIW\On_This_Day', 'query_args' ),
	'method:row-visible' => method_exists( 'PKIW\On_This_Day', 'row_visible' ),
	'method:display' => method_exists( 'PKIW\On_This_Day', 'display' ),
	'method:public-query' => method_exists( 'PKIW\Public_Listing', 'query_args' ),
	'method:public-listable' => method_exists( 'PKIW\Public_Listing', 'is_listable' ),
	'method:public-filter' => method_exists( 'PKIW\Public_Listing', 'filter_ids' ),
	'method:public-meta' => method_exists( 'PKIW\Public_Listing', 'meta_keys' ),
	'ability' => null !== wp_get_ability( 'post-kinds/get-on-this-day' ),
	'mcp' => in_array( 'post-kinds/get-on-this-day', \PKIW\Abilities_Manager::MCP_PUBLIC_ABILITIES, true ),
	'block:card' => \WP_Block_Type_Registry::get_instance()->is_registered( \PKIW\On_This_Day::BLOCK ),
	'block:entry' => \WP_Block_Type_Registry::get_instance()->is_registered( \PKIW\On_This_Day::ENTRY_BLOCK ),
];
foreach ( $seams as $name => $present ) {
	if ( ! $present ) {
		$set_reason( 'seam_missing:' . $name );
		break;
	}
}

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
			$year_bound   = (int) $clause['year'];
			$year_compare = (string) ( $clause['compare'] ?? '' );
		}
	}
	$query = new \WP_Query();
	foreach ( $args as $key => $value ) {
		$query->set( $key, $value );
	}
	$where = \PKIW\Public_Listing::filter_where( ' AND 1=1', $query );
	if ( true !== ( $args['pkiw_public_only'] ?? null ) || true !== ( $args['pkiw_public_listing'] ?? null ) || 'publish' !== ( $args['post_status'] ?? null ) || (int) $day->format( 'Y' ) !== (int) $year_bound || '<' !== $year_compare || ! str_contains( $where, 'NOT EXISTS ( SELECT 1 FROM' ) || ! str_contains( $where, $meta_key ) ) {
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

$rows = \PKIW\On_This_Day::build_rows( \PKIW\On_This_Day::today() );
$bad_ids = [];
foreach ( $rows as $row ) {
	$post = get_post( $row['id'] );
	if ( ! $post instanceof \WP_Post || ! \PKIW\Public_Listing::is_listable( $post ) || ! \PKIW\On_This_Day::row_visible( $post, \PKIW\On_This_Day::kinds() ) ) {
		$bad_ids[] = $row['id'];
	}
}
if ( $bad_ids ) {
	$set_reason( 'rows_real:' . implode( ',', $bad_ids ) );
}

$fixed_zone = wp_timezone();
$fixed = new \DateTimeImmutable( '2026-11-17 23:59:30', $fixed_zone );
$midnight = \PKIW\On_This_Day::seconds_until_midnight( new \DateTimeImmutable( '2026-11-17 00:00:00', $fixed_zone ) );
if ( \PKIW\On_This_Day::seconds_until_midnight() < 1 || \PKIW\On_This_Day::seconds_until_midnight() > 90000 || 30 !== \PKIW\On_This_Day::seconds_until_midnight( $fixed ) || ! in_array( $midnight, [ 82800, 86400, 90000 ], true ) ) {
	$set_reason( 'midnight' );
}

$ability = wp_get_ability( 'post-kinds/get-on-this-day' );
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

echo '' === $reason ? 'RESULT PASS' : 'RESULT FAIL ' . $reason;
