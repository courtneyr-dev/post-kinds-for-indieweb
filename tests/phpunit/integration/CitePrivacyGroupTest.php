<?php
/**
 * Grouped cite archives: password-protected posts file in the empty group.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;
use PKIW\Grouping\Term_Source;
use PKIW\Kind_Archive_Layouts;

/**
 * Cite, review and bookmark archives keep protected posts out of public
 * per-source headings in PHP, SQL and rendered sections. Fixture kinds,
 * sources and entry blocks register in set_up() and go away in tear_down();
 * names, hosts and titles are invented.
 *
 * @group integration
 */
final class CitePrivacyGroupTest extends WP_UnitTestCase {

	private const MARKER = 'pkiw-test/cite-marker';

	private const SOURCES = [ 'test_review_cats', 'test_bookmark_tags', 'order_meta', 'order_cats', 'plain_tags' ];

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 20 );

		$this->entry_block( self::MARKER, [ 'expose_key' => false ] );
		Grouped_Archive::register_source( new Term_Source( 'test_review_cats', 'category', null, '', true ), 'reviewkind' );
		Grouped_Archive::register_source( new Term_Source( 'test_bookmark_tags', 'post_tag', null, '', true ), 'bookmarkkind' );
	}

	public function tear_down(): void {
		Grouped_Archive::unregister_entry_block( self::MARKER );
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::MARKER ) ) {
			unregister_block_type( self::MARKER );
		}
		foreach ( self::SOURCES as $id ) {
			Grouped_Archive::unregister_source( $id );
		}
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * Register a fixture entry block and its block type.
	 *
	 * @param string               $name Block name.
	 * @param array<string, mixed> $args Entry args.
	 */
	private function entry_block( string $name, array $args ): void {
		Grouped_Archive::register_entry_block( $name, $args );
		register_block_type(
			$name,
			[
				'api_version'     => 3,
				'uses_context'    => [ 'postId', 'postType', 'queryId' ],
				'attributes'      => Grouped_Archive::entry_attributes(),
				'render_callback' => function ( array $attributes, string $content, WP_Block $block ) use ( $name ): string {
					return Grouped_Archive::render_entry(
						$name,
						$attributes,
						$block,
						static function ( WP_Post $post, bool $in_section ): string {
							$group = Grouped_Archive::current_group();

							return sprintf(
								'<p class="line" data-in-section="%d" data-group="%s">%s</p>',
								$in_section ? 1 : 0,
								esc_attr( $group ? $group->key() : '-' ),
								esc_html( get_the_title( $post ) )
							);
						}
					);
				},
			]
		);
	}

	/**
	 * A published post of a kind.
	 *
	 * @param string                         $kind     Kind slug.
	 * @param string                         $title    Title.
	 * @param string                         $date     Post date.
	 * @param string                         $password Post password.
	 * @param array<string, string|string[]> $meta     Meta key => value, or values for several rows.
	 * @param array<string, array<int|string>> $terms  Taxonomy => terms.
	 */
	private function make( string $kind, string $title, string $date, string $password = '', array $meta = [], array $terms = [] ): int {
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_date'     => $date,
				'post_password' => $password,
				'post_content'  => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $id, $kind, 'kind' );
		foreach ( $meta as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_post_meta( $id, $key, $value );
			}
		}
		foreach ( $terms as $taxonomy => $list ) {
			wp_set_object_terms( $id, $list, $taxonomy );
		}

		return $id;
	}

	/**
	 * An inherited Query Loop whose Post Template holds an entry block.
	 *
	 * @param string               $entry Entry block name.
	 * @param array<string, mixed> $attrs Entry attributes.
	 */
	private function loop( string $entry, array $attrs = [] ): string {
		$block = '<!-- wp:' . $entry . ( $attrs ? ' ' . wp_json_encode( $attrs ) : '' ) . ' /-->';

		return '<!-- wp:query {"queryId":7,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /-->' . $block . '<!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * Serve a kind archive from a template holding the given content and render it.
	 *
	 * @param string $kind     Kind slug.
	 * @param string $template Template content.
	 */
	private function serve( string $kind, string $template ): string {
		$swap = static function ( $templates ) use ( $kind, $template ) {
			foreach ( $templates as $found ) {
				if ( in_array( $found->slug, [ 'taxonomy-kind', "taxonomy-kind-{$kind}" ], true ) ) {
					$found->content = $template;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$url = get_term_link( $kind, 'kind' );
		$this->assertIsString( $url );
		$this->go_to( $url );
		$html = do_blocks( $template );
		remove_filter( 'get_block_templates', $swap );

		return $html;
	}

	/**
	 * Each section: heading tag and text, then its items' titles.
	 *
	 * @return array<int, array{0:string,1:string[]}>
	 */
	private function sections( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$titles = [];
			foreach ( $xpath->query( './ul/li//*[contains(@class, "wp-block-post-title") or @class="line"]', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$heading = $section->firstChild;
			$out[]   = [ $heading ? $heading->nodeName . ':' . trim( $heading->textContent ) : '', $titles ];
		}

		return $out;
	}

	/**
	 * Post IDs of a kind in a source's grouped order.
	 *
	 * @param string               $kind   Kind slug.
	 * @param string               $source Source id.
	 * @param array<string, mixed> $args   Extra query args.
	 * @return int[]
	 */
	private function grouped_ids( string $kind, string $source, array $args = [] ): array {
		$query = new WP_Query(
			array_merge(
				[
					'post_type'         => 'post',
					'posts_per_page'    => -1,
					'fields'            => 'ids',
					'pkiw_group_source' => $source,
					'tax_query'         => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => $kind ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				],
				$args
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Assert that the SQL order keeps each PHP group together.
	 *
	 * @param string $source Source id.
	 * @param int[]  $ids    Post IDs in SQL order.
	 */
	private function assert_contiguous( string $source, array $ids ): void {
		$seen = [];
		$last = null;
		foreach ( $ids as $id ) {
			$key = Grouped_Archive::source( $source )->group_of( get_post( $id ) )->key();
			if ( $key !== $last ) {
				$this->assertNotContains( $key, $seen, "Group \"{$key}\" of {$source} comes back after another group." );
				$seen[] = $key;
				$last   = $key;
			}
		}
	}

	/**
	 * Sources with protected-empty behavior.
	 *
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public function protected_sources(): array {
		return [
			'cite_host'         => [ 'cite_host', 'like', 'cite' ],
			'review categories' => [ 'test_review_cats', 'reviewkind', 'category' ],
			'bookmark tags'     => [ 'test_bookmark_tags', 'bookmarkkind', 'tag' ],
		];
	}

	/**
	 * Six posts for a grouped privacy case.
	 *
	 * @param string $kind  Kind slug.
	 * @param string $shape Fixture shape.
	 * @return array<string, int>
	 */
	private function privacy_posts( string $kind, string $shape ): array {
		$alpha = 'alpha.example';
		$beta  = 'beta.example';
		if ( 'category' === $shape ) {
			$alpha = (int) self::factory()->category->create( [ 'name' => 'Alpha Books' ] );
			$beta  = (int) self::factory()->category->create( [ 'name' => 'Beta Books' ] );
		} elseif ( 'tag' === $shape ) {
			$alpha = 'Alpha Tag';
			$beta  = 'Beta Tag';
		}

		$posts = [
			'a1'     => $this->privacy_post( $kind, $shape, 'P_a1', '2026-02-06 10:00:00', '', $alpha ),
			'b1'     => $this->privacy_post( $kind, $shape, 'P_b1', '2026-02-05 10:00:00', '', $beta ),
			'secret' => $this->privacy_post( $kind, $shape, 'P_secret', '2026-02-04 10:00:00', 'secret', $alpha ),
			'spaces' => $this->privacy_post( $kind, $shape, 'P_spaces', '2026-02-03 10:00:00', '  ', $beta ),
			'none'   => $this->privacy_post( $kind, $shape, 'P_none', '2026-02-02 10:00:00', '', null ),
			'a2'     => $this->privacy_post( $kind, $shape, 'P_a2', '2026-02-01 10:00:00', '', $alpha ),
		];
		if ( 'category' === $shape ) {
			wp_set_object_terms( $posts['none'], [], 'category' );
		}

		return $posts;
	}

	/**
	 * One post for a grouped privacy case.
	 *
	 * @param string          $kind     Kind slug.
	 * @param string          $shape    Fixture shape.
	 * @param string          $title    Title.
	 * @param string          $date     Post date.
	 * @param string          $password Post password.
	 * @param int|string|null $group    Group value.
	 */
	private function privacy_post( string $kind, string $shape, string $title, string $date, string $password, $group ): int {
		if ( 'cite' === $shape ) {
			return $this->make(
				$kind,
				$title,
				$date,
				$password,
				null === $group ? [] : [ \PKIW\CITE_HOST_META => (string) $group ]
			);
		}
		if ( 'category' === $shape ) {
			return $this->make( $kind, $title, $date, $password, [], null === $group ? [] : [ 'category' => [ (int) $group ] ] );
		}

		return $this->make( $kind, $title, $date, $password, [], null === $group ? [] : [ 'post_tag' => [ (string) $group ] ] );
	}

	/**
	 * Rendered section headings for a source shape.
	 *
	 * @param string $shape Fixture shape.
	 * @return string[]
	 */
	private function expected_headings( string $shape ): array {
		if ( 'category' === $shape ) {
			return [ 'h2:Alpha Books', 'h2:Beta Books', 'h2:Other' ];
		}
		if ( 'tag' === $shape ) {
			return [ 'h2:Alpha Tag', 'h2:Beta Tag', 'h2:Other' ];
		}

		return [ 'h2:alpha.example', 'h2:beta.example', 'h2:Other' ];
	}

	public function test_the_flag_is_off_by_default(): void {
		$meta = new Meta_Source( 'default_meta', [ '_pkiw_default_group' ] );
		$term = new Term_Source( 'default_terms', 'post_tag' );
		$id   = $this->make(
			'defaultkind',
			'Default privacy',
			'2026-03-01 10:00:00',
			'secret',
			[ '_pkiw_default_group' => 'Alpha' ],
			[ 'post_tag' => [ 'Alpha Tag' ] ]
		);

		$this->assertFalse( $meta->files_protected_as_empty() );
		$this->assertFalse( $term->files_protected_as_empty() );
		$this->assertSame( 'alpha', $meta->group_of( get_post( $id ) )->key() );
		$this->assertNotSame( '', $term->group_of( get_post( $id ) )->key() );
		$this->assertStringNotContainsString( 'post_password', $meta->sql( new WP_Query() )['value'] );
		$this->assertStringNotContainsString( 'post_password', $term->sql( new WP_Query() )['value'] );

		$eat_key = Kind_Archive_Layouts::group_fields()['eat'];
		$eat_id  = $this->make( 'eat', 'Default eat privacy', '2026-03-02 10:00:00', 'secret', [ $eat_key => 'Thai' ] );
		$legacy  = Meta_Source::legacy( $eat_key, 'eat' );

		$this->assertSame( 'thai', Grouped_Archive::group_of_post( 'eat', $eat_id )->key() );
		$this->assertSame( 'thai', $legacy->group_of( get_post( $eat_id ) )->key() );
		$this->assertStringNotContainsString( 'post_password', $legacy->sql( new WP_Query() )['value'] );
	}

	/**
	 * @dataProvider protected_sources
	 */
	public function test_protected_posts_file_in_the_empty_group_in_php_and_sql( string $source_id, string $kind, string $shape ): void {
		$p      = $this->privacy_posts( $kind, $shape );
		$source = Grouped_Archive::source( $source_id );

		foreach ( [ 'secret', 'spaces' ] as $key ) {
			$group = $source->group_of( get_post( $p[ $key ] ) );
			$this->assertTrue( $group->is_empty(), $key );
			$this->assertSame( '', $group->key(), $key );
		}

		$ids = $this->grouped_ids( $kind, $source_id );
		$this->assert_contiguous( $source_id, $ids );
		$this->assertSame( [ $p['secret'], $p['spaces'], $p['none'] ], array_slice( $ids, -3 ) );
		$this->assertLessThan( array_search( $p['b1'], $ids, true ), array_search( $p['a1'], $ids, true ) );

		$this->assertSame( [ $p['secret'], $p['spaces'], $p['none'] ], array_slice( $this->grouped_ids( $kind, $source_id, [ 'pkiw_group_empty' => 'first' ] ), 0, 3 ) );
	}

	/**
	 * @dataProvider protected_sources
	 */
	public function test_a_visitor_with_the_post_password_cookie_still_gets_the_empty_group( string $source_id, string $kind, string $shape ): void {
		$p = $this->privacy_posts( $kind, $shape );

		require_once ABSPATH . WPINC . '/class-phpass.php';
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'secret' );

		$this->assertFalse( post_password_required( $p['secret'] ) );
		$this->assertTrue( post_password_required( $p['spaces'] ) );

		$source = Grouped_Archive::source( $source_id );
		$this->assertTrue( $source->group_of( get_post( $p['secret'] ) )->is_empty() );
		$this->assertSame( [ $p['secret'], $p['spaces'], $p['none'] ], array_slice( $this->grouped_ids( $kind, $source_id ), -3 ) );

		$sections = $this->sections( $this->serve( $kind, $this->loop( self::MARKER ) ) );
		$headings = array_column( $sections, 0 );
		$this->assertSame( count( $headings ), count( array_unique( $headings ) ) );
		$this->assertCount( 3, $sections );
		$this->assertSame( $this->expected_headings( $shape ), $headings );
		$this->assertCount( 3, $sections[2][1] );
	}

	public function test_every_sql_part_treats_a_password_as_empty(): void {
		$meta = new Meta_Source( 'sql_meta', [ '_pkiw_sql_group' ], [ 'alpha', 'beta' ], Label_Mode::verbatim(), '', true );
		$term = new Term_Source( 'sql_terms', 'category', static fn(): int => (int) get_option( 'default_category' ), '', true );
		$query = new WP_Query();

		$meta_sql = $meta->sql( $query );
		$term_sql = $term->sql( $query );
		$this->assertArrayHasKey( 'rank', $meta_sql );
		$this->assertArrayHasKey( 'last', $term_sql );

		foreach ( [ $meta_sql, $term_sql ] as $parts ) {
			foreach ( [ 'value', 'rank', 'tiebreak', 'last' ] as $key ) {
				if ( ! isset( $parts[ $key ] ) ) {
					continue;
				}
				$this->assertMatchesRegularExpression( '/LENGTH\s*\(\s*\S*post_password\s*\)\s*>\s*0/', $parts[ $key ], $key );
				$this->assertDoesNotMatchRegularExpression( "/post_password\s*=\s*''/", $parts[ $key ], $key );
			}
		}
	}

	/**
	 * Sources whose empty group must keep date order.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function protected_empty_order_cases(): array {
		return [
			'meta rank'             => [ 'meta' ],
			'term default last'     => [ 'term_default' ],
			'term tiebreak by name' => [ 'term_zeta' ],
		];
	}

	/**
	 * @dataProvider protected_empty_order_cases
	 */
	public function test_protected_posts_keep_the_empty_groups_date_order( string $shape ): void {
		if ( 'meta' === $shape ) {
			Grouped_Archive::register_source( new Meta_Source( 'order_meta', [ '_pkiw_order_group' ], [ 'alpha', 'beta' ], null, '', true ), 'ordermeta' );
			$protected = $this->make( 'ordermeta', 'Protected alpha', '2026-04-01 10:00:00', 'secret', [ '_pkiw_order_group' => 'alpha' ] );
			$public    = $this->make( 'ordermeta', 'Public empty', '2026-04-02 10:00:00' );

			$this->assertSame( [ $public, $protected ], $this->grouped_ids( 'ordermeta', 'order_meta' ) );
			return;
		}

		Grouped_Archive::register_source( new Term_Source( 'order_cats', 'category', static fn(): int => (int) get_option( 'default_category' ), '', true ), 'ordercats' );
		$term = (int) get_option( 'default_category' );
		if ( 'term_zeta' === $shape ) {
			$term = (int) self::factory()->category->create( [ 'name' => 'Zeta Cat' ] );
		}
		$protected = $this->make( 'ordercats', 'Protected term', '2026-04-02 10:00:00', 'secret', [], [ 'category' => [ $term ] ] );
		$public    = $this->make( 'ordercats', 'Public empty', '2026-04-01 10:00:00' );
		wp_set_object_terms( $public, [], 'category' );

		$this->assertSame( [ $protected, $public ], $this->grouped_ids( 'ordercats', 'order_cats' ) );
	}

	public function test_a_source_with_the_flag_off_is_unchanged_in_sql_order(): void {
		Grouped_Archive::register_source( new Term_Source( 'plain_tags', 'post_tag' ), 'plaintags' );
		$public = $this->make( 'plaintags', 'Apple public', '2026-05-03 10:00:00', '', [], [ 'post_tag' => [ 'Apple' ] ] );
		$secret = $this->make( 'plaintags', 'Apple secret', '2026-05-02 10:00:00', 'secret', [], [ 'post_tag' => [ 'Apple' ] ] );
		$banana = $this->make( 'plaintags', 'Banana public', '2026-05-01 10:00:00', '', [], [ 'post_tag' => [ 'Banana' ] ] );
		$empty  = $this->make( 'plaintags', 'Empty public', '2026-05-04 10:00:00' );
		$source = Grouped_Archive::source( 'plain_tags' );

		$this->assertSame( 'Apple', $source->group_of( get_post( $secret ) )->raw() );
		$this->assertSame( [ $public, $secret, $banana, $empty ], $this->grouped_ids( 'plaintags', 'plain_tags' ) );
	}
}
