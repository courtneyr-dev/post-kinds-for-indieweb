<?php
/**
 * Interactivity API coverage for the Check-in Dashboard block.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/** @group integration */
final class CheckinDashboardInteractivityTest extends WP_UnitTestCase {
	private const DASHBOARD_NAMESPACE = 'post-kinds-indieweb/checkin-dashboard';
	private const MODULE_ID = 'post-kinds-indieweb-checkin-dashboard-view-script-module';

	private int $checkin_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		$this->register_source_block();
		$this->checkin_id = $this->create_checkin();
	}

	public function tear_down(): void {
		$this->register_build_block();
		parent::tear_down();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function layouts(): array {
		return [
			'grid'     => [ 'grid' ],
			'map'      => [ 'map' ],
			'timeline' => [ 'timeline' ],
		];
	}

	/** @dataProvider layouts */
	public function test_server_and_unprocessed_markup_share_the_same_initial_state( string $layout ): void {
		$processed = $this->render_dashboard( $layout );
		add_filter( 'interactivity_process_directives', '__return_false' );
		try {
			$unprocessed = $this->render_dashboard( $layout );
		} finally {
			remove_filter( 'interactivity_process_directives', '__return_false' );
		}

		$processed_state   = $this->state_attributes( $processed );
		$unprocessed_state = $this->state_attributes( $unprocessed );

		$this->assertSame( $unprocessed_state, $processed_state );
		$this->assertSame( [ 'view' => $layout ], $processed_state['wrapper_context'] );
		foreach ( [ 'grid', 'map', 'timeline' ] as $view ) {
			$selected = $layout === $view;
			$this->assertSame( $selected, $processed_state['buttons'][ $view ]['active'], "{$view} button active state must match {$layout}." );
			$this->assertSame( $selected ? 'true' : 'false', $processed_state['buttons'][ $view ]['aria_pressed'], "{$view} button aria-pressed must match {$layout}." );
			$this->assertSame( $selected, $processed_state['panels'][ $view ]['active'], "{$view} panel active state must match {$layout}." );
			$this->assertSame( ! $selected, $processed_state['panels'][ $view ]['hidden'], "{$view} panel hidden state must oppose {$layout}." );
		}
	}

	public function test_context_and_module_data_carry_no_checkin_fields(): void {
		$html     = $this->render_dashboard( 'grid' );
		$contexts = $this->contexts( $html );
		$this->assertNotEmpty( $contexts );
		$encoded = wp_json_encode( $contexts, JSON_UNESCAPED_SLASHES );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( 'Sentinel Hidden Venue Qx7', $encoded );
		$this->assertStringNotContainsString( '9 Sentinel Way Qx7', $encoded );
		$this->assertStringNotContainsString( '12.987654', $encoded );
		$this->assertStringNotContainsString( '-45.123456', $encoded );
		$this->assertStringNotContainsString( get_permalink( $this->checkin_id ), $encoded );

		$data = apply_filters( 'script_module_data_@wordpress/interactivity', [] );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'state', $data );
		$this->assertArrayHasKey( self::DASHBOARD_NAMESPACE, $data['state'] );
		$this->assertSame( [ 'isSelected' ], array_keys( $data['state'][ self::DASHBOARD_NAMESPACE ] ) );
		$this->assertSame( [ 'state.isSelected' ], $data['derivedStateClosures'][ self::DASHBOARD_NAMESPACE ] ?? null );
		$module_json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$this->assertIsString( $module_json );
		$this->assertStringNotContainsString( 'Sentinel Hidden Venue Qx7', $module_json );
		$this->assertStringNotContainsString( '9 Sentinel Way Qx7', $module_json );
		$this->assertStringNotContainsString( '12.987654', $module_json );
		$this->assertStringNotContainsString( '-45.123456', $module_json );
		$this->assertStringNotContainsString( get_permalink( $this->checkin_id ), $module_json );
	}

	public function test_render_enqueues_only_the_script_module(): void {
		$this->render_dashboard( 'grid' );

		$this->assertContains( self::MODULE_ID, wp_script_modules()->get_queue() );
		$this->assertFalse( wp_script_is( 'post-kinds-indieweb-checkin-dashboard-view-script', 'registered' ) );
	}

	public function test_two_blocks_keep_independent_contexts_and_map_ids(): void {
		$html = do_blocks(
			'<!-- wp:post-kinds-indieweb/checkin-dashboard {"layout":"grid","showFilters":true} /-->'
			. '<!-- wp:post-kinds-indieweb/checkin-dashboard {"layout":"map","showFilters":true} /-->'
		);

		$contexts = [];
		$map_ids  = [];
		$tags     = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( self::DASHBOARD_NAMESPACE === $tags->get_attribute( 'data-wp-interactive' ) ) {
				$context = json_decode( (string) $tags->get_attribute( 'data-wp-context' ), true, 512, JSON_THROW_ON_ERROR );
				$this->assertIsArray( $context );
				$contexts[] = $context;
			}
			if ( $tags->has_class( 'checkin-map' ) ) {
				$map_ids[] = (string) $tags->get_attribute( 'id' );
			}
		}

		$this->assertSame( [ [ 'view' => 'grid' ], [ 'view' => 'map' ] ], $contexts );
		$this->assertCount( 2, $map_ids );
		$this->assertNotSame( $map_ids[0], $map_ids[1] );
	}

	private function create_checkin(): int {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'N5K4 Interactivity check-in',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'checkin', Taxonomy::TAXONOMY ) );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', 'Sentinel Hidden Venue Qx7' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_address', '9 Sentinel Way Qx7' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_latitude', '12.987654' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_longitude', '-45.123456' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'public' );
		return $post_id;
	}

	private function render_dashboard( string $layout ): string {
		return do_blocks( '<!-- wp:post-kinds-indieweb/checkin-dashboard {"layout":"' . $layout . '","showFilters":true} /-->' );
	}

	/**
	 * @return array{wrapper_context: array<string, string>, buttons: array<string, array{active: bool, aria_pressed: string}>, panels: array<string, array{active: bool, hidden: bool}>}
	 */
	private function state_attributes( string $html ): array {
		$state = [
			'wrapper_context' => [],
			'buttons'         => [],
			'panels'          => [],
		];
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( self::DASHBOARD_NAMESPACE === $tags->get_attribute( 'data-wp-interactive' ) ) {
				$context = json_decode( (string) $tags->get_attribute( 'data-wp-context' ), true, 512, JSON_THROW_ON_ERROR );
				$this->assertIsArray( $context );
				$state['wrapper_context'] = $context;
			}
			$view = (string) $tags->get_attribute( 'data-view' );
			if ( in_array( $view, [ 'grid', 'map', 'timeline' ], true ) ) {
				$state['buttons'][ $view ] = [
					'active'       => $tags->has_class( 'active' ),
					'aria_pressed' => (string) $tags->get_attribute( 'aria-pressed' ),
				];
			}
			foreach ( [ 'grid', 'map', 'timeline' ] as $panel ) {
				if ( $tags->has_class( 'checkin-view-' . $panel ) ) {
					$state['panels'][ $panel ] = [
						'active' => $tags->has_class( 'active' ),
						'hidden' => null !== $tags->get_attribute( 'hidden' ),
					];
				}
			}
		}
		return $state;
	}

	/** @return array<int, array<string, string>> */
	private function contexts( string $html ): array {
		$contexts = [];
		$tags     = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$value = $tags->get_attribute( 'data-wp-context' );
			if ( null === $value ) {
				continue;
			}
			$context = json_decode( (string) $value, true, 512, JSON_THROW_ON_ERROR );
			$this->assertIsArray( $context );
			$contexts[] = $context;
		}
		return $contexts;
	}

	private function register_source_block(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::DASHBOARD_NAMESPACE ) ) {
			unregister_block_type( self::DASHBOARD_NAMESPACE );
		}
		wp_deregister_script( 'post-kinds-indieweb-checkin-dashboard-view-script' );
		register_block_type( PKIW_PATH . 'src/blocks/checkin-dashboard' );
	}

	private function register_build_block(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::DASHBOARD_NAMESPACE ) ) {
			unregister_block_type( self::DASHBOARD_NAMESPACE );
		}
		wp_script_modules()->deregister( self::MODULE_ID );
		register_block_type( PKIW_PATH . 'build/blocks/checkin-dashboard' );
	}
}
