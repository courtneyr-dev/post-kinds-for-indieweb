<?php
/**
 * One read status label map (issue 234).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Block_Bindings;
use PKIW\Meta_Fields;

/**
 * The read archive's shelf headings, the read card's status line and the
 * read_status block binding all print a status through read_status_labels(),
 * so a book reads "Currently Reading" on every surface. Titles and authors are
 * invented.
 *
 * @group integration
 */
final class ReadStatusLabelsTest extends WP_UnitTestCase {

	/**
	 * Read-card render hashes captured on origin/main 2c26e25, before the
	 * card read its labels from read_status_labels(). The render must stay
	 * byte-identical; regenerate these only for a change meant to alter it.
	 *
	 * @var array<string, string>
	 */
	private const SNAPSHOTS = [
		'to-read'   => '73d8d1b3c1a4b31abf165a054a0abf4e9eebd796a93b3eac9e235e393bf6f36d',
		'reading'   => '65f713e35b8fd2cefa532abc91a11afd7a2a9e933a83cdca70dd6c4f39ec7258',
		'finished'  => '6e233bbb13a71f17d1ad4149c36b5982976dcf33e6d0fb79b053f170559a5430',
		'abandoned' => 'a1b0da45a1ada39d1220998d36f9d997ee5ef82b63efb291466959845c0c038d',
		'paused'    => 'eb0f584958fdb36417113908833e09da992adf7896210ed0a5173ce96f2e37d3',
		'omitted'   => '65f713e35b8fd2cefa532abc91a11afd7a2a9e933a83cdca70dd6c4f39ec7258',
	];

	/**
	 * Translates the four labels while a test runs.
	 *
	 * @var callable|null
	 */
	private $translate = null;

	public function tear_down(): void {
		if ( null !== $this->translate ) {
			remove_filter( 'gettext', $this->translate, 10 );
			$this->translate = null;
		}
		parent::tear_down();
	}

	/**
	 * Render a read card with every field filled and the given status.
	 *
	 * @param string|null $status readStatus, or null to leave it out.
	 */
	private function card( ?string $status ): string {
		$attrs = [
			'bookTitle'   => 'The Quiet Orchard',
			'authorName'  => 'Mara Ellison',
			'isbn'        => '9780000000002',
			'publisher'   => 'Harbor Press',
			'publishDate' => '2024',
			'pageCount'   => 320,
			'currentPage' => 80,
			'coverImage'  => 'https://example.org/wp-content/uploads/quiet-orchard.jpg',
			'bookUrl'     => 'https://example.org/books/quiet-orchard',
			'rating'      => 3.5,
			'startedAt'   => '2026-09-14',
			'finishedAt'  => '2026-09-30',
			'review'      => '<p>Slow, and worth it.</p>',
		];
		if ( null !== $status ) {
			$attrs['readStatus'] = $status;
		}

		return render_block(
			[
				'blockName'    => 'post-kinds-indieweb/read-card',
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * The read_status block binding's value for a post holding a status.
	 *
	 * @param string $status Stored status.
	 */
	private function binding( string $status ): ?string {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_status', $status );
		$block          = $this->createMock( WP_Block::class );
		$block->context = [ 'postId' => $post_id ];

		return ( new Block_Bindings() )->get_binding_value( [ 'key' => 'read_status' ], $block, 'content' );
	}

	/**
	 * Translate the four labels to Spanish through gettext.
	 */
	private function translate_labels(): void {
		$spanish         = [
			'To Read'           => 'Por leer',
			'Currently Reading' => 'Leyendo',
			'Finished'          => 'Terminado',
			'Abandoned'         => 'Abandonado',
		];
		$this->translate = static function ( $translation, $text, $domain ) use ( $spanish ) {
			return 'post-kinds-for-indieweb-in-block-themes' === $domain && isset( $spanish[ $text ] ) ? $spanish[ $text ] : $translation;
		};
		add_filter( 'gettext', $this->translate, 10, 3 );
	}

	public function test_the_map_names_the_four_statuses_in_shelf_order(): void {
		$this->assertTrue( function_exists( 'PKIW\\read_status_labels' ), 'read_status_labels() lives in includes/functions-card-labels.php.' );
		$this->assertSame(
			[
				'reading'   => 'Currently Reading',
				'to-read'   => 'To Read',
				'finished'  => 'Finished',
				'abandoned' => 'Abandoned',
			],
			\PKIW\read_status_labels()
		);
	}

	public function test_the_labels_are_translated_when_read(): void {
		$this->translate_labels();

		$this->assertSame(
			[
				'reading'   => 'Leyendo',
				'to-read'   => 'Por leer',
				'finished'  => 'Terminado',
				'abandoned' => 'Abandonado',
			],
			\PKIW\read_status_labels()
		);
	}

	/**
	 * @return array<string, array{string|null}>
	 */
	public function statuses(): array {
		return [
			'to-read'                   => [ 'to-read' ],
			'reading'                   => [ 'reading' ],
			'finished'                  => [ 'finished' ],
			'abandoned'                 => [ 'abandoned' ],
			'a status the map lacks'    => [ 'paused' ],
			'no status (block default)' => [ null ],
		];
	}

	/**
	 * @dataProvider statuses
	 *
	 * @param string|null $status readStatus, or null to leave it out.
	 */
	public function test_the_read_card_render_is_byte_identical_to_the_snapshot( ?string $status ): void {
		$this->assertSame( self::SNAPSHOTS[ $status ?? 'omitted' ], hash( 'sha256', $this->card( $status ) ) );
	}

	public function test_the_card_status_line_prints_the_map_label(): void {
		$this->translate_labels();

		foreach ( \PKIW\read_status_labels() as $status => $label ) {
			$this->assertStringContainsString( '<span>' . $label . '</span>', $this->card( $status ), $status );
		}
		$this->assertStringNotContainsString( '<span>paused</span>', $this->card( 'paused' ), 'A status the map lacks prints no label.' );
	}

	public function test_the_read_status_binding_prints_the_map_label(): void {
		$this->translate_labels();

		foreach ( \PKIW\read_status_labels() as $status => $label ) {
			$this->assertSame( $label, $this->binding( $status ), $status );
		}
	}

	public function test_the_card_and_the_binding_keep_no_label_map_of_their_own(): void {
		$files = [
			'src/blocks/read-card/render.php',
			'build/blocks/read-card/render.php',
			'includes/class-block-bindings.php',
		];
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( PKIW_PATH . $file );
			$this->assertTrue( str_contains( $source, 'read_status_labels()' ), $file . ' calls read_status_labels().' );
			$this->assertFalse( str_contains( $source, "'Currently Reading'" ), $file . ' keeps no label of its own.' );
		}
		$this->assertFileEquals( PKIW_PATH . 'src/blocks/read-card/render.php', PKIW_PATH . 'build/blocks/read-card/render.php', 'The build copy matches the source.' );
	}
}
