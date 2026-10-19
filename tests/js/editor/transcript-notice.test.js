/**
 * Transcript notice for audio posts (issue 243).
 *
 * An audio post that has a player and no filled transcript group gets one
 * dismissible notice and a pre-publish panel. It never locks saving and
 * never writes a block: the author decides whether and what to transcribe.
 * The editor also offers a Transcript variation of the group block.
 */

import { render, screen } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import { registerBlockVariation } from '@wordpress/blocks';
import { registerPlugin } from '@wordpress/plugins';
import TranscriptNotice, {
	NOTICE_ID,
	NOTICE_TEXT,
} from '../../../src/editor/transcript/TranscriptNotice';
import {
	AUDIO_EMBED_PROVIDERS,
	TRANSCRIPT_CLASS,
	hasAudioPlayer,
	hasFilledTranscript,
} from '../../../src/editor/transcript/find-transcript';

jest.mock( '@wordpress/editor', () => ( {
	PluginPrePublishPanel: ( { title, children } ) => (
		<section aria-label={ title }>{ children }</section>
	),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	registerBlockVariation: jest.fn(),
} ) );

jest.mock( '@wordpress/plugins', () => ( {
	registerPlugin: jest.fn(),
} ) );

const block = (
	name,
	attributes = {},
	innerBlocks = [],
	clientId = name
) => ( {
	clientId,
	name,
	attributes,
	innerBlocks,
} );

const paragraph = ( content ) =>
	block( 'core/paragraph', { content }, [], `p-${ content }` );

const transcript = ( innerBlocks, className = TRANSCRIPT_CLASS ) =>
	block( 'core/group', { className }, innerBlocks, 'transcript' );

const audio = () =>
	block( 'core/audio', { src: 'https://example.test/a.mp3' } );

const KINDS = [
	{ id: 4, slug: 'note' },
	{ id: 12, slug: 'audio' },
	{ id: 14, slug: 'listen' },
];

const createNotice = jest.fn();
const removeNotice = jest.fn();
const lockPostSaving = jest.fn();
const savePost = jest.fn();
const insertBlocks = jest.fn();
const replaceBlocks = jest.fn();
const updateBlockAttributes = jest.fn();

let mockKindIds = [ 12 ];
let mockBlocks = [];

beforeEach( () => {
	jest.clearAllMocks();
	mockKindIds = [ 12 ];
	mockBlocks = [];
	useDispatch.mockImplementation( ( store ) => {
		if ( 'core/notices' === store ) {
			return { createNotice, removeNotice };
		}
		if ( 'core/editor' === store ) {
			return { lockPostSaving, savePost };
		}
		if ( 'core/block-editor' === store ) {
			return { insertBlocks, replaceBlocks, updateBlockAttributes };
		}
		return {};
	} );
	useSelect.mockImplementation( ( callback ) =>
		callback( ( store ) => {
			if ( 'core/editor' === store ) {
				return { getEditedPostAttribute: () => mockKindIds };
			}
			if ( 'core' === store ) {
				return { getEntityRecords: () => KINDS };
			}
			if ( 'core/block-editor' === store ) {
				return { getBlocks: () => mockBlocks };
			}
			return {};
		} )
	);
} );

const expectNoWrites = () => {
	expect( insertBlocks ).not.toHaveBeenCalled();
	expect( replaceBlocks ).not.toHaveBeenCalled();
	expect( updateBlockAttributes ).not.toHaveBeenCalled();
	expect( lockPostSaving ).not.toHaveBeenCalled();
	expect( savePost ).not.toHaveBeenCalled();
};

describe( 'hasAudioPlayer', () => {
	it( 'counts a core/audio block, nested ones included', () => {
		expect( hasAudioPlayer( [ audio() ] ) ).toBe( true );
		expect(
			hasAudioPlayer( [ block( 'core/group', {}, [ audio() ] ) ] )
		).toBe( true );
	} );

	it( 'counts an allowlisted audio embed and not another embed', () => {
		expect( AUDIO_EMBED_PROVIDERS ).toEqual( [
			'soundcloud',
			'spotify',
			'mixcloud',
			'pocket-casts',
			'reverbnation',
		] );
		expect(
			hasAudioPlayer( [
				block( 'core/embed', { providerNameSlug: 'spotify' } ),
			] )
		).toBe( true );
		expect(
			hasAudioPlayer( [
				block( 'core/embed', { providerNameSlug: 'youtube' } ),
			] )
		).toBe( false );
		expect( hasAudioPlayer( [ block( 'core/embed', {} ) ] ) ).toBe( false );
	} );

	it( 'counts a paragraph holding a u-audio link and not a plain link', () => {
		expect(
			hasAudioPlayer( [
				paragraph(
					'<a class="u-audio" href="https://example.test/a.mp3">Listen</a>'
				),
			] )
		).toBe( true );
		expect(
			hasAudioPlayer( [
				paragraph(
					'<a class="other u-audio" href="http://example.test/a.mp3">Listen</a>'
				),
			] )
		).toBe( true );
		expect(
			hasAudioPlayer( [
				paragraph( '<a href="https://example.test/a.mp3">Listen</a>' ),
			] )
		).toBe( false );
		expect(
			hasAudioPlayer( [
				paragraph(
					'<a class="u-audio" href="/relative.mp3">Listen</a>'
				),
			] )
		).toBe( false );
	} );

	it( 'is false for a post with no player and for non-lists', () => {
		expect( hasAudioPlayer( [ paragraph( 'Hello' ) ] ) ).toBe( false );
		expect( hasAudioPlayer( undefined ) ).toBe( false );
		expect( hasAudioPlayer( null ) ).toBe( false );
	} );
} );

describe( 'hasFilledTranscript', () => {
	it( 'is true for a transcript group holding text', () => {
		expect(
			hasFilledTranscript( [ transcript( [ paragraph( 'Hi there' ) ] ) ] )
		).toBe( true );
	} );

	it( 'finds a filled transcript nested in another block', () => {
		expect(
			hasFilledTranscript( [
				block( 'core/group', {}, [
					transcript( [ paragraph( 'Hi there' ) ] ),
				] ),
			] )
		).toBe( true );
	} );

	it( 'reads the class as a whole token', () => {
		expect(
			hasFilledTranscript( [
				transcript(
					[ paragraph( 'Hi' ) ],
					`is-style-wide ${ TRANSCRIPT_CLASS }`
				),
			] )
		).toBe( true );
		expect(
			hasFilledTranscript( [
				transcript( [ paragraph( 'Hi' ) ], 'pkiw-transcript-extra' ),
			] )
		).toBe( false );
	} );

	it( 'does not count an empty group, whitespace, tags or a non-breaking space', () => {
		expect(
			hasFilledTranscript( [ transcript( [ paragraph( '' ) ] ) ] )
		).toBe( false );
		expect( hasFilledTranscript( [ transcript( [] ) ] ) ).toBe( false );
		expect(
			hasFilledTranscript( [
				transcript( [ paragraph( '    <br> ' ) ] ),
			] )
		).toBe( false );
		expect(
			hasFilledTranscript( [ transcript( [ paragraph( '&nbsp;' ) ] ) ] )
		).toBe( false );
	} );

	it( 'does not count a group without the transcript class', () => {
		expect(
			hasFilledTranscript( [
				block( 'core/group', { className: 'other' }, [
					paragraph( 'Hi' ),
				] ),
			] )
		).toBe( false );
	} );

	it( 'counts text in a heading or a list inside the group', () => {
		expect(
			hasFilledTranscript( [
				transcript( [ block( 'core/heading', { content: 'Host' } ) ] ),
			] )
		).toBe( true );
		expect(
			hasFilledTranscript( [
				transcript( [
					block( 'core/list-item', { content: 'Hello' } ),
				] ),
			] )
		).toBe( true );
	} );

	it( 'handles a missing list', () => {
		expect( hasFilledTranscript( undefined ) ).toBe( false );
		expect( hasFilledTranscript( null ) ).toBe( false );
	} );
} );

describe( 'Transcript notice', () => {
	it( 'shows for an audio post with a player and no transcript', () => {
		mockBlocks = [ audio() ];

		render( <TranscriptNotice /> );

		expect( NOTICE_TEXT ).toBe(
			"This audio post has no transcript. Add one for readers who can't listen (WCAG 1.2.1)."
		);
		expect( screen.getByRole( 'region' ) ).toBeTruthy();
		expect( screen.getByText( NOTICE_TEXT ) ).toBeTruthy();
		expect( createNotice ).toHaveBeenCalledTimes( 1 );
		expect( createNotice ).toHaveBeenCalledWith(
			'warning',
			NOTICE_TEXT,
			expect.objectContaining( { id: NOTICE_ID, isDismissible: true } )
		);
		expectNoWrites();
	} );

	it( 'shows for an allowlisted embed and for a u-audio paragraph', () => {
		mockBlocks = [
			block( 'core/embed', { providerNameSlug: 'mixcloud' } ),
		];
		const { unmount } = render( <TranscriptNotice /> );
		expect( createNotice ).toHaveBeenCalledTimes( 1 );
		unmount();

		jest.clearAllMocks();
		mockBlocks = [
			paragraph(
				'<a class="u-audio" href="https://example.test/a.mp3">Listen</a>'
			),
		];
		render( <TranscriptNotice /> );
		expect( createNotice ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows when the only transcript group is empty', () => {
		mockBlocks = [ audio(), transcript( [ paragraph( '' ) ] ) ];

		render( <TranscriptNotice /> );

		expect( screen.getByRole( 'region' ) ).toBeTruthy();
		expect( createNotice ).toHaveBeenCalledTimes( 1 );
		expectNoWrites();
	} );

	it( 'stays away once the transcript group has text', () => {
		mockBlocks = [
			audio(),
			transcript( [ paragraph( 'Welcome to the show.' ) ] ),
		];

		render( <TranscriptNotice /> );

		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( createNotice ).not.toHaveBeenCalled();
		expectNoWrites();
	} );

	it( 'stays away from other kinds', () => {
		mockKindIds = [ 4 ];
		mockBlocks = [ audio() ];
		const { unmount } = render( <TranscriptNotice /> );
		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( createNotice ).not.toHaveBeenCalled();
		unmount();

		mockKindIds = [ 14 ];
		render( <TranscriptNotice /> );
		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( createNotice ).not.toHaveBeenCalled();
	} );

	it( 'stays away from a post with no kind', () => {
		mockKindIds = [];
		mockBlocks = [ audio() ];

		render( <TranscriptNotice /> );

		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( createNotice ).not.toHaveBeenCalled();
	} );

	it( 'stays away from an audio post with no player', () => {
		mockBlocks = [ paragraph( 'Notes only.' ) ];

		render( <TranscriptNotice /> );

		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( createNotice ).not.toHaveBeenCalled();
	} );

	it( 'never locks saving, saves the post or writes a block', () => {
		mockBlocks = [ audio() ];

		render( <TranscriptNotice /> );

		// The notice did run, so the absence of writes below is not vacuous.
		expect( createNotice ).toHaveBeenCalledTimes( 1 );
		expectNoWrites();
	} );
} );

describe( 'Transcript registration', () => {
	it( 'registers a Transcript variation of the group block and the notice plugin', () => {
		// Registration runs when the module loads, once; nothing else in this
		// file requires it.
		require( '../../../src/editor/transcript' );

		expect( registerBlockVariation ).toHaveBeenCalledTimes( 1 );
		expect( registerBlockVariation ).toHaveBeenCalledWith(
			'core/group',
			expect.objectContaining( {
				name: 'post-kinds-indieweb/transcript',
				title: 'Transcript',
				attributes: { className: 'pkiw-transcript' },
				isActive: [ 'className' ],
				innerBlocks: [ [ 'core/paragraph' ] ],
			} )
		);
		expect( registerPlugin ).toHaveBeenCalledWith(
			'pkiw-transcript-notice',
			expect.objectContaining( { render: expect.any( Function ) } )
		);
	} );
} );
