<?php
/**
 * Collection Stream card coverage for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Collection_Items;
use PKIW\Taxonomy;

/**
 * Collection cards use an archive listing shape and a newest-item Stream shape.
 *
 * @group integration
 */
final class CollectionStreamCardTest extends WP_UnitTestCase {

	/** Image IDs available to referenced and external items. */
	private array $images = [];

	/** Register kinds, URLs, and media. */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		update_option( 'date_format', 'F j, Y' );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		for ( $i = 0; $i < 5; ++$i ) {
			$this->images[] = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
		}
		wp_set_current_user( 0 );
		Collection_Items::reset_cache();
	}

	/** Restore URLs and user state. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/** One item block. */
	private function item( array $attributes ): string {
		return '<!-- wp:post-kinds-indieweb/collection-item ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/** Create a post assigned to one kind. */
	private function post( string $kind, string $content = '', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Desk Tools',
					'post_content' => wp_slash( $content ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return $id;
	}

	/** Render through the full Stream card pipeline. */
	private function render( int $post_id, array $attributes = [] ): string {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return \PKIW\render_stream_card( $attributes );
	}

	/** XPath helper for markup assertions. */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/** Archive requests get a four-image listing card with one link. */
	public function test_collection_taxonomy_uses_listing_shape(): void {
		$content = '';
		foreach ( $this->images as $index => $image_id ) {
			$content .= $this->item( [ 'title' => 'Item ' . $index, 'mediaId' => $image_id ] );
		}
		$id = $this->post( 'collection', $content );
		$this->go_to( (string) get_term_link( 'collection', Taxonomy::TAXONOMY ) );
		$html  = $this->render( $id, [ 'headingLevel' => 3 ] );
		$xpath = $this->xpath( $html );

		$this->assertStringContainsString( 'pk-card--collection-listing', $html );
		$this->assertStringNotContainsString( 'pk-card--collection-stream', $html );
		$this->assertSame( 4, $xpath->query( '//div[contains(@class,"pk-collection__bay")]//img' )->length );
		$this->assertSame( 1, $xpath->query( '//a[@href]' )->length );
		foreach ( $xpath->query( '//img' ) as $image ) {
			$this->assertSame( '', $image->getAttribute( 'alt' ) );
		}
		$this->assertStringContainsString( '5 items', $html );
	}

	/** The server-side block renderer gives the Site Editor the listing shape. */
	public function test_block_renderer_preview_uses_listing_shape(): void {
		$id = $this->post( 'collection', $this->item( [ 'title' => 'Field recorder' ] ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/stream-card' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $id );
		$request->set_param( 'pkiw_kind', 'collection' );
		$request->set_param( 'attributes', [ 'headingLevel' => 2 ] );
		$response                  = rest_get_server()->dispatch( $request );
		$GLOBALS['wp_rest_server'] = null;
		$html                      = (string) ( $response->get_data()['rendered'] ?? '' );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertStringContainsString( 'pk-card--collection-listing', $html );
		$this->assertStringNotContainsString( 'pk-card--collection-stream', $html );
	}

	/** Non-collection archive surfaces use the Stream shape and visible newest item. */
	public function test_other_surfaces_use_stream_shape_and_visible_newest_item(): void {
		$hidden = self::factory()->post->create( [ 'post_status' => 'draft', 'post_title' => 'Hidden newest' ] );
		$content = $this->item( [ 'title' => 'Visible newest', 'date' => '2020-03-04', 'source' => 'Personal archive', 'mediaId' => $this->images[0], 'addedAt' => '2026-10-05T10:00:00Z' ] )
			. $this->item( [ 'postId' => $hidden, 'addedAt' => '2026-10-20T10:00:00Z' ] );
		$id = $this->post( 'collection', $content );
		$this->go_to( get_permalink( $id ) );
		$html  = $this->render( $id, [ 'headingLevel' => 3 ] );
		$xpath = $this->xpath( $html );

		$this->assertStringContainsString( 'pk-card--collection-stream', $html );
		$this->assertStringContainsString( 'Visible newest', $html );
		$this->assertStringNotContainsString( 'Hidden newest', $html );
		$this->assertSame( 1, $xpath->query( '//a[@href]' )->length );
		$time = $xpath->query( '//div[contains(@class,"pk-collection__newest")]//time' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $time );
		$this->assertSame( '2020-03-04', $time->getAttribute( 'datetime' ) );
		$this->assertStringNotContainsString( 'dt-published', $time->getAttribute( 'class' ) );
		$this->assertSame( 0, $xpath->query( '//div[contains(@class,"pk-collection__newest")]//*[contains(concat(" ",normalize-space(@class)," ")," p-name ")]' )->length );
	}

	/** All-hidden collections render no count, image, or newest block. */
	public function test_all_hidden_collection_has_no_item_summary(): void {
		$draft = self::factory()->post->create( [ 'post_status' => 'draft', 'post_title' => 'Hidden' ] );
		$id    = $this->post( 'collection', $this->item( [ 'postId' => $draft ] ) );
		$this->go_to( get_permalink( $id ) );
		$html = $this->render( $id );

		$this->assertStringNotContainsString( 'pk-collection__count', $html );
		$this->assertStringNotContainsString( '<img ', $html );
		$this->assertStringNotContainsString( 'pk-collection__newest', $html );
	}

	/** The core pipeline intercepts protected collections before collection facts render. */
	public function test_password_collection_uses_the_shipped_protected_card(): void {
		$id = $this->post( 'collection', $this->item( [ 'title' => 'Secret item' ] ), [ 'post_password' => 'secret' ] );
		$this->go_to( get_permalink( $id ) );
		$html = $this->render( $id );

		$this->assertStringContainsString( 'pk-card--protected', $html );
		$this->assertStringNotContainsString( 'Secret item', $html );
		$this->assertSame( 1, $this->xpath( $html )->query( '//a[@href]' )->length );
	}

	/** The callback preserves its first argument for every kind it does not own. */
	public function test_callback_passes_through_other_kinds_and_non_posts(): void {
		foreach ( [ 'jam', 'wish', 'note' ] as $kind ) {
			$post = get_post( $this->post( $kind ) );
			$this->assertInstanceOf( WP_Post::class, $post );
			$this->assertSame( '<p>earlier</p>', Collection_Items::stream_card( '<p>earlier</p>', $post, 2, [] ) );
			$this->assertNull( Collection_Items::stream_card( null, $post, 2, [] ) );
		}
		$this->assertSame( '<p>earlier</p>', Collection_Items::stream_card( '<p>earlier</p>', 'not a post' ) );
	}
}
