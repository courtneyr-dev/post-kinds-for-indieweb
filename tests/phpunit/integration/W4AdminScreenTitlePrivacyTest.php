<?php
/**
 * Hidden location titles on admin-screen requests (W4-P0C-FU).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Craft_Archive;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Mood_Vocabulary;
use PKIW\Note_Archive;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/**
 * Title_Privacy::filter_the_title() returns the stored title on an admin
 * screen, so code that reads get_the_title() for a post whose title came from
 * a hidden location prints that location there. The Stream card and the W4
 * readers take Title_Privacy::safe_title() instead. Each test renders the
 * reader under the edit-post screen and checks the stored title never prints
 * and that an administrator reads what a visitor reads.
 *
 * @group integration
 */
final class W4AdminScreenTitlePrivacyTest extends WP_UnitTestCase {

	private const TITLE = 'Checked in at Sentinel Venue Zyx9';

	private const PASSWORD = 'fixture-fu';

	/**
	 * What the hidden title must never leave behind, lowercase.
	 *
	 * @var string[]
	 */
	private const NEEDLES = [ 'sentinel venue', 'sentinel-venue', 'zyx9', 'checked in at' ];

	/**
	 * Timezone active before each test.
	 *
	 * @var string
	 */
	private string $original_timezone = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		delete_option( 'pkiw_settings' );
		( new ReflectionProperty( Mood_Vocabulary::class, 'cache' ) )->setValue( null, [] );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		Mood_Vocabulary::register();
		Kind_Facts::register( 'craft', [ Craft_Archive::class, 'facts' ] );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		unset( $GLOBALS['current_screen'], $GLOBALS['post'] );
		wp_set_current_user( 0 );
		update_option( 'timezone_string', $this->original_timezone );
		parent::tear_down();
	}

	/**
	 * A post whose stored title was generated from a location that is hidden.
	 *
	 * @param string $kind     Kind slug.
	 * @param string $password Post password; '' for none.
	 */
	private function hidden_title_post( string $kind, string $password = '' ): int {
		$content = '';
		if ( 'mood' === $kind ) {
			$content = '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Happy","moodKey":"happy","intensity":4} /-->';
		}
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => self::TITLE,
				'post_date'     => '2026-08-26 09:15:00',
				'post_content'  => $content,
				'post_password' => $password,
			]
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		update_post_meta( $id, Meta_Fields::PREFIX . 'checkin_name', 'Sentinel Venue Zyx9' );
		update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		if ( 'craft' === $kind ) {
			update_post_meta( $id, '_pkiw_craft_type', 'Loom work' );
			update_post_meta( $id, '_pkiw_craft_minutes', '90' );
		}
		Title_Privacy::mark_location_title( $id );
		clean_post_cache( $id );

		return $id;
	}

	private function admin_screen(): void {
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin(), 'The request looks like an admin screen.' );
	}

	private function administrator(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * The Stream card for a post.
	 */
	private function stream_card( int $post_id ): string {
		$GLOBALS['post'] = get_post( $post_id );

		return \PKIW\render_stream_card();
	}

	private function assertNoHiddenTitle( string $haystack, string $where ): void {
		$lower = strtolower( $haystack );
		foreach ( self::NEEDLES as $needle ) {
			$this->assertStringNotContainsString( $needle, $lower, $where . ': ' . $needle );
		}
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public function stream_card_cases(): array {
		$plain     = 'Check-in, August 26, 2026';
		$protected = 'Protected: Check-in, August 26, 2026';

		return [
			'note'              => [ 'note', '', $plain ],
			'checkin'           => [ 'checkin', '', $plain ],
			'watch'             => [ 'watch', '', $plain ],
			'mood'              => [ 'mood', '', '' ],
			'craft'             => [ 'craft', '', $plain ],
			'protected note'    => [ 'note', self::PASSWORD, $protected ],
			'protected checkin' => [ 'checkin', self::PASSWORD, $protected ],
			'protected mood'    => [ 'mood', self::PASSWORD, $protected ],
			'protected craft'   => [ 'craft', self::PASSWORD, $protected ],
		];
	}

	/**
	 * @dataProvider stream_card_cases
	 *
	 * @param string $kind     Kind slug.
	 * @param string $password Post password.
	 * @param string $expected Name the card prints.
	 */
	public function test_a_stream_card_never_prints_a_hidden_location_title_on_an_admin_screen( string $kind, string $password, string $expected ): void {
		$id = $this->hidden_title_post( $kind, $password );

		$front = $this->stream_card( $id );
		$this->admin_screen();
		$html = $this->stream_card( $id );

		$this->assertNoHiddenTitle( $html, $kind );
		$this->assertStringContainsString( $expected, $html, 'A mood card prints no title, so it expects none.' );
		$this->assertSame( '' !== $password, post_password_required( get_post( $id ) ) );
		$this->assertSame( $front, $html, 'The admin screen prints the card a visitor gets.' );
	}

	public function test_a_stream_card_prints_the_same_for_an_administrator_and_a_visitor(): void {
		$id = $this->hidden_title_post( 'note' );

		$visitor = $this->stream_card( $id );
		$this->administrator();
		$this->admin_screen();
		$admin = $this->stream_card( $id );

		$this->assertNoHiddenTitle( $admin, 'administrator' );
		$this->assertSame( $visitor, $admin );
	}

	public function test_a_visible_title_still_prints_on_an_admin_screen(): void {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Garden gate',
				'post_date'   => '2026-08-26 09:15:00',
			]
		);
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		$protected = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => 'Locked gate',
				'post_password' => self::PASSWORD,
			]
		);
		wp_set_object_terms( $protected, 'note', Taxonomy::TAXONOMY );
		$this->admin_screen();

		$this->assertStringContainsString( 'Garden gate', $this->stream_card( $id ) );
		$this->assertStringContainsString( 'Locked gate', $this->stream_card( $protected ), 'Core adds its prefix on the front end only.' );
	}

	public function test_the_thumbnail_alt_never_falls_back_to_a_hidden_location_title(): void {
		$id = $this->hidden_title_post( 'note' );
		$this->admin_screen();

		$alt = \PKIW\stream_card_thumbnail_alt( get_post( $id ), 0 );

		$this->assertNoHiddenTitle( $alt, 'alt' );
		$this->assertSame( 'Check-in, August 26, 2026', $alt );
	}

	public function test_craft_facts_name_a_hidden_location_title_by_its_safe_title(): void {
		$id = $this->hidden_title_post( 'craft' );

		$visitor = Craft_Archive::facts( $id );
		$this->administrator();
		$this->admin_screen();
		$admin = Craft_Archive::facts( $id );

		$this->assertSame( 'Check-in, August 26, 2026', $visitor['name'] );
		$this->assertNoHiddenTitle( (string) wp_json_encode( $admin ), 'craft facts' );
		$this->assertSame( 'Check-in, August 26, 2026', $admin['name'] );
		$this->assertEquals( $visitor, $admin, 'An administrator reads what a visitor reads.' );
		$this->assertEquals( $visitor, \PKIW\kind_facts( $id ) );
	}

	public function test_mood_facts_and_the_year_grid_never_carry_a_hidden_location_title(): void {
		$id = $this->hidden_title_post( 'mood' );

		$visitor = Mood_Vocabulary::facts( $id );
		$this->go_to( (string) get_term_link( 'mood', Taxonomy::TAXONOMY ) );
		$visitor_grid = do_blocks( '<!-- wp:post-kinds-indieweb/mood-year-grid /-->' );
		$this->administrator();
		$this->admin_screen();
		$admin      = Mood_Vocabulary::facts( $id );
		$admin_grid = do_blocks( '<!-- wp:post-kinds-indieweb/mood-year-grid /-->' );

		$this->assertSame( 'Happy', $admin['name'] );
		$this->assertNoHiddenTitle( (string) wp_json_encode( $admin ), 'mood facts' );
		$this->assertNoHiddenTitle( $admin_grid, 'mood year grid' );
		$this->assertStringContainsString( 'Happy', $admin_grid, 'The grid printed the mood.' );
		$this->assertEquals( $visitor, $admin, 'An administrator reads what a visitor reads.' );
		$this->assertSame( $visitor_grid, $admin_grid );
	}

	public function test_the_note_reader_keeps_the_safe_title_on_an_admin_screen(): void {
		$id = $this->hidden_title_post( 'note' );

		$visitor = Note_Archive::parts( get_post( $id ) );
		$this->administrator();
		$this->admin_screen();
		$admin = Note_Archive::parts( get_post( $id ) );

		$this->assertNoHiddenTitle( (string) wp_json_encode( $admin ), 'note parts' );
		$this->assertSame( 'Check-in, August 26, 2026', $admin['name'] );
		$this->assertSame( $visitor, $admin );
	}
}
