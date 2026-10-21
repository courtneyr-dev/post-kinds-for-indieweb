<?php
/**
 * Review fixes for the quote archive.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Quote_Archive;
use PKIW\Taxonomy;

/**
 * Review fixes for the quote archive: digit initials, authorless works, entity decoding, ability permission and a non-public leak sweep.
 *
 * @package PKIW
 * @group integration
 */
final class QuoteReviewFixesTest extends WP_UnitTestCase {

	private string $orig = '';

	public function set_up(): void {
		parent::set_up();
		$this->orig = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Quote_Archive() )->register();
		delete_transient( Quote_Archive::INDEX_TRANSIENT );
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		switch_theme( $this->orig );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function quote( string $key, string $author, string $work, array $args = [], string $inner = '' ): int {
		$inner = '' !== $inner ? $inner : '<p>Words of ' . $key . '.</p>';
		$id    = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Quote ' . $key,
					'post_date'    => '2026-08-10 10:00:00',
					'post_content' => '<!-- wp:quote --><blockquote class="wp-block-quote">' . $inner . '</blockquote><!-- /wp:quote -->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );
		if ( '' !== $author ) {
			add_post_meta( $id, '_pkiw_cite_author', $author );
		}
		if ( '' !== $work ) {
			add_post_meta( $id, '_pkiw_cite_name', $work );
		}
		return (int) $id;
	}

	private function url( array $args = [] ): string {
		return add_query_arg( array_map( 'rawurlencode', $args ), (string) get_term_link( 'quote', Taxonomy::TAXONOMY ) );
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-quote.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = (string) $template->content;
		return get_the_block_template_html();
	}

	/** Leak sweep: every non-public state, served logged out, every filter. */
	public function test_no_leak_of_nonpublic_text_author_or_work(): void {
		$this->quote( 'PUB', 'Pat Public', 'Public Work' );
		$bad = [
			'sp'  => [ 'post_password' => '   ' ],
			'pw'  => [ 'post_password' => 'secret' ],
			'prv' => [ 'post_status' => 'private' ],
			'drf' => [ 'post_status' => 'draft' ],
			'fut' => [ 'post_status' => 'future', 'post_date' => '2030-01-01 10:00:00' ],
			'pen' => [ 'post_status' => 'pending' ],
		];
		$ids = [];
		foreach ( $bad as $k => $args ) {
			$ids[ $k ] = $this->quote( 'X' . $k, 'Zed' . $k . ' Author', 'Zwork' . $k, $args, '<p>SecretWords' . $k . '</p>' );
		}
		$trash = $this->quote( 'TRASH', 'Zedtrash Author', 'Zworktrash', [], '<p>SecretWordstrash</p>' );
		wp_trash_post( $trash );

		$urls = [
			$this->url(),
			$this->url( [ 'pkiw_quote_initial' => 'Z' ] ),
			$this->url( [ 'pkiw_quote_initial' => 'z' ] ),
			$this->url( [ 'pkiw_quote_author' => 'Zedsp Author' ] ),
			$this->url( [ 'pkiw_quote_author' => 'Zedpw Author' ] ),
			$this->url( [ 'pkiw_quote_author' => 'Zedprv Author' ] ),
			$this->url( [ 'pkiw_quote_work' => 'Zworksp' ] ),
			$this->url( [ 'pkiw_quote_work' => 'Zworkpw' ] ),
			$this->url( [ 'pkiw_quote_work' => 'Zworkprv' ] ),
		];
		foreach ( $urls as $u ) {
			$html = $this->serve( $u );
			foreach ( [ 'SecretWords', 'Zedsp', 'Zedpw', 'Zedprv', 'Zeddrf', 'Zedfut', 'Zedpen', 'Zedtrash', 'Zwork' ] as $needle ) {
				$this->assertStringNotContainsString( $needle, $html, "leak of $needle on $u" );
			}
		}
		// Feed.
		$this->go_to( trailingslashit( (string) get_term_link( 'quote', Taxonomy::TAXONOMY ) ) . 'feed/' );
		$feed_ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
		foreach ( [ 'prv', 'drf', 'fut', 'pen' ] as $k ) {
			$this->assertNotContains( $ids[ $k ], $feed_ids, "feed has $k" );
		}
		// Index and ability, spaces password.
		$index = Quote_Archive::index();
		$this->assertSame( 1, $index['total'] );
		$this->assertSame( [ 'Pat Public' ], array_column( $index['authors'], 'name' ) );
	}

	public function test_digit_initial_ability_and_current_tab(): void {
		$this->quote( 'D1', '50 Cent', 'Get Rich' );
		$this->quote( 'D2', 'Pat Public', 'Pub' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$ability = wp_get_ability( 'post-kinds/get-quote-index' );
		$this->assertNotNull( $ability );
		$out = $ability->execute( [] );
		$this->assertFalse( is_wp_error( $out ), is_wp_error( $out ) ? $out->get_error_code() . ' ' . $out->get_error_message() : '' );
		$this->assertSame( [ '5', 'P' ], array_column( $out['initials'], 'letter' ) );

	}

	public function test_digit_initial_current_tab(): void {
		$this->quote( 'D1', '50 Cent', 'Get Rich' );
		$this->quote( 'D2', 'Pat Public', 'Pub' );
		$control = $this->serve( $this->url( [ 'pkiw_quote_initial' => 'P' ] ) );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*pkiw_quote_initial=P"[^>]*aria-current="page"#', $control, 'control letter P' );
		$html = $this->serve( $this->url( [ 'pkiw_quote_initial' => '5' ] ) );
		$this->assertStringContainsString( 'pkiw_quote_initial=5', $html, 'tab exists' );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*pkiw_quote_initial=5"[^>]*aria-current="page"#', $html );
	}

	public function test_work_pair_count_matches_filtered_page(): void {
		$this->quote( 'A', 'Ann Author', 'Shared Title' );
		$this->quote( 'B', '', 'Shared Title' );
		$index = Quote_Archive::index();
		$html  = $this->serve( $this->url() );
		preg_match_all( '#<a class="pkiw-quote-work__title" href="([^"]+)"#', $html, $m );
		$this->assertCount( 2, $m[1] );
		foreach ( $index['works'] as $i => $work ) {
			$page = $this->serve( html_entity_decode( $m[1][ $i ] ) );
			$n    = substr_count( $page, 'pkiw-quote-entry__link' );
			$this->assertSame( $work['count'], $n, 'work "' . $work['name'] . '" author "' . $work['author'] . '" says ' . $work['count'] . ' but filtered page has ' . $n );
		}
	}

	public function test_entity_double_decode(): void {
		$id    = $this->quote( 'E', 'E Author', 'E Work', [], '<p>Type &amp;lt;b&amp;gt; literally</p>' );
		$facts = Quote_Archive::facts( $id );
		$this->assertSame( 'Type &lt;b&gt; literally', $facts['text'] );
	}

	public function test_fallback_text_still_decodes_entities_once(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<p>Fish &amp; chips&nbsp;here</p>', 'post_date' => '2026-08-10 10:00:00' ] );
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );

		$this->assertSame( 'Fish & chips here', Quote_Archive::facts( (int) $id )['text'] );
	}

	public function test_authorless_work_link_filters_to_quotes_with_no_author(): void {
		$this->quote( 'A', 'Ann Author', 'Shared Title' );
		$this->quote( 'B', '', 'Shared Title' );
		$page = $this->serve( $this->url( [ 'pkiw_quote_work' => 'Shared Title', 'pkiw_quote_author' => '' ] ) );

		$this->assertSame( 1, substr_count( $page, 'pkiw-quote-entry__link' ) );
		$this->assertStringContainsString( 'Words of B.', $page );
		$this->assertStringNotContainsString( 'Words of A.', $page );
	}

	public function test_logged_out_ability_refused(): void {
		$this->quote( 'A', 'Ann Author', 'W' );
		$ability = wp_get_ability( 'post-kinds/get-quote-index' );
		$out     = $ability->execute( [] );
		$this->assertTrue( is_wp_error( $out ) );
	}

	public function test_leading_space_author_and_work_links_find_their_quotes(): void {
		$this->quote( 'L', ' Lead Space', ' Lead Work' );
		$html = $this->serve( $this->url() );
		preg_match_all( '#<(?:h3[^>]*><a|a class="pkiw-quote-work__title") href="([^"]+)"#', $html, $m );
		$this->assertCount( 2, $m[1], 'heading + work links' );
		foreach ( $m[1] as $href ) {
			$page = $this->serve( html_entity_decode( $href ) );
			$this->assertSame( 1, substr_count( $page, 'pkiw-quote-entry__link' ), "link $href finds its quote" );
		}
	}
}
