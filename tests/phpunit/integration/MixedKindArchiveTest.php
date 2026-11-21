<?php
/**
 * Mixed-kind archive repair: one card for the queried kind (N7, option B).
 *
 * A post carrying Check-in and the queried like, repost, favorite or jam kind
 * shows one card for that kind on that kind's archive. The theme adds the
 * "Also a check-in" label; PKIW supplies the selection and the guarded facts.
 * The label's own gates are asserted here through the public APIs the theme
 * probes. Names, URLs and places are invented.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Public_Eligibility;
use PKIW\Taxonomy;

/**
 * Mixed-kind archive behavior.
 *
 * @group integration
 */
final class MixedKindArchiveTest extends WP_UnitTestCase {

	private const VENUE   = 'Sentinel Venue Mk91';
	private const ADDRESS = '91 Sentinel Row Mk';
	private const NOTE    = 'Sentinel check-in note Mk91';
	private const PLACE   = 'https://place.example.test/sentinel-mk91';
	private const PHOTO   = 'https://place.example.test/sentinel-mk91.jpg';
	private const COORD   = '41.919191';
	private const TARGET  = 'https://target.example.test/cited-page';
	private const OTHER   = 'https://other.example.test/ambiguous-shared-cite';

	/**
	 * Register the request environment.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'blocked', 'No network in tests.' ) );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		require_once ABSPATH . WPINC . '/class-phpass.php';
	}

	/**
	 * Restore URL, user and cookie state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A card block comment.
	 *
	 * @param string               $kind  Kind slug.
	 * @param array<string, mixed> $attrs Attributes.
	 */
	private function card( string $kind, array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/' . $kind . '-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * The target card for a kind.
	 *
	 * @param string $kind Kind slug.
	 * @param string $url  Target URL.
	 */
	private function target( string $kind, string $url = self::TARGET ): string {
		return $this->card(
			$kind,
			'jam' === $kind
				? [
					'title'  => 'Quiet Orchards',
					'artist' => 'Marlowe Kite',
					'url'    => $url,
				]
				: [
					'title'  => 'A Cited Page',
					'url'    => $url,
					'author' => 'Rowan Vale',
				]
		);
	}

	/**
	 * A Check-in card carrying every sentinel.
	 */
	private function checkin(): string {
		return $this->card(
			'checkin',
			[
				'venueName' => self::VENUE,
				'address'   => self::ADDRESS,
				'note'      => self::NOTE,
				'venueUrl'  => self::PLACE,
				'photo'     => self::PHOTO,
				'latitude'  => (float) self::COORD,
				'longitude' => -76.191919,
			]
		);
	}

	/**
	 * Create a published post.
	 *
	 * @param string[]             $kinds Kind slugs in assignment order.
	 * @param string               $slug  Slug.
	 * @param string               $date  Post date.
	 * @param string               $body  Post content.
	 * @param array<string, mixed> $args  Extra post arguments.
	 */
	private function post( array $kinds, string $slug, string $date, string $body, array $args = [] ): int {
		$id = wp_insert_post(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => ucfirst( str_replace( '-', ' ', $slug ) ),
					'post_name'    => $slug,
					'post_date'    => $date,
					'post_content' => wp_slash( $body ),
				],
				$args
			)
		);
		wp_set_object_terms( (int) $id, $kinds, Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * A mixed post.
	 *
	 * @param string               $kind Kind slug.
	 * @param string               $slug Slug.
	 * @param string               $body Content, '' for target then Check-in.
	 * @param array<string, mixed> $args Extra post arguments.
	 */
	private function mixed( string $kind, string $slug = 'mixed-row', string $body = '', array $args = [] ): int {
		return $this->post( [ $kind, 'checkin' ], $slug, '2026-08-20 10:00:00', '' === $body ? $this->target( $kind ) . $this->checkin() : $body, $args );
	}

	/**
	 * Fill an archive's neighbors so a mixed row has company on its page.
	 *
	 * @param string $kind Kind slug.
	 */
	private function neighbors( string $kind ): void {
		$this->post( [ $kind ], 'neighbor-one', '2026-08-25 10:00:00', $this->target( $kind, 'https://target.example.test/neighbor-one' ) );
		$this->post( [ $kind ], 'neighbor-two', '2026-07-12 10:00:00', $this->target( $kind, 'https://target.example.test/neighbor-two' ) );
	}

	/**
	 * Serve a kind archive from its resolved template.
	 *
	 * @param string $kind Kind slug.
	 */
	private function serve( string $kind ): string {
		if ( 'like' === $kind || 'checkin' === $kind ) {
			$markup  = '<!-- wp:query {"queryId":0,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/archive-sections {"linesPerPage":12} /--><!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
			$post_id = self::factory()->post->create(
				[
					'post_type'    => 'wp_template',
					'post_name'    => 'taxonomy-kind-' . $kind,
					'post_title'   => 'Test ' . $kind,
					'post_status'  => 'publish',
					'post_content' => $markup,
				]
			);
			wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );
		}
		$this->go_to( (string) get_term_link( $kind, Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-' . $kind . '.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * The list items on a page that belong to a slug.
	 *
	 * @param string $html Page HTML.
	 * @param string $slug Post slug.
	 * @return string[]
	 */
	private function rows( string $html, string $slug ): array {
		preg_match_all( '#<li\b[^>]*>.*?</li>#s', $html, $found );

		return array_values( array_filter( $found[0], static fn( string $li ): bool => str_contains( $li, '/' . $slug . '/' ) ) );
	}

	/**
	 * The one row of a slug, with the invariants every selected card keeps.
	 *
	 * @param string $kind Kind slug.
	 * @param string $slug Post slug.
	 * @param string $html Page HTML.
	 */
	private function one_card( string $kind, string $slug, string $html ): string {
		$rows = $this->rows( $html, $slug );
		$this->assertCount( 1, $rows, 'one li' );
		$row = $rows[0];
		$this->assertSame( 1, substr_count( $row, '<article' ), 'one winning artifact: ' . $row );
		$this->assertSame( 1, preg_match_all( '#<h3\b#', $row ), 'one H3' );
		$this->assertSame( 1, preg_match_all( '#<a\b[^>]*href="http://example\.org/' . preg_quote( $slug, '#' ) . '/"#', $row ), 'one permalink anchor' );
		$this->assertStringContainsString( 'k-' . $kind, $row );
		$this->assertStringNotContainsString( 'k-checkin', $row );
		$this->assertNoCheckinData( $row );

		return $row;
	}

	/**
	 * Fail when anything the Check-in carries reaches the markup.
	 *
	 * @param string $html Markup.
	 */
	private function assertNoCheckinData( string $html ): void {
		foreach ( [ self::VENUE, self::ADDRESS, self::NOTE, self::PLACE, self::PHOTO, self::COORD, 'checkin-card', 'p-location', 'h-adr', 'geo' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
	}

	/**
	 * Parsed top-level and nested h-entry count for a fragment.
	 *
	 * @param string $html Fragment.
	 */
	private function entry_count( string $html ): int {
		$count = 0;
		$walk  = static function ( array $items ) use ( &$walk, &$count ): void {
			foreach ( $items as $item ) {
				if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
					++$count;
				}
				$walk( $item['children'] ?? [] );
			}
		};
		$walk( Mf2\parse( $html, 'http://example.org/' )['items'] );

		return $count;
	}

	/**
	 * Every property value of a given name in a fragment's parse, flattened.
	 *
	 * @param string $html     Fragment.
	 * @param string $property Property name.
	 * @return string[]
	 */
	private function urls( string $html, string $property ): array {
		$out  = [];
		$walk = static function ( array $items ) use ( &$walk, &$out, $property ): void {
			foreach ( $items as $item ) {
				foreach ( $item['properties'][ $property ] ?? [] as $value ) {
					$out[] = is_array( $value ) ? (string) ( $value['properties']['url'][0] ?? '' ) : (string) $value;
				}
				$walk( $item['children'] ?? [] );
			}
		};
		foreach ( Mf2\parse( $html, 'http://example.org/' )['items'] as $item ) {
			$walk( [ $item ] );
			foreach ( $item['properties'] as $values ) {
				foreach ( $values as $value ) {
					if ( is_array( $value ) && isset( $value['type'] ) ) {
						$walk( [ $value ] );
					}
				}
			}
		}

		return $out;
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function kinds(): array {
		return [
			'like'     => [ 'like' ],
			'repost'   => [ 'repost' ],
			'favorite' => [ 'favorite' ],
			'jam'      => [ 'jam' ],
		];
	}

	/**
	 * Both block orders by both first-term orders, for each kind.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool}>
	 */
	public function orders(): array {
		$out = [];
		foreach ( array_keys( $this->kinds() ) as $kind ) {
			foreach ( [
				'target block first' => true,
				'check-in block first' => false,
			] as $block_label => $target_first ) {
				foreach ( [
					'kind term first'     => false,
					'check-in term first' => true,
				] as $term_label => $checkin_first ) {
					$out[ $kind . ', ' . $block_label . ', ' . $term_label ] = [ $kind, $target_first, $checkin_first ];
				}
			}
		}

		return $out;
	}

	/**
	 * @dataProvider orders
	 */
	public function test_a_mixed_row_shows_one_card_for_the_queried_kind_in_every_order( string $kind, bool $target_first, bool $checkin_first ): void {
		// Terms list by name, so renaming Check-in last puts the kind term first.
		if ( ! $checkin_first ) {
			wp_update_term( (int) get_term_by( 'slug', 'checkin', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY, [ 'name' => 'Zeta Check-in' ] );
		}
		$this->neighbors( $kind );
		$body = $target_first ? $this->target( $kind ) . $this->checkin() : $this->checkin() . $this->target( $kind );
		$id   = $this->mixed( $kind, 'mixed-row', $body );
		$this->assertSame( $checkin_first ? 'checkin' : $kind, Kind_Facts::kind_of( get_post( $id ) ), 'first-term order' );

		$html = $this->serve( $kind );
		$row  = $this->one_card( $kind, 'mixed-row', $html );

		$this->assertSame( 1, $this->entry_count( $row ), 'mf2 parses one h-entry' );
		$this->assertStringContainsString( self::TARGET, $row, 'the selected kind keeps its own target' );
		$this->assertSame( 1, substr_count( $row, self::TARGET ), 'the target once' );
	}

	public function test_an_unmixed_neighbor_still_renders_its_own_card(): void {
		$this->neighbors( 'like' );
		$this->mixed( 'like' );

		$row = $this->one_card( 'like', 'mixed-row', $this->serve( 'like' ) );

		$this->assertNotSame( '', $row );
	}

	/**
	 * Location and password variants. Each: post meta, post args, cookie, whether the card is a title-only shell, whether the theme's label gate opens.
	 *
	 * @return array<string, array{0: array<string, string>, 1: array<string, mixed>, 2: string, 3: bool, 4: bool}>
	 */
	public function states(): array {
		return [
			'public'                         => [ [ '_pkiw_geo_privacy' => 'public' ], [], 'none', false, true ],
			'approximate'                    => [ [ '_pkiw_geo_privacy' => 'approximate' ], [], 'none', false, true ],
			'unset privacy'                  => [ [], [], 'none', false, true ],
			'simple location protected'      => [
				[
					'_pkiw_geo_privacy' => 'public',
					'geo_public'        => '2',
				],
				[],
				'none',
				false,
				true,
			],
			'pkiw private location'          => [ [ '_pkiw_geo_privacy' => 'private' ], [], 'none', false, false ],
			'simple location private'        => [
				[
					'_pkiw_geo_privacy' => 'public',
					'geo_public'        => '0',
				],
				[],
				'none',
				false,
				false,
			],
			'stored password, valid cookie'  => [ [ '_pkiw_geo_privacy' => 'public' ], [ 'post_password' => 'secret' ], 'valid', false, false ],
			'stored password, no cookie'     => [ [ '_pkiw_geo_privacy' => 'public' ], [ 'post_password' => 'secret' ], 'none', true, false ],
			'stored password, wrong cookie'  => [ [ '_pkiw_geo_privacy' => 'public' ], [ 'post_password' => 'secret' ], 'wrong', true, false ],
		];
	}

	/**
	 * @dataProvider states
	 *
	 * @param array<string, string> $meta   Post meta.
	 * @param array<string, mixed>  $args   Post arguments.
	 * @param string                $cookie none, valid or wrong.
	 * @param bool                  $shell  Whether the row is a title-only shell.
	 * @param bool                  $gate   Whether the label's disclosure gate opens.
	 */
	public function test_each_privacy_state_prints_the_safe_card_and_sets_the_label_gate( array $meta, array $args, string $cookie, bool $shell, bool $gate ): void {
		foreach ( [ 'like', 'repost', 'favorite', 'jam' ] as $kind ) {
			$slug = 'state-' . $kind;
			$id   = $this->mixed( $kind, $slug, '', $args );
			foreach ( $meta as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}
			if ( 'none' !== $cookie ) {
				$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'valid' === $cookie ? 'secret' : 'nope' );
			}

			$html = $this->serve( $kind );
			$row  = $this->one_card( $kind, $slug, $html );

			$this->assertSame( $shell, str_contains( $row, 'pk-card--protected' ), $kind . ' shell' );
			if ( $shell ) {
				$this->assertStringNotContainsString( self::TARGET, $row, 'a shell reads no facts' );
				$this->assertStringNotContainsString( 'Rowan Vale', $row );
				$this->assertStringNotContainsString( 'u-' . $kind . '-of', $row );
			} else {
				$this->assertStringContainsString( self::TARGET, $row, $kind . ' positive control: the selected target is printed' );
			}
			$this->assertSame( $gate, true === ( Meta_Fields::get_public_location_fields( $id )['name'] ?? null ) && Public_Eligibility::is_eligible( $id ), $kind . ' label gate' );
		}
	}

	public function test_the_checkin_archive_prints_the_sentinels_the_mixed_row_must_not(): void {
		$id = $this->mixed( 'like', 'control-row' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );

		$checkin = $this->serve( 'checkin' );

		$this->assertStringContainsString( self::VENUE, $checkin, 'positive control: the Check-in card carries the sentinel' );
		$this->assertStringNotContainsString( self::VENUE, $this->serve( 'like' ) );
	}

	public function test_a_hidden_governed_post_is_omitted(): void {
		$this->neighbors( 'like' );
		$id = $this->post( [ 'like', 'checkin', 'tag' ], 'hidden-row', '2026-08-21 10:00:00', $this->target( 'like' ) . $this->checkin(), [ 'post_password' => 'secret' ] );
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'secret' );

		$html = $this->serve( 'like' );

		$this->assertTrue( Public_Eligibility::hides( $id ) );
		$this->assertCount( 0, $this->rows( $html, 'hidden-row' ) );
		$this->assertCount( 1, $this->rows( $html, 'neighbor-one' ), 'positive control: the page renders' );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function statuses(): array {
		return [
			'draft, logged out'   => [ 'draft', false ],
			'private, logged out' => [ 'private', false ],
			'draft, editor'       => [ 'draft', true ],
			'private, editor'     => [ 'private', true ],
		];
	}

	/**
	 * @dataProvider statuses
	 */
	public function test_a_draft_or_private_mixed_post_is_not_on_the_public_archive( string $status, bool $editor ): void {
		$this->neighbors( 'repost' );
		$this->mixed( 'repost', 'status-row', '', [ 'post_status' => $status ] );
		if ( $editor ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		}

		$html = $this->serve( 'repost' );

		$this->assertCount( 0, $this->rows( $html, 'status-row' ) );
		$this->assertCount( 1, $this->rows( $html, 'neighbor-one' ), 'positive control: the page renders' );
	}

	public function test_a_post_with_checkin_but_without_the_frame_kind_is_not_a_mixed_row(): void {
		$this->neighbors( 'like' );
		$id = $this->post( [ 'checkin' ], 'checkin-only', '2026-08-22 10:00:00', $this->checkin() );
		// A loop filter hands the like archive a row that never had the like term.
		add_filter(
			'the_posts',
			static function ( array $posts, WP_Query $query ) use ( $id ): array {
				if ( $query->is_main_query() && $query->is_tax() ) {
					$posts[] = get_post( $id );
				}
				return $posts;
			},
			10,
			2
		);

		$html = $this->serve( 'like' );
		$row  = $this->rows( $html, 'checkin-only' );

		$this->assertCount( 1, $row );
		$this->assertStringNotContainsString( 'k-like', $row[0], 'not rewritten into a like card' );
		$this->assertStringContainsString( 'k-checkin', $row[0], 'keeps its own card' );
		$this->assertSame( [], Kind_Facts::get_for_archive_kind( $id, 'like' ) );
	}

	public function test_the_first_exact_target_block_wins_even_when_wrapped_or_repeated(): void {
		$this->neighbors( 'repost' );
		$body = $this->checkin()
			. '<!-- wp:group --><div class="wp-block-group">' . $this->target( 'repost', 'https://target.example.test/first-wrapped' ) . '</div><!-- /wp:group -->'
			. $this->target( 'repost', 'https://target.example.test/second-repeat' );
		$this->mixed( 'repost', 'wrapped-row', $body );

		$row = $this->one_card( 'repost', 'wrapped-row', $this->serve( 'repost' ) );

		$this->assertStringContainsString( 'first-wrapped', $row );
		$this->assertStringNotContainsString( 'second-repeat', $row );
	}

	public function test_a_long_body_never_enters_the_card(): void {
		$this->neighbors( 'favorite' );
		$body = '<!-- wp:paragraph --><p>Long sentinel paragraph Lb77.</p><!-- /wp:paragraph -->' . $this->checkin() . $this->target( 'favorite' )
			. '<!-- wp:image --><figure class="wp-block-image"><img src="https://body.example.test/body-image.jpg" alt="Body image"/></figure><!-- /wp:image -->';
		$this->mixed( 'favorite', 'long-row', $body );

		$row = $this->one_card( 'favorite', 'long-row', $this->serve( 'favorite' ) );

		$this->assertStringNotContainsString( 'Lb77', $row );
		$this->assertStringNotContainsString( 'body-image', $row );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_a_meta_only_target_uses_its_own_meta_and_never_shared_cite_meta( string $kind ): void {
		$this->neighbors( $kind );
		$own = [
			'like'     => 'like_of',
			'repost'   => 'repost_of',
			'favorite' => 'favorite_url',
			'jam'      => 'jam_url',
		][ $kind ];
		$id  = $this->mixed( $kind, 'legacy-row', $this->checkin() );
		update_post_meta( $id, '_pkiw_' . $own, self::TARGET );
		update_post_meta( $id, '_pkiw_cite_url', self::OTHER );
		$bare = $this->mixed( $kind, 'shared-only-row', $this->checkin() );
		update_post_meta( $bare, '_pkiw_cite_url', self::OTHER );
		update_post_meta( $bare, '_pkiw_cite_photo', 'https://other.example.test/shared.jpg' );

		$html = $this->serve( $kind );

		$this->assertStringContainsString( self::TARGET, $this->one_card( $kind, 'legacy-row', $html ), 'the kind-owned key supplies the target' );
		$this->assertStringNotContainsString( self::OTHER, $this->one_card( $kind, 'shared-only-row', $html ), 'ambiguous shared cite meta is dropped' );
		$this->assertStringNotContainsString( 'shared.jpg', $html );
	}

	public function test_authored_like_attributes_beat_ambiguous_shared_cite_meta(): void {
		$this->neighbors( 'like' );
		$id = $this->mixed( 'like', 'authored-row' );
		update_post_meta( $id, '_pkiw_cite_url', self::OTHER );

		$row = $this->one_card( 'like', 'authored-row', $this->serve( 'like' ) );

		$this->assertStringContainsString( self::TARGET, $row );
		$this->assertStringNotContainsString( self::OTHER, $row );
		$this->assertSame( [ self::TARGET ], array_values( array_unique( $this->urls( $row, 'like-of' ) ) ) );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_an_empty_or_unsafe_target_falls_back_to_a_visitor_safe_card( string $kind ): void {
		$this->neighbors( $kind );
		$this->mixed( $kind, 'unsafe-row', $this->card( $kind, [ 'url' => 'javascript:alert(1)' ] ) . $this->checkin() );
		$this->mixed( $kind, 'empty-row', $this->card( $kind, [] ) . $this->checkin(), [ 'post_title' => '' ] );

		$html  = $this->serve( $kind );
		$unsafe = $this->one_card( $kind, 'unsafe-row', $html );

		$this->assertStringNotContainsString( 'javascript:', $unsafe );
		$this->assertSame( [], $this->urls( $unsafe, $kind . '-of' ), 'no citation URL for an unsafe target' );
		$empty = $this->rows( $html, 'empty-row' );
		$this->assertCount( 1, $empty, 'an untitled row still renders one card' );
		$this->assertMatchesRegularExpression( '#<h3[^>]*>.*?<a[^>]*>[^<]*\S[^<]*#s', $empty[0], 'a non-empty visitor-safe name' );
	}

	public function test_a_card_less_jam_with_a_checkin_url_prints_no_jam_of_url(): void {
		$this->neighbors( 'jam' );
		$this->mixed( 'jam', 'cardless-jam', $this->checkin() . '<!-- wp:paragraph --><p><a class="u-jam-of" href="' . self::PLACE . '">place</a></p><!-- /wp:paragraph -->' );

		$row = $this->one_card( 'jam', 'cardless-jam', $this->serve( 'jam' ) );

		$this->assertSame( [], $this->urls( $row, 'jam-of' ) );
		$this->assertStringNotContainsString( 'u-jam-of', $row );
	}

	public function test_a_mixed_jam_takes_the_lead_slot_and_keeps_cover_rules(): void {
		$this->neighbors( 'jam' );
		$id = $this->mixed(
			'jam',
			'lead-jam',
			$this->checkin() . $this->card(
				'jam',
				[
					'title'  => 'Lead Track',
					'artist' => 'Marlowe Kite',
					'cover'  => 'https://cover.example.test/lead.jpg',
				]
			),
			[ 'post_date' => '2026-09-01 10:00:00' ]
		);
		set_post_thumbnail( $id, self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id ) );
		$nocover = $this->mixed( 'jam', 'plain-jam', $this->checkin() . $this->target( 'jam' ), [ 'post_date' => '2026-08-30 10:00:00' ] );

		$html = $this->serve( 'jam' );

		$this->assertStringContainsString( 'data-pkiw-lead="1"', $html );
		$lead = $this->one_card( 'jam', 'lead-jam', $html );
		$this->assertLessThan( strpos( $html, 'class="pkiw-group"' ), strpos( $html, $lead ), 'the lead precedes the month sections' );
		$this->assertStringNotContainsString( 'canola', $lead, 'a Check-in featured image is not imported as a cover' );
		$plain = $this->one_card( 'jam', 'plain-jam', $html );
		$this->assertStringNotContainsString( 'pk-media', $plain, 'no cover when the Jam has none' );
		$this->assertGreaterThan( 0, $nocover );
	}

	public function test_the_selector_and_facts_api_answer_only_inside_the_archive_row(): void {
		$id   = $this->mixed( 'like' );
		$post = get_post( $id );
		$this->go_to( (string) get_term_link( 'like', Taxonomy::TAXONOMY ) );
		$block = new WP_Block( [ 'blockName' => 'post-kinds-indieweb/stream-card' ], [ 'postId' => $id ] );

		$this->assertSame( '', PKIW\mixed_kind_archive_slug( $post, $block ), 'no frame, no selection' );
		$this->assertSame( '', Grouped_Archive::current_archive_kind() );
		$this->assertSame( [], Kind_Facts::get_for_archive_kind( $id, 'like' ) );
	}

	public function test_a_loop_nested_in_the_row_and_a_second_loop_on_the_url_are_not_selected(): void {
		$this->neighbors( 'like' );
		$this->mixed( 'like', 'frame-row' );
		$this->go_to( (string) get_term_link( 'like', Taxonomy::TAXONOMY ) );
		$second = do_blocks( '<!-- wp:query {"queryId":9,"query":{"perPage":5,"postType":"post","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /--><!-- /wp:post-template --></div><!-- /wp:query -->' );

		$this->assertStringContainsString( 'frame-row', $second );
		$this->assertSame( '', Grouped_Archive::current_archive_kind() );
		$row = $this->rows( $second, 'frame-row' )[0] ?? '';
		$this->assertStringNotContainsString( 'pk-card--stream k-like h-entry', $row, 'an independent loop is not rewritten into the selected card' );
	}

	public function test_the_default_cite_call_is_unchanged(): void {
		$id = $this->post( [ 'like' ], 'plain-like', '2026-08-01 10:00:00', $this->target( 'like' ) );
		$post = get_post( $id );

		$this->assertSame( PKIW\stream_card_kind_cite( $post ), PKIW\stream_card_kind_cite( $post, null, '' ) );
		$this->assertStringContainsString( self::TARGET, PKIW\stream_card_kind_cite( $post ) );
	}
}
