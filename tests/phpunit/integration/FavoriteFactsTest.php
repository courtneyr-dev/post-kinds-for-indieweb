<?php
/**
 * Favorite facts reader (issue 259).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Taxonomy;

/**
 * Favorite facts come from the first favorite-card block, then the
 * favorite and cite meta a Quick Post or import stores. Names, people and
 * hosts are invented.
 *
 * @group integration
 */
final class FavoriteFactsTest extends WP_UnitTestCase {

	/**
	 * The facts contract (keys sorted), per W3-G rulings: cited_title and summary, never title and description.
	 *
	 * @var string[]
	 */
	private const KEYS = [ 'author', 'cited_title', 'favorited', 'has_card', 'host', 'name', 'name_is_title', 'picture', 'rel', 'summary', 'url' ];

	/**
	 * Site options restored after each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_options = [];

	public function set_up(): void {
		parent::set_up();
		foreach ( [ 'timezone_string', 'date_format' ] as $option ) {
			$this->original_options[ $option ] = get_option( $option );
		}
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		( new Taxonomy() )->create_default_terms();
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		remove_filter( 'pre_http_request', '__return_empty_array' );
		foreach ( $this->original_options as $option => $value ) {
			update_option( $option, $value );
		}
		parent::tear_down();
	}

	/**
	 * A published favorite.
	 *
	 * @param array<string, mixed>|null $card Favorite-card attributes, or null for no card.
	 * @param array<string, mixed>      $args Extra post arguments.
	 * @param array<string, string>     $meta Post meta.
	 * @return int Post ID.
	 */
	private function favorite( ?array $card, array $args = [], array $meta = [] ): int {
		$content = null === $card
			? '<!-- wp:paragraph --><p>Saved for later.</p><!-- /wp:paragraph -->'
			: '<!-- wp:post-kinds-indieweb/favorite-card ' . wp_json_encode( $card, JSON_UNESCAPED_SLASHES ) . ' /-->';
		$args    = array_merge(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Seed favorite',
				'post_date'    => '2026-08-28 10:00:00',
				'post_content' => $content,
			],
			$args
		);
		// wp_insert_post() expects slashed data; without this a JSON backslash in a card attribute is lost.
		$args['post_content'] = wp_slash( (string) $args['post_content'] );
		$post_id              = self::factory()->post->create( $args );
		wp_set_object_terms( $post_id, 'favorite', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Facts through the one door themes use.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function facts( int $post_id ): array {
		return Kind_Facts::get( $post_id );
	}

	/**
	 * The picture shape for none.
	 *
	 * @return array<string, mixed>
	 */
	private function no_picture(): array {
		return [
			'source'            => '',
			'attachment_id'     => 0,
			'url'               => '',
			'alt'               => '',
			'remote'            => false,
			'suppress_featured' => false,
		];
	}

	/**
	 * A real image attachment in the uploads directory.
	 *
	 * @param string $alt Stored attachment alt.
	 * @return int Attachment ID.
	 */
	private function upload( string $alt = '' ): int {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertGreaterThan( 0, $id );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	public function test_the_plugin_registers_the_favorite_reader_at_init(): void {
		$this->assertNotNull( Kind_Facts::reader( 'favorite' ) );
		$this->assertSame( [], Kind_Facts::reader( 'favorite' )['location'], 'No location keys.' );
	}

	public function test_a_full_card_gives_exactly_the_contract_keys(): void {
		$post_id = $this->favorite(
			[
				'title'       => 'Field Guide to Quiet Software',
				'url'         => 'https://notes.example.com/quiet-software',
				'author'      => 'Rowan Vale',
				'description' => 'A short guide. It has two sentences.',
				'favoritedAt' => '2026-08-14',
				'rel'         => 'nofollow',
			],
			[ 'post_title' => 'Field Guide to Quiet Software' ]
		);

		$facts = $this->facts( $post_id );
		$keys  = array_keys( $facts );
		sort( $keys );

		$this->assertSame( self::KEYS, $keys );
		$this->assertTrue( $facts['has_card'] );
		$this->assertSame( 'Field Guide to Quiet Software', $facts['cited_title'] );
		$this->assertSame( 'https://notes.example.com/quiet-software', $facts['url'] );
		$this->assertSame( 'notes.example.com', $facts['host'] );
		$this->assertSame( 'Rowan Vale', $facts['author'] );
		$this->assertSame( 'A short guide. It has two sentences.', $facts['summary'] );
		$this->assertSame( 'nofollow', $facts['rel'] );
		$this->assertSame( [ '2026-08-14', 'August 14, 2026' ], $facts['favorited'] );
		$this->assertSame( $this->no_picture(), $facts['picture'] );
		$this->assertSame( 'Field Guide to Quiet Software', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );
	}

	public function test_a_card_title_that_differs_from_the_post_title_is_the_cited_title(): void {
		$post_id = $this->favorite(
			[
				'title'  => 'Small Press Almanac, Issue 3',
				'url'    => 'https://zine.example.org/almanac-3',
				'author' => 'Imogen Thatch',
			],
			[ 'post_title' => 'Why this zine stuck with me' ]
		);
		$facts   = $this->facts( $post_id );

		$this->assertSame( 'Small Press Almanac, Issue 3', $facts['cited_title'] );
		$this->assertSame( 'Why this zine stuck with me', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );
		$this->assertSame( [ '', '' ], $facts['favorited'], 'No favoritedAt, no date.' );
	}

	public function test_a_card_title_equal_to_its_url_is_no_cited_title(): void {
		$url     = 'https://notes.example.com/patient-tools';
		$post_id = $this->favorite( [ 'title' => $url, 'url' => $url ], [ 'post_title' => 'Notes on Patient Tools' ] );

		$this->assertSame( '', $this->facts( $post_id )['cited_title'] );

		$other = $this->favorite( [ 'title' => 'https://notes.example.com/other', 'url' => $url ], [ 'post_title' => 'Notes on Patient Tools' ] );
		$this->assertSame( '', $this->facts( $other )['cited_title'], 'Any absolute URL as a title counts as no title.' );
	}

	public function test_a_url_only_card_falls_back_to_the_stored_favorite_name_and_names_the_post_by_its_host(): void {
		$post_id = $this->favorite(
			[ 'url' => 'https://www.Blog.Example.NET/posts/7' ],
			[ 'post_title' => '' ],
			[ '_pkiw_favorite_name' => 'Seed Meta Name' ]
		);
		$facts   = $this->facts( $post_id );

		$this->assertTrue( $facts['has_card'] );
		$this->assertSame( 'Seed Meta Name', $facts['cited_title'], 'The stored name stands in for a card with no title.' );
		$this->assertSame( 'blog.example.net', $facts['host'] );
		$this->assertSame( 'blog.example.net', $facts['name'], 'X8: the card URL host comes before the stored name.' );
		$this->assertFalse( $facts['name_is_title'] );
	}

	public function test_an_untitled_post_takes_the_card_title_as_its_name(): void {
		$post_id = $this->favorite( [ 'title' => 'Quiet Hours Handbook', 'url' => 'https://notes.example.com/handbook' ], [ 'post_title' => '' ] );
		$facts   = $this->facts( $post_id );

		$this->assertSame( 'Quiet Hours Handbook', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'] );
	}

	public function test_a_post_title_that_is_the_cited_url_is_not_a_name(): void {
		$url     = 'https://zine.example.org/issue-9';
		$post_id = $this->favorite( [ 'url' => $url ], [ 'post_title' => $url ] );
		$facts   = $this->facts( $post_id );

		$this->assertSame( 'zine.example.org', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'], 'Micropub stores title = url for a nameless response.' );
	}

	public function test_a_card_with_no_url_has_no_target(): void {
		$post_id = $this->favorite( [ 'title' => 'Kitchen Radio Hour', 'author' => 'Pell Okafor' ], [ 'post_title' => 'Kitchen Radio Hour' ] );
		$facts   = $this->facts( $post_id );

		$this->assertTrue( $facts['has_card'] );
		$this->assertSame( '', $facts['url'] );
		$this->assertSame( '', $facts['host'] );
		$this->assertSame( 'Pell Okafor', $facts['author'] );
	}

	public function test_an_unsafe_card_url_is_dropped(): void {
		$post_id = $this->favorite( [ 'title' => 'Bad link', 'url' => 'javascript:alert(1)' ] );

		$this->assertSame( '', $this->facts( $post_id )['url'] );
		$this->assertSame( '', $this->facts( $post_id )['host'] );
	}

	public function test_a_quick_post_with_meta_only_reads_the_favorite_meta(): void {
		$post_id = $this->favorite(
			null,
			[ 'post_title' => 'Seed Recipe Index', 'post_date' => '2026-06-28 09:00:00' ],
			[
				'_pkiw_favorite_name' => 'Seed Recipe Index',
				'_pkiw_favorite_url'  => 'https://recipes.example.com/index',
			]
		);
		$facts   = $this->facts( $post_id );

		$this->assertFalse( $facts['has_card'] );
		$this->assertSame( 'https://recipes.example.com/index', $facts['url'] );
		$this->assertSame( 'recipes.example.com', $facts['host'] );
		$this->assertSame( 'Seed Recipe Index', $facts['cited_title'] );
		$this->assertSame( 'Seed Recipe Index', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );
		$this->assertSame( '', $facts['author'] );
		$this->assertSame( '', $facts['summary'] );
	}

	public function test_a_favorite_name_that_is_a_url_is_no_cited_title(): void {
		$post_id = $this->favorite(
			null,
			[],
			[
				'_pkiw_favorite_name' => 'https://recipes.example.com/index',
				'_pkiw_favorite_url'  => 'https://recipes.example.com/index',
			]
		);

		$this->assertSame( '', $this->facts( $post_id )['cited_title'] );
	}

	public function test_cite_meta_stands_in_when_the_favorite_has_no_card(): void {
		// The shape P0C's Stream and microformats tests use for a favorite with no reader.
		$post_id = $this->favorite(
			null,
			[ 'post_title' => 'My note on it' ],
			[
				'_pkiw_cite_url'    => 'https://remote.example.org/page?x=1',
				'_pkiw_cite_name'   => 'A remote page',
				'_pkiw_cite_author' => 'Remote Author',
			]
		);
		$facts   = $this->facts( $post_id );

		$this->assertFalse( $facts['has_card'] );
		$this->assertSame( 'https://remote.example.org/page?x=1', $facts['url'] );
		$this->assertSame( 'A remote page', $facts['cited_title'] );
		$this->assertSame( 'Remote Author', $facts['author'] );
	}

	public function test_the_favorite_meta_wins_over_the_cite_meta(): void {
		$post_id = $this->favorite(
			null,
			[],
			[
				'_pkiw_favorite_url'  => 'https://recipes.example.com/index',
				'_pkiw_favorite_name' => 'Favorite name',
				'_pkiw_cite_url'      => 'https://remote.example.org/page',
				'_pkiw_cite_name'     => 'Cite name',
			]
		);
		$facts   = $this->facts( $post_id );

		$this->assertSame( 'https://recipes.example.com/index', $facts['url'] );
		$this->assertSame( 'Favorite name', $facts['cited_title'] );
	}

	public function test_a_micropub_paragraph_alone_gives_no_url_and_no_card(): void {
		$post_id = $this->favorite(
			null,
			[
				'post_title'   => '',
				'post_content' => '<!-- wp:paragraph --><p class="u-favorite-of">Favorited <a class="u-favorite-of" href="https://paragraph.example.org/a">a page</a></p><!-- /wp:paragraph -->',
			]
		);
		$facts   = $this->facts( $post_id );

		$this->assertFalse( $facts['has_card'] );
		$this->assertSame( '', $facts['url'], 'The reader never parses a link out of body text.' );
		$this->assertSame( '', $facts['cited_title'] );
	}

	public function test_a_card_nested_in_a_group_is_found(): void {
		$card    = wp_json_encode( [ 'title' => 'Nested', 'url' => 'https://notes.example.com/nested' ], JSON_UNESCAPED_SLASHES );
		$post_id = $this->favorite(
			null,
			[ 'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-kinds-indieweb/favorite-card ' . $card . ' /--></div><!-- /wp:group -->' ]
		);
		$facts   = $this->facts( $post_id );

		$this->assertTrue( $facts['has_card'] );
		$this->assertSame( 'https://notes.example.com/nested', $facts['url'] );
	}

	public function test_the_first_of_two_cards_wins(): void {
		$first   = wp_json_encode( [ 'title' => 'First card', 'url' => 'https://notes.example.com/first' ], JSON_UNESCAPED_SLASHES );
		$second  = wp_json_encode( [ 'title' => 'Second card', 'url' => 'https://notes.example.com/second' ], JSON_UNESCAPED_SLASHES );
		$post_id = $this->favorite(
			null,
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/favorite-card ' . $first . ' /--><!-- wp:post-kinds-indieweb/favorite-card ' . $second . ' /-->' ]
		);
		$facts   = $this->facts( $post_id );

		$this->assertSame( 'First card', $facts['cited_title'] );
		$this->assertSame( 'https://notes.example.com/first', $facts['url'] );
	}

	/**
	 * @return array<string, array{0: string, 1: array{0: string, 1: string}}>
	 */
	public function favorited_dates(): array {
		return [
			'a calendar day'         => [ '2026-08-14', [ '2026-08-14', 'August 14, 2026' ] ],
			'a day with a time'      => [ '2026-08-14T23:30:00', [ '2026-08-14', 'August 14, 2026' ] ],
			'a day that never was'   => [ '2026-02-30', [ '', '' ] ],
			'text that is no date'   => [ 'tomorrow', [ '', '' ] ],
			'nothing stored'         => [ '', [ '', '' ] ],
		];
	}

	/**
	 * Under America/New_York the stored day never shifts (the 228 read-card bug).
	 *
	 * @dataProvider favorited_dates
	 *
	 * @param string                 $stored   Stored favoritedAt.
	 * @param array{0: string, 1: string} $expected Machine and display dates.
	 */
	public function test_favorited_follows_card_calendar_date( string $stored, array $expected ): void {
		$post_id = $this->favorite( [ 'title' => 'Dated', 'url' => 'https://notes.example.com/dated', 'favoritedAt' => $stored ] );

		$this->assertSame( $expected, $this->facts( $post_id )['favorited'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function rel_values(): array {
		return [
			'two tokens are kept'                  => [ 'nofollow ugc', 'nofollow ugc' ],
			'extra whitespace collapses'           => [ "  nofollow \t ugc \n", 'nofollow ugc' ],
			'case folds'                           => [ 'NoFollow UGC', 'nofollow ugc' ],
			'a repeat is dropped'                  => [ 'nofollow nofollow ugc', 'nofollow ugc' ],
			'a quoted token and its onclick go'    => [ 'nofollow" onclick="alert(1)', '' ],
			'a bad token goes, a good one stays'   => [ 'ugc onclick="x" me', 'ugc me' ],
			'a token that starts with a digit goes' => [ '9lives ugc', 'ugc' ],
			'nothing stored'                       => [ '', '' ],
		];
	}

	/**
	 * @dataProvider rel_values
	 *
	 * @param string $stored   Stored rel.
	 * @param string $expected Facts rel.
	 */
	public function test_rel_keeps_only_safe_tokens( string $stored, string $expected ): void {
		$post_id = $this->favorite( [ 'title' => 'Rel', 'url' => 'https://notes.example.com/rel', 'rel' => $stored ] );

		$this->assertSame( $expected, $this->facts( $post_id )['rel'] );
	}

	public function test_the_picture_is_the_featured_image_first(): void {
		$featured = $this->upload( 'Featured alt' );
		$card_img = $this->upload();
		$post_id  = $this->favorite(
			[
				'title'    => 'Pictured',
				'url'      => 'https://notes.example.com/pictured',
				'image'    => (string) wp_get_attachment_url( $card_img ),
				'imageAlt' => 'Card alt',
			]
		);
		set_post_thumbnail( $post_id, $featured );

		$picture = $this->facts( $post_id )['picture'];

		$this->assertSame( 'featured', $picture['source'] );
		$this->assertSame( $featured, $picture['attachment_id'] );
		$this->assertSame( (string) wp_get_attachment_url( $featured ), $picture['url'] );
		$this->assertSame( 'Featured alt', $picture['alt'] );
		$this->assertFalse( $picture['remote'] );
		$this->assertTrue( $picture['suppress_featured'] );
	}

	public function test_the_picture_is_the_card_upload_when_there_is_no_featured_image(): void {
		$card_img = $this->upload( 'Library alt' );
		$post_id  = $this->favorite(
			[
				'title'    => 'Pictured',
				'url'      => 'https://notes.example.com/pictured',
				'image'    => (string) wp_get_attachment_url( $card_img ),
				'imageAlt' => 'A flowchart of three yes-or-no questions',
			]
		);

		$picture = $this->facts( $post_id )['picture'];

		$this->assertSame( 'card', $picture['source'] );
		$this->assertSame( $card_img, $picture['attachment_id'] );
		$this->assertSame( (string) wp_get_attachment_url( $card_img ), $picture['url'] );
		$this->assertSame( 'A flowchart of three yes-or-no questions', $picture['alt'], 'imageAlt, verbatim (X7).' );
		$this->assertFalse( $picture['remote'] );
		$this->assertFalse( $picture['suppress_featured'] );
	}

	public function test_a_card_image_with_no_image_alt_has_empty_alt_even_when_the_library_has_one(): void {
		$card_img = $this->upload( 'Library alt that must not leak in' );
		$post_id  = $this->favorite( [ 'title' => 'Pictured', 'url' => 'https://notes.example.com/pictured', 'image' => (string) wp_get_attachment_url( $card_img ) ] );

		$picture = $this->facts( $post_id )['picture'];

		$this->assertSame( 'card', $picture['source'] );
		$this->assertSame( '', $picture['alt'], 'X7: no invented alt, and no fallback to the library.' );
	}

	public function test_a_remote_card_image_is_flagged_remote_with_no_attachment(): void {
		$post_id = $this->favorite(
			[
				'title'    => 'Remote',
				'url'      => 'https://notes.example.com/remote',
				'image'    => 'https://images.example.com/remote.jpg',
				'imageAlt' => 'Remote alt',
			]
		);

		$picture = $this->facts( $post_id )['picture'];

		$this->assertSame( 'card', $picture['source'] );
		$this->assertSame( 0, $picture['attachment_id'] );
		$this->assertSame( 'https://images.example.com/remote.jpg', $picture['url'] );
		$this->assertSame( 'Remote alt', $picture['alt'] );
		$this->assertTrue( $picture['remote'] );
		$this->assertFalse( $picture['suppress_featured'] );
	}

	public function test_an_unsafe_or_missing_card_image_gives_no_picture(): void {
		$unsafe = $this->favorite( [ 'title' => 'Unsafe', 'url' => 'https://notes.example.com/unsafe', 'image' => 'javascript:alert(1)' ] );
		$none   = $this->favorite( [ 'title' => 'None', 'url' => 'https://notes.example.com/none' ] );

		$this->assertSame( $this->no_picture(), $this->facts( $unsafe )['picture'] );
		$this->assertSame( $this->no_picture(), $this->facts( $none )['picture'] );
	}

	public function test_a_password_protected_favorite_has_no_facts(): void {
		$post_id = $this->favorite( [ 'title' => 'Locked', 'url' => 'https://secret.example.org/a' ], [ 'post_password' => 'seed259' ] );

		$this->assertSame( [], $this->facts( $post_id ) );
	}

	public function test_a_private_favorite_has_facts_only_for_a_user_who_can_read_it(): void {
		$post_id = $this->favorite( [ 'title' => 'Private', 'url' => 'https://secret.example.org/b' ], [ 'post_status' => 'private' ] );

		wp_set_current_user( 0 );
		$this->assertSame( [], $this->facts( $post_id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$facts = $this->facts( $post_id );
		$keys  = array_keys( $facts );
		sort( $keys );
		$this->assertSame( self::KEYS, $keys );
		$this->assertSame( 'https://secret.example.org/b', $facts['url'] );
	}

	public function test_a_post_with_nothing_stored_is_named_by_kind_and_date(): void {
		$post_id = $this->favorite( null, [ 'post_title' => '', 'post_date' => '2026-06-30 09:00:00' ] );
		$facts   = $this->facts( $post_id );

		$this->assertSame( 'Favorite, June 30, 2026', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'] );
		$this->assertFalse( $facts['has_card'] );
		$this->assertSame( '', $facts['url'] );
	}
}
