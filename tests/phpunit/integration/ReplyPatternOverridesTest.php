<?php
/**
 * Synced Reply pattern with Pattern Overrides on the Reply Card.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * One synced pattern holds a Reply Card with Pattern Overrides enabled. Each
 * pattern instance overrides the card's display text; each post keeps its
 * own kind, reply target and author.
 *
 * @group integration
 */
final class ReplyPatternOverridesTest extends WP_UnitTestCase {

	private const CARD_NAME = 'Reply';

	private const PATTERN_TARGET = 'https://pattern.example/shared-target';

	/**
	 * The synced pattern (wp_block) ID.
	 *
	 * @var int
	 */
	private int $pattern_id;

	/**
	 * Create the synced pattern.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Reply pattern',
				'post_content' => $this->card(
					[
						'title'       => 'Pattern default title',
						'description' => 'Pattern default context',
						'url'         => self::PATTERN_TARGET,
						'author'      => 'Pattern Author',
					]
				),
			]
		);
	}

	public function test_each_instance_renders_its_own_display_text(): void {
		$alpha = $this->reply_post(
			'https://alpha.example/notes/1',
			'Alpha Author',
			[
				'title'     => 'Alpha reply heading',
				'quotation' => 'Alpha quoted line',
			]
		);
		$beta  = $this->reply_post(
			'https://beta.example/posts/2',
			'Beta Author',
			[
				'title'       => 'Beta reply heading',
				'description' => 'Beta context',
			]
		);

		$alpha_cite = $this->reply_cite( $alpha );
		$this->assertSame( [ 'Alpha reply heading' ], $alpha_cite['properties']['name'] );
		$this->assertSame( [ 'Alpha quoted line' ], $alpha_cite['properties']['content'] );

		$beta_cite = $this->reply_cite( $beta );
		$this->assertSame( [ 'Beta reply heading' ], $beta_cite['properties']['name'] );
		$this->assertSame( [ 'Beta context' ], $beta_cite['properties']['content'] );

		$this->assertStringNotContainsString( 'Pattern default title', $this->render( $alpha ) );
		$this->assertStringNotContainsString( 'Alpha reply heading', $this->render( $beta ) );
	}

	public function test_reply_microformats_come_from_each_post(): void {
		$alpha = $this->reply_post( 'https://alpha.example/notes/1', 'Alpha Author', [ 'title' => 'Alpha reply heading' ] );
		$beta  = $this->reply_post( 'https://beta.example/posts/2', 'Beta Author', [ 'title' => 'Beta reply heading' ] );

		$alpha_cite = $this->reply_cite( $alpha );
		$this->assertSame( [ 'https://alpha.example/notes/1' ], $alpha_cite['properties']['url'] );
		$this->assertSame( [ 'Alpha Author' ], $alpha_cite['properties']['author'][0]['properties']['name'] );

		$beta_cite = $this->reply_cite( $beta );
		$this->assertSame( [ 'https://beta.example/posts/2' ], $beta_cite['properties']['url'] );
		$this->assertSame( [ 'Beta Author' ], $beta_cite['properties']['author'][0]['properties']['name'] );
	}

	public function test_an_instance_cannot_override_the_target_or_author(): void {
		$beta = $this->reply_post(
			'https://beta.example/posts/2',
			'Beta Author',
			[
				'title'  => 'Beta reply heading',
				'url'    => 'https://override.example/',
				'author' => 'Override Author',
			]
		);

		$cite = $this->reply_cite( $beta );
		$this->assertSame( [ 'https://beta.example/posts/2' ], $cite['properties']['url'] );
		$this->assertSame( [ 'Beta Author' ], $cite['properties']['author'][0]['properties']['name'] );

		$html = $this->render( $beta );
		$this->assertStringNotContainsString( 'override.example', $html );
		$this->assertStringNotContainsString( 'Override Author', $html );
	}

	public function test_the_editor_offers_only_display_text_for_overrides(): void {
		$settings = get_block_editor_settings( [], new WP_Block_Editor_Context( [ 'name' => 'core/edit-post' ] ) );

		$this->assertSame(
			[ 'title', 'description', 'quotation' ],
			$settings['__experimentalBlockBindingsSupportedAttributes']['post-kinds-indieweb/reply-card'] ?? null
		);
	}

	public function test_a_post_without_its_own_target_never_shows_the_patterns_target(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->instance( [ 'title' => 'Unapproved reply heading' ] ),
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'reply', Taxonomy::TAXONOMY ) );

		$html = $this->render( $post_id );
		$this->assertStringContainsString( 'Unapproved reply heading', $html );
		$this->assertStringNotContainsString( self::PATTERN_TARGET, $html );
		$this->assertStringNotContainsString( 'Pattern Author', $html );
	}

	public function test_a_synced_pattern_update_keeps_instance_text_and_post_data(): void {
		$alpha = $this->reply_post( 'https://alpha.example/notes/1', 'Alpha Author', [ 'title' => 'Alpha reply heading' ] );
		$beta  = $this->reply_post( 'https://beta.example/posts/2', 'Beta Author', [ 'title' => 'Beta reply heading' ] );

		$updated = wp_update_post(
			[
				'ID'           => $this->pattern_id,
				'post_content' => '<!-- wp:group {"className":"reply-pattern-v2"} --><div class="wp-block-group reply-pattern-v2">'
					. $this->card(
						[
							'title'  => 'Updated default title',
							'layout' => 'vertical',
							'url'    => 'https://pattern.example/updated-target',
						]
					)
					. '</div><!-- /wp:group -->',
			],
			true
		);
		$this->assertNotWPError( $updated );

		foreach ( [
			[ $alpha, 'Alpha reply heading', 'https://alpha.example/notes/1', 'Alpha Author' ],
			[ $beta, 'Beta reply heading', 'https://beta.example/posts/2', 'Beta Author' ],
		] as [ $post_id, $heading, $target, $author ] ) {
			$html = $this->render( $post_id );
			$this->assertStringContainsString( 'reply-pattern-v2', $html, 'The pattern update reaches every instance.' );
			$this->assertStringNotContainsString( 'Updated default title', $html );

			$cite = $this->reply_cite( $post_id );
			$this->assertSame( [ $heading ], $cite['properties']['name'] );
			$this->assertSame( [ $target ], $cite['properties']['url'] );
			$this->assertSame( [ $author ], $cite['properties']['author'][0]['properties']['name'] );

			$this->assertSame( $target, get_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', true ) );
			$this->assertSame( [ 'reply' ], wp_get_object_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ) );
		}
	}

	public function test_a_query_loop_gives_each_post_its_own_target(): void {
		$this->reply_post( 'https://alpha.example/notes/1', 'Alpha Author', [ 'title' => 'Alpha reply heading' ], '2026-01-01 10:00:00' );
		$this->reply_post( 'https://beta.example/posts/2', 'Beta Author', [ 'title' => 'Beta reply heading' ], '2026-01-02 10:00:00' );

		$this->go_to( home_url( '/' ) );
		$html = do_blocks(
			'<!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"post","order":"desc","orderBy":"date","inherit":false}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-content /--><!-- /wp:post-template --></div>'
			. '<!-- /wp:query -->'
		);

		$this->assertSame(
			[ 'https://beta.example/posts/2', 'https://alpha.example/notes/1' ],
			$this->reply_targets( \Mf2\parse( $html )['items'] ?? [] )
		);
		$this->assertStringNotContainsString( self::PATTERN_TARGET, $html );
	}

	public function test_a_reply_card_outside_a_pattern_keeps_its_own_target(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:post-kinds-indieweb/reply-card {"title":"Plain card","url":"https://card.example/own","author":"Card Author"} /-->',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'reply', Taxonomy::TAXONOMY ) );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://meta.example/other' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_author', 'Meta Author' );

		$cite = $this->reply_cite( $post_id );
		$this->assertSame( [ 'https://card.example/own' ], $cite['properties']['url'] );
		$this->assertSame( [ 'Card Author' ], $cite['properties']['author'][0]['properties']['name'] );
	}

	public function test_a_query_loop_hides_a_protected_posts_target_and_author(): void {
		$this->reply_post( 'https://alpha.example/notes/1', 'Alpha Author', [ 'title' => 'Alpha reply heading' ], '2026-01-01 10:00:00' );
		$protected = $this->reply_post( 'https://secret.example/thread', 'Secret Author', [ 'title' => 'Protected reply heading' ], '2026-01-02 10:00:00' );
		wp_update_post(
			[
				'ID'            => $protected,
				'post_password' => 'hunter2',
			]
		);

		$this->go_to( home_url( '/' ) );
		$html = do_blocks(
			'<!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"post","order":"desc","orderBy":"date","inherit":false}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template -->'
			. $this->instance( [ 'title' => 'Loop heading' ] )
			. '<!-- /wp:post-template --></div><!-- /wp:query -->'
		);

		$this->assertStringContainsString( 'https://alpha.example/notes/1', $html );
		$this->assertStringNotContainsString( 'secret.example', $html );
		$this->assertStringNotContainsString( 'Secret Author', $html );
	}

	public function test_a_card_without_a_usable_target_is_not_a_reply_context(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->instance( [ 'title' => 'Targetless heading' ] ),
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'reply', Taxonomy::TAXONOMY ) );

		$html  = $this->render( $post_id );
		$entry = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' )['items'][0] ?? [];

		$this->assertStringContainsString( 'Targetless heading', $html );
		$this->assertArrayNotHasKey( 'in-reply-to', $entry['properties'] ?? [] );
	}

	public function test_an_override_enabled_card_outside_a_pattern_keeps_its_target(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->card(
					[
						'title'  => 'Standalone heading',
						'url'    => 'https://card.example/own',
						'author' => 'Card Author',
					]
				),
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'reply', Taxonomy::TAXONOMY ) );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://meta.example/other' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_author', 'Meta Author' );

		$cite = $this->reply_cite( $post_id );
		$this->assertSame( [ 'https://card.example/own' ], $cite['properties']['url'] );
		$this->assertSame( [ 'Card Author' ], $cite['properties']['author'][0]['properties']['name'] );
	}

	public function test_a_non_string_override_keeps_the_patterns_text(): void {
		$post_id = $this->reply_post(
			'https://alpha.example/notes/1',
			'Alpha Author',
			[
				'title'       => [ 'not', 'text' ],
				'description' => 42,
				'quotation'   => [ 'nested' => true ],
			]
		);

		$cite = $this->reply_cite( $post_id );
		$this->assertSame( [ 'Pattern default title' ], $cite['properties']['name'] );
		$this->assertSame( [ 'Pattern default context' ], $cite['properties']['content'] );
	}

	/**
	 * Reply Card markup with Pattern Overrides enabled.
	 *
	 * @param array<string, string> $attributes Card attributes.
	 * @return string
	 */
	private function card( array $attributes ): string {
		$attributes['metadata'] = [
			'name'     => self::CARD_NAME,
			'bindings' => [ '__default' => [ 'source' => 'core/pattern-overrides' ] ],
		];

		return '<!-- wp:post-kinds-indieweb/reply-card ' . wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES ) . ' /-->';
	}

	/**
	 * A core/block instance of the pattern with per-instance overrides.
	 *
	 * @param array<string, mixed> $overrides Override values keyed by attribute.
	 * @return string
	 */
	private function instance( array $overrides ): string {
		return '<!-- wp:block ' . wp_json_encode(
			[
				'ref'     => $this->pattern_id,
				'content' => [ self::CARD_NAME => $overrides ],
			],
			JSON_UNESCAPED_SLASHES
		) . ' /-->';
	}

	/**
	 * A published reply post that inserts the pattern and owns its target.
	 *
	 * @param string                $target    The post's reply target.
	 * @param string                $author    The post's cited author.
	 * @param array<string, mixed>  $overrides Instance display text.
	 * @param string                $date      Optional post date.
	 * @return int
	 */
	private function reply_post( string $target, string $author, array $overrides, string $date = '' ): int {
		$args = [
			'post_status'  => 'publish',
			'post_content' => $this->instance( $overrides ),
		];
		if ( '' !== $date ) {
			$args['post_date'] = $date;
		}
		$post_id = self::factory()->post->create( $args );

		$this->assertNotWPError( wp_set_object_terms( $post_id, 'reply', Taxonomy::TAXONOMY ) );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', $target );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_author', $author );

		return $post_id;
	}

	/**
	 * Render a post's content as its single view does.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function render( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );

		return do_blocks( (string) get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * The parsed in-reply-to citation of a post's h-entry.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function reply_cite( int $post_id ): array {
		$parsed = \Mf2\parse( '<div class="h-entry">' . $this->render( $post_id ) . '</div>' );
		$entry  = $parsed['items'][0] ?? [];

		$this->assertSame( [ 'h-entry' ], $entry['type'] ?? null );
		$this->assertArrayHasKey( 'in-reply-to', $entry['properties'] ?? [] );
		$cite = $entry['properties']['in-reply-to'][0];
		$this->assertIsArray( $cite );
		$this->assertSame( [ 'h-cite' ], $cite['type'] );

		return $cite;
	}

	/**
	 * Reply target URLs of every h-cite card, in document order.
	 *
	 * @param array<int, array<string, mixed>> $items Parsed mf2 items.
	 * @return string[]
	 */
	private function reply_targets( array $items ): array {
		$targets = [];
		foreach ( $items as $item ) {
			if ( in_array( 'h-cite', $item['type'] ?? [], true ) ) {
				$targets = array_merge( $targets, $item['properties']['url'] ?? [] );
			}
			foreach ( $item['properties'] ?? [] as $values ) {
				$targets = array_merge( $targets, $this->reply_targets( array_filter( (array) $values, 'is_array' ) ) );
			}
			$targets = array_merge( $targets, $this->reply_targets( $item['children'] ?? [] ) );
		}

		return $targets;
	}
}
