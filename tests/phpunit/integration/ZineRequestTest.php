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
}
