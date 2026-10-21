/**
 * Comic card detection for the drawn-comic fields.
 */

import hasComicCard, {
	COMIC_CARD_BLOCK_NAME,
} from '../../../src/editor/kind-selector/has-comic-card';

const block = ( name, innerBlocks = [] ) => ( { name, innerBlocks } );

describe( 'hasComicCard', () => {
	it( "recognizes this plugin's comic card", () => {
		expect( COMIC_CARD_BLOCK_NAME ).toBe(
			'post-kinds-indieweb/comic-card'
		);
		expect( hasComicCard( [ block( COMIC_CARD_BLOCK_NAME ) ] ) ).toBe(
			true
		);
	} );

	it( 'finds a comic card at any depth', () => {
		expect(
			hasComicCard( [
				block( 'core/group', [
					block( 'core/columns', [ block( COMIC_CARD_BLOCK_NAME ) ] ),
				] ),
			] )
		).toBe( true );
	} );

	it( 'is false for other blocks, empty lists, and non-lists', () => {
		expect( hasComicCard( [ block( 'core/gallery' ) ] ) ).toBe( false );
		expect( hasComicCard( [] ) ).toBe( false );
		expect( hasComicCard( undefined ) ).toBe( false );
		expect( hasComicCard( null ) ).toBe( false );
	} );
} );
