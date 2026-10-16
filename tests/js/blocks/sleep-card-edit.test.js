/**
 * Sleep card in the editor (issue 247).
 *
 * Hours and minutes write whole minutes (7 h 42 min is 462), clearing both
 * removes the attribute, bedtime and wake time are site-local wall times, and
 * "Show bedtime and wake time publicly" starts off.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useState } from '@wordpress/element';
import Edit from '../../../src/blocks/sleep-card/edit';
import Save from '../../../src/blocks/sleep-card/save';
import metadata from '../../../src/blocks/sleep-card/block.json';

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
	ToggleControl: ( { label, checked, onChange } ) => (
		<input
			type="checkbox"
			aria-label={ label }
			checked={ checked }
			onChange={ ( event ) => onChange( event.target.checked ) }
		/>
	),
	Disabled: ( { children } ) => <div>{ children }</div>,
} ) );

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

describe( 'Sleep card editor', () => {
	it( 'saves no markup, so a private time never reaches post_content', () => {
		expect( Save() ).toBeNull();
	} );

	it( 'writes 462 for 7 hours and 42 minutes', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		fireEvent.change( screen.getByLabelText( 'Hours' ), {
			target: { value: '7' },
		} );
		fireEvent.change( screen.getByLabelText( 'Minutes' ), {
			target: { value: '42' },
		} );

		expect( calls ).toEqual( [
			{ durationMinutes: 420 },
			{ durationMinutes: 462 },
		] );
		expect( screen.getByLabelText( 'Hours' ).value ).toBe( '7' );
		expect( screen.getByLabelText( 'Minutes' ).value ).toBe( '42' );
	} );

	it( 'shows a stored duration as hours and minutes', () => {
		render( <Harness initial={ { durationMinutes: 462 } } calls={ [] } /> );

		expect( screen.getByLabelText( 'Hours' ).value ).toBe( '7' );
		expect( screen.getByLabelText( 'Minutes' ).value ).toBe( '42' );
	} );

	it( 'writes undefined once both fields are cleared', () => {
		const calls = [];
		render(
			<Harness initial={ { durationMinutes: 462 } } calls={ calls } />
		);

		fireEvent.change( screen.getByLabelText( 'Hours' ), {
			target: { value: '' },
		} );
		fireEvent.change( screen.getByLabelText( 'Minutes' ), {
			target: { value: '' },
		} );

		expect( calls ).toEqual( [
			{ durationMinutes: 42 },
			{ durationMinutes: undefined },
		] );
	} );

	it( 'stores at most a day', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		fireEvent.change( screen.getByLabelText( 'Hours' ), {
			target: { value: '30' },
		} );

		expect( calls ).toEqual( [ { durationMinutes: 1440 } ] );
	} );

	it( 'treats text that is not a number as no time', () => {
		const calls = [];
		render(
			<Harness initial={ { durationMinutes: 60 } } calls={ calls } />
		);

		fireEvent.change( screen.getByLabelText( 'Hours' ), {
			target: { value: '-3' },
		} );

		expect( calls ).toEqual( [ { durationMinutes: undefined } ] );
	} );

	it( 'starts with the times switch off, and turning it on sets the attribute', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		const toggle = screen.getByLabelText(
			'Show bedtime and wake time publicly'
		);
		expect( toggle.checked ).toBe( false );

		fireEvent.click( toggle );

		expect( calls ).toEqual( [ { showTimesPublicly: true } ] );
	} );

	it( 'writes bedtime and wake time as datetime-local values and clears them', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		const bedtime = screen.getByLabelText( 'Bedtime' );
		const wake = screen.getByLabelText( 'Wake time' );
		expect( bedtime.getAttribute( 'type' ) ).toBe( 'datetime-local' );
		expect( wake.getAttribute( 'type' ) ).toBe( 'datetime-local' );

		fireEvent.change( bedtime, { target: { value: '2026-08-19T23:10' } } );
		fireEvent.change( wake, { target: { value: '2026-08-20T06:52' } } );
		fireEvent.change( bedtime, { target: { value: '' } } );

		expect( calls ).toEqual( [
			{ bedtime: '2026-08-19T23:10' },
			{ wakeTime: '2026-08-20T06:52' },
			{ bedtime: undefined },
		] );
	} );

	it( 'asks the server for the card, and writes no origin', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		expect( screen.getByTestId( 'preview' ).textContent ).toBe(
			'post-kinds-indieweb/sleep-card'
		);

		fireEvent.change( screen.getByLabelText( 'Hours' ), {
			target: { value: '8' },
		} );
		fireEvent.click(
			screen.getByLabelText( 'Show bedtime and wake time publicly' )
		);
		for ( const call of calls ) {
			expect( Object.keys( call ) ).not.toContain( 'origin' );
		}
		expect( screen.queryByLabelText( /origin/i ) ).toBeNull();
	} );

	it( 'declares the attributes Card_Meta_Sync and the render test use', () => {
		expect( metadata.attributes ).toEqual( {
			durationMinutes: { type: 'integer' },
			bedtime: { type: 'string' },
			wakeTime: { type: 'string' },
			showTimesPublicly: { type: 'boolean', default: false },
			origin: { type: 'string' },
		} );
		expect( metadata.usesContext ).toEqual( [ 'postId' ] );
		expect( metadata.render ).toBe( 'file:./render.php' );
	} );
} );
