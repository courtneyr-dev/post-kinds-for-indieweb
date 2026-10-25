<?php
/**
 * Venue archive URLs, template, old-link redirect and term screen hooks
 * (issue 492).
 *
 * Simple Location registers a public, hierarchical `venue` post type with
 * rewrite slug `venue` before this plugin's taxonomy, so its rules win
 * `/venue/<slug>/`. These tests register a stand-in for it the same way.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Venue_Taxonomy;

/**
 * @group integration
 */
final class VenueUrlsTest extends WP_UnitTestCase {

	private const STAND_IN = 'venue';

	private static string $theme_root = '';

	private string $original_stylesheet = '';

	private string $original_home = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-venue-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-venue-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Venue Theme\n*/\n" );
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' );
		file_put_contents( $theme . '/templates/taxonomy-pkiw_venue.html', '<!-- wp:paragraph --><p>theme-owned-venue-archive</p><!-- /wp:paragraph -->' );
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-pkiw_venue.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-venue-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-venue-theme/templates' );
		rmdir( self::$theme_root . '/pkiw-venue-theme' );
		rmdir( self::$theme_root );
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_home       = (string) get_option( 'home' );

		// Pretty permalinks first: register_taxonomy() and register_post_type()
		// only add their permastructs when they're on. Then Simple Location's
		// venue post type, then the taxonomy, as on a live site where its
		// rules land ahead of ours.
		$this->set_permalink_structure( '/%postname%/' );
		unregister_taxonomy( Venue_Taxonomy::TAXONOMY );
		register_post_type(
			self::STAND_IN,
			[
				'label'        => 'Venues',
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [ 'slug' => 'venue' ],
				'supports'     => [ 'title', 'editor', 'geo-location' ],
			]
		);
		$this->register_venue_taxonomy();
		flush_rewrite_rules( false );

		remove_action( 'template_redirect', 'redirect_canonical' );
	}

	public function tear_down(): void {
		update_option( 'home', $this->original_home );
		unregister_post_type( self::STAND_IN );
		unregister_taxonomy( Venue_Taxonomy::TAXONOMY );
		$this->set_permalink_structure( '' );
		$this->register_venue_taxonomy();

		switch_theme( $this->original_stylesheet );
		foreach ( [ dirname( __DIR__ ) . '/fixtures/themes', self::$theme_root ] as $root ) {
			$at = array_search( $root, $GLOBALS['wp_theme_directories'], true );
			if ( false !== $at ) {
				unset( $GLOBALS['wp_theme_directories'][ $at ] );
			}
		}
		search_theme_directories( true );
		wp_clean_themes_cache();

		$_POST = [];
		parent::tear_down();
	}

	// --- Rewrite ---------------------------------------------------------

	public function test_the_term_link_lives_under_venues(): void {
		$term = $this->venue( 'automattic-offices' );

		$this->assertSame( home_url( '/venues/automattic-offices/' ), get_term_link( $term ) );
	}

	public function test_the_term_link_reaches_the_venue_archive_next_to_the_venue_post_type(): void {
		$term = $this->venue( 'automattic-offices' );
		$this->checkin_at( $term );

		$this->go_to( (string) get_term_link( $term ) );

		$this->assertTrue( is_tax( Venue_Taxonomy::TAXONOMY ), 'The term link should resolve to the venue archive.' );
		$this->assertFalse( is_404() );
		$this->assertSame( $term->term_id, get_queried_object_id() );
	}

	public function test_the_old_path_belongs_to_the_venue_post_type(): void {
		$term = $this->venue( 'automattic-offices' );
		$this->checkin_at( $term );

		$this->go_to( home_url( '/venue/automattic-offices/' ) );

		$this->assertFalse( is_tax( Venue_Taxonomy::TAXONOMY ) );
		$this->assertTrue( is_404() );
	}

	// --- Old-link redirect -----------------------------------------------

	public function test_the_old_path_301s_to_the_term_link_when_it_404s(): void {
		$term = $this->venue( 'automattic-offices' );
		$this->checkin_at( $term );

		$this->go_to( home_url( '/venue/automattic-offices/' ) );

		$this->assertSame( [ 301, home_url( '/venues/automattic-offices/' ) ], $this->redirect() );
	}

	public function test_the_old_paged_path_301s_to_the_same_page_of_the_term(): void {
		$term = $this->venue( 'automattic-offices' );
		$this->checkin_at( $term );

		$this->go_to( home_url( '/venue/automattic-offices/page/2/' ) );

		$this->assertSame( [ 301, home_url( '/venues/automattic-offices/page/2/' ) ], $this->redirect() );
	}

	public function test_no_redirect_when_a_venue_post_answers_the_old_path(): void {
		$term = $this->venue( 'automattic-offices' );
		$this->checkin_at( $term );
		self::factory()->post->create(
			[
				'post_type'   => self::STAND_IN,
				'post_name'   => 'automattic-offices',
				'post_title'  => 'Automattic Offices',
				'post_status' => 'publish',
			]
		);

		$this->go_to( home_url( '/venue/automattic-offices/' ) );

		$this->assertFalse( is_404() );
		$this->assertNull( $this->redirect() );
	}

	public function test_no_redirect_without_a_matching_term(): void {
		$this->go_to( home_url( '/venue/nowhere-at-all/' ) );

		$this->assertTrue( is_404() );
		$this->assertNull( $this->redirect() );
	}

	public function test_no_redirect_for_other_404s(): void {
		$this->venue( 'automattic-offices' );

		$this->go_to( home_url( '/venue/automattic-offices/extra/bits/' ) );
		$this->assertNull( $this->redirect() );

		$this->go_to( home_url( '/elsewhere/automattic-offices/' ) );
		$this->assertNull( $this->redirect() );
	}

	public function test_a_subdirectory_install_compares_against_the_home_path(): void {
		update_option( 'home', 'http://' . WP_TESTS_DOMAIN . '/blog' );
		$term = $this->venue( 'automattic-offices' );
		$this->checkin_at( $term );

		$this->go_to( 'http://' . WP_TESTS_DOMAIN . '/blog/venue/automattic-offices/' );
		$this->assertSame( [ 301, 'http://' . WP_TESTS_DOMAIN . '/blog/venues/automattic-offices/' ], $this->redirect() );

		// Outside the home path the request isn't ours to redirect.
		$this->go_to( 'http://' . WP_TESTS_DOMAIN . '/venue/automattic-offices/' );
		$this->assertNull( $this->redirect() );
	}

	// --- Template ----------------------------------------------------------

	public function test_the_plugin_template_serves_the_venue_archive(): void {
		$this->use_block_theme( 'twentytwentyfive' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-pkiw_venue', $template->id );
		$this->assertSame( 'Venue Archive', $template->title );
	}

	public function test_a_theme_venue_template_wins(): void {
		$this->use_block_theme( 'pkiw-venue-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-venue-theme//taxonomy-pkiw_venue', $template->id );
		$this->assertStringContainsString( 'theme-owned-venue-archive', $template->content );
	}

	public function test_a_site_editor_venue_template_wins(): void {
		$this->use_block_theme( 'twentytwentyfive' );
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-pkiw_venue',
				'post_title'   => 'Venue archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-venue</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-venue', $template->content );
	}

	// --- Term screen hooks -------------------------------------------------

	public function test_the_add_form_prints_the_venue_fields_and_a_nonce(): void {
		ob_start();
		do_action( Venue_Taxonomy::TAXONOMY . '_add_form_fields', Venue_Taxonomy::TAXONOMY );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="venue_address"', $html );
		$this->assertStringContainsString( 'name="venue_latitude"', $html );
		$this->assertStringContainsString( 'name="pkiw_venue_meta_nonce"', $html );
	}

	public function test_the_edit_form_prints_the_saved_venue_fields_and_a_nonce(): void {
		$term = $this->venue( 'ritual-coffee' );
		update_term_meta( $term->term_id, 'address', '1026 Valencia St' );

		ob_start();
		do_action( Venue_Taxonomy::TAXONOMY . '_edit_form_fields', $term, Venue_Taxonomy::TAXONOMY );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="1026 Valencia St"', $html );
		$this->assertStringContainsString( 'name="pkiw_venue_meta_nonce"', $html );
	}

	public function test_saving_the_edit_form_stores_the_venue_meta(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$term  = $this->venue( 'ritual-coffee' );
		$_POST = [
			'pkiw_venue_meta_nonce' => wp_create_nonce( 'pkiw_venue_meta' ),
			'venue_address'         => '1026 Valencia St',
			'venue_latitude'        => '37.7564',
		];

		wp_update_term( $term->term_id, Venue_Taxonomy::TAXONOMY, [ 'description' => 'Coffee' ] );

		$this->assertSame( '1026 Valencia St', get_term_meta( $term->term_id, 'address', true ) );
		$this->assertEquals( 37.7564, get_term_meta( $term->term_id, 'latitude', true ) );
	}

	public function test_saving_the_add_form_stores_the_venue_meta(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [
			'pkiw_venue_meta_nonce' => wp_create_nonce( 'pkiw_venue_meta' ),
			'venue_city'            => 'San Francisco',
		];

		$result = wp_insert_term( 'Tartine', Venue_Taxonomy::TAXONOMY );

		$this->assertSame( 'San Francisco', get_term_meta( $result['term_id'], 'city', true ) );
	}

	public function test_saving_without_the_nonce_stores_nothing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$term  = $this->venue( 'ritual-coffee' );
		$_POST = [ 'venue_address' => '1026 Valencia St' ];

		wp_update_term( $term->term_id, Venue_Taxonomy::TAXONOMY, [ 'description' => 'Coffee' ] );

		$this->assertSame( '', get_term_meta( $term->term_id, 'address', true ) );
	}

	public function test_saving_as_a_user_who_cannot_edit_the_term_stores_nothing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$term  = $this->venue( 'ritual-coffee' );
		$_POST = [
			'pkiw_venue_meta_nonce' => wp_create_nonce( 'pkiw_venue_meta' ),
			'venue_address'         => '1026 Valencia St',
		];

		wp_update_term( $term->term_id, Venue_Taxonomy::TAXONOMY, [ 'description' => 'Coffee' ] );

		$this->assertSame( '', get_term_meta( $term->term_id, 'address', true ) );
	}

	public function test_the_list_table_gets_the_location_column(): void {
		$term = $this->venue( 'ritual-coffee' );
		update_term_meta( $term->term_id, 'city', 'San Francisco' );
		update_term_meta( $term->term_id, 'country', 'US' );

		$columns = apply_filters(
			'manage_edit-' . Venue_Taxonomy::TAXONOMY . '_columns',
			[
				'cb'   => '',
				'name' => 'Name',
			]
		);
		$cell    = apply_filters( 'manage_' . Venue_Taxonomy::TAXONOMY . '_custom_column', '', 'location', $term->term_id );

		$this->assertSame( [ 'cb', 'name', 'location' ], array_keys( $columns ) );
		$this->assertSame( 'San Francisco, US', $cell );
	}

	// --- Helpers -------------------------------------------------------------

	private function register_venue_taxonomy(): void {
		( new ReflectionClass( Venue_Taxonomy::class ) )->newInstanceWithoutConstructor()->register_taxonomy();
	}

	private function venue( string $slug ): WP_Term {
		$term = self::factory()->term->create_and_get(
			[
				'taxonomy' => Venue_Taxonomy::TAXONOMY,
				'name'     => ucwords( str_replace( '-', ' ', $slug ) ),
				'slug'     => $slug,
			]
		);
		$this->assertInstanceOf( WP_Term::class, $term );

		return $term;
	}

	private function checkin_at( WP_Term $term ): int {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, $term->term_id, Venue_Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * Fire template_redirect and capture a redirect instead of exiting.
	 *
	 * @return array{0: int, 1: string}|null Status and location, or null.
	 */
	private function redirect(): ?array {
		$capture = static function ( $status, $location ) {
			throw new RuntimeException( wp_json_encode( [ $status, $location ] ) );
		};
		add_filter( 'wp_redirect_status', $capture, 10, 2 );

		try {
			do_action( 'template_redirect' );
		} catch ( RuntimeException $redirect ) {
			return json_decode( $redirect->getMessage(), true );
		} finally {
			remove_filter( 'wp_redirect_status', $capture, 10 );
		}

		return null;
	}

	private function use_block_theme( string $stylesheet ): void {
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		register_theme_directory( self::$theme_root );
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( $stylesheet );
	}

	/**
	 * @return list<string>
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-pkiw_venue-ritual-coffee.php', 'taxonomy-pkiw_venue.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}
}
