$checks = [];
$years = [ (int) wp_date( 'Y' ), (int) wp_date( 'Y' ) - 1, (int) wp_date( 'Y' ) - 2 ];

/*X267:SEAMS*/ $checks['seams'] = class_exists( 'PKIW\Wrapped_Stats' )
	&& class_exists( 'PKIW\Public_Listing' )
	&& method_exists( 'PKIW\Public_Listing', 'meta_keys' )
	&& method_exists( 'PKIW\Public_Eligibility', 'is_eligible' ); /*X267:/SEAMS*/

$registry = $checks['seams'] ? \PKIW\Wrapped_Stats::registry() : [];
$top_ok = true;
if ( $checks['seams'] ) {
	foreach ( array_keys( $registry ) as $kind ) {
		foreach ( $years as $year ) {
			$data = \PKIW\Wrapped_Stats::compute( $kind, $year );
			foreach ( $data['top'] ?? [] as $row ) {
				if ( ! \PKIW\Public_Listing::is_listable( (int) $row['post_id'] ) ) {
					$top_ok = false;
				}
			}
		}
	}
}
/*X267:TOP*/ $checks['top'] = $top_ok; /*X267:/TOP*/

$registry_text = wp_json_encode( $registry );
/*X267:REGISTRY*/ $checks['registry'] = is_string( $registry_text )
	&& ! str_contains( $registry_text, 'acquisition_price' )
	&& ! str_contains( $registry_text, 'exercise_distance' )
	&& ! str_contains( $registry_text, 'exercise_splits' )
	&& ! str_contains( $registry_text, 'exercise_pb' )
	&& ! str_contains( $registry_text, 'sleep_bedtime' )
	&& ! str_contains( $registry_text, 'sleep_wake' )
	&& str_contains( $registry_text, 'exercise_duration' )
	&& str_contains( $registry_text, 'sleep_duration' ); /*X267:/REGISTRY*/

$oracle_ok = true;
if ( $checks['seams'] ) {
	$terms = get_terms( [ 'taxonomy' => 'kind', 'hide_empty' => false ] );
	foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
		if ( in_array( $term->slug, [ 'read', 'listen', 'comics' ], true ) ) {
			continue;
		}
		foreach ( $years as $year ) {
			// Independent of Public_Listing's SQL (C16): a plain get_posts(), then the per-post guard.
			$args = [
				'post_type' => 'post',
				'post_status' => 'publish',
				'has_password' => false,
				'fields' => 'ids',
				'posts_per_page' => -1,
				'tax_query' => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => $term->slug ] ],
				'date_query' => [ [ 'year' => $year ] ],
			];
			$ids = \PKIW\Public_Listing::filter_ids( get_posts( $args ) );
			$ids = array_values( array_filter( $ids, static fn( int $id ): bool => '' === (string) get_post_meta( $id, '_pkiw_wrapped_kind', true ) ) );
			if ( 'watch' === $term->slug ) {
				$ids = array_values( array_filter( $ids, static fn( int $id ): bool => 'watched' === get_post_meta( $id, '_pkiw_watch_status', true ) ) );
			} elseif ( 'play' === $term->slug ) {
				$ids = array_values( array_filter( $ids, static fn( int $id ): bool => 'completed' === get_post_meta( $id, '_pkiw_play_status', true ) ) );
			}
			$data = \PKIW\Wrapped_Stats::compute( $term->slug, $year );
			$main = $data['main'] ?? '';
			$value = null;
			foreach ( $data['metrics'] ?? [] as $metric ) {
				if ( $main === $metric['key'] ) {
					$value = (int) $metric['value'];
				}
			}
			if ( count( $ids ) !== $value ) {
				$oracle_ok = false;
			}
		}
	}
}
/*X267:ORACLE*/ $checks['oracle'] = $oracle_ok; /*X267:/ORACLE*/

$stats_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'post-kinds/kind-year-stats' ) : null;
$draft_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'post-kinds/create-wrapped-draft' ) : null;
/*X267:ABILITIES*/ $checks['abilities'] = null !== $stats_ability
	&& null !== $draft_ability
	&& ! in_array( 'post-kinds/create-wrapped-draft', \PKIW\Abilities_Manager::MCP_PUBLIC_ABILITIES, true ); /*X267:/ABILITIES*/

$binding = function_exists( 'get_block_bindings_source' ) ? get_block_bindings_source( 'post-kinds/wrapped' ) : null;
/*X267:BINDING*/ $checks['binding'] = null !== $binding; /*X267:/BINDING*/

$rules = (array) get_option( 'rewrite_rules' );
$rewrite_ok = false;
foreach ( array_keys( $rules ) as $rule ) {
	if ( str_contains( (string) $rule, 'kind/' ) && str_contains( (string) $rule, '/wrapped' ) ) {
		$rewrite_ok = true;
	}
}
/*X267:REWRITE*/ $checks['rewrite'] = $rewrite_ok; /*X267:/REWRITE*/

$term_ok = true;
$wrapped_ids = get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_pkiw_wrapped_kind', 'meta_compare' => 'EXISTS' ] );
foreach ( $wrapped_ids as $wrapped_id ) {
	if ( has_term( '', 'kind', (int) $wrapped_id ) ) {
		$term_ok = false;
	}
}
/*X267:TERMS*/ $checks['terms'] = $term_ok; /*X267:/TERMS*/

$failed = array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) );
echo [] === $failed ? 'RESULT PASS' : 'RESULT FAIL ' . implode( ',', $failed );
