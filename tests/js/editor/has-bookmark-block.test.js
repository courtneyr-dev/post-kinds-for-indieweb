/**
 * Tests for bookmark block detection.
 *
 * Regression: a bookmark post holding this plugin's own Bookmark Card was not
 * recognised as already having somewhere for its link, so the editor inserted
 * an empty core/embed above it on every load.
 */

import hasBookmarkBlock, {
	BOOKMARK_BLOCK_NAMES,
} from '../../../src/editor/kind-selector/has-bookmark-block';

const block = ( name, innerBlocks = [] ) => ( { name, innerBlocks } );

describe( 'hasBookmarkBlock', () => {
	it( "recognises this plugin's own bookmark card", () => {
		expect(
			hasBookmarkBlock( [ block( 'post-kinds-indieweb/bookmark-card' ) ] )
		).toBe( true );
	} );

	it( 'recognises a core embed', () => {
		expect( hasBookmarkBlock( [ block( 'core/embed' ) ] ) ).toBe( true );
	} );

	it( 'recognises the third-party bookmark blocks', () => {
		expect(
			hasBookmarkBlock( [ block( 'mamaduka/bookmark-card' ) ] )
		).toBe( true );
		expect( hasBookmarkBlock( [ block( 'indieblocks/bookmark' ) ] ) ).toBe(
			true
		);
	} );

	it( 'finds a bookmark block nested inside another block', () => {
		const tree = [
			block( 'core/group', [
				block( 'core/columns', [
					block( 'post-kinds-indieweb/bookmark-card' ),
				] ),
			] ),
		];

		expect( hasBookmarkBlock( tree ) ).toBe( true );
	} );

	it( 'returns false when no bookmark block is present', () => {
		expect(
			hasBookmarkBlock( [
				block( 'core/paragraph' ),
				block( 'post-kinds-indieweb/listen-card' ),
			] )
		).toBe( false );
	} );

	it( 'handles an empty list and a missing list', () => {
		expect( hasBookmarkBlock( [] ) ).toBe( false );
		expect( hasBookmarkBlock( undefined ) ).toBe( false );
		expect( hasBookmarkBlock( null ) ).toBe( false );
	} );

	it( 'tolerates blocks with no name', () => {
		expect( hasBookmarkBlock( [ {}, block( 'core/embed' ) ] ) ).toBe(
			true
		);
	} );

	it( 'lists every bookmark block name it knows about', () => {
		expect( BOOKMARK_BLOCK_NAMES ).toContain(
			'post-kinds-indieweb/bookmark-card'
		);
	} );
} );
