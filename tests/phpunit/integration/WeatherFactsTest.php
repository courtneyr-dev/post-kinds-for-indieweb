<?php
/**
 * The weather facts reader (issue 246).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Simple_Location_Weather;
use PKIW\Kind_Facts;
use PKIW\Plugin;
use PKIW\Taxonomy;

/**
 * Weather facts carry the station log and the public-tier place, and nothing
 * that could place a post more precisely than its tier allows.
 *
 * @group integration
 */
final class WeatherFactsTest extends WP_UnitTestCase {

	/**
	 * The keys the reader returns, in order.
	 *
	 * @var string[]
	 */
	private const KEYS = [
		'name',
		'name_is_title',
		'name_is_reading',
		'reading',
		'reading_source',
		'reading_in_content',
		'icon',
		'code',
		'temperature_visible',
		'log',
		'date_iso',
		'calendar_day',
		'place_name',
		'place_locality',
		'place_region',
		'place_country',
	];

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
		update_option( 'timezone_string', 'America/New_York' );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A weather post with a full observation and a named place.
	 *
	 * @param array<string, mixed> $args    Extra post arguments.
	 * @param array<string, mixed> $weather Weather rows, replacing the default observation.
	 * @param array<string, mixed> $meta    Extra post meta.
	 */
	private function weather_post( array $args = [], ?array $weather = null, array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => '',
					'post_date'    => '2026-08-03 09:15:00',
					'post_content' => '<!-- wp:paragraph --><p>Out for a walk.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'weather', Taxonomy::TAXONOMY ) );
		$weather = $weather ?? [
			'code'        => 800,
			'temperature' => 26.8,
			'windspeed'   => 3.1,
			'winddegree'  => 315,
			'humidity'    => 61,
			'pressure'    => 1013,
			'cloudiness'  => 12,
			'rain'        => 0.5,
		];
		foreach ( $weather as $prop => $value ) {
			add_post_meta( $post_id, 'weather_' . $prop, $value );
		}
		$meta = array_merge(
			[
				'geo_public'       => '1',
				'geo_venue'        => 'Example Hall',
				'geo_locality'     => 'Sampletown',
				'geo_region'       => 'Example Region',
				'geo_country_name' => 'Exampleland',
				'geo_address'      => '100 Example Avenue, Sampletown',
				'geo_latitude'     => '10.1',
				'geo_longitude'    => '20.2',
			],
			$meta
		);
		foreach ( $meta as $key => $value ) {
			add_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * A binding's label, the way the binding definitions state it.
	 *
	 * @param string $key Binding key.
	 */
	private function label( string $key ): string {
		$bindings = Plugin::get_instance()->get_block_bindings();
		$this->assertNotNull( $bindings );

		return (string) $bindings->get_bindings()[ $key ]['label'];
	}

	/**
	 * Rows of a facts log as key => value.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return array<string, string>
	 */
	private function rows( array $facts ): array {
		$rows = [];
		foreach ( $facts['log'] as $row ) {
			$rows[ $row['key'] ] = $row['value'];
		}

		return $rows;
	}

	public function test_the_reader_is_registered_for_weather_with_the_place_tiers(): void {
		$reader = Kind_Facts::reader( 'weather' );

		$this->assertIsArray( $reader );
		$this->assertSame( [ Simple_Location_Weather::class, 'facts' ], $reader['reader'] );
		$this->assertSame(
			[
				'place_name'     => 'name',
				'place_locality' => 'locality',
				'place_region'   => 'region',
				'place_country'  => 'country',
			],
			$reader['location']
		);
	}

	public function test_the_facts_hold_exactly_the_agreed_keys(): void {
		$facts = Kind_Facts::get( $this->weather_post() );

		$this->assertSame( self::KEYS, array_keys( $facts ) );
	}

	public function test_no_key_could_hold_a_street_a_postal_code_coordinates_or_a_url(): void {
		$facts = Kind_Facts::get( $this->weather_post( [], null, [ 'geo_public' => '1' ] ) );
		$json  = (string) wp_json_encode( $facts );

		foreach ( array_keys( $facts ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/street|postal|zip|latitude|longitude|coordinate|map|url|geo|address/i', $key );
		}
		foreach ( [ '100 Example Avenue', '10.1', '20.2', 'http' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_the_log_labels_are_the_binding_labels_byte_for_byte(): void {
		$facts = Kind_Facts::get( $this->weather_post() );

		$this->assertSame(
			[
				'summary'     => $this->label( 'weather_summary' ),
				'temperature' => $this->label( 'weather_temperature' ),
				'windspeed'   => $this->label( 'weather_windspeed' ),
				'winddegree'  => $this->label( 'weather_winddegree' ),
				'humidity'    => $this->label( 'weather_humidity' ),
				'pressure'    => $this->label( 'weather_pressure' ),
				'cloudiness'  => $this->label( 'weather_cloudiness' ),
				'rain'        => $this->label( 'weather_rain' ),
			],
			array_column( $facts['log'], 'label', 'key' )
		);
		$this->assertSame( 'Weather Summary', $facts['log'][0]['label'] );
		$this->assertSame( 'Wind Direction', $facts['log'][3]['label'] );
	}

	public function test_the_rows_follow_the_agreed_order(): void {
		$facts = Kind_Facts::get( $this->weather_post() );

		$this->assertSame(
			[ 'summary', 'temperature', 'windspeed', 'winddegree', 'humidity', 'pressure', 'cloudiness', 'rain' ],
			array_column( $facts['log'], 'key' )
		);
	}

	public function test_each_value_equals_the_formatted_field_and_wind_direction_reads_in_degrees(): void {
		$post_id = $this->weather_post();
		$rows    = $this->rows( Kind_Facts::get( $post_id ) );

		foreach ( [ 'summary', 'temperature', 'windspeed', 'winddegree', 'humidity', 'pressure', 'cloudiness', 'rain' ] as $key ) {
			$this->assertSame( Simple_Location_Weather::format( $key, $post_id ), $rows[ $key ], $key );
		}
		$this->assertSame( '315°', $rows['winddegree'] );
		$this->assertSame( '27 °C', $rows['temperature'] );
		$this->assertSame( '3.1 m/s', $rows['windspeed'] );
	}

	public function test_a_row_is_left_out_when_its_value_is_not_stored(): void {
		$post_id = $this->weather_post(
			[],
			[
				'temperature' => 12.5,
				'humidity'    => 40,
			]
		);

		$this->assertSame( [ 'temperature', 'humidity' ], array_column( Kind_Facts::get( $post_id )['log'], 'key' ) );
	}

	public function test_no_value_or_label_says_high_low_observed_or_privacy(): void {
		$facts = Kind_Facts::get( $this->weather_post() );
		$text  = implode( ' ', array_merge( array_column( $facts['log'], 'value' ), array_column( $facts['log'], 'label' ), [ $facts['name'], $facts['reading'] ] ) );

		foreach ( [ 'High', 'Low', 'Observed', 'Privacy' ] as $word ) {
			$this->assertStringNotContainsString( $word, $text, $word );
		}
	}

	public function test_place_follows_the_privacy_tier(): void {
		$expected = [
			'place_name'     => 'Example Hall',
			'place_locality' => 'Sampletown',
			'place_region'   => 'Example Region',
			'place_country'  => 'Exampleland',
		];
		$hidden   = array_fill_keys( array_keys( $expected ), '' );

		$unset       = $this->weather_post();
		$approximate = $this->weather_post( [], null, [ '_pkiw_geo_privacy' => 'approximate' ] );
		$public      = $this->weather_post( [], null, [ '_pkiw_geo_privacy' => 'public' ] );
		$private     = $this->weather_post( [], null, [ '_pkiw_geo_privacy' => 'private' ] );
		$zero        = $this->weather_post( [], null, [ 'geo_public' => '0' ] );

		foreach ( [ 'unset' => $unset, 'approximate' => $approximate, 'public' => $public ] as $label => $post_id ) {
			$facts = Kind_Facts::get( $post_id );
			$this->assertSame( $expected, array_intersect_key( $facts, $expected ), $label );
		}
		foreach ( [ 'private' => $private, 'geo_public 0' => $zero ] as $label => $post_id ) {
			$facts = Kind_Facts::get( $post_id );
			$this->assertSame( $hidden, array_intersect_key( $facts, $expected ), $label );
		}
	}

	public function test_an_administrator_sees_every_log_row_on_an_admin_screen_but_only_the_public_tier_place(): void {
		$post_id = $this->weather_post( [], null, [ '_pkiw_geo_privacy' => 'private' ] );

		$this->assertSame( [], Kind_Facts::get( $post_id )['log'], 'An anonymous reader of a private post gets an empty log.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( [], Kind_Facts::get( $post_id )['log'], 'The front end shows an administrator what a visitor sees.' );

		set_current_screen( 'edit-post' );
		try {
			$facts = Kind_Facts::get( $post_id );
		} finally {
			$GLOBALS['current_screen'] = null;
		}

		$this->assertCount( 8, $facts['log'] );
		$this->assertSame( 'observation', $facts['reading_source'] );
		foreach ( [ 'place_name', 'place_locality', 'place_region', 'place_country' ] as $key ) {
			$this->assertSame( '', $facts[ $key ], $key );
		}
	}

	public function test_a_protected_post_and_an_unreadable_private_post_give_no_facts(): void {
		$protected = $this->weather_post( [ 'post_password' => 'secret' ] );
		$private   = $this->weather_post( [ 'post_status' => 'private' ] );

		$this->assertSame( [], Kind_Facts::get( $protected ) );
		$this->assertSame( [], Simple_Location_Weather::facts( $protected ), 'The reader itself refuses too.' );
		$this->assertSame( [], Kind_Facts::get( $private ) );
		$this->assertSame( [], Simple_Location_Weather::facts( $private ) );
	}

	public function test_a_post_of_another_kind_gives_no_weather_facts(): void {
		$note = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );

		$this->assertSame( [], Simple_Location_Weather::facts( $note ) );
	}

	public function test_the_name_is_the_title_then_the_reading_then_the_kind(): void {
		$titled   = $this->weather_post( [ 'post_title' => 'A cold snap' ] );
		$reading  = $this->weather_post();
		$nothing  = $this->weather_post( [], [] );
		$authored = $this->weather_post( [ 'post_content' => '<p><span class="p-weather">Mild</span></p>' ], [], [ 'geo_public' => '0' ] );

		$facts = Kind_Facts::get( $titled );
		$this->assertSame( 'A cold snap', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );
		$this->assertFalse( $facts['name_is_reading'] );

		$facts = Kind_Facts::get( $reading );
		$this->assertSame( 'Clear Sky, 27 °C', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'], 'A synthetic name never claims to be the title.' );
		$this->assertTrue( $facts['name_is_reading'] );

		$facts = Kind_Facts::get( $authored );
		$this->assertSame( 'Mild', $facts['name'] );
		$this->assertTrue( $facts['name_is_reading'] );
		$this->assertSame( 'authored', $facts['reading_source'] );

		$facts = Kind_Facts::get( $nothing );
		$this->assertSame( 'Weather', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'] );
		$this->assertFalse( $facts['name_is_reading'] );
		$this->assertSame( '', $facts['reading_source'] );
	}

	public function test_the_reading_icon_code_and_temperature_flag_come_from_the_reading(): void {
		$facts = Kind_Facts::get( $this->weather_post() );

		$this->assertSame( 'Clear Sky, 27 °C', $facts['reading'] );
		$this->assertSame( 'observation', $facts['reading_source'] );
		$this->assertSame( 'wi-day-sunny', $facts['icon'] );
		$this->assertSame( '800', $facts['code'] );
		$this->assertTrue( $facts['temperature_visible'] );
		$this->assertFalse( $facts['reading_in_content'] );
	}

	public function test_an_observation_only_log_is_empty_for_authored_or_hidden_readings(): void {
		$authored = $this->weather_post( [ 'post_content' => '<p><span class="p-weather">Mild</span></p>' ], null, [ 'geo_public' => '0' ] );

		$facts = Kind_Facts::get( $authored );

		$this->assertSame( [], $facts['log'] );
		$this->assertTrue( $facts['reading_in_content'] );
		$this->assertSame( '', $facts['icon'] );
		$this->assertFalse( $facts['temperature_visible'] );
	}

	public function test_the_log_is_empty_unless_the_reading_is_the_observation(): void {
		// Humidity alone is no reading, so the authored text is: no log rows from the observation behind it.
		$post_id = $this->weather_post(
			[ 'post_content' => '<p><span class="p-weather">Muggy</span></p>' ],
			[ 'humidity' => 61 ]
		);

		$facts = Kind_Facts::get( $post_id );

		$this->assertSame( 'authored', $facts['reading_source'] );
		$this->assertSame( [], $facts['log'] );
	}

	public function test_the_dates_are_the_post_dates(): void {
		$post_id = $this->weather_post();
		$facts   = Kind_Facts::get( $post_id );

		$this->assertSame( get_post_time( 'c', true, $post_id ), $facts['date_iso'] );
		$this->assertSame( '2026-08-03', $facts['calendar_day'] );
	}

	public function test_reading_the_facts_does_not_mark_the_weather_as_printed(): void {
		$post_id = $this->weather_post();
		$bound   = new ReflectionProperty( Simple_Location_Weather::class, 'bound' );
		$bound->setValue( null, [] );

		Kind_Facts::get( $post_id );

		$this->assertSame( [], $bound->getValue(), 'No format() call, so Simple Location\'s own weather is not switched off.' );
	}

	public function test_facts_read_no_weather_meta_for_writing(): void {
		$post_id = $this->weather_post();
		$writes  = [];
		$record  = static function ( $meta_id, $object_id, $meta_key ) use ( &$writes ): void {
			$writes[] = (string) $meta_key;
		};
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $action ) {
			add_action( $action, $record, 10, 3 );
		}

		Kind_Facts::get( $post_id );

		$this->assertSame( [], $writes );
		$this->assertSame( 26.8, (float) get_post_meta( $post_id, 'weather_temperature', true ) );
	}
}
