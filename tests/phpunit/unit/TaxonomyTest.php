<?php
namespace PKIW\Tests\Unit;

use WP_UnitTestCase;
use PKIW\Taxonomy;

class TaxonomyTest extends WP_UnitTestCase {

	private Taxonomy $taxonomy;

	public function set_up(): void {
		parent::set_up();
		$this->taxonomy = new Taxonomy();
	}

	public function test_taxonomy_constant() {
		$this->assertSame( 'kind', Taxonomy::TAXONOMY );
	}

	public function test_taxonomy_is_registered() {
		$this->assertTrue( taxonomy_exists( Taxonomy::TAXONOMY ) );
	}

	public function test_taxonomy_is_public() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertTrue( $tax->public );
	}

	public function test_taxonomy_shows_in_rest() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertTrue( $tax->show_in_rest );
		$this->assertSame( 'kind', $tax->rest_base );
	}

	public function test_taxonomy_is_not_hierarchical() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertFalse( $tax->hierarchical );
	}

	public function test_taxonomy_attached_to_post() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertContains( 'post', $tax->object_type );
	}

	public function test_taxonomy_default_term_is_note() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertSame( 'note', $tax->default_term['slug'] );
	}

	public function test_get_default_kinds_returns_40() {
		$kinds = $this->taxonomy->get_default_kinds();
		$this->assertCount( 40, $kinds );
	}

	/**
	 * @dataProvider kind_slugs_provider
	 */
	public function test_default_kind_exists( string $slug ) {
		$kinds = $this->taxonomy->get_default_kinds();
		$this->assertArrayHasKey( $slug, $kinds );
	}

	public function kind_slugs_provider(): array {
		return [
			'note'        => [ 'note' ],
			'article'     => [ 'article' ],
			'reply'       => [ 'reply' ],
			'like'        => [ 'like' ],
			'repost'      => [ 'repost' ],
			'bookmark'    => [ 'bookmark' ],
			'rsvp'        => [ 'rsvp' ],
			'checkin'     => [ 'checkin' ],
			'listen'      => [ 'listen' ],
			'watch'       => [ 'watch' ],
			'read'        => [ 'read' ],
			'event'       => [ 'event' ],
			'photo'       => [ 'photo' ],
			'video'       => [ 'video' ],
			'review'      => [ 'review' ],
			'favorite'    => [ 'favorite' ],
			'jam'         => [ 'jam' ],
			'wish'        => [ 'wish' ],
			'mood'        => [ 'mood' ],
			'acquisition' => [ 'acquisition' ],
			'drink'       => [ 'drink' ],
			'eat'         => [ 'eat' ],
			'recipe'      => [ 'recipe' ],
			'play'        => [ 'play' ],
			'audio'       => [ 'audio' ],
			'quote'       => [ 'quote' ],
			'tag'         => [ 'tag' ],
			'weather'     => [ 'weather' ],
			'exercise'    => [ 'exercise' ],
			'trip'        => [ 'trip' ],
			'itinerary'   => [ 'itinerary' ],
			'follow'      => [ 'follow' ],
			'issue'       => [ 'issue' ],
			'question'    => [ 'question' ],
			'sleep'       => [ 'sleep' ],
			'craft'       => [ 'craft' ],
			'chicken'     => [ 'chicken' ],
			'comics'      => [ 'comics' ],
			'collection'  => [ 'collection' ],
			'presentation' => [ 'presentation' ],
		];
	}

	/**
	 * @dataProvider kind_slugs_provider
	 */
	public function test_default_kind_has_name_and_description( string $slug ) {
		$kinds = $this->taxonomy->get_default_kinds();
		$this->assertNotEmpty( $kinds[ $slug ]['name'] );
		$this->assertNotEmpty( $kinds[ $slug ]['description'] );
	}

	public function test_set_and_get_post_kind() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'listen', Taxonomy::TAXONOMY );

		$result = $this->taxonomy->set_post_kind( $post_id, 'listen' );
		$this->assertTrue( $result );

		$term = $this->taxonomy->get_post_kind( $post_id );
		$this->assertNotNull( $term );
		$this->assertSame( 'listen', $term->slug );
	}

	public function test_get_post_kind_returns_null_when_unset() {
		$post_id = self::factory()->post->create();
		$term    = $this->taxonomy->get_post_kind( $post_id );
		$this->assertNull( $term );
	}

	public function test_is_valid_kind_with_existing_term() {
		wp_insert_term( 'note', Taxonomy::TAXONOMY );
		$this->assertTrue( $this->taxonomy->is_valid_kind( 'note' ) );
	}

	public function test_is_valid_kind_with_nonexistent_term() {
		$this->assertFalse( $this->taxonomy->is_valid_kind( 'nonexistent_kind_xyz' ) );
	}

	public function test_get_kinds_returns_terms() {
		wp_insert_term( 'note', Taxonomy::TAXONOMY );
		wp_insert_term( 'like', Taxonomy::TAXONOMY );

		$kinds = $this->taxonomy->get_kinds();
		$this->assertIsArray( $kinds );
		$this->assertNotEmpty( $kinds );
	}

	public function test_get_post_types_includes_post() {
		$this->assertContains( 'post', $this->taxonomy->get_post_types() );
	}

	public function test_taxonomy_capabilities() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertSame( 'manage_categories', $tax->cap->manage_terms );
		$this->assertSame( 'edit_posts', $tax->cap->assign_terms );
	}

	public function test_filter_term_link_passes_through_other_taxonomies() {
		$term_data = wp_insert_term( 'test-cat', 'category' );
		$wp_term   = get_term( $term_data['term_id'], 'category' );
		$result    = $this->taxonomy->filter_term_link( 'http://example.com/test', $wp_term, 'category' );
		$this->assertSame( 'http://example.com/test', $result );
	}

	// --- first-block kind sync ------------------------------------------------

	private function ensure_kind_terms( string ...$slugs ): void {
		foreach ( $slugs as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( $slug, Taxonomy::TAXONOMY );
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function kind_slugs( int $post_id ): array {
		$slugs = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
		return is_array( $slugs ) ? $slugs : [];
	}

	public function test_kind_card_blocks_map_only_default_kinds() {
		$default_kinds = $this->taxonomy->get_default_kinds();
		foreach ( Taxonomy::KIND_CARD_BLOCKS as $block_name => $kind ) {
			$this->assertArrayHasKey( $kind, $default_kinds, "$block_name maps to unknown kind $kind" );
		}
	}

	public function test_get_first_block_kind_maps_every_card_block() {
		foreach ( Taxonomy::KIND_CARD_BLOCKS as $block_name => $kind ) {
			$this->assertSame(
				$kind,
				Taxonomy::get_first_block_kind( "<!-- wp:$block_name /-->" )
			);
		}
	}

	public function test_get_first_block_kind_skips_leading_whitespace() {
		$this->assertSame(
			'eat',
			Taxonomy::get_first_block_kind( "\n\n<!-- wp:post-kinds-indieweb/eat-card /-->\n" )
		);
	}

	public function test_get_first_block_kind_null_for_plain_text_and_non_card_first_block() {
		$this->assertNull( Taxonomy::get_first_block_kind( '' ) );
		$this->assertNull( Taxonomy::get_first_block_kind( 'just a plain note' ) );
		// Only the FIRST block counts — a card further down doesn't.
		$this->assertNull(
			Taxonomy::get_first_block_kind(
				"<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->\n<!-- wp:post-kinds-indieweb/eat-card /-->"
			)
		);
	}

	public function test_get_first_block_kind_descends_into_wrapper_blocks() {
		// The Micropub bridge wraps its card in an h-entry core/group —
		// the card is still the first thing a person sees.
		$this->assertSame(
			'listen',
			Taxonomy::get_first_block_kind(
				'<!-- wp:group {"className":"h-entry"} --><div class="wp-block-group h-entry"><!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"One"} /--></div><!-- /wp:group -->'
			)
		);
		// A wrapper whose first visible child is not a card behaves like
		// a paragraph-first post.
		$this->assertNull(
			Taxonomy::get_first_block_kind(
				'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph --><!-- wp:post-kinds-indieweb/eat-card /--></div><!-- /wp:group -->'
			)
		);
	}

	public function test_save_assigns_kind_from_first_card_block() {
		$this->ensure_kind_terms( 'eat' );

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/eat-card {"name":"Pizza"} /-->' ]
		);

		$this->assertSame( [ 'eat' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( 'eat', get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_save_overrides_note_default_term() {
		// The test suite's between-class cleanup deletes the bootstrap-created
		// `note` term while `default_term_kind` still points at it, silently
		// breaking core's default stamping. Re-registering recreates the term
		// and repairs the option — what init does on a real site.
		$this->taxonomy->register_taxonomy();
		$this->ensure_kind_terms( 'checkin' );
		// Core only stamps the `note` default_term when the acting user can
		// assign terms — create as an author like a real editor session.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$post_id = self::factory()->post->create(
			[
				'post_content' => 'plain text, no blocks',
				'post_status'  => 'publish',
			]
		);
		$this->assertSame( [ 'note' ], $this->kind_slugs( $post_id ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/checkin-card /-->',
			]
		);

		$this->assertSame( [ 'checkin' ], $this->kind_slugs( $post_id ) );
	}

	public function test_save_respects_manually_chosen_kind() {
		$this->ensure_kind_terms( 'eat', 'photo' );

		$post_id = self::factory()->post->create( [ 'post_content' => 'plain' ] );
		wp_set_post_terms( $post_id, [ 'photo' ], Taxonomy::TAXONOMY );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/eat-card /-->',
			]
		);

		$this->assertSame( [ 'photo' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_changing_first_block_resyncs_auto_assigned_kind() {
		$this->ensure_kind_terms( 'eat', 'drink' );

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/eat-card /-->' ]
		);
		$this->assertSame( [ 'eat' ], $this->kind_slugs( $post_id ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/drink-card /-->',
			]
		);

		$this->assertSame( [ 'drink' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( 'drink', get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_manual_change_after_auto_assign_is_never_overridden() {
		$this->ensure_kind_terms( 'eat', 'watch' );

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/eat-card /-->' ]
		);
		$this->assertSame( [ 'eat' ], $this->kind_slugs( $post_id ) );

		// A person re-kinds the post; the stale auto marker still says eat.
		wp_set_post_terms( $post_id, [ 'watch' ], Taxonomy::TAXONOMY );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/eat-card {"name":"edited"} /-->',
			]
		);

		$this->assertSame( [ 'watch' ], $this->kind_slugs( $post_id ) );
	}

	public function test_sync_skips_kind_without_registered_term() {
		// The sync must not invent a term the site doesn't have.
		$term = get_term_by( 'slug', 'jam', Taxonomy::TAXONOMY );
		if ( $term instanceof \WP_Term ) {
			wp_delete_term( $term->term_id, Taxonomy::TAXONOMY );
		}

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/jam-card /-->' ]
		);

		$this->assertSame( [], $this->kind_slugs( $post_id ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_sync_ignores_non_card_content() {
		$this->ensure_kind_terms( 'eat' );

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:paragraph --><p>dinner was great</p><!-- /wp:paragraph -->' ]
		);

		$this->assertSame( [], $this->kind_slugs( $post_id ) );
	}

	// --- presentation classification on save ---------------------------------

	private const WPTV_EMBED = '<!-- wp:embed {"url":"https://wordpress.tv/2021/07/22/hari-shanker-hauwa-abashiya-courtney-robertson-help-shape-content-on-learn-wordpress/","type":"video","providerNameSlug":"wordpress-tv-embed","responsive":true} -->
<figure class="wp-block-embed is-type-video is-provider-wordpress-tv-embed wp-block-embed-wordpress-tv-embed"><div class="wp-block-embed__wrapper">
https://wordpress.tv/2021/07/22/hari-shanker-hauwa-abashiya-courtney-robertson-help-shape-content-on-learn-wordpress/
</div></figure>
<!-- /wp:embed -->';

	private const DECK_EMBED = '<!-- wp:embed {"url":"https://speakerdeck.com/courtneyr/blocks-for-everyone","type":"rich","providerNameSlug":"speaker-deck","responsive":true} -->
<figure class="wp-block-embed is-type-rich is-provider-speaker-deck wp-block-embed-speaker-deck"><div class="wp-block-embed__wrapper">
https://speakerdeck.com/courtneyr/blocks-for-everyone
</div></figure>
<!-- /wp:embed -->';

	private const YOUTUBE_EMBED = '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=Zr1m5aYk0aQ","type":"video","providerNameSlug":"youtube","responsive":true} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=Zr1m5aYk0aQ
</div></figure>
<!-- /wp:embed -->';

	private const LISTEN_CARD = '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"Episode 12: Community"} /-->';

	public function test_save_classifies_wordpress_tv_talk_without_a_kind() {
		$this->ensure_kind_terms( 'presentation' );

		$post_id = self::factory()->post->create( [ 'post_content' => self::WPTV_EMBED ] );

		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( 'presentation', get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_save_classifies_deck_over_note_default() {
		$this->ensure_kind_terms( 'presentation', 'note' );

		$post_id = self::factory()->post->create( [ 'post_content' => 'plain' ] );
		wp_set_post_terms( $post_id, [ 'note' ], Taxonomy::TAXONOMY );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => self::DECK_EMBED,
			]
		);

		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $post_id ) );
	}

	public function test_save_keeps_manually_chosen_article_with_a_deck() {
		$this->ensure_kind_terms( 'presentation', 'article' );

		$post_id = self::factory()->post->create( [ 'post_content' => 'plain' ] );
		wp_set_post_terms( $post_id, [ 'article' ], Taxonomy::TAXONOMY );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => self::DECK_EMBED,
			]
		);

		$this->assertSame( [ 'article' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_save_keeps_manually_chosen_listen_with_a_deck() {
		$this->ensure_kind_terms( 'presentation', 'listen' );

		$post_id = self::factory()->post->create( [ 'post_content' => 'plain' ] );
		wp_set_post_terms( $post_id, [ 'listen' ], Taxonomy::TAXONOMY );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => self::DECK_EMBED,
			]
		);

		$this->assertSame( [ 'listen' ], $this->kind_slugs( $post_id ) );
	}

	public function test_save_listen_card_with_a_deck_is_a_presentation_and_stays_one() {
		$this->ensure_kind_terms( 'presentation', 'listen' );

		$post_id = self::factory()->post->create( [ 'post_content' => self::LISTEN_CARD . "\n\n" . self::DECK_EMBED ] );

		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( 'presentation', get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );

		// A second save must not flip it back to listen.
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Edited',
			]
		);

		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $post_id ) );
	}

	public function test_listen_card_post_that_loses_its_deck_returns_to_listen() {
		// The auto marker shows the sync set presentation, and the listen
		// card is still first, so the card's kind comes back.
		$this->ensure_kind_terms( 'presentation', 'listen' );

		$post_id = self::factory()->post->create( [ 'post_content' => self::LISTEN_CARD . "\n\n" . self::DECK_EMBED ] );
		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $post_id ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => self::LISTEN_CARD,
			]
		);

		$this->assertSame( [ 'listen' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( 'listen', get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_card_less_post_that_loses_its_deck_keeps_presentation() {
		// Nothing in the content implies a kind any more, so the sync leaves
		// the stored term alone, as it does when a card is removed.
		$this->ensure_kind_terms( 'presentation' );

		$post_id = self::factory()->post->create( [ 'post_content' => self::DECK_EMBED ] );
		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $post_id ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>Slides coming soon.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $post_id ) );
	}

	public function test_save_listen_card_with_youtube_stays_listen() {
		$this->ensure_kind_terms( 'presentation', 'listen' );

		$post_id = self::factory()->post->create( [ 'post_content' => self::LISTEN_CARD . "\n\n" . self::YOUTUBE_EMBED ] );

		$this->assertSame( [ 'listen' ], $this->kind_slugs( $post_id ) );
	}

	public function test_save_youtube_only_assigns_no_kind() {
		$this->ensure_kind_terms( 'presentation' );

		$post_id = self::factory()->post->create( [ 'post_content' => self::YOUTUBE_EMBED ] );

		$this->assertSame( [], $this->kind_slugs( $post_id ) );
	}

	public function test_save_other_card_keeps_winning_over_a_deck() {
		$this->ensure_kind_terms( 'presentation', 'eat' );

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/eat-card {"name":"Conference lunch"} /-->' . "\n\n" . self::DECK_EMBED ]
		);

		$this->assertSame( [ 'eat' ], $this->kind_slugs( $post_id ) );
	}

	public function test_auto_kind_status_reports_the_guard() {
		$this->ensure_kind_terms( 'presentation', 'note', 'article', 'eat' );

		$none = self::factory()->post->create( [ 'post_content' => 'plain' ] );

		$note = self::factory()->post->create( [ 'post_content' => 'plain' ] );
		wp_set_post_terms( $note, [ 'note' ], Taxonomy::TAXONOMY );

		$picked = self::factory()->post->create( [ 'post_content' => 'plain' ] );
		wp_set_post_terms( $picked, [ 'article' ], Taxonomy::TAXONOMY );

		$auto = self::factory()->post->create( [ 'post_content' => '<!-- wp:post-kinds-indieweb/eat-card /-->' ] );

		$this->assertSame( 'eligible', $this->taxonomy->auto_kind_status( $none, 'presentation' ) );
		$this->assertSame( 'eligible', $this->taxonomy->auto_kind_status( $note, 'presentation' ) );
		$this->assertSame( 'protected', $this->taxonomy->auto_kind_status( $picked, 'presentation' ) );
		$this->assertSame( 'eligible', $this->taxonomy->auto_kind_status( $auto, 'presentation' ), 'a kind this plugin set may change' );
		$this->assertSame( 'same', $this->taxonomy->auto_kind_status( $auto, 'eat' ) );
	}

	/**
	 * Raw-markup decks from real posts, saved through wp_after_insert_post.
	 *
	 * @return array<string, array{string}>
	 */
	public function raw_markup_deck_provider(): array {
		return [
			'Google Slides iframe (2588)'            => [ '<p><iframe src="https://docs.google.com/presentation/embed?id=1sdVsnj3yHEDVOLU6WtFU08O7H_FM3n6ou25LLqvqBTc&amp;start=false&amp;loop=false&amp;delayms=3000" frameborder="0" width="483" height="341" allowfullscreen="true"></iframe></p>' ],
			'SlideShare iframe in HTML block (5221)' => [ "<!-- wp:html -->\n<iframe src=\"//www.slideshare.net/slideshow/embed_code/key/fRg05TpkEwlzD9\" width=\"1200\" height=\"628\" frameborder=\"0\" allowfullscreen> </iframe>\n<!-- /wp:html -->" ],
			'Notist script embed'                    => [ "<!-- wp:html -->\n<p data-notist=\"courtneyr/AbC123\">View <a href=\"https://noti.st/courtneyr/AbC123\">Building Community</a> on Notist.</p><script async src=\"https://on.notist.cloud/embed/002.js\"></script>\n<!-- /wp:html -->" ],
			'Canva iframe, encoded src (8581)'       => [ "<!-- wp:html -->\n<iframe loading=\"lazy\" src=\"https:&#x2F;&#x2F;www.canva.com&#x2F;design&#x2F;DAFhsyOf9EM&#x2F;view?embed\" allowfullscreen=\"allowfullscreen\" allow=\"fullscreen\"></iframe>\n<!-- /wp:html -->" ],
			'[slideshare] shortcode block (4129)'    => [ "<!-- wp:shortcode -->\n[slideshare id=31623205&amp;doc=websitechecklist-140225091106-phpapp01]\n<!-- /wp:shortcode -->" ],
		];
	}

	/**
	 * @dataProvider raw_markup_deck_provider
	 */
	public function test_save_classifies_raw_markup_deck_for_an_administrator( string $content ) {
		$this->ensure_kind_terms( 'presentation' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $post_id ) );
		$this->assertSame( 'presentation', get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_author_saving_a_deck_embed_block_gets_presentation() {
		$this->ensure_kind_terms( 'presentation' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$post_id = self::factory()->post->create( [ 'post_content' => self::DECK_EMBED ] );

		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $post_id ) );
	}

	public function test_author_raw_deck_iframe_is_stripped_by_kses_and_not_classified() {
		$this->ensure_kind_terms( 'presentation' );
		// Authors lack unfiltered_html, so kses removes the <iframe> before
		// the classifier sees it. The embed block is an author's way in.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$post_id = self::factory()->post->create(
			[
				'post_content' => '<p><iframe src="https://docs.google.com/presentation/embed?id=1sdVsnj3yHEDVOLU6WtFU08O7H_FM3n6ou25LLqvqBTc"></iframe></p>',
			]
		);

		$this->assertStringNotContainsString( '<iframe', get_post_field( 'post_content', $post_id ) );
		$this->assertNotContains( 'presentation', $this->kind_slugs( $post_id ) );
	}
}
