<?php
/** Follow plugin-template coverage. @package PKIW */
declare(strict_types=1);

/** @group integration */
final class FollowArchiveTemplateTest extends WP_UnitTestCase {
	public function test_template_query_keeps_namespace_and_letters_before_entries(): void {
		$path   = PKIW_PATH . 'templates/taxonomy-kind-follow.html';
		$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository fixture.
		$this->assertStringContainsString( '"namespace":"pkiw-kind-follow"', $source );
		$this->assertLessThan( strpos( $source, 'follow-entry' ), strpos( $source, 'follow-letters' ), 'Moving letters after entries would break the saved Query variation.' );
		$this->assertNotEmpty( parse_blocks( $source ) );
	}
}
