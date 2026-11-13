<?php
/**
 * Tag privacy-surface coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Public_Eligibility;
use PKIW\Tag_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class TagPrivacyTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	private function hidden( string $status = 'publish', string $password = 'secret' ): int {
		$id = self::factory()->post->create( [ 'post_status' => $status, 'post_password' => $password, 'post_title' => 'Tg261 Sentinel' ] );
		wp_set_object_terms( $id, 'tag', Taxonomy::TAXONOMY );
		return (int) $id;
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

	public function test_pretty_query_and_feed_forms_are_refused_without_a_redirect(): void {
		$id = $this->hidden( 'private', '' );
		foreach ( [ '?p=' . $id, '?p=' . $id . '&feed=rss2', '?name=' . get_post_field( 'post_name', $id ) . '&feed=atom' ] as $path ) {
			$this->go_to( home_url( '/' . $path ) );
			set_query_var( 'p', $id );
			Public_Eligibility::refuse_hidden_request();
			$this->assertTrue( is_404(), $path );
			$this->assertFalse( Public_Eligibility::filter_redirect_canonical( get_permalink( $id ), home_url( '/' . $path ) ) );
			$this->assertSame( 0, Public_Eligibility::filter_old_slug_redirect( $id ) );
		}
	}

	public function test_singular_comment_feed_is_changed_to_a_plain_404(): void {
		$id = $this->hidden();
		self::factory()->comment->create( [ 'comment_post_ID' => $id, 'comment_approved' => 1, 'comment_content' => 'Sentinel comment' ] );
		$this->go_to( get_permalink( $id ) . 'feed/' );
		set_query_var( 'p', $id );
		$GLOBALS['wp_query']->is_feed         = true;
		$GLOBALS['wp_query']->is_comment_feed = true;
		Public_Eligibility::refuse_hidden_request();
		$this->assertTrue( is_404() );
		$this->assertFalse( is_feed() );
		$this->assertFalse( is_comment_feed() );
	}
}
