<?php
/**
 * Collection privacy surface coverage for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Collection_Items;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/**
 * Collection references expose only public title, permalink, day, and featured image.
 *
 * @group integration
 */
final class CollectionPrivacyTest extends WP_UnitTestCase {

	/** Hidden referenced titles. */
	private array $hidden_titles = [];

	/** Register kind/meta behavior and stable URLs. */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'date_format', 'F j, Y' );
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
		Collection_Items::reset_cache();
	}

	/** Restore user and URL state. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/** One item block. */
	private function item( array $attributes ): string {
		return '<!-- wp:post-kinds-indieweb/collection-item ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/** One cabinet group. */
	private function cabinet( string $items ): string {
		return '<!-- wp:group {"className":"pkiw-collection"} --><div class="wp-block-group pkiw-collection">' . $items . '</div><!-- /wp:group -->';
	}

	/** Create a collection post. */
	private function collection( string $content, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Private reference cabinet',
					'post_content' => wp_slash( $content ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, Collection_Items::KIND, Taxonomy::TAXONOMY );
		return $id;
	}

	/** Build a cabinet whose six references all resolve to hidden. */
	private function hidden_collection(): int {
		$specs = [
			[ 'draft', '', 'Hidden draft 240' ],
			[ 'private', '', 'Hidden private 240' ],
			[ 'publish', 'secret', 'Hidden password 240' ],
			[ 'pending', '', 'Hidden pending 240' ],
			[ 'publish', '', 'Hidden trashed 240' ],
		];
		$items = '';
		foreach ( $specs as [ $status, $password, $title ] ) {
			$id = self::factory()->post->create( [ 'post_status' => $status, 'post_password' => $password, 'post_title' => $title ] );
			if ( 'Hidden trashed 240' === $title ) {
				wp_trash_post( $id );
			}
			$this->hidden_titles[] = $title;
			$items .= $this->item( [ 'postId' => $id ] );
		}
		$items .= $this->item( [ 'postId' => 999999 ] );
		return $this->collection( $this->cabinet( $items ) );
	}

	/** Assert a surface contains no case, cabinet, or hidden title. */
	private function assert_hidden_surface( string $html, string $surface ): void {
		$this->assertStringNotContainsString( 'pkiw-collection-item', $html, $surface );
		$this->assertStringNotContainsString( 'pkiw-collection', $html, $surface );
		foreach ( $this->hidden_titles as $title ) {
			$this->assertStringNotContainsString( $title, $html, $surface . ': ' . $title );
		}
	}

	/** Single, feed filter paths, excerpt, REST, and federation proxy all drop hidden references. */
	public function test_hidden_references_are_absent_from_every_content_surface(): void {
		$id      = $this->hidden_collection();
		$content = (string) get_post_field( 'post_content', $id );
		$this->go_to( get_permalink( $id ) );
		$this->assert_hidden_surface( (string) apply_filters( 'the_content', $content ), 'single' );

		// The feed templates are impractical inside an isolated unit request; each
		// feed query still exercises the same the_content render path they call.
		foreach ( [ 'rss2', 'atom', 'json' ] as $feed ) {
			$this->go_to( '/?feed=' . $feed . '&p=' . $id );
			$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->assert_hidden_surface( (string) apply_filters( 'the_content', $content ), $feed . ' feed filter path' );
		}

		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assert_hidden_surface( (string) apply_filters( 'the_content', $content ), 'ActivityPub-style proxy' );
		$this->assert_hidden_surface( get_the_excerpt( $id ), 'get_the_excerpt' );
		$this->assert_hidden_surface( wp_trim_excerpt( '', get_post( $id ) ), 'wp_trim_excerpt' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assert_hidden_surface( (string) ( $response->get_data()['content']['rendered'] ?? '' ), 'REST content.rendered' );
	}

	/** A private generated check-in title prints the generic date name only. */
	public function test_checkin_reference_uses_the_safe_title(): void {
		$venue = 'Hidden venue issue 240';
		$checkin = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Checked in at ' . $venue,
				'post_date'   => '2026-09-12 14:30:00',
			]
		);
		wp_set_object_terms( $checkin, 'checkin', Taxonomy::TAXONOMY );
		update_post_meta( $checkin, Meta_Fields::PREFIX . 'checkin_name', $venue );
		update_post_meta( $checkin, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		Title_Privacy::mark_location_title( $checkin );
		$collection = $this->collection( $this->item( [ 'postId' => $checkin ] ) );

		$html = do_blocks( (string) get_post_field( 'post_content', $collection ) );
		$this->assertStringContainsString( 'Check-in, September 12, 2026', $html );
		$this->assertStringNotContainsString( $venue, $html );
	}

	/** Referenced acquisition costs never cross any collection output. */
	public function test_referenced_costs_never_cross_collection_surfaces(): void {
		$private = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Brass desk lamp' ] );
		$public  = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Field recorder' ] );
		update_post_meta( $private, '_pkiw_acquisition_price', '84.00' );
		update_post_meta( $public, '_pkiw_acquisition_price', '219.00' );
		update_post_meta( $public, Meta_Fields::COST_PUBLIC_KEY, '1' );
		$id = $this->collection( $this->item( [ 'postId' => $private ] ) . $this->item( [ 'postId' => $public ] ) );

		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$outputs = [
			do_blocks( (string) get_post_field( 'post_content', $id ) ),
			\PKIW\render_stream_card(),
			wp_json_encode( Collection_Items::facts( $id ) ),
		];
		if ( function_exists( 'wp_get_ability' ) && wp_get_ability( 'post-kinds/get-collection-items' ) ) {
			$outputs[] = wp_json_encode( wp_get_ability( 'post-kinds/get-collection-items' )->execute( [ 'post_id' => $id ] ) );
		}
		foreach ( $outputs as $output ) {
			$this->assertStringNotContainsString( '84.00', (string) $output );
			$this->assertStringNotContainsString( '219.00', (string) $output );
		}
	}

	/** REST and editor-side rendering cannot bypass reference or collection privacy. */
	public function test_password_collection_and_editor_render_match_public_privacy(): void {
		$reference = self::factory()->post->create( [ 'post_status' => 'draft', 'post_title' => 'Editor-only hidden title 240' ] );
		$public     = $this->collection( $this->cabinet( $this->item( [ 'postId' => $reference ] ) ) );
		$password   = $this->collection( $this->item( [ 'title' => 'Protected collection item 240' ] ), [ 'post_password' => 'secret' ] );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $password ) );
		$this->assertStringNotContainsString( 'pkiw-collection-item', (string) ( $response->get_data()['content']['rendered'] ?? '' ) );

		wp_set_current_user( 0 );
		$visitor = do_blocks( (string) get_post_field( 'post_content', $public ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$editor = do_blocks( (string) get_post_field( 'post_content', $public ) );
		$this->assertSame( $visitor, $editor );
		$this->assertStringNotContainsString( 'Editor-only hidden title 240', $editor );
	}
}
