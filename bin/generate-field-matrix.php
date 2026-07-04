<?php
/**
 * Generate field matrix fixture from block.json definitions.
 *
 * Returns a callable so tests can invoke it in-memory; running the file
 * directly (php bin/generate-field-matrix.php) writes the fixture.
 *
 * @package PostKindsForIndieWeb
 */

declare(strict_types=1);

$pkiw_generate_field_matrix = static function ( string $blocks_dir ): array {
	$pk_matrix = [];

	foreach ( glob( $blocks_dir . '/*/block.json' ) as $file ) {
		$json = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $json ) || empty( $json['name'] ) ) {
			continue;
		}

		$pk_attrs = [];
		foreach ( ( $json['attributes'] ?? [] ) as $attr => $def ) {
			$type = is_array( $def['type'] ?? null ) ? ( $def['type'][0] ?? 'string' ) : ( $def['type'] ?? 'string' );

			if ( 'layout' === $attr ) {
				$sample = $def['default'] ?? 'horizontal';
			} elseif ( 'boolean' === $type ) {
				$sample = true;
			} elseif ( 'number' === $type || 'integer' === $type ) {
				$sample = 4;
			} elseif ( preg_match( '/(url|photo|cover|image)$/i', $attr ) ) {
				$sample = 'https://example.com/sample-' . strtolower( $attr );
			} elseif ( preg_match( '/(At|Date)$/', $attr ) || 'publishDate' === $attr ) {
				$sample = '2026-07-04';
			} else {
				$sample = 'Sample ' . $attr . ' value';
			}

			$pk_attrs[ $attr ] = [
				'type'   => $type,
				'sample' => $sample,
			];
		}

		$pk_matrix[ $json['name'] ] = [
			'render'     => isset( $json['render'] ) ? 'dynamic' : 'static',
			'attributes' => $pk_attrs,
		];
	}

	ksort( $pk_matrix );
	return $pk_matrix;
};

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- CLI script.
if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$pk_root   = dirname( __DIR__ );
	$pk_matrix = $pkiw_generate_field_matrix( $pk_root . '/src/blocks' );
	file_put_contents(
		$pk_root . '/tests/phpunit/fixtures/field-matrix.json',
		json_encode( $pk_matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
	);
	echo 'Wrote matrix for ' . count( $pk_matrix ) . " blocks\n";
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

return $pkiw_generate_field_matrix;
