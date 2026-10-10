<?php
/**
 * Coverage for the [pk_stream_card] Stream renderer.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * The Stream card renderer collapses two post shapes — Post Kinds
 * micro-posts and long-form watch articles — into one compact feed item.
 *
 * @group integration
 */
final class StreamCardTest extends WP_UnitTestCase {

	/**
	 * Block every live HTTP request so render_block never hits the network
	 * for an oEmbed lookup.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	/**
	 * Whether this test registered the stand-in play facts reader.
	 *
	 * @var bool
	 */
	private bool $registered_play_reader = false;

	public function tear_down(): void {
		if ( $this->registered_play_reader ) {
			$registered = new ReflectionProperty( \PKIW\Kind_Facts::class, 'registered' );
			$registered->setAccessible( true );
			$readers = $registered->getValue();
			unset( $readers['play'] );
			$registered->setValue( null, $readers );
			$this->registered_play_reader = false;
		}
		parent::tear_down();
	}

	/**
	 * W1-PPLAY registers the play facts reader. Until it merges, a reader
	 * with the same keys (#237 PL5) stands in, and only when none exists.
	 */
	private function ensure_play_reader(): void {
		if ( null !== \PKIW\Kind_Facts::reader( 'play' ) ) {
			return;
		}
		\PKIW\Kind_Facts::register(
			'play',
			static function ( int $post_id ): array {
				$facts = [];
				foreach ( [ 'title', 'game_url', 'bgg_id', 'rawg_id', 'steam_id' ] as $key ) {
					$facts[ $key ] = (string) get_post_meta( $post_id, '_pkiw_play_' . $key, true );
				}
				return $facts;
			}
		);
		$this->registered_play_reader = true;
	}

	/**
	 * A published post of a kind, rendered through the stream-card block.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $args Post arguments.
	 * @return array{0: int, 1: string} Post ID and the Stream card HTML.
	 */
	private function stream_card_for( string $kind, array $args ): array {
		$this->ensure_kind_term( $kind );
		$post_id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish' ], $args ) );
		wp_set_object_terms( $post_id, $kind, 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		return [ $post_id, \PKIW\render_stream_card() ];
	}

	/**
	 * The h-entry the Stream card parses to.
	 *
	 * @param string $html Stream card HTML.
	 * @return array<string, mixed>
	 */
	private function parsed_entry( string $html ): array {
		$items = \Mf2\parse( $html, 'https://example.org/' )['items'];
		$this->assertCount( 1, $items );
		$this->assertContains( 'h-entry', $items[0]['type'] );

		return $items[0];
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function protected_card_attrs(): array {
		return [
			'play' => [
				'play',
				[
					'title'       => 'Locked Box',
					'platform'    => 'Board Game',
					'status'      => 'completed',
					'hoursPlayed' => 2,
					'rating'      => 5,
					'review'      => 'Secret review text.',
					'cover'       => 'https://example.test/covers/locked-box.jpg',
					'gameUrl'     => 'https://example.test/games/locked-box',
					'officialUrl' => 'https://example.test/official/locked-box',
					'purchaseUrl' => 'https://example.test/buy/locked-box',
					'bggId'       => '9990018',
				],
			],
			'read' => [
				'read',
				[
					'bookTitle'  => 'Locked Box',
					'authorName' => 'A. Fictional',
					'coverImage' => 'https://example.test/covers/locked-book.jpg',
					'bookUrl'    => 'https://example.test/books/locked-box',
					'readStatus' => 'finished',
					'rating'     => 5,
					'review'     => 'Secret review text.',
				],
			],
		];
	}

	/**
	 * Each protected card, once with a post title and once without.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function protected_card_posts(): array {
		$sets = [];
		foreach ( $this->protected_card_attrs() as $kind => [ $slug, $attrs ] ) {
			$sets[ $kind ]               = [ $slug, $attrs, 'Locked Box' ];
			$sets[ 'untitled ' . $kind ] = [ $slug, $attrs, '' ];
		}
		return $sets;
	}

	/**
	 * A password-protected card-only post shows its title link and nothing
	 * from the card: no review, links, rating, cover or excerpt (#232 check
	 * gap 3, #237 check gap 2). An untitled one links its kind-and-date name,
	 * which carries no p-name (X8), not core's bare "Protected:" prefix.
	 *
	 * @dataProvider protected_card_posts
	 *
	 * @param array<string, mixed> $attrs      Card attributes.
	 * @param string               $post_title Post title, empty for an untitled post.
	 */
	public function test_a_protected_card_only_post_shows_only_its_title_link( string $kind, array $attrs, string $post_title ): void {
		$this->ensure_play_reader();
		[ $post_id, $html ] = $this->stream_card_for(
			$kind,
			[
				'post_title'    => $post_title,
				'post_password' => 'secret',
				'post_content'  => '<!-- wp:post-kinds-indieweb/' . $kind . '-card ' . wp_json_encode( $attrs ) . ' /-->',
			]
		);

		$this->assertTrue( post_password_required( $post_id ) );
		$this->assertStringNotContainsString( 'k-' . $kind . ' h-cite', $html, 'No card markup.' );
		$this->assertStringNotContainsString( 'Secret review text.', $html );
		$this->assertStringNotContainsString( 'example.test', $html, 'No cover, game, book, official or buy link.' );
		$this->assertStringNotContainsString( 'pk-stars', $html );
		$this->assertStringNotContainsString( 'p-rating', $html );
		$this->assertStringNotContainsString( 'A. Fictional', $html );
		$this->assertStringNotContainsString( 'pk-excerpt', $html );
		$this->assertStringNotContainsString( '<img', $html );

		// The entry's hidden author and date stay; nothing else prints.
		$visible = (string) preg_replace( '#<span class="pk-entry-props" hidden>.*?</span>$#s', '', str_replace( '</article>', '', $html ) );
		$this->assertSame( 1, preg_match_all( '#<a\b[^>]*href="([^"]*)"#', $visible, $links ) );
		$this->assertSame( get_permalink( $post_id ), $links[1][0] );

		$entry = $this->parsed_entry( $html );
		if ( '' === $post_title ) {
			$this->assertStringNotContainsString( 'Protected:', $html );
			$this->assertStringNotContainsString( 'Locked Box', $html, 'The card title is not the post name.' );
			$this->assertSame( \PKIW\untitled_name( get_post( $post_id ), false ), trim( wp_strip_all_tags( $visible ) ) );
			$this->assertStringNotContainsString( 'p-name', $html, 'A synthetic name carries no p-name.' );
			$this->assertNotContains( 'Protected:', $entry['properties']['name'] ?? [] );
			return;
		}

		$this->assertSame( get_the_title( $post_id ), trim( wp_strip_all_tags( $visible ) ) );
		$this->assertSame( [ get_the_title( $post_id ) ], $entry['properties']['name'] );
	}

	/**
	 * A protected long-form play prints no hidden citation either.
	 */
	public function test_a_protected_long_form_play_prints_no_citation(): void {
		$this->ensure_play_reader();
		[ , $html ] = $this->stream_card_for(
			'play',
			[
				'post_title'    => 'Locked Box',
				'post_password' => 'secret',
				'post_content'  => '<!-- wp:post-kinds-indieweb/play-card {"title":"Locked Box","bggId":"9990018"} /-->'
					. '<!-- wp:paragraph --><p>Secret session notes.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertStringNotContainsString( 'Secret session notes.', $html );
		$this->assertStringNotContainsString( 'h-cite', $html );
		$this->assertStringNotContainsString( 'boardgamegeek', $html );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function play_provider_uids(): array {
		return [
			'bgg'   => [ 'bggId', '9990019', 'https://boardgamegeek.com/boardgame/9990019' ],
			'rawg'  => [ 'rawgId', '900010', 'https://rawg.io/games/900010' ],
			'steam' => [ 'steamId', '900002', 'https://store.steampowered.com/app/900002' ],
		];
	}

	/**
	 * A play whose body holds a card and a paragraph renders the generic
	 * card, which still carries the play-of citation with the game's name
	 * and uid (#232 check gap 4, #237 check gap 0).
	 *
	 * @dataProvider play_provider_uids
	 */
	public function test_a_long_form_play_carries_a_play_of_citation_with_name_and_uid( string $attr, string $id, string $uid ): void {
		$this->ensure_play_reader();
		[ , $html ] = $this->stream_card_for(
			'play',
			[
				'post_title'   => 'Game night notes',
				'post_content' => '<!-- wp:post-kinds-indieweb/play-card {"title":"Tide Pool Commons","gameUrl":"https://example.test/games/tide-pool-commons","' . $attr . '":"' . $id . '"} /-->'
					. '<!-- wp:paragraph --><p>We played twice.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertStringContainsString( 'pk-card--stream', $html );
		$cite = $this->parsed_entry( $html )['properties']['play-of'][0];
		$this->assertSame( [ 'h-cite' ], $cite['type'] );
		$this->assertSame( [ 'Tide Pool Commons' ], $cite['properties']['name'] );
		$this->assertSame( [ $uid ], $cite['properties']['uid'] );
		$this->assertSame( [ 'https://example.test/games/tide-pool-commons' ], $cite['properties']['url'] );
		$this->assertSame( 'https://example.test/games/tide-pool-commons', $cite['value'] );
	}

	/**
	 * A read with no card (the meta-only shape, like read 37874) carries a
	 * read-of citation with the book's name and author (#234 check gap 2).
	 */
	public function test_a_meta_only_read_carries_a_read_of_citation_with_name_and_author(): void {
		$this->ensure_kind_term( 'read' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Started a new book',
				'post_content' => '<!-- wp:paragraph --><p>Twenty pages in.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'read', 'kind' );
		update_post_meta( $post_id, '_pkiw_read_title', 'The Quiet Orchard' );
		update_post_meta( $post_id, '_pkiw_read_author', 'A. Fictional' );
		update_post_meta( $post_id, '_pkiw_read_url', 'https://example.test/books/the-quiet-orchard' );
		$GLOBALS['post'] = get_post( $post_id );

		$cite = $this->parsed_entry( \PKIW\render_stream_card() )['properties']['read-of'][0];

		$this->assertSame( [ 'h-cite' ], $cite['type'] );
		$this->assertSame( [ 'The Quiet Orchard' ], $cite['properties']['name'] );
		$this->assertSame( [ 'h-card' ], $cite['properties']['author'][0]['type'] );
		$this->assertSame( [ 'A. Fictional' ], $cite['properties']['author'][0]['properties']['name'] );
		$this->assertSame( [ 'https://example.test/books/the-quiet-orchard' ], $cite['properties']['url'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function citations_with_a_script_url(): array {
		return [
			'play game url' => [ 'play', '_pkiw_play_title', '_pkiw_play_game_url' ],
			'read url'      => [ 'read', '_pkiw_read_title', '_pkiw_read_url' ],
		];
	}

	/**
	 * esc_url() empties a javascript: URL. The citation leaves that u-url
	 * out instead of printing value="", which php-mf2 resolves to the page
	 * URL. The registered sanitizer empties the URL on update_post_meta(),
	 * so the row goes straight to the table, as an import or an older
	 * version could have left it.
	 *
	 * @dataProvider citations_with_a_script_url
	 */
	public function test_a_citation_leaves_out_a_url_that_esc_url_empties( string $kind, string $title_key, string $url_key ): void {
		global $wpdb;
		$this->ensure_play_reader();
		$this->ensure_kind_term( $kind );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Notes with a script link',
				'post_content' => '<!-- wp:paragraph --><p>Some notes.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, $kind, 'kind' );
		update_post_meta( $post_id, $title_key, 'Tide Pool Commons' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Skips the registered sanitizer on purpose.
			$wpdb->postmeta,
			[
				'post_id'    => $post_id,
				'meta_key'   => $url_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value' => 'javascript:alert(1)', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			]
		);
		wp_cache_delete( $post_id, 'post_meta' );
		$this->assertSame( 'javascript:alert(1)', get_metadata_raw( 'post', $post_id, $url_key, true ), 'The script URL is stored as is.' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();
		$cite = $this->parsed_entry( $html )['properties'][ $kind . '-of' ][0];

		$this->assertSame( [ 'Tide Pool Commons' ], $cite['properties']['name'] );
		$this->assertSame( [], $cite['properties']['url'] ?? [], 'php-mf2 reads no URL for the citation.' );
		$this->assertStringNotContainsString( '<data class="u-url" value="">', $html );
	}

	/**
	 * A play or read whose facts come back empty prints no citation.
	 *
	 * @dataProvider kinds_with_a_citation
	 */
	public function test_a_post_with_empty_facts_prints_no_citation( string $kind ): void {
		$this->ensure_play_reader();
		[ , $html ] = $this->stream_card_for(
			$kind,
			[
				'post_title'   => 'Untracked',
				'post_content' => '<!-- wp:paragraph --><p>No card and no stored facts.</p><!-- /wp:paragraph -->',
			]
		);

		$entry = $this->parsed_entry( $html );
		$this->assertArrayNotHasKey( $kind . '-of', $entry['properties'] );
		$this->assertStringNotContainsString( 'h-cite', $html );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function kinds_with_a_citation(): array {
		return [
			'play' => [ 'play' ],
			'read' => [ 'read' ],
		];
	}

	/**
	 * Other kinds join the citation map through its filter (W2 adds reply,
	 * like and bookmark this way).
	 */
	public function test_the_citation_map_takes_new_kinds_through_its_filter(): void {
		$add = static function ( array $map ): array {
			$map['like'] = static fn( array $facts, \WP_Post $post ): string => '<span class="h-cite u-like-of" hidden><data class="p-name" value="' . esc_attr( $post->post_title ) . ' target"></data></span>';
			return $map;
		};
		add_filter( 'pkiw_stream_card_kind_cite', $add );

		[ , $html ] = $this->stream_card_for(
			'like',
			[
				'post_title'   => 'Liked',
				'post_content' => '<!-- wp:paragraph --><p>A note about it.</p><!-- /wp:paragraph -->',
			]
		);
		remove_filter( 'pkiw_stream_card_kind_cite', $add );

		$this->assertSame( [ 'Liked target' ], $this->parsed_entry( $html )['properties']['like-of'][0]['properties']['name'] );
	}

	/**
	 * A linked card title is repointed at the post permalink.
	 */
	public function test_link_title_to_post_repoints_anchor(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Movie' ] );
		$post    = get_post( $post_id );
		$html    = '<h3 class="pk-title p-name"><a class="u-url" href="https://youtu.be/x" target="_blank" rel="noopener">Movie</a></h3>';

		$out = \PKIW\link_title_to_post( $html, $post );

		$this->assertStringContainsString( '<a class="u-url" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">Movie</a>', $out );
		$this->assertStringNotContainsString( 'youtu.be', $out );
	}

	/**
	 * A plain-text card title gets wrapped in a link to the post.
	 */
	public function test_link_title_to_post_wraps_plaintext(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Enola' ] );
		$post    = get_post( $post_id );
		$html    = '<h3 class="pk-title p-name">Enola</h3>';

		$out = \PKIW\link_title_to_post( $html, $post );

		$this->assertStringContainsString( '<a class="u-url" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">Enola</a>', $out );
	}

	public function test_link_title_to_post_preserves_like_target_as_hidden_cite_data(): void {
		$post_id   = self::factory()->post->create( [ 'post_title' => 'A liked page' ] );
		$permalink = esc_url( (string) get_permalink( $post_id ) );
		$target    = 'https://example.com/liked';
		$html      = '<article class="pk-card k-like h-cite u-like-of"><div><h3 class="pk-title p-name"><a class="u-url" href="' . $target . '">A liked page</a></h3></div></article>';

		$out = \PKIW\link_title_to_post( $html, get_post( $post_id ) );

		$this->assertStringContainsString( '<a href="' . $permalink . '">A liked page</a>', $out );
		$this->assertStringNotContainsString( 'class="u-url" href="' . $permalink . '"', $out );
		$this->assertMatchesRegularExpression( '#<data class="u-url" value="' . preg_quote( $target, '#' ) . '" hidden></data><h3#', $out );
		$this->assertStringNotContainsString( 'href="' . $target . '"', $out );
	}

	public function test_link_title_to_post_preserves_all_wish_url_classes(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'A wished-for item' ] );
		$target  = 'https://example.org/wish';
		$html    = '<div><article class="pk-card k-wish h-cite"><div><h2 class="pk-title p-name"><a class="u-url u-wish-of" href="' . $target . '">A wished-for item</a></h2></div></article></div>';

		$out = \PKIW\link_title_to_post( $html, get_post( $post_id ) );

		$this->assertStringContainsString( '<data class="u-url u-wish-of" value="' . $target . '" hidden></data><h2', $out );
	}

	public function test_link_title_to_post_keeps_non_cite_card_behavior(): void {
		$post_id   = self::factory()->post->create( [ 'post_title' => 'Example Cafe' ] );
		$permalink = esc_url( (string) get_permalink( $post_id ) );
		$html      = '<article class="pk-card k-checkin h-entry"><h2 class="pk-title p-name"><a class="u-url" href="https://example.com/cafe">Example Cafe</a></h2></article>';

		$out = \PKIW\link_title_to_post( $html, get_post( $post_id ) );

		$this->assertStringContainsString( '<a class="u-url" href="' . $permalink . '">Example Cafe</a>', $out );
		$this->assertStringNotContainsString( '<data ', $out );
	}

	/**
	 * A body of only card blocks is a micro-post.
	 */
	public function test_card_only_body_is_micro_post(): void {
		$content = '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Enola Holmes 3"} /-->';
		$this->assertTrue( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * A trailing empty paragraph doesn't stop a single-card post from
	 * reading as a micro-post.
	 */
	public function test_card_with_trailing_empty_paragraph_is_micro_post(): void {
		$content = '<!-- wp:group --><div class="wp-block-group">' .
			'<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Enola"} /-->' .
			"</div><!-- /wp:group -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
		$this->assertTrue( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * A non-empty trailing paragraph makes it long-form again.
	 */
	public function test_card_with_real_paragraph_is_not_micro_post(): void {
		$content = '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"X"} /-->' .
			"\n\n<!-- wp:paragraph -->\n<p>Real words.</p>\n<!-- /wp:paragraph -->";
		$this->assertFalse( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * The post date is injected under the card title, before the media.
	 */
	public function test_inject_post_date_into_card(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title' => 'Movie',
				'post_date'  => '2026-07-05 09:00:00',
			]
		);
		$post = get_post( $post_id );
		$html = '<h3 class="pk-title p-name"><a href="#">Movie</a></h3><div class="pk-embed"></div>';

		$out = \PKIW\inject_post_date_into_card( $html, $post );

		$this->assertStringContainsString( 'pk-stream-date', $out );
		$this->assertStringContainsString( '<time class="dt-published"', $out );
		$this->assertLessThan( strpos( $out, 'pk-embed' ), strpos( $out, 'pk-stream-date' ) );
	}

	/**
	 * A card wrapped in a group is still a micro-post.
	 */
	public function test_group_wrapped_card_is_micro_post(): void {
		$content = "<!-- wp:group -->\n<div class=\"wp-block-group\">" .
			'<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Enola Holmes 3","rating":4} /-->' .
			"</div>\n<!-- /wp:group -->";
		$this->assertTrue( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * An article with paragraphs and headings is not a micro-post.
	 */
	public function test_article_body_is_not_micro_post(): void {
		$content = "<!-- wp:heading -->\n<h2>Hi</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Words.</p>\n<!-- /wp:paragraph -->";
		$this->assertFalse( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * Empty content is never a micro-post.
	 */
	public function test_empty_body_is_not_micro_post(): void {
		$this->assertFalse( \PKIW\content_is_kind_card_only( '' ) );
	}

	/**
	 * The Able Player shortcode's youtube-id becomes a watch URL.
	 */
	public function test_extracts_video_from_ableplayer_shortcode(): void {
		$content = '<!-- wp:shortcode -->[ableplayer youtube-id="dQw4w9WgXcQ" youtube-nocookie="true"]<!-- /wp:shortcode -->';
		$this->assertSame(
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			\PKIW\extract_first_video_url( $content )
		);
	}

	/**
	 * A YouTube core/embed block is used when no shortcode is present.
	 */
	public function test_extracts_video_from_core_embed(): void {
		$content = '<!-- wp:embed {"url":"https://youtu.be/dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube"} -->' .
			'<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://youtu.be/dQw4w9WgXcQ</div></figure>' .
			'<!-- /wp:embed -->';
		$this->assertSame( 'https://youtu.be/dQw4w9WgXcQ', \PKIW\extract_first_video_url( $content ) );
	}

	/**
	 * No video in the body returns an empty string.
	 */
	public function test_no_video_returns_empty_string(): void {
		$content = "<!-- wp:paragraph -->\n<p>No video here.</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( '', \PKIW\extract_first_video_url( $content ) );
	}

	/**
	 * A micro-post renders its card, not the fallback link.
	 */
	public function test_micro_post_renders_card(): void {
		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Enola Holmes 3"} /-->' ]
		);
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card', $html );
		$this->assertStringContainsString( 'Enola Holmes 3', $html );
		$this->assertStringNotContainsString( 'pk-stream-fallback', $html );
	}

	/**
	 * The title is a card's only link: the hidden author h-card carries its
	 * name and URL as non-interactive markup that still parses.
	 */
	public function test_card_has_one_link_and_a_linkless_author_h_card(): void {
		$author_id = self::factory()->user->create( [ 'display_name' => 'Courtney Example' ] );
		$post_id   = self::factory()->post->create(
			[
				'post_author'  => $author_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Enola Holmes 3"} /-->',
			]
		);
		$GLOBALS['post'] = get_post( $post_id );

		$html = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );

		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		$this->assertStringContainsString( '<span class="p-author h-card"><span class="p-name">Courtney Example</span><data class="u-url" value="' . esc_url( get_author_posts_url( $author_id ) ) . '"></data>', $html );

		$authors = [];
		$walk    = static function ( array $items ) use ( &$walk, &$authors ): void {
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				foreach ( $item['properties']['author'] ?? [] as $author ) {
					if ( is_array( $author ) ) {
						$authors[] = $author;
					}
				}
				$walk( $item['children'] ?? [] );
				foreach ( $item['properties'] ?? [] as $values ) {
					$walk( is_array( $values ) ? $values : [] );
				}
			}
		};
		$walk( \Mf2\parse( '<div class="h-entry">' . $html . '</div>' )['items'] );

		$this->assertCount( 1, $authors );
		$this->assertSame( [ 'h-card' ], $authors[0]['type'] );
		$this->assertSame( [ 'Courtney Example' ], $authors[0]['properties']['name'] );
		$this->assertSame( [ get_author_posts_url( $author_id ) ], $authors[0]['properties']['url'] );
	}

	/**
	 * A long-form watch post renders a watch card carrying the post title.
	 */
	public function test_long_form_watch_renders_watch_card(): void {
		$this->ensure_kind_term( 'watch' );

		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'This Week in WordPress 374',
				'post_content' => "<!-- wp:paragraph -->\n<p>A long recap.</p>\n<!-- /wp:paragraph -->\n\n" .
					'<!-- wp:shortcode -->[ableplayer youtube-id="dQw4w9WgXcQ"]<!-- /wp:shortcode -->',
			]
		);
		wp_set_object_terms( $post_id, 'watch', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'k-watch', $html );
		$this->assertStringContainsString( 'This Week in WordPress 374', $html );
		// The article prose must not reach the feed.
		$this->assertStringNotContainsString( 'A long recap.', $html );
	}

	/**
	 * A long-form post with no kind renders a compact card with its excerpt —
	 * never the full body, and never the old bare-link fallback.
	 */
	public function test_long_form_non_watch_renders_generic_card(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Just an essay',
				'post_content' => "<!-- wp:paragraph -->\n<p>The full body of the essay.</p>\n<!-- /wp:paragraph -->",
				'post_excerpt' => 'A short summary.',
			]
		);
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card', $html );
		$this->assertStringContainsString( 'pk-card--stream', $html );
		$this->assertStringNotContainsString( 'pk-stream-fallback', $html );
		$this->assertStringContainsString( 'Just an essay', $html );
		// The excerpt shows; the full body never reaches the feed.
		$this->assertStringContainsString( 'A short summary.', $html );
		$this->assertStringContainsString( 'p-summary', $html );
		$this->assertStringNotContainsString( 'The full body of the essay.', $html );
	}

	/**
	 * A kinded long-form post shows its kind label; an unkinded one falls back
	 * to "Note".
	 */
	public function test_generic_card_shows_kind_label(): void {
		$this->ensure_kind_term( 'article' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'A write-up',
				'post_content' => "<!-- wp:paragraph -->\n<p>Words and words.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'article', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-kindlabel', $html );
		$this->assertStringContainsString( 'k-article', $html );
	}

	/**
	 * A no-kind post's card labels as "Note" and carries the k-note class.
	 */
	public function test_generic_card_defaults_to_note_without_kind(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Untagged thought',
				'post_content' => "<!-- wp:paragraph -->\n<p>Just a thought.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'k-note', $html );
		$this->assertStringContainsString( 'Note', $html );
	}

	/**
	 * A featured image rides into the card as a `u-photo`.
	 */
	public function test_generic_card_includes_featured_image(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Illustrated post',
				'post_content' => "<!-- wp:paragraph -->\n<p>Has a picture.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);
		set_post_thumbnail( $post_id, $attachment_id );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-media--stream', $html );
		$this->assertStringContainsString( 'u-photo', $html );
	}

	/**
	 * The featured image's alt text comes from the body's core/image block
	 * when one matches the thumbnail's attachment ID — Outpost and Micropub
	 * uploads leave `_wp_attachment_image_alt` empty, but the real alt text
	 * lives on the block, not the attachment.
	 */
	public function test_generic_card_thumbnail_alt_prefers_matching_image_block(): void {
		$post_id       = self::factory()->post->create( [ 'post_title' => 'Illustrated post' ] );
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);
		// Attachment alt deliberately left empty, matching real uploads.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:image {"id":' . $attachment_id . '} -->'
					. '<figure class="wp-block-image"><img src="canola.jpg" alt="Yellow canola field at sunset" class="wp-image-' . $attachment_id . '"/></figure>'
					. '<!-- /wp:image -->',
			]
		);
		set_post_thumbnail( $post_id, $attachment_id );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'alt="Yellow canola field at sunset"', $html );
	}

	/**
	 * With no matching core/image block, the attachment's own alt meta is
	 * used instead of shipping alt="".
	 */
	public function test_generic_card_thumbnail_alt_falls_back_to_attachment_alt(): void {
		$post_id       = self::factory()->post->create(
			[
				'post_title'   => 'Illustrated post',
				'post_content' => "<!-- wp:paragraph -->\n<p>No image block here.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'A field of canola' );
		set_post_thumbnail( $post_id, $attachment_id );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'alt="A field of canola"', $html );
	}

	/**
	 * With no image block and no attachment alt, the post title is the last
	 * resort — never an empty alt.
	 */
	public function test_generic_card_thumbnail_alt_falls_back_to_post_title(): void {
		$post_id       = self::factory()->post->create(
			[
				'post_title'   => 'Illustrated post',
				'post_content' => "<!-- wp:paragraph -->\n<p>No image block here.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);
		set_post_thumbnail( $post_id, $attachment_id );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'alt="Illustrated post"', $html );
		$this->assertStringNotContainsString( 'alt=""', $html );
	}

	/**
	 * A title-less post still renders a full card — the kind label stands in
	 * as the linked title, without claiming to be the entry's p-name.
	 */
	public function test_long_form_without_title_renders_card_with_label_title(): void {
		$this->ensure_kind_term( 'weather' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => "<!-- wp:paragraph -->\n<p>Sunny, 22°C.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'weather', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card--stream', $html );
		$this->assertStringContainsString( 'k-weather', $html );
		$this->assertStringContainsString( 'pk-title', $html );
		// The synthetic title is navigation, not the entry name.
		$this->assertStringNotContainsString( 'p-name', $html );
		$this->assertStringContainsString( '>Weather<span class="pk-sr-only">, ' . get_the_date( '', $post_id ) . '</span></a>', $html );
	}

	/**
	 * S7: one visible date per Stream card. The "<Kind>, <date>" name
	 * keeps its date in the link name only, since the card prints the
	 * date below the title.
	 */
	public function test_untitled_stream_card_prints_its_date_once(): void {
		$this->ensure_kind_term( 'weather' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => '<!-- wp:paragraph --><p>Post excerpt.</p><!-- /wp:paragraph -->',
				'post_date'    => '2026-10-06 09:00:00',
			]
		);
		wp_set_object_terms( $post_id, 'weather', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertSame( 1, preg_match( '#<h2 class="pk-title"><a class="u-url" href="[^"]+">(.*?)</a></h2>#s', $html, $link ) );
		$this->assertSame( 'Weather, October 6, 2026', wp_strip_all_tags( $link[1] ) );

		$visible = wp_strip_all_tags( (string) preg_replace( '#<span class="pk-sr-only">.*?</span>#s', '', $html ) );
		$this->assertSame( 1, substr_count( $visible, 'October 6, 2026' ) );
		$this->assertStringContainsString( '<time class="dt-published"', $html );
	}

	public function test_untitled_long_form_like_uses_card_name_without_p_name(): void {
		$this->ensure_kind_term( 'like' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/like-card {"title":"A post on example.org","url":"https://example.org/a"} /-->'
					. '<!-- wp:paragraph --><p>A separate paragraph.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'like', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( '>A post on example.org</a>', $html );
		$this->assertStringNotContainsString( 'p-name', $html );
	}

	/**
	 * Micropub stores a nameless reply's title as its URL. The Stream
	 * prints the host, never the path or query, which can carry tokens
	 * (#253, X8).
	 */
	public function test_untitled_long_form_reply_with_url_title_prints_only_the_host(): void {
		$this->ensure_kind_term( 'reply' );
		$url     = 'https://example.com/notes/2026/10/private-thread?token=abc123';
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/reply-card {"title":"' . $url . '","url":"' . $url . '"} /-->'
					. '<!-- wp:paragraph --><p>A fictional reply.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'reply', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertSame( 1, preg_match( '#<h2 class="pk-title"><a class="u-url" href="[^"]+">(.*?)</a></h2>#s', $html, $link ) );
		$this->assertSame( 'example.com', $link[1] );
		$this->assertStringNotContainsString( 'private-thread', $html );
		$this->assertStringNotContainsString( 'token=abc123', $html );
	}

	public function test_untitled_long_form_note_uses_fallback_and_prints_excerpt_once(): void {
		$this->ensure_kind_term( 'note' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => '<!-- wp:paragraph --><p>A fictional thought printed once.</p><!-- /wp:paragraph -->',
				'post_excerpt' => 'A fictional thought printed once.',
				'post_date'    => '2026-09-12 12:00:00',
			]
		);
		wp_set_object_terms( $post_id, 'note', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( '>Note<span class="pk-sr-only">, September 12, 2026</span></a>', $html );
		$this->assertSame( 1, substr_count( $html, 'A fictional thought printed once.' ) );
	}

	/**
	 * A kind term the plugin has never heard of — no card block, no icon, no
	 * default entry — still renders a complete generic card carrying the
	 * standard structure classes the theme styles against.
	 */
	public function test_unknown_kind_renders_generic_card(): void {
		$this->ensure_kind_term( 'zzz-future-kind' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => "<!-- wp:paragraph -->\n<p>From the future.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'zzz-future-kind', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertNotSame( '', $html );
		$this->assertStringContainsString( 'k-zzz-future-kind', $html );
		$this->assertStringContainsString( 'pk-badge', $html );
		$this->assertStringContainsString( 'pk-kindlabel', $html );
		$this->assertStringContainsString( 'pk-title', $html );
		$this->assertStringContainsString( 'pk-stream-date', $html );
	}

	/**
	 * A long-form mood post keeps its mood-card emoji on the generic card,
	 * ahead of the caption so the theme can paint it as the enamel pin.
	 */
	public function test_long_form_mood_renders_mood_card_emoji(): void {
		$this->ensure_kind_term( 'mood' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'A rainy-day reflection',
				'post_content' => '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Melancholy","emoji":"🌧️"} /-->' .
					"\n\n<!-- wp:paragraph -->\n<p>Long thoughts about the rain.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'mood', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card--stream', $html );
		$this->assertStringContainsString( '<span class="pk-mood__emoji" role="img" aria-label="Melancholy">🌧️</span>', $html );
		$this->assertLessThan( strpos( $html, 'pk-caption' ), strpos( $html, 'pk-mood__emoji' ) );
	}

	/**
	 * A mood-card block with no explicit emoji still pins the block's 😊
	 * default to the card.
	 */
	public function test_long_form_mood_without_explicit_emoji_uses_block_default(): void {
		$this->ensure_kind_term( 'mood' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Feeling fine',
				'post_content' => '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Content"} /-->' .
					"\n\n<!-- wp:paragraph -->\n<p>Nothing much to add.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'mood', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( '<span class="pk-mood__emoji" role="img" aria-label="Content">😊</span>', $html );
	}

	/**
	 * A mood post with no mood-card block in the body gets no invented pin.
	 */
	public function test_long_form_mood_without_mood_card_block_has_no_emoji(): void {
		$this->ensure_kind_term( 'mood' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Mood, in prose only',
				'post_content' => "<!-- wp:paragraph -->\n<p>Words about a feeling.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'mood', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'k-mood', $html );
		$this->assertStringNotContainsString( 'pk-mood__emoji', $html );
	}

	/**
	 * Make sure a `kind` term exists so it can be assigned to a post.
	 *
	 * @param string $slug Kind slug.
	 */
	private function ensure_kind_term( string $slug ): void {
		if ( ! term_exists( $slug, 'kind' ) ) {
			wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
		}
	}

	/**
	 * A reply card whose only u-url belongs to the cited object still gets the
	 * entry's own hidden u-url (a nested h-cite must not satisfy the check).
	 */
	public function test_ensure_entry_properties_ignores_cited_u_url(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Reply' ] );
		$post    = get_post( $post_id );
		$html    = '<article class="pk-card k-reply h-entry"><div class="h-cite u-in-reply-to"><a class="u-url" href="https://example.com/other/">Other</a></div><time class="dt-published" datetime="2026-01-01T00:00:00+00:00"></time></article>';

		$out = \PKIW\ensure_entry_properties( $html, $post, true );

		$this->assertStringContainsString( '<data class="u-url" value="' . esc_url( (string) get_permalink( $post_id ) ) . '"', $out );
	}

	/**
	 * An RSVP card whose permalink link sits inside a nested h-event still gets
	 * the entry's own u-url: the h-event owns that link for parsers.
	 */
	public function test_ensure_entry_properties_ignores_u_url_inside_nested_event(): void {
		$post_id   = self::factory()->post->create( [ 'post_title' => 'Town hall' ] );
		$post      = get_post( $post_id );
		$permalink = esc_url( (string) get_permalink( $post_id ) );
		$html      = '<article class="pk-card k-rsvp h-entry"><div class="pk-event p-in-reply-to h-event"><h2 class="pk-title p-name"><a class="u-url" href="' . $permalink . '">Town hall</a></h2></div><div class="pk-meta"><time class="dt-published" datetime="2026-08-04T00:00:00+00:00">RSVPed</time></div></article>';

		$out = \PKIW\ensure_entry_properties( $html, $post, true );

		$this->assertMatchesRegularExpression( '#<span class="pk-entry-props" hidden><data class="u-url" value="' . preg_quote( $permalink, '#' ) . '"#', $out );
		$this->assertStringNotContainsString( '<time class="dt-published" datetime="' . esc_attr( (string) get_post_time( 'c', true, $post ) ) . '" aria-hidden="true">', $out, 'the card already has its own dt-published' );
	}

	/**
	 * A card that already carries its own permalink u-url gets no duplicate.
	 */
	public function test_ensure_entry_properties_keeps_own_u_url(): void {
		$post_id   = self::factory()->post->create( [ 'post_title' => 'Own' ] );
		$post      = get_post( $post_id );
		$permalink = esc_url( (string) get_permalink( $post_id ) );
		$html      = '<article class="pk-card k-note h-entry"><h2 class="pk-title p-name"><a class="u-url" href="' . $permalink . '">Own</a></h2><time class="dt-published" datetime="2026-01-01T00:00:00+00:00"></time></article>';

		$out = \PKIW\ensure_entry_properties( $html, $post, true );

		$this->assertSame( 1, substr_count( $out, 'class="u-url" href="' . $permalink . '"' ) );
	}

	/**
	 * The mood emoji carries an accessible name from the mood label.
	 */
	public function test_mood_card_accessible_name_uses_the_mood_label(): void {
		$content = '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Recharged","emoji":"🔋"} /-->';
		$this->assertSame( 'Recharged', \PKIW\mood_card_accessible_name( $content ) );
		$this->assertSame( 'Mood', \PKIW\mood_card_accessible_name( '<!-- wp:post-kinds-indieweb/mood-card {"emoji":"🔋"} /-->' ) );
	}

	/**
	 * A stream card for a post whose body holds a card and more.
	 *
	 * @return int Post ID.
	 */
	private function long_form_read(): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Piranesi',
				'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Piranesi","bookUrl":"https://example.com/piranesi"} /-->'
					. "\n\n<!-- wp:paragraph -->\n<p>Stayed up for the last hundred pages.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'read', 'kind' );
		return $post_id;
	}

	/**
	 * The generic card is the entry root, so the Query Loop item is not.
	 */
	public function test_generic_card_keeps_the_loop_item_from_being_a_second_entry_root(): void {
		$post_id = $this->long_form_read();
		$this->go_to( get_permalink( $post_id ) );

		$card = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );

		$this->assertMatchesRegularExpression( '/<article\b[^>]*class="[^"]*\bpk-card\b[^"]*\bh-entry\b/', $card );
		$this->assertNotContains( 'h-entry', get_post_class( '', $post_id ) );
	}

	/**
	 * A theme adapter that swaps the generic card for the post's own h-cite
	 * card leaves no rooted article, so the Query Loop item is the entry.
	 */
	public function test_loop_item_is_the_entry_root_when_an_adapter_swaps_in_an_h_cite_card(): void {
		$post_id = $this->long_form_read();
		$this->go_to( get_permalink( $post_id ) );
		$adapter = static function () use ( $post_id ) {
			return \PKIW\link_title_to_post(
				render_block(
					[
						'blockName'    => 'post-kinds-indieweb/read-card',
						'attrs'        => [
							'bookTitle' => 'Piranesi',
							'bookUrl'   => 'https://example.com/piranesi',
						],
						'innerBlocks'  => [],
						'innerHTML'    => '',
						'innerContent' => [],
					]
				),
				get_post( $post_id )
			);
		};
		add_filter( 'render_block_post-kinds-indieweb/stream-card', $adapter, 10 );

		$card = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );

		remove_filter( 'render_block_post-kinds-indieweb/stream-card', $adapter, 10 );

		$this->assertStringContainsString( 'pk-card k-read h-cite u-read-of', $card );
		$this->assertContains( 'h-entry', get_post_class( '', $post_id ) );

		$entry = \Mf2\parse( '<li class="' . esc_attr( implode( ' ', get_post_class( '', $post_id ) ) ) . '">' . $card . '</li>' )['items'][0];
		$this->assertContains( 'h-entry', $entry['type'] );
		$this->assertSame( [ 'Piranesi' ], $entry['properties']['read-of'][0]['properties']['name'] );
		$this->assertSame( [ get_permalink( $post_id ) ], $entry['properties']['url'] );
	}
}
