<?php
/**
 * Issue single and archive microformats.
 *
 * @package PKIW
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/fixtures/issue-render-state-reset.php';

use PKIW\Issue_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class IssueMicroformatsTest extends WP_UnitTestCase {

	use Issue_Render_State_Reset;

	private string $original_stylesheet = '';
	private int $author_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Issue_Archive() )->register();
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'Site Author' ] );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		$this->reset_render_state();
		wp_reset_postdata();
		$this->set_permalink_structure( '' );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/** @param array<string, string> $meta */
	private function issue( string $title, array $meta ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '<!-- wp:paragraph --><p>Ticket body.</p><!-- /wp:paragraph -->',
				'post_date'    => '2026-07-24 10:00:00',
				'post_author'  => $this->author_id,
			]
		);
		wp_set_object_terms( $post_id, 'issue', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}
		return (int) $post_id;
	}

	private function render_single( int $post_id ): string {
		$this->go_to( (string) get_permalink( $post_id ) );
		$template = resolve_block_template( 'single', [ 'single-post.php', 'single.php', 'singular.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/** @return array<string, mixed> */
	private function entry( string $html ): array {
		$entries = array_values(
			array_filter(
				\Mf2\parse( $html, 'http://example.org/' )['items'] ?? [],
				static fn( array $item ): bool => in_array( 'h-entry', $item['type'] ?? [], true )
			)
		);
		$this->assertCount( 1, $entries );

		return $entries[0];
	}

	public function test_single_is_one_entry_with_repository_cite_content_date_and_author(): void {
		$post_id = $this->issue(
			'Export drops the last row',
			[
				'cite_url'     => 'https://a.example.net/acorn-ledger',
				'cite_name'    => 'acorn-ledger',
				'issue_number' => '101',
				'issue_status' => 'open',
			]
		);
		$html  = $this->render_single( $post_id );
		$entry = $this->entry( $html );

		$this->assertSame( [ 'Export drops the last row' ], $entry['properties']['name'] ?? [] );
		$this->assertNotEmpty( $entry['properties']['content'] ?? [] );
		$this->assertSame( 'Site Author', $entry['properties']['author'][0]['properties']['name'][0] ?? null );
		$this->assertCount( 1, $entry['properties']['in-reply-to'] ?? [] );
		$cite = $entry['properties']['in-reply-to'][0];
		$this->assertContains( 'h-cite', $cite['type'] ?? [] );
		$this->assertSame( [ 'https://a.example.net/acorn-ledger' ], $cite['properties']['url'] ?? [] );
		$this->assertSame( [ 'acorn-ledger' ], $cite['properties']['name'] ?? [] );
		$expected = strtotime( (string) get_post_field( 'post_date_gmt', $post_id ) . ' UTC' );
		foreach ( $entry['properties']['published'] ?? [] as $published ) {
			$this->assertSame( $expected, strtotime( (string) $published ) );
		}
		foreach ( [ 'h-adr', 'p-location', 'p-geo' ] as $private ) {
			$this->assertStringNotContainsString( $private, $html );
		}
	}

	public function test_archive_stream_entries_cite_repositories_only_when_stored(): void {
		$with = $this->issue( 'R1 ticket', [ 'cite_url' => 'https://a.example.net/acorn-ledger', 'cite_name' => 'acorn-ledger' ] );
		$none = $this->issue( 'No repository ticket', [] );
		$this->go_to( (string) get_term_link( 'issue', Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-issue.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;
		$html                         = get_the_block_template_html();

		$entries = [];
		foreach ( \Mf2\parse( $html, 'http://example.org/' )['items'] ?? [] as $item ) {
			if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
				$entries[ (string) ( $item['properties']['url'][0] ?? '' ) ] = $item;
			}
		}
		$this->assertCount( 1, $entries[ (string) get_permalink( $with ) ]['properties']['in-reply-to'] ?? [] );
		$this->assertArrayNotHasKey( 'in-reply-to', $entries[ (string) get_permalink( $none ) ]['properties'] );
	}
}
