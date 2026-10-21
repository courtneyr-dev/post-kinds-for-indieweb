<?php
/**
 * Issue privacy and location-suppression boundaries.
 *
 * @package PKIW
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/fixtures/issue-render-state-reset.php';

use PKIW\Abilities\Core_Abilities;
use PKIW\Issue_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class IssuePrivacyTest extends WP_UnitTestCase {

	use Issue_Render_State_Reset;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Issue_Archive() )->register();
		Core_Abilities::reset();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->reset_render_state();
		set_query_var( 'atproto', null );
		wp_reset_postdata();
		wp_set_current_user( 0 );
		Core_Abilities::reset();
		parent::tear_down();
	}

	private function issue( bool $protected ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => $protected ? 'Protected issue' : 'Public issue',
				'post_content'  => '<p>Ticket body.</p>',
				'post_password' => $protected ? 'ticket-secret' : '',
			]
		);
		wp_set_object_terms( $post_id, 'issue', Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://a.example.net/acorn-ledger' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_name', 'acorn-ledger' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_number', $protected ? '110' : '101' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_status', 'open' );

		return (int) $post_id;
	}

	private function content( int $post_id ): string {
		$this->go_to( (string) get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$content = get_the_content( null, false, get_post( $post_id ) );

		return apply_filters( 'the_content', $content );
	}

	private function stream( int $post_id ): string {
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );

		return do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );
	}

	public function test_protected_issue_hides_facts_single_details_stream_cite_and_ability_meta(): void {
		$post_id = $this->issue( true );
		$this->assertSame( [], Issue_Archive::facts( $post_id ) );
		$single = $this->content( $post_id );
		$this->assertStringNotContainsString( 'pkiw-issue-facts', $single );
		$this->assertStringNotContainsString( 'acorn-ledger', $single );
		$this->assertStringNotContainsString( '110', $single );

		$stream = $this->stream( $post_id );
		$this->assertStringContainsString( 'pk-card--protected', $stream );
		$this->assertStringNotContainsString( 'acorn-ledger', $stream );
		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $post_id ] );
		$this->assertSame( [], $result['meta'] );
	}

	public function test_rest_redacts_protected_issue_meta_for_visitors_and_returns_it_to_an_administrator(): void {
		$post_id = $this->issue( true );
		$public  = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) )->get_data();
		foreach ( array_keys( $public['meta'] ?? [] ) as $key ) {
			$this->assertFalse( str_starts_with( $key, Meta_Fields::PREFIX . 'cite_' ) || str_starts_with( $key, Meta_Fields::PREFIX . 'issue_' ), $key );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$admin = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) )->get_data();
		$this->assertSame( 'acorn-ledger', $admin['meta'][ Meta_Fields::PREFIX . 'cite_name' ] );
		$this->assertSame( '110', $admin['meta'][ Meta_Fields::PREFIX . 'issue_number' ] );
	}

	public function test_atproto_projection_and_all_issue_views_add_no_location_markup(): void {
		$post_id = $this->issue( false );
		$this->go_to( (string) get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		set_query_var( 'atproto', 1 );
		$this->assertStringNotContainsString( 'pkiw-issue-facts', Issue_Archive::decorate_content( '<p>Projection.</p>' ) );
		set_query_var( 'atproto', null );

		$views = [ $this->content( $post_id ), $this->stream( $post_id ) ];
		foreach ( $views as $html ) {
			foreach ( [ 'geo', 'p-location', 'h-adr', 'p-geo' ] as $private ) {
				$this->assertStringNotContainsString( $private, $html );
			}
		}
	}
}
