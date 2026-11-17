<?php
/** Wrapped off-site absence coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedOffSiteTest extends WrappedTestCase {
	private int $wrapped;
	private string $top_title = 'Off-site top sentinel Qx7';
	private string $cover = 'https://example.invalid/offsite-qx7.jpg';

	public function set_up(): void {
		parent::set_up();
		// Loaded here, not at file scope: PHPUnit loads every test file, and QuestionContentStepTest
		// asserts the stub is absent until its own test loads it.
		require_once dirname( __DIR__ ) . '/fixtures/activitypub-request-stub.php';
		for ( $index = 0; $index < 3; ++$index ) {
			$this->read_post( 2026, [ 'read_pages' => 100, 'read_rating' => 5, 'read_cover' => $this->cover ], [ 'post_title' => 0 === $index ? $this->top_title : 'Off-site control ' . $index ] );
		}
		$this->wrapped = $this->wrapped_post();
		$GLOBALS['pkiw_test_activitypub_request'] = false;
	}

	public function tear_down(): void {
		$GLOBALS['pkiw_test_activitypub_request'] = false;
		remove_filter( 'wp_doing_cron', '__return_true' );
		parent::tear_down();
	}

	public function test_feed_and_content_feed_omit_every_wrapped_surface(): void {
		$this->assertOnSiteControl();
		$this->go_to( get_feed_link() );
		$GLOBALS['post'] = get_post( $this->wrapped );
		setup_postdata( $GLOBALS['post'] );
		$this->assertAbsent( get_the_content_feed( 'rss2' ) );
		$this->assertGatedHelpers();
		// Core's own feed tests load the template with @ so its header() calls don't fail under PHPUnit.
		ob_start();
		@require ABSPATH . WPINC . '/feed-rss2.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$feed = (string) ob_get_clean();
		// The feed also lists the counted Read posts as their own items, so only the Wrapped item is checked.
		$xml = simplexml_load_string( $feed );
		$this->assertNotFalse( $xml, 'The RSS2 feed must parse; feed=' . $feed );
		$item = null;
		foreach ( $xml->channel->item as $candidate ) {
			if ( get_permalink( $this->wrapped ) === (string) $candidate->link ) {
				$item = $candidate;
			}
		}
		$this->assertNotNull( $item, 'The Wrapped post must be a feed item.' );
		$this->assertAbsent( (string) $item->asXML() . (string) $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded );
		wp_reset_postdata();
	}

	public function test_activitypub_request_omits_every_wrapped_surface(): void {
		$this->assertOnSiteControl();
		$GLOBALS['pkiw_test_activitypub_request'] = true;
		$this->assertAbsent( $this->renderContent() );
		$this->assertGatedHelpers();
	}

	public function test_save_hook_render_omits_every_wrapped_surface(): void {
		$this->assertOnSiteControl();
		// Spec A15a (3): render inside wp_publish_post(), at ActivityPub's wp_after_insert_post 33 and
		// on the status-transition actions core fires outside transition_post_status itself.
		$draft    = $this->wrapped_post( 'read', 2026, [], [ 'post_status' => 'draft' ] );
		$captured = [];
		$probe    = function ( $post_or_id ) use ( &$captured, $draft ): void {
			$post_id = $post_or_id instanceof WP_Post ? $post_or_id->ID : (int) $post_or_id;
			if ( $draft !== $post_id ) {
				return;
			}
			$GLOBALS['post'] = get_post( $post_id );
			setup_postdata( $GLOBALS['post'] );
			$captured[ current_filter() ] = [
				'html'    => apply_filters( 'the_content', (string) get_post_field( 'post_content', $post_id ) ),
				'title'   => $this->binding( $post_id, 'title', [ 'post_id' => $post_id ] ),
				'finished' => $this->binding( $post_id, 'finished', [ 'post_id' => $post_id ] ),
				'facts'   => PKIW\wrapped_facts( $post_id ),
			];
			wp_reset_postdata();
		};
		add_action( 'wp_after_insert_post', $probe, 33 );
		add_action( 'publish_post', $probe );
		add_action( 'draft_to_publish', $probe );
		wp_publish_post( $draft );
		$this->assertSame( [ 'draft_to_publish', 'publish_post', 'wp_after_insert_post' ], array_keys( $captured ), 'every probe must have run' );
		foreach ( $captured as $hook => $seen ) {
			$this->assertAbsent( $seen['html'] );
			$this->assertSame( '', $seen['title'], $hook . ' title binding' );
			$this->assertSame( '', $seen['finished'], $hook . ' metric binding' );
			$this->assertSame( [], $seen['facts'], $hook . ' facts' );
		}
		$this->assertSame( 'publish', get_post_status( $draft ) );
		$this->go_to( get_permalink( $draft ) );
		$this->assertNotSame( [], PKIW\wrapped_facts( $draft ), 'after the save the published Wrapped prints on-site' );
	}

	public function test_cron_render_omits_every_wrapped_surface(): void {
		$this->assertOnSiteControl();
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertAbsent( $this->renderContent() );
		$this->assertGatedHelpers();
	}

	public function test_rest_content_omits_numbers_but_stats_ability_still_returns_them(): void {
		$this->assertOnSiteControl();
		$inside  = null;
		$capture = function ( $response ) use ( &$inside ) {
			$inside = [ $this->binding( $this->wrapped, 'title', [ 'post_id' => $this->wrapped ] ), PKIW\wrapped_facts( $this->wrapped ) ];
			return $response;
		};
		add_filter( 'rest_prepare_post', $capture );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->wrapped ) );
		remove_filter( 'rest_prepare_post', $capture );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ '', [] ], $inside, 'binding and facts inside the REST dispatch' );
		$this->assertAbsent( (string) $response->get_data()['content']['rendered'] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/post-kinds/kind-year-stats/run' );
		$request->set_query_params( [ 'input' => [ 'kind' => 'read', 'year' => 2026 ] ] );
		$ability = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $ability->get_status() );
		$this->assertSame( 3, $this->metric( $ability->get_data(), 'finished' )['value'] );
	}

	public function test_atproto_and_markdown_alternates_omit_every_wrapped_surface(): void {
		foreach ( [ [ 'atproto', 1 ], [ 'markdown_request', 1 ], [ 'format', 'markdown' ] ] as [ $key, $value ] ) {
			$this->assertOnSiteControl();
			$this->assertAbsent(
				$this->renderContent(
					static function () use ( $key, $value ): void {
						set_query_var( $key, $value );
					}
				)
			);
			set_query_var( $key, $value );
			$this->assertGatedHelpers();
			set_query_var( $key, null );
		}
	}

	public function test_frontend_single_and_block_renderer_preview_are_on_site_controls(): void {
		$this->assertOnSiteControl();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/wrapped' );
		$request->set_query_params( [ 'context' => 'edit', 'post_id' => $this->wrapped, 'attributes' => [ 'kind' => 'read', 'year' => 2026 ] ] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertPresent( (string) $response->get_data()['rendered'] );
	}

	private function renderContent( ?callable $after_go_to = null ): string {
		$this->go_to( get_permalink( $this->wrapped ) );
		if ( null !== $after_go_to ) {
			$after_go_to();
		}
		$GLOBALS['post'] = get_post( $this->wrapped );
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', (string) get_post_field( 'post_content', $this->wrapped ) );
		wp_reset_postdata();
		return $html;
	}

	private function assertOnSiteControl(): void {
		$GLOBALS['pkiw_test_activitypub_request'] = false;
		remove_filter( 'wp_doing_cron', '__return_true' );
		foreach ( [ 'atproto', 'markdown_request', 'format' ] as $key ) {
			set_query_var( $key, null );
		}
		$this->go_to( get_permalink( $this->wrapped ) );
		$this->assertPresent( $this->renderContent() );
	}

	private function assertPresent( string $html ): void {
		$data = Wrapped_Stats::for_kind_year( 'read', 2026 );
		$this->assertStringContainsString( 'pkiw-wrapped', $html, 'The on-site negative control must contain Wrapped markup; html=' . $html );
		$this->assertStringContainsString( $data['metrics'][0]['label'], $html );
		$this->assertStringContainsString( $data['metrics'][0]['display'], $html );
		$this->assertStringContainsString( $this->top_title, $html );
		$this->assertStringContainsString( $this->cover, $html );
	}

	private function assertAbsent( string $html ): void {
		$data = Wrapped_Stats::for_kind_year( 'read', 2026 );
		foreach ( [ 'pkiw-wrapped', 'pkiw-wrapped-strip', $this->top_title, $this->cover ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
		foreach ( $data['metrics'] as $metric ) {
			$this->assertStringNotContainsString( $metric['label'], $html, $metric['key'] . ' label' );
			if ( strlen( $metric['display'] ) >= 3 ) {
				$this->assertStringNotContainsString( $metric['display'], $html, $metric['key'] . ' display' );
			}
		}
	}

	private function assertGatedHelpers(): void {
		$keys = [ 'title', 'url', 'count_line' ];
		foreach ( Wrapped_Stats::for_kind_year( 'read', 2026 )['metrics'] as $metric ) {
			$keys[] = $metric['key'];
			$keys[] = 'label:' . $metric['key'];
		}
		foreach ( $keys as $key ) {
			$this->assertSame( '', $this->binding( $this->wrapped, $key ) );
		}
		$this->assertSame( [], PKIW\wrapped_facts( $this->wrapped ) );
	}
}
