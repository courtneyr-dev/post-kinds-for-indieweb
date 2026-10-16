/**
 * Missing-alt notice for photo posts (issue 241).
 *
 * A photo post lists each image with no alt text, galleries included, in the
 * pre-publish panel and in a dismissible notice. It never blocks publishing
 * and never fills alt text in for the author.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import MissingAltNotice, {
	NOTICE_ID,
} from '../../../src/editor/missing-alt-notice/MissingAltNotice';
import {
	MISSING_ALT_KINDS,
	findImagesMissingAlt,
} from '../../../src/editor/missing-alt-notice/find-missing-alt';

jest.mock( '@wordpress/editor', () => ( {
	PluginPrePublishPanel: ( { title, children } ) => (
		<section aria-label={ title }>{ children }</section>
	),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick } ) => (
		<button type="button" onClick={ onClick }>
			{ children }
		</button>
	),
} ) );

const image = (
	clientId,
	alt,
	url = `https://example.test/${ clientId }.jpg`
) => ( {
	clientId,
	name: 'core/image',
	attributes: { url, alt },
	innerBlocks: [],
} );

const gallery = ( clientId, innerBlocks ) => ( {
	clientId,
	name: 'core/gallery',
	attributes: {},
	innerBlocks,
} );

// A craft post keeps its photos in steps: a steps list of step blocks, each
// holding an image and a paragraph (issue 249).
const steps = ( clientId, innerBlocks ) => ( {
	clientId,
	name: 'post-kinds-indieweb/steps',
	attributes: {},
	innerBlocks,
} );

const step = ( clientId, innerBlocks ) => ( {
	clientId,
	name: 'post-kinds-indieweb/step',
	attributes: {},
	innerBlocks: [
		...innerBlocks,
		{
			clientId: `${ clientId }-note`,
			name: 'core/paragraph',
			attributes: { content: 'Cast on 40.' },
			innerBlocks: [],
		},
	],
} );

const group = ( clientId, innerBlocks ) => ( {
	clientId,
	name: 'core/group',
	attributes: {},
	innerBlocks,
} );

const KINDS = [
	{ id: 4, slug: 'note' },
	{ id: 9, slug: 'photo' },
	{ id: 11, slug: 'craft' },
];

const createNotice = jest.fn();
const removeNotice = jest.fn();
const selectBlock = jest.fn();
const updateBlockAttributes = jest.fn();
const replaceBlock = jest.fn();
const replaceBlocks = jest.fn();
const insertBlocks = jest.fn();
const removeBlock = jest.fn();

let mockKindIds = [ 9 ];
let mockBlocks = [];

beforeEach( () => {
	jest.clearAllMocks();
	mockKindIds = [ 9 ];
	mockBlocks = [];
	useDispatch.mockImplementation( ( store ) => {
		if ( 'core/notices' === store ) {
			return { createNotice, removeNotice };
		}
		if ( 'core/block-editor' === store ) {
			return {
				selectBlock,
				updateBlockAttributes,
				replaceBlock,
				replaceBlocks,
				insertBlocks,
				removeBlock,
			};
		}
		return {};
	} );
	useSelect.mockImplementation( ( callback ) =>
		callback( ( store ) => {
			if ( 'core/editor' === store ) {
				return {
					getEditedPostAttribute: () => mockKindIds,
				};
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

const expectNoBlockWrites = () => {
	expect( updateBlockAttributes ).not.toHaveBeenCalled();
	expect( replaceBlock ).not.toHaveBeenCalled();
	expect( replaceBlocks ).not.toHaveBeenCalled();
	expect( insertBlocks ).not.toHaveBeenCalled();
	expect( removeBlock ).not.toHaveBeenCalled();
};

describe( 'findImagesMissingAlt', () => {
	it( 'finds a top-level image with no alt', () => {
		const found = findImagesMissingAlt( [ image( 'a', '' ) ] );

		expect( found.map( ( item ) => item.clientId ) ).toEqual( [ 'a' ] );
	} );

	it( 'finds gallery children and images nested in other blocks', () => {
		const found = findImagesMissingAlt( [
			image( 'top', 'A bench' ),
			gallery( 'g', [ image( 'g1', '' ), image( 'g2', 'A path' ) ] ),
			group( 'grp', [ gallery( 'g-in-grp', [ image( 'deep', '' ) ] ) ] ),
		] );

		expect( found.map( ( item ) => item.clientId ) ).toEqual( [
			'g1',
			'deep',
		] );
	} );

	it( 'counts an alt of only whitespace and an alt that was never set', () => {
		const unset = image( 'unset', undefined );
		const found = findImagesMissingAlt( [
			image( 'blank', '   ' ),
			unset,
		] );

		expect( found.map( ( item ) => item.clientId ) ).toEqual( [
			'blank',
			'unset',
		] );
	} );

	it( 'skips an image block that has no picture yet', () => {
		const found = findImagesMissingAlt( [ image( 'empty', '', '' ) ] );

		expect( found ).toEqual( [] );
	} );

	it( 'skips images that have alt text', () => {
		expect(
			findImagesMissingAlt( [ image( 'a', 'A walnut lamp' ) ] )
		).toEqual( [] );
	} );

	it( 'reads the image list of a gallery saved in the older format', () => {
		const old = {
			clientId: 'old-gallery',
			name: 'core/gallery',
			attributes: {
				images: [
					{ url: 'https://example.test/1.jpg', alt: '' },
					{ url: 'https://example.test/2.jpg', alt: 'A path' },
				],
			},
			innerBlocks: [],
		};

		const found = findImagesMissingAlt( [ old ] );

		expect( found ).toHaveLength( 1 );
		expect( found[ 0 ].clientId ).toBe( 'old-gallery' );
	} );

	it( 'handles a missing list', () => {
		expect( findImagesMissingAlt( undefined ) ).toEqual( [] );
		expect( findImagesMissingAlt( null ) ).toEqual( [] );
	} );

	it( 'names photo and craft as the kinds it checks, in a list other kinds can join', () => {
		expect( MISSING_ALT_KINDS ).toEqual( [ 'photo', 'craft' ] );
	} );

	it( 'finds the image of a step inside a steps list', () => {
		const found = findImagesMissingAlt( [
			steps( 'steps', [
				step( 's1', [ image( 'a', '' ) ] ),
				step( 's2', [ image( 'b', 'A finished sleeve' ) ] ),
				step( 's3', [ image( 'c', '' ) ] ),
			] ),
		] );

		expect( found.map( ( item ) => item.clientId ) ).toEqual( [
			'a',
			'c',
		] );
	} );
} );

describe( 'Missing alt notice', () => {
	it( 'lists the empty-alt images of a photo post, gallery children included', () => {
		mockBlocks = [
			image( 'solo', '' ),
			gallery( 'g', [ image( 'g1', '' ), image( 'g2', 'A path' ) ] ),
		];

		render( <MissingAltNotice /> );

		const panel = screen.getByRole( 'region' );
		expect( panel ).toBeTruthy();
		expect( screen.getAllByRole( 'button' ) ).toHaveLength( 2 );
		expect( createNotice ).toHaveBeenCalledTimes( 1 );
		expect( createNotice ).toHaveBeenCalledWith(
			'warning',
			expect.stringContaining( '2 images' ),
			expect.objectContaining( { id: NOTICE_ID, isDismissible: true } )
		);
		expectNoBlockWrites();
	} );

	it( 'does not block publishing', () => {
		mockBlocks = [ image( 'solo', '' ) ];

		render( <MissingAltNotice /> );

		// Core's lock API is the only way a notice or panel stops a publish.
		const dispatched = useDispatch.mock.calls.map( ( call ) => call[ 0 ] );
		expect( dispatched ).not.toContain( 'core/editor' );
	} );

	it( 'shows nothing for another kind', () => {
		mockKindIds = [ 4 ];
		mockBlocks = [ image( 'solo', '' ) ];

		const { container } = render( <MissingAltNotice /> );

		expect( container.innerHTML ).toBe( '' );
		expect( createNotice ).not.toHaveBeenCalled();
		expectNoBlockWrites();
	} );

	it( 'lists the empty-alt step images of a craft post', () => {
		mockKindIds = [ 11 ];
		mockBlocks = [
			steps( 'steps', [
				step( 's1', [ image( 'a', '' ) ] ),
				step( 's2', [ image( 'b', 'A finished sleeve' ) ] ),
				step( 's3', [ image( 'c', '' ) ] ),
			] ),
		];

		render( <MissingAltNotice /> );

		expect( screen.getByRole( 'region' ) ).toBeTruthy();
		expect( screen.getAllByRole( 'button' ) ).toHaveLength( 2 );
		expect( createNotice ).toHaveBeenCalledWith(
			'warning',
			expect.stringContaining( '2 images' ),
			expect.objectContaining( { id: NOTICE_ID, isDismissible: true } )
		);
		expectNoBlockWrites();
	} );

	it( 'lists nothing for a note, even one holding the same steps', () => {
		mockKindIds = [ 4 ];
		mockBlocks = [
			steps( 'steps', [ step( 's1', [ image( 'a', '' ) ] ) ] ),
			image( 'solo', '' ),
		];

		const { container } = render( <MissingAltNotice /> );

		expect( container.innerHTML ).toBe( '' );
		expect( createNotice ).not.toHaveBeenCalled();
		expectNoBlockWrites();
	} );

	it( 'shows nothing for a post with no kind', () => {
		mockKindIds = [];
		mockBlocks = [ image( 'solo', '' ) ];

		const { container } = render( <MissingAltNotice /> );

		expect( container.innerHTML ).toBe( '' );
		expect( createNotice ).not.toHaveBeenCalled();
	} );

	it( 'shows nothing once every image has alt text', () => {
		mockBlocks = [
			image( 'solo', 'A walnut lamp' ),
			gallery( 'g', [ image( 'g1', 'A path' ) ] ),
		];

		const { container } = render( <MissingAltNotice /> );

		expect( container.innerHTML ).toBe( '' );
		expect( createNotice ).not.toHaveBeenCalled();
		expect( removeNotice ).not.toHaveBeenCalled();
	} );

	it( 'drops the notice when the alt text is typed', () => {
		mockBlocks = [ image( 'solo', '' ) ];
		const { rerender, container } = render( <MissingAltNotice /> );
		expect( createNotice ).toHaveBeenCalledTimes( 1 );

		mockBlocks = [ image( 'solo', 'A walnut lamp' ) ];
		rerender( <MissingAltNotice /> );

		expect( removeNotice ).toHaveBeenCalledWith( NOTICE_ID );
		expect( container.innerHTML ).toBe( '' );
		expectNoBlockWrites();
	} );

	it( 'does not raise a dismissed notice again for the same images', () => {
		mockBlocks = [ image( 'solo', '' ), image( 'two', '' ) ];
		const { rerender } = render( <MissingAltNotice /> );
		expect( createNotice ).toHaveBeenCalledTimes( 1 );

		// One image gets alt text; the other is the one already reported.
		mockBlocks = [ image( 'solo', 'A walnut lamp' ), image( 'two', '' ) ];
		rerender( <MissingAltNotice /> );

		expect( createNotice ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'raises the notice again for an image added later', () => {
		mockBlocks = [ image( 'solo', '' ) ];
		const { rerender } = render( <MissingAltNotice /> );

		mockBlocks = [ image( 'solo', '' ), image( 'later', '' ) ];
		rerender( <MissingAltNotice /> );

		expect( createNotice ).toHaveBeenCalledTimes( 2 );
		expect( createNotice ).toHaveBeenLastCalledWith(
			'warning',
			expect.stringContaining( '2 images' ),
			expect.objectContaining( { id: NOTICE_ID } )
		);
	} );

	it( 'selects the image from the panel without changing it', () => {
		mockBlocks = [ image( 'solo', '' ) ];

		render( <MissingAltNotice /> );
		fireEvent.click( screen.getByRole( 'button' ) );

		expect( selectBlock ).toHaveBeenCalledWith( 'solo' );
		expectNoBlockWrites();
	} );
} );
