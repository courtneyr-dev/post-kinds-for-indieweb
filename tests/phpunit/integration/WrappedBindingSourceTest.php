<?php
/** Wrapped binding-source coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

/** @group integration */
final class WrappedBindingSourceTest extends WrappedTestCase {
	public function test_source_registers_and_resolves_five_steps_in_order(): void {
		$this->assertNotNull( get_block_bindings_source( 'post-kinds/wrapped' ) );
		$this->read_post();
		$first  = $this->wrapped_post( 'read', 2025, [], [ 'post_title' => 'First target' ] );
		$second = $this->wrapped_post( 'read', 2026, [], [ 'post_title' => 'Second target' ] );
		$this->assertSame( 'First target', $this->binding( $second, 'title', [ 'post_id' => $first, 'kind' => 'read', 'year' => 2026 ] ), 'step 1 wins' );
		$this->assertSame( 'Second target', $this->binding( $first, 'title', [ 'kind' => 'read', 'year' => 2026 ] ), 'step 2' );
		$this->assertSame( 'First target', $this->binding( 0, 'title', [], [ 'post-kinds-indieweb/wrappedKind' => 'read', 'post-kinds-indieweb/wrappedYear' => 2025 ] ), 'step 3' );
		$this->assertSame( 'Second target', $this->binding( $second, 'title' ), 'step 4' );
		$this->go_to( get_term_link( 'read', 'kind' ) );
		$this->assertSame( 'Second target', $this->binding( 0, 'title', [], [] ), 'step 5' );
	}

	public function test_bare_archive_strip_year_context_keeps_container_and_children_on_same_target(): void {
		$this->read_post( 2025 );
		$this->read_post( 2026 );
		$year_2025 = $this->wrapped_post( 'read', 2025, [], [ 'post_title' => 'Read 2025 exact' ] );
		$this->wrapped_post( 'read', 2026, [], [ 'post_title' => 'Read 2026 latest' ] );
		$this->go_to( get_term_link( 'read', 'kind' ) );
		$content = '<!-- wp:post-kinds-indieweb/wrapped-strip {"year":2025} -->'
			. '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"post-kinds/wrapped","args":{"key":"title"}}}}} --><p>fallback</p><!-- /wp:paragraph -->'
			. '<!-- /wp:post-kinds-indieweb/wrapped-strip -->';
		$html = do_blocks( $content );
		$this->assertStringContainsString( 'Read 2025 exact', $this->html_text( $html ), 'The bare strip and bound child must resolve the saved 2025 context; html=' . $html );
		$this->assertStringNotContainsString( 'Read 2026 latest', $this->html_text( $html ) );
		$url = do_blocks( '<!-- wp:post-kinds-indieweb/wrapped-strip {"year":2025} --><!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"post-kinds/wrapped","args":{"key":"url"}}}}} --><p>fallback</p><!-- /wp:paragraph --><!-- /wp:post-kinds-indieweb/wrapped-strip -->' );
		$this->assertStringContainsString( get_permalink( $year_2025 ), $url, 'the bound url resolves the same 2025 post' );
	}

	public function test_configured_order_drives_metrics_count_line_and_card_metrics(): void {
		for ( $index = 0; $index < 3; ++$index ) {
			$this->read_post( 2026, [ 'read_pages' => 100, 'read_rating' => 4 ] );
		}
		$wrapped = $this->wrapped_post( 'read', 2026, [ 'metrics' => [ 'average_rating', 'finished', 'pages' ] ] );
		$facts   = PKIW\wrapped_facts( $wrapped );
		$this->assertSame( [ 'average_rating', 'finished', 'pages' ], array_column( $facts['metrics'], 'key' ) );
		$this->assertSame( [ 'average_rating', 'finished', 'pages' ], array_column( $facts['card_metrics'], 'key' ) );
		$this->assertSame( '4.0 Average from 3 rated · 3 Finished', $facts['count_line'] );
	}

	public function test_ineligible_quiet_and_empty_fact_targets_return_empty_strings(): void {
		$this->read_post();
		foreach ( [
			[ 'post_status' => 'draft' ],
			[ 'post_status' => 'publish', 'post_password' => 'secret' ],
		] as $args ) {
			$id = $this->wrapped_post( 'read', 2026, [], $args );
			foreach ( [ 'title', 'url', 'year', 'kind_label', 'count_line', 'finished', 'label:finished' ] as $key ) {
				$this->assertSame( '', $this->binding( $id, $key, [ 'post_id' => $id ] ), $key );
			}
		}
		$quiet = $this->wrapped_post();
		update_post_meta( $quiet, 'activitypub_content_visibility', 'quiet_public' );
		foreach ( [ 'title', 'url', 'count_line', 'finished', 'label:finished' ] as $key ) {
			$this->assertSame( '', $this->binding( $quiet, $key, [ 'post_id' => $quiet ] ), 'quiet ' . $key );
			$this->assertSame( '', $this->binding( $quiet, $key ), 'quiet context ' . $key );
		}
	}

	public function test_omitted_metric_and_label_are_empty_unknown_is_null_and_values_are_plain_text(): void {
		$this->read_post();
		$wrapped = $this->wrapped_post( 'read', 2026, [ 'metrics' => [ 'finished' ] ] );
		$this->assertSame( '', $this->binding( $wrapped, 'pages' ) );
		$this->assertSame( '', $this->binding( $wrapped, 'label:pages' ) );
		$unknown = $this->binding( $wrapped, 'not-a-key' );
		$this->assertNull( $unknown, 'An unknown binding key must return null; actual=' . print_r( $unknown, true ) );
		foreach ( [ 'title', 'url', 'kind_label', 'count_line', 'finished', 'label:finished' ] as $key ) {
			$value = (string) $this->binding( $wrapped, $key );
			$this->assertSame( $value, wp_strip_all_tags( $value ), $key );
		}
	}
}
