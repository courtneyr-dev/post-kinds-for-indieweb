<?php
/**
 * Exact-phrase search can't confirm a hidden card value (issues 239, 245, 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

/**
 * `exact` and `sentence` are public query vars, so a logged-out visitor can
 * search for a whole card comment with `?s=<comment>&exact=1&sentence=1`. If
 * that skipped the private-value strip, a right guess of a hidden distance,
 * bedtime or cost would match and a wrong one wouldn't. Every guess here is
 * the full stored comment of a post whose private value is hidden.
 *
 * @group integration
 */
final class SearchExactPrivacyTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'exercise', 'sleep', 'acquisition' ] as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			}
		}
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A card's stored block comment.
	 *
	 * @param string               $name  Block name without the namespace.
	 * @param array<string, mixed> $attrs Attributes.
	 */
	private function card( string $name, array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/' . $name . ' ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * A published post holding some content and a kind.
	 *
	 * @param string $content Post content.
	 * @param string $kind    Kind slug.
	 * @param string $title   Post title.
	 * @return int Post ID.
	 */
	private function post( string $content, string $kind, string $title = 'Exact fixture' ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => 'Exact excerpt',
				'post_content' => $content,
			]
		);
		wp_set_object_terms( $id, $kind, 'kind' );

		return $id;
	}

	/**
	 * IDs the site's main query returns for a search request.
	 *
	 * @param string $search Search string.
	 * @param string $flags  Extra query string, such as '&exact=1&sentence=1'.
	 * @return int[]
	 */
	private function main_query_ids( string $search, string $flags ): array {
		$this->go_to( home_url( '/?s=' . rawurlencode( $search ) . $flags ) );

		return array_map( static fn( $post ): int => (int) $post->ID, $GLOBALS['wp_query']->posts );
	}

	/**
	 * Hidden-card fixtures: a name and the comment of a post that hides a value.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function hidden_cards(): array {
		return [
			'exercise distance'  => [
				'exercise-card',
				[
					'activity' => 'Run',
					'distance' => 7.77,
				],
				'exercise',
			],
			'sleep bedtime'      => [
				'sleep-card',
				[
					'durationMinutes' => 462,
					'bedtime'         => '2026-08-19T23:10',
				],
				'sleep',
			],
			'acquisition cost'   => [
				'acquisition-card',
				[
					'title'           => 'Walnut Desk Lamp Zq9',
					'acquisitionType' => 'purchase',
					'cost'            => '$149.99',
				],
				'acquisition',
			],
		];
	}

	/**
	 * @dataProvider hidden_cards
	 *
	 * @param string               $block Card block name.
	 * @param array<string, mixed> $attrs Attributes with a private value.
	 * @param string               $kind  Kind slug.
	 */
	public function test_an_exact_sentence_search_for_the_full_hidden_card_finds_nothing_logged_out( string $block, array $attrs, string $kind ): void {
		$comment = $this->card( $block, $attrs );
		$id      = $this->post( $comment, $kind );

		$this->assertNotContains( $id, $this->main_query_ids( $comment, '&exact=1&sentence=1' ), 'A right guess of the hidden value is no hit.' );
	}

	/**
	 * @dataProvider hidden_cards
	 *
	 * @param string               $block Card block name.
	 * @param array<string, mixed> $attrs Attributes with a private value.
	 * @param string               $kind  Kind slug.
	 */
	public function test_an_exact_search_without_the_sentence_flag_finds_nothing_logged_out( string $block, array $attrs, string $kind ): void {
		$comment = $this->card( $block, $attrs );
		$id      = $this->post( $comment, $kind );

		$this->assertNotContains( $id, $this->main_query_ids( $comment, '&exact=1' ) );
	}

	/**
	 * @dataProvider hidden_cards
	 *
	 * @param string               $block Card block name.
	 * @param array<string, mixed> $attrs Attributes with a private value.
	 * @param string               $kind  Kind slug.
	 */
	public function test_an_editor_s_exact_search_still_finds_the_full_card( string $block, array $attrs, string $kind ): void {
		$comment = $this->card( $block, $attrs );
		$id      = $this->post( $comment, $kind );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertContains( $id, $this->main_query_ids( $comment, '&exact=1&sentence=1' ), 'An editor sees the post by its stored card.' );
	}

	/**
	 * @dataProvider hidden_cards
	 *
	 * @param string               $block Card block name.
	 * @param array<string, mixed> $attrs Attributes with a private value.
	 * @param string               $kind  Kind slug.
	 */
	public function test_an_exact_search_for_the_public_title_still_finds_the_post( string $block, array $attrs, string $kind ): void {
		$id = $this->post( $this->card( $block, $attrs ), $kind, 'Exact public title' );

		$this->assertContains( $id, $this->main_query_ids( 'Exact public title', '&exact=1&sentence=1' ), 'Exact search still works for a public field.' );
		$this->assertContains( $id, $this->main_query_ids( 'exact public TITLE', '&exact=1&sentence=1' ), 'Exact match ignores case, as the database does.' );
		$this->assertNotContains( $id, $this->main_query_ids( 'Exact public', '&exact=1&sentence=1' ), 'Exact is the whole field, not a substring.' );
	}

	public function test_an_opted_in_exercise_card_is_found_by_its_exact_comment_logged_out(): void {
		$comment = $this->card(
			'exercise-card',
			[
				'activity'            => 'Run',
				'distance'            => 7.77,
				'showDetailsPublicly' => true,
			]
		);
		$id      = $this->post( $comment, 'exercise' );
		$this->assertTrue( Meta_Fields::health_details_visible( $id ) );

		$this->assertContains( $id, $this->main_query_ids( $comment, '&exact=1&sentence=1' ), 'Visible details match, so the strip changes nothing.' );
	}

	public function test_an_exact_search_does_not_read_a_leading_dash_as_an_exclusion(): void {
		$id = $this->post( $this->card( 'exercise-card', [ 'activity' => 'Run', 'distance' => 7.77 ] ), 'exercise', '-Dash title' );

		$this->assertContains( $id, $this->main_query_ids( '-Dash title', '&exact=1&sentence=1' ), 'In exact mode core treats the dash as part of the phrase.' );
	}

	/**
	 * What the field comparison does in each mode, which the SQL can't show:
	 * the database has already matched the raw field, so the comparison only
	 * decides what the stripped field does.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
	 */
	public function comparison_cases(): array {
		return [
			'exact, whole field'            => [ 'Exact Title', 'exact title', true, true ],
			'exact, a substring'            => [ 'Exact Title', 'Exact', true, false ],
			'exact, accents are not folded' => [ 'Café', 'Cafe', true, false ],
			'loose, a substring'            => [ 'Exact Title', 'exact', false, true ],
			'loose, no match'               => [ 'Exact Title', 'other', false, false ],
		];
	}

	/**
	 * @dataProvider comparison_cases
	 *
	 * @param string $field    Field text.
	 * @param string $term     Search term.
	 * @param bool   $exact    Exact mode.
	 * @param bool   $expected Whether the field matches.
	 */
	public function test_the_field_comparison_follows_the_search_mode( string $field, string $term, bool $exact, bool $expected ): void {
		$method = new ReflectionMethod( Meta_Fields::class, 'search_matches' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( null, [ [ $term, false ] ], [ 'post_title' => $field ], $exact ) );
		$this->assertSame( ! $expected, $method->invoke( null, [ [ $term, true ] ], [ 'post_title' => $field ], $exact ), 'An excluded term inverts it.' );
	}
}
