/**
 * Craft card in the editor (issue 249).
 *
 * The type is free text kept as typed. Hours and minutes write timeMinutes
 * (18 h is 1080) and remove it at 0 and 0. The card renders on the server, so
 * the block registers with a save that returns null.
 */

// The real data store, so the block registers; a stub block-editor.
jest.mock( '@wordpress/data', () => jest.requireActual( '@wordpress/data' ) );
jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: ( props = {} ) => props,
	InspectorControls: ( { children } ) => <div>{ children }</div>,
} ) );

jest.mock( '@wordpress/server-side-render', () => ( props ) => (
	<div data-testid="preview">{ props.block }</div>
) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { title, children } ) => (
		<section aria-label={ title }>{ children }</section>
	),
	TextControl: ( { label, value, onChange, type } ) => (
		<input
			aria-label={ label }
			type={ type || 'text' }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	),
	Disabled: ( { children } ) => <div>{ children }</div>,
	Placeholder: ( { label, instructions } ) => (
		<div data-testid="placeholder">
			{ label }: { instructions }
		</div>
	),
} ) );

import { render, screen, fireEvent } from '@testing-library/react';
import { useState } from '@wordpress/element';
import {
	createBlock,
	getBlockContent,
	getCategories,
	getBlockType,
	serialize,
	setCategories,
} from '@wordpress/blocks';
import Edit from '../../../src/blocks/craft-card/edit';
import metadata from '../../../src/blocks/craft-card/block.json';

/**
 * Edit with real attribute state, recording every setAttributes call.
 *
 * @param {Object} props         Props.
 * @param {Object} props.initial Starting attributes.
 * @param {Array}  props.calls   Collects each update.
 * @return {JSX.Element} The block.
 */
function Harness( { initial = {}, calls } ) {
	const [ attributes, setAll ] = useState( initial );
	return (
		<Edit
			attributes={ attributes }
			setAttributes={ ( update ) => {
				calls.push( update );
				setAll( ( current ) => ( { ...current, ...update } ) );
			} }
		/>
	);
}

describe( 'Craft card editor', () => {
	it( 'writes 1080 for 18 hours and 0 minutes', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		fireEvent.change( screen.getByLabelText( 'Hours' ), {
			target: { value: '18' },
		} );

		expect( calls ).toEqual( [ { timeMinutes: 1080 } ] );
		expect( screen.getByLabelText( 'Hours' ).value ).toBe( '18' );
		expect( screen.getByLabelText( 'Minutes' ).value ).toBe( '0' );
	} );

	it( 'adds the minutes to the hours', () => {
		const calls = [];
		render( <Harness initial={ { timeMinutes: 120 } } calls={ calls } /> );

		fireEvent.change( screen.getByLabelText( 'Minutes' ), {
			target: { value: '30' },
		} );

		expect( calls ).toEqual( [ { timeMinutes: 150 } ] );
	} );

	it( 'removes timeMinutes at 0 hours and 0 minutes', () => {
		const calls = [];
		render( <Harness initial={ { timeMinutes: 1080 } } calls={ calls } /> );

		fireEvent.change( screen.getByLabelText( 'Hours' ), {
			target: { value: '0' },
		} );

		expect( calls ).toEqual( [ { timeMinutes: undefined } ] );
	} );

	it( 'stores the type exactly as typed', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		fireEvent.change( screen.getByLabelText( 'Craft type' ), {
			target: { value: 'woodworking ' },
		} );

		expect( calls ).toEqual( [ { craftType: 'woodworking ' } ] );
	} );

	it( 'removes the type when the field is emptied', () => {
		const calls = [];
		render(
			<Harness initial={ { craftType: 'Knitting' } } calls={ calls } />
		);

		fireEvent.change( screen.getByLabelText( 'Craft type' ), {
			target: { value: '' },
		} );

		expect( calls ).toEqual( [ { craftType: undefined } ] );
	} );

	it( 'offers no list of types', () => {
		render( <Harness calls={ [] } /> );

		expect(
			screen.getByLabelText( 'Craft type' ).getAttribute( 'list' )
		).toBeNull();
	} );

	it( 'shows a placeholder while both facts are empty, then the server markup', () => {
		const { rerender } = render(
			<Edit attributes={ {} } setAttributes={ () => {} } />
		);
		expect( screen.getByTestId( 'placeholder' ) ).toBeTruthy();
		expect( screen.queryByTestId( 'preview' ) ).toBeNull();

		rerender(
			<Edit
				attributes={ { craftType: '   ' } }
				setAttributes={ () => {} }
			/>
		);
		expect( screen.getByTestId( 'placeholder' ) ).toBeTruthy();

		rerender(
			<Edit
				attributes={ { craftType: 'Knitting' } }
				setAttributes={ () => {} }
			/>
		);
		expect( screen.queryByTestId( 'placeholder' ) ).toBeNull();
		expect( screen.getByTestId( 'preview' ).textContent ).toBe(
			'post-kinds-indieweb/craft-card'
		);
	} );

	it( 'declares the attributes the render test registers, with no defaults', () => {
		expect( metadata.attributes ).toEqual( {
			craftType: { type: 'string' },
			timeMinutes: { type: 'integer' },
		} );
		expect( metadata.usesContext ).toEqual( [ 'postId' ] );
		expect( metadata.supports ).toEqual( { html: false } );
		expect( metadata.render ).toBe( 'file:./render.php' );
	} );

	it( 'registers with a save that returns null, so the post holds one comment', () => {
		setCategories( [
			{
				slug: 'post-kinds-indieweb',
				title: 'Post Kinds for IndieWeb in Block Themes',
			},
			...getCategories(),
		] );
		require( '../../../src/blocks/craft-card' );

		expect( getBlockType( metadata.name ) ).toBeTruthy();
		const block = createBlock( metadata.name, {
			craftType: 'Knitting',
			timeMinutes: 1080,
		} );
		expect( getBlockContent( block ) ).toBe( '' );
		expect( serialize( block ) ).toBe(
			'<!-- wp:post-kinds-indieweb/craft-card {"craftType":"Knitting","timeMinutes":1080} /-->'
		);
		expect( serialize( createBlock( metadata.name ) ) ).toBe(
			'<!-- wp:post-kinds-indieweb/craft-card /-->'
		);
	} );
} );
