<?php
/** Zine request contract tests. @package PKIW */
declare(strict_types=1);

use PKIW\Zine_Export;
use PKIW\Zine_Request;

require_once __DIR__ . '/ZineTestCase.php';

/** @group integration */
final class ZineRequestTest extends ZineTestCase {
	/** @dataProvider invalid_requests */
	public function test_validation_names_the_invalid_field( array $vars, string $field, string $message ): void {
		$query = new WP_Query();
		$query->init();
		foreach ( $vars as $key => $value ) {
			$query->set( $key, $value );
		}
		$request = Zine_Request::from_query( $query );
		$this->assertFalse( $request->is_valid() );
		$this->assertSame( $message, $request->errors()[ $field ] );
	}

	public static function invalid_requests(): array {
		return [
			'pages' => [ [ Zine_Request::VAR_PAGES => '12' ], 'pages', 'Choose 8 or 16 pages.' ],
			'from'  => [ [ Zine_Request::VAR_PAGES => '8', Zine_Request::VAR_FROM => 'banana' ], 'from', 'Enter a valid From date, like 2026-03-09.' ],
			'to'    => [ [ Zine_Request::VAR_PAGES => '8', Zine_Request::VAR_TO => '2026-02-30' ], 'to', 'Enter a valid To date, like 2026-03-09.' ],
			'order' => [ [ Zine_Request::VAR_PAGES => '8', Zine_Request::VAR_FROM => '2025-03-10', Zine_Request::VAR_TO => '2025-03-09' ], 'from', 'From must not be later than To.' ],
		];
	}

	public function test_date_bounds_are_inclusive_site_local_days(): void {
		$query = new WP_Query();
		$query->init();
		$query->set( Zine_Request::VAR_PAGES, '8' );
		$query->set( Zine_Request::VAR_FROM, '2025-03-09' );
		$query->set( Zine_Request::VAR_TO, '2025-03-10' );
		$this->assertSame(
			[ [ 'inclusive' => true, 'column' => 'post_date', 'after' => '2025-03-09 00:00:00', 'before' => '2025-03-10 23:59:59' ] ],
			Zine_Request::from_query( $query )->date_query()
		);
	}

	public function test_pages_var_alone_enables_print_request(): void {
		$query = new WP_Query();
		$query->init();
		$query->set( Zine_Request::VAR_FROM, '2025-03-09' );
		$this->assertFalse( Zine_Request::from_query( $query )->is_present() );
		$query->set( Zine_Request::VAR_PAGES, '8' );
		$this->assertTrue( Zine_Request::from_query( $query )->is_valid() );
	}

	public function test_hidden_fields_are_allow_listed_and_validate_order_values(): void {
		$query = new WP_Query();
		$query->init();
		$query->set( 'pkiw_recipe_course', 'soup' );
		$query->set( 'orderby', 'title' );
		$query->set( 'order', 'ASC' );
		$query->set( 'paged', 4 );
		$query->set( 'pkiw_follow_opml', '1' );
		$query->set( 'pkiw_quote_public', '1' );
		$this->assertSame( [ 'pkiw_recipe_course' => 'soup', 'orderby' => 'title', 'order' => 'asc' ], Zine_Export::filter_vars( $query ) );
		$query->set( 'orderby', 'rand' );
		$query->set( 'order', 'sideways' );
		$this->assertSame( [ 'pkiw_recipe_course' => 'soup' ], Zine_Export::filter_vars( $query ) );
	}

	public function test_recipe_course_and_title_order_drive_the_print_manifest(): void {
		$soup = [];
		foreach ( [ 'Zulu', 'Alpha', 'Mike', 'Bravo', 'Echo', 'Hotel', 'Delta', 'Golf', 'Foxtrot' ] as $index => $title ) {
			$id = $this->post( $title . ' recipe', [ 'post_date' => sprintf( '2025-02-%02d 12:00:00', $index + 1 ) ] );
			if ( in_array( $title, [ 'Zulu', 'Alpha', 'Mike', 'Bravo' ], true ) ) {
				wp_set_object_terms( $id, 'soup', \PKIW\recipe_fallback_course_taxonomy(), true );
				$soup[ $title ] = $id;
			}
		}
		ksort( $soup, SORT_STRING );
		$url = $this->archive_url(
			[
				'pkiw_recipe_course'  => 'soup',
				'orderby'             => 'title',
				'order'               => 'asc',
				Zine_Request::VAR_PAGES => 8,
			]
		);
		$this->go_to( $url );
		$this->assertSame( array_values( $soup ), Zine_Export::manifest()['included'] );
	}

	public function test_form_hidden_inputs_are_exactly_the_allowed_active_filters(): void {
		$soup = $this->post( 'Soup recipe for the form' );
		wp_set_object_terms( $soup, 'soup', \PKIW\recipe_fallback_course_taxonomy(), true );
		$url = $this->archive_url(
			[
				'pkiw_recipe_course' => 'soup',
				'orderby'            => 'title',
				'order'              => 'asc',
			]
		);
		$this->go_to( $url );
		// Page 4 of a one-post archive is a 404, so paged is set after routing too.
		foreach ( [ 'paged' => 4, 'pkiw_follow_opml' => 1, 'pkiw_quote_public' => 1 ] as $name => $value ) {
			$GLOBALS['wp']->query_vars[ $name ] = $value;
		}
		$GLOBALS['wp_query']->set( 'paged', 4 );
		// Set after routing: on a real request pkiw_follow_opml streams the OPML export on parse_request.
		$GLOBALS['wp_query']->set( 'pkiw_follow_opml', 1 );
		$GLOBALS['wp_query']->set( 'pkiw_quote_public', 1 );
		$html = $this->render_block_on_current_request();
		$this->assertSame(
			[ 'pkiw_recipe_course' => 'soup', 'orderby' => 'title', 'order' => 'asc' ],
			$this->hidden_inputs( $html )
		);
		$html = $this->render_archive_block( $this->archive_url( [ 'pkiw_recipe_course' => 'soup', 'orderby' => 'rand', 'order' => 'sideways' ] ) );
		$this->assertSame( [ 'pkiw_recipe_course' => 'soup' ], $this->hidden_inputs( $html ) );
	}

	public function test_feed_and_embed_requests_never_become_print_views(): void {
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->post( 'Feed control ' . $i, [ 'post_date' => sprintf( '2025-03-%02d 12:00:00', 10 + $i ) ] );
		}
		$quiet = $this->post( 'Sentinel Embed Quiet Qx7', [ 'post_date' => '2024-01-01 12:00:00' ] );
		add_post_meta( $quiet, \PKIW\Public_Listing::meta_keys()[0], 'quiet_public' );
		$this->post( 'Sentinel Embed Password Qx7', [ 'post_date' => '2024-01-02 12:00:00', 'post_password' => 'secret' ] );
		$this->go_to( add_query_arg( [ 'feed' => 'rss2', Zine_Request::VAR_PAGES => 8 ], $this->archive_url() ) );
		$this->assertTrue( is_feed() );
		$this->assertNotTrue( $GLOBALS['wp_query']->get( 'pkiw_public_listing' ), 'The feed keeps its own query.' );
		$this->assertSame( [], Zine_Export::manifest() );

		// Core keeps is_embed only on singular requests, so an archive ?embed=true is a plain archive request.
		// The guard that matters: a print view never builds from a main query filter_main_query() didn't narrow.
		remove_action( 'pre_get_posts', [ Zine_Export::class, 'filter_main_query' ], 999 );
		$this->go_to( $this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ) );
		$this->assertTrue( is_tax() );
		$this->assertSame( [], Zine_Export::manifest(), 'An unnarrowed main query would count hidden posts past the capacity.' );
		$this->assertNotSame( $this->print_template(), apply_filters( 'template_include', 'theme-template.php' ) );
		// REST requests return through wp_is_serving_rest_request(), which needs the REST_REQUEST constant and can't be set per test.
	}

	public function test_array_page_input_is_an_error_without_a_php_warning(): void {
		$this->post( 'Array input control' );
		$query = new WP_Query();
		$query->set( Zine_Request::VAR_PAGES, [ '8' ] );
		$request = Zine_Request::from_query( $query );
		$this->assertFalse( $request->is_valid() );
		$this->assertSame( 'pages', $request->first_invalid() );
	}

	/** Read hidden form controls with core's HTML processor. */
	private function hidden_inputs( string $html ): array {
		$inputs = [];
		$tags   = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( [ 'tag_name' => 'INPUT' ] ) ) {
			if ( 'hidden' !== $tags->get_attribute( 'type' ) ) {
				continue;
			}
			$name = $tags->get_attribute( 'name' );
			$this->assertIsString( $name );
			$this->assertNull( $tags->get_attribute( 'tabindex' ) );
			$inputs[ $name ] = (string) $tags->get_attribute( 'value' );
		}

		return $inputs;
	}

	public function test_a_site_date_clause_on_the_stream_loop_survives_the_zine_bounds(): void {
		$early = $this->post( 'Before the site clause', [ 'post_date' => '2025-03-01 12:00:00' ] );
		$late  = $this->post( 'After the site clause', [ 'post_date' => '2025-03-09 12:00:00' ] );
		$page  = $this->stream_page();
		$site  = static function ( array $vars ): array {
			$vars['date_query'] = [ [ 'after' => '2025-03-05 00:00:00', 'column' => 'post_date' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- Test stand-in for a site filter.
			return $vars;
		};
		add_filter( 'query_loop_block_query_vars', $site );
		try {
			foreach ( [ '', '2025-02-01' ] as $from ) {
				$manifest = \PKIW\Zine_Manifest::build( [ 'source' => 'stream', 'page_id' => $page, 'pages' => 8, 'from' => $from ] );
				$this->assertIsArray( $manifest );
				$this->assertContains( $late, $manifest['included'], $from );
				$this->assertNotContains( $early, $manifest['included'], 'The site\'s own date clause stays when From is "' . $from . '".' );
			}
		} finally {
			remove_filter( 'query_loop_block_query_vars', $site );
		}
	}


	public function test_a_paged_calendar_window_on_the_archive_never_narrows_the_zine(): void {
		$early = $this->post( 'Early calendar unit', [ 'post_date' => '2025-01-15 12:00:00' ] );
		$late  = $this->post( 'Late calendar unit', [ 'post_date' => '2025-03-15 12:00:00' ] );
		// Stand-in for Kind_Archive_Layouts::maybe_group_main_query(): a per-page date window at pre_get_posts 10.
		$window = static function ( WP_Query $query ): void {
			if ( $query->is_main_query() && $query->is_tax() ) {
				$query->set( 'date_query', [ [ 'after' => '2025-03-01 00:00:00', 'inclusive' => true ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- Test stand-in.
			}
		};
		add_action( 'pre_get_posts', $window, 10 );
		try {
			$this->go_to( $this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ) );
			$manifest = Zine_Export::manifest();
			$this->assertSame( 2, $manifest['total'], 'The zine covers the whole archive, not the paged window (C2, C4).' );
			$this->assertSame( [ $late, $early ], $manifest['included'] );
		} finally {
			remove_action( 'pre_get_posts', $window, 10 );
		}
	}

}
