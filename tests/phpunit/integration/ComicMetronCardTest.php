<?php
/**
 * Metron facts, cover handling, and comic-card meta coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Featured_Artwork;
use PKIW\Integrations\Yoast_SEO;
use PKIW\Kind_Artwork;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ComicMetronCardTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/comic-card';

	private const COVER = 'https://static.metron.cloud/media/issue/2026/11/15/sentinel-07.jpg';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', '__return_empty_array' );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
	}

	public function tear_down(): void {
		wp_reset_postdata();
		parent::tear_down();
	}

	/** @return array<string, mixed> */
	private function attributes(): array {
		return [
			'title'         => 'Sentinel Comics',
			'series'        => 'Sentinel Comics',
			'issueNumber'   => '7',
			'publisher'     => 'Example Press',
			'coverImage'    => self::COVER,
			'coverImageAlt' => 'Sentinel Comics issue 7 cover.',
			'sourceUrl'     => 'https://metron.cloud/issue/sentinel-comics-7/',
			'metronId'      => '101',
			'isbn'          => '9798368809267',
		];
	}

	/** @param array<string, mixed> $attributes */
	private function block_comment( array $attributes ): string {
		return '<!-- wp:' . self::BLOCK . ' ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/** @param array<string, mixed> $attributes */
	private function comic_post( array $attributes, bool $disable_featured = true ): int {
		if ( $disable_featured ) {
			add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		}
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->block_comment( $attributes ),
			]
		);
		if ( $disable_featured ) {
			remove_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		}
		wp_set_object_terms( $post_id, 'comics', Taxonomy::TAXONOMY );

		return $post_id;
	}

	/** @param array<string, mixed> $attributes */
	private function render( array $attributes, ?int $post_id = null ): string {
		$post_id = $post_id ?? $this->comic_post( $attributes );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Dynamic block context.
		setup_postdata( $GLOBALS['post'] );

		return render_block(
			[
				'blockName'    => self::BLOCK,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	public function test_attributes_have_no_defaults_and_meta_is_registered(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );
		$this->assertInstanceOf( WP_Block_Type::class, $type );

		foreach ( [ 'metronId', 'isbn' ] as $attribute ) {
			$this->assertArrayHasKey( $attribute, $type->attributes );
			$this->assertArrayNotHasKey( 'default', $type->attributes[ $attribute ] );
		}

		$registered = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayHasKey( Meta_Fields::PREFIX . 'comic_metron_id', $registered );
		$this->assertArrayHasKey( Meta_Fields::PREFIX . 'comic_isbn', $registered );
		$this->assertNotEmpty( $registered[ Meta_Fields::PREFIX . 'comic_metron_id' ]['show_in_rest'] );
		$this->assertNotEmpty( $registered[ Meta_Fields::PREFIX . 'comic_isbn' ]['show_in_rest'] );
	}

	public function test_facts_and_cover_credits_have_the_exact_links_and_no_license_relation(): void {
		update_option( 'pkiw_settings', [ 'image_handling' => 'hotlink' ] );
		$html = $this->render( $this->attributes() );

		$this->assertStringContainsString( 'class="pk-sub pk-credit pk-credit--facts"', $html );
		$this->assertStringContainsString( 'href="https://creativecommons.org/licenses/by-sa/4.0/">CC BY-SA 4.0</a>, <a href="https://metron.cloud/issue/sentinel-comics-7/">Metron</a>', $html );
		$this->assertStringContainsString( '<p class="pk-credit pk-credit--cover">Cover via Metron</p>', $html );
		$this->assertStringNotContainsString( 'rel="license"', $html );

		$parsed = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' );
		$this->assertSame( [], $parsed['rels']['license'] ?? [] );
	}

	/**
	 * @dataProvider invalid_source_provider
	 */
	public function test_non_exact_metron_source_falls_back_to_the_issue_id( string $source ): void {
		$attributes              = $this->attributes();
		$attributes['sourceUrl'] = $source;
		$html                    = $this->render( $attributes );

		$this->assertStringContainsString( 'href="https://metron.cloud/issue/101/">Metron</a>', $html );
		$this->assertStringNotContainsString( 'href="https://metron.cloud/" onmouseover=', $html );
		$this->assertStringNotContainsString( 'href="javascript:', $html );
	}

	/** @return array<string, array{0:string}> */
	public static function invalid_source_provider(): array {
		return [
			'suffix attack' => [ 'https://metron.cloud.example/issue/101/' ],
			'subdomain'     => [ 'https://www.metron.cloud/issue/101/' ],
			'plain HTTP'    => [ 'http://metron.cloud/issue/101/' ],
			'script scheme' => [ 'javascript:alert(1)' ],
		];
	}

	public function test_source_url_with_a_quote_cannot_add_an_html_attribute(): void {
		$attributes              = $this->attributes();
		$attributes['sourceUrl'] = 'https://metron.cloud/" onmouseover="alert(1)';
		$html                    = $this->render( $attributes );

		$this->assertStringContainsString( 'class="pk-sub pk-credit pk-credit--facts"', $html );
		$this->assertStringNotContainsString( 'href="https://metron.cloud/" onmouseover=', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_non_string_source_url_falls_back_without_an_array_conversion_warning(): void {
		$attributes              = $this->attributes();
		$attributes['sourceUrl'] = [ 'https://metron.cloud/issue/101/' ];
		// The post holds valid attributes: Card_Meta_Sync's own cast of an array value on save predates this lane.
		$post_id                 = $this->comic_post( $this->attributes() );
		$warnings                = [];
		set_error_handler(
			static function ( int $severity, string $message ) use ( &$warnings ): bool {
				if ( E_WARNING === $severity && str_contains( $message, 'Array to string conversion' ) ) {
					$warnings[] = $message;
					return true;
				}
				return false;
			}
		);

		try {
			$html = $this->render( $attributes, $post_id );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( [], $warnings );
		$this->assertStringContainsString( 'href="https://metron.cloud/issue/101/">Metron</a>', $html );
	}

	public function test_comic_without_metron_id_gets_no_featured_image_retry_or_yoast_change(): void {
		$attributes = $this->attributes();
		unset( $attributes['metronId'] );
		$post_id = $this->comic_post( $attributes, false );

		$this->assertSame( 0, get_post_thumbnail_id( $post_id ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
		$this->assertNull( Kind_Artwork::get_representative_image_url( $post_id ) );

		$permalink = get_permalink( $post_id );
		$this->go_to( $permalink );
		$graph = [
			[ '@type' => 'Article', '@id' => $permalink . '#article' ],
			[ '@type' => 'WebPage', '@id' => $permalink ],
		];
		$this->assertSame(
			$graph,
			( new Yoast_SEO() )->filter_schema_graph(
				$graph,
				(object) [ 'canonical' => $permalink, 'main_image_url' => null ]
			)
		);
	}

	public function test_cover_credit_requires_a_printed_cover_and_metron_id_requires_digits(): void {
		$attributes = $this->attributes();
		unset( $attributes['coverImage'] );
		$html = $this->render( $attributes );
		$this->assertStringContainsString( 'CC BY-SA 4.0', $html );
		$this->assertStringNotContainsString( 'Cover via Metron', $html );

		$attributes             = $this->attributes();
		$attributes['metronId'] = '101x';
		$html                   = $this->render( $attributes );
		$this->assertStringNotContainsString( 'CC BY-SA 4.0', $html );
		$this->assertStringNotContainsString( 'Cover via Metron', $html );
	}

	public function test_metron_cover_display_table_for_remote_and_local_copies(): void {
		$attributes    = $this->attributes();
		$post_id       = $this->comic_post( $attributes );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$local_url     = (string) wp_get_attachment_url( $attachment_id );

		foreach ( [ 'sideload', 'hotlink', 'none' ] as $handling ) {
			update_option( 'pkiw_settings', [ 'image_handling' => $handling ] );
			$html = $this->render( $attributes, $post_id );
			if ( 'hotlink' === $handling ) {
				$this->assertStringContainsString( self::COVER, $html );
				$this->assertStringContainsString( 'Cover via Metron', $html );
			} else {
				$this->assertStringNotContainsString( 'class="pk-media"', $html );
				$this->assertStringNotContainsString( 'static.metron.cloud', $html );
				$this->assertStringNotContainsString( 'Cover via Metron', $html );
			}

			update_post_meta( $post_id, Featured_Artwork::SOURCE_META, self::COVER );
			update_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META, $attachment_id );
			$html = $this->render( $attributes, $post_id );
			if ( 'none' === $handling ) {
				$this->assertStringNotContainsString( 'class="pk-media"', $html );
				$this->assertStringNotContainsString( $local_url, $html );
			} else {
				$this->assertStringContainsString( $local_url, $html );
				$this->assertStringContainsString( 'Cover via Metron', $html );
			}
			delete_post_meta( $post_id, Featured_Artwork::SOURCE_META );
			delete_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META );
		}
	}

	public function test_card_meta_sync_and_isbn_sanitizers(): void {
		$post_id = $this->comic_post( $this->attributes() );
		$this->assertSame( '101', get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_metron_id', true ) );
		$this->assertSame( '9798368809267', get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_isbn', true ) );

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_isbn', '979-8-3688-0926-7' );
		$this->assertSame( '9798368809267', get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_isbn', true ) );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_isbn', '12345678901' );
		$this->assertSame( '', get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_isbn', true ) );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_metron_id', '101x' );
		$this->assertSame( '', get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_metron_id', true ) );

		$fields = new Meta_Fields();
		foreach ( [ '979-8-3688-0926-7', '123456789X', '12345678901', [] ] as $value ) {
			$this->assertSame( Meta_Fields::normalize_isbn( $value ), $fields->sanitize_isbn( $value ) );
		}
	}

	public function test_credit_translation_has_domain_and_translator_comment(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/blocks/comic-card/render.php' );
		$this->assertStringContainsString( 'translators: 1: link to the CC BY-SA 4.0 license, 2: link to the Metron issue page.', $source );
		$this->assertStringContainsString( "__( '%1\$s, %2\$s', 'post-kinds-for-indieweb-in-block-themes' )", $source );
	}
}
