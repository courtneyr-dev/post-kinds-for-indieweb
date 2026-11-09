<?php
/** Presentation record block. */

declare(strict_types=1);

use PKIW\Presentation;

final class PresentationRecordTest extends WP_UnitTestCase {

	public function test_record_markup_omits_empty_rows_and_extends_link_names(): void {
		$html = Presentation::record_html(
			[ 'title' => 'Accessible blocks', 'event_name' => 'WordCamp', 'event_url' => 'https://example.com/event', 'event_type' => 'talk', 'event_type_label' => 'Talk', 'slides_url' => 'https://example.com/slides', 'recording_url' => '', 'date_iso' => '2026-01-02T10:00:00+00:00', 'date_display' => 'January 2, 2026' ],
			[ 'title_level' => 1, 'show_date' => true, 'class' => 'custom-record' ]
		);
		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringContainsString( '<dl', $html );
		$this->assertStringContainsString( 'Slides<span class="screen-reader-text"> for Accessible blocks</span>', $html );
		$this->assertStringNotContainsString( '>Recording<', $html );
		$this->assertStringContainsString( 'aria-labelledby="pkiw-presentation-record-title"', $html );

		$empty = Presentation::record_html( [ 'title' => 'No details', 'event_name' => '', 'event_url' => '', 'event_type' => '', 'event_type_label' => '', 'slides_url' => '', 'recording_url' => '', 'date_iso' => '', 'date_display' => '' ] );
		$this->assertStringNotContainsString( '<h', $empty );
		$this->assertStringNotContainsString( '<dl', $empty );
		$this->assertStringNotContainsString( '__links', $empty );
		$this->assertStringNotContainsString( 'aria-labelledby', $empty );
	}

	public function test_empty_facts_return_empty_and_single_gets_one_block_comment(): void {
		$this->assertSame( '', Presentation::record_html( [] ) );
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<p>Body</p>' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		$content = Presentation::prepend_record( '<p>Body</p>' );
		$this->assertSame( '<!-- wp:post-kinds-indieweb/presentation-record /-->' . "\n\n<p>Body</p>", $content );
		$this->assertSame( $content, Presentation::prepend_record( $content ) );
	}

	public function test_prepend_record_obeys_every_alternate_surface_guard(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => 'Body' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		foreach ( [ 'atproto' => '1', 'markdown_request' => '1', 'format' => 'markdown' ] as $key => $value ) {
			set_query_var( $key, $value );
			$this->assertSame( 'Body', Presentation::prepend_record( 'Body' ), $key );
			set_query_var( $key, '' );
		}
		$this->go_to( get_post_comments_feed_link( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		$this->assertTrue( is_feed() );
		$this->assertSame( 'Body', Presentation::prepend_record( 'Body' ) );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );

		$inside_excerpt = '';
		$probe          = static function ( string $value ) use ( &$inside_excerpt ): string {
			$inside_excerpt = Presentation::prepend_record( 'Body' );
			return $value;
		};
		add_filter( 'get_the_excerpt', $probe );
		apply_filters( 'get_the_excerpt', '' );
		remove_filter( 'get_the_excerpt', $probe );
		$this->assertSame( 'Body', $inside_excerpt );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_head';
		$this->assertSame( 'Body', Presentation::prepend_record( 'Body' ) );
		array_pop( $wp_current_filter );

		wp_set_object_terms( $post_id, 'note', 'kind' );
		$this->assertSame( 'Body', Presentation::prepend_record( 'Body' ) );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		wp_update_post( [ 'ID' => $post_id, 'post_password' => 'secret' ] );
		$GLOBALS['post'] = get_post( $post_id );
		$this->assertSame( 'Body', Presentation::prepend_record( 'Body' ) );
	}

	public function test_full_content_pipeline_renders_the_record_once_before_wpautop(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'Conference' );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		$html = apply_filters( 'the_content', (string) get_post_field( 'post_content', $post_id ) );
		$this->assertSame( 1, substr_count( $html, 'class="pkiw-presentation-record"' ) );
		$record = strstr( $html, '<section class="pkiw-presentation-record"' );
		$this->assertIsString( $record );
		$record = substr( $record, 0, strpos( $record, '</section>' ) + strlen( '</section>' ) );
		$this->assertStringNotContainsString( '<p><section', $record );
		$this->assertStringNotContainsString( '<br', $record );
	}

	public function test_full_content_pipeline_preserves_classic_paragraphs_and_block_content(): void {
		$classic_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => "Line one\n\nLine two" ] );
		wp_set_object_terms( $classic_id, 'presentation', 'kind' );
		update_post_meta( $classic_id, '_pkiw_presentation_event_name', 'Classic conference' );
		$this->go_to( get_permalink( $classic_id ) );
		$GLOBALS['post'] = get_post( $classic_id );
		$classic = apply_filters( 'the_content', (string) get_post_field( 'post_content', $classic_id ) );
		$this->assertSame( 1, substr_count( $classic, 'class="pkiw-presentation-record"' ) );
		$this->assertStringContainsString( "<p>Line one</p>\n<p>Line two</p>", $classic );

		$block_content = '<!-- wp:paragraph --><p>Block body</p><!-- /wp:paragraph -->';
		$block_id      = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $block_content ] );
		wp_set_object_terms( $block_id, 'presentation', 'kind' );
		update_post_meta( $block_id, '_pkiw_presentation_event_name', 'Block conference' );
		$this->go_to( get_permalink( $block_id ) );
		$GLOBALS['post'] = get_post( $block_id );
		$blocks = apply_filters( 'the_content', $block_content );
		$this->assertSame( 1, substr_count( $blocks, 'class="pkiw-presentation-record"' ) );
		$this->assertMatchesRegularExpression( '#<p(?: [^>]*)?>Block body</p>#', $blocks );
		$this->assertStringNotContainsString( '<p><section', $blocks );
	}

	public function test_record_block_default_class_does_not_come_from_class_name(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Class contract' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'Conference' );
		$seen = null;
		$probe = static function ( array $args ) use ( &$seen ): array {
			$seen = $args;
			return $args;
		};
		add_filter( 'pkiw_presentation_record_args', $probe );
		try {
			$block = new WP_Block( [ 'blockName' => Presentation::RECORD_BLOCK, 'attrs' => [ 'className' => 'from-block-attribute' ] ], [ 'postId' => $post_id, 'postType' => 'post' ] );
			Presentation::render_record_block( [ 'className' => 'from-block-attribute' ], '', $block );
		} finally {
			remove_filter( 'pkiw_presentation_record_args', $probe );
		}
		$this->assertIsArray( $seen );
		$this->assertSame( '', $seen['class'] );
	}
}
