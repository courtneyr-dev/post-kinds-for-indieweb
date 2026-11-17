<?php
/** Public listing contract coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Public_Listing;

/** @group integration */
final class PublicListingTest extends WP_UnitTestCase {
	private const KEY = 'activitypub_content_visibility';
	private const HIDDEN = 'quiet_public';

	public function set_up(): void {
		parent::set_up();
		Public_Listing::register();
	}

	public function test_contract_methods_and_query_args_are_exact(): void {
		foreach ( [ 'query_args', 'is_listable', 'filter_ids', 'meta_keys' ] as $method ) {
			$this->assertTrue( method_exists( Public_Listing::class, $method ) );
		}
		$this->assertSame( [ self::KEY ], Public_Listing::meta_keys() );
		$args = Public_Listing::query_args( [ 'posts_per_page' => 5 ] );
		$this->assertTrue( $args['pkiw_public_listing'] );
		$this->assertTrue( $args['pkiw_public_only'] );
		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertFalse( $args['has_password'] );
		$this->assertFalse( $args['suppress_filters'] );
	}

	public function test_sql_php_and_id_readers_agree_for_zero_local_hidden_and_double_rows(): void {
		$zero = $this->post();
		$local = $this->post( [ 'local' ] );
		$hidden = $this->post( [ self::HIDDEN ] );
		$after = $this->post( [ '', self::HIDDEN ] );
		$before = $this->post( [ self::HIDDEN, '' ] );
		$input = [ $before, $zero, $hidden, $local, $after ];

		$query = new WP_Query( Public_Listing::query_args( [ 'post__in' => $input, 'orderby' => 'post__in', 'posts_per_page' => 20 ] ) );
		$sql_ids = wp_list_pluck( $query->posts, 'ID' );
		$this->assertSame( [ $zero, $local ], array_map( 'intval', $sql_ids ) );
		$this->assertTrue( Public_Listing::is_listable( $zero ) );
		$this->assertTrue( Public_Listing::is_listable( $local ) );
		$this->assertFalse( Public_Listing::is_listable( $hidden ) );
		$this->assertFalse( Public_Listing::is_listable( $after ) );
		$this->assertFalse( Public_Listing::is_listable( $before ) );
		$this->assertSame( [ $zero, $local ], Public_Listing::filter_ids( [ $zero, $hidden, $local, $before, $after ] ), 'filter_ids() must preserve the surviving input order.' );
		$this->assertSame( 2, (int) $query->found_posts, 'Hidden metadata rows must not inflate found_posts.' );
	}

	public function test_prepared_not_exists_clause_has_no_join_or_private_value(): void {
		global $wpdb;
		$query = new WP_Query();
		$query->set( 'pkiw_public_listing', true );
		$where = Public_Listing::filter_where( ' AND 1=1', $query );
		$this->assertStringContainsString( 'NOT EXISTS ( SELECT 1 FROM', $where );
		$this->assertStringContainsString( "meta_key IN ( '" . self::KEY . "' )", $where );
		$this->assertStringContainsString( "meta_value IN ( '" . self::HIDDEN . "' )", $where );
		$this->assertStringNotContainsString( '%s', $where );
		$this->assertStringNotContainsString( "'private'", $where );

		$hidden   = $this->post( [ '', self::HIDDEN ] );
		$two_rows = $this->post( [ '', 'local' ] );
		$no_rows  = $this->post();
		$real     = new WP_Query( Public_Listing::query_args( [ 'post__in' => [ $hidden, $two_rows, $no_rows ], 'posts_per_page' => 20 ] ) );
		$this->assertStringNotContainsString( 'JOIN ' . $wpdb->postmeta, $real->request );
		// A listable post with two visibility rows would appear twice under a meta join.
		$this->assertCount( 2, $real->posts );
		$this->assertSame( count( array_unique( wp_list_pluck( $real->posts, 'ID' ) ) ), count( $real->posts ) );
		$this->assertEqualsCanonicalizing( [ $two_rows, $no_rows ], wp_list_pluck( $real->posts, 'ID' ) );
	}

	public function test_query_args_readds_removed_where_filter(): void {
		remove_filter( 'posts_where', [ Public_Listing::class, 'filter_where' ], 20 );
		$this->assertFalse( has_filter( 'posts_where', [ Public_Listing::class, 'filter_where' ] ) );
		Public_Listing::query_args( [] );
		$this->assertSame( 20, has_filter( 'posts_where', [ Public_Listing::class, 'filter_where' ] ) );
	}

	private function post( array $values = [] ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		foreach ( $values as $value ) {
			add_post_meta( $id, self::KEY, $value );
		}
		return $id;
	}
}
