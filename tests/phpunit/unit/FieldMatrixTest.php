<?php
// tests/phpunit/unit/FieldMatrixTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FieldMatrixTest extends TestCase {

	public function test_fixture_matches_block_json_inventory(): void {
		$generate = require dirname( __DIR__, 3 ) . '/bin/generate-field-matrix.php';
		$expected = $generate( dirname( __DIR__, 3 ) . '/src/blocks' );

		$fixture_path = dirname( __DIR__ ) . '/fixtures/field-matrix.json';
		$this->assertFileExists( $fixture_path, 'Run: php bin/generate-field-matrix.php' );

		$actual = json_decode( (string) file_get_contents( $fixture_path ), true );

		$this->assertSame(
			$expected,
			$actual,
			'field-matrix.json is stale — a block.json changed. Regenerate: php bin/generate-field-matrix.php'
		);
	}

	public function test_matrix_covers_every_card_block(): void {
		$fixture = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/field-matrix.json' ),
			true
		);
		foreach ( glob( dirname( __DIR__, 3 ) . '/src/blocks/*/block.json' ) as $file ) {
			$name = json_decode( (string) file_get_contents( $file ), true )['name'];
			$this->assertArrayHasKey( $name, $fixture );
		}
	}
}
