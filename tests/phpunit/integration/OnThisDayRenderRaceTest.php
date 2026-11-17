<?php
/** On This Day late-privacy render coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\On_This_Day;
use PKIW\Public_Listing;
use PKIW\Taxonomy;

/** @group integration */
final class OnThisDayRenderRaceTest extends WP_UnitTestCase {
	private DateTimeImmutable $today;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		On_This_Day::register_blocks();
		$this->today = new DateTimeImmutable( '2026-11-17 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
	}

	public function tear_down(): void {
		remove_all_actions( 'the_post' );
		On_This_Day::override_now( null );
		wp_reset_postdata();
		parent::tear_down();
	}

	/** @dataProvider transition_provider */
	public function test_post_hidden_after_query_has_no_item_heading_or_section_count( string $transition ): void {
		$target = $this->post( 'RACE-' . $transition, '2025-11-17 10:00:00' );
		$this->post( 'Older control', '2024-11-17 10:00:00' );
		$this->install_query();
		$changed = false;
		add_action(
			'the_post',
			function ( WP_Post $post ) use ( $target, $transition, &$changed ): void {
				if ( $changed || $post->ID !== $target ) {
					return;
				}
				$changed = true;
				$this->hide( $target, $transition );
			}
		);
		$html = $this->render();
		$this->assertStringNotContainsString( 'post-' . $target, $html );
		$this->assertStringNotContainsString( 'RACE-' . $transition, $html );
		$this->assertStringNotContainsString( '2025</', $html );
		$this->assertStringNotContainsString( '<li class="wp-block-post post-' . $target . '"></li>', $html );
		$this->assertStringContainsString( 'data-pkiw-sections="1"', $html );
	}

	public function test_hiding_one_of_two_posts_keeps_the_shared_year_heading_and_one_item(): void {
		$target = $this->post( 'Shared hidden', '2025-11-17 11:00:00' );
		$control = $this->post( 'Shared visible', '2025-11-17 10:00:00' );
		$this->install_query();
		add_action( 'the_post', function ( WP_Post $post ) use ( $target ): void {
			if ( $post->ID === $target ) {
				wp_update_post( [ 'ID' => $target, 'post_status' => 'private' ] );
			}
		} );
		$html = $this->render();
		$this->assertStringContainsString( '2025', $html );
		$this->assertStringContainsString( 'post-' . $control, $html );
		$this->assertStringNotContainsString( 'post-' . $target, $html );
		$this->assertSame( 1, substr_count( $html, '<li' ) );
	}

	public static function transition_provider(): array {
		return [ 'private' => [ 'private' ], 'password' => [ 'password' ], 'quiet' => [ 'quiet' ] ];
	}

	private function hide( int $id, string $transition ): void {
		if ( 'private' === $transition ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'private' ] );
		} elseif ( 'password' === $transition ) {
			wp_update_post( [ 'ID' => $id, 'post_password' => 'secret' ] );
		} else {
			update_post_meta( $id, Public_Listing::meta_keys()[0], 'quiet_public' );
		}
	}

	private function install_query(): void {
		$GLOBALS['wp_query'] = new WP_Query( On_This_Day::query_args( $this->today, [ 'pkiw_group_source' => On_This_Day::SOURCE, 'pkiw_group_order' => 'DESC', 'posts_per_page' => 20 ] ) );
	}

	private function render(): string {
		$markup = '<!-- wp:query {"queryId":7,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:' . On_This_Day::ENTRY_BLOCK . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		return do_blocks( $markup );
	}

	private function post( string $title, string $date ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => $title, 'post_date' => $date ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		return $id;
	}
}
