<?php
/** On This Day route coverage. @package PKIW */
declare(strict_types=1);

use PKIW\On_This_Day;
use PKIW\Plugin;

/** @group integration */
final class OnThisDayRouteTest extends WP_UnitTestCase {
	private string $permalinks = '';

	public function set_up(): void {
		parent::set_up();
		$this->permalinks = (string) get_option( 'permalink_structure' );
		$this->set_permalink_structure( '/%postname%/' );
		On_This_Day::register_route();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( $this->permalinks );
		parent::tear_down();
	}

	public function test_rewrite_rules_and_request_scrubbing_are_exact(): void {
		$rules = (array) get_option( 'rewrite_rules' );
		$this->assertSame( 'index.php?pkiw_on_this_day=1', $rules['^on-this-day/?$'] );
		$this->assertSame( 'index.php?pkiw_on_this_day=1&paged=$matches[1]', $rules['^on-this-day/page/([0-9]{1,})/?$'] );
		foreach ( array_keys( $rules ) as $rule ) {
			$this->assertFalse( str_contains( $rule, 'on-this-day' ) && str_contains( $rule, 'feed' ), $rule );
		}
		foreach ( [ 'p' => 4, 's' => 'x', 'cat' => 1, 'embed' => 1, 'feed' => 'rss2' ] as $key => $value ) {
			$this->assertSame( [ On_This_Day::QUERY_VAR => 1, 'paged' => 2 ], On_This_Day::filter_request( [ On_This_Day::QUERY_VAR => 1, 'paged' => 2, $key => $value ] ) );
		}
	}

	public function test_version_stamp_flush_persists_both_route_rules(): void {
		update_option( 'pkiw_rewrite_version', '0.0.0' );
		delete_option( 'rewrite_rules' );
		On_This_Day::register_route();
		Plugin::get_instance()->maybe_flush_rewrite_rules();
		$rules = (array) get_option( 'rewrite_rules' );
		$this->assertArrayHasKey( '^on-this-day/?$', $rules );
		$this->assertArrayHasKey( '^on-this-day/page/([0-9]{1,})/?$', $rules );
		$this->assertSame( PKIW_VERSION, get_option( 'pkiw_rewrite_version' ) );
	}

	public function test_route_is_an_archive_and_empty_first_page_is_not_a_404(): void {
		$this->go_to( home_url( '/on-this-day/' ) );
		$this->assertTrue( is_archive() );
		$this->assertFalse( is_home() );
		$this->assertFalse( is_404() );
		$this->assertSame( 'on-this-day.php', On_This_Day::template_hierarchy( [ 'archive.php' ] )[0] );
		$this->assertTrue( On_This_Day::pre_handle_404( false, $GLOBALS['wp_query'] ) );
	}

	public function test_page_past_last_is_404_and_conflicting_vars_keep_archive_template(): void {
		$this->go_to( home_url( '/on-this-day/page/9999/' ) );
		$this->assertTrue( is_404() );

		foreach ( [ 'p' => 4, 's' => 'x', 'cat' => 1, 'embed' => 1, 'feed' => 'rss2' ] as $key => $value ) {
			$this->go_to( add_query_arg( [ On_This_Day::QUERY_VAR => 1, $key => $value ], home_url( '/' ) ) );
			$this->assertSame( 1, (int) get_query_var( On_This_Day::QUERY_VAR ), $key );
			$this->assertTrue( is_archive(), $key );
			$this->assertSame( 'on-this-day.php', On_This_Day::template_hierarchy( [ 'archive.php' ] )[0], $key );
			$this->assertSame( [ On_This_Day::QUERY_VAR => 1, 'paged' => 0 ], On_This_Day::filter_request( [ On_This_Day::QUERY_VAR => 1, 'paged' => 0, $key => $value ] ), $key );
		}
	}

	public function test_title_and_yoast_callbacks_are_route_only_and_page_aware(): void {
		$off = [ 'title' => 'Control' ];
		$this->assertSame( $off, On_This_Day::document_title_parts( $off ) );
		$this->assertNull( On_This_Day::yoast_url( null ) );
		$this->assertNull( On_This_Day::yoast_title( null ) );
		$this->go_to( home_url( '/on-this-day/page/3/' ) );
		$this->assertSame( 'On This Day', On_This_Day::document_title_parts( [ 'title' => 'Old' ] )['title'] );
		$this->assertSame( home_url( '/on-this-day/page/3/' ), On_This_Day::route_url() );
		$this->assertSame( 'On This Day - ' . get_bloginfo( 'name' ), On_This_Day::yoast_title( 'Old' ) );
		$this->assertSame( home_url( '/on-this-day/page/3/' ), On_This_Day::yoast_url( 'old' ) );
		// Yoast passes null for the Open Graph URL of a route it has no indexable for.
		$this->assertSame( home_url( '/on-this-day/page/3/' ), apply_filters( 'wpseo_opengraph_url', null, null ) );
		$this->assertSame( home_url( '/on-this-day/page/3/' ), apply_filters( 'wpseo_canonical', '', null ) );
		$this->assertNotNull( apply_filters( 'wpseo_title', null, null ) );
	}
}
