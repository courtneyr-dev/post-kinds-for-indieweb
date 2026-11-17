<?php
/** Zine selection privacy tests. @package PKIW */
declare(strict_types=1);

use PKIW\Public_Listing;
use PKIW\Public_Eligibility;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;
use PKIW\Zine_Export;
use PKIW\Zine_Manifest;
use PKIW\Zine_Request;

require_once __DIR__ . '/ZineTestCase.php';

/** @group integration */
final class ZineSelectionPrivacyTest extends ZineTestCase {
	public function test_sql_uses_public_listing_and_governed_kind_subqueries_under_both_d1_values(): void {
		$this->post( 'Visible' );
		Public_Eligibility::override_cli_for_tests( true );
		foreach ( [ 'A' => [], 'B' => [ 'private' ] ] as $label => $visibility ) {
			Zine_Manifest::override_narrowed_visibility_for_tests( $visibility );
			$sql = '';
			$capture = static function ( string $request, WP_Query $query ) use ( &$sql ): string {
				if ( true === $query->get( 'pkiw_public_listing' ) ) {
					$sql = $request;
				}
				return $request;
			};
			add_filter( 'posts_request', $capture, 10, 2 );
			try {
				$result = Zine_Manifest::build( [ 'source' => 'archive', 'kind' => 'recipe', 'pages' => 8 ] );
				$this->assertIsArray( $result );
			} finally {
				remove_filter( 'posts_request', $capture, 10 );
			}
			$this->assertNotSame( '', $sql );
			$this->assertStringContainsString( "post_status = 'publish'", $sql, $label );
			$this->assertStringContainsString( "post_password = ''", $sql, $label );
			$this->assertStringContainsString( 'NOT EXISTS ( SELECT 1', $sql, $label );
			$this->assertStringContainsString( 'pkiw_public_tr.object_id', $sql, $label . ' must force the governed-kind clause under CLI.' );
			$this->assertStringContainsString( "pkiw_apv.meta_value IN ( 'quiet_public' )", $sql, $label );
			$this->assertStringNotContainsString( "pkiw_apv.meta_value IN ( 'private' )", $sql, $label );
			if ( 'A' === $label ) {
				$this->assertStringNotContainsString( 'pkiw_zine_apv', $sql );
			} else {
				$this->assertStringContainsString( "pkiw_zine_apv.meta_value IN ( 'private' )", $sql );
			}
			foreach ( Public_Listing::meta_keys() as $key ) {
				$this->assertStringContainsString( $key, $sql );
				$this->assertStringNotContainsString( 'INNER JOIN ' . $GLOBALS['wpdb']->postmeta . ' ', $sql, $key );
			}
		}
	}

	/** @dataProvider hidden_cases */
	public function test_hidden_sentinels_are_absent_from_every_zine_surface( string $case ): void {
		$kind      = 'recipe';
		$sentinels = [ 'Sentinel Hidden Title Qx7' ];
		$args      = [];
		$included  = null;
		if ( 'password' === $case ) {
			$args['post_password'] = 'secret';
		} elseif ( 'private-status' === $case ) {
			$args['post_status'] = 'private';
		} elseif ( 'draft' === $case ) {
			$args['post_status'] = 'draft';
		} elseif ( 'follow-password' === $case ) {
			$kind                  = 'follow';
			$args['post_password'] = 'secret';
			add_filter( 'pkiw_zine_excluded_kinds', '__return_empty_array' );
		}
		$this->post( 'Sentinel Public Control Qx7', [], $kind );
		if ( 'hidden-venue' === $case ) {
			$kind = 'checkin';
			$this->post( 'Sentinel Public Control Qx7', [], $kind );
			$id = $this->post( 'Sentinel Hidden Venue Qx7', [ 'post_date' => '2025-03-09 12:00:00', 'post_content' => '<!-- wp:paragraph --><p>Check-in body without location data.</p><!-- /wp:paragraph -->' ], $kind );
			update_post_meta( $id, Meta_Fields::PREFIX . 'checkin_name', 'Sentinel Hidden Venue Qx7' );
			update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
			Title_Privacy::mark_location_title( $id );
			$sentinels = [ 'Sentinel Hidden Venue Qx7' ];
			$included  = $id;
		} elseif ( 'private-location' === $case ) {
			$id = $this->post( 'Private location entry', [], $kind );
			update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
			update_post_meta( $id, Meta_Fields::PREFIX . 'geo_latitude', '12.987654' );
			update_post_meta( $id, Meta_Fields::PREFIX . 'geo_longitude', '-45.123456' );
			update_post_meta( $id, Meta_Fields::PREFIX . 'checkin_address', '9 Sentinel Way Qx7' );
			$sentinels = [ '12.987654', '-45.123456', '9 Sentinel Way Qx7' ];
			$included  = $id;
		} else {
			$id = $this->post( 'Sentinel Hidden Title Qx7', $args, $kind );
			if ( 'quiet-public' === $case ) {
				add_post_meta( $id, Public_Listing::meta_keys()[0], 'quiet_public' );
			}
		}

		if ( null !== $included ) {
			$manifest = Zine_Manifest::build( [ 'source' => 'archive', 'kind' => $kind, 'pages' => 8 ] );
			$this->assertIsArray( $manifest );
			$this->assertContains( $included, $manifest['included'], 'The fixture must prove redaction on an included entry.' );
		}
		$this->assert_sentinels_absent( $kind, $sentinels );
	}

	public static function hidden_cases(): array {
		return [
			'password protected' => [ 'password' ],
			'private status'     => [ 'private-status' ],
			'draft'              => [ 'draft' ],
			'quiet public'       => [ 'quiet-public' ],
			'follow password'    => [ 'follow-password' ],
			'hidden venue'       => [ 'hidden-venue' ],
			'private location'   => [ 'private-location' ],
		];
	}

	public function test_local_visibility_is_included_in_manifest_and_print_document(): void {
		$id = $this->post( 'Sentinel Local Included Qx7' );
		add_post_meta( $id, Public_Listing::meta_keys()[0], 'local' );
		$manifest = $this->manifest( $this->archive_query() );
		$this->assertContains( $id, $manifest['included'] );
		$document = $this->render_document( $this->archive_url( [ 'pkiw_zine_pages' => 8 ] ) );
		$this->assertSame( $this->print_template(), $document['template'] );
		$this->assertStringContainsString( 'Sentinel Local Included Qx7', $document['html'] );
	}

	public function test_double_rows_are_excluded_in_both_orders_and_do_not_change_total(): void {
		$this->post( 'Visible' );
		$baseline = $this->manifest( $this->archive_query() );
		$key = Public_Listing::meta_keys()[0];
		$titles = [];
		foreach ( [ [ '', 'quiet_public' ], [ 'quiet_public', '' ] ] as $index => $values ) {
			$title    = 'Sentinel Double Row ' . $index . ' Qx7';
			$titles[] = $title;
			$id       = $this->post( $title );
			foreach ( $values as $value ) {
				add_post_meta( $id, $key, $value );
			}
			$this->assertFalse( Public_Listing::is_listable( $id ) );
		}
		$after = $this->manifest( $this->archive_query() );
		$this->assertSame( $baseline['total'], $after['total'], 'A meta_query != implementation would count a double-row post through its public row.' );
		$this->assertSame( [ 'Visible' ], array_column( $after['entries'], 'title' ) );
		$this->assert_sentinels_absent( 'recipe', $titles );
	}

	public function test_hidden_posts_past_capacity_never_reach_total_on_the_print_main_query(): void {
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->post( 'Visible past capacity ' . $i, [ 'post_date' => sprintf( '2025-03-%02d 12:00:00', 10 + $i ) ] );
		}
		$key   = Public_Listing::meta_keys()[0];
		$quiet = $this->post( 'Sentinel Past Quiet Qx7', [ 'post_date' => '2024-01-01 12:00:00' ] );
		add_post_meta( $quiet, $key, 'quiet_public' );
		foreach ( [ [ '', 'quiet_public' ], [ 'quiet_public', '' ] ] as $index => $values ) {
			$id = $this->post( 'Sentinel Past Double ' . $index . ' Qx7', [ 'post_date' => sprintf( '2024-01-%02d 12:00:00', 2 + $index ) ] );
			foreach ( $values as $value ) {
				add_post_meta( $id, $key, $value );
			}
		}
		$this->post( 'Sentinel Past Password Qx7', [ 'post_date' => '2024-01-05 12:00:00', 'post_password' => 'secret' ] );

		// The hidden posts sort after the 6 fetched entries, so only the SQL keeps them out of found_posts (spec I7).
		$html     = $this->render_document( $this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ) )['html'];
		$manifest = Zine_Export::manifest();
		$this->assertSame( 7, $manifest['total'] );
		$this->assertCount( 6, $manifest['included'] );
		$this->assertStringContainsString( '6 of 7 posts', $html );
		$this->assertStringNotContainsString( 'Sentinel Past', $html );
	}

	public function test_php_recheck_drops_a_post_changed_after_sql_and_lowers_total(): void {
		$first = $this->post( 'First' );
		$this->post( 'Second' );
		$key = Public_Listing::meta_keys()[0];
		$inject = static function ( array $posts ) use ( $first, $key ): array {
			add_post_meta( $first, $key, 'quiet_public' );
			return $posts;
		};
		add_filter( 'posts_results', $inject, 10, 1 );
		try {
			$manifest = $this->manifest( $this->archive_query() );
		} finally {
			remove_filter( 'posts_results', $inject, 10 );
		}
		$this->assertSame( 1, $manifest['total'] );
		$this->assertNotContains( $first, $manifest['included'] );
	}

	public function test_d1_seam_changes_only_zine_selection_and_not_plain_archive(): void {
		$this->post( 'D1 public control' );
		$id = $this->post( 'Sentinel D1 Private Qx7' );
		add_post_meta( $id, Public_Listing::meta_keys()[0], 'private' );
		Zine_Manifest::override_narrowed_visibility_for_tests( [] );
		$under_a = $this->manifest( $this->archive_query() );
		$this->assertContains( $id, $under_a['included'] );
		$this->assertSame( 2, $under_a['total'] );
		$this->assertStringContainsString( 'Sentinel D1 Private Qx7', $this->render_document( $this->archive_url( [ 'pkiw_zine_pages' => 8 ] ) )['html'] );
		Zine_Manifest::override_narrowed_visibility_for_tests( [ 'private' ] );
		$under_b = $this->manifest( $this->archive_query() );
		$this->assertNotContains( $id, $under_b['included'] );
		$this->assertSame( 1, $under_b['total'] );
		$this->assertStringNotContainsString( 'Sentinel D1 Private Qx7', $this->render_document( $this->archive_url( [ 'pkiw_zine_pages' => 8 ] ) )['html'] );
		$this->assert_sentinels_absent( 'recipe', [ 'Sentinel D1 Private Qx7' ] );
		$this->assertTrue( Public_Listing::is_listable( $id ) );
		$this->go_to( $this->archive_url() );
		$this->assertContains( $id, array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
	}

	public function test_d2_default_excludes_people_kinds_from_stream_and_blocks(): void {
		$this->assertSame( [ 'follow', 'tag' ], Zine_Manifest::excluded_kinds() );
		$recipe = $this->post( 'Stream recipe control' );
		$follow = $this->post( 'Sentinel Stream Follow Qx7', [], 'follow' );
		$page   = $this->stream_page();
		$result = Zine_Manifest::build( [ 'source' => 'stream', 'page_id' => $page, 'pages' => 8 ] );
		$this->assertIsArray( $result );
		$this->assertContains( $recipe, $result['included'] );
		$this->assertNotContains( $follow, $result['included'] );
		$document = $this->render_document( add_query_arg( 'pkiw_zine_pages', 8, get_permalink( $page ) ) );
		$this->assertStringNotContainsString( 'Sentinel Stream Follow Qx7', $document['html'] );
		foreach ( [ 'follow', 'tag' ] as $slug ) {
			$term = get_term_by( 'slug', $slug, Taxonomy::TAXONOMY );
			$this->assertInstanceOf( WP_Term::class, $term );
			$this->go_to( get_term_link( $term ) );
			$this->assertSame( '', Zine_Export::render_block( [ 'source' => 'archive' ] ) );
		}
	}

	/** Assert sentinels stay out of print, archive block, count and contents. */
	private function assert_sentinels_absent( string $kind, array $sentinels ): void {
		$term = get_term_by( 'slug', $kind, Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		$url = get_term_link( $term );
		$this->assertIsString( $url );
		$block    = $this->render_archive_block( $url );
		$document = $this->render_document( add_query_arg( 'pkiw_zine_pages', 8, $url ) );
		$this->assertSame( $this->print_template(), $document['template'] );
		$dom = new DOMDocument();
		$dom->loadHTML( $document['html'], LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath    = new DOMXPath( $dom );
		$count    = (string) $xpath->evaluate( 'string(//*[contains(concat(" ", normalize-space(@class), " "), " pkiw-zine-toolbar__count ")])' );
		$contents = (string) $xpath->evaluate( 'string(//section[@data-pkiw-zine-page="2"])' );
		foreach ( $sentinels as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $document['html'], 'print document' );
			$this->assertStringNotContainsString( $sentinel, $block, 'archive block' );
			$this->assertStringNotContainsString( $sentinel, $count, 'count line' );
			$this->assertStringNotContainsString( $sentinel, $contents, 'contents list' );
		}
	}
}
