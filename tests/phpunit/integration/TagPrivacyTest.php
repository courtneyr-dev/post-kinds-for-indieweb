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

	/** @var WP_REST_Server|null */
	private $rest_server_before_test = null;

	private bool $rest_server_replaced = false;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		global $wpdb;

		wp_set_current_user( 0 );
		remove_role( 'pkiw_round3_partial_editor' );
		if ( post_type_exists( 'pkiw_governed_test' ) ) {
			unregister_post_type( 'pkiw_governed_test' );
		}
		if ( $this->rest_server_replaced ) {
			$GLOBALS['wp_rest_server'] = $this->rest_server_before_test;
		}
		$wpdb->suppress_errors( false );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	private function initialize_rest_server(): WP_REST_Server {
		$this->rest_server_before_test = $GLOBALS['wp_rest_server'] ?? null;
		$this->rest_server_replaced    = true;
		$GLOBALS['wp_rest_server']     = null;
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
}
