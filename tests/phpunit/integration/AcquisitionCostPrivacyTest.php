<?php
/**
 * Acquisition cost is private unless the post opts in (#239).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Integrations\Atmosphere_Document_Map;
use PKIW\Integrations\Atmosphere_Titles;
use PKIW\Meta_Fields;

/**
 * Courtney's answer A on #239: cost stays out of everything a visitor or a
 * federated reader gets until the card's "Show cost publicly" toggle is on.
 * The toggle is the card attribute `showCostPublicly`, which Card_Meta_Sync
 * keeps in `_pkiw_acquisition_cost_public`. Stored cost is never touched.
 *
 * Covers the card and its microformats, the Stream card, feed
 * `content:encoded` and whole RSS2 and Atom documents, REST
 * `content.rendered` and `_pkiw_acquisition_price`, the
 * `post-kinds/get-post-meta` ability, and the theme helper
 * Meta_Fields::acquisition_cost_visible(). Readers of raw post_content get
 * the card as the editor stored it, static HTML included: the text
 * ActivityPub builds a summary from, the ATmosphere title of an untitled
 * post, and WP_Query and REST search.
 *
 * @group integration
 */
final class AcquisitionCostPrivacyTest extends WP_UnitTestCase {

	private const TITLE = 'Walnut Desk Lamp Zq9';
	private const WHERE = 'Corner Hardware Zq5';
	private const COST  = '$149.99';

	/**
	 * What the cost prints as. Nothing else on these pages contains it.
	 */
	private const COST_TEXT = '149.99';

	private const TOGGLE_KEY = '_pkiw_acquisition_cost_public';
	private const PRICE_KEY  = '_pkiw_acquisition_price';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Acquisition card markup.
	 *
	 * @param bool $show_cost Whether the card's "Show cost publicly" toggle is on.
	 */
	private function card( bool $show_cost ): string {
		$attrs = [
			'title'           => self::TITLE,
			'acquisitionType' => 'purchase',
			'cost'            => self::COST,
			'where'           => self::WHERE,
		];
		if ( $show_cost ) {
			$attrs['showCostPublicly'] = true;
		}

		return '<!-- wp:post-kinds-indieweb/acquisition-card ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/**
	 * The card as the block editor stored it before issue 239: the block
	 * comment plus the static HTML save.js printed, cost included. Live
	 * acquisitions hold this markup.
	 *
	 * @param bool $show_cost Whether the card's "Show cost publicly" toggle is on.
	 */
	private function saved_card( bool $show_cost ): string {
		$attrs = [
			'title' => self::TITLE,
			'cost'  => self::COST,
			'where' => self::WHERE,
		];
		if ( $show_cost ) {
			$attrs['showCostPublicly'] = true;
		}

		return '<!-- wp:post-kinds-indieweb/acquisition-card ' . serialize_block_attributes( $attrs ) . ' -->'
			. '<div class="wp-block-post-kinds-indieweb-acquisition-card acquisition-card layout-horizontal"><div class="post-kinds-card h-cite"><div class="post-kinds-card__content">'
			. '<span class="post-kinds-card__badge">Purchase</span><h3 class="post-kinds-card__title p-name">' . self::TITLE . '</h3>'
			. '<p class="post-kinds-card__subtitle">' . self::COST . '</p>'
			. '<p class="post-kinds-card__meta p-location">from ' . self::WHERE . '</p></div>'
			. '<data class="u-acquired" value="' . self::TITLE . '" hidden></data></div></div>'
			. '<!-- /wp:post-kinds-indieweb/acquisition-card -->';
	}

	/**
	 * A published acquisition with a card, plus the price meta Quick Post writes.
	 *
	 * @param bool   $show_cost Whether the card's toggle is on.
	 * @param bool   $saved     Whether the card is stored editor markup (saved_card()) rather than a bare comment.
	 * @param string $title     Post title.
	 * @return int Post ID.
	 */
	private function acquisition( bool $show_cost, bool $saved = false, string $title = self::TITLE ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => '',
				'post_content' => $saved ? $this->saved_card( $show_cost ) : $this->card( $show_cost ),
			]
		);
		wp_set_object_terms( $id, 'acquisition', 'kind' );
		update_post_meta( $id, self::PRICE_KEY, self::COST );

		return $id;
	}

	/**
	 * The card as its permalink renders it.
	 *
	 * @param int $id Post ID.
	 */
	private function render_card( int $id ): string {
		$this->go_to( get_permalink( $id ) );

		return do_blocks( (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * The post as the REST posts route returns it.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>
	 */
	private function rest_post( int $id ): array {
		$GLOBALS['wp_rest_server'] = null;

		return (array) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) )->get_data();
	}

	/**
	 * The post's feed item content (`content:encoded`), as the RSS2 template prints it.
	 *
	 * @param int $id Post ID.
	 */
	private function feed_content( int $id ): string {
		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed(), 'Feed context did not take.' );

		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $id ) {
				return get_the_content_feed( 'rss2' );
			}
		}

		$this->fail( sprintf( 'Post %d never appeared in the feed loop.', $id ) );
	}

	/**
	 * A whole feed document, as core's feed template prints it.
	 *
	 * @param string $type `rss2` or `atom`.
	 */
	private function feed_document( string $type ): string {
		$this->go_to( '/?feed=' . $type );
		$this->assertTrue( is_feed(), 'Feed context did not take.' );

		ob_start();
		try {
			// The template sends headers after PHPUnit's output, as core's feed tests do.
			@require ABSPATH . WPINC . '/feed-' . $type . '.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} finally {
			$out = (string) ob_get_clean();
		}

		return $out;
	}

	/**
	 * The text ActivityPub's generate_post_summary() starts from for a post
	 * with no excerpt: post_content through sanitize_post_field()'s display
	 * filters, shortcodes and tags stripped, entities decoded (ActivityPub
	 * 9.3.1 includes/functions-post.php:363-378). An Article's summary and
	 * preview come from it.
	 *
	 * @param int $id Post ID.
	 */
	private function activitypub_summary_source( int $id ): string {
		$post    = get_post( $id );
		$content = sanitize_post_field( 'post_content', $post->post_content, $post->ID );

		return html_entity_decode( wp_strip_all_tags( strip_shortcodes( $content ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * ATmosphere's plain-text excerpt for a post with no excerpt: raw
	 * post_content, entities decoded, tags stripped, trimmed to $words
	 * (ATmosphere transformer/class-base.php:228-234 and functions.php:216,
	 * checkout bf8e267). It becomes the document description, the
	 * link-card description and the Bluesky post text.
	 *
	 * @param int $id    Post ID.
	 * @param int $words Word limit.
	 */
	private function atmosphere_excerpt( int $id, int $words ): string {
		$text = wp_strip_all_tags( html_entity_decode( get_post( $id )->post_content, ENT_QUOTES, 'UTF-8' ) );

		return wp_trim_words( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), $words, '...' );
	}

	/**
	 * PKIW's ATmosphere record filters, as the integration registers them.
	 */
	private function register_atmosphere_filters(): void {
		( new Atmosphere_Document_Map() )->register();
	}

	/**
	 * IDs a front-end search returns.
	 *
	 * @param string $search Search string.
	 * @return int[]
	 */
	private function search_ids( string $search ): array {
		$query = new WP_Query(
			[
				's'              => $search,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			]
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Both ways a card sits in post_content.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public function card_markup(): array {
		return [
			'bare comment'  => [ false ],
			'editor markup' => [ true ],
		];
	}

	public function test_the_card_hides_cost_by_default(): void {
		$html = $this->render_card( $this->acquisition( false ) );

		$this->assertStringContainsString( self::TITLE, $html, 'The card itself renders.' );
		$this->assertStringContainsString( self::WHERE, $html, 'Where still prints.' );
		$this->assertStringNotContainsString( self::COST_TEXT, $html );
		$this->assertStringNotContainsString( 'pk-dot', $html, 'No separator is left for a missing cost.' );
	}

	public function test_the_card_shows_cost_with_the_toggle(): void {
		$html = $this->render_card( $this->acquisition( true ) );

		$this->assertMatchesRegularExpression( '#<p class="pk-sub">\s*<span>\$149\.99</span>#', $html );
		$this->assertStringContainsString( self::WHERE, $html );
	}

	public function test_the_single_page_microformats_carry_no_cost_by_default(): void {
		$id = $this->acquisition( false );
		$this->go_to( get_permalink( $id ) );
		$parsed = \Mf2\parse( apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) ) );
		$items  = (string) wp_json_encode( $parsed['items'] );

		$this->assertStringContainsString( 'h-entry', $items, 'Expected a parsed h-entry.' );
		$this->assertStringContainsString( self::TITLE, $items );
		$this->assertStringNotContainsString( self::COST_TEXT, $items );
	}

	public function test_an_editors_request_renders_no_cost_without_the_toggle(): void {
		// Feeds, ActivityPub and ATmosphere can build their text in the
		// author's own request, so the card answers the same for an editor.
		$id = $this->acquisition( false );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->go_to( get_permalink( $id ) );
		$content = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );

		$this->assertStringContainsString( self::TITLE, $content );
		$this->assertStringNotContainsString( self::COST_TEXT, $content );
	}

	public function test_the_card_hides_cost_when_the_post_toggle_is_off(): void {
		// A card left switched on while the post's synced toggle says off
		// (a second card, or content changed without a save) stays private.
		$id = $this->acquisition( true );
		delete_post_meta( $id, self::TOGGLE_KEY );

		$this->assertStringNotContainsString( self::COST_TEXT, $this->render_card( $id ) );
	}

	public function test_the_stream_card_hides_cost_by_default(): void {
		$id = $this->acquisition( false );
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$html = \PKIW\render_stream_card();
		$this->assertStringContainsString( self::TITLE, $html, 'The Stream card itself renders.' );
		$this->assertStringNotContainsString( self::COST_TEXT, $html );
	}

	public function test_the_feed_content_hides_cost_by_default(): void {
		$content = $this->feed_content( $this->acquisition( false ) );

		$this->assertStringContainsString( self::TITLE, $content, 'The card itself is in the feed.' );
		$this->assertStringNotContainsString( self::COST_TEXT, $content );
	}

	public function test_the_feed_content_shows_cost_with_the_toggle(): void {
		$this->assertStringContainsString( self::COST_TEXT, $this->feed_content( $this->acquisition( true ) ) );
	}

	/**
	 * Both feed templates core ships.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function feed_types(): array {
		return [
			'rss2' => [ 'rss2' ],
			'atom' => [ 'atom' ],
		];
	}

	/**
	 * @dataProvider feed_types
	 *
	 * @param string $type Feed type.
	 */
	public function test_a_whole_feed_document_hides_cost_by_default( string $type ): void {
		$this->acquisition( false );

		$doc = $this->feed_document( $type );
		$this->assertStringContainsString( 'k-acquisition', $doc, 'The card is in the feed item.' );
		$this->assertStringNotContainsString( self::COST_TEXT, $doc );
	}

	public function test_rest_hides_cost_from_a_visitor_and_keeps_it_stored(): void {
		$id   = $this->acquisition( false );
		$data = $this->rest_post( $id );

		$this->assertArrayHasKey( self::PRICE_KEY, $data['meta'] );
		$this->assertSame( '', $data['meta'][ self::PRICE_KEY ] );
		$this->assertStringContainsString( self::TITLE, $data['content']['rendered'] );
		$this->assertStringNotContainsString( self::COST_TEXT, $data['content']['rendered'] );

		$this->assertSame( self::COST, get_post_meta( $id, self::PRICE_KEY, true ), 'Stored meta stays intact.' );
		$this->assertStringContainsString( self::COST, (string) get_post_field( 'post_content', $id ), 'The card keeps its cost attribute.' );
	}

	public function test_rest_shows_cost_to_an_editor_without_the_toggle(): void {
		$id = $this->acquisition( false );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( self::COST, $this->rest_post( $id )['meta'][ self::PRICE_KEY ] );
	}

	public function test_rest_shows_cost_to_a_visitor_with_the_toggle(): void {
		$data = $this->rest_post( $this->acquisition( true ) );

		$this->assertSame( self::COST, $data['meta'][ self::PRICE_KEY ] );
		$this->assertStringContainsString( self::COST_TEXT, $data['content']['rendered'] );
	}

	public function test_the_get_post_meta_ability_hides_cost_from_a_subscriber(): void {
		$id = $this->acquisition( false );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'acquisition_price', $result['meta'] );
		$this->assertSame( '', $result['meta']['acquisition_price'] );

		$result = Core_Abilities::instance()->execute_get_post_meta(
			[
				'post_id'   => $id,
				'meta_keys' => [ 'acquisition_price' ],
			]
		);
		$this->assertSame( '', $result['meta']['acquisition_price'], 'Named keys get the same answer.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] );
		$this->assertSame( self::COST, $result['meta']['acquisition_price'], 'An editor gets the stored cost.' );
	}

	public function test_the_toggle_syncs_from_the_card_attribute(): void {
		$id = $this->acquisition( true );
		$this->assertSame( '1', get_post_meta( $id, self::TOGGLE_KEY, true ) );
		$this->assertTrue( Meta_Fields::acquisition_cost_visible( $id ) );

		// Off is the default, so the block comment drops the attribute.
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $this->card( false ),
			]
		);
		$this->assertFalse( metadata_exists( 'post', $id, self::TOGGLE_KEY ), 'Switching the toggle off clears it.' );
		$this->assertFalse( Meta_Fields::acquisition_cost_visible( $id ) );
	}

	public function test_the_toggle_follows_the_acquisition_card_behind_another_card(): void {
		$read = '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Sentinel Book Zq1"} /-->';
		$id   = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $read . $this->card( true ),
			]
		);
		$this->assertTrue( Meta_Fields::acquisition_cost_visible( $id ) );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $read . $this->card( false ),
			]
		);
		$this->assertFalse( Meta_Fields::acquisition_cost_visible( $id ) );
	}

	public function test_cost_is_private_without_a_card_and_for_no_post(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $id, self::PRICE_KEY, self::COST );

		$this->assertFalse( Meta_Fields::acquisition_cost_visible( $id ) );
		$this->assertFalse( Meta_Fields::acquisition_cost_visible( 0 ) );
	}

	public function test_the_toggle_is_not_published_in_rest(): void {
		$data = $this->rest_post( $this->acquisition( true ) );

		$this->assertArrayNotHasKey( self::TOGGLE_KEY, $data['meta'] );
	}

	public function test_stored_editor_markup_stays_out_of_the_card_feed_and_rest(): void {
		$id = $this->acquisition( false, true );

		$this->assertStringNotContainsString( self::COST_TEXT, $this->render_card( $id ) );
		$this->assertStringNotContainsString( self::COST_TEXT, $this->feed_content( $id ) );

		$rendered = $this->rest_post( $id )['content']['rendered'];
		$this->assertStringContainsString( self::TITLE, $rendered );
		$this->assertStringNotContainsString( self::COST_TEXT, $rendered );
	}

	public function test_the_activitypub_summary_source_hides_cost_by_default(): void {
		$text = $this->activitypub_summary_source( $this->acquisition( false, true ) );

		$this->assertStringContainsString( self::TITLE, $text, 'The card text is still there.' );
		$this->assertStringContainsString( self::WHERE, $text );
		$this->assertStringNotContainsString( self::COST_TEXT, $text );
	}

	public function test_the_activitypub_summary_source_hides_cost_from_an_authors_request(): void {
		// ActivityPub can transform the post in the publishing request.
		$id = $this->acquisition( false, true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertStringNotContainsString( self::COST_TEXT, $this->activitypub_summary_source( $id ) );
	}

	public function test_the_activitypub_summary_source_shows_cost_with_the_toggle(): void {
		$this->assertStringContainsString( self::COST_TEXT, $this->activitypub_summary_source( $this->acquisition( true, true ) ) );
	}

	public function test_display_content_keeps_the_stored_cost_attribute(): void {
		// Something that reads display content and writes it back must not lose cost.
		$id      = $this->acquisition( false, true );
		$display = sanitize_post_field( 'post_content', get_post( $id )->post_content, $id );

		$this->assertStringContainsString( '"cost":"' . self::COST . '"', $display );
		$this->assertStringContainsString( '<p class="post-kinds-card__subtitle">' . self::COST . '</p>', get_post( $id )->post_content, 'Stored content is untouched.' );
		$this->assertStringContainsString( self::COST_TEXT, get_post_field( 'post_content', $id, 'edit' ), 'The edit context keeps it.' );
	}

	public function test_the_atmosphere_title_of_an_untitled_card_carries_no_cost(): void {
		$id    = $this->acquisition( false, true, '' );
		$title = Atmosphere_Titles::derive( get_post( $id ) );

		$this->assertStringContainsString( 'Walnut', $title, 'The title comes from the card text.' );
		$this->assertStringNotContainsString( self::COST_TEXT, $title );
	}

	/**
	 * @dataProvider card_markup
	 *
	 * @param bool $saved Whether the card is stored editor markup.
	 */
	public function test_search_cannot_find_a_private_cost( bool $saved ): void {
		$id = $this->acquisition( false, $saved );

		$this->assertContains( $id, $this->search_ids( 'Zq9' ), 'Search finds the post by its title.' );
		$this->assertContains( $id, $this->search_ids( 'Corner Hardware' ), 'Search finds the post by the card text.' );
		foreach ( [ '149.99', '$149.99', 'Zq9 $14', 'Zq9 $149', 'Zq9 149.9' ] as $search ) {
			$this->assertNotContains( $id, $this->search_ids( $search ), "Search for '$search' matched the private cost." );
		}
	}

	/**
	 * @dataProvider card_markup
	 *
	 * @param bool $saved Whether the card is stored editor markup.
	 */
	public function test_an_excluded_search_term_cannot_probe_a_private_cost( bool $saved ): void {
		$id = $this->acquisition( false, $saved );

		$this->assertContains( $id, $this->search_ids( 'Zq9 -148' ) );
		$this->assertContains( $id, $this->search_ids( 'Zq9 -149' ), 'Excluding the cost must not drop the post.' );
		$this->assertNotContains( $id, $this->search_ids( 'Zq9 -Corner' ), 'Exclusion still works on visible text.' );
	}

	public function test_rest_search_cannot_find_a_private_cost(): void {
		$id = $this->acquisition( false, true );

		$GLOBALS['wp_rest_server'] = null;
		$request                   = new WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_param( 'search', '149.99' );
		$this->assertNotContains( $id, array_map( 'intval', wp_list_pluck( (array) rest_do_request( $request )->get_data(), 'id' ) ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'search', '149.99' );
		$this->assertNotContains( $id, array_map( 'intval', wp_list_pluck( (array) rest_do_request( $request )->get_data(), 'id' ) ) );
	}

	public function test_an_editor_can_search_by_cost(): void {
		$id = $this->acquisition( false, true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertContains( $id, $this->search_ids( '149.99' ) );
	}

	public function test_search_finds_a_public_cost(): void {
		$this->assertContains( $this->acquisition( true, true ), $this->search_ids( '149.99' ) );
	}

	public function test_the_atmosphere_document_description_carries_no_cost(): void {
		$id      = $this->acquisition( false, true );
		$excerpt = $this->atmosphere_excerpt( $id, 55 );
		$this->assertStringContainsString( self::COST_TEXT, $excerpt, 'ATmosphere\'s own excerpt holds the cost.' );
		$this->register_atmosphere_filters();

		$record = apply_filters( 'atmosphere_transform_document', [ 'description' => $excerpt ], get_post( $id ) );

		$this->assertStringContainsString( self::WHERE, $record['description'] );
		$this->assertStringNotContainsString( self::COST_TEXT, $record['description'] );
	}

	public function test_the_atmosphere_link_card_carries_no_cost(): void {
		$id = $this->acquisition( false, true );
		$this->register_atmosphere_filters();

		$embed = apply_filters(
			'atmosphere_post_embed',
			[
				'$type'    => 'app.bsky.embed.external',
				'external' => [
					'uri'         => get_permalink( $id ),
					'title'       => self::TITLE,
					'description' => $this->atmosphere_excerpt( $id, 55 ),
				],
			],
			get_post( $id ),
			'link-card'
		);

		$this->assertStringContainsString( self::WHERE, $embed['external']['description'] );
		$this->assertStringNotContainsString( self::COST_TEXT, $embed['external']['description'] );
	}

	public function test_the_atmosphere_post_text_carries_no_cost_and_keeps_its_link(): void {
		$id        = $this->acquisition( false, true );
		$permalink = get_permalink( $id );
		$text      = self::TITLE . "\n\n" . $this->atmosphere_excerpt( $id, 30 ) . "\n\n" . $permalink;
		$start     = strpos( $text, $permalink );
		$this->register_atmosphere_filters();

		$record = apply_filters(
			'atmosphere_transform_bsky_post',
			[
				'$type'  => 'app.bsky.feed.post',
				'text'   => $text,
				'facets' => [
					[
						'index'    => [
							'byteStart' => $start,
							'byteEnd'   => $start + strlen( $permalink ),
						],
						'features' => [
							[
								'$type' => 'app.bsky.richtext.facet#link',
								'uri'   => $permalink,
							],
						],
					],
				],
			],
			get_post( $id ),
			[ 'strategy' => 'link-card' ]
		);

		$this->assertStringNotContainsString( self::COST_TEXT, $record['text'] );
		$this->assertStringContainsString( self::WHERE, $record['text'] );
		$index = $record['facets'][0]['index'];
		$this->assertSame( $permalink, substr( $record['text'], $index['byteStart'], $index['byteEnd'] - $index['byteStart'] ), 'The link facet still points at the permalink.' );
	}

	public function test_the_atmosphere_records_keep_a_public_cost(): void {
		$id      = $this->acquisition( true, true );
		$excerpt = $this->atmosphere_excerpt( $id, 55 );
		$this->register_atmosphere_filters();

		$record = apply_filters( 'atmosphere_transform_document', [ 'description' => $excerpt ], get_post( $id ) );
		$this->assertSame( $excerpt, $record['description'] );
	}
}
