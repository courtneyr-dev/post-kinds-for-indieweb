<?php
/**
 * Issue citations in generic Stream cards.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Issue_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class IssueStreamCiteTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Issue_Archive() )->register();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	public function tear_down(): void {
		wp_reset_postdata();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** @param array<string, string> $meta */
	private function issue( array $meta = [], array $args = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[ 'post_status' => 'publish', 'post_title' => 'Export drops the last row', 'post_content' => '<p>Body.</p>' ],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'issue', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}
		return (int) $post_id;
	}

	private function stream( int $post_id ): string {
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$html = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /-->' );
		wp_reset_postdata();

		return $html;
	}

	public function test_issue_stream_card_contains_the_hidden_repository_cite(): void {
		$post_id = $this->issue( [ 'cite_url' => 'https://a.example.net/acorn-ledger', 'cite_name' => 'acorn-ledger' ] );
		$html    = $this->stream( $post_id );

		$this->assertStringContainsString( 'pk-kind-cite h-cite u-in-reply-to', $html );
		$this->assertStringContainsString( 'class="u-url" value="https://a.example.net/acorn-ledger"', $html );
		$this->assertStringContainsString( 'class="p-name" value="acorn-ledger"', $html );
	}

	public function test_issue_without_repository_prints_no_hidden_cite(): void {
		$this->assertStringNotContainsString( 'pk-kind-cite', $this->stream( $this->issue() ) );
	}

	public function test_stream_cite_map_has_the_issue_callback(): void {
		$map = \PKIW\stream_card_kind_cite_map();

		$this->assertArrayHasKey( 'issue', $map );
		$this->assertSame( [ Issue_Archive::class, 'cite' ], $map['issue'] );
	}

	public function test_protected_issue_is_title_only_with_no_citation(): void {
		$post_id = $this->issue(
			[ 'cite_url' => 'https://a.example.net/acorn-ledger', 'cite_name' => 'acorn-ledger' ],
			[ 'post_password' => 'ticket-secret' ]
		);
		$html = $this->stream( $post_id );

		$this->assertStringContainsString( 'pk-card--protected', $html );
		$this->assertStringContainsString( 'Protected: Export drops the last row', $html );
		$this->assertStringNotContainsString( 'pk-kind-cite', $html );
		$this->assertStringNotContainsString( 'acorn-ledger', $html );
	}
}
