<?php
/**
 * Jam archive microformats coverage for issue 235.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Each jam archive item is one h-entry whose nested jam-of h-cite reflects
 * only facts that were stored for the jam.
 *
 * @group integration
 */
final class JamMicroformatsTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Register the real archive request environment.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'blocked', 'No network in tests.' )
		);
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Site Author' ] ) );
	}

	/**
	 * Restore theme, URL and user state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A serialized jam-card comment.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/jam-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * Insert a published jam in the same path as production saves.
	 *
	 * @param string $title   Post title.
	 * @param string $date    Local post date.
	 * @param string $content Post content.
	 */
	private function jam( string $title, string $date, string $content ): int {
		$id = wp_insert_post(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => wp_slash( $content ),
				'post_author'  => get_current_user_id(),
			],
			true
		);
		$this->assertIsInt( $id );
		wp_set_object_terms( $id, 'jam', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * Visit and render the plugin's jam archive template.
	 */
	private function serve(): string {
		$this->go_to( (string) get_term_link( 'jam', Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-jam.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-jam', $template->id );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Every parsed h-entry, keyed by its URL.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function entries( string $html ): array {
		$entries = [];
		$walk    = static function ( array $items ) use ( &$walk, &$entries ): void {
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
					foreach ( $item['properties']['url'] ?? [] as $url ) {
						$entries[ (string) $url ] = $item;
					}
				}
				$walk( $item['children'] ?? [] );
			}
		};
		$walk( \Mf2\parse( $html, 'http://example.org/' )['items'] ?? [] );

		return $entries;
	}

	public function test_card_micropub_and_meta_shapes_are_one_entry_each_with_nested_jam_cites(): void {
		$card = $this->jam(
			'Hollow Light',
			'2026-08-28 10:00:00',
			$this->card(
				[
					'title'    => 'Hollow Light',
					'artist'   => 'Marlowe Kite',
					'url'      => 'https://tracks.example.org/t/hollow',
					'jammedAt' => '2026-08-28',
				]
			)
		);
		$micropub = $this->jam(
			'',
			'2026-08-27 10:00:00',
			'<p>Jamming to <a class="u-jam-of" href="https://tracks.example.org/t/1">https://tracks.example.org/t/1</a></p>'
		);
		$quick = $this->jam( 'The Quiet Orchards by Marlowe Kite', '2026-08-26 10:00:00', '<p>Quick jam.</p>' );
		update_post_meta( $quick, Meta_Fields::PREFIX . 'jam_track', 'The Quiet Orchards' );
		update_post_meta( $quick, Meta_Fields::PREFIX . 'jam_artist', 'Marlowe Kite' );

		$entries = $this->entries( $this->serve() );
		$this->assertCount( 3, $entries, 'The Query Loop li does not become a second microformats root.' );

		$card_entry = $entries[ (string) get_permalink( $card ) ] ?? null;
		$this->assertIsArray( $card_entry );
		$this->assertContains( 'h-entry', $card_entry['type'] );
		$this->assertSame( [ 'Hollow Light' ], $card_entry['properties']['name'] ?? null );
		$this->assertNotEmpty( $card_entry['properties']['published'] ?? [] );
		$this->assertSame( 'Site Author', $card_entry['properties']['author'][0]['properties']['name'][0] ?? null );
		$this->assertCount( 1, $card_entry['properties']['jam-of'] ?? [] );
		$card_cite = $card_entry['properties']['jam-of'][0];
		$this->assertContains( 'h-cite', $card_cite['type'] ?? [] );
		$this->assertSame( [ 'Hollow Light' ], $card_cite['properties']['name'] ?? null );
		$this->assertSame( [ 'https://tracks.example.org/t/hollow' ], $card_cite['properties']['url'] ?? null );
		$this->assertSame( 'Marlowe Kite', $card_cite['properties']['author'][0]['properties']['name'][0] ?? null );

		$micropub_entry = $entries[ (string) get_permalink( $micropub ) ] ?? null;
		$this->assertIsArray( $micropub_entry );
		$this->assertArrayNotHasKey( 'name', $micropub_entry['properties'], 'A synthetic display name never becomes p-name.' );
		$this->assertSame( 'Site Author', $micropub_entry['properties']['author'][0]['properties']['name'][0] ?? null );
		$this->assertNotEmpty( $micropub_entry['properties']['published'] ?? [] );
		$micropub_cite = $micropub_entry['properties']['jam-of'][0] ?? null;
		$this->assertIsArray( $micropub_cite );
		$this->assertContains( 'h-cite', $micropub_cite['type'] ?? [] );
		$this->assertSame( [ 'https://tracks.example.org/t/1' ], $micropub_cite['properties']['url'] ?? null );
		$this->assertSame( [ '' ], $micropub_cite['properties']['name'] ?? [ '' ], 'The cite prints no name; the parser implies an empty one.' );
		$this->assertArrayNotHasKey( 'author', $micropub_cite['properties'] );

		$quick_entry = $entries[ (string) get_permalink( $quick ) ] ?? null;
		$this->assertIsArray( $quick_entry );
		$this->assertSame( [ 'The Quiet Orchards' ], $quick_entry['properties']['name'] ?? null );
		$quick_cite = $quick_entry['properties']['jam-of'][0] ?? null;
		$this->assertIsArray( $quick_cite );
		$this->assertSame( [ 'The Quiet Orchards' ], $quick_cite['properties']['name'] ?? null );
		$this->assertSame( 'Marlowe Kite', $quick_cite['properties']['author'][0]['properties']['name'][0] ?? null );
		$this->assertArrayNotHasKey( 'url', $quick_cite['properties'] );
	}

	public function test_untitled_cardless_jam_has_no_entry_name_property(): void {
		$id = $this->jam( '', '2026-08-25 10:00:00', '<p>A jam with no cited details.</p>' );

		$entry = $this->entries( $this->serve() )[ (string) get_permalink( $id ) ] ?? null;

		$this->assertIsArray( $entry );
		$this->assertArrayNotHasKey( 'name', $entry['properties'] );
		$this->assertSame( [ (string) get_permalink( $id ) ], $entry['properties']['url'] ?? null );
		$this->assertNotEmpty( $entry['properties']['published'] ?? [] );
		$this->assertSame( 'Site Author', $entry['properties']['author'][0]['properties']['name'][0] ?? null );
		$this->assertArrayNotHasKey( 'jam-of', $entry['properties'], 'An empty citation prints no h-cite.' );
	}
}
