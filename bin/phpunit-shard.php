<?php
/**
 * Writes a PHPUnit config that runs one shard of the suite.
 *
 * Usage: php bin/phpunit-shard.php <shard> <total> <output>
 *
 * The script lists the test files of phpunit.xml.dist's test suites in the
 * order an unsharded run takes them (suites in file order, files sorted within
 * each) and cuts the list into <total> consecutive ranges. Each file lands in
 * exactly one shard, so the shards together run the same classes as the
 * unsharded suite, and every shard keeps the unsharded run's relative order
 * (some tests depend on the classes that run just before them). Everything
 * else in phpunit.xml.dist (bootstrap, coverage, constants) carries over, with
 * its paths made absolute because the config lives outside the plugin folder:
 * a stray phpunit.shard.xml in it would fail DistributionManifestTest.
 *
 * @package PKIW
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.ValidVariableName

$pkiw_phpunit_shard = static function ( int $shard, int $total, string $root, string $output ): string {
	$config                     = new DOMDocument();
	$config->preserveWhiteSpace = false;
	$config->formatOutput       = true;
	$config->load( $root . '/phpunit.xml.dist' );

	$xpath = new DOMXPath( $config );
	$files = [];

	foreach ( $xpath->query( '//testsuites/testsuite/directory' ) as $directory ) {
		$suffix   = $directory->getAttribute( 'suffix' );
		$found    = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( realpath( $root . '/' . $directory->nodeValue ), FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( str_ends_with( $file->getFilename(), $suffix ) ) {
				$found[] = $file->getPathname();
			}
		}

		sort( $found, SORT_STRING );
		$files = array_values( array_unique( array_merge( $files, $found ) ) );
	}

	$suites = $xpath->query( '//testsuites' )->item( 0 );
	while ( $suites->firstChild ) {
		$suites->removeChild( $suites->firstChild );
	}

	$suite = $suites->appendChild( $config->createElement( 'testsuite' ) );
	$suite->setAttribute( 'name', "shard-{$shard}-of-{$total}" );

	$range = array_slice( $files, ( $shard - 1 ) * (int) ceil( count( $files ) / $total ), (int) ceil( count( $files ) / $total ) );
	foreach ( $range as $test_file ) {
		$suite->appendChild( $config->createElement( 'file', $test_file ) );
	}
	$count = count( $range );

	$config->documentElement->setAttribute( 'bootstrap', $root . '/' . $config->documentElement->getAttribute( 'bootstrap' ) );
	foreach ( $xpath->query( '//coverage/report/*' ) as $report ) {
		foreach ( [ 'outputFile', 'outputDirectory' ] as $attribute ) {
			if ( $report->hasAttribute( $attribute ) ) {
				$report->setAttribute( $attribute, $root . '/' . $report->getAttribute( $attribute ) );
			}
		}
	}

	$config->save( $output );

	return "Shard {$shard}/{$total}: {$count} of " . count( $files ) . " test files -> {$output}\n";
};

$pkiw_shard = (int) ( $argv[1] ?? 0 );
$pkiw_total = (int) ( $argv[2] ?? 0 );

if ( $pkiw_total < 1 || $pkiw_shard < 1 || $pkiw_shard > $pkiw_total || ! isset( $argv[3] ) ) {
	fwrite( STDERR, "Usage: php bin/phpunit-shard.php <shard> <total> <output>\n" );
	exit( 1 );
}

echo $pkiw_phpunit_shard( $pkiw_shard, $pkiw_total, dirname( __DIR__ ), $argv[3] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
