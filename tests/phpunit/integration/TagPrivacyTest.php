<?php
/**
 * Tag privacy-surface coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Public_Eligibility;
use PKIW\Microformats;
use PKIW\Collection_Items;
use PKIW\Tag_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class TagPrivacyTest extends WP_UnitTestCase {
	private const PERSON = 'Tg261 Sentinel Person';
	private const URL    = 'https://tg261-sentinel.example/people/sentinel';
	private const LABEL  = 'tg261-sentinel-label';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
		// Rebuild the REST server per test, as core's REST tests do: the hook restore between tests
		// removes filters rest_api_init added (rest_handle_options_request among them).
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down(): void {
		global $wpdb;

		wp_set_current_user( 0 );
		remove_role( 'pkiw_round3_partial_editor' );
		if ( post_type_exists( 'pkiw_governed_test' ) ) {
			unregister_post_type( 'pkiw_governed_test' );
		}
		$GLOBALS['wp_rest_server'] = null;
		$wpdb->suppress_errors( false );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	private function initialize_rest_server(): WP_REST_Server {
		$GLOBALS['wp_rest_server'] = null;
		return rest_get_server();
	}

	private function card(): string {
		return '<!-- wp:post-kinds-indieweb/tag-card {"targetType":"person","url":"' . self::URL . '","title":"' . self::PERSON . '","labels":["' . self::LABEL . '"]} /-->';
	}

	private function hidden( string $status = 'publish', string $password = 'secret', string $kind = 'tag', string $title = 'Tag control' ): int {
		$args = [ 'post_status' => $status, 'post_password' => $password, 'post_title' => $title, 'post_content' => wp_slash( $this->card() ) ];
		if ( 'future' === $status ) {
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS );
			$args['post_date_gmt'] = $args['post_date'];
		}
		$id = self::factory()->post->create( $args );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		return (int) $id;
	}

	private function rest( string $method, string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_query_params( $params );
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
			$request->set_body_params( $params );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function served_rest( string $method, string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_query_params( $params );
		$response = rest_get_server()->dispatch( $request );
		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
	}

	private function comment_feed( string $url ): string {
		$this->go_to( $url );
		do_action( 'template_redirect' );
		if ( ! is_feed() || is_404() ) {
			return '';
		}
		ob_start();
		try {
			@require ABSPATH . WPINC . '/feed-rss2-comments.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Core's template emits headers under PHPUnit.
		} finally {
			$output = (string) ob_get_clean();
		}
		return $output;
	}

	public function test_archive_search_feed_and_comment_feed_sql_exclude_hidden_posts(): void {
		$hidden = $this->hidden();
		$public = $this->hidden( 'publish', '' );
		$query  = new WP_Query( [ 'post__in' => [ $hidden, $public ], 'orderby' => 'ID', 'order' => 'ASC' ] );
		$this->assertSame( [ $public ], array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) ) );
		$where = Public_Eligibility::filter_comment_feed_where( ' AND 1=1', $query );
		$this->assertStringContainsString( 'term_relationships', $where );
		$this->assertStringContainsString( "post_password = ''", $where );
	}

	public function test_hidden_stream_fallback_never_prints_person_data(): void {
		$id      = $this->hidden();
		$content = '<!-- wp:post-kinds-indieweb/tag-card {"targetType":"person","url":"https://person.example/","title":"Private Person","labels":["Friend"]} /-->';
		wp_update_post( [ 'ID' => $id, 'post_content' => wp_slash( $content ) ] );
		$html = Tag_Archive::stream_card( null, get_post( $id ), 2, [] );
		$this->assertIsString( $html );
		$this->assertStringNotContainsString( 'Private Person', $html );
		$this->assertStringNotContainsString( 'person.example', $html );
		$this->assertStringContainsString( 'Friend', $html );
	}

	public function test_password_single_embed_and_feed_forms_match_missing_and_keep_eligible_redirects(): void {
		$hidden   = $this->hidden( 'publish', 'secret', 'tag', 'Tg261 Route Sentinel' );
		$eligible = $this->hidden( 'publish', '', 'tag', 'Tg261 Route Control' );
		$slug     = (string) get_post_field( 'post_name', $hidden );
		$forms    = [
			get_permalink( $hidden ),
			get_permalink( $hidden ) . 'embed/',
			get_permalink( $hidden ) . 'feed/',
			get_permalink( $hidden ) . 'feed/atom/',
			home_url( '/?p=' . $hidden ),
			home_url( '/?p=' . $hidden . '&feed=rss2' ),
			home_url( '/?name=' . $slug . '&feed=atom' ),
		];
		foreach ( $forms as $url ) {
			$this->go_to( $url );
			do_action( 'template_redirect' );
			$this->assertTrue( is_404(), $url );
			$this->assertFalse( is_embed(), $url );
			$this->assertEmpty( redirect_canonical( $url, false ), $url );
			ob_start();
			if ( is_embed() ) {
				@require ABSPATH . WPINC . '/theme-compat/embed.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Mirrors template-loader selection under PHPUnit.
			} elseif ( is_404() ) {
				echo '404';
			}
			$body = (string) ob_get_clean();
			$this->assertStringNotContainsString( 'Tg261 Route Sentinel', $body, $url );
		}

		// B4: a near-miss that only the hidden post matches never redirects to it.
		$this->go_to( home_url( '/tg261-route-sen/' ) );
		$this->assertEmpty( redirect_canonical( home_url( '/tg261-route-sen/' ), false ) );
		// A prefix both posts share may guess the eligible control, never the hidden post.
		$this->go_to( home_url( '/tg261-route/' ) );
		$near = redirect_canonical( home_url( '/tg261-route/' ), false );
		$this->assertNotSame( get_permalink( $hidden ), $near );
		$this->assertSame( get_permalink( $eligible ), $near );
		$this->go_to( home_url( '/missing-route-261/' ) );
		$this->assertEmpty( redirect_canonical( home_url( '/missing-route-261/' ), false ) );
		$this->go_to( home_url( '/?p=' . $eligible ) );
		$this->assertSame( get_permalink( $eligible ), redirect_canonical( home_url( '/?p=' . $eligible ), false ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->go_to( get_permalink( $hidden ) );
		do_action( 'template_redirect' );
		$this->assertFalse( is_404(), 'O-7 keeps core single access for a caller with edit_post.' );
	}

	public function test_singular_comment_feed_has_no_sql_error_and_keeps_the_eligible_control(): void {
		global $wpdb;
		$hidden   = $this->hidden();
		$eligible = $this->hidden( 'publish', '', 'tag', 'Tg261 Comment Control' );
		self::factory()->comment->create( [ 'comment_post_ID' => $hidden, 'comment_approved' => 1, 'comment_content' => 'Hidden comment sentinel' ] );
		self::factory()->comment->create( [ 'comment_post_ID' => $eligible, 'comment_approved' => 1, 'comment_content' => 'Eligible comment control' ] );
		$wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$this->assertSame( '', $this->comment_feed( get_post_comments_feed_link( $hidden ) ) );
		$this->assertSame( '', $wpdb->last_error );
		$wpdb->last_error = '';
		$feed = $this->comment_feed( get_post_comments_feed_link( $eligible ) );
		$this->assertStringContainsString( 'Eligible comment control', $feed );
		$this->assertSame( '', $wpdb->last_error );
		$wpdb->suppress_errors( false );
	}

	public function test_rest_item_writes_work_for_editors_and_hidden_items_look_missing_to_others(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		$draft = $this->hidden( 'draft', '', 'tag', 'REST draft' );
		$this->assertSame( 200, $this->rest( 'PUT', '/wp/v2/posts/' . $draft, [ 'title' => 'REST draft saved' ] )->get_status() );
		$this->assertSame( 200, $this->rest( 'PUT', '/wp/v2/posts/' . $draft, [ 'status' => 'publish' ] )->get_status() );
		$this->assertSame( 'publish', get_post_status( $draft ) );
		$this->assertSame( 200, $this->rest( 'DELETE', '/wp/v2/posts/' . $draft )->get_status() );
		$this->assertSame( 'trash', get_post_status( $draft ) );

		$private = $this->hidden( 'private', '', 'tag', 'REST private' );
		$this->assertSame( 200, $this->rest( 'GET', '/wp/v2/posts/' . $private, [ 'context' => 'edit' ] )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		foreach ( [ [], [ 'context' => 'edit' ], [ 'context' => 'embed' ] ] as $params ) {
			$hidden = $this->rest( 'GET', '/wp/v2/posts/' . $private, $params );
			$missing = $this->rest( 'GET', '/wp/v2/posts/987654321', $params );
			$this->assertSame( 404, $hidden->get_status() );
			$this->assertSame( 'rest_post_invalid_id', $hidden->get_data()['code'] );
			$this->assertSame( $missing->get_status(), $hidden->get_status() );
			$this->assertSame( $missing->get_data(), $hidden->get_data() );
		}
	}

	public function test_rest_post_item_gate_uses_dispatched_id_for_route_variants_and_nested_parent_routes(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'REST route-shape sentinel' );
		$public = $this->hidden( 'publish', '', 'tag', 'REST route-shape control' );
		$routes = [
			[ 'GET', '/wp/v2/posts/0' . $hidden, [] ],
			[ 'GET', '/wp/v2/Posts/' . $hidden, [] ],
			[ 'GET', '/WP/V2/POSTS/' . $hidden, [] ],
			[ 'GET', '/wp/v2/posts/' . $public, [ 'id' => $hidden ] ],
			[ 'GET', '/wp/v2/posts/' . $public, [ 'id' => $hidden, 'password' => 'secret' ] ],
			[ 'PUT', '/wp/v2/posts/0' . $hidden, [ 'title' => 'Must not save' ] ],
			[ 'DELETE', '/wp/v2/Posts/' . $hidden, [] ],
		];
		foreach ( $routes as [ $method, $route, $params ] ) {
			$response = $this->rest( $method, $route, $params );
			$this->assertSame( 404, $response->get_status(), $method . ' ' . $route );
			$this->assertSame( 'rest_post_invalid_id', $response->get_data()['code'], $method . ' ' . $route );
		}
		$this->assertSame( 'REST route-shape sentinel', get_post_field( 'post_title', $hidden ) );
		$this->assertSame( 'publish', get_post_status( $hidden ) );
	}

	public function test_hidden_revision_and_autosave_parents_match_missing_parent_with_public_control(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R3-S2 hidden parent' );
		$public = $this->hidden( 'publish', '', 'tag', 'R3-S2 public control' );
		foreach ( [ 'revisions', 'autosaves' ] as $child_route ) {
			$actual  = $this->rest( 'GET', '/wp/v2/posts/' . $hidden . '/' . $child_route );
			$missing = $this->rest( 'GET', '/wp/v2/posts/987654321/' . $child_route );
			$this->assertSame( $missing->get_status(), $actual->get_status(), 'R3-S2: ' . $child_route );
			$this->assertSame( $missing->get_data(), $actual->get_data(), 'R3-S2: ' . $child_route );
			$this->assertSame( 'rest_post_invalid_parent', $actual->get_data()['code'], 'R3-S2: ' . $child_route );
		}
		$this->assertSame( 200, $this->rest( 'GET', '/wp/v2/posts/' . $public )->get_status() );
	}

	public function test_rest_post_refusal_has_missing_id_headers(): void {
		$hidden = $this->hidden();
		$actual = $this->served_rest( 'GET', '/wp/v2/posts/' . $hidden );
		$missing = $this->served_rest( 'GET', '/wp/v2/posts/987654321' );
		$this->assertSame( $missing->get_status(), $actual->get_status() );
		$this->assertSame( $missing->get_data(), $actual->get_data() );
		$this->assertArrayNotHasKey( 'Allow', $actual->get_headers() );
		$this->assertSame( $missing->get_headers(), $actual->get_headers() );
	}

	public function test_rest_item_gate_uses_the_governed_posts_own_rest_base(): void {
		register_post_type( 'pkiw_governed_test', [ 'public' => true, 'show_in_rest' => true, 'rest_base' => 'governed-tests', 'supports' => [ 'title', 'editor' ] ] );
		register_taxonomy_for_object_type( Taxonomy::TAXONOMY, 'pkiw_governed_test' );
		$id = self::factory()->post->create( [ 'post_type' => 'pkiw_governed_test', 'post_status' => 'publish', 'post_password' => 'secret' ] );
		wp_set_object_terms( $id, 'tag', Taxonomy::TAXONOMY );
		$this->initialize_rest_server();
		wp_set_current_user( 0 );
		$response = $this->rest( 'GET', '/wp/v2/governed-tests/' . $id );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_post_invalid_id', $response->get_data()['code'] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 200, $this->rest( 'PUT', '/wp/v2/governed-tests/' . $id, [ 'title' => 'Edited governed CPT' ] )->get_status() );
		unregister_post_type( 'pkiw_governed_test' );
	}

	public function test_rest_edit_collection_totals_hide_rows_without_edit_published_posts(): void {
		$role = add_role(
			'pkiw_round3_partial_editor',
			'Round 3 partial editor',
			[
				'read'               => true,
				'edit_posts'         => true,
				'edit_others_posts'  => true,
				'edit_private_posts' => true,
			]
		);
		$this->assertInstanceOf( WP_Role::class, $role );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'pkiw_round3_partial_editor' ] ) );
		$hidden = $this->hidden();
		$actual = $this->rest( 'GET', '/wp/v2/posts', [ 'context' => 'edit', 'include' => [ $hidden ], 'status' => 'any' ] );
		$missing = $this->rest( 'GET', '/wp/v2/posts', [ 'context' => 'edit', 'include' => [ 987654321 ], 'status' => 'any' ] );
		$this->assertSame( $missing->get_data(), $actual->get_data() );
		$this->assertSame( $missing->get_headers()['X-WP-Total'], $actual->get_headers()['X-WP-Total'] );
		$this->assertSame( $missing->get_headers()['X-WP-TotalPages'], $actual->get_headers()['X-WP-TotalPages'] );
		remove_role( 'pkiw_round3_partial_editor' );
	}

	public function test_kind_queries_cannot_bypass_the_clause_with_multiple_terms_or_markup(): void {
		$hidden = $this->hidden();
		wp_set_object_terms( $hidden, [ 'comics', 'tag' ], Taxonomy::TAXONOMY );
		$control = $this->hidden( 'publish', '', 'comics', 'Comic control' );
		foreach ( [ [ 'kind' => 'comics' ], [ 'kind' => 't<b>ag' ], [ 'kind' => 't&x;ag' ] ] as $args ) {
			$query = new WP_Query( $args + [ 'fields' => 'ids', 'posts_per_page' => 50 ] );
			$this->assertNotContains( $hidden, array_map( 'intval', $query->posts ), wp_json_encode( $args ) );
		}
		$this->assertContains( $control, array_map( 'intval', ( new WP_Query( [ 'kind' => 'comics', 'fields' => 'ids' ] ) )->posts ) );
		$this->assertIsString( Tag_Archive::stream_card( null, get_post( $hidden ), 2, [] ), 'N1: tag membership wins over the first kind term.' );
	}

	public function test_adjacent_navigation_skips_a_hidden_governed_neighbor(): void {
		$before = $this->hidden( 'publish', '', 'tag', 'Adjacent before' );
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'Adjacent hidden sentinel' );
		$after = $this->hidden( 'publish', '', 'tag', 'Adjacent after' );
		wp_update_post( [ 'ID' => $before, 'post_date' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00' ] );
		wp_update_post( [ 'ID' => $hidden, 'post_date' => '2026-01-02 00:00:00', 'post_date_gmt' => '2026-01-02 00:00:00' ] );
		wp_update_post( [ 'ID' => $after, 'post_date' => '2026-01-03 00:00:00', 'post_date_gmt' => '2026-01-03 00:00:00' ] );
		$this->go_to( get_permalink( $before ) );
		$next = get_adjacent_post( false, '', false );
		$this->assertInstanceOf( WP_Post::class, $next );
		$this->assertSame( $after, $next->ID );
		$this->go_to( get_permalink( $after ) );
		$previous = get_adjacent_post( false, '', true );
		$this->assertInstanceOf( WP_Post::class, $previous );
		$this->assertSame( $before, $previous->ID );
	}

	public function test_comments_hide_on_queries_blocks_and_rest_with_an_editor_control(): void {
		$hidden = $this->hidden();
		$public = $this->hidden( 'publish', '', 'tag', 'Tg261 Public Comment Post' );
		$hidden_comment = self::factory()->comment->create( [ 'comment_post_ID' => $hidden, 'comment_approved' => 1, 'comment_content' => 'Hidden comment body' ] );
		$public_comment = self::factory()->comment->create( [ 'comment_post_ID' => $public, 'comment_approved' => 1, 'comment_content' => 'Public comment body' ] );
		$ids = wp_list_pluck( get_comments( [ 'status' => 'approve' ] ), 'comment_ID' );
		$this->assertNotContains( $hidden_comment, array_map( 'intval', $ids ) );
		$this->assertContains( $public_comment, array_map( 'intval', $ids ) );
		$html = render_block( [ 'blockName' => 'core/latest-comments', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] );
		$this->assertStringNotContainsString( 'Tag control', $html );
		$this->assertStringContainsString( 'Tg261 Public Comment Post', $html );

		$hidden_response = $this->rest( 'GET', '/wp/v2/comments', [ 'post' => $hidden, 'password' => 'secret' ] );
		$missing_response = $this->rest( 'GET', '/wp/v2/comments', [ 'post' => 987654321 ] );
		$this->assertSame( $missing_response->get_status(), $hidden_response->get_status() );
		$this->assertSame( $missing_response->get_data(), $hidden_response->get_data() );
		$this->assertSame( '0', (string) $hidden_response->get_headers()['X-WP-Total'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 200, $this->rest( 'GET', '/wp/v2/comments', [ 'post' => $hidden, 'context' => 'edit' ] )->get_status() );
		$this->assertContains( $hidden_comment, array_map( 'intval', wp_list_pluck( get_comments( [ 'post_id' => $hidden, 'status' => 'approve' ] ), 'comment_ID' ) ) );
		$this->assertContains( $hidden_comment, array_map( 'intval', wp_list_pluck( get_comments( [ 'status' => 'approve' ] ), 'comment_ID' ) ) );
	}

	public function test_empty_ids_never_inherit_a_hidden_global_post(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'Global post sentinel' );
		$public = $this->hidden( 'publish', '' );
		$GLOBALS['post'] = get_post( $hidden ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A hidden page is rendering.
		try {
			$this->assertFalse( Public_Eligibility::hides( 0 ) );
			$this->assertFalse( Public_Eligibility::hides( null ) );
			$this->assertTrue( Public_Eligibility::hides( $hidden ) );
			$media = $this->rest( 'GET', '/wp/v2/media' );
			$this->assertSame( 200, $media->get_status() );
			$posts = $this->rest( 'GET', '/wp/v2/posts', [ 'include' => [ $public ] ] );
			$this->assertSame( 200, $posts->get_status() );
			$this->assertSame( [ $public ], array_map( 'intval', wp_list_pluck( $posts->get_data(), 'id' ) ) );
		} finally {
			unset( $GLOBALS['post'] );
		}
	}

	public function test_rest_comments_hide_governed_posts_in_scalar_array_and_item_routes(): void {
		$hidden = $this->hidden();
		$public = $this->hidden( 'publish', '', 'tag', 'REST public comment control' );
		$hidden_comment = self::factory()->comment->create( [ 'comment_post_ID' => $hidden, 'comment_approved' => 1, 'comment_content' => 'REST hidden comment sentinel' ] );
		$public_comment = self::factory()->comment->create( [ 'comment_post_ID' => $public, 'comment_approved' => 1, 'comment_content' => 'REST public comment control' ] );

		$missing = $this->rest( 'GET', '/wp/v2/comments', [ 'post' => 987654321 ] );
		foreach ( [ $hidden, [ $hidden ] ] as $post_param ) {
			$actual = $this->rest( 'GET', '/wp/v2/comments', [ 'post' => $post_param ] );
			$this->assertSame( $missing->get_status(), $actual->get_status() );
			$this->assertSame( $missing->get_data(), $actual->get_data() );
			$this->assertSame( '0', (string) $actual->get_headers()['X-WP-Total'] );
			$this->assertSame( '0', (string) $actual->get_headers()['X-WP-TotalPages'] );
		}

		$mixed = $this->rest( 'GET', '/wp/v2/comments', [ 'post' => [ $hidden, $public ] ] );
		$this->assertSame( 200, $mixed->get_status() );
		$this->assertSame( [ $public_comment ], array_map( static fn( array $row ): int => (int) $row['id'], $mixed->get_data() ) );

		$missing_item = $this->rest( 'GET', '/wp/v2/comments/987654321' );
		foreach ( [ [], [ 'password' => 'secret' ] ] as $params ) {
			$actual = $this->rest( 'GET', '/wp/v2/comments/' . $hidden_comment, $params );
			$this->assertSame( $missing_item->get_status(), $actual->get_status() );
			$this->assertSame( $missing_item->get_data(), $actual->get_data() );
			$this->assertSame( 'rest_comment_invalid_id', $actual->get_data()['code'] );
		}
		$this->assertSame( 200, $this->rest( 'GET', '/wp/v2/comments/' . $public_comment )->get_status() );
	}

	public function test_comment_filter_keeps_postless_comments(): void {
		$comment_ids = [];
		$comment_ids[] = (int) self::factory()->comment->create( [ 'comment_post_ID' => 0, 'comment_approved' => 1 ] );
		for ( $index = 0; $index < 4; ++$index ) {
			$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			$comment_ids[] = (int) self::factory()->comment->create( [ 'comment_post_ID' => $post_id, 'comment_approved' => 1 ] );
		}
		$comments = get_comments(
			[
				'comment__in' => $comment_ids,
				'orderby'     => 'comment_ID',
				'order'       => 'ASC',
				'status'      => 'approve',
			]
		);
		$this->assertSame( $comment_ids, array_map( 'intval', wp_list_pluck( $comments, 'comment_ID' ) ) );
	}

	public function test_hidden_parent_gates_attachment_pages_redirects_and_rest_media(): void {
		$hidden     = $this->hidden( 'publish', 'secret', 'tag', 'Hidden attachment parent sentinel' );
		$attachment = (int) self::factory()->attachment->create_object(
			'tg261-hidden-parent.jpg',
			$hidden,
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Hidden parent media sentinel',
				'post_name'      => 'hidden-parent-media-sentinel',
			]
		);
		$option_before = get_option( 'wp_attachment_pages_enabled', null );
		try {
			foreach ( [ '0', '1' ] as $enabled ) {
				update_option( 'wp_attachment_pages_enabled', $enabled );
				foreach ( [ home_url( '/?attachment_id=' . $attachment ), get_attachment_link( $attachment ) ] as $url ) {
					$this->go_to( $url );
					Public_Eligibility::refuse_hidden_request();
					$this->assertTrue( is_404(), 'attachment pages=' . $enabled . ': ' . $url );
					$this->assertEmpty( redirect_canonical( $url, false ), 'attachment pages=' . $enabled . ': ' . $url );
				}
			}
		} finally {
			if ( null === $option_before ) {
				delete_option( 'wp_attachment_pages_enabled' );
			} else {
				update_option( 'wp_attachment_pages_enabled', $option_before );
			}
		}

		$item = $this->rest( 'GET', '/wp/v2/media/' . $attachment );
		$this->assertSame( 404, $item->get_status() );
		$this->assertSame( 'rest_post_invalid_id', $item->get_data()['code'] );
		$actual = $this->rest( 'GET', '/wp/v2/media', [ 'parent' => $hidden ] );
		$missing = $this->rest( 'GET', '/wp/v2/media', [ 'parent' => 987654321 ] );
		$this->assertSame( $missing->get_status(), $actual->get_status() );
		$this->assertSame( $missing->get_data(), $actual->get_data() );
		$this->assertSame( $missing->get_headers()['X-WP-Total'], $actual->get_headers()['X-WP-Total'] );
		$this->assertSame( $missing->get_headers()['X-WP-TotalPages'], $actual->get_headers()['X-WP-TotalPages'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 200, $this->rest( 'GET', '/wp/v2/media/' . $attachment )->get_status() );
	}

	public function test_rest_edit_skip_uses_the_queried_governed_post_type_capabilities(): void {
		register_post_type(
			'pkiw_governed_test',
			[
				'public'          => true,
				'show_in_rest'    => true,
				'rest_base'       => 'governed-tests',
				'capability_type' => [ 'governed_test', 'governed_tests' ],
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'editor' ],
			]
		);
		register_taxonomy_for_object_type( Taxonomy::TAXONOMY, 'pkiw_governed_test' );
		$hidden = self::factory()->post->create( [ 'post_type' => 'pkiw_governed_test', 'post_status' => 'publish', 'post_password' => 'secret' ] );
		wp_set_object_terms( $hidden, 'tag', Taxonomy::TAXONOMY );
		$this->initialize_rest_server();

		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$user = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$user->add_cap( 'edit_governed_tests' );
		wp_set_current_user( $user_id );
		$actual = $this->rest( 'GET', '/wp/v2/governed-tests', [ 'context' => 'edit', 'include' => [ $hidden ], 'status' => 'any' ] );
		$missing = $this->rest( 'GET', '/wp/v2/governed-tests', [ 'context' => 'edit', 'include' => [ 987654321 ], 'status' => 'any' ] );
		$this->assertSame( 200, $actual->get_status() );
		$this->assertSame( $missing->get_data(), $actual->get_data() );
		$this->assertSame( $missing->get_headers()['X-WP-Total'], $actual->get_headers()['X-WP-Total'] );
		$this->assertSame( $missing->get_headers()['X-WP-TotalPages'], $actual->get_headers()['X-WP-TotalPages'] );
	}

	public function test_six_status_matrix_hides_both_governed_kinds_and_keeps_positive_controls(): void {
		$hidden = [];
		foreach ( [ 'tag', 'follow' ] as $kind ) {
			$hidden[ $kind ] = [
				$this->hidden( 'private', '', $kind, ucfirst( $kind ) . ' private control' ),
				$this->hidden( 'draft', '', $kind, ucfirst( $kind ) . ' draft control' ),
				$this->hidden( 'pending', '', $kind, ucfirst( $kind ) . ' pending control' ),
				$this->hidden( 'future', '', $kind, ucfirst( $kind ) . ' future control' ),
				$this->hidden( 'publish', 'secret', $kind, ucfirst( $kind ) . ' password control' ),
			];
			$trash = $this->hidden( 'publish', '', $kind, ucfirst( $kind ) . ' trash control' );
			wp_trash_post( $trash );
			$hidden[ $kind ][] = $trash;
		}
		$tag_public = $this->hidden( 'publish', '', 'tag', 'Tag eligible control' );
		$follow_public = $this->hidden( 'publish', '', 'follow', 'Follow eligible control' );
		$all_hidden = array_merge( $hidden['tag'], $hidden['follow'] );

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( [ 'tag' => $tag_public, 'follow' => $follow_public ] as $kind => $positive ) {
				foreach ( [ 1, 2 ] as $page ) {
					$query = new WP_Query( [ 'kind' => $kind, 'fields' => 'ids', 'posts_per_page' => 100, 'paged' => $page ] );
					$ids = array_map( 'intval', $query->posts );
					$this->assertSame( 1 === $page ? [ $positive ] : [], array_values( array_intersect( [ $positive ], $ids ) ), $kind . ' page ' . $page );
					$this->assertSame( [], array_values( array_intersect( $hidden[ $kind ], $ids ) ), $kind . ' page ' . $page );
				}
			}
			$stream = new WP_Query( [ 'post__in' => array_merge( $all_hidden, [ $tag_public, $follow_public ] ), 'fields' => 'ids', 'posts_per_page' => 100 ] );
			$this->assertEqualsCanonicalizing( [ $tag_public, $follow_public ], array_map( 'intval', $stream->posts ) );
		}

		wp_set_current_user( 0 );
		foreach ( [ '/?feed=rss2', '/?kind=tag&feed=rss2', '/?s=control' ] as $path ) {
			$this->go_to( home_url( $path ) );
			$ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
			$this->assertSame( [], array_values( array_intersect( $all_hidden, $ids ) ), $path );
		}
		$provider = new WP_Sitemaps_Posts();
		$locations = wp_list_pluck( $provider->get_url_list( 1, 'post' ), 'loc' );
		$this->assertContains( get_permalink( $tag_public ), $locations );
		foreach ( $all_hidden as $id ) {
			$this->assertNotContains( get_permalink( $id ), $locations );
			$response = $this->rest( 'GET', '/wp/v2/posts/' . $id );
			$this->assertSame( 404, $response->get_status(), (string) $id );
		}
		$collection = $this->rest( 'GET', '/wp/v2/posts', [ 'include' => array_merge( $all_hidden, [ $tag_public ] ), 'per_page' => 100 ] );
		$this->assertSame( [ $tag_public ], array_map( static fn( array $row ): int => (int) $row['id'], $collection->get_data() ) );
		$search = $this->rest( 'GET', '/wp/v2/search', [ 'search' => 'control', 'per_page' => 100 ] );
		$this->assertSame( [], array_values( array_intersect( $all_hidden, array_map( static fn( array $row ): int => (int) $row['id'], $search->get_data() ) ) ) );

		foreach ( $hidden['tag'] as $id ) {
			$this->assertSame( [], Tag_Archive::facts( $id ) );
			$this->assertSame( '', Tag_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], false ) );
			$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Microformats fixture context.
			setup_postdata( $GLOBALS['post'] );
			$this->assertSame( '<p>Body</p>', ( new Microformats() )->add_hidden_mf2_data( '<p>Body</p>' ) );
		}
		$facts = Tag_Archive::facts( $tag_public );
		$this->assertSame( self::PERSON, $facts['title'] );
		$this->assertSame( self::LABEL, $facts['label'] );
		$this->assertStringContainsString( self::PERSON, Tag_Archive::entry_html( get_post( $tag_public ), [ 'headingLevel' => 2 ], false ) );
		$this->assertStringContainsString( self::PERSON, Tag_Archive::stream_card( null, get_post( $tag_public ), 2, [] ) );
		wp_reset_postdata();
	}

	public function test_untitled_hidden_controls_never_promote_person_data_into_titles(): void {
		foreach ( [ [ 'private', '' ], [ 'publish', 'secret' ] ] as [ $status, $password ] ) {
			$id = $this->hidden( $status, $password, 'tag', '' );
			$this->assertStringNotContainsString( self::PERSON, (string) pkiw_get_safe_title( $id ) );
			$this->assertStringNotContainsString( self::PERSON, PKIW\untitled_name( get_post( $id ), false ) );
			$this->go_to( get_permalink( $id ) );
			do_action( 'template_redirect' );
			$this->assertStringNotContainsString( self::PERSON, wp_get_document_title() );
			$response = $this->rest( 'GET', '/wp/v2/posts/' . $id );
			$this->assertSame( 404, $response->get_status() );
			$this->assertStringNotContainsString( self::PERSON, wp_json_encode( $response->get_data() ) );
		}
		$positive = $this->hidden( 'publish', '', 'tag', '' );
		$this->assertSame( self::PERSON, Tag_Archive::facts( $positive )['display_title'] );
	}

	public function test_collection_reference_resolution_has_hidden_and_eligible_controls(): void {
		foreach ( [ $this->hidden( 'private', '' ), $this->hidden( 'draft', '' ), $this->hidden() ] as $id ) {
			$this->assertNull( Collection_Items::resolve_item( [ 'postId' => $id ] ), (string) $id );
		}
		$eligible = $this->hidden( 'publish', '', 'tag', 'Collection eligible control' );
		$item = Collection_Items::resolve_item( [ 'postId' => $eligible ] );
		$this->assertIsArray( $item );
		$this->assertSame( 'Collection eligible control', $item['title'] );
		$this->assertSame( get_permalink( $eligible ), $item['url'] );
		$this->assertNotSame( '', $item['date_iso'] );
	}

	public function test_hidden_parent_attachments_disappear_from_every_media_collection_with_public_controls(): void {
		$hidden_parent = $this->hidden( 'publish', 'secret', 'tag', 'R3 hidden media parent' );
		$public_parent = $this->hidden( 'publish', '', 'tag', 'R3 public media parent' );
		$other_parent  = $this->hidden( 'publish', '', 'tag', 'R3 excluded media parent' );
		$hidden_media  = (int) self::factory()->attachment->create_object(
			'r3-hidden-media.jpg',
			$hidden_parent,
			[
				'post_mime_type' => 'image/jpeg',
				'post_name'      => 'r3-hidden-media',
				'post_title'     => 'R3 shared media sentinel hidden',
			]
		);
		$public_media  = (int) self::factory()->attachment->create_object(
			'r3-public-media.jpg',
			$public_parent,
			[
				'post_mime_type' => 'image/jpeg',
				'post_name'      => 'r3-public-media',
				'post_title'     => 'R3 shared media sentinel public',
			]
		);
		self::factory()->attachment->create_object( 'r3-excluded-media.jpg', $other_parent, [ 'post_mime_type' => 'image/jpeg' ] );

		$requests = [
			'unfiltered'     => [],
			'include'        => [ 'include' => [ $hidden_media, $public_media ] ],
			'slug'           => [ 'slug' => [ 'r3-hidden-media', 'r3-public-media' ] ],
			'search'         => [ 'search' => 'R3 shared media sentinel' ],
			'parent_exclude' => [ 'parent_exclude' => [ $other_parent ] ],
		];
		foreach ( $requests as $label => $params ) {
			$response = $this->rest( 'GET', '/wp/v2/media', $params );
			$ids      = array_map( static fn( array $row ): int => (int) $row['id'], $response->get_data() );
			$this->assertNotContains( $hidden_media, $ids, 'R3-B1/O-12: ' . $label );
			$this->assertContains( $public_media, $ids, 'R3-B1/O-12 public control: ' . $label );
		}

		$query = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post__in'       => [ $hidden_media, $public_media ],
				'fields'         => 'ids',
				'posts_per_page' => 20,
			]
		);
		$this->assertSame( [ $public_media ], array_map( 'intval', $query->posts ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$editor_ids = array_map( static fn( array $row ): int => (int) $row['id'], $this->rest( 'GET', '/wp/v2/media', [ 'include' => [ $hidden_media, $public_media ] ] )->get_data() );
		$this->assertContains( $hidden_media, $editor_ids );
		$this->assertContains( $public_media, $editor_ids );
	}

	public function test_term_routes_for_hidden_post_match_missing_post_and_keep_public_terms(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R3 hidden term route post' );
		$private = $this->hidden( 'private', '', 'tag', 'R3 private term route post' );
		$public = $this->hidden( 'publish', '', 'tag', 'R3 public term route post' );
		$hidden_tag = wp_insert_term( 'R3 hidden route label', 'post_tag' );
		$public_tag = wp_insert_term( 'R3 public route label', 'post_tag' );
		$hidden_cat = wp_insert_term( 'R3 hidden route category', 'category' );
		$public_cat = wp_insert_term( 'R3 public route category', 'category' );
		$this->assertNotWPError( $hidden_tag );
		$this->assertNotWPError( $public_tag );
		$this->assertNotWPError( $hidden_cat );
		$this->assertNotWPError( $public_cat );
		wp_set_post_terms( $hidden, [ $hidden_tag['term_id'] ], 'post_tag', false );
		wp_set_post_terms( $private, [ $hidden_tag['term_id'] ], 'post_tag', false );
		wp_set_post_terms( $public, [ $public_tag['term_id'] ], 'post_tag', false );
		wp_set_post_terms( $hidden, [ $hidden_cat['term_id'] ], 'category', false );
		wp_set_post_terms( $private, [ $hidden_cat['term_id'] ], 'category', false );
		wp_set_post_terms( $public, [ $public_cat['term_id'] ], 'category', false );
		$kind = get_term_by( 'slug', 'tag', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $kind );

		foreach (
			[
				'/wp/v2/tags'       => (int) $public_tag['term_id'],
				'/wp/v2/categories' => (int) $public_cat['term_id'],
				'/wp/v2/kind'       => (int) $kind->term_id,
			] as $route => $public_term_id
		) {
			$missing = $this->rest( 'GET', $route, [ 'post' => 987654321 ] );
			foreach ( [ $hidden, $private ] as $hidden_post_id ) {
				$actual = $this->rest( 'GET', $route, [ 'post' => $hidden_post_id ] );
				$this->assertSame( $missing->get_status(), $actual->get_status(), $route );
				$this->assertSame( $missing->get_data(), $actual->get_data(), $route );
				$this->assertSame( 'rest_post_invalid_id', $actual->get_data()['code'], $route );
			}

			$public_response = $this->rest( 'GET', $route, [ 'post' => $public ] );
			$this->assertSame( 200, $public_response->get_status(), $route );
			$this->assertContains( $public_term_id, array_map( static fn( array $row ): int => (int) $row['id'], $public_response->get_data() ), $route . ' public control' );
		}
	}

	public function test_term_route_refusal_headers_match_missing_id_and_keep_public_allow(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R4 hidden term route headers' );
		$public = $this->hidden( 'publish', '', 'tag', 'R4 public term route headers' );
		foreach ( [ '/wp/v2/tags', '/wp/v2/categories', '/wp/v2/kind' ] as $route ) {
			$actual  = $this->served_rest( 'GET', $route, [ 'post' => $hidden ] );
			$missing = $this->served_rest( 'GET', $route, [ 'post' => 987654321 ] );
			$positive = $this->served_rest( 'GET', $route, [ 'post' => $public ] );
			$this->assertSame( $missing->get_headers(), $actual->get_headers(), $route );
			$this->assertArrayNotHasKey( 'Allow', $actual->get_headers(), $route );
			$this->assertSame( 'GET', $positive->get_headers()['Allow'] ?? null, $route . ' public control' );
		}
	}

	public function test_term_counts_hide_hidden_only_terms_across_public_surfaces(): void {
		register_taxonomy( 'pkiw_r3_label', 'post', [ 'public' => true ] );
		try {
			$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R3 hidden count post' );
			$public = $this->hidden( 'publish', '', 'tag', 'R3 public count post' );
			$category = wp_insert_term( 'pkiw-r3-shared-category-count', 'category' );
			$this->assertNotWPError( $category );
			wp_set_post_tags( $hidden, [ 'pkiw-r3-hidden-count' ], false );
			wp_set_post_tags( $public, [ 'pkiw-r3-public-count' ], false );
			wp_set_post_categories( $hidden, [ $category['term_id'] ], false );
			wp_set_post_categories( $public, [ $category['term_id'] ], false );
			wp_set_object_terms( $hidden, [ 'pkiw-r3-custom-count' ], 'pkiw_r3_label' );
			wp_set_object_terms( $public, [ 'pkiw-r3-custom-count' ], 'pkiw_r3_label' );

			$hidden_term = get_term_by( 'slug', 'pkiw-r3-hidden-count', 'post_tag' );
			$public_term = get_term_by( 'slug', 'pkiw-r3-public-count', 'post_tag' );
			$custom_term = get_term_by( 'slug', 'pkiw-r3-custom-count', 'pkiw_r3_label' );
			$category    = get_term( $category['term_id'], 'category' );
			$kind        = get_term_by( 'slug', 'tag', Taxonomy::TAXONOMY );
			$this->assertInstanceOf( WP_Term::class, $hidden_term );
			$this->assertInstanceOf( WP_Term::class, $public_term );
			$this->assertInstanceOf( WP_Term::class, $custom_term );
			$this->assertInstanceOf( WP_Term::class, $category );
			$this->assertInstanceOf( WP_Term::class, $kind );
			$this->assertSame( 0, $hidden_term->count );
			$this->assertSame( 1, $public_term->count );
			$this->assertSame( 1, $custom_term->count );
			$this->assertSame( 1, $category->count );
			$this->assertSame( 1, $kind->count );

			$visible_tags = get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => true, 'slug' => [ 'pkiw-r3-hidden-count', 'pkiw-r3-public-count' ] ] );
			$this->assertNotWPError( $visible_tags );
			$this->assertSame( [ 'pkiw-r3-public-count' ], wp_list_pluck( $visible_tags, 'slug' ) );
			// O-13 "like private": a label only hidden posts use stays listed by core's default
			// hide_empty=false, with count 0, exactly like a label only a private post uses.
			$rest_tags = $this->rest( 'GET', '/wp/v2/tags', [ 'search' => 'pkiw-r3-hidden-count' ] );
			$this->assertSame( [ 0 ], array_map( static fn( array $row ): int => (int) $row['count'], $rest_tags->get_data() ) );
			$rest_nonempty = $this->rest( 'GET', '/wp/v2/tags', [ 'search' => 'pkiw-r3-hidden-count', 'hide_empty' => true ] );
			$this->assertSame( [], $rest_nonempty->get_data() );
			$rest_public = $this->rest( 'GET', '/wp/v2/tags', [ 'slug' => [ 'pkiw-r3-public-count' ] ] );
			$this->assertSame( 1, $rest_public->get_data()[0]['count'] );
			$rest_kind = $this->rest( 'GET', '/wp/v2/kind', [ 'slug' => [ 'tag' ] ] );
			$this->assertSame( 1, $rest_kind->get_data()[0]['count'] );
			$cloud = (string) wp_tag_cloud( [ 'echo' => false, 'taxonomy' => 'post_tag' ] );
			$this->assertStringNotContainsString( 'pkiw-r3-hidden-count', $cloud );
			$this->assertStringContainsString( 'pkiw-r3-public-count', $cloud );
			$block_cloud = render_block( [ 'blockName' => 'core/tag-cloud', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] );
			$this->assertStringNotContainsString( 'pkiw-r3-hidden-count', $block_cloud );
			$this->assertStringContainsString( 'pkiw-r3-public-count', $block_cloud );
			// Core's term search passes hide_empty false, so it lists empty terms. "Like private" (O-13)
			// means a label only hidden posts use is found exactly like a label only a private post uses.
			$private_post = self::factory()->post->create( [ 'post_status' => 'private' ] );
			wp_set_post_tags( $private_post, [ 'pkiw-r3-private-only' ] );
			$hidden_search  = $this->rest( 'GET', '/wp/v2/search', [ 'search' => 'pkiw-r3-hidden-count', 'type' => 'term' ] );
			$private_search = $this->rest( 'GET', '/wp/v2/search', [ 'search' => 'pkiw-r3-private-only', 'type' => 'term' ] );
			$this->assertSame( count( $private_search->get_data() ), count( $hidden_search->get_data() ) );
			$this->assertSame( 0, get_term_by( 'slug', 'pkiw-r3-private-only', 'post_tag' )->count );
		} finally {
			unregister_taxonomy( 'pkiw_r3_label' );
		}
	}

	public function test_padded_hierarchical_counts_hide_hidden_posts_across_category_surfaces(): void {
		$parent = wp_insert_term( 'R4 padded parent', 'category', [ 'slug' => 'pkiw-r4-padded-parent' ] );
		$this->assertNotWPError( $parent );
		$child = wp_insert_term( 'R4 padded child', 'category', [ 'slug' => 'pkiw-r4-padded-child', 'parent' => $parent['term_id'] ] );
		$this->assertNotWPError( $child );
		$hidden_leaf = wp_insert_term( 'R4 padded hidden only', 'category', [ 'slug' => 'pkiw-r4-padded-hidden', 'parent' => $child['term_id'] ] );
		$public_leaf = wp_insert_term( 'R4 padded public control', 'category', [ 'slug' => 'pkiw-r4-padded-public', 'parent' => $child['term_id'] ] );
		$private_leaf = wp_insert_term( 'R4 padded private control', 'category', [ 'slug' => 'pkiw-r4-padded-private', 'parent' => $child['term_id'] ] );
		$this->assertNotWPError( $hidden_leaf );
		$this->assertNotWPError( $public_leaf );
		$this->assertNotWPError( $private_leaf );

		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R4 padded hidden post' );
		$public = $this->hidden( 'publish', '', 'tag', 'R4 padded public post' );
		$private = self::factory()->post->create( [ 'post_status' => 'private' ] );
		wp_set_post_categories( $hidden, [ $hidden_leaf['term_id'] ], false );
		wp_set_post_categories( $public, [ $public_leaf['term_id'] ], false );
		wp_set_post_categories( $private, [ $private_leaf['term_id'] ], false );

		$terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => true, 'pad_counts' => true ] );
		$this->assertNotWPError( $terms );
		$counts = [];
		foreach ( $terms as $term ) {
			if ( str_starts_with( $term->slug, 'pkiw-r4-padded-' ) ) {
				$counts[ $term->slug ] = (int) $term->count;
			}
		}
		$this->assertSame(
			[
				'pkiw-r4-padded-child'  => 1,
				'pkiw-r4-padded-parent' => 1,
				'pkiw-r4-padded-public' => 1,
			],
			$counts
		);

		$list = (string) wp_list_categories( [ 'echo' => false, 'hide_empty' => true, 'hierarchical' => true, 'show_count' => true, 'title_li' => '' ] );
		$this->assertStringContainsString( 'R4 padded parent', $list );
		$this->assertStringContainsString( 'R4 padded child', $list );
		$this->assertStringContainsString( 'R4 padded public control', $list );
		$this->assertStringNotContainsString( 'R4 padded hidden only', $list );
		$this->assertStringNotContainsString( 'R4 padded private control', $list );

		foreach ( [ false, true ] as $dropdown ) {
			$block = render_block(
				[
					'blockName'    => 'core/categories',
					'attrs'        => [ 'displayAsDropdown' => $dropdown, 'showPostCounts' => true, 'showHierarchy' => true ],
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				]
			);
			$this->assertStringContainsString( 'R4 padded public control', $block, $dropdown ? 'dropdown' : 'list' );
			$this->assertStringNotContainsString( 'R4 padded hidden only', $block, $dropdown ? 'dropdown' : 'list' );
			$this->assertStringNotContainsString( 'R4 padded private control', $block, $dropdown ? 'dropdown' : 'list' );
		}
	}

	public function test_term_counts_recount_when_eligibility_or_governed_kind_changes(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
		wp_set_post_tags( $post, [ 'pkiw-r3-recount-control' ], false );
		$term = get_term_by( 'slug', 'pkiw-r3-recount-control', 'post_tag' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 1, $term->count, 'A plain password-protected post remains core-counted.' );

		wp_set_object_terms( $post, [ 'tag' ], Taxonomy::TAXONOMY );
		$this->assertSame( 0, get_term( $term->term_id, 'post_tag' )->count, 'Adding a governed kind hides the label.' );
		wp_set_object_terms( $post, [], Taxonomy::TAXONOMY );
		$this->assertSame( 1, get_term( $term->term_id, 'post_tag' )->count, 'Removing the governed kind restores the core count.' );
		wp_set_object_terms( $post, [ 'tag' ], Taxonomy::TAXONOMY );
		wp_update_post( [ 'ID' => $post, 'post_password' => '' ] );
		$this->assertSame( 1, get_term( $term->term_id, 'post_tag' )->count, 'Removing the password restores the term.' );
		wp_update_post( [ 'ID' => $post, 'post_password' => 'secret' ] );
		$this->assertSame( 0, get_term( $term->term_id, 'post_tag' )->count, 'Adding the password hides the term.' );
		wp_update_post( [ 'ID' => $post, 'post_password' => '', 'post_status' => 'draft' ] );
		$this->assertSame( 0, get_term( $term->term_id, 'post_tag' )->count, 'Drafts remain outside core counts.' );
		wp_update_post( [ 'ID' => $post, 'post_status' => 'publish' ] );
		$this->assertSame( 1, get_term( $term->term_id, 'post_tag' )->count, 'Publishing the eligible post restores the public control.' );
	}

	public function test_rewritten_collection_link_headers_match_missing_ids_and_keep_public_controls(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R3 Link hidden post' );
		$public = $this->hidden( 'publish', '', 'tag', 'R3 Link public post' );
		$missing = 987654321;
		$hidden_comment = (int) self::factory()->comment->create( [ 'comment_post_ID' => $hidden, 'comment_approved' => 1 ] );
		$public_comment = (int) self::factory()->comment->create( [ 'comment_post_ID' => $public, 'comment_approved' => 1 ] );
		$hidden_media = (int) self::factory()->attachment->create_object( 'r3-link-hidden.jpg', $hidden, [ 'post_mime_type' => 'image/jpeg' ] );
		$public_media = (int) self::factory()->attachment->create_object( 'r3-link-public.jpg', $public, [ 'post_mime_type' => 'image/jpeg' ] );

		foreach ( [ [ '/wp/v2/comments', 'post', $public_comment ], [ '/wp/v2/media', 'parent', $public_media ] ] as [ $route, $parameter, $public_item ] ) {
			foreach ( [ [ $hidden ], [ $hidden, $public ] ] as $ids ) {
				$params = [ $parameter => $ids, 'page' => 2, 'per_page' => 1 ];
				$actual = $this->served_rest( 'GET', $route, $params );
				$params[ $parameter ][0] = $missing;
				$control = $this->served_rest( 'GET', $route, $params );
				$actual_link  = $actual->get_headers()['Link'] ?? null;
				$control_link = $control->get_headers()['Link'] ?? null;
				// A page past the end answers 400 with no Link for the missing ID too; match it either way.
				$this->assertSame( $control->get_status(), $actual->get_status(), $route );
				$this->assertSame( $control_link, null === $actual_link ? null : str_replace( (string) $hidden, (string) $missing, (string) $actual_link ), 'R3-S1: ' . $route . ' ' . wp_json_encode( $ids ) );
			}

			$visible = $this->rest( 'GET', $route, [ $parameter => [ $hidden, $public ], 'per_page' => 100 ] );
			$this->assertContains( $public_item, array_map( static fn( array $row ): int => (int) $row['id'], $visible->get_data() ), $route . ' public control' );
		}
		$this->assertGreaterThan( 0, $hidden_comment );
		$this->assertGreaterThan( 0, $hidden_media );
	}

	public function test_rewritten_collection_links_match_core_with_special_keys_and_plain_permalinks(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R4 Link hidden post' );
		$public = $this->hidden( 'publish', '', 'tag', 'R4 Link public post' );
		$missing = 987654321;
		$timestamp = '2026-11-16 12:00:00';
		self::factory()->comment->create( [ 'comment_post_ID' => $hidden, 'comment_approved' => 1, 'comment_date' => $timestamp, 'comment_date_gmt' => $timestamp ] );
		$public_comments = [
			(int) self::factory()->comment->create( [ 'comment_post_ID' => $public, 'comment_approved' => 1, 'comment_date' => $timestamp, 'comment_date_gmt' => $timestamp ] ),
			(int) self::factory()->comment->create( [ 'comment_post_ID' => $public, 'comment_approved' => 1, 'comment_date' => $timestamp, 'comment_date_gmt' => $timestamp ] ),
		];
		self::factory()->attachment->create_object( 'r4-link-special-hidden.jpg', $hidden, [ 'post_mime_type' => 'image/jpeg', 'post_date' => $timestamp, 'post_date_gmt' => $timestamp ] );
		$public_media = [
			(int) self::factory()->attachment->create_object( 'r4-link-special-public-a.jpg', $public, [ 'post_mime_type' => 'image/jpeg', 'post_date' => $timestamp, 'post_date_gmt' => $timestamp ] ),
			(int) self::factory()->attachment->create_object( 'r4-link-special-public-b.jpg', $public, [ 'post_mime_type' => 'image/jpeg', 'post_date' => $timestamp, 'post_date_gmt' => $timestamp ] ),
		];

		$structure_before = (string) get_option( 'permalink_structure' );
		$server_before    = $GLOBALS['wp_rest_server'] ?? null;
		try {
			foreach ( [ '', '/%postname%/' ] as $structure ) {
				$this->set_permalink_structure( $structure );
				$GLOBALS['wp_rest_server'] = null;
				foreach ( [ [ '/wp/v2/comments', 'post', $public_comments ], [ '/wp/v2/media', 'parent', $public_media ] ] as [ $route, $parameter, $positive_ids ] ) {
					$params = [ $parameter => [ $hidden, $public ], 'page' => 2, 'per_page' => 1, 'x>y' => '1' ];
					if ( '' === $structure ) {
						$params['rest_route'] = $route;
					}
					$actual = $this->served_rest( 'GET', $route, $params );
					$params[ $parameter ][0] = $missing;
					$control = $this->served_rest( 'GET', $route, $params );
					$actual_link = str_replace( (string) $hidden, (string) $missing, (string) ( $actual->get_headers()['Link'] ?? '' ) );
					$this->assertSame( $control->get_headers()['Link'] ?? '', $actual_link, $structure . ' ' . $route );
					$this->assertCount( 1, $actual->get_data(), $structure . ' ' . $route . ' public control count' );
					$this->assertContains( (int) $actual->get_data()[0]['id'], $positive_ids, $structure . ' ' . $route . ' public control' );
				}
			}
		} finally {
			$this->set_permalink_structure( $structure_before );
			$GLOBALS['wp_rest_server'] = $server_before;
		}
	}

	public function test_mixed_hidden_parent_lists_match_missing_id_order_for_same_second_items(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R4 order hidden post' );
		$public = $this->hidden( 'publish', '', 'tag', 'R4 order public post' );
		$missing = 987654321;
		$timestamp = '2026-11-16 12:00:00';
		self::factory()->comment->create( [ 'comment_post_ID' => $hidden, 'comment_approved' => 1, 'comment_date' => $timestamp, 'comment_date_gmt' => $timestamp ] );
		$public_comments = [
			(int) self::factory()->comment->create( [ 'comment_post_ID' => $public, 'comment_approved' => 1, 'comment_date' => $timestamp, 'comment_date_gmt' => $timestamp ] ),
			(int) self::factory()->comment->create( [ 'comment_post_ID' => $public, 'comment_approved' => 1, 'comment_date' => $timestamp, 'comment_date_gmt' => $timestamp ] ),
		];
		self::factory()->attachment->create_object( 'r4-order-hidden.jpg', $hidden, [ 'post_mime_type' => 'image/jpeg', 'post_date' => $timestamp, 'post_date_gmt' => $timestamp ] );
		$public_media = [
			(int) self::factory()->attachment->create_object( 'r4-order-public-a.jpg', $public, [ 'post_mime_type' => 'image/jpeg', 'post_date' => $timestamp, 'post_date_gmt' => $timestamp ] ),
			(int) self::factory()->attachment->create_object( 'r4-order-public-b.jpg', $public, [ 'post_mime_type' => 'image/jpeg', 'post_date' => $timestamp, 'post_date_gmt' => $timestamp ] ),
		];

		foreach ( [ [ '/wp/v2/comments', 'post', $public_comments ], [ '/wp/v2/media', 'parent', $public_media ] ] as [ $route, $parameter, $positive_ids ] ) {
			$actual = $this->rest( 'GET', $route, [ $parameter => [ $hidden, $public ], 'per_page' => 100 ] );
			$control = $this->rest( 'GET', $route, [ $parameter => [ $missing, $public ], 'per_page' => 100 ] );
			$actual_ids = array_map( static fn( array $row ): int => (int) $row['id'], $actual->get_data() );
			$control_ids = array_map( static fn( array $row ): int => (int) $row['id'], $control->get_data() );
			$this->assertEqualsCanonicalizing( $positive_ids, $actual_ids, $route . ' public controls' );
			$this->assertSame( $control_ids, $actual_ids, $route . ' item order' );
		}
	}

	public function test_options_for_hidden_post_has_missing_id_allow_header_and_public_control(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R3 OPTIONS hidden post' );
		$public = $this->hidden( 'publish', '', 'tag', 'R3 OPTIONS public post' );
		$actual = $this->served_rest( 'OPTIONS', '/wp/v2/posts/' . $hidden );
		$missing = $this->served_rest( 'OPTIONS', '/wp/v2/posts/987654321' );
		$positive = $this->served_rest( 'OPTIONS', '/wp/v2/posts/' . $public );
		$this->assertSame( $missing->get_headers()['Allow'] ?? null, $actual->get_headers()['Allow'] ?? null );
		$this->assertArrayNotHasKey( 'Allow', $actual->get_headers() );
		$this->assertSame( 'GET', $positive->get_headers()['Allow'] ?? null );
	}

	public function test_options_zero_padded_posts_media_and_hidden_comments_match_missing_allow_headers(): void {
		$hidden = $this->hidden( 'publish', 'secret', 'tag', 'R4 OPTIONS hidden post' );
		$public = $this->hidden( 'publish', '', 'tag', 'R4 OPTIONS public post' );
		$hidden_media = (int) self::factory()->attachment->create_object( 'r4-options-hidden.jpg', $hidden, [ 'post_mime_type' => 'image/jpeg' ] );
		$public_media = (int) self::factory()->attachment->create_object( 'r4-options-public.jpg', $public, [ 'post_mime_type' => 'image/jpeg' ] );
		$hidden_comment = (int) self::factory()->comment->create( [ 'comment_post_ID' => $hidden, 'comment_approved' => 1 ] );
		$public_comment = (int) self::factory()->comment->create( [ 'comment_post_ID' => $public, 'comment_approved' => 1 ] );

		foreach (
			[
				[ '/wp/v2/posts/0' . $hidden, '/wp/v2/posts/987654321', '/wp/v2/posts/0' . $public, [] ],
				[ '/wp/v2/media/0' . $hidden_media, '/wp/v2/media/987654321', '/wp/v2/media/0' . $public_media, [] ],
				[ '/wp/v2/comments/' . $hidden_comment, '/wp/v2/comments/987654321', '/wp/v2/comments/' . $public_comment, [ 'password' => 'secret' ] ],
			] as [ $hidden_route, $missing_route, $public_route, $params ]
		) {
			$actual = $this->served_rest( 'OPTIONS', $hidden_route, $params );
			$missing = $this->served_rest( 'OPTIONS', $missing_route, $params );
			$positive = $this->served_rest( 'OPTIONS', $public_route, $params );
			$this->assertSame( $missing->get_headers()['Allow'] ?? null, $actual->get_headers()['Allow'] ?? null, $hidden_route );
			$this->assertArrayNotHasKey( 'Allow', $actual->get_headers(), $hidden_route );
			$this->assertSame( 'GET', $positive->get_headers()['Allow'] ?? null, $public_route . ' public control' );
		}
	}
}
