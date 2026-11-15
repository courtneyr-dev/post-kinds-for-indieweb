<?php
/**
 * Tag archive template coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Plugin;
use PKIW\Tag_Archive;

/** @group integration */
final class TagArchiveTemplateTest extends WP_UnitTestCase {

	public function test_template_uses_the_frozen_query_namespace_and_entry_block(): void {
		$template = file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-tag.html' );
		$this->assertStringContainsString( '"namespace":"pkiw-kind-tag"', $template );
		$this->assertStringContainsString( 'post-kinds-indieweb/tag-entry', $template );
		$this->assertStringContainsString( '"linesPerPage":12', $template );
		$this->assertSame( 12, Tag_Archive::preview_per_page( 10, 'tag' ) );
		$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( Plugin::class, 'get_plugin_template_definitions' );
		$method->setAccessible( true );
		$this->assertArrayHasKey( 'taxonomy-kind-tag', $method->invoke( $plugin ) );
	}
}
