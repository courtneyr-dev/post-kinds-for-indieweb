/**
 * Exercise card in the editor (issue 245).
 *
 * The time is typed as h:mm:ss and stored as an ISO 8601 duration, splits keep
 * their order as rows come and go, "Show workout details publicly" starts off,
 * the activity list and personal best categories come from window.pkiwExercise
 * with a built-in fallback, and no control writes the card's origin.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import { useState } from '@wordpress/element';
import Edit, {
	FALLBACK_PB_CATEGORIES,
	clockToIso,
	isoToClock,
} from '../../../src/blocks/exercise-card/edit';

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
	TextControl: ( { label, value, onChange, type, list, help } ) => (
		<div>
			<input
				aria-label={ label }
				type={ type || 'text' }
				value={ value }
				list={ list }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
			{ help && <span>{ help }</span> }
		</div>
	),
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
	ToggleControl: ( { label, checked, onChange, help } ) => (
		<div>
			<input
				type="checkbox"
				aria-label={ label }
				checked={ checked }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			{ help && <span>{ help }</span> }
		</div>
	),
	Button: ( { children, onClick } ) => (
		<button type="button" onClick={ onClick }>
			{ children }
		</button>
	),
	Disabled: ( { children } ) => <div>{ children }</div>,
	Placeholder: ( { label } ) => <div>{ label }</div>,
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

afterEach( () => {
	delete window.pkiwExercise;
} );

describe( 'duration conversion', () => {
	it( 'turns clock text into an ISO 8601 duration', () => {
		expect( clockToIso( '29:12' ) ).toBe( 'PT29M12S' );
		expect( clockToIso( '1:02:03' ) ).toBe( 'PT1H2M3S' );
		expect( clockToIso( '1:00:00' ) ).toBe( 'PT1H' );
		expect( clockToIso( '0:45' ) ).toBe( 'PT45S' );
		expect( clockToIso( '90:00' ) ).toBe( 'PT1H30M' );
	} );

	it( 'turns an ISO 8601 duration back into clock text', () => {
		expect( isoToClock( 'PT29M12S' ) ).toBe( '29:12' );
		expect( isoToClock( 'PT1H2M3S' ) ).toBe( '1:02:03' );
		expect( isoToClock( 'PT1H' ) ).toBe( '1:00:00' );
		expect( isoToClock( 'PT45S' ) ).toBe( '0:45' );
		expect( isoToClock( 'P1DT1H' ) ).toBe( '25:00:00' );
	} );

	it( 'round-trips', () => {
		for ( const text of [ '29:12', '1:02:03', '0:05', '12:00:30' ] ) {
			expect( isoToClock( clockToIso( text ) ) ).toBe( text );
		}
	} );

	it( 'reads empty text as empty and rejects what is not a time', () => {
		expect( clockToIso( '' ) ).toBe( '' );
		expect( clockToIso( '   ' ) ).toBe( '' );
		for ( const text of [
			'abc',
			'29',
			'12:75',
			'1:75:00',
			'1:2:3:4',
			'-5:00',
			'1.5:00',
			'0:00',
		] ) {
			expect( clockToIso( text ) ).toBeNull();
		}
		expect( isoToClock( 'not a duration' ) ).toBe( '' );
		expect( isoToClock( undefined ) ).toBe( '' );
	} );
} );

describe( 'Exercise card editor', () => {
	it( 'starts with "Show workout details publicly" off, and turning it on sets the attribute', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		const toggle = screen.getByLabelText( 'Show workout details publicly' );
		expect( toggle.checked ).toBe( false );

		fireEvent.click( toggle );

		expect( calls ).toEqual( [ { showDetailsPublicly: true } ] );
		expect(
			screen.getByText(
				'Distance, duration, splits and personal best print only when this is on.'
			)
		).toBeTruthy();
	} );

	it( 'stores a typed time as ISO 8601 and clears it when emptied', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		const time = screen.getByLabelText( 'Time' );
		fireEvent.change( time, { target: { value: '29:12' } } );
		fireEvent.change( time, { target: { value: '1:02:03' } } );
		fireEvent.change( time, { target: { value: '' } } );

		expect( calls ).toEqual( [
			{ duration: 'PT29M12S' },
			{ duration: 'PT1H2M3S' },
			{ duration: undefined },
		] );
	} );

	it( 'shows a stored duration as clock text and keeps half-typed text', () => {
		const calls = [];
		render(
			<Harness initial={ { duration: 'PT29M12S' } } calls={ calls } />
		);

		const time = screen.getByLabelText( 'Time' );
		expect( time.value ).toBe( '29:12' );

		fireEvent.change( time, { target: { value: '29:1x' } } );

		expect( time.value ).toBe( '29:1x' );
		expect( calls ).toEqual( [] );
		expect(
			screen.getByText( 'Use h:mm:ss or m:ss, such as 29:12.' )
		).toBeTruthy();
	} );

	it( 'does not reformat the text while it is being typed', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		const time = screen.getByLabelText( 'Time' );
		fireEvent.change( time, { target: { value: '29:1' } } );

		// 29:1 is a time (PT29M1S); the field keeps what was typed.
		expect( calls ).toEqual( [ { duration: 'PT29M1S' } ] );
		expect( time.value ).toBe( '29:1' );
	} );

	it( 'keeps split order as rows are added and removed, renumbering from 1', () => {
		const calls = [];
		render(
			<Harness
				initial={ {
					splits: [
						{ i: 1, t: 'PT5M' },
						{ i: 2, t: 'PT5M30S' },
						{ i: 3, t: 'PT6M' },
					],
				} }
				calls={ calls }
			/>
		);

		fireEvent.click( screen.getByText( 'Remove split 2' ) );
		expect( calls.pop() ).toEqual( {
			splits: [
				{ i: 1, t: 'PT5M' },
				{ i: 2, t: 'PT6M' },
			],
		} );

		fireEvent.click( screen.getByText( 'Add split' ) );
		expect( calls.pop() ).toEqual( {
			splits: [
				{ i: 1, t: 'PT5M' },
				{ i: 2, t: 'PT6M' },
				{ i: 3, t: '' },
			],
		} );

		fireEvent.change( screen.getByLabelText( 'Split 3' ), {
			target: { value: '5:45' },
		} );
		expect( calls.pop() ).toEqual( {
			splits: [
				{ i: 1, t: 'PT5M' },
				{ i: 2, t: 'PT6M' },
				{ i: 3, t: 'PT5M45S' },
			],
		} );

		expect( screen.getByLabelText( 'Split 1' ).value ).toBe( '5:00' );
		expect( screen.getByLabelText( 'Split 2' ).value ).toBe( '6:00' );
	} );

	it( 'removes the attribute when the last split goes', () => {
		const calls = [];
		render(
			<Harness
				initial={ { splits: [ { i: 1, t: 'PT5M' } ] } }
				calls={ calls }
			/>
		);

		fireEvent.click( screen.getByText( 'Remove split 1' ) );

		expect( calls ).toEqual( [ { splits: undefined } ] );
	} );

	it( 'reads activities and personal best categories from window.pkiwExercise', () => {
		window.pkiwExercise = {
			activities: [ 'Walk', 'Row' ],
			pbCategories: { '5k': '5K', ultra: 'Ultra' },
		};
		const calls = [];
		const { container } = render(
			<Harness initial={ { personalBest: true } } calls={ calls } />
		);

		const activity = screen.getByLabelText( 'Activity' );
		const list = container.querySelector(
			`datalist[id="${ activity.getAttribute( 'list' ) }"]`
		);
		expect(
			Array.from( list.querySelectorAll( 'option' ) ).map(
				( option ) => option.value
			)
		).toEqual( [ 'Walk', 'Row' ] );

		const category = screen.getByLabelText( 'Personal best for' );
		expect(
			within( category )
				.getAllByRole( 'option' )
				.map( ( option ) => option.value )
		).toEqual( [ '', '5k', 'ultra' ] );

		fireEvent.change( category, { target: { value: 'ultra' } } );
		expect( calls ).toEqual( [ { pbCategory: 'ultra' } ] );
	} );

	it( 'falls back to five built-in categories and an empty activity list without window.pkiwExercise', () => {
		const calls = [];
		const { container } = render(
			<Harness initial={ { personalBest: true } } calls={ calls } />
		);

		const activity = screen.getByLabelText( 'Activity' );
		expect(
			container
				.querySelector(
					`datalist[id="${ activity.getAttribute( 'list' ) }"]`
				)
				.querySelectorAll( 'option' )
		).toHaveLength( 0 );

		const category = screen.getByLabelText( 'Personal best for' );
		expect(
			within( category )
				.getAllByRole( 'option' )
				.map( ( option ) => option.value )
		).toEqual( [ '', ...FALLBACK_PB_CATEGORIES.map( ( c ) => c.value ) ] );
		expect( FALLBACK_PB_CATEGORIES ).toHaveLength( 5 );
	} );

	it( 'shows the category choice only for a personal best', () => {
		render( <Harness calls={ [] } /> );

		expect( screen.queryByLabelText( 'Personal best for' ) ).toBeNull();

		fireEvent.click( screen.getByLabelText( 'This is a personal best' ) );

		expect( screen.getByLabelText( 'Personal best for' ) ).toBeTruthy();
	} );

	it( 'writes distance, unit and activity, and nothing writes origin', () => {
		const calls = [];
		render( <Harness calls={ calls } /> );

		fireEvent.change( screen.getByLabelText( 'Activity' ), {
			target: { value: 'Run' },
		} );
		fireEvent.change( screen.getByLabelText( 'Distance' ), {
			target: { value: '7.77' },
		} );
		fireEvent.change( screen.getByLabelText( 'Distance unit' ), {
			target: { value: 'mi' },
		} );
		fireEvent.change( screen.getByLabelText( 'Time' ), {
			target: { value: '29:12' },
		} );
		fireEvent.click( screen.getByText( 'Add split' ) );
		fireEvent.click( screen.getByLabelText( 'This is a personal best' ) );
		fireEvent.click(
			screen.getByLabelText( 'Show workout details publicly' )
		);

		expect( calls ).toEqual(
			expect.arrayContaining( [
				{ activity: 'Run' },
				{ distance: 7.77 },
				{ distanceUnit: 'mi' },
				{ duration: 'PT29M12S' },
			] )
		);
		for ( const call of calls ) {
			expect( Object.keys( call ) ).not.toContain( 'origin' );
		}
		expect( screen.queryByLabelText( /origin/i ) ).toBeNull();
	} );

	it( 'removes the distance when the field is cleared or not above 0', () => {
		const calls = [];
		render( <Harness initial={ { distance: 5 } } calls={ calls } /> );

		const distance = screen.getByLabelText( 'Distance' );
		fireEvent.change( distance, { target: { value: '' } } );
		fireEvent.change( distance, { target: { value: '-2' } } );

		expect( calls ).toEqual( [
			{ distance: undefined },
			{ distance: undefined },
		] );
	} );

	it( 'shows the server preview once there is something to show, and a placeholder before', () => {
		const { rerender } = render(
			<Edit attributes={ {} } setAttributes={ () => {} } />
		);
		expect( screen.queryByTestId( 'preview' ) ).toBeNull();
		expect( screen.getByText( 'Exercise card' ) ).toBeTruthy();

		rerender(
			<Edit
				attributes={ { activity: 'Run' } }
				setAttributes={ () => {} }
			/>
		);
		expect( screen.getByTestId( 'preview' ).textContent ).toBe(
			'post-kinds-indieweb/exercise-card'
		);
	} );
} );
