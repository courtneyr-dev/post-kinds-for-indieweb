<?php
/**
 * The admin Check-ins screen draws its map from the filtered tile layer.
 *
 * Its script hard-coded https://{s}.tile.openstreetmap.org, so the
 * pkiw_map_tile_url and pkiw_map_tile_attribution filters reached the
 * archive map and the dashboard block but not this screen (issue 224).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Admin\Checkin_Dashboard;

/**
 * @group integration
 */
final class CheckinDashboardTileLayerTest extends WP_UnitTestCase {

	private const SCREEN = 'pkiw_page_post-kinds-indieweb-checkins';

	/**
	 * The registries the plugin filled at init, put back after each test so
	 * later tests still find its registered scripts and styles.
	 *
	 * @var array{scripts: WP_Scripts|null, styles: WP_Styles|null}
	 */
	private array $registries;

	public function set_up(): void {
		parent::set_up();
		$this->registries = [
			'scripts' => $GLOBALS['wp_scripts'] ?? null,
			'styles'  => $GLOBALS['wp_styles'] ?? null,
		];
		$GLOBALS['wp_scripts'] = new WP_Scripts();
		$GLOBALS['wp_styles']  = new WP_Styles();
	}

	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = $this->registries['scripts'];
		$GLOBALS['wp_styles']  = $this->registries['styles'];
		parent::tear_down();
	}

	/**
	 * The object the screen's script reads, as localized for the page.
	 *
	 * @return array<string, mixed>
	 */
	private function localized(): array {
		$dashboard = ( new ReflectionClass( Checkin_Dashboard::class ) )->newInstanceWithoutConstructor();
		$dashboard->enqueue_assets( self::SCREEN );

		$data = (string) wp_scripts()->get_data( 'pkiw-checkin-dashboard', 'data' );
		$this->assertMatchesRegularExpression( '/var pkiwCheckinDashboard = (\{.*\});/s', $data );
		preg_match( '/var pkiwCheckinDashboard = (\{.*\});/s', $data, $match );

		return (array) json_decode( $match[1], true );
	}

	public function test_the_screen_gets_the_default_tile_layer(): void {
		$data = $this->localized();

		$this->assertSame( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', $data['tileUrl'] ?? null );
		$this->assertStringContainsString( 'OpenStreetMap', (string) ( $data['tileAttribution'] ?? '' ) );
	}

	public function test_the_screen_follows_the_tile_filters(): void {
		add_filter( 'pkiw_map_tile_url', static fn() => 'https://tiles.example.test/{z}/{x}/{y}.png' );
		add_filter( 'pkiw_map_tile_attribution', static fn() => '<a href="https://tiles.example.test/">Example tiles</a><script>x</script>' );

		$data = $this->localized();

		$this->assertSame( 'https://tiles.example.test/{z}/{x}/{y}.png', $data['tileUrl'] ?? null );
		$this->assertSame( '<a href="https://tiles.example.test/">Example tiles</a>x', $data['tileAttribution'] ?? null );
	}
}
