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

	/**
	 * Kinds whose facts reader a test registered, to unregister afterwards.
	 *
	 * @var string[]
	 */
	private array $registered_readers = [];

	public function tear_down(): void {
		foreach ( $this->registered_readers as $kind ) {
			$registered = new ReflectionProperty( \PKIW\Kind_Facts::class, 'registered' );
			$registered->setAccessible( true );
			$readers = $registered->getValue();
			unset( $readers[ $kind ] );
			$registered->setValue( null, $readers );
		}
		$this->registered_readers = [];
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

	public function test_inject_post_date_into_card_keeps_dt_published_on_an_entry_rooted_card(): void {
		$post = get_post( self::factory()->post->create( [ 'post_date' => '2026-07-05 09:00:00' ] ) );
		$html = '<article class="pk-card k-note h-entry"><h3 class="pk-title p-name"><a href="#">Note</a></h3></article>';

		$this->assertStringContainsString( '<time class="dt-published"', \PKIW\inject_post_date_into_card( $html, $post ) );
	}

	public function test_inject_post_date_into_card_leaves_dt_published_off_a_nested_h_cite(): void {
		$post = get_post( self::factory()->post->create( [ 'post_date' => '2026-07-05 09:00:00' ] ) );
		$html = '<article class="pk-card k-like h-cite u-like-of"><h2 class="pk-title p-name"><a href="#">Liked</a></h2></article>';

		$out = \PKIW\inject_post_date_into_card( $html, $post );

		$this->assertStringContainsString( '<p class="pk-sub pk-stream-date"><time datetime="', $out );
		$this->assertStringNotContainsString( 'dt-published', $out );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function stream_cite_cards_without_their_own_date(): array {
		return [
			'like'  => [ 'like', 'like-of', 'https://notes.example.com/paper-sites' ],
			'reply' => [ 'reply', 'in-reply-to', 'https://notes.example.com/a-reply-target' ],
		];
	}

	/**
	 * F1 on the Stream: the visible date stays, the entry owns dt-published and the cited object doesn't.
	 *
	 * @dataProvider stream_cite_cards_without_their_own_date
	 *
	 * @param string $kind     Kind slug.
	 * @param string $property Citation property.
	 * @param string $url      Stored card URL.
	 */
	public function test_a_cite_card_stream_date_belongs_to_the_entry_not_the_citation( string $kind, string $property, string $url ): void {
		$this->ensure_kind_term( $kind );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_date'    => '2026-10-11 11:09:34',
				'post_content' => sprintf(
					'<!-- wp:post-kinds-indieweb/%s-card {"title":"Field notes on paper sites","url":"%s"} /-->',
					$kind,
					$url
				),
			]
		);
		wp_set_object_terms( $post_id, $kind, 'kind' );
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id );

		$card  = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );
		$items = \Mf2\parse( '<li class="' . esc_attr( implode( ' ', get_post_class( '', $post_id ) ) ) . '">' . $card . '</li>', 'https://example.org/' )['items'];

		$this->assertCount( 1, $items );
		$entry = $items[0];
		$this->assertContains( 'h-entry', $entry['type'] );
		$this->assertSame( [ (string) get_post_time( 'c', true, $post_id ) ], $entry['properties']['published'] );
		$this->assertContains( 'h-cite', $entry['properties'][ $property ][0]['type'] );
		$this->assertArrayNotHasKey( 'published', $entry['properties'][ $property ][0]['properties'] );
		$this->assertSame( [ $url ], $entry['properties'][ $property ][0]['properties']['url'] );
		$this->assertSame( 1, preg_match( '#<p class="pk-sub pk-stream-date"><time datetime="[^"]+">([^<]+)</time></p>#', $card, $date ) );
		$this->assertSame( get_the_date( '', $post_id ), $date[1] );
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
		$this->assertMatchesRegularExpression( '#>Weather<span class="pk-sr-only">, ' . preg_quote( get_the_date( '', $post_id ), '#' ) . ' at [^<]+</span></a>#', $html );
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
		$this->assertSame( 'Weather, October 6, 2026 at 9:00 am', wp_strip_all_tags( $link[1] ) );

		$visible = wp_strip_all_tags( (string) preg_replace( '#<span class="pk-sr-only">.*?</span>#s', '', $html ) );
		$this->assertSame( 1, substr_count( $visible, 'October 6, 2026' ) );
		$this->assertStringContainsString( '<time class="dt-published"', $html );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function named_response_cards(): array {
		return [
			'reply'    => [ 'reply', 'u-in-reply-to' ],
			'like'     => [ 'like', 'u-like-of' ],
			'bookmark' => [ 'bookmark', 'u-bookmark-of' ],
		];
	}

	/**
	 * A reply, like or bookmark whose body holds its own card plus more
	 * renders that card, not the generic one: the card's name is a real
	 * name, so it keeps its p-name, and its title links to the post. The
	 * target URL moves to hidden data. W2 flips the like case from the
	 * generic card, which printed the card name without a p-name.
	 *
	 * @dataProvider named_response_cards
	 *
	 * @param string $kind     Kind slug.
	 * @param string $property Card citation class.
	 */
	public function test_a_long_form_response_with_a_named_card_renders_that_card( string $kind, string $property ): void {
		[ $post_id, $html ] = $this->stream_card_for(
			$kind,
			[
				'post_title'   => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/' . $kind . '-card {"title":"A post on example.org","url":"https://example.org/a?x=1"} /-->'
					. '<!-- wp:paragraph --><p>A separate paragraph.</p><!-- /wp:paragraph -->',
			]
		);
		$permalink = esc_url( (string) get_permalink( $post_id ) );

		$this->assertSame( 1, preg_match( '/class="[^"]*pk-card k-' . $kind . ' h-cite ' . $property . '/', $html ), 'The card block renders.' );
		$this->assertStringContainsString( '<h2 class="pk-title p-name">', $html );
		$this->assertStringContainsString( '<a href="' . $permalink . '">A post on example.org</a>', $html );
		$this->assertStringContainsString( '<data class="u-url" value="https://example.org/a?x=1" hidden></data>', $html );
		$this->assertStringNotContainsString( 'href="https://example.org/a', $html, 'The target is no visible link.' );
		$this->assertStringNotContainsString( 'A separate paragraph.', $html, 'The body stays out of the Stream.' );
		$this->assertStringNotContainsString( 'pk-excerpt', $html );
		$this->assertStringNotContainsString( 'Read more', $html );
	}

	/**
	 * The card route follows the Stream's heading level like the card-only one.
	 */
	public function test_a_long_form_response_card_takes_the_stream_heading_level(): void {
		$this->ensure_kind_term( 'like' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/like-card {"title":"A post on example.org","url":"https://example.org/a"} /-->'
					. '<!-- wp:paragraph --><p>A separate paragraph.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'like', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card_inner( [ 'headingLevel' => 3 ] );

		$this->assertStringContainsString( '<h3 class="pk-title p-name">', $html );
		$this->assertStringNotContainsString( '<h2 class="pk-title', $html );
	}

	/**
	 * A card that names nothing usable falls through to the generic card,
	 * whose host name never shows a path or query: no title, or a title
	 * that is the URL, is no name (X8).
	 *
	 * @dataProvider named_response_cards
	 *
	 * @param string $kind Kind slug.
	 */
	public function test_a_long_form_response_card_with_no_usable_title_keeps_the_generic_card( string $kind ): void {
		[ , $html ] = $this->stream_card_for(
			$kind,
			[
				'post_title'   => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/' . $kind . '-card {"url":"https://example.org/private?token=abc123"} /-->'
					. '<!-- wp:paragraph --><p>A separate paragraph.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertStringContainsString( 'pk-card--stream', $html );
		$this->assertStringContainsString( '>example.org</a>', $html );
		// G5: the token sits only in the hidden citation's data value. The
		// bookmark card has no map in W2 (the bookmark archive lane adds its
		// own), so a bookmark post has no stored target and no citation yet.
		if ( 'bookmark' !== $kind ) {
			$this->assertStringContainsString( '<data class="u-url" value="https://example.org/private?token=abc123"></data>', $html );
		}
		$visible = (string) preg_replace( '#<span class="pk-kind-cite[^>]*hidden>.*?</span>#s', '', $html );
		$this->assertStringNotContainsString( 'token=abc123', $visible );
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
		// G5: the hidden u-in-reply-to keeps the full URL, query included, in
		// its data value. Nowhere else on the card shows the path or token.
		$this->assertStringContainsString( '<data class="u-url" value="' . $url . '"></data>', $html );
		$visible = (string) preg_replace( '#<span class="pk-kind-cite[^>]*hidden>.*?</span>#s', '', $html );
		$this->assertStringNotContainsString( 'private-thread', $visible );
		$this->assertStringNotContainsString( 'token=abc123', $visible );
	}

	/**
	 * A like whose post title and card title are both its URL (F7) prints
	 * the host with no p-name, like an untitled one. The hidden like-of
	 * keeps the full URL (issue 254, X8).
	 */
	public function test_a_like_titled_with_its_url_prints_only_the_host(): void {
		$this->ensure_kind_term( 'like' );
		$url     = 'https://zine.example.org/issue-12';
		$post_id = self::factory()->post->create(
			[
				'post_title'   => $url,
				'post_content' => '<!-- wp:post-kinds-indieweb/like-card {"title":"' . $url . '","url":"' . $url . '"} /-->'
					. '<!-- wp:paragraph --><p>A fictional like.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'like', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertSame( 1, preg_match( '#<h2 class="pk-title"><a class="u-url" href="[^"]+">(.*?)</a></h2>#s', $html, $link ) );
		$this->assertSame( 'zine.example.org', $link[1] );
		$this->assertStringNotContainsString( 'p-name', $link[0] );
		$this->assertStringContainsString( '<data class="u-url" value="' . $url . '"></data>', $html );
		$visible = (string) preg_replace( '#<span class="pk-kind-cite[^>]*hidden>.*?</span>#s', '', $html );
		$this->assertStringNotContainsString( 'issue-12', $visible );
	}

	/**
	 * A titled article whose title happens to be a URL keeps it as its name:
	 * only response kinds treat a URL title as synthetic.
	 */
	public function test_a_note_titled_with_a_url_keeps_its_title_as_p_name(): void {
		$this->ensure_kind_term( 'note' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'https://example.org/a-page',
				'post_content' => '<!-- wp:paragraph --><p>A fictional note.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'note', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( '<h2 class="pk-title p-name"><a class="u-url" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">https://example.org/a-page</a></h2>', $html );
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
	 * Register a stand-in facts reader for a kind, unregistered in tear_down().
	 *
	 * @param string   $kind   Kind slug.
	 * @param callable $reader Takes a post ID, returns facts.
	 */
	private function register_test_reader( string $kind, callable $reader ): void {
		\PKIW\Kind_Facts::register( $kind, $reader );
		$this->registered_readers[] = $kind;
	}

	/**
	 * Where a reply, like or bookmark with no card keeps its target.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function response_meta_shapes(): array {
		return [
			'reply, cite url'     => [ 'reply', 'in-reply-to', '_pkiw_cite_url' ],
			'reply, in_reply_to'  => [ 'reply', 'in-reply-to', '_pkiw_in_reply_to' ],
			'like, cite url'      => [ 'like', 'like-of', '_pkiw_cite_url' ],
			'like, like_of'       => [ 'like', 'like-of', '_pkiw_like_of' ],
			'bookmark, cite url'  => [ 'bookmark', 'bookmark-of', '_pkiw_cite_url' ],
			'bookmark, bookmark_of' => [ 'bookmark', 'bookmark-of', '_pkiw_bookmark_of' ],
		];
	}

	/**
	 * A reply, like or bookmark with no card (the Quick Post shape) renders
	 * the generic card, which carries the hidden citation with the full
	 * target URL, query string included (G5), and the name and author the
	 * post stores.
	 *
	 * @dataProvider response_meta_shapes
	 *
	 * @param string $kind     Kind slug.
	 * @param string $property php-mf2 property name.
	 * @param string $url_key  Meta key holding the target.
	 */
	public function test_a_meta_only_response_carries_its_citation( string $kind, string $property, string $url_key ): void {
		$this->ensure_kind_term( $kind );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Thinking about threads',
				'post_content' => '<!-- wp:paragraph --><p>My own words.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, $kind, 'kind' );
		update_post_meta( $post_id, $url_key, 'https://remote.example.org/thread?page=2' );
		update_post_meta( $post_id, '_pkiw_cite_name', 'A remote thread' );
		update_post_meta( $post_id, '_pkiw_cite_author', 'Remote Author' );
		$GLOBALS['post'] = get_post( $post_id );

		$cite = $this->parsed_entry( \PKIW\render_stream_card() )['properties'][ $property ][0];

		$this->assertSame( [ 'h-cite' ], $cite['type'] );
		$this->assertSame( [ 'https://remote.example.org/thread?page=2' ], $cite['properties']['url'] );
		$this->assertSame( 'https://remote.example.org/thread?page=2', $cite['value'] );
		$this->assertSame( [ 'A remote thread' ], $cite['properties']['name'] );
		$this->assertSame( [ 'h-card' ], $cite['properties']['author'][0]['type'] );
		$this->assertSame( [ 'Remote Author' ], $cite['properties']['author'][0]['properties']['name'] );
	}

	/**
	 * With the target alone there is a URL and no invented name or author.
	 *
	 * @dataProvider response_meta_shapes
	 *
	 * @param string $kind     Kind slug.
	 * @param string $property php-mf2 property name.
	 * @param string $url_key  Meta key holding the target.
	 */
	public function test_a_meta_only_response_with_just_a_target_prints_its_url( string $kind, string $property, string $url_key ): void {
		$this->ensure_kind_term( $kind );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Thinking about threads',
				'post_content' => '<!-- wp:paragraph --><p>My own words.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, $kind, 'kind' );
		update_post_meta( $post_id, $url_key, 'https://remote.example.org/thread' );
		$GLOBALS['post'] = get_post( $post_id );

		$cite = $this->parsed_entry( \PKIW\render_stream_card() )['properties'][ $property ][0];

		$this->assertSame( [ 'https://remote.example.org/thread' ], $cite['properties']['url'] );
		$this->assertArrayNotHasKey( 'author', $cite['properties'] );
	}

	/**
	 * Micropub stores title = url for a nameless response. The hidden
	 * citation leaves that out as a p-name rather than naming the target by
	 * its address.
	 */
	public function test_a_meta_only_response_leaves_a_url_cite_name_out_of_p_name(): void {
		$url = 'https://remote.example.org/thread?page=2';
		$this->ensure_kind_term( 'reply' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'A reply',
				'post_content' => '<!-- wp:paragraph --><p>My own words.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'reply', 'kind' );
		update_post_meta( $post_id, '_pkiw_cite_url', $url );
		update_post_meta( $post_id, '_pkiw_cite_name', $url );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( '<data class="u-url" value="' . $url . '"></data>', $html );
		$this->assertStringNotContainsString( '<data class="p-name" value="' . $url . '"', $html );
	}

	/**
	 * A response with no stored target prints no citation.
	 *
	 * @dataProvider named_response_cards
	 *
	 * @param string $kind Kind slug.
	 */
	public function test_a_response_with_no_facts_prints_no_citation( string $kind ): void {
		[ , $html ] = $this->stream_card_for(
			$kind,
			[
				'post_title'   => 'Nothing cited',
				'post_content' => '<!-- wp:paragraph --><p>No card and no stored target.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertStringNotContainsString( 'h-cite', $html );
		$this->assertStringNotContainsString( 'pk-kind-cite', $html );
	}

	/**
	 * A registered facts reader supplies the citation, read through the
	 * contract keys url, cited_title and author; the raw meta fallback is
	 * for kinds with no reader yet.
	 */
	public function test_a_registered_reader_supplies_the_citation_through_the_contract_keys(): void {
		$this->register_test_reader(
			'reply',
			static fn( int $post_id ): array => [
				'url'         => 'https://reader.example.org/thread?page=2',
				'host'        => 'reader.example.org',
				'cited_title' => 'From the reader',
				'author'      => 'Reader Author',
				'summary'     => 'Ignored here.',
				'picture'     => [],
			]
		);
		$this->ensure_kind_term( 'reply' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'A reply',
				'post_content' => '<!-- wp:paragraph --><p>My own words.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'reply', 'kind' );
		update_post_meta( $post_id, '_pkiw_cite_url', 'https://stored.example.org/other' );
		update_post_meta( $post_id, '_pkiw_cite_name', 'From the meta' );
		$GLOBALS['post'] = get_post( $post_id );

		$cite = $this->parsed_entry( \PKIW\render_stream_card() )['properties']['in-reply-to'][0];

		$this->assertSame( [ 'https://reader.example.org/thread?page=2' ], $cite['properties']['url'] );
		$this->assertSame( [ 'From the reader' ], $cite['properties']['name'] );
		$this->assertSame( [ 'Reader Author' ], $cite['properties']['author'][0]['properties']['name'] );
	}

	/**
	 * A reader that returns no facts (its post can't be shown) leaves the
	 * citation empty; the raw meta fallback must not step in behind it.
	 */
	public function test_a_registered_reader_with_no_facts_leaves_no_citation(): void {
		$this->register_test_reader( 'like', static fn( int $post_id ): array => [] );
		$this->ensure_kind_term( 'like' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'A like',
				'post_content' => '<!-- wp:paragraph --><p>My own words.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'like', 'kind' );
		update_post_meta( $post_id, '_pkiw_cite_url', 'https://stored.example.org/other' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringNotContainsString( 'stored.example.org', $html );
		$this->assertStringNotContainsString( 'h-cite', $html );
	}

	/**
	 * A protected response prints only its title link, card or no card:
	 * no target, no citation, no body.
	 *
	 * @dataProvider named_response_cards
	 *
	 * @param string $kind Kind slug.
	 */
	public function test_a_protected_response_prints_only_the_title_link( string $kind ): void {
		[ $post_id, $html ] = $this->stream_card_for(
			$kind,
			[
				'post_title'    => 'Locked response',
				'post_password' => 'secret',
				'post_content'  => '<!-- wp:post-kinds-indieweb/' . $kind . '-card {"title":"Secret target","url":"https://secret.example.org/a"} /-->'
					. '<!-- wp:paragraph --><p>Secret words.</p><!-- /wp:paragraph -->',
				'meta_input'    => [ '_pkiw_cite_url' => 'https://secret.example.org/a' ],
			]
		);

		$this->assertStringNotContainsString( 'secret.example.org', $html );
		$this->assertStringNotContainsString( 'Secret target', $html );
		$this->assertStringNotContainsString( 'Secret words.', $html );
		$this->assertStringNotContainsString( 'h-cite', $html );
		$this->assertSame( 1, preg_match_all( '#<a\b[^>]*href="([^"]*)"#', $html, $links ) );
		$this->assertSame( get_permalink( $post_id ), $links[1][0] );
	}

	/**
	 * A review always takes the generic card, even when its body is only a
	 * star-rating block, which would otherwise read as a card-only post.
	 */
	public function test_a_review_always_takes_the_generic_card(): void {
		[ $post_id, $html ] = $this->stream_card_for(
			'review',
			[
				'post_title'   => 'Fictional Kettle',
				'post_content' => '<!-- wp:post-kinds-indieweb/star-rating {"rating":4,"itemName":"Fictional Kettle","itemUrl":"https://shop.example.com/kettle"} /-->',
			]
		);

		$this->assertStringContainsString( 'pk-card--stream', $html );
		$this->assertStringContainsString( 'k-review', $html );
		$this->assertStringContainsString( 'pk-stream-date', $html );
		$this->assertStringContainsString( '<a class="u-url" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">Fictional Kettle</a>', $html );
	}

	/**
	 * Every link on a review card points at the review's permalink: the
	 * reviewed item's URL is not a link there.
	 */
	public function test_every_link_on_a_review_card_points_at_the_permalink(): void {
		[ $post_id, $html ] = $this->stream_card_for(
			'review',
			[
				'post_title'   => 'Fictional Kettle',
				'post_content' => '<!-- wp:post-kinds-indieweb/star-rating {"rating":4,"itemName":"Fictional Kettle","itemUrl":"https://shop.example.com/kettle"} /-->'
					. '<!-- wp:paragraph --><p>See <a href="https://elsewhere.example/x">this</a> too.</p><!-- /wp:paragraph -->',
				'meta_input'   => [ '_pkiw_review_item_url' => 'https://shop.example.com/kettle' ],
			]
		);

		$this->assertGreaterThan( 0, preg_match_all( '#<a\b[^>]*href="([^"]*)"#', $html, $links ) );
		$this->assertSame( [ get_permalink( $post_id ) ], array_values( array_unique( $links[1] ) ) );
		$this->assertStringNotContainsString( 'shop.example.com', $html );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function generic_card_kinds(): array {
		return [
			'review' => [ 'review' ],
			'note'   => [ 'note' ],
		];
	}

	/**
	 * pkiw_stream_card_after_date prints right after the date paragraph of
	 * the generic card. It receives '' and the post.
	 *
	 * @dataProvider generic_card_kinds
	 *
	 * @param string $kind Kind slug.
	 */
	public function test_the_after_date_filter_prints_after_the_date_paragraph( string $kind ): void {
		$seen  = [];
		$probe = static function ( string $html, \WP_Post $post ) use ( &$seen ): string {
			$seen[] = [ $html, $post->ID ];
			return $html . '<p class="after-date-probe">Probe</p>';
		};
		add_filter( 'pkiw_stream_card_after_date', $probe, 10, 2 );

		[ $post_id, $html ] = $this->stream_card_for(
			$kind,
			[
				'post_title'   => 'Fictional Kettle',
				'post_content' => '<!-- wp:paragraph --><p>Some words.</p><!-- /wp:paragraph -->',
			]
		);
		remove_filter( 'pkiw_stream_card_after_date', $probe, 10 );

		$this->assertSame( [ [ '', $post_id ] ], $seen );
		$this->assertSame( 1, substr_count( $html, 'after-date-probe' ) );
		$this->assertStringContainsString( '</time></p><p class="after-date-probe">Probe</p>', $html );
		$this->assertLessThan( strpos( $html, 'pk-excerpt' ), strpos( $html, 'after-date-probe' ) );
	}

	/**
	 * With nothing hooked the generic card is unchanged.
	 */
	public function test_the_after_date_filter_prints_nothing_by_default(): void {
		[ , $html ] = $this->stream_card_for(
			'review',
			[
				'post_title'   => 'Fictional Kettle',
				'post_content' => '<!-- wp:paragraph --><p>Some words.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertStringContainsString( '</time></p></div>', $html );
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

	// ------------------------------------------------------------------
	// W3-P0C: photo cards, favorite and repost citations, the article cut.
	// ------------------------------------------------------------------

	/**
	 * XPath over a card fragment.
	 *
	 * @param string $html Card HTML.
	 * @return DOMXPath
	 */
	private function card_xpath( string $html ): DOMXPath {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="card-scope">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $doc );
	}

	/**
	 * Rendered anchors: a[href] with no [hidden] on it or any ancestor (W3-G2).
	 *
	 * @param string $html            Card HTML.
	 * @param bool   $outside_caption Skip anchors inside the hero caption.
	 * @return int
	 */
	private function rendered_anchor_count( string $html, bool $outside_caption = false ): int {
		$query = '//a[@href][not(ancestor-or-self::*[@hidden])]';
		if ( $outside_caption ) {
			$query .= '[not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " pk-media__caption ")])]';
		}

		return $this->card_xpath( $html )->query( $query )->length;
	}

	/**
	 * Anchors with tabindex -1 that are not hidden (W3-G2 needs none).
	 *
	 * @param string $html Card HTML.
	 * @return int
	 */
	private function visible_negative_tabindex_count( string $html ): int {
		return $this->card_xpath( $html )->query( '//a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]' )->length;
	}

	/**
	 * A real image attachment (sub-sizes written) on a post.
	 *
	 * @param int    $parent  Parent post ID.
	 * @param string $alt     Stored alt.
	 * @param string $caption Stored caption.
	 * @return int Attachment ID.
	 */
	private function upload_image( int $parent, string $alt = '', string $caption = '' ): int {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $parent );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		if ( '' !== $caption ) {
			wp_update_post(
				[
					'ID'           => $id,
					'post_excerpt' => $caption,
				]
			);
		}

		return $id;
	}

	/**
	 * A published photo post whose body holds one image block.
	 *
	 * @param string               $title Post title ('' for an untitled photo).
	 * @param array<string, mixed> $args  Extra post arguments.
	 * @param string               $alt   Alt in the image block.
	 * @param string               $cap   Figcaption HTML in the image block.
	 * @return array{0: int, 1: int} Post ID and attachment ID.
	 */
	private function photo_post_with_image( string $title, array $args = [], string $alt = 'Stored alt', string $cap = '' ): array {
		$this->ensure_kind_term( 'photo' );
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => '2026-09-12 14:30:00',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'photo', 'kind' );
		$attachment = $this->upload_image( $post_id );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:image {"id":' . $attachment . '} --><figure class="wp-block-image"><img src="' . esc_url( (string) wp_get_attachment_url( $attachment ) ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $attachment . '"/>'
					. ( '' === $cap ? '' : '<figcaption class="wp-element-caption">' . $cap . '</figcaption>' )
					. '</figure><!-- /wp:image -->',
			]
		);
		$GLOBALS['post'] = get_post( $post_id );

		return [ (int) $post_id, $attachment ];
	}

	public function test_a_titled_photo_card_prints_the_title_date_image_and_caption_only(): void {
		[ $post_id, $attachment ] = $this->photo_post_with_image( 'Tide pool at dusk', [], 'Stored alt', 'Low tide <em>again</em>' );
		unset( $GLOBALS['pkiw_stream_card_root_seen'] );

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$this->assertStringStartsWith( '<article class="pk-card pk-card--stream pk-card--photo k-photo h-entry">', $html, 'pk-card comes before h-entry.' );
		$this->assertTrue( ! empty( $GLOBALS['pkiw_stream_card_root_seen'][ $post_id ] ), 'The card registers as the entry root.' );
		$this->assertSame( 'Photo', trim( $xp->query( '//span[contains(@class,"pk-kindlabel")]' )->item( 0 )->textContent ) );

		$link = $xp->query( '//h2[@class="pk-title p-name"]/a[@class="u-url"]' );
		$this->assertSame( 1, $link->length );
		$this->assertSame( 'Tide pool at dusk', $link->item( 0 )->textContent );
		$this->assertSame( get_permalink( $post_id ), $link->item( 0 )->getAttribute( 'href' ) );

		$this->assertSame( 1, $xp->query( '//p[contains(@class,"pk-stream-date")]/time[@class="dt-published"]' )->length );
		$this->assertSame( 1, $xp->query( '//time[contains(@class,"dt-published")]' )->length );

		$img = $xp->query( '//div[contains(@class,"pk-media") and contains(@class,"pk-media--stream")]/img[contains(@class,"u-photo")]' );
		$this->assertSame( 1, $img->length );
		$this->assertSame( '', $img->item( 0 )->getAttribute( 'alt' ), 'The title names a titled photo, so the image is decorative.' );
		$this->assertSame( 'lazy', $img->item( 0 )->getAttribute( 'loading' ) );
		$this->assertSame( wp_get_attachment_image_url( $attachment, 'medium' ), $img->item( 0 )->getAttribute( 'src' ), 'The medium size, unfiltered.' );

		$caption = $xp->query( '//p[contains(@class,"pk-media__caption")]' );
		$this->assertSame( 1, $caption->length );
		$this->assertFalse( $caption->item( 0 )->hasAttribute( 'aria-live' ) );
		$this->assertStringContainsString( 'Low tide <em>again</em>', $html );

		foreach ( [ 'pk-badge', 'pk-meta', 'pk-media__thumbs', 'pk-media__thumb-more-link', 'pk-excerpt', 'Read more' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html, $absent . ' never prints on a photo card.' );
		}
		$this->assertSame( 1, $this->rendered_anchor_count( $html, true ), 'One link outside the caption: the title.' );
		$this->assertSame( 0, $this->visible_negative_tabindex_count( $html ) );
	}

	public function test_a_photo_card_keeps_a_caption_link_and_prints_one_other_link(): void {
		$caption_html = 'Photo by <a href="https://example.org/credit">Ana</a> at <em>dusk</em>';
		[ $post_id ] = $this->photo_post_with_image( 'Tide pool at dusk', [], 'Stored alt', $caption_html );

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$this->assertSame( 1, $this->rendered_anchor_count( $html, true ), 'One link outside [hidden] and outside the caption: the title.' );
		$this->assertSame( 1, $xp->query( '//h2[@class="pk-title p-name"]/a[@class="u-url"]' )->length );
		$caption = $xp->query( '//p[contains(@class,"pk-media__caption")]' );
		$this->assertSame( 1, $caption->length );
		$this->assertSame( 1, $xp->query( '//p[contains(@class,"pk-media__caption")]//a[@href="https://example.org/credit"]' )->length, 'The caption link survives (G10 ruling, 2026-10-15).' );
		$this->assertSame( 'Photo by Ana at dusk', $caption->item( 0 )->textContent );
		$this->assertSame( 1, $xp->query( '//p[contains(@class,"pk-media__caption")]/em[text()="dusk"]' )->length );

		[ $untitled_post_id ] = $this->photo_post_with_image( '', [], 'Stored alt', $caption_html );

		$untitled_html = \PKIW\render_stream_card();
		$untitled_xp   = $this->card_xpath( $untitled_html );

		$this->assertSame( 1, $this->rendered_anchor_count( $untitled_html, true ) );
		$this->assertSame( 1, $untitled_xp->query( '//p[contains(@class,"pk-media__caption")]//a[@href="https://example.org/credit"]' )->length );
		$this->assertSame( 1, $untitled_xp->query( '//h2[@class="pk-title"]/a[@class="u-url"]' )->length );
		$this->assertSame( get_permalink( $post_id ), $xp->query( '//h2[@class="pk-title p-name"]/a[@class="u-url"]' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( get_permalink( $untitled_post_id ), $untitled_xp->query( '//h2[@class="pk-title"]/a[@class="u-url"]' )->item( 0 )->getAttribute( 'href' ) );
	}

	public function test_an_untitled_photo_card_names_itself_kind_and_date_with_no_p_name(): void {
		[ $post_id ] = $this->photo_post_with_image( '', [], 'Stored alt' );

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$this->assertSame( 0, $xp->query( '//*[contains(@class,"pk-kindlabel")]' )->length, 'No kind label on an untitled photo.' );
		$this->assertStringNotContainsString( 'p-name', $html, 'A synthetic name is no p-name.' );
		$heading = $xp->query( '//h2[@class="pk-title"]/a[@class="u-url"]' );
		$this->assertSame( 1, $heading->length );
		$this->assertSame( get_permalink( $post_id ), $heading->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 'Photo, ' . get_the_date( '', $post_id ), $heading->item( 0 )->textContent );
		$this->assertSame( 1, $xp->query( '//a[@class="u-url"]/time[@class="dt-published"]' )->length, 'The date is the link name, once.' );
		$this->assertSame( 1, $xp->query( '//time[contains(@class,"dt-published")]' )->length );
		$this->assertSame( 'Stored alt', $xp->query( '//img[contains(@class,"u-photo")]' )->item( 0 )->getAttribute( 'alt' ) );
		$this->assertSame( 1, $this->rendered_anchor_count( $html ) );
	}

	public function test_a_photo_card_without_an_image_has_no_media_block(): void {
		$this->ensure_kind_term( 'photo' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Words about a photo',
				'post_content' => '<!-- wp:paragraph --><p>The picture is lost.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'photo', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card--photo', $html );
		$this->assertStringNotContainsString( 'pk-media', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_a_photo_card_with_no_caption_prints_no_caption_element(): void {
		$this->photo_post_with_image( 'Quiet shot' );

		$this->assertStringNotContainsString( 'pk-media__caption', \PKIW\render_stream_card() );
	}

	public function test_a_photo_card_takes_the_stream_heading_level(): void {
		$this->photo_post_with_image( 'Third level' );

		$html = \PKIW\render_stream_card( [ 'headingLevel' => 3 ] );

		$this->assertStringContainsString( '<h3 class="pk-title p-name">', $html );
		$this->assertStringNotContainsString( '<h2', $html );
	}

	public function test_a_photo_card_title_that_names_a_hidden_location_prints_the_fallback(): void {
		[ $post_id ] = $this->photo_post_with_image( 'Checked in at Fictional Cove' );
		update_post_meta( $post_id, '_pkiw_checkin_name', 'Fictional Cove' );
		update_post_meta( $post_id, '_pkiw_geo_privacy', 'private' );
		\PKIW\Title_Privacy::mark_location_title( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringNotContainsString( 'Fictional Cove', $html );
		$this->assertStringContainsString( esc_html( \PKIW\Title_Privacy::safe_title( $post_id ) ), $html );
	}

	public function test_a_protected_photo_still_takes_the_protected_card(): void {
		[ $post_id ] = $this->photo_post_with_image( 'Locked photo', [ 'post_password' => 'secret' ], 'Secret alt', 'Secret caption' );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card--protected', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'Secret caption', $html );
		$this->assertStringNotContainsString( 'Secret alt', $html );
		$this->assertSame( 1, $this->rendered_anchor_count( $html ) );
		$this->assertStringContainsString( (string) get_permalink( $post_id ), $html );
	}

	public function test_a_photo_stream_card_parses_to_one_h_entry_with_url_published_author_and_photo(): void {
		$author_id = self::factory()->user->create( [ 'display_name' => 'Site Author' ] );
		[ $post_id ] = $this->photo_post_with_image( 'Tide pool at dusk', [ 'post_author' => $author_id ] );

		$html  = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );
		$items = \Mf2\parse( $html, 'https://example.org/' )['items'];

		$this->assertCount( 1, $items );
		$entry = $items[0];
		$this->assertContains( 'h-entry', $entry['type'] );
		$this->assertSame( [ get_permalink( $post_id ) ], $entry['properties']['url'] );
		$this->assertNotEmpty( $entry['properties']['published'] );
		$this->assertSame( [ 'Site Author' ], $entry['properties']['author'][0]['properties']['name'] );
		$this->assertCount( 1, $entry['properties']['photo'] );
		$this->assertSame( [ 'Tide pool at dusk' ], $entry['properties']['name'] );
		$this->assertSame( 1, substr_count( $html, 'pk-entry-props' ), 'The root is the card, so the author rides in one hidden span.' );
	}

	public function test_is_rendering_stream_card_is_true_only_during_a_render(): void {
		$this->assertFalse( \PKIW\is_rendering_stream_card() );

		$seen  = [];
		$probe = static function ( string $html, \WP_Post $post ) use ( &$seen ): string {
			$seen[] = \PKIW\is_rendering_stream_card();
			return $html;
		};
		add_filter( 'pkiw_stream_card_after_date', $probe, 10, 2 );
		$this->stream_card_for(
			'note',
			[
				'post_title'   => 'Flag probe',
				'post_content' => '<!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph -->',
			]
		);
		remove_filter( 'pkiw_stream_card_after_date', $probe, 10 );

		$this->assertSame( [ true ], $seen );
		$this->assertFalse( \PKIW\is_rendering_stream_card() );
	}

	public function test_is_rendering_stream_card_is_false_again_after_a_render_throws(): void {
		$boom = static function (): string {
			throw new \RuntimeException( 'render failed' );
		};
		add_filter( 'pkiw_stream_card_after_date', $boom );

		try {
			$this->stream_card_for(
				'note',
				[
					'post_title'   => 'Throwing probe',
					'post_content' => '<!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph -->',
				]
			);
			$this->fail( 'The render should have thrown.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'render failed', $e->getMessage() );
		}
		remove_filter( 'pkiw_stream_card_after_date', $boom );

		$this->assertFalse( \PKIW\is_rendering_stream_card() );
	}

	public function test_is_rendering_stream_card_stays_true_through_a_nested_render(): void {
		$inner_post = self::factory()->post->create( [ 'post_title' => 'Inner', 'post_status' => 'publish' ] );
		$seen       = [];
		$probe      = static function ( string $html, \WP_Post $post ) use ( &$seen, $inner_post ): string {
			if ( $post->ID !== $inner_post ) {
				$GLOBALS['post'] = get_post( $inner_post );
				\PKIW\render_stream_card();
				$GLOBALS['post'] = $post;
			}
			$seen[ $post->ID === $inner_post ? 'inner' : 'outer' ] = \PKIW\is_rendering_stream_card();
			return $html;
		};
		add_filter( 'pkiw_stream_card_after_date', $probe, 10, 2 );
		$this->stream_card_for(
			'note',
			[
				'post_title'   => 'Outer',
				'post_content' => '<!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph -->',
			]
		);
		remove_filter( 'pkiw_stream_card_after_date', $probe, 10 );

		$this->assertSame( [ 'inner' => true, 'outer' => true ], $seen );
		$this->assertFalse( \PKIW\is_rendering_stream_card() );
	}

	/**
	 * Where a favorite or repost with no usable card keeps its target.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function favorite_and_repost_meta_shapes(): array {
		return [
			'favorite, favorite_url' => [ 'favorite', 'favorite-of', '_pkiw_favorite_url' ],
			'favorite, cite url'     => [ 'favorite', 'favorite-of', '_pkiw_cite_url' ],
			'repost, cite url'       => [ 'repost', 'repost-of', '_pkiw_cite_url' ],
			'repost, repost_of'      => [ 'repost', 'repost-of', '_pkiw_repost_of' ],
		];
	}

	/**
	 * A favorite or repost with a URL carries a hidden citation with its
	 * name and author, and the note stays out of the cite (G5).
	 *
	 * @dataProvider favorite_and_repost_meta_shapes
	 *
	 * @param string $kind     Kind slug.
	 * @param string $property php-mf2 property name.
	 * @param string $url_key  Meta key holding the target.
	 */
	public function test_a_favorite_or_repost_with_a_url_carries_its_citation( string $kind, string $property, string $url_key ): void {
		$this->ensure_kind_term( $kind );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'My note on it',
				'post_content' => '<!-- wp:paragraph --><p>My own words.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, $kind, 'kind' );
		update_post_meta( $post_id, $url_key, 'https://remote.example.org/page?x=1' );
		update_post_meta( $post_id, '_pkiw_cite_name', 'A remote page' );
		update_post_meta( $post_id, '_pkiw_cite_author', 'Remote Author' );
		$GLOBALS['post'] = get_post( $post_id );

		$cite = $this->parsed_entry( \PKIW\render_stream_card() )['properties'][ $property ][0];

		$this->assertSame( [ 'h-cite' ], $cite['type'] );
		$this->assertSame( [ 'https://remote.example.org/page?x=1' ], $cite['properties']['url'] );
		$this->assertSame( [ 'A remote page' ], $cite['properties']['name'] );
		$this->assertSame( [ 'Remote Author' ], $cite['properties']['author'][0]['properties']['name'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function favorite_and_repost(): array {
		return [
			'favorite' => [ 'favorite' ],
			'repost'   => [ 'repost' ],
		];
	}

	/**
	 * A name or author with no URL prints no citation: hidden_cite_html()
	 * would otherwise print a URL-less h-cite that parsers read as a bare
	 * property value.
	 *
	 * @dataProvider favorite_and_repost
	 *
	 * @param string $kind Kind slug.
	 */
	public function test_a_favorite_or_repost_with_no_url_prints_no_citation( string $kind ): void {
		[ , $html ] = $this->stream_card_for(
			$kind,
			[
				'post_title'   => 'No target',
				'post_content' => '<!-- wp:paragraph --><p>My own words.</p><!-- /wp:paragraph -->',
				'meta_input'   => [
					'_pkiw_cite_name'   => 'A name with no URL',
					'_pkiw_cite_author' => 'An author with no URL',
				],
			]
		);

		$this->assertStringNotContainsString( 'h-cite', $html );
		$this->assertStringNotContainsString( 'pk-kind-cite', $html );
		$this->assertStringNotContainsString( 'A name with no URL', $html );
	}

	/**
	 * A registered favorite or repost reader supplies the citation through
	 * the contract keys url, cited_title and author; with a name and author
	 * but an empty url it prints nothing.
	 *
	 * @dataProvider favorite_and_repost
	 *
	 * @param string $kind Kind slug.
	 */
	public function test_a_registered_reader_supplies_the_favorite_and_repost_citation( string $kind ): void {
		$facts = [
			'url'         => 'https://reader.example.org/page',
			'host'        => 'reader.example.org',
			'cited_title' => 'From the reader',
			'author'      => 'Reader Author',
			'summary'     => 'Ignored here.',
			'picture'     => [],
		];
		$this->register_test_reader(
			$kind,
			static function ( int $post_id ) use ( &$facts ): array {
				return $facts;
			}
		);
		[ , $html ] = $this->stream_card_for(
			$kind,
			[
				'post_title'   => 'Reader shape',
				'post_content' => '<!-- wp:paragraph --><p>My own words.</p><!-- /wp:paragraph -->',
			]
		);
		$cite = $this->parsed_entry( $html )['properties'][ $kind . '-of' ][0];

		$this->assertSame( [ 'https://reader.example.org/page' ], $cite['properties']['url'] );
		$this->assertSame( [ 'From the reader' ], $cite['properties']['name'] );
		$this->assertSame( [ 'Reader Author' ], $cite['properties']['author'][0]['properties']['name'] );

		$facts['url'] = '';
		[ , $html ]   = $this->stream_card_for(
			$kind,
			[
				'post_title'   => 'Reader shape, no url',
				'post_content' => '<!-- wp:paragraph --><p>My own words.</p><!-- /wp:paragraph -->',
			]
		);
		$this->assertStringNotContainsString( 'h-cite', $html, 'A reader with no URL prints no citation.' );
	}

	public function test_a_repost_with_a_note_parses_repost_of_to_the_source_and_a_url_title_prints_no_p_name(): void {
		$url = 'https://quiet.example.com/essay?token=abc123';
		$this->ensure_kind_term( 'repost' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Worth sharing',
				'post_content' => '<!-- wp:post-kinds-indieweb/repost-card ' . wp_json_encode( [ 'title' => $url, 'url' => $url ], JSON_UNESCAPED_SLASHES ) . ' /-->'
					. '<!-- wp:paragraph --><p>My note about it.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'repost', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();
		$cite = $this->parsed_entry( $html )['properties']['repost-of'][0];

		$this->assertSame( [ $url ], $cite['properties']['url'] );
		$this->assertStringNotContainsString( '<data class="p-name" value="' . $url . '"', $html, 'A title equal to the URL is no name.' );
		$this->assertStringNotContainsString( 'My note about it.', $html, 'The note never prints on the Stream.' );
	}

	public function test_a_protected_favorite_or_repost_prints_only_the_title_link(): void {
		foreach ( [ 'favorite', 'repost' ] as $kind ) {
			[ $post_id, $html ] = $this->stream_card_for(
				$kind,
				[
					'post_title'    => 'Locked ' . $kind,
					'post_password' => 'secret',
					'post_content'  => '<!-- wp:paragraph --><p>Secret words.</p><!-- /wp:paragraph -->',
					'meta_input'    => [
						'_pkiw_cite_url'     => 'https://secret.example.org/a',
						'_pkiw_favorite_url' => 'https://secret.example.org/b',
						'_pkiw_cite_name'    => 'Secret target',
					],
				]
			);

			$this->assertStringNotContainsString( 'secret.example.org', $html );
			$this->assertStringNotContainsString( 'Secret target', $html );
			$this->assertStringNotContainsString( 'h-cite', $html );
			$this->assertSame( 1, $this->rendered_anchor_count( $html ) );
		}
	}

	/**
	 * An article post with a featured image, a caption and attachments.
	 *
	 * @param int    $extra_images Further attached images.
	 * @param string $caption      Hero caption (attachment excerpt).
	 * @param array  $args         Extra post arguments.
	 * @return int Post ID.
	 */
	private function article_post( int $extra_images = 0, string $caption = '', array $args = [] ): int {
		$this->ensure_kind_term( 'article' );
		$no_hero = ! empty( $args['no_hero'] );
		unset( $args['no_hero'] );
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'A long write-up',
					'post_excerpt' => 'The deck for the write-up.',
					'post_content' => '<!-- wp:paragraph --><p>The full body of the write-up.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'article', 'kind' );
		if ( ! $no_hero ) {
			$hero = $this->upload_image( $post_id, 'Hero alt', $caption );
			set_post_thumbnail( $post_id, $hero );
			for ( $n = 0; $n < $extra_images; $n++ ) {
				$this->upload_image( $post_id );
			}
		}
		$GLOBALS['post'] = get_post( $post_id );

		return (int) $post_id;
	}

	public function test_an_article_card_keeps_the_hero_caption_links_included_and_prints_one_other_link(): void {
		$this->article_post( 6, 'Photo by <a href="https://example.org/credit">Ana Example</a>' );

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$this->assertSame( 1, $xp->query( '//p[contains(@class,"pk-media__caption")]' )->length, 'The hero caption survives (G10 corrected).' );
		$this->assertSame( 1, $xp->query( '//p[contains(@class,"pk-media__caption")]//a[@href="https://example.org/credit"]' )->length, 'The caption link survives.' );
		$this->assertSame( 1, $this->rendered_anchor_count( $html, true ), 'One link outside [hidden] and outside the caption: the title.' );
		$this->assertSame( 0, $this->visible_negative_tabindex_count( $html ) );
		foreach ( [ 'pk-meta', 'Read more', 'pk-media__thumbs', 'pk-media__thumb', 'pk-media__thumb-more-link', 'data-pk-full' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html, $absent . ' is cut from the article card.' );
		}
		$this->assertDoesNotMatchRegularExpression( '/\+\d/', wp_strip_all_tags( $html ), 'No +N.' );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public function article_extra_image_counts(): array {
		return [
			'hero only'             => [ 0 ],
			'one extra attachment'  => [ 1 ],
			'six extra attachments' => [ 6 ],
		];
	}

	/**
	 * @dataProvider article_extra_image_counts
	 *
	 * @param int $extra Extra attached images.
	 */
	public function test_an_article_card_has_no_strip_whatever_the_attachment_count( int $extra ): void {
		$this->article_post( $extra, 'A plain caption' );

		$html = \PKIW\render_stream_card();

		$this->assertStringNotContainsString( 'pk-media__thumbs', $html );
		$this->assertStringNotContainsString( 'pk-media__thumb', $html );
		$this->assertStringContainsString( 'A plain caption', $html );
	}

	public function test_an_article_card_keeps_its_badge_first_and_the_dom_order(): void {
		$this->article_post( 2, 'A caption' );

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$this->assertSame( 'pk-badge', $xp->query( '//article/*[1]' )->item( 0 )->getAttribute( 'class' ) );
		$this->assertSame( 0, $xp->query( '//div[@class="pk-badge"]//a' )->length );
		$order = [ 'pk-kindlabel', 'pk-title', 'dt-published', 'pk-media', 'pk-excerpt' ];
		$last  = -1;
		foreach ( $order as $token ) {
			$pos = strpos( $html, $token );
			$this->assertNotFalse( $pos, $token . ' prints.' );
			$this->assertGreaterThan( $last, $pos, $token . ' keeps its place.' );
			$last = $pos;
		}
		$this->assertStringContainsString( 'The deck for the write-up.', $html );
	}

	public function test_an_article_card_without_a_picture_or_deck_prints_no_media_and_no_excerpt(): void {
		$this->article_post( 0, '', [ 'no_hero' => true, 'post_excerpt' => '', 'post_content' => '' ] );

		$html = \PKIW\render_stream_card();

		$this->assertStringNotContainsString( 'pk-media', $html );
		$this->assertStringNotContainsString( 'pk-excerpt', $html );
		$this->assertStringNotContainsString( 'pk-meta', $html );
		$this->assertSame( 1, $this->rendered_anchor_count( $html ) );
	}

	public function test_an_article_card_never_prints_the_body_or_a_reading_time(): void {
		$this->article_post( 0, '', [ 'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'Body words that run on. ', 40 ) . '</p><!-- /wp:paragraph -->' ] );

		$html = \PKIW\render_stream_card();

		$this->assertStringNotContainsString( 'Body words that run on.', $html );
		$this->assertStringContainsString( 'The deck for the write-up.', $html );
		$this->assertStringNotContainsString( 'min read', $html );
	}

	public function test_a_note_card_keeps_read_more_and_thumbnails(): void {
		$this->ensure_kind_term( 'note' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'A long note',
				'post_excerpt' => 'A note deck.',
				'post_content' => '<!-- wp:paragraph --><p>Note body.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'note', 'kind' );
		$hero = $this->upload_image( $post_id, 'Hero alt', 'Note caption' );
		set_post_thumbnail( $post_id, $hero );
		$this->upload_image( $post_id );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-meta', $html );
		$this->assertStringContainsString( 'Read more', $html );
		$this->assertStringContainsString( 'pk-media__thumbs', $html );
		$this->assertStringContainsString( 'Note caption', $html );
	}

	public function test_a_protected_article_still_takes_the_protected_card(): void {
		$this->article_post( 2, 'Secret caption', [ 'post_password' => 'secret' ] );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card--protected', $html );
		$this->assertStringNotContainsString( 'pk-meta', $html );
		$this->assertStringNotContainsString( 'Secret caption', $html );
		$this->assertStringNotContainsString( 'The deck for the write-up.', $html );
	}

	// --- W4-P0C: date-only kinds, the kind render filter and the craft card. ---

	/**
	 * A published post of a kind, with its local publish time set.
	 *
	 * @param string $kind  Kind slug.
	 * @param string $local Local post_date.
	 * @param array  $args  Extra post arguments.
	 * @return \WP_Post
	 */
	private function kind_post_at( string $kind, string $local, array $args = [] ): \WP_Post {
		$this->ensure_kind_term( $kind );
		update_option( 'timezone_string', 'America/New_York' );
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => ucfirst( $kind ) . ' fixture',
					'post_date'    => $local,
					'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, $kind, 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		return get_post( $post_id );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function date_only_kinds(): array {
		return [
			'exercise' => [ 'exercise' ],
			'sleep'    => [ 'sleep' ],
		];
	}

	/**
	 * Exercise and sleep print a date, never a clock time, on the Stream. The
	 * local date is the one the card shows: 22:30 on Aug 23 in New York is
	 * already Aug 24 in UTC.
	 *
	 * @dataProvider date_only_kinds
	 *
	 * @param string $kind Kind slug.
	 */
	public function test_inject_post_date_writes_the_local_date_only_for_exercise_and_sleep( string $kind ): void {
		$post = $this->kind_post_at( $kind, '2026-08-23 22:30:00' );
		$html = '<article class="pk-card"><h2 class="pk-title">x</h2></article>';

		$out = \PKIW\inject_post_date_into_card( $html, $post );

		$this->assertMatchesRegularExpression( '/<time class="dt-published" datetime="2026-08-23">/', $out, 'Local date, no time.' );
		$this->assertDoesNotMatchRegularExpression( '/datetime="[^"]*T/', $out );
		$this->assertStringContainsString( 'August 23, 2026', $out, 'The visible date text doesn\'t change.' );
	}

	public function test_inject_post_date_keeps_the_full_timestamp_for_a_note(): void {
		$post = $this->kind_post_at( 'note', '2026-08-23 22:30:00' );

		$out = \PKIW\inject_post_date_into_card( '<article class="pk-card"><h2 class="pk-title">x</h2></article>', $post );

		$this->assertMatchesRegularExpression( '/datetime="2026-08-24T02:30:00\+00:00"/', $out );
	}

	public function test_the_date_only_kinds_filter_adds_and_removes_kinds(): void {
		$note     = $this->kind_post_at( 'note', '2026-08-23 22:30:00' );
		$exercise = $this->kind_post_at( 'exercise', '2026-08-23 22:30:00' );
		$html     = '<article class="pk-card"><h2 class="pk-title">x</h2></article>';

		$add = static fn( array $kinds ): array => array_merge( $kinds, [ 'note' ] );
		add_filter( 'pkiw_stream_date_only_kinds', $add );
		$this->assertStringContainsString( 'datetime="2026-08-23"', \PKIW\inject_post_date_into_card( $html, $note ) );
		remove_filter( 'pkiw_stream_date_only_kinds', $add );

		add_filter( 'pkiw_stream_date_only_kinds', '__return_empty_array' );
		$this->assertMatchesRegularExpression( '/datetime="2026-08-24T02:30:00\+00:00"/', \PKIW\inject_post_date_into_card( $html, $exercise ) );
		remove_filter( 'pkiw_stream_date_only_kinds', '__return_empty_array' );
	}

	/**
	 * The generic card and the hidden entry date follow the same rule, so a
	 * long exercise post can't print the clock time the card-only path hides.
	 *
	 * @dataProvider date_only_kinds
	 *
	 * @param string $kind Kind slug.
	 */
	public function test_the_generic_card_and_the_hidden_entry_date_are_date_only_too( string $kind ): void {
		$this->kind_post_at( $kind, '2026-08-23 22:30:00' );

		$html = \PKIW\render_stream_card();
		$this->assertMatchesRegularExpression( '/<time class="dt-published" datetime="2026-08-23">/', $html );

		$hidden = \PKIW\ensure_entry_properties( '<article class="pk-card"><h2 class="pk-title">x</h2></article>', get_post(), true );
		$this->assertStringContainsString( '<data class="dt-published" value="2026-08-23"></data>', $hidden );
		foreach ( [ $html, $hidden ] as $out ) {
			$this->assertDoesNotMatchRegularExpression( '/(?:datetime|value)="[^"]*T\d\d:/', $out, 'No clock time anywhere.' );
		}
	}

	public function test_a_note_keeps_the_full_timestamp_in_the_generic_card_and_hidden_entry_date(): void {
		$this->kind_post_at( 'note', '2026-08-23 22:30:00' );

		$this->assertMatchesRegularExpression( '/<time class="dt-published" datetime="2026-08-24T02:30:00\+00:00">/', \PKIW\render_stream_card() );
		$this->assertStringContainsString( 'value="2026-08-24T02:30:00+00:00"', \PKIW\ensure_entry_properties( '<article class="pk-card"><h2 class="pk-title">x</h2></article>', get_post(), true ) );
	}

	public function test_a_card_only_exercise_post_on_the_stream_carries_a_date_only_dt_published(): void {
		$this->kind_post_at( 'exercise', '2026-08-23 22:30:00', [ 'post_content' => '<!-- wp:post-kinds-indieweb/exercise-card {"activity":"Run"} /-->' ] );
		// Stand in for the card's render.php, which a later lane ships.
		$registered = false;
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'post-kinds-indieweb/exercise-card' ) ) {
			register_block_type(
				'post-kinds-indieweb/exercise-card',
				[
					'render_callback' => static fn(): string => '<article class="pk-card k-exercise h-entry"><h2 class="pk-title">Run</h2></article>',
				]
			);
			$registered = true;
		}

		$html = \PKIW\render_stream_card();

		if ( $registered ) {
			unregister_block_type( 'post-kinds-indieweb/exercise-card' );
		}
		$this->assertMatchesRegularExpression( '/<time class="dt-published" datetime="2026-08-23">/', $html );
		$this->assertDoesNotMatchRegularExpression( '/datetime="[^"]*T/', $html );
	}

	// --- pkiw_stream_card_kind_render. ---

	public function test_a_kind_render_callback_that_returns_a_string_replaces_the_card(): void {
		$this->kind_post_at( 'note', '2026-08-23 10:00:00' );
		$seen = [];
		add_filter(
			'pkiw_stream_card_kind_render',
			static function ( $html, $post, $level, $attributes ) use ( &$seen ) {
				$seen[] = [ $post->post_title, $level, $attributes ];
				return '<article class="pk-card k-note h-entry">Kind card</article>';
			},
			10,
			4
		);

		$html = \PKIW\render_stream_card( [ 'headingLevel' => 3 ] );

		$this->assertSame( '<article class="pk-card k-note h-entry">Kind card</article>', $html );
		$this->assertSame( [ [ 'Note fixture', 3, [ 'headingLevel' => 3 ] ] ], $seen, 'It receives the post, the clamped level and the attributes.' );
	}

	public function test_a_kind_render_callback_that_returns_null_falls_through_to_the_card(): void {
		$this->kind_post_at( 'note', '2026-08-23 10:00:00' );
		add_filter( 'pkiw_stream_card_kind_render', '__return_null' );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card--stream', $html );
		$this->assertStringContainsString( 'Note fixture', $html );
	}

	public function test_a_protected_post_never_reaches_the_kind_render_filter(): void {
		$this->kind_post_at( 'note', '2026-08-23 10:00:00', [ 'post_password' => 'secret' ] );
		$called = false;
		add_filter(
			'pkiw_stream_card_kind_render',
			static function ( $html ) use ( &$called ) {
				$called = true;
				return '<p>Kind card</p>';
			}
		);

		$html = \PKIW\render_stream_card();

		$this->assertFalse( $called );
		$this->assertStringContainsString( 'pk-card--protected', $html );
	}

	// --- Craft. ---

	/**
	 * A craft post, card-only unless the arguments say otherwise.
	 *
	 * @param array $args Extra post arguments.
	 * @return int Post ID.
	 */
	private function craft_post( array $args = [] ): int {
		return $this->kind_post_at(
			'craft',
			'2026-08-23 10:00:00',
			array_merge(
				[
					'post_title'   => 'Garter scarf',
					'post_excerpt' => 'A scarf for winter.',
					'post_content' => '<!-- wp:post-kinds-indieweb/craft-card {"craftType":"Knitting","timeMinutes":1080} /-->',
				],
				$args
			)
		)->ID;
	}

	/**
	 * Register a craft facts reader that returns what the Craft_Archive one
	 * will: type, minutes and the ISO and label of the time.
	 *
	 * @param array<string, mixed> $facts Facts to return.
	 */
	private function craft_reader( array $facts ): void {
		$this->register_test_reader( 'craft', static fn(): array => $facts );
	}

	public function test_a_card_only_craft_post_renders_the_generic_card_with_one_link_and_no_card_facts_list(): void {
		$id = $this->craft_post();

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$this->assertSame( 1, $xp->query( '//article[contains(@class,"pk-card") and contains(@class,"k-craft") and contains(@class,"h-entry")]' )->length );
		$link = $xp->query( '//h2[contains(@class,"pk-title")]/a[contains(@class,"u-url")]' );
		$this->assertSame( 1, $link->length );
		$this->assertSame( get_permalink( $id ), $link->item( 0 )->getAttribute( 'href' ) );
		$this->assertStringNotContainsString( 'pkiw-craft-facts', $html, 'The card block\'s own facts list stays off the Stream.' );
		$this->assertSame( 1, $this->rendered_anchor_count( $html ) );
	}

	public function test_a_craft_card_prints_the_type_and_time_after_the_title_and_before_the_picture(): void {
		$this->craft_post();
		$this->craft_reader(
			[
				'craft_type'     => 'Knitting',
				'minutes'        => 1080,
				'duration_iso'   => 'PT18H',
				'duration_label' => '18 hr',
			]
		);
		$id = (int) get_the_ID();
		set_post_thumbnail( $id, $this->upload_image( $id, 'Hero alt' ) );

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$facts = $xp->query( '//p[contains(@class,"pk-craft-facts")]' );
		$this->assertSame( 1, $facts->length );
		$this->assertSame( 'pk-sub pk-craft-facts', $facts->item( 0 )->getAttribute( 'class' ) );
		$this->assertSame( 'Knitting', trim( $xp->query( '//p[contains(@class,"pk-craft-facts")]/span[@class="pk-craft-type"]' )->item( 0 )->textContent ) );
		$this->assertSame( 'Time spent ', $xp->query( '//span[@class="pk-craft-time"]/span[@class="pk-sr-only"]' )->item( 0 )->textContent );
		$time = $xp->query( '//span[@class="pk-craft-time"]/time[@datetime="PT18H"]' );
		$this->assertSame( 1, $time->length );
		$this->assertSame( '18 hr', $time->item( 0 )->textContent );

		$order = [ 'pk-title', 'pk-stream-date', 'pk-craft-facts', 'pk-media' ];
		$last  = -1;
		foreach ( $order as $token ) {
			$pos = strpos( $html, $token );
			$this->assertNotFalse( $pos, $token );
			$this->assertGreaterThan( $last, $pos, $token . ' keeps its place.' );
			$last = $pos;
		}
	}

	public function test_a_craft_card_cuts_the_caption_the_thumbnails_and_the_plus_link_but_keeps_the_picture(): void {
		$id   = $this->craft_post();
		$hero = $this->upload_image( $id, 'Hero alt', 'Photo by <a href="https://example.org/credit">Ana Example</a>' );
		set_post_thumbnail( $id, $hero );
		for ( $n = 0; $n < 5; $n++ ) {
			$this->upload_image( $id, 'Step alt' );
		}

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$this->assertSame( 1, $xp->query( '//div[contains(@class,"pk-media")]//img' )->length, 'The hero stays.' );
		$this->assertSame( 0, $xp->query( '//ul[contains(@class,"pk-media__thumbs")]' )->length );
		$this->assertSame( 0, $xp->query( '//button' )->length );
		$this->assertSame( 0, $xp->query( '//p[contains(@class,"pk-media__caption")]' )->length );
		$this->assertDoesNotMatchRegularExpression( '/\+\d/', wp_strip_all_tags( $html ), 'No +N.' );
		$this->assertSame( 1, $this->rendered_anchor_count( $html ), 'The title is the only link.' );
		$this->assertStringNotContainsString( 'pk-meta', $html );
		$this->assertStringNotContainsString( 'Read more', $html );
	}

	public function test_the_craft_cut_does_not_reach_other_kinds(): void {
		$this->kind_post_at( 'note', '2026-08-23 10:00:00' );
		$id = (int) get_the_ID();
		set_post_thumbnail( $id, $this->upload_image( $id, 'Hero alt', 'Note caption' ) );
		$this->upload_image( $id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-media__thumbs', $html );
		$this->assertStringContainsString( 'Note caption', $html );
		$this->assertStringContainsString( 'Read more', $html );
	}

	public function test_a_craft_card_never_prints_a_status_line_or_a_craft_of_link(): void {
		$this->craft_post();
		$this->craft_reader(
			[
				'craft_type'     => 'Knitting',
				'minutes'        => 90,
				'duration_iso'   => 'PT1H30M',
				'duration_label' => '1 hr 30 min',
			]
		);

		$html = \PKIW\render_stream_card();

		foreach ( [ 'Finished', 'In progress', 'u-craft-of' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html );
		}
	}

	public function test_a_protected_craft_post_takes_the_protected_card(): void {
		$this->craft_post( [ 'post_password' => 'secret' ] );
		$this->craft_reader(
			[
				'craft_type'   => 'Secret craft',
				'duration_iso' => 'PT1H',
			]
		);

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card--protected', $html );
		$this->assertStringNotContainsString( 'Secret craft', $html );
		$this->assertStringNotContainsString( 'pk-craft-facts', $html );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function craft_fact_shapes(): array {
		return [
			'both'              => [
				[
					'craft_type'     => 'Knitting',
					'duration_iso'   => 'PT18H',
					'duration_label' => '18 hr',
					'minutes'        => 1080,
				],
				'<p class="pk-sub pk-craft-facts"><span class="pk-craft-type">Knitting</span> <span class="pk-craft-time"><span class="pk-sr-only">Time spent </span><time datetime="PT18H">18 hr</time></span></p>',
			],
			'type only'         => [
				[ 'craft_type' => 'Sewing' ],
				'<p class="pk-sub pk-craft-facts"><span class="pk-craft-type">Sewing</span></p>',
			],
			'time only'         => [
				[
					'duration_iso'   => 'PT45M',
					'duration_label' => '45 min',
					'minutes'        => 45,
				],
				'<p class="pk-sub pk-craft-facts"><span class="pk-craft-time"><span class="pk-sr-only">Time spent </span><time datetime="PT45M">45 min</time></span></p>',
			],
			'neither'           => [ [], '' ],
			'zero minutes'      => [
				[
					'craft_type' => 'Sewing',
					'minutes'    => 0,
				],
				'<p class="pk-sub pk-craft-facts"><span class="pk-craft-type">Sewing</span></p>',
			],
			'type is escaped'   => [
				[ 'craft_type' => 'Knit <b>&</b>' ],
				'<p class="pk-sub pk-craft-facts"><span class="pk-craft-type">Knit &lt;b&gt;&amp;&lt;/b&gt;</span></p>',
			],
		];
	}

	/**
	 * @dataProvider craft_fact_shapes
	 *
	 * @param array<string, mixed> $facts    Facts from the craft reader.
	 * @param string               $expected Markup.
	 */
	public function test_craft_stream_card_facts_prints_each_fact_only_when_known( array $facts, string $expected ): void {
		$this->assertSame( $expected, \PKIW\craft_stream_card_facts( $facts ) );
	}

	// --- untitled_thought(). ---

	public function test_untitled_thought_is_the_name_an_untitled_note_gets(): void {
		$this->ensure_kind_term( 'note' );
		$long     = implode( ' ', array_fill( 0, 40, 'word' ) );
		$shapes   = [
			'excerpt'             => [ 'post_excerpt' => 'A thought from the excerpt.', 'post_content' => 'Ignored body.' ],
			'content'             => [ 'post_excerpt' => '', 'post_content' => '<!-- wp:paragraph --><p>A thought from the body.</p><!-- /wp:paragraph -->' ],
			'shortcode stripped'  => [ 'post_excerpt' => '', 'post_content' => 'Before [gallery] after.' ],
			'trimmed to 25 words' => [ 'post_excerpt' => '', 'post_content' => $long ],
			'whitespace'          => [ 'post_excerpt' => '', 'post_content' => "Line one\n\n   line   two" ],
		];

		foreach ( $shapes as $label => $args ) {
			$post_id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish', 'post_title' => '' ], $args ) );
			wp_set_object_terms( $post_id, 'note', 'kind' );
			$post = get_post( $post_id );

			$this->assertNotSame( '', \PKIW\untitled_thought( $post ), $label );
			$this->assertSame( \PKIW\untitled_name( $post ), \PKIW\untitled_thought( $post ), $label . ': identical output.' );
		}

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => '', 'post_excerpt' => '', 'post_content' => $long ] );
		$this->assertSame( implode( ' ', array_fill( 0, 25, 'word' ) ) . '…', \PKIW\untitled_thought( get_post( $post_id ) ) );
	}

	public function test_untitled_thought_is_empty_for_a_post_with_no_text(): void {
		// WordPress trunk refuses an empty post unless this filter allows it.
		add_filter( 'wp_insert_post_empty_content', '__return_false' );
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => '', 'post_excerpt' => '', 'post_content' => '' ] );
		remove_filter( 'wp_insert_post_empty_content', '__return_false' );

		$this->assertSame( '', \PKIW\untitled_thought( get_post( $post_id ) ) );
	}

	// --- From the issue 263 stream fidelity nit: no dead live region. ---

	public function test_an_article_card_prints_a_static_caption_with_no_live_region(): void {
		$this->article_post( 3, 'A plain caption' );

		$html    = \PKIW\render_stream_card();
		$caption = $this->card_xpath( $html )->query( '//p[contains(@class,"pk-media__caption")]' );

		$this->assertSame( 1, $caption->length );
		$this->assertSame( 'A plain caption', trim( $caption->item( 0 )->textContent ) );
		$this->assertFalse( $caption->item( 0 )->hasAttribute( 'aria-live' ), 'Nothing swaps an article caption, so it is not a live region.' );
		$this->assertStringNotContainsString( 'aria-live', $html );
	}

	public function test_a_card_with_a_thumbnail_strip_keeps_its_live_caption(): void {
		$this->ensure_kind_term( 'note' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'A note with photos',
				'post_content' => '<!-- wp:paragraph --><p>Note body.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'note', 'kind' );
		set_post_thumbnail( $post_id, $this->upload_image( $post_id, 'Hero alt', 'Swappable caption' ) );
		$this->upload_image( $post_id );
		$GLOBALS['post'] = get_post( $post_id );

		$html    = \PKIW\render_stream_card();
		$xp      = $this->card_xpath( $html );
		$caption = $xp->query( '//p[contains(@class,"pk-media__caption")]' );

		$this->assertSame( 1, $xp->query( '//ul[contains(@class,"pk-media__thumbs")]' )->length, 'There is a strip to swap it.' );
		$this->assertSame( 1, $caption->length );
		$this->assertSame( 'polite', $caption->item( 0 )->getAttribute( 'aria-live' ) );
	}

	public function test_a_single_image_card_that_is_not_an_article_has_a_static_caption(): void {
		$this->ensure_kind_term( 'note' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'A note with one photo',
				'post_content' => '<!-- wp:paragraph --><p>Note body.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'note', 'kind' );
		set_post_thumbnail( $post_id, $this->upload_image( $post_id, 'Hero alt', 'Only caption' ) );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$this->assertSame( 0, $xp->query( '//ul[contains(@class,"pk-media__thumbs")]' )->length, 'No strip, so nothing swaps the caption.' );
		$caption = $xp->query( '//p[contains(@class,"pk-media__caption")]' );
		$this->assertSame( 1, $caption->length );
		$this->assertFalse( $caption->item( 0 )->hasAttribute( 'aria-live' ), 'No dead live region.' );
	}

	public function test_the_caption_helper_is_live_only_when_asked(): void {
		$post_id = $this->article_post( 0, 'Helper caption' );
		$post    = get_post( $post_id );

		$this->assertStringNotContainsString( 'aria-live', \PKIW\stream_card_media_caption( $post ) );
		$this->assertStringContainsString( 'aria-live="polite"', \PKIW\stream_card_media_caption( $post, true ) );
	}

	// ------------------------------------------------------------------
	// W5-P0C: entry media properties and generic-card cuts.
	// ------------------------------------------------------------------

	/**
	 * Create an attachment whose URL resolves back to its post ID.
	 *
	 * @param string $mime      Attachment MIME type.
	 * @param string $extension File extension.
	 * @return int Attachment ID.
	 */
	private function stream_media_attachment( string $mime, string $extension ): int {
		$id = self::factory()->attachment->create( [ 'post_mime_type' => $mime ] );
		update_post_meta( $id, '_wp_attached_file', '2026/10/pkiw-stream-media-' . $id . '.' . $extension );

		return $id;
	}

	public function test_video_entry_media_uses_the_reader_and_respects_visibility(): void {
		$this->ensure_kind_term( 'video' );
		$public_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $public_id, 'video', 'kind' );
		$empty_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $empty_id, 'video', 'kind' );
		$protected_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
		wp_set_object_terms( $protected_id, 'video', 'kind' );
		$private_id = self::factory()->post->create( [ 'post_status' => 'private' ] );
		wp_set_object_terms( $private_id, 'video', 'kind' );

		$sources = [
			$public_id    => home_url( '/media/local-video.mp4' ),
			$protected_id => home_url( '/media/secret.mp4' ),
			$private_id   => home_url( '/media/private.mp4' ),
		];
		$this->register_test_reader(
			'video',
			static fn( int $post_id ): array => [ 'src' => $sources[ $post_id ] ?? '' ]
		);

		$this->assertSame(
			'<data class="u-video" value="' . esc_url( $sources[ $public_id ] ) . '"></data>',
			\PKIW\entry_media_property_html( get_post( $public_id ) )
		);
		$this->assertSame( '', \PKIW\entry_media_property_html( get_post( $empty_id ) ) );
		$this->assertSame( '', \PKIW\entry_media_property_html( get_post( $protected_id ) ) );

		wp_set_current_user( 0 );
		$this->assertSame( '', \PKIW\entry_media_property_html( get_post( $private_id ) ) );

		$this->ensure_kind_term( 'note' );
		$note_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:video --><figure class="wp-block-video"><video controls src="https://example.org/note.mp4"></video></figure><!-- /wp:video -->',
			]
		);
		wp_set_object_terms( $note_id, 'note', 'kind' );
		$this->assertSame( '', \PKIW\entry_media_property_html( get_post( $note_id ) ) );
	}

	/**
	 * A remote video source never becomes a u-video property, even when a reader
	 * hands one over. Only facts that say the file is local, or a source under
	 * the uploads folder or the site's own host, print.
	 */
	public function test_video_entry_media_never_prints_a_remote_source(): void {
		$this->ensure_kind_term( 'video' );
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'video', 'kind' );
		$post    = get_post( $post_id );
		$uploads = wp_get_upload_dir();

		$cases = [
			'remote, no is_local'      => [ [ 'src' => 'https://remote.example.org/v.mp4' ], '' ],
			'remote, is_local false'   => [ [ 'src' => 'https://remote.example.org/v.mp4', 'is_local' => false ], '' ],
			'lookalike host'           => [ [ 'src' => 'https://example.org.evil.example/v.mp4' ], '' ],
			'local, is_local true'     => [ [ 'src' => 'https://remote.example.org/v.mp4', 'is_local' => true ], 'https://remote.example.org/v.mp4' ],
			'uploads, no is_local'     => [ [ 'src' => $uploads['baseurl'] . '/2026/01/v.mp4' ], $uploads['baseurl'] . '/2026/01/v.mp4' ],
			'site host, is_local false' => [ [ 'src' => home_url( '/media/v.mp4' ), 'is_local' => false ], home_url( '/media/v.mp4' ) ],
		];
		foreach ( $cases as $label => [ $facts, $expected_url ] ) {
			$this->register_test_reader( 'video', static fn(): array => $facts );
			$this->assertSame(
				'' === $expected_url ? '' : '<data class="u-video" value="' . esc_url( $expected_url ) . '"></data>',
				\PKIW\entry_media_property_html( $post ),
				$label
			);
		}
	}

	public function test_existing_video_property_is_not_duplicated(): void {
		$this->ensure_kind_term( 'video' );
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'video', 'kind' );
		$this->register_test_reader( 'video', static fn(): array => [ 'src' => 'https://example.org/video.mp4' ] );
		$post = get_post( $post_id );
		$html = '<article class="pk-card h-entry"><data class="u-video" value="https://example.org/video.mp4"></data></article>';

		$out = \PKIW\ensure_entry_properties( $html, $post, true );

		$this->assertSame( 1, substr_count( $out, 'class="u-video"' ) );
	}

	/**
	 * Audio resolves its player from the post's blocks, not from Kind_Facts, so
	 * the can_show() gate is what keeps a private, draft or future post's file
	 * URL out of the property for a logged-out reader.
	 *
	 * @dataProvider unreadable_audio_statuses
	 *
	 * @param string $status Post status.
	 */
	public function test_audio_entry_media_is_empty_for_a_post_the_reader_cannot_see( string $status ): void {
		$this->ensure_kind_term( 'audio' );
		$args = [
			'post_status'  => $status,
			'post_content' => '<!-- wp:paragraph --><p><a class="u-audio" href="https://media.example.org/hidden.mp3">Listen</a></p><!-- /wp:paragraph -->',
		];
		if ( 'future' === $status ) {
			$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}
		$post_id = self::factory()->post->create( $args );
		wp_set_object_terms( $post_id, 'audio', 'kind' );
		$post = get_post( $post_id );

		$this->assertFalse( post_password_required( $post ), 'No password stands in the way.' );
		$this->assertSame( [], \PKIW\Kind_Facts::get( $post_id ), 'Kind_Facts has no audio reader here, so it is not what blocks this.' );

		wp_set_current_user( 0 );
		$this->assertSame( '', \PKIW\entry_media_property_html( $post ), 'A logged-out reader gets nothing.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame(
			'<data class="u-audio" value="https://media.example.org/hidden.mp3"></data>',
			\PKIW\entry_media_property_html( $post ),
			'A reader who may read the post gets the property.'
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function unreadable_audio_statuses(): array {
		return [
			'private' => [ 'private' ],
			'draft'   => [ 'draft' ],
			'future'  => [ 'future' ],
		];
	}

	public function test_audio_entry_media_uses_attachment_and_url_players(): void {
		$this->ensure_kind_term( 'audio' );
		$attachment_id = $this->stream_media_attachment( 'audio/mpeg', 'mp3' );
		$attachment_url = (string) wp_get_attachment_url( $attachment_id );
		$attachment_post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:audio {"id":' . $attachment_id . '} --><figure class="wp-block-audio"><audio controls src="https://stale.example.org/audio.mp3"></audio></figure><!-- /wp:audio -->',
			]
		);
		wp_set_object_terms( $attachment_post_id, 'audio', 'kind' );
		$this->assertSame(
			'<data class="u-audio" value="' . esc_url( $attachment_url ) . '"></data>',
			\PKIW\entry_media_property_html( get_post( $attachment_post_id ) )
		);
		$attachment_post = get_post( $attachment_post_id );
		$attachment_html = \PKIW\ensure_entry_properties( '<article class="pk-card h-entry"></article>', $attachment_post, true );
		$attachment_xp   = $this->card_xpath( $attachment_html );
		$this->assertSame(
			1,
			$attachment_xp->query( '//span[contains(@class,"pk-entry-props") and @hidden]//data[@class="u-audio" and @value="' . $attachment_url . '"]' )->length
		);

		$url_post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p><a class="u-audio" href="https://media.example.org/episode.mp3">Listen</a></p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $url_post_id, 'audio', 'kind' );
		$post      = get_post( $url_post_id );
		$permalink = esc_url( (string) get_permalink( $post ) );
		$html      = '<article class="pk-card h-entry"><a class="u-url" href="' . $permalink . '">Episode</a><time class="dt-published"></time><span class="p-author h-card"><span class="p-name">Author</span></span></article>';

		$out = \PKIW\ensure_entry_properties( $html, $post, true );

		$this->assertStringContainsString( '<span class="pk-entry-props" hidden><data class="u-audio" value="https://media.example.org/episode.mp3"></data></span>', $out );
		$this->assertSame( 1, substr_count( $out, 'class="u-url"' ) );
		$this->assertSame( 1, substr_count( $out, 'class="dt-published"' ) );
		$this->assertSame( 1, substr_count( $out, 'class="p-author h-card"' ) );
	}

	public function test_audio_entry_media_omits_embeds_missing_players_and_protected_posts(): void {
		$this->ensure_kind_term( 'audio' );
		$contents = [
			'embed' => '<!-- wp:embed {"url":"https://www.mixcloud.com/example/show/","providerNameSlug":"mixcloud"} --><figure class="wp-block-embed"></figure><!-- /wp:embed -->',
			'none'  => '<!-- wp:paragraph --><p>No player here.</p><!-- /wp:paragraph -->',
		];
		foreach ( $contents as $label => $content ) {
			$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $content ] );
			wp_set_object_terms( $post_id, 'audio', 'kind' );
			$this->assertSame( '', \PKIW\entry_media_property_html( get_post( $post_id ) ), $label );
		}

		$protected_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => '<!-- wp:audio --><figure class="wp-block-audio"><audio controls src="https://example.org/secret.mp3"></audio></figure><!-- /wp:audio -->',
			]
		);
		wp_set_object_terms( $protected_id, 'audio', 'kind' );
		$this->assertSame( '', \PKIW\entry_media_property_html( get_post( $protected_id ) ) );
	}

	public function test_the_before_date_filter_prints_between_title_and_date(): void {
		$probe = static fn( string $html ): string => $html . '<span class="before-date-probe">Probe</span>';
		add_filter( 'pkiw_stream_card_before_date', $probe, 10, 2 );
		[ , $html ] = $this->stream_card_for(
			'note',
			[
				'post_title'   => 'Filter order',
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
			]
		);
		remove_filter( 'pkiw_stream_card_before_date', $probe, 10 );

		$this->assertLessThan( strpos( $html, 'before-date-probe' ), strpos( $html, 'pk-title' ) );
		$this->assertLessThan( strpos( $html, 'pk-stream-date' ), strpos( $html, 'before-date-probe' ) );
	}

	public function test_generic_card_cuts_have_closed_kind_defaults(): void {
		$this->assertSame( [ 'media', 'excerpt', 'meta', 'badge' ], \PKIW\generic_card_cuts( 'audio' ) );
		$this->assertSame( [ 'extras', 'meta' ], \PKIW\generic_card_cuts( 'article' ) );
		$this->assertSame( [ 'extras', 'meta' ], \PKIW\generic_card_cuts( 'craft' ) );
		$this->assertSame( [], \PKIW\generic_card_cuts( 'note' ) );
	}

	public function test_audio_generic_card_cuts_visual_media_excerpt_meta_and_badge(): void {
		$this->ensure_kind_term( 'audio' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Audio field note',
				'post_excerpt' => 'An excerpt that stays off the card.',
				'post_content' => '<!-- wp:audio --><figure class="wp-block-audio"><audio controls src="https://example.org/audio.mp3"></audio></figure><!-- /wp:audio -->',
			]
		);
		wp_set_object_terms( $post_id, 'audio', 'kind' );
		$hero = $this->upload_image( $post_id, 'Hero alt', 'Photo by <a href="https://example.org/credit">Ana</a>' );
		set_post_thumbnail( $post_id, $hero );
		for ( $n = 0; $n < 5; $n++ ) {
			$this->upload_image( $post_id );
		}
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();
		$xp   = $this->card_xpath( $html );

		$this->assertSame( 0, $xp->query( '//div[contains(@class,"pk-media")]' )->length );
		$this->assertSame( 0, $xp->query( '//ul[contains(@class,"pk-media__thumbs")]' )->length );
		$this->assertSame( 0, $xp->query( '//button' )->length );
		$this->assertDoesNotMatchRegularExpression( '/\+\d/', wp_strip_all_tags( $html ) );
		$this->assertSame( 0, $xp->query( '//p[contains(@class,"pk-excerpt")]' )->length );
		$this->assertSame( 0, $xp->query( '//div[contains(@class,"pk-meta")]' )->length );
		$this->assertSame( 0, $xp->query( '//div[contains(@class,"pk-badge")]' )->length );
		$this->assertSame( 1, $xp->query( '//span[contains(@class,"pk-kindlabel")]' )->length );
	}

	public function test_article_and_craft_keep_their_existing_headline_only_regions(): void {
		$this->article_post( 5, 'Article caption' );
		$article = \PKIW\render_stream_card();
		$this->assertStringContainsString( 'pk-badge', $article );
		$this->assertStringContainsString( 'pk-media pk-media--stream', $article );
		$this->assertStringContainsString( 'pk-media__caption', $article );
		$this->assertStringContainsString( 'pk-excerpt', $article );
		$this->assertStringNotContainsString( 'pk-media__thumbs', $article );
		$this->assertStringNotContainsString( 'pk-meta', $article );

		$craft_id = $this->craft_post();
		$hero     = $this->upload_image( $craft_id, 'Hero alt', 'Craft caption' );
		set_post_thumbnail( $craft_id, $hero );
		for ( $n = 0; $n < 5; $n++ ) {
			$this->upload_image( $craft_id );
		}
		$GLOBALS['post'] = get_post( $craft_id );
		$craft           = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-badge', $craft );
		$this->assertStringContainsString( 'pk-media pk-media--stream', $craft );
		$this->assertStringContainsString( 'pk-excerpt', $craft );
		$this->assertStringNotContainsString( 'pk-media__caption', $craft );
		$this->assertStringNotContainsString( 'pk-media__thumbs', $craft );
		$this->assertStringNotContainsString( 'pk-meta', $craft );
		$this->assertDoesNotMatchRegularExpression( '/\+\d/', wp_strip_all_tags( $craft ) );
	}

	// ------------------------------------------------------------------
	// W5-P0C: the protected-card author guard (W5-G35).
	// ------------------------------------------------------------------

	/**
	 * Kinds whose protected Stream card must not name the author.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function protected_author_kinds(): array {
		return [
			'video' => [ 'video' ],
			'jam'   => [ 'jam' ],
			'note'  => [ 'note' ],
		];
	}

	/**
	 * A post of a kind by a named author, rendered through the Stream card
	 * block inside a loop, the way a Query Loop item reaches the filter.
	 *
	 * @param string $kind     Kind slug.
	 * @param string $password Post password, '' for none.
	 * @return array{html: string, post_id: int, author: int}
	 */
	private function loop_card_by_named_author( string $kind, string $password ): array {
		$this->ensure_kind_term( $kind );
		$author  = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Zed Authorperson',
				'user_login'   => 'zedauthor' . $kind . ( '' === $password ? 'pub' : 'prot' ),
			]
		);
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => 'A ' . $kind . ' by Zed',
				'post_author'   => $author,
				'post_password' => $password,
				'post_content'  => '<!-- wp:paragraph --><p>Body for the ' . $kind . '.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, $kind, 'kind' );

		$this->go_to( get_permalink( $post_id ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );
		}
		wp_reset_postdata();

		return [
			'html'    => $html,
			'post_id' => $post_id,
			'author'  => $author,
		];
	}

	/**
	 * @dataProvider protected_author_kinds
	 */
	public function test_a_protected_stream_card_names_no_author_through_the_block_filter( string $kind ): void {
		$card = $this->loop_card_by_named_author( $kind, 'secret' );
		$html = $card['html'];

		$this->assertStringContainsString( 'pk-card--protected', $html, 'The title-only protected card renders.' );
		$this->assertStringNotContainsString( 'p-author', $html );
		$this->assertStringNotContainsString( 'Zed Authorperson', $html );
		$this->assertStringNotContainsString( get_author_posts_url( $card['author'] ), $html );
		$this->assertStringNotContainsString( 'gravatar', $html );
		$this->assertStringContainsString( 'u-url', $html, 'The permalink property stays.' );
		$this->assertStringContainsString( 'dt-published', $html, 'The publication date stays.' );
	}

	/**
	 * @dataProvider protected_author_kinds
	 */
	public function test_an_unprotected_stream_card_keeps_the_hidden_author( string $kind ): void {
		$card = $this->loop_card_by_named_author( $kind, '' );

		$this->assertStringContainsString( 'p-author h-card', $card['html'] );
		$this->assertStringContainsString( 'Zed Authorperson', $card['html'] );
		$this->assertStringContainsString( get_author_posts_url( $card['author'] ), $card['html'] );
	}
}
