<?php
/**
 * Game lookup credentials survive the API Connections save.
 *
 * The settings screen rendered BoardGameGeek and RAWG fields, but the
 * credentials sanitizer allowlist omitted both, so every save discarded
 * them and the game lookup reported api_not_configured.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Admin\Admin;
use PKIW\Admin\API_Settings;
use PKIW\APIs\BoardGameGeek;
use PKIW\APIs\RAWG;
use PKIW\Plugin;

/**
 * @group integration
 */
final class GameCredentialsSaveTest extends WP_UnitTestCase {

	private const BGG_TOKEN = 'bgg-credentials-fixture-token-not-real';
	private const RAWG_KEY  = 'rawg-credentials-fixture-key-not-real';

	public function set_up(): void {
		parent::set_up();
		delete_option( 'pkiw_api_credentials' );
	}

	private function admin(): Admin {
		return new Admin( Plugin::get_instance() );
	}

	public function test_sanitizer_keeps_bgg_and_rawg_credentials(): void {
		$sanitized = $this->admin()->sanitize_api_credentials(
			[
				'bgg'  => [ 'enabled' => '1', 'api_token' => self::BGG_TOKEN ],
				'rawg' => [ 'enabled' => '1', 'api_key' => self::RAWG_KEY ],
			]
		);

		$this->assertTrue( $sanitized['bgg']['enabled'] ?? false );
		$this->assertSame( self::BGG_TOKEN, $sanitized['bgg']['api_token'] ?? null );
		$this->assertTrue( $sanitized['rawg']['enabled'] ?? false );
		$this->assertSame( self::RAWG_KEY, $sanitized['rawg']['api_key'] ?? null );
	}

	public function test_saved_tokens_configure_the_game_apis(): void {
		update_option(
			'pkiw_api_credentials',
			$this->admin()->sanitize_api_credentials(
				[
					'bgg'  => [ 'enabled' => '1', 'api_token' => self::BGG_TOKEN ],
					'rawg' => [ 'enabled' => '1', 'api_key' => self::RAWG_KEY ],
				]
			)
		);

		$this->assertTrue( ( new BoardGameGeek() )->is_configured() );
		$this->assertTrue( ( new RAWG() )->is_configured() );
	}

	public function test_submitting_the_mask_keeps_the_saved_bgg_token(): void {
		update_option(
			'pkiw_api_credentials',
			[ 'bgg' => [ 'enabled' => true, 'api_token' => self::BGG_TOKEN ] ]
		);

		$sanitized = $this->admin()->sanitize_api_credentials(
			[ 'bgg' => [ 'enabled' => '1', 'api_token' => '************' ] ]
		);

		$this->assertSame( self::BGG_TOKEN, $sanitized['bgg']['api_token'] ?? null );
	}

	public function test_every_rendered_provider_field_survives_the_save(): void {
		$settings = new API_Settings( $this->admin() );
		$configs  = ( new ReflectionMethod( API_Settings::class, 'get_api_configs' ) )->invoke( $settings );

		$input = [];
		foreach ( $configs as $api_id => $config ) {
			$input[ $api_id ] = [ 'enabled' => '1' ];
			foreach ( array_keys( $config['fields'] ?? [] ) as $field_id ) {
				$input[ $api_id ][ $field_id ] = 'fixture-' . $field_id;
			}
		}

		$sanitized = $this->admin()->sanitize_api_credentials( $input );

		foreach ( $input as $api_id => $fields ) {
			$this->assertArrayHasKey( $api_id, $sanitized, "Provider {$api_id} is dropped on save." );
			foreach ( array_keys( $fields ) as $field_id ) {
				$this->assertArrayHasKey( $field_id, $sanitized[ $api_id ], "Field {$api_id}.{$field_id} is dropped on save." );
			}
		}
	}
}
