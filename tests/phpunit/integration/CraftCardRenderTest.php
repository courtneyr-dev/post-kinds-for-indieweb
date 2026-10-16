<?php
/**
 * The craft card's server render (issue 249).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Taxonomy;

/**
 * The craft card prints a description list of what the author stored: the
 * craft type verbatim and the time spent. The editor's preview, the front end,
 * feeds and ATmosphere's text all read this one render. Nothing about status
 * or completion prints, and no microformats class does either.
 *
 * Names and times are invented.
 *
 * @group integration
 */
final class CraftCardRenderTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/craft-card';

	/**
	 * Whether this test registered the block, so it removes only its own.
	 *
	 * @var bool
	 */
	private bool $registered_block = false;

	/**
	 * Register the block with a callback that includes render.php.
	 *
	 * block.json for the craft card lands with the editor-bundle lane, so
	 * the test registers the block itself unless a block.json already has.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		update_option( 'posts_per_rss', 30 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			register_block_type(
				self::BLOCK,
				[
					'api_version'     => 3,
					'attributes'      => [
						'craftType'   => [ 'type' => 'string' ],
						'timeMinutes' => [ 'type' => 'integer' ],
					],
					'uses_context'    => [ 'postId' ],
					'supports'        => [ 'html' => false ],
					'render_callback' => static function ( array $attributes, string $content, WP_Block $block ): string {
						ob_start();
						require PKIW_PATH . 'src/blocks/craft-card/render.php';
						return (string) ob_get_clean();
					},
				]
			);
			$this->registered_block = true;
		}
	}

	public function tear_down(): void {
		if ( $this->registered_block ) {
			unregister_block_type( self::BLOCK );
			$this->registered_block = false;
		}
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Render the card for some attributes.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function render( array $attrs ): string {
		return trim( render_block(
			[
				'blockName'    => self::BLOCK,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		) );
	}

	/**
	 * Parse rendered HTML.
	 *
	 * @param string $html HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * The card's rows as label => the dd's text.
	 *
	 * @param DOMXPath $xpath Parsed render.
	 * @return array<string, string>
	 */
	private function rows( DOMXPath $xpath ): array {
		$rows = [];
		foreach ( $xpath->query( '//dl[contains(concat(" ", normalize-space(@class), " "), " pkiw-craft-facts ")]/div[contains(@class, "pkiw-craft-facts__row")]' ) as $row ) {
			$dt = $xpath->query( './dt', $row )->item( 0 );
			$dd = $xpath->query( './dd', $row )->item( 0 );
			$this->assertNotNull( $dt );
			$this->assertNotNull( $dd );
			$rows[ trim( $dt->textContent ) ] = trim( $dd->textContent );
		}

		return $rows;
	}

	public function test_a_type_and_a_time_print_two_rows(): void {
		$html  = $this->render(
			[
				'craftType'   => 'Knitting',
				'timeMinutes' => 1080,
			]
		);
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//dl' )->length, 'One description list.' );
		$this->assertSame( [ 'Craft type' => 'Knitting', 'Time spent' => '18 hr' ], $this->rows( $xpath ) );
		$time = $xpath->query( '//dd/time' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $time );
		$this->assertSame( 'PT18H', $time->getAttribute( 'datetime' ) );
		$this->assertSame( 'pkiw-craft-facts__type', $xpath->query( '//dt[normalize-space(.)="Craft type"]/following-sibling::dd[1]' )->item( 0 )->getAttribute( 'class' ) );
	}

	public function test_the_block_wrapper_carries_the_facts_class(): void {
		$html = $this->render( [ 'craftType' => 'Knitting' ] );

		$this->assertMatchesRegularExpression( '/^<dl class="[^"]*\bpkiw-craft-facts\b[^"]*\bwp-block-post-kinds-indieweb-craft-card\b[^"]*">|^<dl class="[^"]*\bwp-block-post-kinds-indieweb-craft-card\b[^"]*\bpkiw-craft-facts\b[^"]*">/', $html );
	}

	public function test_a_time_alone_prints_one_row_in_hours_and_minutes(): void {
		$xpath = $this->xpath( $this->render( [ 'timeMinutes' => 150 ] ) );

		$this->assertSame( [ 'Time spent' => '2 hr 30 min' ], $this->rows( $xpath ) );
		$this->assertSame( 'PT2H30M', $xpath->query( '//time' )->item( 0 )->getAttribute( 'datetime' ) );
	}

	public function test_a_type_alone_prints_one_row(): void {
		$xpath = $this->xpath( $this->render( [ 'craftType' => 'Bookbinding' ] ) );

		$this->assertSame( [ 'Craft type' => 'Bookbinding' ], $this->rows( $xpath ) );
		$this->assertSame( 0, $xpath->query( '//time' )->length );
		$this->assertSame( 0, $xpath->query( '//dt[normalize-space(.)="Time spent"]' )->length, 'No row for a time that is not stored.' );
	}

	public function test_minutes_under_an_hour_print_in_minutes(): void {
		$xpath = $this->xpath( $this->render( [ 'timeMinutes' => 45 ] ) );

		$this->assertSame( [ 'Time spent' => '45 min' ], $this->rows( $xpath ) );
		$this->assertSame( 'PT45M', $xpath->query( '//time' )->item( 0 )->getAttribute( 'datetime' ) );
	}

	/**
	 * Attributes that store nothing to print.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function empty_attributes(): array {
		return [
			'no attributes'      => [ [] ],
			'empty type'         => [ [ 'craftType' => '' ] ],
			'spaces for a type'  => [ [ 'craftType' => "  \t " ] ],
			'zero minutes'       => [ [ 'timeMinutes' => 0 ] ],
			'negative minutes'   => [ [ 'timeMinutes' => -5 ] ],
			'both empty'         => [
				[
					'craftType'   => '',
					'timeMinutes' => 0,
				],
			],
		];
	}

	/**
	 * @dataProvider empty_attributes
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	public function test_a_card_with_nothing_stored_prints_nothing( array $attrs ): void {
		$this->assertSame( '', $this->render( $attrs ) );
	}

	public function test_the_stored_case_is_kept(): void {
		$rows = $this->rows( $this->xpath( $this->render( [ 'craftType' => 'woodworking' ] ) ) );

		$this->assertSame( [ 'Craft type' => 'woodworking' ], $rows, 'Never capitalized, never lowercased.' );
	}

	public function test_a_padded_type_loses_only_its_padding(): void {
		$rows = $this->rows( $this->xpath( $this->render( [ 'craftType' => "  Pottery \t" ] ) ) );

		$this->assertSame( [ 'Craft type' => 'Pottery' ], $rows );
	}

	public function test_a_type_with_markup_is_escaped(): void {
		$html = $this->render( [ 'craftType' => '<b onmouseover="x()">Weaving</b> & more' ] );

		$this->assertStringNotContainsString( '<b', $html );
		$this->assertStringNotContainsString( 'onmouseover="', $html );
		$this->assertStringContainsString( '&lt;b onmouseover=&quot;x()&quot;&gt;Weaving&lt;/b&gt; &amp; more', $html );
	}

	public function test_no_status_completion_date_icon_or_microformat_class_prints(): void {
		$html = $this->render(
			[
				'craftType'   => 'Knitting',
				'timeMinutes' => 1080,
			]
		);

		foreach ( [ 'Status', 'Completed', 'Finished', 'In progress', 'Started' ] as $word ) {
			$this->assertStringNotContainsString( $word, $html, $word );
		}
		$this->assertDoesNotMatchRegularExpression( '/\b(?:h-|p-|u-|dt-|e-)[a-z]/', $html, 'No microformats class: the post\'s h-entry carries them.' );
		$this->assertStringNotContainsString( '<svg', $html );
		$this->assertStringNotContainsString( 'pk-badge', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertSame( 1, $this->xpath( $html )->query( '//time' )->length, 'The one time element is the time spent, not a date.' );
	}

	// Feeds.

	/**
	 * A craft post.
	 *
	 * @param string               $title Post title.
	 * @param array<string, mixed> $card  Card attributes.
	 * @param array<string, mixed> $args  Extra post arguments.
	 */
	private function craft_post( string $title, array $card, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => '2026-08-30 10:00:00',
					'post_content' => '<!-- wp:post-kinds-indieweb/craft-card ' . serialize_block_attributes( $card ) . ' /-->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'craft', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * The RSS2 feed's items for a URL: title => content:encoded.
	 *
	 * @param string $url Feed URL.
	 * @return array<string, string>
	 */
	private function feed_items( string $url ): array {
		$this->go_to( $url );
		$this->assertTrue( is_feed(), $url );

		ob_start();
		try {
			// The template sends headers after PHPUnit's output, as core's feed tests do.
			@require ABSPATH . WPINC . '/feed-rss2.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} finally {
			$out = (string) ob_get_clean();
		}

		$xml = simplexml_load_string( $out );
		$this->assertNotFalse( $xml, 'The feed is XML.' );
		$items = [];
		foreach ( $xml->channel->item as $item ) {
			$content                        = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$items[ (string) $item->title ] = (string) $content->encoded;
		}

		return $items;
	}

	public function test_the_feed_item_holds_each_label_and_value_on_its_own(): void {
		$this->craft_post(
			'Wool Scarf',
			[
				'craftType'   => 'Knitting',
				'timeMinutes' => 1080,
			]
		);

		$items = $this->feed_items( (string) get_term_feed_link( get_term_by( 'slug', 'craft', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );

		$this->assertArrayHasKey( 'Wool Scarf', $items );
		$xpath = $this->xpath( $items['Wool Scarf'] );
		$dts   = $xpath->query( '//dl[contains(@class, "pkiw-craft-facts")]/div/dt' );
		$this->assertSame( 2, $dts->length );
		$this->assertSame( 'Craft type', trim( $dts->item( 0 )->textContent ) );
		$this->assertSame( 'Time spent', trim( $dts->item( 1 )->textContent ) );
		$this->assertSame( 'Knitting', trim( $xpath->query( '//dt[normalize-space(.)="Craft type"]/following-sibling::dd[1]' )->item( 0 )->textContent ), 'The dd after the "Craft type" dt.' );
		$time = $xpath->query( '//dt[normalize-space(.)="Time spent"]/following-sibling::dd[1]/time' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $time, 'The time element sits in the dd after the "Time spent" dt.' );
		$this->assertSame( 'PT18H', $time->getAttribute( 'datetime' ) );
		$this->assertSame( '18 hr', trim( $time->textContent ) );
		// The render separates label and value with whitespace, so a reader that strips tags still gets "Craft type Knitting".
		$this->assertSame( 'Craft type Knitting Time spent 18 hr', trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $items['Wool Scarf'] ) ) ) );
	}

	public function test_a_single_posts_feed_holds_the_same_facts(): void {
		$id = $this->craft_post(
			'Ribbed Hat',
			[
				'craftType'   => 'Knitting',
				'timeMinutes' => 150,
			]
		);

		$items = $this->feed_items( home_url( '/?feed=rss2&p=' . $id ) );

		$this->assertArrayHasKey( 'Ribbed Hat', $items );
		$time = $this->xpath( $items['Ribbed Hat'] )->query( '//time' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $time );
		$this->assertSame( 'PT2H30M', $time->getAttribute( 'datetime' ) );
		$this->assertSame( '2 hr 30 min', trim( $time->textContent ) );
	}

	public function test_a_protected_posts_feed_item_holds_no_facts(): void {
		$this->craft_post(
			'Locked Quilt Square',
			[
				'craftType'   => 'Knitting',
				'timeMinutes' => 120,
			],
			[ 'post_password' => 'seed-249' ]
		);

		$items = $this->feed_items( (string) get_term_feed_link( get_term_by( 'slug', 'craft', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );

		$this->assertArrayHasKey( 'Protected: Locked Quilt Square', $items );
		$this->assertStringNotContainsString( 'Craft type', $items['Protected: Locked Quilt Square'] );
		$this->assertStringNotContainsString( 'Knitting', $items['Protected: Locked Quilt Square'] );
		$this->assertStringNotContainsString( 'Time spent', $items['Protected: Locked Quilt Square'] );
	}

	public function test_the_cards_text_reaches_the_content_atmosphere_reads(): void {
		$id = $this->craft_post(
			'Spool Holder',
			[
				'craftType'   => '3D printing',
				'timeMinutes' => 360,
			]
		);

		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( apply_filters( 'the_content', get_post_field( 'post_content', $id ) ) ) ) );

		$this->assertSame( 'Craft type 3D printing Time spent 6 hr', $text );
	}

	// The build copy.

	public function test_the_build_copy_matches_src_once_the_bundle_has_built(): void {
		$src   = PKIW_PATH . 'src/blocks/craft-card/render.php';
		$build = PKIW_PATH . 'build/blocks/craft-card/render.php';

		$this->assertFileExists( $src );
		$this->assertFileExists( $build, 'The kind lane copies render.php to build/ itself; the bundle build keeps it identical.' );
		$this->assertSame( md5_file( $src ), md5_file( $build ), 'cmp of src and build exits 0.' );
	}
}
