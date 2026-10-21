/**
 * Drawn-comic fields in the kind selector.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import KindFields from '../../../src/editor/kind-selector/components/KindFields';

jest.mock( '@wordpress/components', () => ( {
	TextControl: ( { label, value, onChange, help } ) => (
		<div>
			<input
				aria-label={ label }
				value={ value }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
			{ help && <span>{ help }</span> }
		</div>
	),
	Flex: ( { children } ) => <div>{ children }</div>,
} ) );

// The kind store pulls in the whole editor package, which these fields don't use.
jest.mock( '../../../src/editor/stores/post-kinds', () => ( {
	STORE_NAME: 'post-kinds-indieweb',
} ) );

jest.mock( '@wordpress/core-data', () => ( {
	useEntityProp: jest.fn(),
} ) );

jest.mock(
	'../../../src/editor/kind-selector/components/SyndicationControls',
	() => ( {
		__esModule: true,
		default: () => null,
	} )
);

jest.mock( '../../../src/blocks/shared/mood-vocabulary', () => ( {
	MoodSuggestions: () => null,
	moodDisplayLabel: ( value ) => value,
	moodKeyForLabel: ( value ) => value,
	useMoodVocabulary: () => ( {} ),
} ) );

const setMeta = jest.fn();
let mockBlocks = [];
let mockMeta = {};

const block = ( name, innerBlocks = [] ) => ( { name, innerBlocks } );

beforeEach( () => {
	jest.clearAllMocks();
	mockBlocks = [];
	mockMeta = {
		_pkiw_comic_series: 'Field Notes',
		_pkiw_comic_issue: '12',
		unrelated: 'keep me',
	};
	useSelect.mockImplementation( ( callback ) =>
		callback( ( store ) => {
			if ( 'core/block-editor' === store ) {
				return { getBlocks: () => mockBlocks };
			}
			if ( 'core/editor' === store ) {
				return { getCurrentPostType: () => 'post' };
			}
			return {};
		} )
	);
	useEntityProp.mockImplementation( () => [ mockMeta, setMeta ] );
} );

describe( 'ComicsFields', () => {
	it( 'renders Series and Number for a drawn comic', () => {
		render( <KindFields kind="comics" /> );

		expect( screen.getByLabelText( 'Series' ).value ).toBe( 'Field Notes' );
		expect( screen.getByLabelText( 'Number' ).value ).toBe( '12' );
		expect(
			screen.getByText(
				'For a comic you drew. Leave both empty for a standalone strip.'
			)
		).toBeTruthy();
		expect( useEntityProp ).toHaveBeenCalledWith(
			'postType',
			'post',
			'meta'
		);
	} );

	it.each( [
		[ 'top level', [ block( 'post-kinds-indieweb/comic-card' ) ] ],
		[
			'nested in a group',
			[
				block( 'core/group', [
					block( 'post-kinds-indieweb/comic-card' ),
				] ),
			],
		],
	] )( 'renders nothing when a comic card is %s', ( label, blocks ) => {
		mockBlocks = blocks;
		const { container } = render( <KindFields kind="comics" /> );

		expect( label ).toBeTruthy();
		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'merges series and issue changes into the existing meta object', () => {
		render( <KindFields kind="comics" /> );

		fireEvent.change( screen.getByLabelText( 'Series' ), {
			target: { value: 'New Arc' },
		} );
		fireEvent.change( screen.getByLabelText( 'Number' ), {
			target: { value: '13' },
		} );

		expect( setMeta.mock.calls ).toEqual( [
			[
				{
					...mockMeta,
					_pkiw_comic_series: 'New Arc',
				},
			],
			[
				{
					...mockMeta,
					_pkiw_comic_issue: '13',
				},
			],
		] );
		for ( const [ update ] of setMeta.mock.calls ) {
			expect( update.unrelated ).toBe( 'keep me' );
			expect( update ).not.toHaveProperty( '_pkiw_comic_title' );
			expect( update ).not.toHaveProperty( '_pkiw_comic_status' );
		}
	} );

	it( 'uses empty strings when comic meta has not been stored', () => {
		mockMeta = { unrelated: 'keep me' };
		render( <KindFields kind="comics" /> );

		expect( screen.getByLabelText( 'Series' ).value ).toBe( '' );
		expect( screen.getByLabelText( 'Number' ).value ).toBe( '' );
	} );
} );
