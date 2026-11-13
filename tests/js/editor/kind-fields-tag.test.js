import { render, screen } from '@testing-library/react';
import { useDispatch, useSelect } from '@wordpress/data';
import { TagFields } from '../../../src/editor/kind-selector/components/KindFields';
import hasTagCard from '../../../src/editor/kind-selector/has-tag-card';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/components', () => ( {
	Notice: ( { children } ) => <div role="status">{ children }</div>,
	TextControl: ( { label } ) => <label>{ label }<input /></label>,
	TextareaControl: ( { label } ) => <label>{ label }<textarea /></label>,
} ) );

describe( 'tag kind fields', () => {
	beforeEach( () => {
		useSelect.mockReset();
		useDispatch.mockReturnValue( { updateKindMeta: jest.fn() } );
	} );

	it( 'finds nested Tag Cards', () => {
		expect( hasTagCard( [ { name: 'core/group', innerBlocks: [ { name: 'post-kinds-indieweb/tag-card' } ] } ] ) ).toBe( true );
		expect( hasTagCard( [ { name: 'core/paragraph' } ] ) ).toBe( false );
	} );

	it( 'shows the card notice instead of duplicate citation fields', () => {
		useSelect.mockReturnValue( [ { name: 'post-kinds-indieweb/tag-card' } ] );
		render( <TagFields /> );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Edit the target and labels in the Tag Card block.' );
		expect( screen.queryByLabelText( 'Author' ) ).not.toBeInTheDocument();
	} );

	it( 'shows URL, title and summary without Author for a cardless tag', () => {
		useSelect
			.mockReturnValueOnce( [] )
			.mockReturnValueOnce( { citeName: '', citeUrl: '', citeAuthor: '', citeSummary: '' } );
		render( <TagFields /> );
		expect( screen.getByLabelText( 'URL' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Title' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Summary' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Author' ) ).not.toBeInTheDocument();
	} );
} );
