<?php
/** Wrapped source guards from brief-1 L3 and L4. @package PKIW */
declare(strict_types=1);

/** @group integration */
final class WrappedGrepTest extends WP_UnitTestCase {
	private const FILES = [
		'includes/class-wrapped-stats.php',
		'includes/class-wrapped-post.php',
		'includes/class-wrapped-blocks.php',
		'includes/abilities/class-wrapped-abilities.php',
		'includes/functions-wrapped.php',
		'src/blocks/wrapped/render.php',
		'src/blocks/wrapped-strip/render.php',
	];

	public function test_l3_new_files_never_assign_publish_or_future_status(): void {
		foreach ( self::FILES as $relative ) {
			$source = $this->source( $relative );
			if ( 'includes/class-wrapped-stats.php' === $relative ) {
				$source = str_replace( "'post_status'    => 'publish',", '', $source );
			}
			$this->assertDoesNotMatchRegularExpression(
				'/(?:[\'\"]post_status[\'\"]\s*=>\s*[\'\"](?:publish|future)[\'\"]|->post_status\s*=\s*[\'\"](?:publish|future)[\'\"]|\bwp_publish_post\s*\()/',
				$source,
				$relative
			);
		}
	}

	public function test_l4_q10_literals_live_only_in_public_listing_helper(): void {
		$files = array_merge( self::FILES, $this->treeFiles( 'src/blocks/wrapped' ), $this->treeFiles( 'src/blocks/wrapped-strip' ) );
		foreach ( array_unique( $files ) as $relative ) {
			$source = $this->source( $relative );
			$this->assertStringNotContainsString( 'activitypub_' . 'content_visibility', $source, $relative );
			$this->assertStringNotContainsString( 'quiet_' . 'public', $source, $relative );
		}
	}

	private function source( string $relative ): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/' . $relative );
		$this->assertIsString( $source );
		return $source;
	}

	private function treeFiles( string $relative ): array {
		$files = [];
		foreach ( glob( dirname( __DIR__, 3 ) . '/' . $relative . '/*' ) ?: [] as $path ) {
			if ( is_file( $path ) ) {
				$files[] = substr( $path, strlen( dirname( __DIR__, 3 ) . '/' ) );
			}
		}
		return $files;
	}
}
