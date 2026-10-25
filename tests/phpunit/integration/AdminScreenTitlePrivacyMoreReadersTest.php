<?php
/**
 * Hidden location titles in the readers issue 434 didn't list.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Almanac_Month;
use PKIW\Collection_Items;
use PKIW\Integrations\Simple_Location_Weather;
use PKIW\Jam_Archive;
use PKIW\Listen_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/**
 * The collection, almanac, listen, jam and weather readers, each rendered for
 * a post whose title was generated from a hidden location under the edit-post
 * screen, the same probe as AdminScreenTitlePrivacyReadersTest.
 *
 * @group integration
 */
final class AdminScreenTitlePrivacyMoreReadersTest extends WP_UnitTestCase {

	private const TITLE = 'Checked in at Zephyr Tea Room';

	private const SAFE = 'Check-in, August 26, 2026';

	/**
	 * What the hidden title must never leave behind, lowercase.
	 *
	 * @var string[]
	 */
	private const NEEDLES = [ 'zephyr', 'tea room', 'tea-room', 'checked in at' ];

	/**
	 * Timezone active before each test.
	 *
	 * @var string
	 */
	private string $original_timezone = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/location-stub.php';
	}

	public function set_up(): void {
		parent::set_up();
		$this->original_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		delete_option( 'pkiw_settings' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		$this->reset_listen_duplicates();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		unset( $GLOBALS['current_screen'], $GLOBALS['post'] );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		$this->reset_listen_duplicates();
		update_option( 'timezone_string', $this->original_timezone );
		parent::tear_down();
	}

	/**
	 * A post whose stored title was generated from a location that is hidden.
	 *
	 * @param string               $kind    Kind slug.
	 * @param array<string, mixed> $meta    Extra post meta, key => value.
	 * @param string               $content Post content.
	 * @param array<string, mixed> $args    Extra post arguments.
	 */
	private function hidden_title_post( string $kind, array $meta = [], string $content = '', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => self::TITLE,
					'post_date'    => '2026-08-26 09:15:00',
					'post_content' => $content,
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		update_post_meta( $id, Meta_Fields::PREFIX . 'checkin_name', 'Zephyr Tea Room' );
		update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		Title_Privacy::mark_location_title( $id );
		clean_post_cache( $id );
		$this->assertTrue( \PKIW\post_title_is_hidden( get_post( $id ) ), 'The fixture title is hidden.' );

		return $id;
	}

	/**
	 * A post with a title of its own.
	 *
	 * @param string $kind    Kind slug.
	 * @param string $title   Title.
	 * @param string $content Post content.
	 */
	private function titled_post( string $kind, string $title, string $content = '' ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			]
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return $id;
	}

	private function admin_screen(): void {
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin(), 'The request looks like an admin screen.' );
	}

	private function assertNoHiddenTitle( string $haystack, string $where ): void {
		$lower = strtolower( $haystack );
		foreach ( self::NEEDLES as $needle ) {
			$this->assertStringNotContainsString( $needle, $lower, $where . ': ' . $needle );
		}
	}

	/**
	 * Call a private static reader method.
	 *
	 * @param string $class_name Class.
	 * @param string $method     Method.
	 * @param mixed  ...$args    Arguments.
	 * @return mixed
	 */
	private function call( string $class_name, string $method, ...$args ) {
		return ( new ReflectionMethod( $class_name, $method ) )->invoke( null, ...$args );
	}

	/**
	 * Render on the front end, then on an admin screen.
	 *
	 * @param callable $render Returns the reader's output.
	 * @return array{string, string} Front-end output, admin-screen output.
	 */
	private function front_and_admin( callable $render ): array {
		$front = (string) $render();
		$this->admin_screen();
		$admin = (string) $render();

		return [ $front, $admin ];
	}

	/**
	 * Elements carrying p-name in some markup.
	 *
	 * @param string $html Markup.
	 */
	private function p_names( string $html ): DOMNodeList {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return ( new DOMXPath( $dom ) )->query( '//*[contains(concat(" ",normalize-space(@class)," ")," p-name ")]' );
	}

	private function reset_listen_duplicates(): void {
		( new ReflectionProperty( Listen_Archive::class, 'duplicate_names' ) )->setValue( null, [] );
	}

	private function jam_card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/jam-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	public function test_collection_facts_and_cards_name_a_hidden_location_title_by_its_safe_name(): void {
		$id = $this->hidden_title_post( 'collection' );

		$cards = function () use ( $id ): string {
			$facts = Collection_Items::facts( $id );

			return wp_json_encode( $facts )
				. $this->call( Collection_Items::class, 'listing_card_html', get_post( $id ), $facts, 2 )
				. $this->call( Collection_Items::class, 'stream_card_html', get_post( $id ), $facts, 2 );
		};
		[ $front, $admin ] = $this->front_and_admin( $cards );

		$this->assertNoHiddenTitle( $admin, 'collection' );
		$facts = Collection_Items::facts( $id );
		$this->assertSame( self::SAFE, $facts['name'] );
		$this->assertFalse( $facts['name_is_title'], 'A hidden title is a synthetic name (X8).' );
		$this->assertSame( 0, $this->p_names( $admin )->length, 'A hidden title is a synthetic name (X8).' );
		$this->assertSame( $front, $admin );
	}

	public function test_an_almanac_entry_never_prints_a_hidden_location_title(): void {
		$id = $this->hidden_title_post( 'weather' );

		[ $front, $admin ] = $this->front_and_admin( fn() => $this->call( Almanac_Month::class, 'entry', get_post( $id ), 3 ) );

		$this->assertNoHiddenTitle( $admin, 'almanac entry' );
		$this->assertStringContainsString( self::SAFE, $admin );
		$this->assertSame( 0, $this->p_names( $admin )->length, 'A hidden title is a synthetic name (X8).' );
		$this->assertSame( $front, $admin );
	}

	public function test_listen_facts_and_the_podcast_line_name_a_hidden_location_title_by_its_safe_name(): void {
		$id = $this->hidden_title_post( 'listen' );

		[ $front, $admin ] = $this->front_and_admin(
			fn() => wp_json_encode( Listen_Archive::facts( $id ) ) . $this->call( Listen_Archive::class, 'line_html', get_post( $id ), [], false )
		);

		$this->assertNoHiddenTitle( $admin, 'listen' );
		$facts = Listen_Archive::facts( $id );
		$this->assertSame( self::SAFE, $facts['name'] );
		$this->assertSame( self::SAFE, $facts['episode_text'] );
		$this->assertFalse( $facts['name_is_title'], 'A hidden title is a synthetic name (X8).' );
		$this->assertSame( $front, $admin );
	}

	public function test_a_protected_podcast_line_never_prints_a_hidden_location_title(): void {
		$id = $this->hidden_title_post( 'listen', [], '', [ 'post_password' => 'tea' ] );

		[ $front, $admin ] = $this->front_and_admin( fn() => $this->call( Listen_Archive::class, 'line_html', get_post( $id ), [], false ) );

		$this->assertNoHiddenTitle( $admin, 'protected podcast line' );
		$this->assertStringContainsString( '>Protected: ' . self::SAFE . '</a>', $admin );
		$this->assertSame( $front, $admin );
	}

	public function test_podcast_duplicate_names_never_hold_a_hidden_location_title(): void {
		$protected = $this->hidden_title_post( 'listen', [], '', [ 'post_password' => 'tea' ] );
		$titled    = $this->titled_post( 'listen', self::TITLE );

		global $wp_query;
		$wp_query = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The grouped page the duplicate check reads.
		$wp_query->set( 'pkiw_group_by', 'station' );
		$wp_query->posts = [ get_post( $titled ), get_post( $protected ) ];

		$front = $this->call( Listen_Archive::class, 'duplicate_name_set', get_post( $titled ) );
		$this->reset_listen_duplicates();
		$this->admin_screen();
		$admin = $this->call( Listen_Archive::class, 'duplicate_name_set', get_post( $titled ) );

		$this->assertSame( [], $admin, 'The protected line reads as its safe name, so the titled line has no twin.' );
		$this->assertSame( $front, $admin );
	}

	public function test_jam_facts_and_card_name_a_hidden_location_title_by_its_safe_name(): void {
		$id = $this->hidden_title_post( 'jam', [], $this->jam_card( [ 'artist' => 'Nina Simone' ] ) );

		[ $front, $admin ] = $this->front_and_admin(
			fn() => wp_json_encode( Jam_Archive::facts( $id ) ) . Jam_Archive::stream_card( null, get_post( $id ), 2 )
		);

		$this->assertNoHiddenTitle( $admin, 'jam' );
		$facts = Jam_Archive::facts( $id );
		$this->assertSame( self::SAFE, $facts['name'] );
		$this->assertFalse( $facts['name_is_title'], 'A hidden title is a synthetic name (X8).' );
		$names = $this->p_names( (string) Jam_Archive::stream_card( null, get_post( $id ), 2 ) );
		foreach ( $names as $name ) {
			$this->assertStringNotContainsString( self::SAFE, (string) $name->textContent, 'A hidden title is a synthetic name (X8).' );
		}
		$this->assertSame( $front, $admin );
	}

	public function test_a_jam_track_is_compared_with_the_title_a_visitor_reads(): void {
		$id = $this->hidden_title_post( 'jam', [], $this->jam_card( [ 'title' => self::TITLE ] ) );

		[ $front, $admin ] = $this->front_and_admin( fn() => wp_json_encode( Jam_Archive::facts( $id )['track_is_h1'] ) );

		$this->assertSame( 'false', $admin, 'The h1 a visitor reads is the safe title, not the track.' );
		$this->assertSame( $front, $admin );
	}

	public function test_weather_facts_and_card_name_a_hidden_location_title_by_its_safe_name(): void {
		$id = $this->hidden_title_post( 'weather' );

		[ $front, $admin ] = $this->front_and_admin(
			fn() => wp_json_encode( Simple_Location_Weather::facts( $id ) ) . Simple_Location_Weather::stream_card( null, get_post( $id ), 2 )
		);

		$this->assertNoHiddenTitle( $admin, 'weather' );
		$facts = Simple_Location_Weather::facts( $id );
		$this->assertSame( self::SAFE, $facts['name'] );
		$this->assertFalse( $facts['name_is_title'], 'A hidden title is a synthetic name (X8).' );
		$this->assertStringContainsString( self::SAFE, $admin );
		$this->assertSame( 0, $this->p_names( (string) Simple_Location_Weather::stream_card( null, get_post( $id ), 2 ) )->length, 'A hidden title is a synthetic name (X8).' );
		$this->assertSame( $front, $admin );
	}

	/**
	 * Weather facts built while a classic-editor save runs read the title a
	 * visitor reads: the save hooks count as a visitor read (issue 434).
	 */
	public function test_weather_facts_built_during_an_admin_save_read_the_safe_title(): void {
		$id   = $this->hidden_title_post( 'weather' );
		$seen = [];
		$read = static function () use ( $id, &$seen ): void {
			$seen[] = Simple_Location_Weather::facts( $id )['name'] ?? '';
		};
		add_action( 'wp_after_insert_post', $read, 33, 0 );
		set_current_screen( 'post' );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => 'Fog by noon.',
			]
		);
		remove_action( 'wp_after_insert_post', $read, 33 );

		$this->assertNotEmpty( $seen );
		foreach ( $seen as $name ) {
			$this->assertSame( self::SAFE, $name );
		}
	}

	public function test_visible_titles_stay_titles_in_these_readers(): void {
		$collection = $this->titled_post( 'collection', 'Garden gate' );
		$listen     = $this->titled_post( 'listen', 'Garden gate' );
		$jam        = $this->titled_post( 'jam', 'Garden gate', $this->jam_card( [ 'artist' => 'Nina Simone' ] ) );
		$weather    = $this->titled_post( 'weather', 'Garden gate' );
		$this->admin_screen();

		foreach (
			[
				'collection' => Collection_Items::facts( $collection ),
				'listen'     => Listen_Archive::facts( $listen ),
				'jam'        => Jam_Archive::facts( $jam ),
				'weather'    => Simple_Location_Weather::facts( $weather ),
			] as $kind => $facts
		) {
			$this->assertSame( 'Garden gate', $facts['name'], $kind );
			$this->assertTrue( $facts['name_is_title'], $kind );
		}
		foreach (
			[
				'jam card'      => (string) Jam_Archive::stream_card( null, get_post( $jam ), 2 ),
				'weather card'  => (string) Simple_Location_Weather::stream_card( null, get_post( $weather ), 2 ),
				'almanac entry' => (string) $this->call( Almanac_Month::class, 'entry', get_post( $weather ), 3 ),
			] as $where => $html
		) {
			$titles = [];
			foreach ( $this->p_names( $html ) as $name ) {
				$titles[] = trim( (string) $name->textContent );
			}
			$this->assertContains( 'Garden gate', $titles, $where );
		}
	}
}
