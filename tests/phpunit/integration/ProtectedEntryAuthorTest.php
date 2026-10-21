<?php
/**
 * Protected entries print no hidden author h-card (bug wave).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Note_Archive;
use PKIW\Taxonomy;

/**
 * A password-protected read shows its title and the password form, nothing
 * else. The hidden `p-author h-card` names the author, so it goes too;
 * unprotected output stays exactly as it was.
 *
 * @group integration
 */
final class ProtectedEntryAuthorTest extends WP_UnitTestCase {

	private const NAME = 'Hidden Author Name';

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Author user ID.
	 *
	 * @var int
	 */
	private int $author_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		update_option( 'posts_per_page', 20 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		$this->author_id = (int) self::factory()->user->create(
			[
				'role'         => 'editor',
				'user_login'   => 'bw-author-login',
				'display_name' => self::NAME,
			]
		);
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		remove_all_filters( 'the_content', 11 );
		remove_all_filters( 'the_content', 12 );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * Create a post of a kind by the fixture author.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $args Extra post fields.
	 */
	private function entry( string $kind, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Entry ' . $kind,
					'post_author'  => $this->author_id,
					'post_content' => '<p>Body of the entry.</p>',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * The count of hidden author h-cards in some markup.
	 */
	private function author_cards( string $html ): int {
		return substr_count( $html, 'p-author h-card' );
	}

	public function test_protected_single_prints_no_author_card_and_unprotected_prints_one(): void {
		$locked = $this->entry( 'note', [ 'post_password' => 'bw-pass' ] );
		$open   = $this->entry( 'note', [ 'post_title' => 'Open note' ] );

		$this->go_to( get_permalink( $locked ) );
		$this->assertTrue( is_singular() );
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $locked ) );
		$this->assertStringContainsString( 'pkiw-singular-entry', $html, 'The singular wrapper still renders on a protected read.' );
		$this->assertStringNotContainsString( 'p-author', $html );
		$this->assertStringNotContainsString( self::NAME, $html );

		$this->go_to( get_permalink( $open ) );
		$this->assertTrue( is_singular() );
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $open ) );
		$this->assertSame( 1, $this->author_cards( $html ), 'An unprotected single keeps its one author h-card.' );
		$this->assertStringContainsString( self::NAME, $html );
	}

	public function test_protected_article_single_prints_no_author_card(): void {
		$locked = $this->entry( 'article', [ 'post_password' => 'bw-pass' ] );

		$this->go_to( get_permalink( $locked ) );
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $locked ) );

		$this->assertStringNotContainsString( 'p-author', $html );
		$this->assertStringNotContainsString( self::NAME, $html );
	}

	public function test_review_archive_entry_hides_the_author_only_when_protected(): void {
		$locked = $this->entry( 'review', [ 'post_title' => 'Locked review', 'post_password' => 'bw-pass' ] );
		$open   = $this->entry( 'review', [ 'post_title' => 'Open review' ] );

		$html = $this->serve( 'review' );

		$this->assertSame( 1, $this->author_cards( $this->entry_markup( $html, $open ) ), 'The open review entry keeps its author h-card.' );
		$locked_markup = $this->entry_markup( $html, $locked );
		$this->assertStringContainsString( 'pkiw-review-entry__password', $locked_markup, 'The locked entry prints the password form.' );
		$this->assertSame( 0, $this->author_cards( $locked_markup ) );
		$this->assertStringNotContainsString( self::NAME, $locked_markup );
	}

	public function test_chicken_archive_entry_hides_the_author_only_when_protected(): void {
		$locked = $this->entry( 'chicken', [ 'post_title' => 'Locked hen', 'post_password' => 'bw-pass' ] );
		$open   = $this->entry( 'chicken', [ 'post_title' => 'Open hen' ] );

		$html = $this->serve( 'chicken' );

		$this->assertSame( 1, $this->author_cards( $this->entry_markup( $html, $open ) ), 'The open chicken entry keeps its author h-card.' );
		$locked_markup = $this->entry_markup( $html, $locked );
		$this->assertNotSame( '', $locked_markup );
		$this->assertSame( 0, $this->author_cards( $locked_markup ) );
		$this->assertStringNotContainsString( self::NAME, $locked_markup );
	}

	public function test_note_entry_block_hides_the_author_only_when_protected(): void {
		$locked = $this->entry( 'note', [ 'post_password' => 'bw-pass' ] );
		$open   = $this->entry( 'note', [ 'post_title' => 'Open note' ] );

		$this->assertSame( 0, $this->author_cards( $this->note_block( $locked ) ) );
		$this->assertStringNotContainsString( self::NAME, $this->note_block( $locked ) );
		$this->assertSame( 1, $this->author_cards( $this->note_block( $open ) ), 'An open note keeps its author h-card.' );
	}

	public function test_entry_author_html_is_empty_when_protected_and_unchanged_otherwise(): void {
		$locked = get_post( $this->entry( 'note', [ 'post_password' => 'bw-pass' ] ) );
		$open   = get_post( $this->entry( 'note', [ 'post_title' => 'Open note' ] ) );

		$this->assertSame( '', \PKIW\entry_author_html( $locked ) );

		$url      = esc_url( (string) get_author_posts_url( $this->author_id ) );
		$photo    = esc_url( (string) get_avatar_url( $this->author_id, [ 'size' => 96 ] ) );
		$expected = '<span class="p-author h-card"><span class="p-name">' . esc_html( self::NAME ) . '</span>'
			. '<data class="u-url" value="' . $url . '"></data>'
			. '<data class="u-photo" value="' . $photo . '"></data></span>';
		$this->assertNotSame( '', $photo, 'The fixture author has an avatar URL.' );
		$this->assertSame( $expected, \PKIW\entry_author_html( $open ), 'An unprotected post renders the same markup as before.' );
	}

	public function test_a_blank_but_set_password_counts_as_protected_like_core(): void {
		$blank = get_post( $this->entry( 'note', [ 'post_password' => '   ' ] ) );

		$this->assertTrue( post_password_required( $blank ), 'Core treats a whitespace password as protected.' );
		$this->assertSame( '', \PKIW\entry_author_html( $blank ) );
	}

	public function test_the_author_card_returns_once_core_accepts_the_password_cookie(): void {
		// Core's post_password_required() is false once the matching cookie is set, so the card returns.
		$post = get_post( $this->entry( 'note', [ 'post_password' => 'bw-pass' ] ) );
		$this->assertSame( '', \PKIW\entry_author_html( $post ) );

		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher = new PasswordHash( 8, true );

		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( 'bw-pass' );
		$this->assertFalse( post_password_required( $post ) );
		$this->assertStringContainsString( 'p-author h-card', \PKIW\entry_author_html( $post ) );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	/**
	 * Render a kind archive through the resolved plugin template.
	 */
	private function serve( string $kind ): string {
		$url = get_term_link( $kind, Taxonomy::TAXONOMY );
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-' . $kind . '.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * The markup of the list item for one post.
	 */
	private function entry_markup( string $html, int $post_id ): string {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$item = ( new DOMXPath( $dom ) )->query( '//li[contains(concat(" ",normalize-space(@class)," ")," post-' . $post_id . ' ")]' )->item( 0 );

		return $item instanceof DOMNode ? (string) $dom->saveHTML( $item ) : '';
	}

	/**
	 * The note entry block for one post, the way a Post Template item renders it.
	 */
	private function note_block( int $post_id ): string {
		$post = get_post( $post_id );
		setup_postdata( $post );
		$block = new WP_Block(
			[ 'blockName' => Note_Archive::BLOCK, 'attrs' => [] ],
			[ 'postId' => $post_id, 'postType' => 'post' ]
		);
		$html  = $block->render();
		wp_reset_postdata();

		return $html;
	}
}
