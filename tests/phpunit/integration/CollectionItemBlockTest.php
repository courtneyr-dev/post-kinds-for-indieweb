<?php
/**
 * Collection item block coverage for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Collection_Items;

/**
 * The dynamic collection item block stores references safely and renders h-cites.
 *
 * @group integration
 */
final class CollectionItemBlockTest extends WP_UnitTestCase {

	/** Image attachment used by render cases. */
	private int $image_id;

	/** Create a real image attachment and reset request state. */
	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'F j, Y' );
		$this->set_permalink_structure( '/%postname%/' );
		$this->image_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
		update_post_meta( $this->image_id, '_wp_attachment_image_alt', 'A field recorder on a desk' );
		wp_set_current_user( 0 );
		Collection_Items::reset_cache();
	}

	/** Restore request globals. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/** Render one collection item through the block parser. */
	private function render( array $attributes ): string {
		$json = [] === $attributes ? '' : serialize_block_attributes( $attributes ) . ' ';

		return do_blocks( '<!-- wp:post-kinds-indieweb/collection-item ' . $json . '/-->' );
	}

	/** The registered block exposes the permanent eight-field schema. */
	public function test_block_registers_the_permanent_attribute_schema(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( Collection_Items::ITEM_BLOCK );
		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame(
			[
				'postId'       => [ 'type' => 'integer', 'default' => 0 ],
				'title'        => [ 'type' => 'string', 'default' => '' ],
				'date'         => [ 'type' => 'string', 'default' => '' ],
				'source'       => [ 'type' => 'string', 'default' => '' ],
				'url'          => [ 'type' => 'string', 'default' => '' ],
				'mediaId'      => [ 'type' => 'integer', 'default' => 0 ],
				'addedAt'      => [ 'type' => 'string', 'default' => '' ],
				'headingLevel' => [ 'type' => 'integer', 'default' => 2 ],
			],
			array_intersect_key( $type->attributes, array_flip( [ 'postId', 'title', 'date', 'source', 'url', 'mediaId', 'addedAt', 'headingLevel' ] ) )
		);
		$this->assertSame(
			[ 'postId', 'title', 'date', 'source', 'url', 'mediaId', 'addedAt', 'headingLevel' ],
			array_values( array_diff( array_keys( $type->attributes ), [ 'lock', 'metadata', 'className', 'style' ] ) )
		);
	}

	/** Heading levels below and above the supported range clamp to h2 and h4. */
	public function test_heading_level_clamps_to_two_through_four(): void {
		$this->assertStringContainsString( '<h2 class="pkiw-collection-item__title p-name">', $this->render( [ 'title' => 'Low', 'headingLevel' => 1 ] ) );
		$this->assertStringContainsString( '<h4 class="pkiw-collection-item__title p-name">', $this->render( [ 'title' => 'High', 'headingLevel' => 5 ] ) );
	}

	/** A valid image can stand alone; an empty external item has no case. */
	public function test_media_only_and_empty_external_items(): void {
		$media = $this->render( [ 'mediaId' => $this->image_id ] );
		$this->assertStringContainsString( 'pkiw-collection-item__media', $media );
		$this->assertStringNotContainsString( 'pkiw-collection-item__label', $media );
		$this->assertStringNotContainsString( '<a ', $media );
		$this->assertSame( '', $this->render( [] ) );
	}

	/** Only HTTP(S) URLs become links, and a linked title-less case prints its host. */
	public function test_link_protocols_host_fallback_and_one_link_limit(): void {
		$this->assertStringNotContainsString( '<a ', $this->render( [ 'title' => 'Unsafe', 'url' => 'javascript:alert(1)' ] ) );
		$this->assertSame( 1, substr_count( $this->render( [ 'title' => 'HTTP', 'url' => 'http://example.test/item' ] ), '<a ' ) );
		$this->assertSame( 1, substr_count( $this->render( [ 'title' => 'HTTPS', 'url' => 'https://example.test/item' ] ), '<a ' ) );

		$host = $this->render( [ 'url' => 'https://archive.example.test/item' ] );
		$this->assertStringContainsString( '>archive.example.test</a>', $host );
		$this->assertStringContainsString( '<p class="pkiw-collection-item__title">', $host );
		$this->assertStringNotContainsString( 'p-name', $host );
		$this->assertSame( 1, substr_count( $host, '<a ' ) );
	}

	/** Missing and non-image attachments never create image markup. */
	public function test_only_image_attachments_render(): void {
		$document = self::factory()->attachment->create(
			[
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
			]
		);
		$this->assertStringNotContainsString( '<img ', $this->render( [ 'title' => 'Document', 'mediaId' => $document ] ) );
		$this->assertSame( '', $this->render( [ 'mediaId' => $document ] ), 'A document alone is not an item.' );
		$this->assertStringNotContainsString( '<img ', $this->render( [ 'title' => 'Missing', 'mediaId' => 999999 ] ) );
	}

	/** Linked images are decorative; unlinked images keep their media-library alt. */
	public function test_image_alt_depends_on_whether_the_case_is_linked(): void {
		$linked   = $this->render( [ 'title' => 'Recorder', 'url' => 'https://example.test/recorder', 'mediaId' => $this->image_id ] );
		$unlinked = $this->render( [ 'title' => 'Recorder', 'mediaId' => $this->image_id ] );

		$this->assertMatchesRegularExpression( '/<img[^>]+alt=""/', $linked );
		$this->assertMatchesRegularExpression( '/<img[^>]+alt="A field recorder on a desk"/', $unlinked );
	}

	/** Internal references read live post fields and ignore stale stored copies. */
	public function test_internal_item_ignores_copied_fields_and_keeps_only_the_reference_in_raw_content(): void {
		$referenced = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Live reference title',
				'post_date'   => '2026-09-21 10:00:00',
			]
		);
		set_post_thumbnail( $referenced, $this->image_id );

		$html = $this->render(
			[
				'postId' => $referenced,
				'title'   => 'Stale stored title',
				'date'    => '1999-01-01',
				'url'     => 'https://stale.example.test/',
				'mediaId' => 999999,
			]
		);
		$this->assertStringContainsString( 'Live reference title', $html );
		foreach ( [ 'Stale stored title', '1999-01-01', 'stale.example.test' ] as $stale ) {
			$this->assertStringNotContainsString( $stale, $html );
		}
		$this->assertStringContainsString( basename( (string) get_post_meta( $this->image_id, '_wp_attached_file', true ), '.jpg' ), $html );

		$raw    = '<!-- wp:post-kinds-indieweb/collection-item ' . serialize_block_attributes( [ 'postId' => $referenced ] ) . ' /-->';
		$parsed = parse_blocks( $raw );
		$this->assertSame( [ 'postId' => $referenced ], $parsed[0]['attrs'] );
	}

	/** A custom className survives alongside the required item classes. */
	public function test_wrapper_keeps_a_sanitized_custom_class(): void {
		$html = $this->render( [ 'title' => 'Recorder', 'className' => 'author-case' ] );
		$this->assertStringContainsString( 'author-case', $html );
		$this->assertStringContainsString( 'pkiw-collection-item h-cite', $html );
	}
}
