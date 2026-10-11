<?php
/**
 * Review single microformats (issue 252).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Review_Archive;
use PKIW\Taxonomy;

/**
 * Review singles expose one h-review with public review facts and body content.
 *
 * @group integration
 */
final class ReviewMicroformatsTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private int $original_user_id = 0;

	private int $author_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_user_id    = get_current_user_id();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		if ( null === \PKIW\Kind_Facts::reader( 'review' ) ) {
			( new Review_Archive() )->register();
		}
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'Site Author' ] );
	}

	public function tear_down(): void {
		wp_reset_postdata();
		wp_set_current_user( $this->original_user_id );
		if ( isset( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query']->set( 'atproto', null );
		}
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	public function test_review_single_parses_as_exactly_one_top_level_h_review(): void {
		$post_id = $this->review(
			'Lantern Review',
			[
				'rating'    => 4,
				'best'      => 5,
				'item_name' => 'Fictional Lantern',
				'item_url'  => 'https://items.example.com/lantern',
			],
			'<!-- wp:paragraph --><p>The beam held steady.</p><!-- /wp:paragraph -->'
		);

		$parsed = \Mf2\parse( $this->render_single( $post_id ), get_permalink( $post_id ) );

		$this->assertCount( 1, $parsed['items'] ?? [], 'The page has exactly one top-level mf2 item.' );
		$this->assertSame( [ 'h-review' ], $parsed['items'][0]['type'] ?? [], 'The top-level item is the review.' );
	}

	public function test_protected_review_emits_no_hidden_review_facts_or_item_text(): void {
		$post_id = $this->review(
			'Locked Review',
			[
				'rating'    => 4,
				'best'      => 5,
				'item_name' => 'Fictional Lantern',
				'item_url'  => 'https://items.example.com/lantern',
			],
			'<!-- wp:paragraph --><p>The protected body.</p><!-- /wp:paragraph -->',
			[ 'post_password' => 'secret' ]
		);

		$html = $this->render_single( $post_id );

		$this->assertStringContainsString( 'name="post_password"', $html, 'The protected review renders the password form.' );
		$this->assertStringNotContainsString( 'p-rating', $html, 'Protected reviews do not print rating facts.' );
		$this->assertStringNotContainsString( 'p-best', $html, 'Protected reviews do not print best-rating facts.' );
		$this->assertStringNotContainsString( 'p-item', $html, 'Protected reviews do not print item facts.' );
		$this->assertStringNotContainsString( 'Fictional Lantern', $html, 'Protected reviews do not reveal the reviewed item.' );
	}

	public function test_review_hidden_facts_come_from_review_facts_once(): void {
		$post_id = $this->review(
			'Atlas Review',
			[
				'rating'    => 4,
				'best'      => 5,
				'item_name' => 'Fictional Atlas',
				'item_url'  => 'https://items.example.com/atlas',
			],
			'<!-- wp:paragraph --><p>The maps were useful.</p><!-- /wp:paragraph -->'
		);

		$entry = $this->top_review( $post_id );

		$this->assertSame( [ '4' ], $entry['properties']['rating'] ?? [], 'The h-review has one p-rating value.' );
		$this->assertSame( [ '5' ], $entry['properties']['best'] ?? [], 'The h-review has one p-best value.' );
		$this->assertCount( 1, $entry['properties']['item'] ?? [], 'The h-review has one p-item value.' );
		$item = $entry['properties']['item'][0];
		$this->assertSame( [ 'h-product' ], $item['type'] ?? [], 'The review item is an h-product.' );
		$this->assertSame( [ 'Fictional Atlas' ], $item['properties']['name'] ?? [], 'The review item has the fact name.' );
		$this->assertSame( [ 'https://items.example.com/atlas' ], $item['properties']['url'] ?? [], 'The review item has the fact URL.' );
	}

	public function test_star_rating_block_and_meta_do_not_duplicate_review_properties(): void {
		$content = '<!-- wp:post-kinds-indieweb/star-rating {"rating":4,"maxRating":5,"itemName":"Fictional Kettle","itemUrl":"https://items.example.com/kettle"} /-->'
			. '<!-- wp:paragraph --><p>The kettle poured cleanly.</p><!-- /wp:paragraph -->';
		$post_id = $this->review(
			'Kettle Review',
			[
				'rating'    => 4,
				'best'      => 5,
				'item_name' => 'Fictional Kettle',
				'item_url'  => 'https://items.example.com/kettle',
			],
			$content
		);

		$entry = $this->top_review( $post_id );

		$this->assertCount( 1, $entry['properties']['rating'] ?? [], 'The h-review has one rating after star block stripping.' );
		$this->assertCount( 1, $entry['properties']['best'] ?? [], 'The h-review has one best rating after star block stripping.' );
		$this->assertCount( 1, $entry['properties']['item'] ?? [], 'The h-review has one item after star block stripping.' );
	}

	public function test_rating_zero_prints_item_without_rating_or_best(): void {
		$post_id = $this->review(
			'Unrated Review',
			[
				'rating'    => 0,
				'best'      => 5,
				'item_name' => 'Fictional Compass',
				'item_url'  => 'https://items.example.com/compass',
			],
			'<!-- wp:paragraph --><p>The compass belongs in a pocket.</p><!-- /wp:paragraph -->'
		);

		$entry = $this->top_review( $post_id );

		$this->assertArrayNotHasKey( 'rating', $entry['properties'] ?? [], 'A zero rating does not print p-rating.' );
		$this->assertArrayNotHasKey( 'best', $entry['properties'] ?? [], 'A zero rating does not print p-best.' );
		$this->assertCount( 1, $entry['properties']['item'] ?? [], 'The item still prints when the review has no positive rating.' );
	}

	public function test_review_body_is_e_content_and_hidden_facts_are_outside_it(): void {
		$post_id = $this->review(
			'Lantern Review',
			[
				'rating'    => 4,
				'best'      => 5,
				'item_name' => 'Fictional Lantern',
			],
			'<!-- wp:paragraph --><p>The body stays visible.</p><!-- /wp:paragraph -->'
		);

		$html  = $this->render_single( $post_id );
		$entry = $this->top_review_from_html( $html, $post_id );

		$this->assertSame( 1, $this->class_token_count( $html, 'e-content' ), 'The review body has exactly one e-content wrapper.' );
		$this->assertCount( 1, $entry['properties']['content'] ?? [], 'The h-review has one content property.' );
		$content = $entry['properties']['content'][0];
		$this->assertIsArray( $content, 'The content property preserves html.' );
		$this->assertStringContainsString( 'The body stays visible.', $content['html'] ?? '', 'The content html contains the review body.' );
		$this->assertStringNotContainsString( 'p-rating', $content['html'] ?? '', 'Hidden rating data stays outside e-content.' );
		$this->assertStringNotContainsString( 'Fictional Lantern', $content['html'] ?? '', 'Hidden item data stays outside e-content.' );
	}

	public function test_password_protected_review_gets_no_e_content_wrap(): void {
		$post_id = $this->review(
			'Locked Review',
			[
				'rating'    => 4,
				'item_name' => 'Fictional Lantern',
			],
			'<!-- wp:paragraph --><p>Hidden body.</p><!-- /wp:paragraph -->',
			[ 'post_password' => 'secret' ]
		);

		$html = $this->render_single( $post_id );

		$this->assertStringContainsString( 'name="post_password"', $html, 'The protected review renders the password form.' );
		$this->assertSame( 0, $this->class_token_count( $html, 'e-content' ), 'A password form is not wrapped as review content.' );
	}

	public function test_untitled_review_has_rating_and_item_but_no_name_property(): void {
		$post_id = $this->review(
			'',
			[
				'rating'    => 4,
				'best'      => 5,
				'item_name' => 'Fictional Lantern',
			],
			'<!-- wp:paragraph --><p>The title is intentionally empty.</p><!-- /wp:paragraph -->'
		);

		$entry = $this->top_review( $post_id );

		$this->assertSame( [ '4' ], $entry['properties']['rating'] ?? [], 'The untitled h-review keeps its rating.' );
		$this->assertCount( 1, $entry['properties']['item'] ?? [], 'The untitled h-review keeps its item.' );
		$this->assertArrayNotHasKey( 'name', $entry['properties'] ?? [], 'The untitled h-review has no synthetic name.' );
	}

	/**
	 * @dataProvider non_review_kinds
	 */
	public function test_e_content_wrap_is_review_only( string $kind ): void {
		$post_id = $this->kind_post( $kind, '<!-- wp:paragraph --><p>Plain body.</p><!-- /wp:paragraph -->' );

		$html = $this->filtered_content( $post_id );

		$this->assertSame( 0, $this->class_token_count( $html, 'e-content' ), "{$kind} singles do not get a plugin e-content wrapper." );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function non_review_kinds(): array {
		return [
			'recipe' => [ 'recipe' ],
			'event'  => [ 'event' ],
			'watch'  => [ 'watch' ],
		];
	}

	public function test_atproto_review_request_carries_none_of_the_new_review_markup(): void {
		$post_id = $this->review(
			'Atlas Review',
			[
				'rating'    => 4,
				'best'      => 5,
				'item_name' => 'Fictional Atlas',
			],
			'<!-- wp:paragraph --><p>The record projection keeps the body only.</p><!-- /wp:paragraph -->'
		);

		$this->go_to_post( $post_id );
		$GLOBALS['wp_query']->set( 'atproto', '' );
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString( 'p-rating', $html, 'The atproto projection has no rating.' );
		$this->assertStringNotContainsString( 'e-content', $html, 'The atproto projection has no review content wrapper.' );
		$this->assertStringNotContainsString( 'pkiw-singular-entry', $html, 'The atproto projection has no singular root wrapper.' );
	}

	private function review( string $title, array $meta, string $content, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_author'  => $this->author_id,
					'post_content' => $content,
				],
				$post
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $id, 'review', Taxonomy::TAXONOMY ), 'The review kind term is assigned.' );
		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $id, Meta_Fields::PREFIX . 'review_' . $suffix, $value );
		}

		return $id;
	}

	private function kind_post( string $kind, string $content ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => ucfirst( $kind ) . ' probe',
				'post_author'  => $this->author_id,
				'post_content' => $content,
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY ), 'The kind term is assigned.' );

		return $id;
	}

	private function top_review( int $post_id ): array {
		return $this->top_review_from_html( $this->render_single( $post_id ), $post_id );
	}

	private function top_review_from_html( string $html, int $post_id ): array {
		$parsed = \Mf2\parse( $html, get_permalink( $post_id ) );
		$this->assertCount( 1, $parsed['items'] ?? [], 'The rendered single has one top-level mf2 item.' );
		$item = $parsed['items'][0];
		$this->assertSame( [ 'h-review' ], $item['type'] ?? [], 'The top-level item is an h-review.' );

		return $item;
	}

	private function render_single( int $post_id ): string {
		$this->go_to_post( $post_id );
		$template = resolve_block_template(
			'single',
			[ 'single-post.php', 'single.php', 'singular.php', 'index.php' ],
			''
		);
		$this->assertNotNull( $template, 'No block template resolved for the review single.' );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	private function filtered_content( int $post_id ): string {
		$this->go_to_post( $post_id );

		return apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
	}

	private function go_to_post( int $post_id ): void {
		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'The request resolves as singular.' );
		setup_postdata( get_post( $post_id ) );
	}

	private function class_token_count( string $html, string $token ): int {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $doc );

		return $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")]' )->length;
	}
}
