<?php
/**
 * Source-level grouped archive section bodies.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Archive_Group;
use PKIW\Grouping\Meta_Source;

/**
 * @group integration
 */
final class GroupedArchiveSectionBodyTest extends WP_UnitTestCase {

	private const SOURCE      = 'test_source_body_264';
	private const PLAIN       = 'test_source_plain_264';
	private const ENTRY       = 'pkiw-test/source-body-264';
	private const OWN_ENTRY   = 'pkiw-test/own-body-264';

	/** @var array<string, mixed> */
	private array $received = [];

	public function set_up(): void {
		parent::set_up();
		Grouped_Archive::register_source( new Meta_Source( self::SOURCE, [ '_pkiw_test_source_body' ] ) );
		Grouped_Archive::register_source( new Meta_Source( self::PLAIN, [ '_pkiw_test_plain_body' ] ) );
		Grouped_Archive::register_entry_block( self::ENTRY );
		Grouped_Archive::register_entry_block(
			self::OWN_ENTRY,
			[
				'section_body' => static fn(): string => '<div class="own-body">Own</div>',
			]
		);
	}

	public function tear_down(): void {
		Grouped_Archive::unregister_entry_block( self::ENTRY );
		Grouped_Archive::unregister_entry_block( self::OWN_ENTRY );
		Grouped_Archive::unregister_source( self::SOURCE );
		Grouped_Archive::unregister_source( self::PLAIN );
		$this->received = [];
		parent::tear_down();
	}

	/**
	 * Invoke the engine's section boundary with controlled post-template items.
	 *
	 * @param string             $entry  Entry block name.
	 * @param Meta_Source        $source Group source.
	 * @param array<int, string> $items  Engine list items.
	 */
	private function section( string $entry, Meta_Source $source, array $items ): string {
		$method = new ReflectionMethod( Grouped_Archive::class, 'section' );

		return (string) $method->invoke(
			null,
			$entry,
			$source,
			new Archive_Group( 'rack', 'Rack', 'rack' ),
			$items,
			[
				'level'        => 2,
				'label'        => '',
				'group_labels' => [],
				'date_format'  => '',
			],
			1
		);
	}

	public function test_source_body_replaces_only_the_list_and_receives_the_section_contract(): void {
		$source = Grouped_Archive::source( self::SOURCE );
		$plain  = Grouped_Archive::source( self::PLAIN );
		$this->assertInstanceOf( Meta_Source::class, $source );
		$this->assertInstanceOf( Meta_Source::class, $plain );
		$items          = [ 41 => '<li id="post-41">First</li>', 37 => '<li id="post-37">Second</li>' ];
		$plain_before   = $this->section( self::ENTRY, $plain, $items );
		Grouped_Archive::register_section_body(
			self::SOURCE,
			function ( Archive_Group $group, array $received, array $settings ): string {
				$this->received = compact( 'group', 'received', 'settings' );

				return '<div class="source-body">' . implode( '', $received ) . '</div>';
			}
		);

		$html = $this->section( self::ENTRY, $source, $items );
		$this->assertStringContainsString( '<h2 class="pkiw-group__heading">Rack</h2>', $html );
		$this->assertStringContainsString( '<div class="source-body">', $html );
		$this->assertStringNotContainsString( '<ul class="pkiw-group__items">', $html );
		$this->assertSame( 'rack', $this->received['group']->key() );
		$this->assertSame( $items, $this->received['received'] );
		$this->assertSame( 2, $this->received['settings']['level'] );

		$this->assertStringContainsString( '<div class="own-body">Own</div>', $this->section( self::OWN_ENTRY, $source, $items ) );
		$this->assertSame( $plain_before, $this->section( self::ENTRY, $plain, $items ) );
	}
}
