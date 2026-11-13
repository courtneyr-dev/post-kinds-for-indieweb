<?php
/**
 * Micropub tag-card coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Micropub_Content_Builder;

/** @group integration */
final class MicropubTagTest extends WP_UnitTestCase {

	private function card( array $properties ): string {
		$method = new ReflectionMethod( Micropub_Content_Builder::class, 'tag_card' );
		$method->setAccessible( true );
		return (string) $method->invoke( null, $properties );
	}

	public function test_first_tag_of_wins_and_non_url_categories_keep_order(): void {
		$html = $this->card( [ 'tag-of' => [ 'https://first.example/', 'https://second.example/' ], 'category' => [ 'One', 'https://category.example/', 'Two' ] ] );
		$this->assertStringContainsString( 'https://first.example/', $html );
		$this->assertStringNotContainsString( 'second.example', $html );
		$this->assertStringContainsString( '"labels":["One","Two"]', $html );
	}

	public function test_h_card_and_plain_text_build_the_expected_target_types(): void {
		$person = $this->card( [ 'tag-of' => [ [ 'type' => [ 'h-card' ], 'properties' => [ 'name' => [ 'Avery' ], 'url' => [ 'https://person.example/' ], 'photo' => [ 'https://remote.example/photo.jpg' ] ] ] ] ] );
		$this->assertStringContainsString( '"targetType":"person"', $person );
		$this->assertStringNotContainsString( 'remote.example', $person );
		$this->assertSame( '', $this->card( [ 'tag-of' => [ [ 'type' => [ 'h-card' ], 'properties' => [] ] ] ] ) );
		$this->assertStringContainsString( '"title":"Local label"', $this->card( [ 'tag-of' => [ 'Local label' ] ] ) );
	}

	public function test_remote_mf2_photos_are_removed_but_upload_photos_survive(): void {
		$id     = self::factory()->post->create();
		$upload = trailingslashit( (string) wp_get_upload_dir()['baseurl'] ) . '2026/11/local.jpg';
		$value  = [ [ 'type' => [ 'h-card' ], 'properties' => [ 'photo' => [ 'https://remote.example/a.jpg', [ 'value' => 'https://remote.example/b.jpg', 'alt' => 'B' ], $upload ] ] ] ];
		update_post_meta( $id, 'mf2_tag-of', $value );
		Micropub_Content_Builder::strip_remote_photos( $id, 'tag-of' );
		$stored = get_post_meta( $id, 'mf2_tag-of', true );
		$this->assertSame( [ $upload ], $stored[0]['properties']['photo'] );
	}
}
