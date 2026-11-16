import { render, screen } from '@testing-library/react';
import { useDispatch, useSelect } from '@wordpress/data';
import { TagFields } from '../../../src/editor/kind-selector/components/KindFields';
import hasTagCard from '../../../src/editor/kind-selector/has-tag-card';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/core-data', () => ( { useEntityProp: jest.fn() } ) );
jest.mock( '@wordpress/icons', () => ( { search: 'search', link: 'link' } ) );
jest.mock( '../../../src/editor/stores/post-kinds', () => ( {
	STORE_NAME: 'post-kinds',
} ) );
jest.mock(
	'../../../src/editor/kind-selector/components/SyndicationControls',
	() => () => null
);
jest.mock( '../../../src/blocks/shared/mood-vocabulary', () => ( {
	MoodSuggestions: () => null,
	moodDisplayLabel: ( value ) => value,
	moodKeyForLabel: () => '',
	useMoodVocabulary: () => [],
} ) );
jest.mock( '@wordpress/components', () => ( {
	Flex: ( { children } ) => <div>{ children }</div>,
	Notice: ( { children } ) => <div role="status">{ children }</div>,
	TextControl: ( { label } ) => (
		<label htmlFor={ `mock-${ label }` }>
			{ label }
			<input id={ `mock-${ label }` } />
		</label>
	),
	TextareaControl: ( { label } ) => (
		<label htmlFor={ `mock-${ label }` }>
			{ label }
			<textarea id={ `mock-${ label }` } />
		</label>
	),
} ) );

// Runs each component's own selector against a fake registry, so re-renders keep working.
const selectWith = ( blocks ) => ( selector ) =>
	selector( () => ( {
		getBlocks: () => blocks,
		getKindMeta: () => '',
	} ) );

describe( 'tag kind fields', () => {
	beforeEach( () => {
		useSelect.mockReset();
		useDispatch.mockReturnValue( { updateKindMeta: jest.fn() } );
	} );

	it( 'finds nested Tag Cards', () => {
		expect(
			hasTagCard( [
				{
					name: 'core/group',
					innerBlocks: [ { name: 'post-kinds-indieweb/tag-card' } ],
				},
			] )
		).toBe( true );
		expect( hasTagCard( [ { name: 'core/paragraph' } ] ) ).toBe( false );
	} );

	it( 'shows the card notice instead of duplicate citation fields', () => {
		useSelect.mockImplementation(
			selectWith( [ { name: 'post-kinds-indieweb/tag-card' } ] )
		);
		render( <TagFields /> );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Edit the target and labels in the Tag Card block.'
		);
		expect( screen.queryByLabelText( 'Author' ) ).not.toBeInTheDocument();
	} );

	it( 'shows URL, title and summary without Author for a cardless tag', () => {
		useSelect.mockImplementation( selectWith( [] ) );
		render( <TagFields /> );
		expect( screen.getByLabelText( 'URL' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Title' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Summary' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Author' ) ).not.toBeInTheDocument();
	} );
} );
