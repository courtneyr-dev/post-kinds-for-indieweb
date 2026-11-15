<?php
/**
 * Shared public eligibility coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Public_Eligibility;
use PKIW\Taxonomy;

/** Test double for Webmention's static comment callback. */
final class PKIW_Test_Webmention_Sender {
	public static int $calls = 0;

	public static function comment_post( int $comment_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Callback signature.
		++self::$calls;
	}
}

/** Test double for Pantheon's queued cache paths. */
final class PKIW_Test_Pantheon_Cache {
	public array $urls = [];
	public array $regex = [];

	public function enqueue_urls( array $urls ): void {
		$this->urls = array_merge( $this->urls, $urls );
	}

	public function enqueue_regex( array $regex ): void {
		$this->regex = array_merge( $this->regex, $regex );
	}
}

/** @group integration */
final class PublicEligibilityTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	private function governed( string $status = 'publish', string $password = '', string $kind = 'tag' ): int {
		$id = self::factory()->post->create( [ 'post_status' => $status, 'post_password' => $password ] );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		return (int) $id;
	}

	public function test_truth_table_and_helpers_are_user_independent(): void {
		$public    = $this->governed();
		$protected = $this->governed( 'publish', 'secret' );
		$private   = $this->governed( 'private' );
		$draft     = $this->governed( 'draft' );

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'administrator' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			$this->assertTrue( Public_Eligibility::is_eligible( $public ) );
			$this->assertFalse( Public_Eligibility::is_eligible( $protected ) );
			$this->assertFalse( Public_Eligibility::is_eligible( $private ) );
			$this->assertFalse( Public_Eligibility::is_eligible( $draft ) );
			$this->assertSame( [ $public ], Public_Eligibility::filter_ids( [ $private, $public, $protected ] ) );
		}
		$this->assertSame( "p.post_status = 'publish' AND p.post_password = ''", Public_Eligibility::sql( 'p' ) );
	}

	public function test_sql_rejects_an_unsafe_alias(): void {
		$this->expectException( InvalidArgumentException::class );
		Public_Eligibility::sql( 'p; DROP TABLE' );
	}

	public function test_public_only_overrides_the_cli_skip(): void {
		$hidden = $this->governed( 'publish', 'secret' );
		Public_Eligibility::override_cli_for_tests( true );
		$query = new WP_Query( [ 'post__in' => [ $hidden ], 'pkiw_public_only' => true ] );
		Public_Eligibility::override_cli_for_tests( null );
		$this->assertSame( [], wp_list_pluck( $query->posts, 'ID' ) );
	}

	public function test_query_filter_leaves_impossible_queries_byte_identical(): void {
		$attachment_query = new WP_Query();
		$attachment_query->set( 'post_type', 'attachment' );
		$this->assertSame( ' WHERE 1=1', Public_Eligibility::filter_where( ' WHERE 1=1', $attachment_query ) );

		$comic_query = new WP_Query();
		$comic_query->set( Taxonomy::TAXONOMY, 'comics' );
		$this->assertSame( ' WHERE 1=1', Public_Eligibility::filter_where( ' WHERE 1=1', $comic_query ) );

		$tag_query = new WP_Query();
		$tag_query->set( Taxonomy::TAXONOMY, 'tag' );
		$this->assertStringContainsString( 'pkiw_public_tr', Public_Eligibility::filter_where( ' WHERE 1=1', $tag_query ) );
	}

	public function test_outbound_filters_block_hidden_posts_and_add_one_public_person_target(): void {
		$hidden = $this->governed( 'publish', 'secret' );
		$public = $this->governed();
		$content = '<!-- wp:post-kinds-indieweb/tag-card {"targetType":"person","url":"https://person.example/","title":"Person"} /-->';
		wp_update_post( [ 'ID' => $hidden, 'post_content' => $content ] );
		wp_update_post( [ 'ID' => $public, 'post_content' => $content ] );

		$this->assertSame( [], Public_Eligibility::filter_webmention_links( [ 'https://old.example/' ], $hidden ) );
		$this->assertSame( [ 'https://person.example/' ], Public_Eligibility::filter_webmention_links( [], $public ) );
		$links = [ 'https://person.example/' ];
		$pung  = [];
		Public_Eligibility::filter_pre_ping( $links, $pung, $hidden );
		$this->assertSame( [], $links );
		$this->assertSame( [], Public_Eligibility::filter_enclosure_links( [ 'https://person.example/' ], $public ) );
	}

	public function test_rest_item_and_redirect_filters_match_a_missing_post(): void {
		$hidden  = $this->governed( 'private' );
		set_query_var( 'p', $hidden );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $hidden );
		$result  = Public_Eligibility::rest_request_before( null, null, $request );
		$this->assertWPError( $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertFalse( Public_Eligibility::filter_redirect_canonical( 'https://example.test/leak', '' ) );
		$this->assertSame( 0, Public_Eligibility::filter_old_slug_redirect( $hidden ) );
		Public_Eligibility::rest_request_after( $result, null, $request );
	}

	public function test_webmention_reply_proxy_sends_only_for_the_eligible_post_and_survives_later_exceptions(): void {
		if ( ! class_exists( 'Webmention\\Sender' ) ) {
			class_alias( PKIW_Test_Webmention_Sender::class, 'Webmention\\Sender' );
		}
		$callback = [ 'Webmention\\Sender', 'comment_post' ];
		remove_all_actions( 'comment_post' );
		add_action( 'comment_post', $callback, 10, 1 );
		Public_Eligibility::replace_webmention_reply_callback();
		$this->assertFalse( has_action( 'comment_post', $callback ) );
		$this->assertSame( 10, has_action( 'comment_post', [ Public_Eligibility::class, 'proxy_webmention_reply' ] ) );

		$eligible = $this->governed();
		$hidden   = $this->governed( 'publish', 'secret' );
		$public_comment = self::factory()->comment->create( [ 'comment_post_ID' => $eligible ] );
		$hidden_comment = self::factory()->comment->create( [ 'comment_post_ID' => $hidden ] );
		PKIW_Test_Webmention_Sender::$calls = 0;
		do_action( 'comment_post', $hidden_comment, 1, [] );
		do_action( 'comment_post', $public_comment, 1, [] );
		$this->assertSame( 1, PKIW_Test_Webmention_Sender::$calls );

		$throw = static function (): void {
			throw new RuntimeException( 'Later callback failed.' );
		};
		add_action( 'comment_post', $throw, 20 );
		try {
			do_action( 'comment_post', $public_comment, 1, [] );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Later callback failed.', $exception->getMessage() );
		}
		remove_action( 'comment_post', $throw, 20 );
		do_action( 'comment_post', $hidden_comment, 1, [] );
		$this->assertSame( 2, PKIW_Test_Webmention_Sender::$calls );
	}

	public function test_rest_edit_skip_requires_all_three_row_capabilities_and_restores_nested_context(): void {
		$role = add_role(
			'pkiw_partial_editor',
			'Partial editor',
			[
				'read'               => true,
				'edit_posts'         => true,
				'edit_others_posts'  => true,
				'edit_private_posts' => true,
			]
		);
		$this->assertInstanceOf( WP_Role::class, $role );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'pkiw_partial_editor' ] ) );
		$edit = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$edit->set_param( 'context', 'edit' );
		Public_Eligibility::rest_request_before( null, [], $edit );
		$this->assertStringContainsString( 'term_relationships', Public_Eligibility::filter_where( '', new WP_Query() ) );

		$view = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$view->set_param( 'context', 'view' );
		Public_Eligibility::rest_request_before( null, [], $view );
		Public_Eligibility::rest_request_after( null, [], $view );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( '', Public_Eligibility::filter_where( '', new WP_Query() ) );
		Public_Eligibility::rest_request_after( null, [], $edit );
		remove_role( 'pkiw_partial_editor' );
	}

	public function test_public_edit_purges_every_default_path_and_filter_addition(): void {
		if ( ! function_exists( 'Pantheon_Cache' ) ) {
			function Pantheon_Cache(): object { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Pantheon API test double.
				$GLOBALS['pkiw_test_pantheon_cache'] ??= new PKIW_Test_Pantheon_Cache();
				return $GLOBALS['pkiw_test_pantheon_cache'];
			}
		}
		$GLOBALS['pkiw_test_pantheon_cache'] = new PKIW_Test_Pantheon_Cache();
		$id   = $this->governed();
		$post = get_post( $id );
		$add  = static function ( array $paths ): array {
			$paths['urls'][]  = home_url( '/extra/' );
			$paths['regex'][] = '^/extra/page/';
			return $paths;
		};
		add_filter( 'pkiw_public_eligibility_purge_urls', $add );
		Public_Eligibility::purge_after_update( $id, $post, clone $post );
		remove_filter( 'pkiw_public_eligibility_purge_urls', $add );

		$cache = $GLOBALS['pkiw_test_pantheon_cache'];
		foreach ( [ get_permalink( $post ), home_url( '/' ), home_url( '/stream/' ), home_url( '/kind/tag/' ), home_url( '/feed/' ), home_url( '/kind/tag/feed/' ), home_url( '/comments/feed/' ), home_url( '/extra/' ) ] as $url ) {
			$this->assertContains( $url, $cache->urls );
		}
		$this->assertContains( '^/kind/tag/page/', $cache->regex );
		$this->assertContains( '^/stream/page/', $cache->regex );
		$this->assertContains( '^/extra/page/', $cache->regex );
	}
}
