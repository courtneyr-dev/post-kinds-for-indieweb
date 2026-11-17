<?php
/** Zine selection privacy tests. @package PKIW */
declare(strict_types=1);

use PKIW\Public_Listing;
use PKIW\Zine_Manifest;

require_once __DIR__ . '/ZineTestCase.php';

/** @group integration */
final class ZineSelectionPrivacyTest extends ZineTestCase {
	public function test_sql_uses_public_listing_subquery_without_a_meta_join(): void {
		$this->post( 'Visible' );
		$query = $this->archive_query();
		$this->assertStringContainsString( "post_status = 'publish'", $query->request );
		$this->assertStringContainsString( "post_password = ''", $query->request );
		$this->assertStringContainsString( 'NOT EXISTS ( SELECT 1', $query->request );
		foreach ( Public_Listing::meta_keys() as $key ) {
			$this->assertStringContainsString( $key, $query->request );
		}
		$this->assertStringNotContainsString( 'INNER JOIN ' . $GLOBALS['wpdb']->postmeta, $query->request );
	}

	public function test_double_rows_are_excluded_in_both_orders_and_do_not_change_total(): void {
		$this->post( 'Visible' );
		$baseline = $this->manifest( $this->archive_query() );
		$key = Public_Listing::meta_keys()[0];
		foreach ( [ [ '', 'quiet_public' ], [ 'quiet_public', '' ] ] as $index => $values ) {
			$id = $this->post( 'Double ' . $index );
			foreach ( $values as $value ) {
				add_post_meta( $id, $key, $value );
			}
			$this->assertFalse( Public_Listing::is_listable( $id ) );
		}
		$after = $this->manifest( $this->archive_query() );
		$this->assertSame( $baseline['total'], $after['total'], 'A meta_query != implementation would count a double-row post through its public row.' );
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

	public function test_d1_seam_changes_only_zine_selection(): void {
		$id = $this->post( 'ActivityPub private' );
		add_post_meta( $id, Public_Listing::meta_keys()[0], 'private' );
		Zine_Manifest::override_narrowed_visibility_for_tests( [] );
		$this->assertContains( $id, $this->manifest( $this->archive_query() )['included'] );
		Zine_Manifest::override_narrowed_visibility_for_tests( [ 'private' ] );
		$this->assertNotContains( $id, $this->manifest( $this->archive_query() )['included'] );
		$this->assertTrue( Public_Listing::is_listable( $id ) );
	}

	public function test_d2_default_excludes_people_kinds_and_filter_can_restore_them(): void {
		$this->assertSame( [ 'follow', 'tag' ], Zine_Manifest::excluded_kinds() );
		add_filter( 'pkiw_zine_excluded_kinds', '__return_empty_array' );
		$this->assertSame( [], Zine_Manifest::excluded_kinds() );
	}
}
