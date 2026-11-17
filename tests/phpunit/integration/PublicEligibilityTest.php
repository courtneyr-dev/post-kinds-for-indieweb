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

	public function enqueue_regex( array|string $regex ): void {
		$this->regex = array_merge( $this->regex, (array) $regex );
	}
}

/** @group integration */
final class PublicEligibilityTest extends WP_UnitTestCase {
	/** @var WP_REST_Server|null */
	private $rest_server_before_test = null;

	private bool $rest_server_replaced = false;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function tear_down(): void {
		Public_Eligibility::override_cli_for_tests( null );
		wp_set_current_user( 0 );
		remove_role( 'pkiw_partial_editor' );
		unset( $GLOBALS['pkiw_test_pantheon_cache'] );
		$GLOBALS['current_screen'] = null;
		if ( $this->rest_server_replaced ) {
			$GLOBALS['wp_rest_server'] = $this->rest_server_before_test;
		}
		parent::tear_down();
	}

	private function initialize_rest_server( callable $register ): WP_REST_Server {
		$this->rest_server_before_test = $GLOBALS['wp_rest_server'] ?? null;
		$this->rest_server_replaced    = true;
		add_action( 'rest_api_init', $register );
		$GLOBALS['wp_rest_server'] = null;
		try {
			return rest_get_server();
		} finally {
			remove_action( 'rest_api_init', $register );
		}
	}

	private function governed( string $status = 'publish', string $password = '', string $kind = 'tag' ): int {
		$args = [ 'post_status' => $status, 'post_password' => $password ];
		if ( 'future' === $status ) {
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS );
			$args['post_date_gmt'] = $args['post_date'];
		}
		$id = self::factory()->post->create( $args );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		return (int) $id;
	}

	public function test_truth_table_and_helpers_are_user_independent(): void {
		$rows = [
			'publish'   => $this->governed(),
			'password'  => $this->governed( 'publish', 'secret' ),
			'private'   => $this->governed( 'private' ),
			'draft'     => $this->governed( 'draft' ),
			'pending'   => $this->governed( 'pending' ),
			'future'    => $this->governed( 'future' ),
			'trash'     => $this->governed( 'trash' ),
			'auto-draft' => $this->governed( 'auto-draft' ),
			'inherit'   => $this->governed( 'inherit' ),
		];

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'administrator' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( $rows as $name => $id ) {
				$this->assertSame( 'publish' === $name, Public_Eligibility::is_eligible( $id ), $name );
			}
			$this->assertSame( [ $rows['publish'] ], Public_Eligibility::filter_ids( array_values( $rows ) ) );
		}
		$this->assertFalse( Public_Eligibility::is_eligible( null ) );
		$this->assertFalse( Public_Eligibility::is_eligible( 0 ) );

		register_post_type( 'pkiw_hidden_test', [ 'public' => false ] );
		$hidden_type = self::factory()->post->create( [ 'post_type' => 'pkiw_hidden_test', 'post_status' => 'publish' ] );
		$this->assertFalse( Public_Eligibility::is_eligible( $hidden_type ) );
		unregister_post_type( 'pkiw_hidden_test' );

		global $wpdb;
		$ids = implode( ',', array_map( 'intval', array_values( $rows ) ) );
		$sql_ids = array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID IN ( {$ids} ) AND " . Public_Eligibility::sql( 'p' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Frozen predicate agreement test over factory IDs.
		$this->assertSame( [ $rows['publish'] ], $sql_ids );
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

	public function test_cron_path_runs_the_clause_while_interactive_cli_skips_it(): void {
		$hidden = $this->governed( 'publish', 'secret' );
		Public_Eligibility::override_cli_for_tests( true );
		$interactive = new WP_Query( [ 'post__in' => [ $hidden ], 'fields' => 'ids' ] );
		Public_Eligibility::override_cli_for_tests( false );
		$cron = new WP_Query( [ 'post__in' => [ $hidden ], 'fields' => 'ids' ] );
		Public_Eligibility::override_cli_for_tests( null );
		$this->assertSame( [ $hidden ], array_map( 'intval', $interactive->posts ) );
		$this->assertSame( [], $cron->posts );
	}

	public function test_query_filter_uses_attachment_parent_clause_without_the_kind_clause(): void {
		global $wpdb;

		$attachment_query = new WP_Query();
		$attachment_query->set( 'post_type', 'attachment' );
		$attachment_where = Public_Eligibility::filter_where( ' WHERE 1=1', $attachment_query );
		$this->assertStringContainsString( 'post_parent NOT IN', $attachment_where );
		$this->assertStringContainsString( 'pkiw_attachment_parent', $attachment_where );
		$this->assertStringNotContainsString( "AND NOT ( {$wpdb->posts}.ID IN", $attachment_where );

		$comic_query = new WP_Query();
		$comic_query->set( Taxonomy::TAXONOMY, 'comics' );
		$this->assertStringContainsString( 'pkiw_public_tr', Public_Eligibility::filter_where( ' WHERE 1=1', $comic_query ) );

		$tag_query = new WP_Query();
		$tag_query->set( Taxonomy::TAXONOMY, 'tag' );
		$this->assertStringContainsString( 'pkiw_public_tr', Public_Eligibility::filter_where( ' WHERE 1=1', $tag_query ) );
	}

	public function test_null_object_type_taxonomy_wraps_after_it_is_attached_to_post(): void {
		register_taxonomy( 'pkiw_r4_null', null, [ 'public' => true ] );
		try {
			$taxonomy = get_taxonomy( 'pkiw_r4_null' );
			$this->assertInstanceOf( WP_Taxonomy::class, $taxonomy );
			$this->assertSame( [], $taxonomy->object_type );

			$this->assertTrue( register_taxonomy_for_object_type( 'pkiw_r4_null', 'post' ) );
			$this->assertSame( [ Public_Eligibility::class, 'update_term_count' ], get_taxonomy( 'pkiw_r4_null' )->update_count_callback );
		} finally {
			unregister_taxonomy( 'pkiw_r4_null' );
		}
	}

	public function test_attachment_filter_covers_any_and_skips_interactive_cli_unless_forced(): void {
		$hidden_parent = $this->governed( 'publish', 'secret' );
		$public_parent = $this->governed();
		$hidden_media  = (int) self::factory()->attachment->create_object( 'r4-any-hidden.jpg', $hidden_parent, [ 'post_mime_type' => 'image/jpeg' ] );
		$public_media  = (int) self::factory()->attachment->create_object( 'r4-any-public.jpg', $public_parent, [ 'post_mime_type' => 'image/jpeg' ] );
		$query_args    = [
			'post_status'    => 'inherit',
			'post__in'       => [ $hidden_media, $public_media ],
			'fields'         => 'ids',
			'posts_per_page' => 10,
		];

		$any = new WP_Query( [ 'post_type' => 'any' ] + $query_args );
		$this->assertSame( [ $public_media ], array_map( 'intval', $any->posts ) );
		$mixed = new WP_Query( [ 'post_type' => [ 'post', 'attachment' ] ] + $query_args );
		$this->assertSame( [ $public_media ], array_map( 'intval', $mixed->posts ) );

		Public_Eligibility::override_cli_for_tests( true );
		try {
			$interactive = new WP_Query( [ 'post_type' => 'any' ] + $query_args );
			$this->assertEqualsCanonicalizing( [ $hidden_media, $public_media ], array_map( 'intval', $interactive->posts ) );

			$forced = new WP_Query( [ 'post_type' => 'any', 'pkiw_public_only' => true ] + $query_args );
			$this->assertSame( [ $public_media ], array_map( 'intval', $forced->posts ) );
		} finally {
			Public_Eligibility::override_cli_for_tests( null );
		}
	}

	public function test_removed_kind_relationships_and_bulk_recount_fix_term_counts(): void {
		global $wpdb;

		$post = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
		wp_set_post_tags( $post, [ 'pkiw-r4-removed-kind' ] );
		wp_set_object_terms( $post, [ 'follow' ], Taxonomy::TAXONOMY );
		$removed_kind_term = get_term_by( 'slug', 'pkiw-r4-removed-kind', 'post_tag' );
		$this->assertInstanceOf( WP_Term::class, $removed_kind_term );
		$this->assertSame( 0, $removed_kind_term->count );
		$this->assertTrue( wp_remove_object_terms( $post, 'follow', Taxonomy::TAXONOMY ) );
		$this->assertSame( 1, get_term( $removed_kind_term->term_id, 'post_tag' )->count );

		$hidden = $this->governed( 'publish', 'secret' );
		$public = $this->governed();
		wp_set_post_tags( $hidden, [ 'pkiw-r4-stale-hidden' ] );
		wp_set_post_tags( $public, [ 'pkiw-r4-stale-public' ] );
		$hidden_term = get_term_by( 'slug', 'pkiw-r4-stale-hidden', 'post_tag' );
		$public_term = get_term_by( 'slug', 'pkiw-r4-stale-public', 'post_tag' );
		$this->assertInstanceOf( WP_Term::class, $hidden_term );
		$this->assertInstanceOf( WP_Term::class, $public_term );
		$wpdb->update( $wpdb->term_taxonomy, [ 'count' => 1 ], [ 'term_taxonomy_id' => $hidden_term->term_taxonomy_id ] );
		$wpdb->update( $wpdb->term_taxonomy, [ 'count' => 0 ], [ 'term_taxonomy_id' => $public_term->term_taxonomy_id ] );
		clean_term_cache( [ $hidden_term->term_id, $public_term->term_id ], 'post_tag' );
		$this->assertSame( 1, get_term( $hidden_term->term_id, 'post_tag' )->count );
		$this->assertSame( 0, get_term( $public_term->term_id, 'post_tag' )->count );

		$touched = Public_Eligibility::recount_governed_terms();
		$this->assertContains( (int) $hidden_term->term_taxonomy_id, $touched );
		$this->assertContains( (int) $public_term->term_taxonomy_id, $touched );
		$this->assertSame( 0, get_term( $hidden_term->term_id, 'post_tag' )->count );
		$this->assertSame( 1, get_term( $public_term->term_id, 'post_tag' )->count );
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

		$legacy = $this->governed();
		wp_update_post( [ 'ID' => $legacy, 'post_content' => '<p>Tagged <a class="u-tag-of" href="https://tg261-anchor.example/p/x">target</a> and <a href="https://unrelated.example/y">other</a></p>' ] );
		$this->assertSame( [ 'https://unrelated.example/y' ], Public_Eligibility::filter_enclosure_links( [ 'https://TG261-anchor.example/p/x/', 'https://unrelated.example/y' ], $legacy ) );

		$note = self::factory()->post->create();
		$unchanged = [ 'https://a.example/x y', 'https://a.example/x y', 'gopher://c.example/' ];
		$this->assertSame( $unchanged, Public_Eligibility::filter_webmention_links( $unchanged, $note ) );
	}

	public function test_metadata_gate_accepts_null_callback_arguments_and_covers_every_hidden_status(): void {
		$note = self::factory()->post->create();
		$this->assertIsArray( get_post_meta( $note, null ) );
		$this->assertNotNull( get_metadata( 'post', $note, 'x', null ) );
		foreach ( [ 'private', 'draft', 'pending', 'future', 'trash' ] as $status ) {
			$id = $this->governed( $status );
			$this->assertSame( '1', get_post_meta( $id, 'webmentions_disabled_pings', true ), $status );
		}
		$this->assertSame( '1', get_post_meta( $this->governed( 'publish', 'secret' ), 'webmentions_disabled_pings', true ) );
		$this->assertSame( '', get_post_meta( $this->governed(), 'webmentions_disabled_pings', true ) );
		$this->assertSame( '', get_post_meta( $note, 'webmentions_disabled_pings', true ) );
	}

	public function test_rest_item_and_redirect_filters_match_a_missing_post(): void {
		$hidden  = $this->governed( 'private' );
		set_query_var( 'p', $hidden );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $hidden );
		$request->set_param( 'id', $hidden );
		$handler = [ 'callback' => [ new WP_REST_Posts_Controller( 'post' ), 'get_item' ] ];
		$result  = Public_Eligibility::rest_request_before( null, $handler, $request );
		try {
			$this->assertWPError( $result );
			$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
			$this->assertSame( 404, $result->get_error_data()['status'] );
			$this->assertFalse( Public_Eligibility::filter_redirect_canonical( 'https://example.test/leak', '' ) );
			$this->assertSame( 0, Public_Eligibility::filter_old_slug_redirect( $hidden ) );
		} finally {
			Public_Eligibility::rest_request_after( $result, $handler, $request );
		}
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

		foreach ( [ [ $public_comment, $hidden_comment ], [ $hidden_comment, $public_comment ] ] as [ $outer, $inner ] ) {
			PKIW_Test_Webmention_Sender::$calls = 0;
			$nested = static function ( int $comment_id ) use ( $outer, $inner ): void {
				static $running = false;
				if ( ! $running && $outer === $comment_id ) {
					$running = true;
					do_action( 'comment_post', $inner, 1, [] );
					$running = false;
				}
			};
			add_action( 'comment_post', $nested, 5, 1 );
			do_action( 'comment_post', $outer, 1, [] );
			remove_action( 'comment_post', $nested, 5 );
			$this->assertSame( 1, PKIW_Test_Webmention_Sender::$calls, 'Only the eligible post sends in either nesting order.' );
		}
	}

	public function test_rest_edit_skip_requires_all_three_row_capabilities_and_restores_after_exceptions(): void {
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
		$server = $this->initialize_rest_server(
			static function (): void {
				register_rest_route(
					'pkiw-test/v1',
					'/context',
					[
						'methods'             => 'GET',
						'permission_callback' => '__return_true',
						'callback'            => static fn(): array => [ 'where' => Public_Eligibility::filter_where( '', new WP_Query() ) ],
					]
				);
				register_rest_route(
					'pkiw-test/v1',
					'/throw',
					[
						'methods'             => 'GET',
						'permission_callback' => '__return_true',
						'callback'            => static function (): void {
							throw new RuntimeException( 'REST callback failed.' );
						},
					]
				);
			}
		);
		$request = new WP_REST_Request( 'GET', '/pkiw-test/v1/context' );
		$request->set_param( 'context', 'edit' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'pkiw_partial_editor' ] ) );
		$response = $server->dispatch( $request );
		$this->assertStringContainsString( 'term_relationships', (string) $response->get_data()['where'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$response = $server->dispatch( $request );
		$this->assertSame( '', $response->get_data()['where'] );

		$throw = new WP_REST_Request( 'GET', '/pkiw-test/v1/throw' );
		$throw->set_param( 'context', 'edit' );
		try {
			$server->dispatch( $throw );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'REST callback failed.', $exception->getMessage() );
		} finally {
			apply_filters( 'rest_request_after_callbacks', null, [], $throw );
		}
		$this->assertStringContainsString( 'term_relationships', Public_Eligibility::filter_where( '', new WP_Query() ) );
	}

	public function test_public_only_overrides_admin_and_rest_edit_skips(): void {
		$public = $this->governed();
		$hidden = $this->governed( 'publish', 'secret' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );
		$query = new WP_Query( [ 'post__in' => [ $public, $hidden ], 'fields' => 'ids', 'pkiw_public_only' => true ] );
		$this->assertSame( [ $public ], array_map( 'intval', $query->posts ) );
		set_current_screen( 'front' );

		$server = $this->initialize_rest_server(
			static function () use ( $public, $hidden ): void {
				register_rest_route(
					'pkiw-test/v1',
					'/public-only',
					[
						'methods'             => 'GET',
						'permission_callback' => '__return_true',
						'callback'            => static fn(): array => ( new WP_Query( [ 'post__in' => [ $public, $hidden ], 'fields' => 'ids', 'pkiw_public_only' => true ] ) )->posts,
					]
				);
			}
		);
		$request = new WP_REST_Request( 'GET', '/pkiw-test/v1/public-only' );
		$request->set_param( 'context', 'edit' );
		$this->assertSame( [ $public ], array_map( 'intval', $server->dispatch( $request )->get_data() ) );
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

	public function test_multi_kind_and_removed_kind_purge_the_governed_archives(): void {
		if ( ! function_exists( 'Pantheon_Cache' ) ) {
			function Pantheon_Cache(): object { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Pantheon API test double.
				$GLOBALS['pkiw_test_pantheon_cache'] ??= new PKIW_Test_Pantheon_Cache();
				return $GLOBALS['pkiw_test_pantheon_cache'];
			}
		}
		$GLOBALS['pkiw_test_pantheon_cache'] = new PKIW_Test_Pantheon_Cache();
		$id = $this->governed();
		wp_set_object_terms( $id, [ 'comics', 'tag' ], Taxonomy::TAXONOMY );
		Public_Eligibility::purge_after_update( $id, get_post( $id ), get_post( $id ) );
		$this->assertContains( home_url( '/kind/tag/' ), $GLOBALS['pkiw_test_pantheon_cache']->urls );

		$GLOBALS['pkiw_test_pantheon_cache'] = new PKIW_Test_Pantheon_Cache();
		wp_set_object_terms( $id, [ 'comics' ], Taxonomy::TAXONOMY );
		$this->assertContains( home_url( '/kind/tag/' ), $GLOBALS['pkiw_test_pantheon_cache']->urls );
	}

	public function test_resetting_same_governed_term_does_not_purge(): void {
		if ( ! function_exists( 'Pantheon_Cache' ) ) {
			function Pantheon_Cache(): object { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Pantheon API test double.
				$GLOBALS['pkiw_test_pantheon_cache'] ??= new PKIW_Test_Pantheon_Cache();
				return $GLOBALS['pkiw_test_pantheon_cache'];
			}
		}
		$id = $this->governed();
		$GLOBALS['pkiw_test_pantheon_cache'] = new PKIW_Test_Pantheon_Cache();
		wp_set_object_terms( $id, [ 'tag' ], Taxonomy::TAXONOMY );
		$this->assertSame( [], $GLOBALS['pkiw_test_pantheon_cache']->urls );
		$this->assertSame( [], $GLOBALS['pkiw_test_pantheon_cache']->regex );
	}

	public function test_one_time_recount_schedules_runs_and_records_its_version(): void {
		delete_option( Public_Eligibility::RECOUNT_OPTION );
		wp_clear_scheduled_hook( Public_Eligibility::RECOUNT_HOOK );
		try {
			Public_Eligibility::maybe_schedule_recount();
			$this->assertNotFalse( wp_next_scheduled( Public_Eligibility::RECOUNT_HOOK ) );

			$term   = self::factory()->tag->create_and_get( [ 'slug' => 'pkiw-recount-stale' ] );
			$hidden = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
			wp_set_object_terms( $hidden, 'tag', Taxonomy::TAXONOMY );
			wp_set_post_tags( $hidden, [ 'pkiw-recount-stale' ] );
			$public = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			wp_set_post_tags( $public, [ 'pkiw-recount-stale' ] );
			global $wpdb;
			$wpdb->update( $wpdb->term_taxonomy, [ 'count' => 2 ], [ 'term_taxonomy_id' => $term->term_taxonomy_id ] ); // A count stored before this release.
			clean_term_cache( $term->term_id, 'post_tag' );

			wp_defer_term_counting( true );
			try {
				Public_Eligibility::run_recount_event();
			} finally {
				wp_defer_term_counting( false );
			}
			$this->assertSame( 1, get_term( $term->term_id, 'post_tag' )->count, 'Recount runs even while counting is deferred.' );
			$this->assertSame( Public_Eligibility::RECOUNT_VERSION, get_option( Public_Eligibility::RECOUNT_OPTION ) );

			wp_clear_scheduled_hook( Public_Eligibility::RECOUNT_HOOK );
			Public_Eligibility::maybe_schedule_recount();
			$this->assertFalse( wp_next_scheduled( Public_Eligibility::RECOUNT_HOOK ), 'A completed version never reschedules.' );
		} finally {
			delete_option( Public_Eligibility::RECOUNT_OPTION );
			wp_clear_scheduled_hook( Public_Eligibility::RECOUNT_HOOK );
		}
	}

	public function test_recount_skips_while_locked_and_clears_its_event_on_completion(): void {
		$lock = Public_Eligibility::RECOUNT_OPTION . '_lock';
		delete_option( Public_Eligibility::RECOUNT_OPTION );
		wp_clear_scheduled_hook( Public_Eligibility::RECOUNT_HOOK );
		try {
			add_option( $lock, time(), '', false );
			Public_Eligibility::run_recount_event();
			$this->assertFalse( get_option( Public_Eligibility::RECOUNT_OPTION ), 'R8-S2: a second run must not run while another holds the lock.' );

			update_option( $lock, time() - 2 * HOUR_IN_SECONDS, false );
			wp_schedule_single_event( time() + 60, Public_Eligibility::RECOUNT_HOOK );
			Public_Eligibility::run_recount_event();
			$this->assertSame( Public_Eligibility::RECOUNT_VERSION, get_option( Public_Eligibility::RECOUNT_OPTION ), 'A stale lock is taken over.' );
			$this->assertFalse( wp_next_scheduled( Public_Eligibility::RECOUNT_HOOK ), 'R8-S2: a queued duplicate event is cleared on completion.' );
			$this->assertFalse( get_option( $lock ), 'The lock is released.' );
		} finally {
			delete_option( $lock );
			delete_option( Public_Eligibility::RECOUNT_OPTION );
			wp_clear_scheduled_hook( Public_Eligibility::RECOUNT_HOOK );
		}
	}

	public function test_recount_waits_until_the_kind_taxonomy_exists(): void {
		global $wp_taxonomies;

		delete_option( Public_Eligibility::RECOUNT_OPTION );
		$saved = $wp_taxonomies[ Taxonomy::TAXONOMY ] ?? null;
		unset( $wp_taxonomies[ Taxonomy::TAXONOMY ] );
		try {
			Public_Eligibility::run_recount_event();
			$this->assertFalse( get_option( Public_Eligibility::RECOUNT_OPTION ), 'R8-S3: completion is never recorded with core counts.' );
		} finally {
			if ( null !== $saved ) {
				$wp_taxonomies[ Taxonomy::TAXONOMY ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the registered taxonomy.
			}
			delete_option( Public_Eligibility::RECOUNT_OPTION );
		}
	}

	public function test_padding_query_filter_passes_non_strings_through(): void {
		$this->assertNull( Public_Eligibility::filter_padded_term_query( null ) );
	}
}
