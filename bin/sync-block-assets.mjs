/**
 * Copy per-block runtime assets from src/blocks into build/blocks.
 *
 * The plugin registers every block from build/blocks/<block>/block.json so
 * the shipped zip works without src/. wp-scripts compiles the JS but does
 * not copy these files, so this runs after each build (see package.json).
 */
import { cpSync, existsSync, mkdirSync, readdirSync } from 'node:fs';
import path from 'node:path';

const srcRoot = path.resolve( 'src/blocks' );
const buildRoot = path.resolve( 'build/blocks' );
const files = [ 'block.json', 'render.php', 'style.css', 'editor.css' ];

let copied = 0;
for ( const entry of readdirSync( srcRoot, { withFileTypes: true } ) ) {
	if ( ! entry.isDirectory() ) {
		continue;
	}
	const outDir = path.join( buildRoot, entry.name );
	mkdirSync( outDir, { recursive: true } );
	for ( const file of files ) {
		const from = path.join( srcRoot, entry.name, file );
		if ( existsSync( from ) ) {
			cpSync( from, path.join( outDir, file ) );
			copied++;
		}
	}
}
console.log( `sync-block-assets: ${ copied } files copied into build/blocks` );
