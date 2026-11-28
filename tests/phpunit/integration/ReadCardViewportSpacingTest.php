<?php
/**
 * Read Card mobile spacing through core's style states (WordPress 7.1+).
 *
 * @package PKIW
 */

declare(strict_types=1);

/** @group integration */
final class ReadCardViewportSpacingTest extends WP_UnitTestCase {

	private const BASE = [
		'bookTitle'  => 'Kindred',
		'authorName' => 'Octavia E. Butler',
		'bookUrl'    => 'https://openlibrary.org/works/OL3256078W',
		'readStatus' => 'finished',
		'style'      => [
			'spacing' => [
				'padding' => [
					'top'    => 'var:preset|spacing|50',
					'right'  => 'var:preset|spacing|50',
					'bottom' => 'var:preset|spacing|50',
					'left'   => 'var:preset|spacing|50',
				],
			],
		],
	];

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_render_block_states_support' ) ) {
			$this->markTestSkipped( 'Per-viewport block styles need WordPress 7.1 or later.' );
		}
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
	}

	public function tear_down(): void {
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
		parent::tear_down();
	}

	/**
	 * Render a Read Card from attributes, the way post content does.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return string HTML.
	 */
	private function render_card( array $attrs ): string {
		return do_blocks( serialize_block( [
			'blockName'    => 'post-kinds-indieweb/read-card',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		] ) );
	}

	public function test_a_mobile_padding_value_adds_one_media_rule_and_changes_nothing_else(): void {
		$base_html = $this->render_card( self::BASE );
		$base_css  = wp_style_engine_get_stylesheet_from_context( 'block-supports' );
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();

		$attrs                       = self::BASE;
		$attrs['style']['@mobile'] = [
			'spacing' => [
				'padding' => [
					'top'    => 'var:preset|spacing|20',
					'right'  => 'var:preset|spacing|20',
					'bottom' => 'var:preset|spacing|20',
					'left'   => 'var:preset|spacing|20',
				],
			],
		];
		$mobile_html = $this->render_card( $attrs );
		$mobile_css  = wp_style_engine_get_stylesheet_from_context( 'block-supports' );

		$this->assertSame( 1, preg_match( '/class="([^"]*\bwp-states-[a-z0-9-]+)/', $mobile_html, $match ), 'The card gains one state class.' );
		preg_match( '/\b(wp-states-[a-z0-9-]+)/', $match[1], $state_class );

		$this->assertMatchesRegularExpression(
			'/@media \(width <= 480px\)\{\.' . preg_quote( $state_class[1], '/' ) . '[^{]*\{[^}]*padding-top:var\(--wp--preset--spacing--20\) !important/',
			$mobile_css,
			'The mobile value prints as a theme token inside the mobile media query.'
		);
		$this->assertStringNotContainsString( 'spacing--20', $mobile_html, 'The mobile value never lands in the inline base style.' );

		$this->assertSame(
			$base_html,
			preg_replace( '/ ' . preg_quote( $state_class[1], '/' ) . '\b/', '', $mobile_html ),
			'Base markup, inline padding, target URL and attribution are byte-identical apart from the state class.'
		);
		$this->assertStringContainsString( 'href="https://openlibrary.org/works/OL3256078W"', $mobile_html );
		$this->assertStringContainsString( '<span class="p-author h-card"><span class="p-name">Octavia E. Butler</span></span>', $mobile_html );
		$this->assertStringContainsString( 'var(--wp--preset--spacing--50)', $mobile_html, 'The base padding still prints inline.' );
		$this->assertStringNotContainsString( ':focus', str_replace( $base_css, '', $mobile_css ), 'No focus rule is added or changed.' );
	}

	public function test_saved_mobile_value_survives_a_save_and_reload(): void {
		$attrs                       = self::BASE;
		$attrs['style']['@mobile'] = [ 'spacing' => [ 'padding' => [ 'top' => 'var:preset|spacing|20' ] ] ];
		$post_id                     = self::factory()->post->create( [
			'post_status'  => 'publish',
			'post_content' => serialize_block( [
				'blockName'    => 'post-kinds-indieweb/read-card',
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			] ),
		] );

		clean_post_cache( $post_id );
		$reloaded = parse_blocks( get_post( $post_id )->post_content )[0]['attrs']['style'];
		$this->assertSame( 'var:preset|spacing|20', $reloaded['@mobile']['spacing']['padding']['top'] );
		$this->assertSame( self::BASE['style']['spacing'], $reloaded['spacing'], 'The base value is unchanged.' );
	}
}
