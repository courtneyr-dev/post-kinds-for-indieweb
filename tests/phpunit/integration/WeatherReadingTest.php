<?php
/**
 * The weather reading accessor (issue 246).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Simple_Location_Weather;
use PKIW\Taxonomy;

/**
 * One reading per weather post: the Simple Location observation when the
 * viewer may see it, else the text the author wrote, never both, and nothing
 * at all behind a password.
 *
 * @group integration
 */
final class WeatherReadingTest extends WP_UnitTestCase {

	/**
	 * Meta writes to weather keys seen during a test.
	 *
	 * @var array<int, string>
	 */
	private array $weather_writes = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/location-stub.php';
	}

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		wp_set_current_user( 0 );
		update_option( 'sloc_measurements', 'metric' );
		update_option( 'geo_public', '0' );

		$this->weather_writes = [];
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $action ) {
			add_action( $action, [ $this, 'record_weather_write' ], 10, 3 );
		}
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Record a write to a weather meta key.
	 *
	 * @param mixed  $meta_id   Meta ID(s).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public function record_weather_write( $meta_id, $object_id, $meta_key ): void {
		if ( str_starts_with( (string) $meta_key, 'weather_' ) || 'geo_weather' === $meta_key ) {
			$this->weather_writes[] = current_action() . ':' . $meta_key;
		}
	}

	/**
	 * A weather post with Simple Location rows written the way it stores them.
	 *
	 * @param array<string, mixed> $weather    Property => value, metric.
	 * @param string|null          $geo_public Simple Location geo_public, or null for no row.
	 * @param array<string, mixed> $args       Extra post arguments.
	 */
	private function weather_post( array $weather, ?string $geo_public = '1', array $args = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => '',
					'post_content' => '<!-- wp:paragraph --><p>Out for a walk.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'weather', Taxonomy::TAXONOMY ) );
		foreach ( $weather as $prop => $value ) {
			add_post_meta( $post_id, 'weather_' . $prop, $value );
		}
		if ( null !== $geo_public ) {
			add_post_meta( $post_id, 'geo_public', $geo_public );
		}
		$this->weather_writes = [];

		return $post_id;
	}

	/**
	 * Post content holding Micropub's authored weather span.
	 *
	 * @param string $inner Inner HTML of the span.
	 */
	private function authored( string $inner ): string {
		return '<!-- wp:paragraph --><p><span class="p-weather">' . $inner . '</span></p><!-- /wp:paragraph -->';
	}

	public function test_a_visible_observation_is_the_reading_with_render_text(): void {
		$post_id = $this->weather_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			]
		);

		$reading = Simple_Location_Weather::reading( $post_id );

		$this->assertSame(
			[
				'source'              => 'observation',
				'text'                => 'Clear Sky, 27 °C',
				'icon'                => 'wi-day-sunny',
				'code'                => '800',
				'temperature_visible' => true,
			],
			$reading
		);
		$this->assertSame(
			html_entity_decode( wp_strip_all_tags( Simple_Location_Weather::render( $post_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			$reading['text'],
			'The text is render() output, stripped and decoded.'
		);
	}

	public function test_a_stored_summary_and_an_ampersand_come_back_decoded(): void {
		$post_id = $this->weather_post(
			[
				'summary'     => 'Sun & cloud',
				'temperature' => 10,
			]
		);

		$this->assertSame( 'Sun & cloud, 10 °C', Simple_Location_Weather::reading( $post_id )['text'] ?? null );
	}

	public function test_a_hidden_observation_with_authored_text_returns_the_authored_text(): void {
		$post_id = $this->weather_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			],
			'0',
			[ 'post_content' => $this->authored( 'Sunny and warm' ) ]
		);

		$this->assertNull( Simple_Location_Weather::get_observation( $post_id ) );
		$this->assertSame(
			[
				'source'              => 'authored',
				'text'                => 'Sunny and warm',
				'icon'                => '',
				'code'                => '',
				'temperature_visible' => false,
			],
			Simple_Location_Weather::reading( $post_id )
		);
	}

	public function test_a_hidden_observation_with_nothing_authored_returns_null(): void {
		$post_id = $this->weather_post( [ 'temperature' => 26.8 ], '0' );

		$this->assertNull( Simple_Location_Weather::reading( $post_id ) );
	}

	public function test_a_visible_observation_and_authored_text_return_the_observation_only(): void {
		$post_id = $this->weather_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			],
			'1',
			[ 'post_content' => $this->authored( 'Sunny and warm' ) ]
		);

		$reading = Simple_Location_Weather::reading( $post_id );

		$this->assertSame( 'observation', $reading['source'] ?? null );
		$this->assertSame( 'Clear Sky, 27 °C', $reading['text'] ?? null );
		$this->assertStringNotContainsString( 'Sunny', (string) ( $reading['text'] ?? '' ), 'Never both.' );
	}

	public function test_an_observation_with_only_other_values_falls_back_to_the_authored_text(): void {
		$post_id = $this->weather_post(
			[ 'humidity' => 61 ],
			'1',
			[ 'post_content' => $this->authored( 'Muggy' ) ]
		);

		$reading = Simple_Location_Weather::reading( $post_id );

		$this->assertSame( 'authored', $reading['source'] ?? null );
		$this->assertSame( 'Muggy', $reading['text'] ?? null );
	}

	public function test_a_post_with_no_location_meta_shows_its_reading_when_geo_public_is_one(): void {
		$post_id = $this->weather_post( [ 'temperature' => 18 ], '1' );

		$this->assertSame( 'observation', Simple_Location_Weather::reading( $post_id )['source'] ?? null );
		$this->assertSame( '', (string) get_post_meta( $post_id, 'geo_latitude', true ), 'No location meta is stored.' );
	}

	public function test_a_post_with_no_geo_public_row_follows_the_private_site_default(): void {
		$bare     = $this->weather_post( [ 'temperature' => 18 ], null );
		$authored = $this->weather_post( [ 'temperature' => 18 ], null, [ 'post_content' => $this->authored( 'Chilly' ) ] );

		$this->assertNull( Simple_Location_Weather::reading( $bare ) );
		$this->assertSame( 'authored', Simple_Location_Weather::reading( $authored )['source'] ?? null );

		update_option( 'geo_public', '1' );
		$this->assertSame( 'observation', Simple_Location_Weather::reading( $bare )['source'] ?? null );
	}

	public function test_a_password_protected_post_has_no_reading_and_no_observation(): void {
		$post_id = $this->weather_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			],
			'1',
			[
				'post_password' => 'secret',
				'post_content'  => $this->authored( 'Sunny and warm' ),
			]
		);

		$this->assertNull( Simple_Location_Weather::reading( $post_id ) );
		$this->assertSame( '', Simple_Location_Weather::render( $post_id ) );
		$this->assertNull( Simple_Location_Weather::format( 'temperature', $post_id ) );
		$this->assertNull( Simple_Location_Weather::get_observation( $post_id ) );
	}

	public function test_a_protected_post_stays_closed_to_its_own_editor_until_the_password_is_entered(): void {
		$post_id = $this->weather_post( [ 'temperature' => 26.8 ], '1', [ 'post_password' => 'secret' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertTrue( post_password_required( $post_id ) );
		$this->assertNull( Simple_Location_Weather::reading( $post_id ) );
		$this->assertNull( Simple_Location_Weather::get_observation( $post_id ) );
	}

	public function test_a_password_of_spaces_is_still_a_password(): void {
		$post_id = $this->weather_post(
			[ 'temperature' => 26.8 ],
			'1',
			[
				'post_password' => '   ',
				'post_content'  => $this->authored( 'Sunny' ),
			]
		);

		$this->assertTrue( post_password_required( $post_id ) );
		$this->assertNull( Simple_Location_Weather::reading( $post_id ) );
	}

	public function test_an_editor_gets_the_observation_where_an_anonymous_viewer_cannot(): void {
		$post_id = $this->weather_post( [ 'temperature' => 18 ], '0', [ 'post_content' => $this->authored( 'Chilly' ) ] );

		$this->assertSame( 'authored', Simple_Location_Weather::reading( $post_id )['source'] ?? null );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 'observation', Simple_Location_Weather::reading( $post_id )['source'] ?? null );
		$this->assertSame( '18 °C', Simple_Location_Weather::reading( $post_id )['text'] ?? null );
	}

	public function test_a_stored_26_point_8_reads_27_celsius_and_80_fahrenheit(): void {
		$post_id = $this->weather_post( [ 'temperature' => 26.8 ] );

		$this->assertSame( '27 °C', Simple_Location_Weather::reading( $post_id )['text'] ?? null );

		update_option( 'sloc_measurements', 'imperial' );
		$this->assertSame( '80 °F', Simple_Location_Weather::reading( $post_id )['text'] ?? null );
	}

	public function test_a_mark_needs_a_stored_condition_code(): void {
		$icon_only = $this->weather_post(
			[
				'icon'        => 'wi-day-haze',
				'temperature' => 20,
			]
		);
		$coded     = $this->weather_post(
			[
				'code'        => 801,
				'temperature' => 20,
			]
		);

		$this->assertNotNull( Simple_Location_Weather::get_observation( $icon_only )['icon'] ?? null, 'The observation falls back to a stored icon row.' );
		$this->assertSame( '', Simple_Location_Weather::reading( $icon_only )['icon'] ?? null );
		$this->assertSame( '', Simple_Location_Weather::reading( $icon_only )['code'] ?? null );
		$this->assertSame( 'wi-day-cloudy', Simple_Location_Weather::reading( $coded )['icon'] ?? null );
		$this->assertSame( '801', Simple_Location_Weather::reading( $coded )['code'] ?? null );
	}

	public function test_temperature_visible_follows_the_observation(): void {
		$with    = $this->weather_post( [ 'temperature' => 20 ] );
		$without = $this->weather_post( [ 'summary' => 'Foggy' ] );

		$this->assertTrue( Simple_Location_Weather::reading( $with )['temperature_visible'] ?? null );
		$this->assertFalse( Simple_Location_Weather::reading( $without )['temperature_visible'] ?? null );
	}

	public function test_authored_markup_is_flattened_to_text(): void {
		$post_id = $this->weather_post( [], null, [ 'post_content' => $this->authored( 'Light <em>rain</em> &amp; cool' ) ] );

		$this->assertSame( 'Light rain & cool', Simple_Location_Weather::reading( $post_id )['text'] ?? null );
	}

	public function test_the_first_p_weather_element_wins_and_lookalike_classes_do_not_count(): void {
		$content = '<p class="not-p-weather">Decoy</p><p><span class="x p-weather">First <b>one</b></span> then <span class="p-weather">Second</span></p>';
		$post_id = $this->weather_post( [], null, [ 'post_content' => $content ] );

		$this->assertSame( 'First one', Simple_Location_Weather::reading( $post_id )['text'] ?? null );
	}

	public function test_an_empty_p_weather_gives_no_reading(): void {
		$post_id = $this->weather_post( [], null, [ 'post_content' => '<p><span class="p-weather">  </span> trailing text</p>' ] );

		$this->assertNull( Simple_Location_Weather::reading( $post_id ) );
	}

	public function test_a_post_with_neither_source_has_no_reading(): void {
		$post_id = $this->weather_post( [], null );

		$this->assertNull( Simple_Location_Weather::reading( $post_id ) );
	}

	public function test_a_missing_post_has_no_reading(): void {
		$this->assertNull( Simple_Location_Weather::reading( 0 ) );
		$this->assertNull( Simple_Location_Weather::reading( 999999 ) );
	}

	public function test_an_inactive_source_leaves_only_the_authored_text(): void {
		$post_id = $this->weather_post( [ 'temperature' => 26.8 ], '1', [ 'post_content' => $this->authored( 'Sunny' ) ] );
		add_filter( 'pkiw_weather_source_active', '__return_false' );

		$this->assertSame( 'authored', Simple_Location_Weather::reading( $post_id )['source'] ?? null );
	}

	public function test_a_legacy_geo_weather_row_reads_without_a_single_weather_write(): void {
		$post_id = $this->weather_post( [], '1' );
		add_post_meta(
			$post_id,
			'geo_weather',
			[
				'temperature' => 9.4,
				'summary'     => 'light rain',
			]
		);
		$this->weather_writes = [];

		$reading = Simple_Location_Weather::reading( $post_id );

		$this->assertSame( 'light rain, 9 °C', $reading['text'] ?? null );
		$this->assertSame( [], $this->weather_writes );
		$this->assertNotEmpty( get_post_meta( $post_id, 'geo_weather', true ), 'The legacy row is still there.' );
	}
}
