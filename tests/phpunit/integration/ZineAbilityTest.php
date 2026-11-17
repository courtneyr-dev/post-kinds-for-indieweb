<?php
/** Zine manifest ability coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Abilities_Manager;
use PKIW\Abilities\Zine_Abilities;
use PKIW\Meta_Fields;
use PKIW\Public_Eligibility;
use PKIW\Public_Listing;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;
use PKIW\Zine_Export;
use PKIW\Zine_Manifest;
use PKIW\Zine_Request;

require_once __DIR__ . '/ZineTestCase.php';

/** @group integration */
final class ZineAbilityTest extends ZineTestCase {
	private int $admin_id = 0;

	private int $subscriber_id = 0;

	private int $eval_page_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->subscriber_id );
		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( 'post-kinds/zine-manifest' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
	}

	public function test_registration_lists_and_schemas_are_frozen(): void {
		$ability = $this->ability();
		$this->assertSame(
			[
				'post-kinds/list-kinds',
				'post-kinds/list-kind-fields',
				'post-kinds/get-kind',
				'post-kinds/get-post-meta',
				'post-kinds/get-quote-index',
				'post-kinds/get-trip-facts',
				'post-kinds/get-itinerary-facts',
				'post-kinds/get-rsvp-facts',
				'post-kinds/get-follow-index',
				'post-kinds/get-tag-facts',
				'post-kinds/get-on-this-day',
				'post-kinds/kind-year-stats',
				'post-kinds/zine-manifest',
				'post-kinds/list-presentation-event-types',
				'post-kinds/get-collection-items',
				'post-kinds/lookup-music',
				'post-kinds/lookup-video',
				'post-kinds/lookup-book',
				'post-kinds/lookup-podcast',
				'post-kinds/lookup-venue',
				'post-kinds/lookup-game',
				'post-kinds/lookup-comic',
				'post-kinds/get-surface',
				'post-kinds/get-public-location-fields',
				'post-kinds/get-checkin-stats',
				'post-kinds/list-venues',
			],
			Abilities_Manager::MCP_PUBLIC_ABILITIES
		);
		$this->assertSame(
			[
				'post-kinds/list-kinds',
				'post-kinds/list-kind-fields',
				'post-kinds/create-post',
				'post-kinds/set-kind',
				'post-kinds/get-kind',
				'post-kinds/update-post-meta',
				'post-kinds/get-post-meta',
				'post-kinds/get-quote-index',
				'post-kinds/get-trip-facts',
				'post-kinds/get-itinerary-facts',
				'post-kinds/get-rsvp-facts',
				'post-kinds/get-follow-index',
				'post-kinds/get-tag-facts',
				'post-kinds/get-on-this-day',
				'post-kinds/kind-year-stats',
				'post-kinds/create-wrapped-draft',
				'post-kinds/zine-manifest',
				'post-kinds/list-presentation-event-types',
				'post-kinds/reclassify-presentations',
				'post-kinds/get-collection-items',
				'post-kinds/lookup-music',
				'post-kinds/lookup-video',
				'post-kinds/lookup-book',
				'post-kinds/lookup-podcast',
				'post-kinds/lookup-venue',
				'post-kinds/lookup-game',
				'post-kinds/lookup-comic',
				'post-kinds/backfill-surfaces',
				'post-kinds/backfill-card-meta',
				'post-kinds/backfill-listen-media-type',
				'post-kinds/backfill-rsvp-meta',
				'post-kinds/backfill-tag-labels',
				'post-kinds/backfill-featured-artwork',
				'post-kinds/retry-featured-artwork',
				'post-kinds/get-surface',
				'post-kinds/get-public-location-fields',
				'post-kinds/get-checkin-stats',
				'post-kinds/list-venues',
			],
			Abilities_Manager::get_ability_names()
		);
		$this->assertTrue( $ability->get_meta()['mcp']['public'] ?? false );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] ?? false );
		$this->assertSame(
			[ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			$ability->get_meta()['annotations']
		);

		$input = $ability->get_input_schema();
		$this->assertFalse( $input['additionalProperties'] );
		$this->assertSame( [ 'source' ], $input['required'] );
		$this->assertSame( [ 'archive', 'stream' ], $input['properties']['source']['enum'] );
		foreach ( [ 'pages', 'from', 'to', 'orderby', 'order' ] as $name ) {
			$this->assertArrayNotHasKey( 'enum', $input['properties'][ $name ], $name );
		}
		$this->assertSame( 'integer', $input['properties']['pages']['type'] );
		$this->assertArrayNotHasKey( 'minimum', $input['properties']['pages'] );
		$this->assertArrayNotHasKey( 'pattern', $input['properties']['from'] );
		$this->assertArrayNotHasKey( 'format', $input['properties']['to'] );

		$output = $ability->get_output_schema();
		$this->assertFalse( $output['additionalProperties'] );
		$this->assertFalse( $output['properties']['entries']['items']['additionalProperties'] );
		$this->assertSame( [ 'id', 'title', 'kind', 'date', 'permalink', 'page' ], $output['properties']['entries']['items']['required'] );
		$this->assertFalse( $output['properties']['contents']['items']['additionalProperties'] );
		$this->assertFalse( $output['properties']['span']['oneOf'][1]['additionalProperties'] );
		$this->assertFalse( $output['properties']['imposition']['additionalProperties'] );
		$this->assertFalse( $output['properties']['imposition']['properties']['sides']['items']['additionalProperties'] );
		$this->assertFalse( $output['properties']['imposition']['properties']['sides']['items']['properties']['cells']['items']['additionalProperties'] );

		$zine_names = array_values( array_filter( array_keys( wp_get_abilities() ), static fn( string $name ): bool => str_contains( $name, 'zine' ) ) );
		$this->assertSame( [ 'post-kinds/zine-manifest' ], $zine_names, 'No other zine ability is registered.' );
	}

	public function test_archive_and_stream_outputs_validate_and_invalid_arguments_are_uniform(): void {
		$recipe = $this->post( 'Ability recipe' );
		$page   = $this->stream_page();
		$ability = $this->ability();
		foreach (
			[
				[ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 8 ],
				[ 'source' => 'stream', 'page_id' => $page, 'pages' => 8 ],
			] as $input
		) {
			$output = $ability->execute( $input );
			$this->assertIsArray( $output );
			$this->assertTrue( rest_validate_value_from_schema( $output, $ability->get_output_schema() ) );
			$this->assertContains( $recipe, $output['included'] );
			$this->assertArrayNotHasKey( 'kindLabel', $output['entries'][0] );
		}
		$direct = Zine_Abilities::instance()->execute( [ 'source' => 'stream', 'page_id' => (string) $page, 'pages' => '8' ] );
		$this->assertIsArray( $direct );
		$this->assertContains( $recipe, $direct['included'] );
		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/post-kinds/zine-manifest/run' );
		$request->set_query_params( [ 'input' => [ 'source' => 'stream', 'page_id' => (string) $page, 'pages' => '8' ] ] );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $recipe, $response->get_data()['included'] );

		$invalid = [
			[ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 9 ],
			[ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 0 ],
			[ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 12 ],
			[ 'source' => 'archive', 'kind' => 'recipe', 'from' => '2026-02-30' ],
			[ 'source' => 'archive', 'kind' => 'recipe', 'from' => '2026-04-02', 'to' => '2026-04-01' ],
			[ 'source' => 'archive', 'pages' => 8 ],
			[ 'source' => 'stream', 'pages' => 8 ],
			[ 'source' => 'stream', 'page_id' => 0, 'pages' => 8 ],
			[ 'source' => 'archive', 'kind' => 'recipe', 'orderby' => 'rand' ],
			[ 'source' => 'archive', 'kind' => 'recipe', 'order' => 'sideways' ],
		];
		foreach ( $invalid as $input ) {
			$error = $ability->execute( $input );
			$this->assertWPError( $error );
			$this->assertSame( 'invalid_zine_args', $error->get_error_code() );
			$this->assertSame( [ 'status' => 400 ], $error->get_error_data() );
		}
		// Core rejects '8.5' on the registered path first, so call the provider directly.
		$fraction = Zine_Abilities::instance()->execute( [ 'source' => 'archive', 'kind' => 'recipe', 'pages' => '8.5' ] );
		$this->assertWPError( $fraction );
		$this->assertSame( 'invalid_zine_args', $fraction->get_error_code() );
	}

	public function test_privacy_sentinels_and_double_rows_stay_out_of_archive_and_stream_outputs(): void {
		$local = $this->post( 'Sentinel Local Included N4K2' );
		add_post_meta( $local, Public_Listing::meta_keys()[0], 'local' );
		$hidden = [];
		$hidden['password'] = $this->post( 'Sentinel Password N4K2', [ 'post_password' => 'secret' ] );
		$hidden['private']  = $this->post( 'Sentinel Private N4K2', [ 'post_status' => 'private' ] );
		$hidden['draft']    = $this->post( 'Sentinel Draft N4K2', [ 'post_status' => 'draft' ] );
		$hidden['quiet']    = $this->post( 'Sentinel Quiet N4K2' );
		add_post_meta( $hidden['quiet'], Public_Listing::meta_keys()[0], 'quiet_public' );
		$hidden['follow'] = $this->post( 'Sentinel Follow Password N4K2', [ 'post_password' => 'secret' ], 'follow' );

		$venue = $this->post( 'Sentinel Hidden Venue N4K2', [ 'post_content' => '<!-- wp:paragraph --><p>Body without a location.</p><!-- /wp:paragraph -->' ], 'checkin' );
		update_post_meta( $venue, Meta_Fields::PREFIX . 'checkin_name', 'Sentinel Hidden Venue N4K2' );
		update_post_meta( $venue, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		Title_Privacy::mark_location_title( $venue );
		$location = $this->post( 'Private location entry N4K2' );
		update_post_meta( $location, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		update_post_meta( $location, Meta_Fields::PREFIX . 'geo_latitude', '12.987654' );
		update_post_meta( $location, Meta_Fields::PREFIX . 'geo_longitude', '-45.123456' );
		update_post_meta( $location, Meta_Fields::PREFIX . 'checkin_address', '9 Sentinel Way N4K2' );

		$ability = $this->ability();
		$before  = $ability->execute( [ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 16 ] );
		$this->assertIsArray( $before );
		$page          = $this->stream_page();
		$stream_before = $ability->execute( [ 'source' => 'stream', 'page_id' => $page, 'pages' => 16 ] );
		$this->assertIsArray( $stream_before );
		$key = Public_Listing::meta_keys()[0];
		$doubles = [];
		foreach ( [ [ '', 'quiet_public' ], [ 'quiet_public', '' ] ] as $index => $values ) {
			$id = $this->post( 'Sentinel Double Row ' . $index . ' N4K2' );
			foreach ( $values as $value ) {
				add_post_meta( $id, $key, $value );
			}
			$doubles[] = $id;
		}
		$archive = $ability->execute( [ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 16 ] );
		$stream  = $ability->execute( [ 'source' => 'stream', 'page_id' => $page, 'pages' => 16 ] );
		$checkin = $ability->execute( [ 'source' => 'archive', 'kind' => 'checkin', 'pages' => 16 ] );
		$this->assertIsArray( $archive );
		$this->assertIsArray( $stream );
		$this->assertIsArray( $checkin );
		$this->assertSame( $before['total'], $archive['total'] );
		$this->assertSame( $stream_before['total'], $stream['total'] );
		$this->assertSame( $this->eligibleOracleCount( 'recipe' ), $archive['total'] );
		$this->assertContains( $local, $archive['included'] );
		$this->assertContains( $local, $stream['included'] );
		$this->assertContains( $venue, $stream['included'], 'The generated title fixture must exercise redaction on an included row.' );
		$this->assertContains( $venue, $checkin['included'], 'The generated title fixture must exercise archive redaction on an included row.' );
		$this->assertContains( $location, $archive['included'], 'The private location fixture must exercise shape redaction on an included row.' );
		foreach ( array_merge( array_values( $hidden ), $doubles ) as $id ) {
			$this->assertNotContains( $id, $archive['included'] );
			$this->assertNotContains( $id, $stream['included'] );
		}
		$encoded = wp_json_encode( [ $archive, $stream, $checkin ] );
		$this->assertIsString( $encoded );
		foreach ( [ 'Sentinel Password N4K2', 'Sentinel Private N4K2', 'Sentinel Draft N4K2', 'Sentinel Quiet N4K2', 'Sentinel Follow Password N4K2', 'Sentinel Hidden Venue N4K2', '12.987654', '-45.123456', '9 Sentinel Way N4K2' ] as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $encoded );
		}
		$this->assertNoForbiddenKeys( $archive );
		$this->assertNoForbiddenKeys( $stream );
		$this->assertNoForbiddenKeys( $checkin );
	}

	public function test_d1_and_d2_selection_rules_match_the_manifest_contract(): void {
		$this->post( 'D1 control' );
		$private = $this->post( 'D1 private visibility' );
		add_post_meta( $private, Public_Listing::meta_keys()[0], 'private' );
		$ability = $this->ability();
		Zine_Manifest::override_narrowed_visibility_for_tests( [] );
		$wide = $ability->execute( [ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 8 ] );
		$this->assertIsArray( $wide );
		$this->assertContains( $private, $wide['included'] );
		$this->assertSame( 2, $wide['total'] );
		Zine_Manifest::override_narrowed_visibility_for_tests( [ 'private' ] );
		$narrow = $ability->execute( [ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 8 ] );
		$this->assertIsArray( $narrow );
		$this->assertNotContains( $private, $narrow['included'] );
		$this->assertSame( 1, $narrow['total'] );
		$this->assertTrue( Public_Listing::is_listable( $private ) );

		$follow = $this->post( 'D2 eligible follow', [], 'follow' );
		$page   = $this->stream_page();
		$stream = $ability->execute( [ 'source' => 'stream', 'page_id' => $page, 'pages' => 8 ] );
		$this->assertIsArray( $stream );
		$this->assertNotContains( $follow, $stream['included'] );
		$unknown = $ability->execute( [ 'source' => 'archive', 'kind' => 'n4-k2-unknown', 'pages' => 8 ] );
		foreach ( [ 'follow', 'tag' ] as $kind ) {
			$this->assertSame( $this->errorShape( $unknown ), $this->errorShape( $ability->execute( [ 'source' => 'archive', 'kind' => $kind, 'pages' => 8 ] ) ) );
		}
	}

	public function test_not_found_refusals_are_identical_for_admin_and_subscriber(): void {
		$hidden_term = wp_insert_term( 'N4 K2 Hidden Only', Taxonomy::TAXONOMY, [ 'slug' => 'n4-k2-hidden-only' ] );
		$this->assertIsArray( $hidden_term );
		$hidden = $this->post( 'Sentinel hidden-only post N4K2', [ 'post_password' => 'secret' ], null );
		wp_set_object_terms( $hidden, 'n4-k2-hidden-only', Taxonomy::TAXONOMY );
		$shown = $this->post( 'Role visible N4K2' );
		$quiet = $this->post( 'Sentinel role quiet N4K2' );
		add_post_meta( $quiet, Public_Listing::meta_keys()[0], 'quiet_public' );
		$password = $this->post( 'Sentinel role password N4K2', [ 'post_password' => 'secret' ] );
		$private_page  = $this->stream_page( [ 'post_status' => 'private', 'post_name' => 'private-stream' ] );
		$draft_page    = $this->stream_page( [ 'post_status' => 'draft', 'post_name' => 'draft-stream' ] );
		$password_page = $this->stream_page( [ 'post_password' => 'secret', 'post_name' => 'password-stream' ] );
		$quiet_page    = $this->stream_page( [ 'post_name' => 'quiet-stream' ] );
		add_post_meta( $quiet_page, Public_Listing::meta_keys()[0], 'quiet_public' );
		$plain_page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:query {"query":{"inherit":false}} /-->' ] );

		$ability = $this->ability();
		$baseline = $this->errorShape( $ability->execute( [ 'source' => 'archive', 'kind' => 'n4-k2-unknown', 'pages' => 8 ] ) );
		$this->assertSame( [ 'not_found', 'Zine source not found.', [ 'status' => 404 ] ], $baseline );
		$archive_inputs = [
			[ 'source' => 'archive', 'kind' => 'n4-k2-unknown', 'pages' => 8 ],
			[ 'source' => 'archive', 'kind' => 'follow', 'pages' => 8 ],
			[ 'source' => 'archive', 'kind' => 'n4-k2-hidden-only', 'pages' => 8 ],
		];
		$not_a_page = $this->post( 'Not a page N4K2', [], null );
		$page_ids   = [ 987654321, $not_a_page, $private_page, $draft_page, $password_page, $quiet_page, $plain_page ];
		foreach ( [ $this->admin_id, $this->subscriber_id ] as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( $archive_inputs as $input ) {
				$this->assertSame( $baseline, $this->errorShape( $ability->execute( $input ) ) );
			}
			foreach ( $page_ids as $page_id ) {
				$this->assertSame( $baseline, $this->errorShape( $ability->execute( [ 'source' => 'stream', 'page_id' => $page_id, 'pages' => 8 ] ) ) );
			}
			$visible = $ability->execute( [ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 8 ] );
			$this->assertIsArray( $visible );
			$this->assertSame( [ $shown ], $visible['included'] );
		}
	}

	public function test_archive_matches_print_view_and_independent_total_oracle(): void {
		for ( $index = 1; $index <= 7; $index++ ) {
			$this->post( 'Archive parity ' . $index, [ 'post_date' => sprintf( '2025-03-%02d 12:00:00', 10 + $index ) ] );
		}
		$this->render_document( $this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ), $this->subscriber_id );
		$print = Zine_Export::manifest();
		wp_set_current_user( $this->subscriber_id );
		$actual = $this->ability()->execute( [ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 8 ] );
		$this->assertIsArray( $actual );
		$this->assertSame( $print['included'], $actual['included'] );
		$this->assertSame( $this->eligibleOracleCount( 'recipe' ), $actual['total'] );
	}

	public function test_stream_rest_mcp_and_cli_contexts_match_the_print_view(): void {
		$outside = [];
		for ( $index = 1; $index <= 16; $index++ ) {
			$id = $this->post( sprintf( 'Stream parity %02d', $index ), [ 'post_date' => sprintf( '2025-02-%02d 12:00:00', $index ) ] );
			if ( 0 !== $index % 4 ) {
				add_post_meta( $id, '_pkiw_surface', 'stream' );
			} else {
				$outside[] = $id;
			}
		}
		$page = $this->stream_page();
		$surface = static function ( array $vars ): array {
			if ( is_page( 'stream' ) ) {
				$vars['meta_query'][] = [ 'key' => '_pkiw_surface', 'value' => 'stream' ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Page-conditioned site-filter fixture.
			}
			return $vars;
		};
		add_filter( 'query_loop_block_query_vars', $surface );
		try {
			$this->render_document( add_query_arg( Zine_Request::VAR_PAGES, 8, get_permalink( $page ) ), $this->subscriber_id );
			$expected = Zine_Export::manifest()['included'];
			$this->assertNotSame( [], $expected );
			foreach ( $outside as $outside_id ) {
				$this->assertNotContains( $outside_id, $expected, 'The non-stream fixtures make the page-context mutation observable.' );
			}

			wp_set_current_user( $this->subscriber_id );
			// Readonly abilities use GET with input query parameters on the core run route.
			$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/post-kinds/zine-manifest/run' );
			$request->set_query_params( [ 'input' => [ 'source' => 'stream', 'page_id' => $page, 'pages' => 8 ] ] );
			$response = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $expected, $response->get_data()['included'] );

			$prior = $GLOBALS['wp_query'] ?? null;
			try {
				$GLOBALS['wp_query'] = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Frozen MCP/WP-CLI caller context.
				$mcp = $this->ability()->execute( [ 'source' => 'stream', 'page_id' => $page, 'pages' => 8 ] );
				$this->assertIsArray( $mcp );
				$this->assertSame( $expected, $mcp['included'] );
				Public_Eligibility::override_cli_for_tests( true );
				$cli = $this->ability()->execute( [ 'source' => 'stream', 'page_id' => $page, 'pages' => 8 ] );
				$this->assertIsArray( $cli );
				$this->assertSame( $expected, $cli['included'] );
			} finally {
				Public_Eligibility::override_cli_for_tests( null );
				if ( $prior instanceof WP_Query ) {
					$GLOBALS['wp_query'] = $prior; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the request context.
				} else {
					unset( $GLOBALS['wp_query'] );
				}
			}
			// Mutation control: remove the page-context stand-in at the top of Zine_Manifest::stream_query_vars().
		} finally {
			remove_filter( 'query_loop_block_query_vars', $surface );
		}
	}

	public function test_eval_fixture_passes_and_detects_a_missing_ability(): void {
		$this->seedEvalFixtures();
		$this->assertSame( 'RESULT PASS', $this->runEval( $this->evalFixture() ) );
		$this->assertNotNull( wp_unregister_ability( 'post-kinds/zine-manifest' ) );
		try {
			$this->assertSame( 'RESULT FAIL seam_missing:post-kinds/zine-manifest', $this->runEval( $this->evalFixture() ) );
		} finally {
			$this->resetAbilitiesRegistry();
			wp_get_abilities();
		}
	}

	public function test_eval_shape_probe_skips_kinds_whose_posts_are_all_hidden(): void {
		$this->seedEvalFixtures();
		$terms = get_terms( [ 'taxonomy' => Taxonomy::TAXONOMY, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC', 'number' => 3 ] );
		$this->assertIsArray( $terms );
		$this->assertCount( 3, $terms );
		foreach ( $terms as $term ) {
			$this->assertInstanceOf( WP_Term::class, $term );
			if ( 'recipe' === $term->slug ) {
				continue;
			}
			$this->post( 'Sentinel hidden-only probe ' . $term->slug . ' N4K2', [ 'post_password' => 'secret' ], $term->slug );
		}
		$this->assertSame( 'RESULT PASS', $this->runEval( $this->evalFixture() ) );
	}

	public function test_eval_fault_controls_name_cases_zero_two_three_and_four(): void {
		$this->seedEvalFixtures();
		$write = $this->insertAfterSpan( 0, '$GLOBALS[\'wpdb\']->query( "UPDATE {$GLOBALS[\'wpdb\']->options} SET option_value = option_value WHERE 1 = 0" );' );
		$this->assertSame( 'RESULT FAIL write_attempted:1', $this->runEval( $write ) );

		$shape = str_replace(
			"\t\t\tif ( ! is_array( \$valid ) ) {",
			"\t\t\tif ( is_array( \$valid ) ) { \$valid['author'] = 'leak'; }\n\t\t\tif ( ! is_array( \$valid ) ) {",
			$this->evalFixture()
		);
		$this->assertSame( 'RESULT FAIL ability_shape', $this->runEval( $shape ) );

		$no_block = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>No zine block.</p><!-- /wp:paragraph -->' ] );
		$this->eval_page_id = $no_block;
		$gate = str_replace(
			"\t\t\$gate = \$run( [ 'source' => 'stream', 'page_id' => \$page_id, 'pages' => 8 ] );",
			"\t\t\$gate = \$run( [ 'source' => 'stream', 'page_id' => \$page_id, 'pages' => 8 ] );\n\t\t\$gate = [];",
			$this->evalFixture()
		);
		$this->assertSame( 'RESULT FAIL stream_gate', $this->runEval( $gate ) );

		$this->eval_page_id = 0;
		$rows = str_replace(
			"\t\t\$actual = \$run( [ 'source' => 'archive', 'kind' => \$term->slug, 'pages' => 8 ] );",
			"\t\t\$actual = \$run( [ 'source' => 'archive', 'kind' => \$term->slug, 'pages' => 8 ] );\n\t\tif ( is_array( \$actual ) ) { \$actual['included'] = array_reverse( \$actual['included'] ); }",
			$this->evalFixture()
		);
		$this->assertMatchesRegularExpression( '/^RESULT FAIL ability_rows:\d+(?:,\d+)*$/', $this->runEval( $rows ) );
	}

	public function test_eval_names_stream_parity_and_not_found_shape_faults(): void {
		$this->seedEvalFixtures();
		$parity = str_replace(
			"\t\t\$actual = \$run( [ 'source' => 'stream', 'page_id' => \$page_id, 'pages' => 8 ] );",
			"\t\t\$actual = \$run( [ 'source' => 'stream', 'page_id' => \$page_id, 'pages' => 8 ] );\n\t\tif ( is_array( \$actual ) ) { \$actual['included'] = array_reverse( \$actual['included'] ); }",
			$this->evalFixture()
		);
		$this->assertNotSame( $this->evalFixture(), $parity );
		$this->assertSame( 'RESULT FAIL stream_parity_rows', $this->runEval( $parity ) );

		$shape = str_replace( "\$missing = \$run( [ 'source' => 'stream', 'page_id' => 987654321, 'pages' => 8 ] );", '$missing = [];', $this->evalFixture() );
		$this->assertNotSame( $this->evalFixture(), $shape );
		$this->assertSame( 'RESULT FAIL ability_shape', $this->runEval( $shape ) );
	}

	public function test_eval_passes_with_no_posts_and_with_nobody_able_to_call_the_ability(): void {
		$this->assertSame( 'RESULT PASS', $this->runEval( $this->evalFixture() ) );
		$this->seedEvalFixtures();
		$none = static fn(): array => [];
		add_filter( 'users_pre_query', $none, 10, 0 );
		try {
			$this->assertSame( 'RESULT PASS', $this->runEval( $this->evalFixture() ) );
		} finally {
			remove_filter( 'users_pre_query', $none, 10 );
		}
	}

	public function test_eval_fixture_text_is_portable_and_has_one_span_per_case(): void {
		$text = $this->evalFixture();
		for ( $case = 0; $case <= 4; $case++ ) {
			$this->assertSame( 1, substr_count( $text, '/*N4K2:' . $case . '*/' ) );
			$this->assertSame( 1, substr_count( $text, '/*N4K2:/' . $case . '*/' ) );
		}
		$this->assertStringStartsWith( '$page_id = 37724;', $text );
		$this->assertStringNotContainsString( '<?php', $text );
		$this->assertStringNotContainsString( 'override_', $text );
		preg_match_all( '/\b[1-9]\d{3,}\b/', $text, $matches );
		$this->assertSame( [ '37724', '987654321' ], array_values( array_unique( $matches[0] ) ), 'Only the required page placeholder and nonexistent-page sentinel may be literal post IDs.' );
	}

	private function ability(): WP_Ability {
		$this->assertTrue( wp_has_ability( 'post-kinds/zine-manifest' ), 'The zine manifest ability must be registered.' );
		$ability = wp_get_ability( 'post-kinds/zine-manifest' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		return $ability;
	}

	private function errorShape( mixed $value ): array {
		$this->assertWPError( $value );
		return [ $value->get_error_code(), $value->get_error_message(), $value->get_error_data() ];
	}

	private function assertNoForbiddenKeys( array $value ): void {
		$forbidden = [ 'author', 'content', 'excerpt', 'image', 'location', 'latitude', 'longitude', 'address', 'venue' ];
		$found = [];
		$walk = static function ( array $items ) use ( &$walk, &$found, $forbidden ): void {
			foreach ( $items as $key => $item ) {
				if ( is_string( $key ) && in_array( strtolower( $key ), $forbidden, true ) ) {
					$found[] = strtolower( $key );
				}
				if ( is_array( $item ) ) {
					$walk( $item );
				}
			}
		};
		$walk( $value );
		$this->assertSame( [], array_values( array_unique( $found ) ) );
	}

	private function eligibleOracleCount( string $kind ): int {
		global $wpdb;
		$keys = Public_Listing::meta_keys();
		$key_marks = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE p.post_status = %s AND p.post_password = %s AND tt.taxonomy = %s AND t.slug = %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ( {$key_marks} ) AND m.meta_value = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Core tables plus generated placeholders.
			array_merge( [ 'publish', '', Taxonomy::TAXONOMY, $kind ], $keys, [ 'quiet_public' ] )
		);
		$ids = Public_Listing::filter_ids( array_map( 'intval', $wpdb->get_col( $sql ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent test oracle.
		$ids = array_values( array_filter( $ids, [ Zine_Manifest::class, 'passes_narrowing' ] ) );
		return count( $ids );
	}

	private function seedEvalFixtures(): void {
		$this->post( 'Eval newest', [ 'post_date' => '2025-03-10 12:00:00' ] );
		$this->post( 'Eval older', [ 'post_date' => '2025-03-09 12:00:00' ] );
		$this->eval_page_id = $this->stream_page();
	}

	private function insertAfterSpan( int $case, string $code ): string {
		$marker = '/*N4K2:/' . $case . '*/';
		return str_replace( $marker, $marker . "\n" . $code . "\n", $this->evalFixture() );
	}

	private function evalFixture(): string {
		$text = file_get_contents( dirname( __DIR__ ) . '/fixtures/privacy-evals/x-n4-k2-zine-manifest.php' );
		$this->assertIsString( $text );
		return $text;
	}

	private function runEval( string $code ): string {
		if ( 0 < $this->eval_page_id ) {
			$code = str_replace( '$page_id = 37724;', '$page_id = ' . $this->eval_page_id . ';', $code );
		}
		ob_start();
		try {
			eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- The live gate evaluates these exact bytes.
		} finally {
			$output = (string) ob_get_clean();
		}
		$lines = preg_split( '/\R/', trim( $output ) );
		return (string) end( $lines );
	}

	private function resetAbilitiesRegistry(): void {
		$property = new ReflectionProperty( WP_Abilities_Registry::class, 'instance' );
		$property->setValue( null, null );
	}
}
