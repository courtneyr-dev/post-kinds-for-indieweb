<?php
/** Follow hidden-mf2 coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Microformats;
use PKIW\Micropub_Content_Builder;
use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowMicroformatsTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_single_adds_one_follow_h_card_and_respects_existing_card(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Omar' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://omar.example/' );
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Singular fixture.
		setup_postdata( $GLOBALS['post'] );
		$mf2  = new Microformats();
		$html = $mf2->add_hidden_mf2_data( '<p>Body</p>' );
		$this->assertSame( 1, substr_count( $html, 'u-follow-of h-card' ) );
		$existing = '<span class="u-follow-of h-card"><data class="u-url" value="https://omar.example/"></data></span>';
		$this->assertSame( $existing, $mf2->add_hidden_mf2_data( $existing ), 'Removing the existing-card check would duplicate follow-of.' );
	}

	public function test_generated_paragraph_decodes_entities_and_single_keeps_one_follow_property(): void {
		$url   = 'https://x.example/?a=1&b=2';
		$exact = '<!-- wp:paragraph -->' . "\n"
			. '<p>Followed <a class="u-follow-of" href="https://x.example/?a=1&#038;b=2">https://x.example/?a=1&#038;b=2</a></p>' . "\n"
			. '<!-- /wp:paragraph -->';
		$block = parse_blocks( $exact )[0];
		$this->assertTrue( Follow_Archive::is_generated_paragraph( $block ), 'The exact generated paragraph must match after entity decoding.' );

		$extra_word              = $block;
		$extra_word['innerHTML'] = str_replace( '</p>', ' extra</p>', (string) $block['innerHTML'] );
		$this->assertFalse( Follow_Archive::is_generated_paragraph( $extra_word ), 'An extra word must keep a hand-written paragraph classified.' );

		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => 'placeholder' ] );
		Micropub_Content_Builder::apply( [ 'h' => 'entry', 'follow-of' => [ $url ] ], [ 'ID' => $id ] );
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Singular fixture.
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		$this->assertSame( 1, substr_count( $html, 'u-follow-of' ), 'A follow single must expose exactly one follow-of after block rendering.' );
	}

	public function test_rendered_single_parses_one_follow_h_card_with_public_photo(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>Followed <a class="u-follow-of" href="https://parsed.example/">https://parsed.example/</a></p><!-- /wp:paragraph -->' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Parsed Person' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://parsed.example/' );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		set_post_thumbnail( $id, $image );
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Singular fixture.
		setup_postdata( $GLOBALS['post'] );
		$html    = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		$entries = array_values( array_filter( \Mf2\parse( $html, get_permalink( $id ) )['items'] ?? [], static fn( array $item ): bool => in_array( 'h-entry', $item['type'] ?? [], true ) ) );
		$this->assertCount( 1, $entries );
		$follow_of = $entries[0]['properties']['follow-of'] ?? [];
		$this->assertCount( 1, $follow_of );
		$this->assertIsArray( $follow_of[0] );
		$this->assertContains( 'h-card', $follow_of[0]['type'] );
		$this->assertSame( 'Parsed Person', $follow_of[0]['properties']['name'][0] );
		$this->assertSame( 'https://parsed.example/', $follow_of[0]['properties']['url'][0] );
		$this->assertNotEmpty( $follow_of[0]['properties']['photo'][0] ?? '' );

		$this->go_to( home_url( '/' ) );
		$archive = do_blocks( (string) get_post_field( 'post_content', $id ) );
		$this->assertStringContainsString( 'class="u-follow-of"', $archive, 'Non-singular rendering keeps the generated paragraph property.' );
	}

	public function test_stream_follow_of_parses_as_only_an_h_card(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Stream follow' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Stream Person' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://stream-person.example/' );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stream fixture.
		setup_postdata( $GLOBALS['post'] );
		$items     = \Mf2\parse( \PKIW\render_stream_card(), get_permalink( $id ) )['items'] ?? [];
		$follow_of = $items[0]['properties']['follow-of'][0] ?? null;
		$this->assertIsArray( $follow_of );
		$this->assertSame( [ 'h-card' ], $follow_of['type'], 'The Stream follow-of must not inherit hidden_cite_html()\'s h-cite type.' );
		wp_reset_postdata();
	}

	public function test_filter_callbacks_return_unexpected_values_unchanged(): void {
		$this->assertNull( Follow_Archive::unclass_generated_paragraph( null, [] ), 'A third-party null paragraph value must pass through.' );
		$this->assertSame( 'incoming', Follow_Archive::embeddable( 'incoming', null ), 'A non-post oEmbed callback value must pass through.' );
	}
}
