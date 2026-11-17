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

	public function test_feed_embed_and_rest_main_queries_are_not_changed(): void {
		foreach ( [ 'feed', 'embed', 'rest' ] as $case ) {
			$query = new WP_Query();
			$query->init();
			$query->is_main_query = true;
			$query->is_tax        = true;
			$query->set( Zine_Request::VAR_PAGES, '8' );
			if ( 'feed' === $case ) {
				$query->is_feed = true;
			} elseif ( 'embed' === $case ) {
				$query->is_embed = true;
			}
			Zine_Export::filter_main_query( $query );
			$this->assertSame( '', $query->get( 'pkiw_public_listing' ), $case );
		}
	}
}
