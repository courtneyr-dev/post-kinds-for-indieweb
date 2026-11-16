$w8_260_result = 'RESULT PASS';
$w8_260_writes = 0;
$w8_260_query_guard = static function ( $sql ) use ( &$w8_260_writes ) {
	$verb = strtoupper( strtok( ltrim( (string) $sql ), " \t\r\n" ) ?: '' );
	if ( ! in_array( $verb, [ 'SELECT', 'SHOW' ], true ) ) {
		++$w8_260_writes;
		return '';
	}
	return $sql;
};
add_filter( 'query', $w8_260_query_guard, PHP_INT_MIN );

$w8_260_cases = [
	'control'  => [ 'publish', '' ],
	'private'  => [ 'private', '' ],
	'draft'    => [ 'draft', '' ],
	'pending'  => [ 'pending', '' ],
	'future'   => [ 'future', '' ],
	'password' => [ 'publish', 'x' ],
	'trash'    => [ 'trash', '' ],
];
$w8_260_posts = [];
$w8_260_ids   = [];
foreach ( $w8_260_cases as $w8_260_case => $w8_260_state ) {
	$w8_260_id                = PHP_INT_MAX - 2601 - count( $w8_260_posts );
	$w8_260_ids[]             = $w8_260_id;
	$w8_260_url               = 'https://fw260-eval-' . $w8_260_case . '.example/';
	$w8_260_posts[ $w8_260_case ] = new \WP_Post(
		(object) [
			'ID'                => $w8_260_id,
			'post_author'       => 0,
			'post_date'         => 'future' === $w8_260_case ? gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) : '2026-11-13 12:00:00',
			'post_date_gmt'     => 'future' === $w8_260_case ? gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) : '2026-11-13 12:00:00',
			'post_content'      => '<!-- wp:paragraph -->' . "\n" . '<p>Followed <a class="u-follow-of" href="' . $w8_260_url . '">' . $w8_260_url . '</a></p>' . "\n" . '<!-- /wp:paragraph -->',
			'post_title'        => '',
			'post_excerpt'      => '',
			'post_status'       => $w8_260_state[0],
			'comment_status'    => 'closed',
			'ping_status'       => 'closed',
			'post_password'     => $w8_260_state[1],
			'post_name'         => 'fw260-eval-' . $w8_260_case,
			'to_ping'           => '',
			'pinged'            => '',
			'post_modified'     => '2026-11-13 12:00:00',
			'post_modified_gmt' => '2026-11-13 12:00:00',
			'post_parent'       => 0,
			'guid'              => $w8_260_url,
			'menu_order'        => 0,
			'post_type'         => 'post',
			'post_mime_type'    => '',
			'comment_count'     => 0,
			'filter'            => 'raw',
		]
	);
}

$w8_260_meta = static function ( $value, $object_id, $meta_key, $single, $meta_type ) use ( $w8_260_ids, $w8_260_posts ) {
	if ( 'post' !== $meta_type || ! in_array( (int) $object_id, $w8_260_ids, true ) ) {
		return $value;
	}
	$case = array_search( (int) $object_id, array_map( static fn( \WP_Post $post ): int => $post->ID, $w8_260_posts ), true );
	if ( false === $case || '' === (string) $meta_key ) {
		return [];
	}
	$values = [
		'_pkiw_cite_name' => 'Fw260 Eval ' . $case,
		'_pkiw_cite_url'  => 'https://fw260-eval-' . $case . '.example/',
		'_thumbnail_id'   => 0,
	];
	$answer = $values[ $meta_key ] ?? '';
	return $single ? $answer : [ $answer ];
};
$w8_260_terms = static function ( $terms, $query ) use ( $w8_260_ids ) {
	$object_ids = array_map( 'intval', (array) ( $query->query_vars['object_ids'] ?? [] ) );
	return [] !== array_intersect( $w8_260_ids, $object_ids ) ? [] : $terms;
};
add_filter( 'get_post_metadata', $w8_260_meta, PHP_INT_MIN, 5 );
add_filter( 'terms_pre_query', $w8_260_terms, PHP_INT_MIN, 2 );

try {
	global $wpdb;
	$w8_260_counts = array_fill_keys( [ 'publish', 'private', 'draft', 'pending', 'future', 'trash' ], 0 );
	$w8_260_rows   = $wpdb->get_results(
		"SELECT p.post_status, p.post_password, p.ID,
		EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm_url WHERE pm_url.post_id = p.ID AND pm_url.meta_key = '_pkiw_cite_url' AND pm_url.meta_value <> '' ) AS has_url,
		EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm_name WHERE pm_name.post_id = p.ID AND pm_name.meta_key = '_pkiw_cite_name' AND pm_name.meta_value <> '' ) AS has_name,
		EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm_thumb WHERE pm_thumb.post_id = p.ID AND pm_thumb.meta_key = '_thumbnail_id' AND CAST( pm_thumb.meta_value AS UNSIGNED ) > 0 ) AS has_thumb,
		( p.post_content LIKE '%class=\"u-follow-of\"%' OR p.post_content LIKE '%class=''u-follow-of''%' ) AS has_content_url
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		WHERE p.post_type = 'post' AND tt.taxonomy = 'kind' AND t.slug = 'follow'
		AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm_seed WHERE pm_seed.post_id = p.ID AND pm_seed.meta_key = '_cr_seed_260' )",
		ARRAY_A
	);
	$w8_260_protected  = 0;
	$w8_260_with_url   = 0;
	$w8_260_with_name  = 0;
	$w8_260_with_thumb = 0;
	$w8_260_content_url = 0;
	foreach ( $w8_260_rows as $w8_260_row ) {
		if ( isset( $w8_260_counts[ $w8_260_row['post_status'] ] ) ) {
			++$w8_260_counts[ $w8_260_row['post_status'] ];
		}
		if ( 'publish' === $w8_260_row['post_status'] && '' !== $w8_260_row['post_password'] ) {
			++$w8_260_protected;
		}
		if ( 'publish' === $w8_260_row['post_status'] && '' === $w8_260_row['post_password'] ) {
			$w8_260_with_url   += (int) $w8_260_row['has_url'];
			$w8_260_with_name  += (int) $w8_260_row['has_name'];
			$w8_260_with_thumb += (int) $w8_260_row['has_thumb'];
			$w8_260_content_url += ! $w8_260_row['has_url'] && $w8_260_row['has_content_url'] ? 1 : 0;
		}
	}
	echo 'INFO follow-260 publish=' . $w8_260_counts['publish'] . ' private=' . $w8_260_counts['private'] . ' draft=' . $w8_260_counts['draft'] . ' pending=' . $w8_260_counts['pending'] . ' future=' . $w8_260_counts['future'] . ' trash=' . $w8_260_counts['trash'] . ' protected=' . $w8_260_protected . ' url=' . $w8_260_with_url . ' name=' . $w8_260_with_name . ' thumb=' . $w8_260_with_thumb . ' content_url=' . $w8_260_content_url . "\n";

	$w8_260_seams = [
		'PKIW\\Public_Eligibility' => class_exists( 'PKIW\\Public_Eligibility' ),
		'follow_kind'              => class_exists( 'PKIW\\Public_Eligibility' ) && in_array( 'follow', \PKIW\Public_Eligibility::KINDS, true ),
		'filter_ids'               => method_exists( 'PKIW\\Public_Eligibility', 'filter_ids' ),
		'Follow_Archive'           => class_exists( 'PKIW\\Follow_Archive' ),
	];
	foreach ( [ 'facts', 'facts_for', 'facts_body', 'target', 'index_query_args', 'build_index', 'served_index', 'opml_document', 'opml_response', 'rest_meta_for', 'guard_cite_photo', 'is_generated_paragraph' ] as $w8_260_method ) {
		$w8_260_seams[ $w8_260_method ] = method_exists( 'PKIW\\Follow_Archive', $w8_260_method );
	}
	$w8_260_seams['posts_where'] = has_filter( 'posts_where', [ 'PKIW\\Public_Eligibility', 'filter_where' ] );
	$w8_260_seams['rest']        = has_filter( 'rest_prepare_post', [ 'PKIW\\Follow_Archive', 'rest_meta' ] );
	$w8_260_seams['photo']       = has_filter( 'add_post_metadata', [ 'PKIW\\Follow_Archive', 'guard_cite_photo' ] );
	$w8_260_seams['micropub']    = has_filter( 'micropub_dynamic_render', [ 'PKIW\\Follow_Archive', 'no_micropub_render' ] );
	$w8_260_seams['ability']     = function_exists( 'wp_get_ability' ) && null !== wp_get_ability( 'post-kinds/get-follow-index' ) && in_array( 'post-kinds/get-follow-index', \PKIW\Abilities_Manager::MCP_PUBLIC_ABILITIES, true );
	$w8_260_rewrite_rules        = (array) get_option( 'rewrite_rules', [] );
	$w8_260_seams['rewrite']     = isset( $w8_260_rewrite_rules['^kind/follow/opml/?$'] ) && false !== strpos( (string) $w8_260_rewrite_rules['^kind/follow/opml/?$'], 'pkiw_follow_opml=1' );
	$w8_260_seams['reader']      = null !== \PKIW\Kind_Facts::reader( 'follow' );
	foreach ( $w8_260_seams as $w8_260_seam => $w8_260_present ) {
		if ( ! $w8_260_present ) {
			$w8_260_result = 'RESULT FAIL seam_missing:' . $w8_260_seam;
			break;
		}
	}

	if ( 'RESULT PASS' === $w8_260_result ) {
		$w8_260_bad = [];
		foreach ( $w8_260_posts as $w8_260_case => $w8_260_post ) {
			if ( null !== get_post( $w8_260_post->ID ) ) {
				$w8_260_result = 'RESULT FAIL id_exists';
				break;
			}
			if ( ( 'control' === $w8_260_case ) !== \PKIW\Public_Eligibility::is_eligible( $w8_260_post ) ) {
				$w8_260_bad[] = $w8_260_case;
			}
		}
		$w8_260_auto = clone $w8_260_posts['control'];
		$w8_260_auto->post_status = 'auto-draft';
		if ( \PKIW\Public_Eligibility::is_eligible( $w8_260_auto ) ) {
			$w8_260_bad[] = 'auto-draft';
		}
		if ( 'RESULT PASS' === $w8_260_result && [] !== $w8_260_bad ) {
			$w8_260_result = 'RESULT FAIL predicate:' . implode( ',', $w8_260_bad );
		}
	}

	if ( 'RESULT PASS' === $w8_260_result ) {
		$w8_260_positive = \PKIW\Follow_Archive::facts_body( $w8_260_posts['control'] );
		if ( 'Fw260 Eval control' !== ( $w8_260_positive['name'] ?? '' ) || 'https://fw260-eval-control.example/' !== ( $w8_260_positive['url'] ?? '' ) ) {
			$w8_260_result = 'RESULT FAIL checker_blind';
		}
	}

	if ( 'RESULT PASS' === $w8_260_result ) {
		$w8_260_reader = /*W8-260:F*/ [ 'PKIW\Follow_Archive', 'facts_for' ] /*W8-260:/F*/;
		$w8_260_leaks  = [];
		$w8_260_facts  = [];
		foreach ( $w8_260_posts as $w8_260_case => $w8_260_post ) {
			$w8_260_facts[ $w8_260_case ] = $w8_260_reader( $w8_260_post, 'follow' );
			if ( 'control' !== $w8_260_case && ( false !== strpos( wp_json_encode( $w8_260_facts[ $w8_260_case ] ), 'fw260-eval-' . $w8_260_case ) || false !== strpos( wp_json_encode( $w8_260_facts[ $w8_260_case ] ), 'Fw260 Eval ' . $w8_260_case ) ) ) {
				$w8_260_leaks[] = $w8_260_case;
			}
		}
		if ( 'Fw260 Eval control' !== ( $w8_260_facts['control']['name'] ?? '' ) ) {
			$w8_260_result = 'RESULT FAIL facts_positive_missing';
		} elseif ( [] !== $w8_260_leaks ) {
			$w8_260_result = 'RESULT FAIL facts_leak:' . implode( ',', $w8_260_leaks );
		} else {
			$w8_260_opml_rows = array_values( array_filter( $w8_260_facts ) );
			$w8_260_opml      = \PKIW\Follow_Archive::opml_document( $w8_260_opml_rows );
			if ( false === strpos( $w8_260_opml, '<outline' ) || false === strpos( $w8_260_opml, 'fw260-eval-control.example' ) ) {
				$w8_260_result = 'RESULT FAIL opml_positive_missing';
			} else {
				$w8_260_opml_leaks = [];
				foreach ( $w8_260_cases as $w8_260_case => $w8_260_unused ) {
					if ( 'control' !== $w8_260_case && ( false !== strpos( $w8_260_opml, 'fw260-eval-' . $w8_260_case ) || false !== strpos( $w8_260_opml, 'Fw260 Eval ' . $w8_260_case ) ) ) {
						$w8_260_opml_leaks[] = $w8_260_case;
					}
				}
				if ( [] !== $w8_260_opml_leaks ) {
					$w8_260_result = 'RESULT FAIL opml_leak:' . implode( ',', $w8_260_opml_leaks );
				}
			}
		}
	}

	if ( 'RESULT PASS' === $w8_260_result ) {
		$w8_260_args = /*W8-260:Q*/ \PKIW\Follow_Archive::index_query_args() /*W8-260:/Q*/;
		if ( ! term_exists( 'follow', 'kind' ) ) {
			echo "INFO no-follow-term\n";
		} else {
			$w8_260_q = new \WP_Query();
			foreach ( $w8_260_args as $w8_260_key => $w8_260_value ) {
				$w8_260_q->set( $w8_260_key, $w8_260_value );
			}
			$w8_260_where = \PKIW\Public_Eligibility::filter_where( ' AND 1=1', $w8_260_q );
			if ( isset( $w8_260_args['post_status'] ) || true !== ( $w8_260_args['pkiw_public_only'] ?? null ) || false === strpos( $w8_260_where, \PKIW\Public_Eligibility::sql( $wpdb->posts ) ) ) {
				$w8_260_result = 'RESULT FAIL index_query_unfiltered';
			}
		}
	}

	if ( 'RESULT PASS' === $w8_260_result ) {
		wp_set_current_user( 0 );
		$w8_260_rest                 = /*W8-260:R*/ [ 'PKIW\Follow_Archive', 'rest_meta_for' ] /*W8-260:/R*/;
		$w8_260_leaks                = [];
		$w8_260_rest_positive        = true;
		$w8_260_rest_photo_is_blank  = true;
		foreach ( $w8_260_posts as $w8_260_case => $w8_260_post ) {
			$w8_260_response = new \WP_REST_Response(
				[
					'id'             => $w8_260_post->ID,
					'featured_media' => 99,
					'meta'           => [
						'_pkiw_cite_name'  => 'Fw260 Eval ' . $w8_260_case,
						'_pkiw_cite_url'   => 'https://fw260-eval-' . $w8_260_case . '.example/',
						'_pkiw_cite_photo' => 'https://fw260-photo-' . $w8_260_case . '.example/p.png',
					],
				]
			);
			$w8_260_data = $w8_260_rest( $w8_260_response, $w8_260_post, 'follow' )->get_data();
			if ( 'control' !== $w8_260_case && ( 0 !== (int) ( $w8_260_data['featured_media'] ?? 0 ) || false !== strpos( wp_json_encode( $w8_260_data ), 'fw260-eval-' . $w8_260_case ) || false !== strpos( wp_json_encode( $w8_260_data ), 'fw260-photo-' . $w8_260_case ) ) ) {
				$w8_260_leaks[] = $w8_260_case;
			} elseif ( 'control' === $w8_260_case ) {
				$w8_260_rest_positive       = 'Fw260 Eval control' === ( $w8_260_data['meta']['_pkiw_cite_name'] ?? '' ) && 'https://fw260-eval-control.example/' === ( $w8_260_data['meta']['_pkiw_cite_url'] ?? '' );
				$w8_260_rest_photo_is_blank = '' === ( $w8_260_data['meta']['_pkiw_cite_photo'] ?? null );
			}
		}
		if ( [] !== $w8_260_leaks ) {
			$w8_260_result = 'RESULT FAIL rest_leak:' . implode( ',', $w8_260_leaks );
		} elseif ( ! $w8_260_rest_positive ) {
			$w8_260_result = 'RESULT FAIL rest_positive_missing';
		} elseif ( ! $w8_260_rest_photo_is_blank ) {
			$w8_260_result = 'RESULT FAIL rest_remote_photo';
		}
	}

	if ( 'RESULT PASS' === $w8_260_result ) {
		$w8_260_index     = \PKIW\Follow_Archive::build_index();
		$w8_260_index_ids = array_map( static fn( array $row ): int => (int) $row['post_id'], $w8_260_index['follows'] );
		$w8_260_all_ids   = array_map(
			'intval',
			$wpdb->get_col(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE p.post_type = 'post' AND tt.taxonomy = 'kind' AND t.slug = 'follow'"
			)
		);
		$w8_260_index_bad = [];
		foreach ( $w8_260_index_ids as $w8_260_index_id ) {
			if ( ! \PKIW\Public_Eligibility::is_eligible( get_post( $w8_260_index_id ) ) ) {
				$w8_260_index_bad[] = $w8_260_index_id;
			}
		}
		foreach ( $w8_260_all_ids as $w8_260_all_id ) {
			if ( ! \PKIW\Public_Eligibility::is_eligible( get_post( $w8_260_all_id ) ) && in_array( $w8_260_all_id, $w8_260_index_ids, true ) ) {
				$w8_260_index_bad[] = $w8_260_all_id;
			}
		}
		$w8_260_index_xml = \PKIW\Follow_Archive::opml_document( $w8_260_index['follows'] );
		$w8_260_parsed    = simplexml_load_string( $w8_260_index_xml, 'SimpleXMLElement', LIBXML_NONET );
		$w8_260_url_rows  = count( array_filter( $w8_260_index['follows'], static fn( array $row ): bool => '' !== (string) ( $row['url'] ?? '' ) ) );
		if ( false === $w8_260_parsed || $w8_260_url_rows !== count( $w8_260_parsed->xpath( '//outline' ) ?: [] ) ) {
			$w8_260_index_bad[] = 0;
		}
		echo 'INFO follow-index ' . count( $w8_260_index['follows'] ) . "\n";
		if ( [] !== $w8_260_index_bad ) {
			$w8_260_result = 'RESULT FAIL index_real:' . implode( ',', array_values( array_unique( $w8_260_index_bad ) ) );
		}
	}

	if ( 'RESULT PASS' === $w8_260_result ) {
		$w8_260_rules = [
			[ 'name' => 'Fw260 Sentinel <&> "Person"', 'url' => 'https://fw260-sentinel.example/' ],
			[ 'name' => 'Fw260 No URL Person', 'url' => '' ],
			[ 'name' => 'Fw260 Script', 'url' => \PKIW\web_url( 'javascript:alert(1)' ) ],
		];
		$w8_260_xml   = \PKIW\Follow_Archive::opml_document( $w8_260_rules );
		$w8_260_parsed = simplexml_load_string( $w8_260_xml, 'SimpleXMLElement', LIBXML_NONET );
		$w8_260_outline = false === $w8_260_parsed ? [] : ( $w8_260_parsed->xpath( '//outline' ) ?: [] );
		$w8_260_block = [ 'blockName' => 'core/paragraph', 'innerHTML' => '<p>Followed <a class="u-follow-of" href="https://fw260-sentinel.example/">https://fw260-sentinel.example/</a></p>' ];
		if ( 1 !== count( $w8_260_outline ) || 'Fw260 Sentinel <&> "Person"' !== (string) $w8_260_outline[0]['text'] || 'https://fw260-sentinel.example/' !== (string) $w8_260_outline[0]['url'] || false !== strpos( $w8_260_xml, 'Fw260 No URL Person' ) || false !== strpos( $w8_260_xml, 'Fw260 Script' ) || false !== strpos( $w8_260_xml, '<dateModified>' ) || ! \PKIW\Follow_Archive::is_generated_paragraph( $w8_260_block ) || \PKIW\Follow_Archive::is_generated_paragraph( [ 'blockName' => 'core/paragraph', 'innerHTML' => $w8_260_block['innerHTML'] . ' extra' ] ) ) {
			$w8_260_result = 'RESULT FAIL opml_rules:shape';
		}
	}

	try {
		echo defined( 'WPSEO_VERSION' ) ? "INFO yoast-260 active\n" : "WARN yoast-260 inactive\n";
	} catch ( \Throwable $w8_260_yoast_error ) {
		echo "WARN yoast-260 inactive\n";
	}
} catch ( \Throwable $w8_260_error ) {
	$w8_260_result = 'RESULT FAIL exception:' . get_class( $w8_260_error );
} finally {
	remove_filter( 'get_post_metadata', $w8_260_meta, PHP_INT_MIN );
	remove_filter( 'terms_pre_query', $w8_260_terms, PHP_INT_MIN );
	remove_filter( 'query', $w8_260_query_guard, PHP_INT_MIN );
}

if ( $w8_260_writes > 0 ) {
	$w8_260_result = 'RESULT FAIL write_attempted:' . $w8_260_writes;
}
echo $w8_260_result . "\n";
