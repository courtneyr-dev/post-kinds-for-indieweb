<?php
/**
 * Singular issue facts list and content wrapper.
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
final class IssueContentFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Issue_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( [ 'atproto', 'markdown_request', 'format' ] as $var ) {
			set_query_var( $var, null );
		}
		$this->go_to( home_url( '/' ) );
		wp_reset_query();
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $meta Meta values by suffix.
	 * @param array<string, mixed> $args Post overrides.
	 */
	private function issue( array $meta = [], array $args = [], string $kind = 'issue' ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Export drops the last row',
					'post_content' => '<p>Ticket body.</p>',
					'post_date'    => '2026-07-24 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}

		return (int) $post_id;
	}

	private function singular( int $post_id, ?string $content = null ): string {
		$this->go_to( (string) get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );

		return apply_filters( 'the_content', null === $content ? (string) get_post_field( 'post_content', $post_id ) : $content );
	}

	private function full_issue(): int {
		return $this->issue(
			[
				'cite_url'     => 'https://a.example.net/acorn-ledger',
				'cite_name'    => 'acorn-ledger',
				'issue_number' => '101',
				'issue_status' => 'open',
			]
		);
	}

	/**
	 * The fact labels in document order, read from the parsed list rather than the raw string.
	 *
	 * @return string[]
	 */
	private function row_labels( string $html ): array {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$labels = [];
		$xpath  = new DOMXPath( $dom );
		foreach ( $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " pkiw-issue-facts ")]/dl/div/dt' ) as $dt ) {
			$labels[] = trim( $dt->textContent );
		}

		return $labels;
	}

	public function test_rows_are_repository_issue_status_date_then_the_wrapped_body(): void {
		$html = $this->singular( $this->full_issue() );

		$this->assertSame( [ 'Repository', 'Issue', 'Status', 'Date' ], $this->row_labels( $html ) );
		$this->assertStringNotContainsString( '>Updated<', $html );
		$this->assertStringNotContainsString( '>Source<', $html );
		$this->assertStringContainsString( '<div class="pkiw-issue-body e-content"><p>Ticket body.</p>', $html );
		$this->assertStringContainsString( 'class="h-cite u-in-reply-to"', $html );
		$this->assertStringContainsString( 'class="u-url p-name"', $html );
	}

	public function test_number_only_prints_issue_and_date_rows(): void {
		$html = $this->singular( $this->issue( [ 'issue_number' => '7' ] ) );

		$this->assertStringContainsString( '<dt>Issue</dt>', $html );
		$this->assertStringContainsString( '<dt>Date</dt>', $html );
		$this->assertStringNotContainsString( '<dt>Repository</dt>', $html );
		$this->assertStringNotContainsString( '<dt>Status</dt>', $html );
	}

	public function test_missing_status_and_repository_omit_their_rows(): void {
		$without_status = $this->singular( $this->issue( [ 'cite_name' => 'acorn-ledger' ] ) );
		$this->assertStringNotContainsString( '<dt>Status</dt>', $without_status );

		$without_repo = $this->singular( $this->issue( [ 'issue_status' => 'closed' ] ) );
		$this->assertStringNotContainsString( '<dt>Repository</dt>', $without_repo );
	}

	public function test_repository_name_without_url_is_plain_text_without_microformats_classes(): void {
		$html = $this->singular( $this->issue( [ 'cite_name' => '<b>acorn-ledger</b>' ] ) );

		$this->assertStringContainsString( '<span class="pkiw-issue-facts__repo">acorn-ledger</span>', $html );
		$this->assertStringNotContainsString( 'h-cite u-in-reply-to', $html );
	}

	public function test_posts_without_structured_facts_and_nonissues_are_not_decorated(): void {
		$ids = [
			$this->issue(),
			$this->issue( [], [ 'post_content' => '<p>Issue for <a class="u-in-reply-to" href="https://a.example.net/acorn-ledger">repo</a>.</p>' ] ),
			$this->issue( [ 'issue_number' => '110' ], [ 'post_password' => 'ticket-secret' ] ),
			$this->issue( [ 'issue_number' => '101' ], [], 'note' ),
		];
		foreach ( $ids as $id ) {
			$html = $this->singular( $id );
			$this->assertStringNotContainsString( 'pkiw-issue-facts', $html );
			$this->assertStringNotContainsString( 'pkiw-issue-body', $html );
		}
	}

	public function test_feed_atproto_and_markdown_contexts_leave_content_unchanged(): void {
		$post_id = $this->full_issue();
		$content = '<p>Projection body.</p>';
		$this->go_to( (string) get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$GLOBALS['wp_query']->is_feed = true;
		$this->assertSame( $content, Issue_Archive::decorate_content( $content ) );
		$GLOBALS['wp_query']->is_feed = false;
		foreach ( [ 'atproto' => 1, 'markdown_request' => '1', 'format' => 'markdown' ] as $var => $value ) {
			set_query_var( $var, $value );
			$this->assertSame( $content, Issue_Archive::decorate_content( $content ), $var );
			set_query_var( $var, null );
		}
	}

	public function test_rest_excerpt_and_head_contexts_leave_content_unchanged(): void {
		$post_id = $this->full_issue();
		$content = '<p>Context body.</p>';
		$this->go_to( (string) get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$seen = '';
		$probe = static function ( string $excerpt ) use ( &$seen, $content ): string {
			$seen = Issue_Archive::decorate_content( $content );
			return $excerpt;
		};
		add_filter( 'get_the_excerpt', $probe );
		apply_filters( 'get_the_excerpt', 'excerpt', get_post( $post_id ) );
		remove_filter( 'get_the_excerpt', $probe );
		$this->assertSame( $content, $seen );

		$seen = '';
		$head = static function () use ( &$seen, $content ): void {
			$seen = Issue_Archive::decorate_content( $content );
		};
		remove_all_actions( 'wp_head' );
		add_action( 'wp_head', $head );
		do_action( 'wp_head' );
		$this->assertSame( $content, $seen );
	}

	public function test_a_request_without_a_singular_main_query_leaves_content_unchanged(): void {
		// A REST request has no singular main query, so is_singular() keeps the list out. The wp_is_serving_rest_request() guard Question_Answers carries is therefore unreachable and this class does not repeat it.
		$post_id = $this->full_issue();
		$content = '<p>Rest body.</p>';
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertFalse( is_singular( 'post' ) );
		$this->assertSame( $content, Issue_Archive::decorate_content( $content ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$this->assertStringNotContainsString( 'pkiw-issue-facts', (string) $response->get_data()['content']['rendered'] );
	}

	public function test_decoration_is_idempotent_and_does_not_nest_an_existing_e_content(): void {
		$post_id = $this->full_issue();
		$first   = $this->singular( $post_id );
		$second  = apply_filters( 'the_content', $first );
		$this->assertSame( 1, substr_count( $second, 'class="pkiw-issue-facts"' ) );

		$authored = '<div class="e-content"><p>Own body.</p></div>';
		$html     = $this->singular( $post_id, $authored );
		$this->assertSame( 1, substr_count( $html, 'class="e-content"' ) );
		$this->assertStringNotContainsString( 'pkiw-issue-body', $html );
	}
}
