<?php
/** Follow Micropub coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Micropub_Content_Builder;
use PKIW\Taxonomy;

/** @group integration */
final class FollowMicropubTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_h_card_builds_paragraph_and_cite_without_remote_photo(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => 'placeholder' ] );
		Micropub_Content_Builder::apply(
			[ 'h' => 'entry', 'follow-of' => [ [ 'type' => [ 'h-card' ], 'properties' => [ 'name' => [ 'Nia' ], 'url' => [ 'https://nia.example/' ], 'photo' => [ 'https://remote.example/p.png' ] ] ] ] ],
			[ 'ID' => $id ]
		);
		$this->assertTrue( has_term( 'follow', Taxonomy::TAXONOMY, $id ) );
		$this->assertStringContainsString( 'u-follow-of', (string) get_post_field( 'post_content', $id ) );
		$this->assertSame( 'https://nia.example/', get_post_meta( $id, '_pkiw_cite_url', true ) );
		$this->assertSame( 'Nia', get_post_meta( $id, '_pkiw_cite_name', true ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_cite_photo' ), 'Reading the h-card photo would reintroduce a remote image row.' );
	}

	public function test_scalar_target_builds_paragraph_url_and_host(): void {
		$id = $this->post();
		Micropub_Content_Builder::apply( [ 'h' => 'entry', 'follow-of' => [ 'https://scalar.example/path' ] ], [ 'ID' => $id ] );
		$this->assertStringContainsString( '<a class="u-follow-of" href="https://scalar.example/path">https://scalar.example/path</a>', (string) get_post_field( 'post_content', $id ) );
		$this->assertSame( 'https://scalar.example/path', get_post_meta( $id, '_pkiw_cite_url', true ) );
		$this->assertSame( 'scalar.example', get_post_meta( $id, '_pkiw_cite_host', true ) );
	}

	public function test_h_card_never_fetches_and_preserves_existing_url(): void {
		$id = $this->post();
		update_post_meta( $id, '_pkiw_cite_url', 'https://bridge.example/' );
		$requests = 0;
		$guard    = static function () use ( &$requests ) {
			++$requests;
			return new WP_Error( 'blocked', 'No network in follow Micropub tests.' );
		};
		add_filter( 'pre_http_request', $guard );
		try {
			Micropub_Content_Builder::apply( [ 'h' => 'entry', 'follow-of' => [ [ 'type' => [ 'h-card' ], 'properties' => [ 'name' => [ 'https://nia.example/' ], 'url' => [ 'https://nia.example/' ], 'photo' => [ 'https://remote.example/p.png' ] ] ] ] ], [ 'ID' => $id ] );
		} finally {
			remove_filter( 'pre_http_request', $guard );
		}
		$this->assertSame( 0, $requests );
		$this->assertSame( 'https://bridge.example/', get_post_meta( $id, '_pkiw_cite_url', true ), 'A bridge value written before priority 20 must survive.' );
		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_cite_name' ), 'A URL-like h-card name must not be stored.' );
		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_cite_photo' ) );
	}

	public function test_second_apply_is_idempotent_and_h_card_without_url_writes_nothing(): void {
		$id    = $this->post();
		$input = [ 'h' => 'entry', 'follow-of' => [ 'https://first.example/' ] ];
		Micropub_Content_Builder::apply( $input, [ 'ID' => $id ] );
		$content = get_post_field( 'post_content', $id );
		Micropub_Content_Builder::apply( [ 'h' => 'entry', 'follow-of' => [ 'https://second.example/' ] ], [ 'ID' => $id ] );
		$this->assertSame( $content, get_post_field( 'post_content', $id ) );
		$this->assertSame( 'https://first.example/', get_post_meta( $id, '_pkiw_cite_url', true ) );

		$empty = $this->post();
		Micropub_Content_Builder::apply( [ 'h' => 'entry', 'follow-of' => [ [ 'type' => [ 'h-card' ], 'properties' => [ 'name' => [ 'No URL' ] ] ] ] ], [ 'ID' => $empty ] );
		$this->assertSame( 'placeholder', get_post_field( 'post_content', $empty ) );
		$this->assertFalse( metadata_exists( 'post', $empty, '_pkiw_cite_url' ) );
		$this->assertFalse( metadata_exists( 'post', $empty, '_pkiw_cite_name' ) );
	}

	public function test_retained_mf2_row_loses_only_remote_photo(): void {
		$id    = $this->post();
		$value = [ [ 'type' => [ 'h-card' ], 'properties' => [ 'name' => [ 'Retained' ], 'url' => [ 'https://retained.example/' ], 'photo' => [ 'https://remote.example/p.jpg' ] ] ] ];
		update_post_meta( $id, 'mf2_follow-of', $value );
		Micropub_Content_Builder::apply( [ 'h' => 'entry', 'follow-of' => $value ], [ 'ID' => $id ] );
		$stored = get_post_meta( $id, 'mf2_follow-of', true );
		$this->assertSame( [ 'Retained' ], $stored[0]['properties']['name'] );
		$this->assertSame( [ 'https://retained.example/' ], $stored[0]['properties']['url'] );
		$this->assertArrayNotHasKey( 'photo', $stored[0]['properties'] );
	}

	private function post(): int {
		return (int) self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => 'placeholder' ] );
	}
}
