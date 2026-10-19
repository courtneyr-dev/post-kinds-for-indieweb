/**
 * Kind detection for audio posts (issue 243).
 *
 * A core/audio block suggests the Audio kind. A post that also holds this
 * plugin's Listen Card keeps suggesting Listen, in either block order. The
 * detector lives in its own module so it runs without the whole panel.
 */

import {
	BLOCK_KIND_MAP,
	detectKindFromContent,
} from '../../../src/editor/kind-selector/detect-kind';
import hasListenBlock, {
	LISTEN_BLOCK_NAME,
} from '../../../src/editor/kind-selector/has-listen-block';

const block = ( name, innerBlocks = [] ) => ( { name, innerBlocks } );

describe( 'detectKindFromContent', () => {
	it( 'suggests audio for a core/audio block', () => {
		expect( BLOCK_KIND_MAP[ 'core/audio' ] ).toBe( 'audio' );
		expect(
			detectKindFromContent( [ block( 'core/audio' ) ], 'Episode 12' )
		).toBe( 'audio' );
	} );

	it( 'suggests audio for a core/audio block nested in a group', () => {
		expect(
			detectKindFromContent(
				[ block( 'core/group', [ block( 'core/audio' ) ] ) ],
				''
			)
		).toBe( 'audio' );
	} );

	it( 'suggests listen when a listen card is present, whatever the order', () => {
		const listenCard = block( 'post-kinds-indieweb/listen-card' );

		expect(
			detectKindFromContent( [ block( 'core/audio' ), listenCard ], '' )
		).toBe( 'listen' );
		expect(
			detectKindFromContent( [ listenCard, block( 'core/audio' ) ], '' )
		).toBe( 'listen' );
	} );

	it( 'suggests listen for a listen card nested in a group, with audio before it', () => {
		expect(
			detectKindFromContent(
				[
					block( 'core/audio' ),
					block( 'core/group', [
						block( 'post-kinds-indieweb/listen-card' ),
					] ),
				],
				''
			)
		).toBe( 'listen' );
	} );

	it( 'suggests listen for a listen card on its own', () => {
		expect(
			detectKindFromContent(
				[ block( 'post-kinds-indieweb/listen-card' ) ],
				'Track'
			)
		).toBe( 'listen' );
	} );

	it( 'keeps suggesting photo, video, article and note as before', () => {
		expect( detectKindFromContent( [ block( 'core/gallery' ) ], '' ) ).toBe(
			'photo'
		);
		expect( detectKindFromContent( [ block( 'core/image' ) ], '' ) ).toBe(
			'photo'
		);
		expect( detectKindFromContent( [ block( 'core/video' ) ], '' ) ).toBe(
			'video'
		);
		expect(
			detectKindFromContent( [ block( 'core/paragraph' ) ], 'A title' )
		).toBe( 'article' );
		expect(
			detectKindFromContent( [ block( 'core/paragraph' ) ], '' )
		).toBe( 'note' );
	} );

	it( 'keeps the IndieBlocks mappings', () => {
		expect( BLOCK_KIND_MAP[ 'indieblocks/reply' ] ).toBe( 'reply' );
		expect( BLOCK_KIND_MAP[ 'indieblocks/bookmark' ] ).toBe( 'bookmark' );
	} );
} );

describe( 'hasListenBlock', () => {
	it( "recognises this plugin's listen card", () => {
		expect( LISTEN_BLOCK_NAME ).toBe( 'post-kinds-indieweb/listen-card' );
		expect(
			hasListenBlock( [ block( 'post-kinds-indieweb/listen-card' ) ] )
		).toBe( true );
	} );

	it( 'finds a listen card at any depth', () => {
		expect(
			hasListenBlock( [
				block( 'core/group', [
					block( 'core/columns', [
						block( 'post-kinds-indieweb/listen-card' ),
					] ),
				] ),
			] )
		).toBe( true );
	} );

	it( 'is false for other blocks, empty lists and non-lists', () => {
		expect( hasListenBlock( [ block( 'core/audio' ) ] ) ).toBe( false );
		expect( hasListenBlock( [] ) ).toBe( false );
		expect( hasListenBlock( undefined ) ).toBe( false );
		expect( hasListenBlock( null ) ).toBe( false );
	} );
} );
