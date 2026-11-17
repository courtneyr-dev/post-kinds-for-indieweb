<?php
/**
 * Follow privacy contract (W8-P260, spec-260 "Privacy surfaces" and C1-C9).
 *
 * Frozen before the implementation. Every negative assertion looks for a
 * sentinel that only a leak would print, and every test carries a positive
 * control that must print, so no test can pass on an empty surface.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Follow_Abilities;
use PKIW\Abilities_Manager;
use PKIW\Follow_Archive;
use PKIW\Meta_Fields;
use PKIW\Microformats;
use PKIW\Taxonomy;

/** @group integration */
final class FollowPrivacyTest extends WP_UnitTestCase {
	private const NAME         = 'Fw260 Sentinel Person';
	private const URL          = 'https://fw260-sentinel.example/';
	private const SNIP         = 'fw260-sentinel';
	private const VISIBLE_NAME = 'Fw260 Visible Person';
	private const VISIBLE_URL  = 'https://fw260-visible.example/';
	private const VISIBLE_HOST = 'fw260-visible.example';
	private const REMOTE_PHOTO = 'https://fw260-photo.example/p.png';
	private const CASES        = [ 'private', 'draft', 'pending', 'future', 'password', 'trash' ];

	/** @var string[] Temporary files copied for uploads. */
	private array $temp_files = [];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		$this->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
		delete_transient( 'pkiw_follow_index_v1' );
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
		delete_transient( 'pkiw_follow_index_v1' );
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temp file.
			}
		}
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/** The Micropub builder's generated paragraph for a URL. */
	private function paragraph( string $url ): string {
		return '<!-- wp:paragraph -->' . "\n" . '<p>Followed <a class="u-follow-of" href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>' . "\n" . '<!-- /wp:paragraph -->';
	}

	/** Upload a copy of a test image or PDF under a chosen file name. */
	private function upload( string $basename, int $parent = 0, string $source = 'test-image.png' ): int {
		$file = trailingslashit( get_temp_dir() ) . $basename;
		copy( DIR_TESTDATA . '/images/' . $source, $file );
		$this->temp_files[] = $file;
		return (int) self::factory()->attachment->create_upload_object( $file, $parent );
	}

	/**
	 * Create a follow post (or another kind) carrying the given person data.
	 *
	 * @param string $state One of self::CASES or 'publish'.
	 */
	private function follow( string $state, string $title = 'Follow control', string $name = self::NAME, string $url = self::URL, string $kind = 'follow', bool $thumb = true ): int {
		$status = in_array( $state, [ 'password', 'trash' ], true ) ? 'publish' : $state;
		$args   = [
			'post_status'   => $status,
			'post_password' => 'password' === $state ? 'secret' : '',
			'post_title'    => $title,
			'post_content'  => wp_slash( $this->paragraph( $url ) ),
		];
		if ( 'future' === $state ) {
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS );
			$args['post_date_gmt'] = $args['post_date'];
		}
		$id = (int) self::factory()->post->create( $args );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', $name );
		update_post_meta( $id, '_pkiw_cite_url', $url );
		if ( $thumb ) {
			$stem = self::URL === $url ? self::SNIP : 'fw260-presence';
			set_post_thumbnail( $id, $this->upload( $stem . '-' . $id . '.png', $id ) );
		}
		if ( 'trash' === $state ) {
			wp_trash_post( $id );
		}
		return $id;
	}

	/** One control per hidden case, titled or title-less. */
	private function controls( string $title = 'Follow control' ): array {
		$ids = [];
		foreach ( self::CASES as $case ) {
			$ids[ $case ] = $this->follow( $case, $title );
		}
		return $ids;
	}

	private function presence( string $title = 'Follow presence' ): int {
		return $this->follow( 'publish', $title, self::VISIBLE_NAME, self::VISIBLE_URL );
	}

	private function render_block_for( string $name, array $attrs, int $post_id ): string {
		$block = new WP_Block(
			[ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ],
			[ 'postId' => $post_id, 'postType' => 'post', 'queryId' => 0 ]
		);
		return (string) $block->render();
	}

	private function rest( string $method, string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function assert_no_sentinel( string $haystack, string $where ): void {
		$this->assertStringNotContainsString( self::SNIP, $haystack, $where );
		$this->assertStringNotContainsString( self::NAME, $haystack, $where );
	}

	public function test_facts_gate_hides_every_control_for_every_user_and_never_reads_cite_photo(): void {
		$controls = $this->controls();
		$presence = $this->presence();
		add_post_meta( $presence, '_pkiw_cite_photo', 'https://fw260-photo.example/never.png' );
		$users = [
			0,
			self::factory()->user->create( [ 'role' => 'subscriber' ] ),
			self::factory()->user->create( [ 'role' => 'editor' ] ),
			self::factory()->user->create( [ 'role' => 'administrator' ] ),
		];
		foreach ( $users as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( $controls as $case => $id ) {
				$this->assertSame( [], Follow_Archive::facts( $id ), $case . ' as user ' . $user_id );
				$this->assertSame( [], Follow_Archive::facts_for( get_post( $id ), 'follow' ), $case . ' as user ' . $user_id );
			}
			$facts = Follow_Archive::facts( $presence );
			$this->assertSame( self::VISIBLE_NAME, $facts['name'] ?? null, 'positive control as user ' . $user_id );
			$this->assertSame( self::VISIBLE_URL, $facts['url'] ?? null );
			$this->assertSame( self::VISIBLE_HOST, $facts['host'] ?? null );
			$this->assertStringNotContainsString( 'fw260-photo', (string) wp_json_encode( $facts ) );
		}
		// The kind arrives as an argument: a non-follow kind gets nothing even when eligible.
		$this->assertSame( [], Follow_Archive::facts_for( get_post( $presence ), 'like' ) );
	}

	public function test_entry_and_letters_blocks_print_the_presence_control_and_no_hidden_follow(): void {
		$controls = array_merge( $this->controls(), $this->controls( '' ) );
		$quentin  = $this->follow( 'private', 'Q control', 'Quentin Sentinel', 'https://fw260-sentinel-q.example/' );
		$presence = $this->presence();

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'subscriber' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( array_merge( $controls, [ 'quentin' => $quentin ] ) as $case => $id ) {
				$html = $this->render_block_for( 'post-kinds-indieweb/follow-entry', [ 'headingLevel' => 2 ], $id );
				$this->assertSame( '', $html, $case );
			}
			$entry = $this->render_block_for( 'post-kinds-indieweb/follow-entry', [ 'headingLevel' => 2 ], $presence );
			$this->assertStringContainsString( self::VISIBLE_NAME, $entry );
			$this->assertStringContainsString( 'value="' . self::VISIBLE_URL . '"', $entry );
			$this->assertStringContainsString( 'h-entry', $entry );
			$this->assert_no_sentinel( $entry, 'presence entry' );

			$letters = $this->render_block_for( 'post-kinds-indieweb/follow-letters', [], $presence );
			$this->assertStringContainsString( 'pkiw_follow_initial=F', $letters, 'positive control letter' );
			$this->assertStringNotContainsString( 'pkiw_follow_initial=Q', $letters, 'a letter only a private follow holds' );
			$this->assert_no_sentinel( $letters, 'letters' );
		}

		$served = Follow_Archive::served_index();
		$ids    = array_map( static fn( array $row ): int => (int) $row['post_id'], $served['follows'] );
		$this->assertContains( $presence, $ids );
		$this->assertSame( [], array_values( array_intersect( array_merge( array_values( $controls ), [ $quentin ] ), $ids ) ) );
		$this->assertArrayNotHasKey( 'Q', $served['letters'] );
	}

	public function test_archive_letter_filter_and_kind_feed_list_only_eligible_follows(): void {
		$controls = $this->controls();
		$this->follow( 'private', 'Q control', 'Quentin Sentinel', 'https://fw260-sentinel-q.example/' );
		$presence = $this->presence();

		$this->go_to( home_url( '/?kind=follow' ) );
		$ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertContains( $presence, $ids );
		$this->assertSame( [], array_values( array_intersect( array_values( $controls ), $ids ) ) );

		$this->go_to( home_url( '/?kind=follow&pkiw_follow_initial=F' ) );
		$ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertSame( [ $presence ], $ids, 'letter F lists only the eligible follow' );

		$this->go_to( home_url( '/?kind=follow&pkiw_follow_initial=Q' ) );
		$this->assertSame( [], $GLOBALS['wp_query']->posts, 'a letter only a private follow holds lists nothing' );

		$this->go_to( home_url( '/?kind=follow&feed=rss2' ) );
		$ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertContains( $presence, $ids );
		$this->assertSame( [], array_values( array_intersect( array_values( $controls ), $ids ) ) );
	}

	public function test_opml_lists_only_eligible_follows_for_visitors_and_administrators(): void {
		$this->controls();
		$this->controls( '' );
		$presence = $this->presence();
		$this->follow( 'publish', 'No URL', 'Fw260 No Url Person', '' );

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'administrator' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			delete_transient( 'pkiw_follow_index_v1' );
			$response = Follow_Archive::opml_response( 'GET' );
			$this->assertSame( 200, $response['status'] );
			$this->assertSame( 'text/x-opml; charset=UTF-8', $response['headers']['Content-Type'] ?? null );
			$this->assertSame( 'noindex', $response['headers']['X-Robots-Tag'] ?? null );
			$this->assertStringContainsString( 'no-store', (string) ( $response['headers']['Cache-Control'] ?? '' ) );
			$xml = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NONET );
			$this->assertInstanceOf( SimpleXMLElement::class, $xml, 'OPML parses' );
			$this->assertSame( '2.0', (string) $xml['version'] );
			$urls = array_map( 'strval', $xml->xpath( '//outline/@url' ) );
			$this->assertSame( [ self::VISIBLE_URL ], $urls, 'only the presence control, and no outline without a URL' );
			$this->assert_no_sentinel( $response['body'], 'OPML as user ' . $user_id );
			$this->assertStringNotContainsString( 'Fw260 No Url Person', $response['body'] );
		}

		$head = Follow_Archive::opml_response( 'HEAD' );
		$this->assertSame( 200, $head['status'] );
		$this->assertSame( '', $head['body'] );
		$post = Follow_Archive::opml_response( 'POST' );
		$this->assertSame( 405, $post['status'] );
		$this->assertSame( 'GET, HEAD', $post['headers']['Allow'] ?? null );
		$this->assertSame( '', $post['body'] );

		$this->assertContains( 'pkiw_follow_opml', $GLOBALS['wp']->public_query_vars );
		$rules = (array) $GLOBALS['wp_rewrite']->extra_rules_top;
		$this->assertArrayHasKey( '^kind/follow/opml/?$', $rules );
		$this->assertStringContainsString( 'pkiw_follow_opml=1', (string) $rules['^kind/follow/opml/?$'] );
		$this->assertGreaterThan( 0, $presence );
	}

	public function test_serve_time_check_drops_stale_cached_rows_on_every_index_reader(): void {
		$presence = $this->presence();
		$stale    = [
			'private' => $this->follow( 'publish', 'Stale', 'Bo Private', 'https://fw260-sentinel-b.example/' ),
			'deleted' => $this->follow( 'publish', 'Stale', 'Cy Deleted', 'https://fw260-sentinel-c.example/' ),
			'rekind'  => $this->follow( 'publish', 'Stale', 'Di Rekind', 'https://fw260-sentinel-d.example/' ),
		];
		$cached = Follow_Archive::index();
		$this->assertCount( 4, $cached['follows'], 'positive control: the cache held all four rows' );

		wp_update_post( [ 'ID' => $stale['private'], 'post_status' => 'private' ] );
		wp_delete_post( $stale['deleted'], true );
		wp_set_object_terms( $stale['rekind'], 'note', Taxonomy::TAXONOMY );
		// Simulate the build-and-flush race: a request that started before the changes writes its stale copy.
		set_transient( 'pkiw_follow_index_v1', $cached, HOUR_IN_SECONDS );

		$served = Follow_Archive::served_index();
		$ids    = array_map( static fn( array $row ): int => (int) $row['post_id'], $served['follows'] );
		$this->assertSame( [ $presence ], $ids );
		foreach ( [ 'B', 'C', 'D' ] as $letter ) {
			$this->assertArrayNotHasKey( $letter, $served['letters'] );
		}
		$this->assertSame( 1, (int) $served['total'] );

		$letters = $this->render_block_for( 'post-kinds-indieweb/follow-letters', [], $presence );
		$this->assertStringContainsString( 'pkiw_follow_initial=F', $letters );
		$this->assertStringNotContainsString( 'pkiw_follow_initial=B', $letters );

		$this->assert_no_sentinel( Follow_Archive::opml_response( 'GET' )['body'], 'stale OPML' );
		$this->assertStringContainsString( self::VISIBLE_URL, Follow_Archive::opml_response( 'GET' )['body'] );

		$index = Follow_Abilities::instance()->execute_get_follow_index( [] );
		$this->assertSame( [ $presence ], array_map( static fn( array $row ): int => (int) $row['post_id'], $index['follows'] ) );

		$this->go_to( home_url( '/?kind=follow&pkiw_follow_initial=B' ) );
		$this->assertSame( [], $GLOBALS['wp_query']->posts );
	}

	public function test_follow_index_ability_is_public_and_user_independent(): void {
		$controls = $this->controls();
		$presence = $this->presence();
		$this->assertContains( 'post-kinds/get-follow-index', Abilities_Manager::MCP_PUBLIC_ABILITIES );

		$outputs = [];
		foreach ( [ 'subscriber', 'administrator' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$outputs[ $role ] = Follow_Abilities::instance()->execute_get_follow_index( [] );
			$ids = array_map( static fn( array $row ): int => (int) $row['post_id'], $outputs[ $role ]['follows'] );
			$this->assertContains( $presence, $ids, $role );
			$this->assertSame( [], array_values( array_intersect( array_values( $controls ), $ids ) ), $role );
			$this->assert_no_sentinel( (string) wp_json_encode( $outputs[ $role ] ), $role );
		}
		$this->assertSame( $outputs['subscriber'], $outputs['administrator'] );

		$f = Follow_Abilities::instance()->execute_get_follow_index( [ 'letter' => 'f' ] );
		$this->assertSame( [ $presence ], array_map( static fn( array $row ): int => (int) $row['post_id'], $f['follows'] ) );
	}

	public function test_untitled_names_and_plugin_stream_cards_never_read_hidden_cite_meta(): void {
		$controls = $this->controls( '' );
		$presence = $this->presence( '' );

		foreach ( $controls as $case => $id ) {
			$post = get_post( $id );
			$this->assert_no_sentinel( PKIW\untitled_name( $post, false ), $case . ' untitled_name' );
			$this->assert_no_sentinel( PKIW\untitled_name( $post, true ), $case . ' untitled_name content' );
			$this->assert_no_sentinel( (string) pkiw_get_safe_title( $id ), $case . ' safe title' );
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stream card fixture.
			$this->assert_no_sentinel( PKIW\render_stream_card(), $case . ' stream card' );
		}

		$this->assertSame( self::VISIBLE_NAME, PKIW\untitled_name( get_post( $presence ), false ) );
		$GLOBALS['post'] = get_post( $presence ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stream card fixture.
		$card = PKIW\render_stream_card();
		$this->assertStringContainsString( 'u-follow-of h-card', $card );
		$this->assertStringContainsString( 'value="' . self::VISIBLE_URL . '"', $card );
		$this->assertStringContainsString( 'value="' . self::VISIBLE_NAME . '"', $card );
		unset( $GLOBALS['post'] );
	}

	public function test_rest_meta_gate_keeps_cite_keys_only_for_eligible_follows_and_edit_context(): void {
		global $wpdb;

		$private  = $this->follow( 'private' );
		$presence = $this->presence();
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $presence, 'meta_key' => '_pkiw_cite_photo', 'meta_value' => self::REMOTE_PHOTO ] ); // phpcs:ignore WordPress.DB.SlowDBQuery -- A row written before 1.9.2.
		wp_cache_delete( $presence, 'post_meta' );

		$visitor = $this->rest( 'GET', '/wp/v2/posts/' . $presence );
		$this->assertSame( 200, $visitor->get_status() );
		$meta = $visitor->get_data()['meta'];
		$this->assertSame( self::VISIBLE_NAME, $meta['_pkiw_cite_name'] ?? null, 'eligible cite keys stay readable (I14)' );
		$this->assertSame( self::VISIBLE_URL, $meta['_pkiw_cite_url'] ?? null );
		$this->assertSame( '', $meta['_pkiw_cite_photo'] ?? null, 'a remote photo row reads empty' );
		$this->assertStringNotContainsString( 'fw260-photo', (string) wp_json_encode( $visitor->get_data() ) );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		$view = $this->rest( 'GET', '/wp/v2/posts/' . $private, [ 'context' => 'view' ] );
		$this->assertSame( 200, $view->get_status(), 'O-6: an editor keeps core access' );
		$data = $view->get_data();
		$this->assertSame( 0, (int) $data['featured_media'] );
		$this->assertSame( [], array_values( array_filter( array_keys( (array) $data['meta'] ), static fn( $key ): bool => str_starts_with( (string) $key, '_pkiw_' ) ) ) );
		$this->assert_no_sentinel( (string) wp_json_encode( $data['meta'] ), 'editor view context' );

		$edit = $this->rest( 'GET', '/wp/v2/posts/' . $private, [ 'context' => 'edit' ] );
		$this->assertSame( 200, $edit->get_status() );
		$this->assertSame( self::NAME, $edit->get_data()['meta']['_pkiw_cite_name'] ?? null, 'the block editor keeps its fields' );
		$this->assertNotSame( 0, (int) $edit->get_data()['featured_media'] );
	}

	public function test_rest_photo_read_rechecks_the_attachment_parent(): void {
		$presence = $this->presence();
		$hidden   = $this->follow( 'private', 'Parent' );
		$public   = $this->upload( 'fw260-public-photo.png' );
		$private  = $this->upload( 'fw260-sentinel-private-photo.png', $hidden );
		$public_url = (string) wp_get_attachment_url( $public );

		update_post_meta( $presence, '_pkiw_cite_photo', $public_url );
		$this->assertSame( $public_url, $this->rest( 'GET', '/wp/v2/posts/' . $presence )->get_data()['meta']['_pkiw_cite_photo'] ?? null, 'positive control' );

		// The parent turns private after the write.
		wp_update_post( [ 'ID' => $public, 'post_parent' => $hidden ] );
		$this->assertSame( '', $this->rest( 'GET', '/wp/v2/posts/' . $presence )->get_data()['meta']['_pkiw_cite_photo'] ?? null );
		$this->assertGreaterThan( 0, $private );
	}

	public function test_cite_photo_writes_accept_only_public_media_library_images(): void {
		$follow  = $this->presence();
		$hidden  = $this->follow( 'private', 'Parent' );
		$image   = (string) wp_get_attachment_url( $this->upload( 'fw260-ok.png' ) );
		$private = (string) wp_get_attachment_url( $this->upload( 'fw260-private.png', $hidden ) );
		$pdf     = (string) wp_get_attachment_url( $this->upload( 'fw260-doc.pdf', 0, 'wordpress-gsoc-flyer.pdf' ) );

		foreach ( [ self::REMOTE_PHOTO, $private, $pdf, home_url( '/wp-content/uploads/../wp-config.php' ) ] as $bad ) {
			$this->assertNotFalse( add_post_meta( $follow, '_pkiw_cite_photo', $bad ), 'refusal reports success: ' . $bad );
			$this->assertFalse( metadata_exists( 'post', $follow, '_pkiw_cite_photo' ), $bad );
			$this->assertNotFalse( update_post_meta( $follow, '_pkiw_cite_photo', $bad ), $bad );
			$this->assertFalse( metadata_exists( 'post', $follow, '_pkiw_cite_photo' ), $bad );
		}
		$this->assertNotFalse( update_post_meta( $follow, '_pkiw_cite_photo', $image ) );
		$this->assertSame( $image, get_post_meta( $follow, '_pkiw_cite_photo', true ), 'a public media-library image is accepted' );
		$this->assertNotFalse( update_post_meta( $follow, '_pkiw_cite_photo', '' ), 'clearing works' );

		// The guard is follow-only: another kind keeps today's behavior.
		$like = $this->follow( 'publish', 'Like', 'Liked', 'https://liked.example/', 'like', false );
		update_post_meta( $like, '_pkiw_cite_photo', self::REMOTE_PHOTO );
		$this->assertSame( self::REMOTE_PHOTO, get_post_meta( $like, '_pkiw_cite_photo', true ) );

		// Gaining the follow term drops a row that fails the rule.
		wp_set_object_terms( $like, 'follow', Taxonomy::TAXONOMY );
		$this->assertFalse( metadata_exists( 'post', $like, '_pkiw_cite_photo' ) );
	}

	public function test_kind_switch_save_through_rest_succeeds_without_storing_a_remote_photo(): void {
		$like = $this->follow( 'publish', 'Like', 'Liked', 'https://liked.example/', 'like', false );
		update_post_meta( $like, '_pkiw_cite_photo', self::REMOTE_PHOTO );
		$follow_term = get_term_by( 'slug', 'follow', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $follow_term );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$response = $this->rest(
			'POST',
			'/wp/v2/posts/' . $like,
			[
				'kind' => [ (int) $follow_term->term_id ],
				'meta' => [ '_pkiw_cite_photo' => self::REMOTE_PHOTO ],
			]
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertTrue( has_term( 'follow', Taxonomy::TAXONOMY, $like ) );
		$this->assertFalse( metadata_exists( 'post', $like, '_pkiw_cite_photo' ) );
	}

	public function test_oembed_refuses_hidden_tag_and_follow_posts_like_unknown_urls(): void {
		$missing = $this->rest( 'GET', '/oembed/1.0/embed', [ 'url' => home_url( '/fw260-no-such-post/' ) ] );
		$this->assertSame( 404, $missing->get_status() );
		$missing_code = $missing->as_error()->get_error_code();

		foreach ( [ 'follow', 'tag' ] as $kind ) {
			foreach ( [ 'password', 'private', 'draft' ] as $case ) {
				$id = $this->follow( $case, 'Fw260 Sentinel ' . $kind . ' title', self::NAME, self::URL, $kind );
				$this->assertFalse( is_post_embeddable( $id ), $kind . ' ' . $case );
				$this->assertFalse( get_oembed_response_data( $id, 600 ), $kind . ' ' . $case );
				$response = $this->rest( 'GET', '/oembed/1.0/embed', [ 'url' => get_permalink( $id ) ] );
				$this->assertSame( 404, $response->get_status(), $kind . ' ' . $case );
				$this->assertSame( $missing_code, $response->as_error()->get_error_code(), $kind . ' ' . $case );
				$this->assert_no_sentinel( (string) wp_json_encode( $response->get_data() ), $kind . ' ' . $case );
			}
			$public = $this->follow( 'publish', 'Fw260 Public ' . $kind, self::VISIBLE_NAME, self::VISIBLE_URL, $kind );
			$this->assertTrue( is_post_embeddable( $public ), 'positive control ' . $kind );
			$ok = $this->rest( 'GET', '/oembed/1.0/embed', [ 'url' => get_permalink( $public ) ] );
			$this->assertSame( 200, $ok->get_status(), 'positive control ' . $kind );
			$this->assertSame( 'Fw260 Public ' . $kind, $ok->get_data()['title'] ?? null );
		}

		$password = $this->follow( 'password', 'Fw260 Sentinel embed page' );
		$this->go_to( get_permalink( $password ) . 'embed/' );
		do_action( 'template_redirect' );
		$this->assertTrue( is_404() );
		$this->assertFalse( is_embed() );
	}

	public function test_hidden_mf2_and_micropub_renderer_for_follow_singles(): void {
		$presence = $this->presence();
		$controls = $this->controls();
		$mf2      = new Microformats();

		$GLOBALS['post'] = get_post( $presence ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Microformats fixture context.
		setup_postdata( $GLOBALS['post'] );
		$html = $mf2->add_hidden_mf2_data( '<p>Body</p>' );
		$this->assertSame( 1, substr_count( $html, 'u-follow-of h-card' ), 'one hidden follow-of h-card' );
		$this->assertStringContainsString( 'value="' . self::VISIBLE_NAME . '"', $html );
		$this->assertStringContainsString( 'value="' . self::VISIBLE_URL . '"', $html );

		foreach ( $controls as $case => $id ) {
			$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Microformats fixture context.
			setup_postdata( $GLOBALS['post'] );
			$out = $mf2->add_hidden_mf2_data( '<p>Body</p>' );
			$this->assertStringNotContainsString( 'u-follow-of', $out, $case );
			$this->assert_no_sentinel( $out, $case );
		}
		wp_reset_postdata();

		$this->assertFalse( apply_filters( 'micropub_dynamic_render', true, get_post( $presence ) ) );
		$note = self::factory()->post->create();
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$this->assertTrue( apply_filters( 'micropub_dynamic_render', true, get_post( $note ) ), 'another kind keeps Micropub rendering' );
	}

	public function test_webmention_notifies_public_follows_on_both_paths_and_never_hidden_ones(): void {
		// D-260-2 answer A: an editor-made follow (URL only in the Site URL field) notifies too.
		$editor_made = self::factory()->post->create( [ 'post_content' => '<!-- wp:paragraph --><p>A note.</p><!-- /wp:paragraph -->' ] );
		wp_set_object_terms( $editor_made, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $editor_made, '_pkiw_cite_url', self::VISIBLE_URL );
		$this->assertContains( self::VISIBLE_URL, (array) apply_filters( 'webmention_links', [], $editor_made ) );

		$presence = $this->presence();
		$links    = (array) apply_filters( 'webmention_links', [ 'https://other.example/' ], $presence );
		$this->assertContains( self::VISIBLE_URL, $links );
		$this->assertContains( 'https://other.example/', $links );

		foreach ( $this->controls() as $case => $id ) {
			$this->assertSame( [], (array) apply_filters( 'webmention_links', [ self::URL ], $id ), $case );
			$ping = [ self::URL ];
			$pung = [];
			do_action_ref_array( 'pre_ping', [ &$ping, &$pung, $id ] );
			$this->assertSame( [], $ping, $case );
		}
		// Pingback follows the site setting for a public follow: the list passes through.
		$ping = [ self::VISIBLE_URL ];
		$pung = [];
		do_action_ref_array( 'pre_ping', [ &$ping, &$pung, $presence ] );
		$this->assertSame( [ self::VISIBLE_URL ], $ping );
	}

	public function test_lane_php_uses_only_the_shared_predicate(): void {
		$root = dirname( __DIR__, 3 );
		foreach ( [ 'includes/class-follow-archive.php', 'includes/abilities/class-follow-abilities.php' ] as $relative ) {
			$this->assertFileExists( $root . '/' . $relative );
			$source = (string) file_get_contents( $root . '/' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source grep.
			$this->assertStringNotContainsString( 'post_status', $source, $relative );
			$this->assertStringNotContainsString( 'post_password', $source, $relative );
		}
		$archive = (string) file_get_contents( $root . '/includes/class-follow-archive.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source grep.
		$this->assertStringContainsString( 'Public_Eligibility::is_eligible', $archive, 'positive control: the gate is called' );
	}
}
