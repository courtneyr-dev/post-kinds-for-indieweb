<?php
/**
 * Podcast listen privacy boundaries (issue 236).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Listen_Archive;
use PKIW\Listen_Media_Type;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ListenPrivacyTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	private string $original_date_format = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet  = get_stylesheet();
		$this->original_timezone    = (string) get_option( 'timezone_string' );
		$this->original_date_format = (string) get_option( 'date_format' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		switch_theme( 'twentytwentyfive' );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Listen_Archive() )->register();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		add_filter( 'pre_oembed_result', [ $this, 'oembed_result' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_oembed_result', [ $this, 'oembed_result' ], 10 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		update_option( 'timezone_string', $this->original_timezone );
		update_option( 'date_format', $this->original_date_format );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * @param mixed                $result Existing result.
	 * @param string               $url    Requested URL.
	 * @param array<string, mixed> $args   Request arguments.
	 * @return mixed
	 */
	public function oembed_result( $result, string $url, array $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return str_contains( $url, 'audio.example.org' ) ? '<iframe src="https://audio.example.org/player"></iframe>' : $result;
	}

	/**
	 * @param string               $title Post title.
	 * @param string               $show  Show name.
	 * @param string               $type  Stored media type.
	 * @param array<string, mixed> $args  Post overrides.
	 * @param array<string, mixed> $meta  More metadata.
	 */
	private function listen( string $title, string $show = 'Alder Street Radio', string $type = 'podcast', array $args = [], array $meta = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => '2026-09-26 14:00:00',
					'post_author' => self::factory()->user->create( [ 'display_name' => 'Jordan Vale' ] ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'listen', Taxonomy::TAXONOMY );
		$values = array_merge(
			[
				Listen_Media_Type::META => $type,
				'_pkiw_listen_track'     => str_replace( 'Listened to ', '', $title ),
				'_pkiw_listen_album'     => $show,
				'_pkiw_listen_url'       => 'https://audio.example.org/episodes/' . sanitize_title( $title ),
				'_pkiw_listen_listened_at' => '2026-09-26',
			],
			$meta
		);
		foreach ( $values as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return (int) $id;
	}

	private function render_line( int $post_id ): string {
		$block = new WP_Block(
			[
				'blockName'    => Listen_Archive::EPISODE_BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[ 'postId' => $post_id, 'postType' => 'post' ]
		);

		return Listen_Archive::render( [], '', $block );
	}

	private function stream_card( int $post_id ): string {
		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		return do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );
	}

	private function podcast_url(): string {
		return add_query_arg( Listen_Archive::QUERY_VAR, 'podcast', get_term_link( 'listen', Taxonomy::TAXONOMY ) );
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template(
			'taxonomy',
			[ 'taxonomy-kind-listen-podcast.php', 'taxonomy-kind-listen.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ],
			''
		);
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	public function test_locked_line_prints_only_the_protected_title_and_published_day(): void {
		$id = $this->listen( 'Listened to Closed Signal', 'Alder Street Radio', 'podcast', [ 'post_password' => 'secret' ] );
		$html = $this->render_line( $id );

		$this->assertSame( [], Kind_Facts::get( $id ) );
		$this->assertStringContainsString( 'Protected: Listened to Closed Signal', $html );
		$this->assertStringContainsString( '2026-09-26', $html );
		foreach ( [ 'p-author', 'h-card', 'Jordan Vale', 'author/', 'gravatar', 'avatar', 'u-listen-of', '<img', 'pk-kind-cite', 'aria-labelledby' ] as $private ) {
			$this->assertStringNotContainsString( $private, $html, $private );
		}
	}

	public function test_locked_posts_file_under_other_and_never_supply_a_station_cover(): void {
		$public = $this->listen( 'Listened to Open Signal' );
		$locked = $this->listen( 'Listened to Closed Signal', 'alder street radio', 'podcast', [ 'post_password' => 'secret', 'post_date' => '2026-09-27 14:00:00' ] );
		$public_image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $public );
		$locked_image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $locked );
		set_post_thumbnail( $public, $public_image );
		set_post_thumbnail( $locked, $locked_image );
		$public_url = (string) wp_get_attachment_url( $public_image );
		$locked_url = (string) wp_get_attachment_url( $locked_image );

		$xpath = $this->xpath( $this->serve( $this->podcast_url() ) );
		$alder = $this->station( $xpath, 'Alder Street Radio' );
		$other = $this->station( $xpath, 'Other' );
		$this->assertInstanceOf( DOMElement::class, $alder );
		$this->assertInstanceOf( DOMElement::class, $other );
		$this->assertStringContainsString( 'Open Signal', $alder->textContent );
		$this->assertStringNotContainsString( 'Closed Signal', $alder->textContent );
		$this->assertStringContainsString( 'Protected: Listened to Closed Signal', $other->textContent );
		$this->assertSame( 1, $xpath->query( './/img[@src="' . $public_url . '"]', $alder )->length );
		$this->assertSame( 0, $xpath->query( './/img', $other )->length );
		$this->assertSame( 1, $xpath->query( './/div[contains(@class,"pkiw-lineup__cover--none")]', $other )->length );
		$this->assertSame( 0, $xpath->query( './/img[@src="' . $locked_url . '"]', $alder )->length );
	}

	public function test_empty_show_group_never_prints_a_readable_posts_cover(): void {
		$id = $this->listen( 'Listened to Nameless Show', '' );
		$image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
		set_post_thumbnail( $id, $image );

		$xpath = $this->xpath( $this->serve( $this->podcast_url() ) );
		$other = $this->station( $xpath, 'Other' );
		$this->assertInstanceOf( DOMElement::class, $other );
		$this->assertSame( 0, $xpath->query( './/img', $other )->length );
		$this->assertSame( 1, $xpath->query( './/div[contains(@class,"pkiw-lineup__cover--none")]', $other )->length );
	}

	public function test_filtered_rest_route_applies_role_and_media_type_boundaries(): void {
		$public = $this->listen( 'Listened to Public Podcast' );
		$locked = $this->listen( 'Listened to Locked Podcast', 'Alder Street Radio', 'podcast', [ 'post_password' => 'secret', 'post_date' => '2026-09-25 14:00:00' ] );
		$music = $this->listen( 'Listened to Public Music', 'Alder Street Radio', 'music' );
		$GLOBALS['wp_rest_server'] = null;
		$term = get_term_by( 'slug', 'listen', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );

		$ids = function ( bool $view = true ) use ( $term ): array {
			$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
			$request->set_param( 'kind', [ $term->term_id ] );
			$request->set_param( 'per_page', 20 );
			if ( $view ) {
				$request->set_param( 'orderby', 'pkiw_group__podcast' );
			}
			$response = rest_get_server()->dispatch( $request );
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

			return array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
		};

		wp_set_current_user( 0 );
		$this->assertSame( [ $public ], $ids() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( [ $public ], $ids() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( [ $public, $locked ], $ids() );
		$this->assertContains( $music, $ids( false ), 'A request with no view still lists music.' );
	}

	public function test_rest_redacts_locked_media_type_even_for_a_password_holder(): void {
		$public = $this->listen( 'Listened to Public Podcast' );
		$locked = $this->listen( 'Listened to Locked Podcast', 'Alder Street Radio', 'podcast', [ 'post_password' => 'secret' ] );
		$GLOBALS['wp_rest_server'] = null;

		$public_data = $this->rest_post( $public );
		$this->assertSame( 'podcast', $public_data['meta'][ Listen_Media_Type::META ] );

		$locked_data = $this->rest_post( $locked );
		$this->assertArrayNotHasKey( Listen_Media_Type::META, $locked_data['meta'] ?? [] );
		$this->assertStringNotContainsString( Listen_Media_Type::META, (string) wp_json_encode( $locked_data ) );

		require_once ABSPATH . WPINC . '/class-phpass.php';
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'secret' );
		$this->assertFalse( post_password_required( $locked ) );
		$holder_data = $this->rest_post( $locked );
		$this->assertArrayNotHasKey( Listen_Media_Type::META, $holder_data['meta'] ?? [] );
		$this->assertStringNotContainsString( Listen_Media_Type::META, (string) wp_json_encode( $holder_data ) );
	}

	public function test_embed_markup_never_reaches_archive_stream_feed_or_lineup(): void {
		$id = $this->listen( 'Listened to Embeddable Episode' );

		$this->go_to( $this->podcast_url() );
		$this->assertSame( '', Kind_Facts::get( $id )['embed_html'] );
		$archive = $this->serve( $this->podcast_url() );
		$line = $this->render_line( $id );
		$stream = $this->stream_card( $id );
		$term = get_term_by( 'slug', 'listen', Taxonomy::TAXONOMY );
		$this->go_to( add_query_arg( Listen_Archive::QUERY_VAR, 'podcast', get_term_feed_link( $term->term_id, Taxonomy::TAXONOMY ) ) );
		$this->assertTrue( is_feed() );
		$this->assertSame( '', Kind_Facts::get( $id )['embed_html'] );

		foreach ( [ $archive, $line, $stream ] as $html ) {
			$this->assertDoesNotMatchRegularExpression( '/<(?:iframe|script)\b|pk-embed/i', $html );
		}

		$this->go_to( get_permalink( $id ) );
		$this->assertStringContainsString( '<iframe', Kind_Facts::get( $id )['embed_html'] );
	}

	public function test_stream_citations_exist_only_for_readable_podcasts(): void {
		$music = $this->listen( 'Listened to Song', 'Example Album', 'music' );
		$locked = $this->listen( 'Listened to Closed Signal', 'Alder Street Radio', 'podcast', [ 'post_password' => 'secret' ] );
		$podcast = $this->listen( 'Listened to Open Signal' );

		$this->assertStringNotContainsString( 'u-listen-of', $this->stream_card( $music ) );
		$locked_html = $this->stream_card( $locked );
		foreach ( [ 'u-listen-of', 'p-author', 'gravatar', 'avatar' ] as $private ) {
			$this->assertStringNotContainsString( $private, $locked_html );
		}

		$html = $this->stream_card( $podcast );
		$this->assertSame( 1, substr_count( $html, 'u-listen-of' ) );
		$this->assertStringContainsString( 'class="p-name" value="Open Signal"', $html );
		$this->assertStringContainsString( 'class="u-url" value="https://audio.example.org/episodes/listened-to-open-signal"', $html );
		$this->assertStringContainsString( 'class="p-name" value="Alder Street Radio"', $html );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rest_post( int $post_id ): array {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'view' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (array) $response->get_data();
	}

	private function station( DOMXPath $xpath, string $heading ): ?DOMElement {
		foreach ( $xpath->query( '//section[contains(concat(" ",@class," ")," pkiw-lineup__station ")]' ) as $section ) {
			$node = $xpath->query( './h2|./h3|./h4|./h5|./h6', $section )->item( 0 );
			if ( $node instanceof DOMElement && $heading === trim( $node->textContent ) ) {
				return $section;
			}
		}

		return null;
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}
}
