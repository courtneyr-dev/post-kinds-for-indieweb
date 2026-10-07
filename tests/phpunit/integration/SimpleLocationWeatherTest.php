<?php
/**
 * Simple Location weather adapter coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Block_Bindings;
use PKIW\Integrations\Simple_Location_Weather;

/**
 * Post Kinds reads the weather observation Simple Location stored on a post
 * and never writes it (#209).
 *
 * Runs against the real Simple Location plugin when
 * PKIW_TESTS_SIMPLE_LOCATION_FILE is set, otherwise against the fixture in
 * tests/phpunit/fixtures/simple-location/weather-stub.php.
 *
 * @group integration
 */
final class SimpleLocationWeatherTest extends WP_UnitTestCase {

	/**
	 * Weather content as Micropub writes it: an authored p-weather
	 * paragraph (class-micropub-content-builder.php:932-938).
	 */
	private const AUTHORED_WEATHER = '<p><span class="p-weather">Sunny and warm</span></p>';

	/**
	 * Meta writes to weather keys seen during a test.
	 *
	 * @var array<int, string>
	 */
	private array $weather_writes = [];

	/**
	 * Load the Simple Location weather API (real plugin or fixture).
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/location-stub.php';
	}

	/**
	 * Start every test anonymous, metric, with a record of weather meta writes.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		// Set both explicitly: the real plugin registers defaults for them
		// (class-loc-config.php), so a deleted option doesn't read as unset.
		update_option( 'sloc_measurements', 'metric' );
		update_option( 'geo_public', '0' );

		$this->weather_writes = [];
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $action ) {
			add_action( $action, [ $this, 'record_weather_write' ], 10, 3 );
		}
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
	 * Create a weather post with Simple Location meta written the way
	 * Sloc_Weather_Data::set_object_weatherdata() stores it: one
	 * `weather_<prop>` row per non-empty value, metric units.
	 *
	 * @param array<string, mixed> $weather    Property => value.
	 * @param string|null          $geo_public Simple Location geo_public, or null for none.
	 * @param string               $kind       Kind slug.
	 */
	private function make_post( array $weather, ?string $geo_public = '1', string $kind = 'weather' ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => '<!-- wp:paragraph --><p>Out for a walk.</p><!-- /wp:paragraph -->',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, $kind, 'kind' ) );
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
	 * Every weather meta row for a post, exactly as stored.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private function weather_rows( int $post_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND ( meta_key LIKE %s OR meta_key = %s ) ORDER BY meta_id",
				$post_id,
				$wpdb->esc_like( 'weather_' ) . '%',
				'geo_weather'
			),
			ARRAY_A
		);
	}

	/**
	 * Resolve a binding key the way core's Block Bindings does.
	 *
	 * @param string $key     Binding key.
	 * @param int    $post_id Post ID.
	 */
	private function binding( string $key, int $post_id ): ?string {
		$block = new WP_Block(
			[
				'blockName' => 'core/paragraph',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		);
		// WP_Block keeps only the context keys a block type declares in
		// uses_context; set it the way a Query Loop's postId arrives.
		$block->context = [ 'postId' => $post_id ];
		return ( new Block_Bindings() )->get_binding_value( [ 'key' => $key ], $block, 'content' );
	}

	/**
	 * Log in as an editor.
	 */
	private function as_editor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Add Simple Location's content filter when the fixture is in use.
	 */
	private function hook_simple_location_content(): void {
		if ( false === has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) ) {
			add_filter( 'the_content', [ 'Geo_Data', 'location_content' ], 12 );
		}
	}

	/**
	 * Visit a post and ensure the loop global matches it.
	 *
	 * @param int $post_id Post ID.
	 */
	private function visit_post( int $post_id ): void {
		$this->go_to( get_permalink( $post_id ) );
		if ( $post_id !== get_the_ID() ) {
			$GLOBALS['post'] = get_post( $post_id );
			setup_postdata( $GLOBALS['post'] );
		}
	}

	/**
	 * Store enough public geodata for Simple Location to append its line.
	 *
	 * @param int $post_id Post ID.
	 */
	private function add_location( int $post_id ): void {
		add_post_meta( $post_id, 'geo_latitude', '40.7128' );
		add_post_meta( $post_id, 'geo_longitude', '-74.0060' );
		add_post_meta( $post_id, 'geo_address', 'New York, NY' );
	}

	/**
	 * Run the_content and return the display defaults Simple Location
	 * would read at priority 12.
	 *
	 * @param string $content Content to filter.
	 * @return array<string, mixed>|null
	 */
	private function display_defaults_in_content( string $content ): ?array {
		$recorded = null;
		add_filter(
			'the_content',
			static function ( $filtered ) use ( &$recorded ) {
				$recorded = apply_filters(
					'simple_location_display_defaults',
					[
						'weather' => true,
						'icon'    => true,
					]
				);
				return $filtered;
			},
			12
		);

		apply_filters( 'the_content', $content );

		return $recorded;
	}

	/**
	 * A weather post whose content carries its authored p-weather turns off
	 * only Simple Location's weather display default.
	 */
	public function test_authored_weather_turns_off_simple_location_weather_default(): void {
		$post_id = $this->make_post( [ 'temperature' => 26.8 ], '1' );
		$this->visit_post( $post_id );

		$defaults = $this->display_defaults_in_content( self::AUTHORED_WEATHER );

		$this->assertIsArray( $defaults );
		$this->assertFalse( $defaults['weather'] );
		$this->assertTrue( $defaults['icon'] );
	}

	/**
	 * With authored weather in the content, Simple Location's line keeps
	 * the location and drops its weather.
	 */
	public function test_weather_post_with_authored_weather_drops_simple_location_weather(): void {
		$post_id = $this->make_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			],
			'1'
		);
		$this->add_location( $post_id );
		$this->hook_simple_location_content();
		$this->visit_post( $post_id );

		$html = apply_filters( 'the_content', self::AUTHORED_WEATHER );

		$this->assertStringContainsString( 'sloc-display', $html );
		$this->assertStringContainsString( 'New York, NY', $html );
		$this->assertStringNotContainsString( 'sloc-weather', $html );
	}

	/**
	 * A weather post made in the editor, with no weather of its own in the
	 * content, keeps Simple Location's weather: it's the only reading.
	 */
	public function test_weather_post_without_own_weather_keeps_simple_location_weather(): void {
		$post_id = $this->make_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			],
			'1'
		);
		$this->add_location( $post_id );
		$this->hook_simple_location_content();
		$this->visit_post( $post_id );

		$html = apply_filters( 'the_content', '<p>Out for a walk.</p>' );

		$this->assertStringContainsString( 'sloc-weather', $html );
		$this->assertSame( 1, substr_count( $html, 'p-weather' ) );
	}

	/**
	 * A kind-meta weather binding that printed the post's weather earlier
	 * in the request counts as the post's own weather.
	 */
	public function test_weather_binding_turns_off_simple_location_weather(): void {
		$post_id = $this->make_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			],
			'1'
		);
		$this->add_location( $post_id );
		$this->hook_simple_location_content();
		$this->visit_post( $post_id );
		$this->assertSame( '27 °C', $this->binding( 'weather_temperature', $post_id ) );

		$html = apply_filters( 'the_content', '<p>Out for a walk.</p>' );

		$this->assertStringContainsString( 'sloc-display', $html );
		$this->assertStringNotContainsString( 'sloc-weather', $html );
	}

	/**
	 * Non-weather kinds keep Simple Location's weather, authored weather
	 * or not.
	 */
	public function test_other_kinds_keep_simple_location_inline_weather(): void {
		$post_id = $this->make_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			],
			'1',
			'note'
		);
		$this->add_location( $post_id );
		$this->hook_simple_location_content();
		$this->visit_post( $post_id );

		$html = apply_filters( 'the_content', self::AUTHORED_WEATHER );

		$this->assertStringContainsString( 'sloc-weather', $html );
	}

	/**
	 * Calls outside the_content keep Simple Location's defaults untouched.
	 */
	public function test_display_defaults_untouched_outside_the_content(): void {
		$post_id = $this->make_post( [ 'temperature' => 26.8 ], '1' );
		$this->visit_post( $post_id );
		apply_filters( 'the_content', self::AUTHORED_WEATHER );

		$defaults = apply_filters( 'simple_location_display_defaults', [ 'weather' => true ] );

		$this->assertTrue( $defaults['weather'] );
	}

	/**
	 * An inactive Post Kinds source leaves Simple Location as the reading.
	 */
	public function test_inline_weather_kept_when_weather_source_inactive(): void {
		$post_id = $this->make_post( [ 'temperature' => 26.8 ], '1' );
		$this->visit_post( $post_id );
		add_filter( 'pkiw_weather_source_active', '__return_false' );

		$defaults = $this->display_defaults_in_content( self::AUTHORED_WEATHER );

		$this->assertIsArray( $defaults );
		$this->assertTrue( $defaults['weather'] );
	}

	/**
	 * Suppressing duplicate output doesn't mutate stored weather rows.
	 */
	public function test_suppression_writes_no_weather_meta(): void {
		$post_id = $this->make_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			],
			'1'
		);
		$this->add_location( $post_id );
		$this->hook_simple_location_content();
		$this->visit_post( $post_id );
		$before               = $this->weather_rows( $post_id );
		$this->weather_writes = [];

		$html = apply_filters( 'the_content', self::AUTHORED_WEATHER );

		$this->assertStringNotContainsString( 'sloc-weather', $html );
		$this->assertSame( $before, $this->weather_rows( $post_id ) );
		$this->assertSame( [], $this->weather_writes );
	}

	/**
	 * The adapter's site-default visibility matches Simple Location itself.
	 */
	public function test_site_default_visibility_matches_simple_location(): void {
		if ( ! class_exists( 'Geo_Data' ) || ! method_exists( 'Geo_Data', 'get_default_visibility' ) ) {
			$this->markTestSkipped( 'The real Simple Location Geo_Data API is unavailable.' );
		}

		$reflection = new ReflectionClass( 'Geo_Data' );
		if ( str_contains( (string) $reflection->getFileName(), 'fixtures/simple-location' ) ) {
			$this->markTestSkipped( 'Parity guard runs only against the real Simple Location plugin.' );
		}

		$post_id = $this->make_post( [ 'temperature' => 18 ], null );
		foreach ( [ '0', '1', '2', 'private', 'public', 'protected', 'none', null ] as $value ) {
			if ( null === $value ) {
				delete_option( 'geo_public' );
				$label = 'deleted';
			} else {
				update_option( 'geo_public', $value );
				$label = $value;
			}

			$expected = in_array( Geo_Data::get_default_visibility(), [ 'public', 'protected' ], true );
			$actual   = null !== Simple_Location_Weather::get_observation( $post_id );
			$this->assertSame( $expected, $actual, 'Site geo_public value: ' . $label );
		}
	}

	/**
	 * With the fixture or real plugin loaded, the source is detected.
	 */
	public function test_is_active_when_simple_location_functions_exist(): void {
		$this->assertTrue( Simple_Location_Weather::is_active() );
	}

	/**
	 * The pkiw_weather_source_active filter turns the source off: no data,
	 * no binding value, no markup.
	 */
	public function test_inactive_source_returns_null_and_renders_nothing(): void {
		$post_id = $this->make_post( [ 'temperature' => 26.8 ] );
		add_filter( 'pkiw_weather_source_active', '__return_false' );

		$this->assertFalse( Simple_Location_Weather::is_active() );
		$this->assertNull( Simple_Location_Weather::get_observation( $post_id ) );
		$this->assertNull( $this->binding( 'weather_temperature', $post_id ) );
		$this->assertSame( '', Simple_Location_Weather::render( $post_id ) );
	}

	/**
	 * Stored metric values come back unconverted with the metric unit.
	 */
	public function test_metric_observation(): void {
		$post_id = $this->make_post(
			[
				'temperature' => 26.8,
				'humidity'    => 61,
				'pressure'    => 1013,
				'windspeed'   => 3.1,
			]
		);

		$obs = Simple_Location_Weather::get_observation( $post_id );

		$this->assertIsArray( $obs );
		$this->assertSame( 26.8, $obs['temperature'] );
		$this->assertSame( '°C', $obs['temperature_unit'] );
		$this->assertSame( 'metric', $obs['units'] );
		$this->assertSame( 1013.0, $obs['pressure'] );
		$this->assertSame( 3.1, $obs['windspeed'] );
		$this->assertSame( '27 °C', $this->binding( 'weather_temperature', $post_id ) );
		$this->assertSame( '1013 hPa', $this->binding( 'weather_pressure', $post_id ) );
		$this->assertSame( '3.1 m/s', $this->binding( 'weather_windspeed', $post_id ) );
		$this->assertSame( '61%', $this->binding( 'weather_humidity', $post_id ) );
	}

	/**
	 * With sloc_measurements set to imperial, values go through Simple
	 * Location's own metric_to_imperial(), so both plugins show one number.
	 */
	public function test_imperial_observation_uses_simple_location_conversion(): void {
		update_option( 'sloc_measurements', 'imperial' );
		$post_id = $this->make_post(
			[
				'temperature' => 26.8,
				'pressure'    => 1013,
				'windspeed'   => 3.1,
				'rain'        => 2.54,
			]
		);

		$obs = Simple_Location_Weather::get_observation( $post_id );

		$this->assertIsArray( $obs );
		$this->assertSame( 80.24, $obs['temperature'] );
		$this->assertSame( '°F', $obs['temperature_unit'] );
		$this->assertSame( 'imperial', $obs['units'] );
		$this->assertSame( 30.39, $obs['pressure'] );
		$this->assertSame( 6.93, $obs['windspeed'] );
		$this->assertSame( '80 °F', $this->binding( 'weather_temperature', $post_id ) );
		$this->assertSame( '30.39 inHg', $this->binding( 'weather_pressure', $post_id ) );
		$this->assertSame( '6.93 mph', $this->binding( 'weather_windspeed', $post_id ) );
		$this->assertSame( '0.1 in', $this->binding( 'weather_rain', $post_id ) );
	}

	/**
	 * Only stored fields appear; nothing is filled in.
	 */
	public function test_missing_fields_are_omitted(): void {
		$post_id = $this->make_post( [ 'temperature' => 12.5 ] );

		$obs = Simple_Location_Weather::get_observation( $post_id );

		$this->assertIsArray( $obs );
		$this->assertSame( [ 'temperature', 'temperature_unit', 'units' ], array_keys( $obs ) );
		$this->assertNull( $this->binding( 'weather_summary', $post_id ) );
		$this->assertNull( $this->binding( 'weather_humidity', $post_id ) );
		$this->assertNull( $this->binding( 'weather_condition', $post_id ) );
	}

	/**
	 * A post with no stored weather has no observation and no markup.
	 */
	public function test_post_without_weather_returns_null(): void {
		$post_id = $this->make_post( [] );

		$this->assertNull( Simple_Location_Weather::get_observation( $post_id ) );
		$this->assertSame( '', Simple_Location_Weather::render( $post_id ) );
	}

	/**
	 * A condition code maps to Simple Location's own label and icon, and
	 * stands in for a missing summary.
	 */
	public function test_condition_code_maps_to_label_and_icon(): void {
		$post_id = $this->make_post(
			[
				'code'        => 800,
				'temperature' => 20,
			]
		);

		$obs = Simple_Location_Weather::get_observation( $post_id );

		$this->assertIsArray( $obs );
		$this->assertSame( '800', $obs['condition_code'] );
		$this->assertSame( 'Clear Sky', $obs['condition_label'] );
		$this->assertSame( 'Clear Sky', $obs['summary'] );
		$this->assertSame( 'wi-day-sunny', $obs['icon'] );
		$this->assertSame( 'Clear Sky', $this->binding( 'weather_condition', $post_id ) );
		$this->assertSame( '800', $this->binding( 'weather_code', $post_id ) );
	}

	/**
	 * A stored summary wins over the code label.
	 */
	public function test_stored_summary_wins_over_code_label(): void {
		$post_id = $this->make_post(
			[
				'code'    => 800,
				'summary' => 'Bright and still',
			]
		);

		$this->assertSame( 'Bright and still', $this->binding( 'weather_summary', $post_id ) );
		$this->assertSame( 'Clear Sky', $this->binding( 'weather_condition', $post_id ) );
	}

	/**
	 * Location privacy per viewer: geo_public 0 hides weather from anyone
	 * who can't edit the post, and editors always see it.
	 */
	public function test_private_geo_public_hides_weather_from_anonymous_only(): void {
		$post_id = $this->make_post( [ 'temperature' => 18 ], '0' );

		$this->assertNull( Simple_Location_Weather::get_observation( $post_id ) );
		$this->assertNull( $this->binding( 'weather_temperature', $post_id ) );
		$this->assertSame( '', Simple_Location_Weather::render( $post_id ) );

		$this->as_editor();
		$this->assertSame( 18.0, Simple_Location_Weather::get_observation( $post_id )['temperature'] ?? null );
		$this->assertSame( '18 °C', $this->binding( 'weather_temperature', $post_id ) );
	}

	/**
	 * Public (1) and protected (2) both show weather to anonymous visitors:
	 * weather is city-level, and protected only hides coordinates and maps.
	 *
	 * @testWith ["1"]
	 *           ["2"]
	 *
	 * @param string $geo_public Simple Location visibility.
	 */
	public function test_public_and_protected_show_weather( string $geo_public ): void {
		$post_id = $this->make_post( [ 'temperature' => 18 ], $geo_public );

		$this->assertSame( '18 °C', $this->binding( 'weather_temperature', $post_id ) );
	}

	/**
	 * An empty per-post geo_public falls back to the site's geo_public
	 * option the way Simple Location's Geo_Data::get_default_visibility()
	 * does: site private hides it, site public shows it.
	 */
	public function test_empty_geo_public_follows_simple_location_site_default(): void {
		$post_id = $this->make_post( [ 'temperature' => 18 ], null );

		$this->assertNull( Simple_Location_Weather::get_observation( $post_id ) );

		update_option( 'geo_public', '1' );
		$this->assertSame( '18 °C', $this->binding( 'weather_temperature', $post_id ) );
	}

	/**
	 * Post Kinds' own private location setting also hides the weather.
	 */
	public function test_pkiw_private_location_hides_weather(): void {
		$post_id = $this->make_post( [ 'temperature' => 18 ], '1' );
		update_post_meta( $post_id, '_pkiw_geo_privacy', 'private' );

		$this->assertNull( Simple_Location_Weather::get_observation( $post_id ) );
	}

	/**
	 * A legacy geo_weather array reads correctly and stays exactly as
	 * stored: no weather_* rows created, geo_weather not deleted.
	 */
	public function test_legacy_geo_weather_reads_without_writing(): void {
		$post_id = $this->make_post( [] );
		add_post_meta(
			$post_id,
			'geo_weather',
			[
				'temperature' => 9.4,
				'humidity'    => 88,
				'summary'     => 'light rain',
				'wind'        => [
					'speed'  => 5.2,
					'degree' => 270,
				],
			]
		);
		$before               = $this->weather_rows( $post_id );
		$this->weather_writes = [];

		$obs = Simple_Location_Weather::get_observation( $post_id );

		$this->assertIsArray( $obs );
		$this->assertSame( 9.4, $obs['temperature'] );
		$this->assertSame( 88.0, $obs['humidity'] );
		$this->assertSame( 'light rain', $obs['summary'] );
		$this->assertSame( 5.2, $obs['windspeed'] );
		$this->assertSame( 270.0, $obs['winddegree'] );
		$this->assertSame( $before, $this->weather_rows( $post_id ) );
		$this->assertSame( [], $this->weather_writes );
	}

	/**
	 * Rendering, bindings, and the stream card leave every weather meta row
	 * byte-identical and fire no meta write on a weather key.
	 */
	public function test_reads_never_write_weather_meta(): void {
		$post_id = $this->make_post(
			[
				'temperature' => 22.2,
				'code'        => 801,
				'humidity'    => 40,
			]
		);
		$before = $this->weather_rows( $post_id );

		Simple_Location_Weather::get_observation( $post_id );
		Simple_Location_Weather::render( $post_id );
		foreach ( [ 'weather_summary', 'weather_temperature', 'weather_condition', 'weather_humidity' ] as $key ) {
			$this->binding( $key, $post_id );
		}
		\PKIW\render_generic_stream_card( get_post( $post_id ) );

		$this->assertSame( $before, $this->weather_rows( $post_id ) );
		$this->assertSame( [], $this->weather_writes );
	}

	/**
	 * The render helper emits one accessible p-weather paragraph, text only.
	 */
	public function test_render_outputs_p_weather_text(): void {
		$post_id = $this->make_post(
			[
				'code'        => 800,
				'temperature' => 26.8,
			]
		);

		$html = Simple_Location_Weather::render( $post_id );

		$this->assertSame( '<p class="pk-weather p-weather">Clear Sky, 27 °C</p>', $html );
	}

	/**
	 * Weather-kind stream cards carry the observation; other kinds don't.
	 */
	public function test_stream_card_shows_weather_on_weather_kind_only(): void {
		$weather_post = $this->make_post( [ 'temperature' => 15 ] );
		$note_post    = $this->make_post( [ 'temperature' => 15 ], '1', 'note' );

		$this->assertStringContainsString( '<p class="pk-weather p-weather">15 °C</p>', \PKIW\render_generic_stream_card( get_post( $weather_post ) ) );
		$this->assertStringNotContainsString( 'p-weather', \PKIW\render_generic_stream_card( get_post( $note_post ) ) );
	}

	/**
	 * No Post Kinds source file writes Simple Location's weather data.
	 */
	public function test_no_pkiw_source_writes_weather_meta(): void {
		$root  = dirname( __DIR__, 3 );
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes' ) );
		$hits  = [];
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			// Code only: docblocks may name the functions this test forbids.
			$source = '';
			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
					continue;
				}
				$source .= is_array( $token ) ? $token[1] : $token;
			}
			if ( preg_match( '/set_(post|object)_weatherdata|migrate_weather|(update|add|delete)_(post_)?meta(data)?\s*\([^;]*[\'"](weather_|geo_weather)/', $source, $match ) ) {
				$hits[] = str_replace( $root . '/', '', $file->getPathname() ) . ': ' . $match[0];
			}
		}

		$this->assertSame( [], $hits );
	}
}
