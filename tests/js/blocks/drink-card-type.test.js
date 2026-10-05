/**
 * The Drink Card's type in the editor.
 *
 * The block gives the type no default. The card preview and both type selects
 * show the block's own drinkType when it holds one, else the post's stored
 * `_pkiw_drink_type`, else "Select type…". Opening a post writes nothing.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { useDispatch, useSelect } from '@wordpress/data';
import Edit from '../../../src/blocks/drink-card/edit';
import metadata from '../../../src/blocks/drink-card/block.json';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );
jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => children,
	useBlockProps: () => ( {} ),
	RichText: () => null,
	MediaUpload: ( { render: renderMedia } ) =>
		renderMedia( { open: () => {} } ),
	MediaUploadCheck: ( { children } ) => children,
} ) );
jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	TextControl: () => null,
	RangeControl: () => null,
	SelectControl: ( { label, value, options, onChange } ) => (
		<select
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	),
} ) );
jest.mock( '../../../src/blocks/shared/components', () => ( {
	StarRating: () => null,
} ) );

const editPost = jest.fn();
let post;

beforeEach( () => {
	// A saved drink post: it has its kind, and no stored drink type.
	post = { kind: [ 7 ], meta: {} };
	editPost.mockClear();
	useDispatch.mockReturnValue( { editPost } );
	useSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( () => ( {
			getEditedPostAttribute: ( name ) => post[ name ],
		} ) )
	);
} );

/**
 * Open a drink card as the editor does: the attributes saved in the block
 * comment plus the block.json defaults.
 *
 * @param {Object} saved Attributes saved in the block comment.
 * @return {Object} The setAttributes spy and what the card shows.
 */
function open( saved ) {
	const setAttributes = jest.fn();
	const { container } = render(
		<Edit
			attributes={ {
				rating: 0,
				geoLatitude: 0,
				geoLongitude: 0,
				layout: 'horizontal',
				...saved,
			} }
			setAttributes={ setAttributes }
		/>
	);
	return {
		setAttributes,
		cardSelect: container.querySelector( '.post-kinds-card__type-select' ),
		sidebarSelect: screen.getByLabelText( 'Type' ),
		icon: container.querySelector( '.post-kinds-card__media-icon' )
			.textContent,
	};
}

/**
 * The meta edits that touched the stored drink type.
 *
 * @return {Array} Each `_pkiw_drink_type` value handed to editPost.
 */
function typeEdits() {
	return editPost.mock.calls
		.map( ( [ edits ] ) => edits.meta )
		.filter( ( meta ) => meta && '_pkiw_drink_type' in meta )
		.map( ( meta ) => meta._pkiw_drink_type );
}

describe( 'drink card type in the editor', () => {
	it( 'has no default in block.json', () => {
		expect( metadata.attributes.drinkType ).toEqual( { type: 'string' } );
	} );

	it( 'shows the post’s stored type when the card holds none', () => {
		post.meta = { _pkiw_drink_type: 'coffee' };

		const card = open( { name: 'Cortado' } );

		expect( card.cardSelect.value ).toBe( 'coffee' );
		expect( card.sidebarSelect.value ).toBe( 'coffee' );
		expect( card.icon ).toBe( '☕' );
	} );

	it( 'shows “Select type…” when neither the card nor the post holds a type', () => {
		const card = open( { name: 'House pour' } );

		expect( card.cardSelect.value ).toBe( '' );
		expect( card.cardSelect.selectedOptions[ 0 ].textContent ).toContain(
			'Select type…'
		);
		expect( card.sidebarSelect.value ).toBe( '' );
		expect( card.icon ).toBe( '🥤' );
	} );

	it( 'shows the card’s own type over the stored one', () => {
		post.meta = { _pkiw_drink_type: 'tea' };

		const card = open( { name: 'Imperial stout', drinkType: 'beer' } );

		expect( card.cardSelect.value ).toBe( 'beer' );
		expect( card.sidebarSelect.value ).toBe( 'beer' );
		expect( card.icon ).toBe( '🍺' );
	} );

	it( 'keeps a type the author cleared empty', () => {
		post.meta = { _pkiw_drink_type: 'coffee' };

		const card = open( { name: 'House pour', drinkType: '' } );

		expect( card.cardSelect.value ).toBe( '' );
		expect( card.sidebarSelect.value ).toBe( '' );
		expect( typeEdits() ).toEqual( [ '' ] );
	} );

	it( 'opens a post without setting an attribute or writing a type', () => {
		post.meta = { _pkiw_drink_type: 'coffee' };

		const card = open( { name: 'Cortado' } );

		expect( card.setAttributes ).not.toHaveBeenCalled();
		expect( typeEdits() ).toEqual( [] );
	} );

	it( 'mirrors a type the card holds into the post meta', () => {
		open( { name: 'Imperial stout', drinkType: 'beer' } );

		expect( typeEdits() ).toEqual( [ 'beer' ] );
	} );

	it( 'stores the type an author picks from either select', () => {
		const card = open( { name: 'House pour' } );

		fireEvent.change( card.cardSelect, { target: { value: 'tea' } } );
		fireEvent.change( card.sidebarSelect, { target: { value: 'wine' } } );

		expect( card.setAttributes.mock.calls ).toEqual( [
			[ { drinkType: 'tea' } ],
			[ { drinkType: 'wine' } ],
		] );
	} );
} );
