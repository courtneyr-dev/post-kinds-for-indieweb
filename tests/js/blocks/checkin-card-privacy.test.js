/**
 * The Check-in Card's privacy control sets both the card and the post's
 * stored setting (issue 358, "Card sets both").
 *
 * Choosing a privacy level writes the card's `locationPrivacy` and the
 * post's `_pkiw_geo_privacy` through the post-kinds store's updateKindMeta(),
 * the same write the card makes for its venue fields. Opening a post whose
 * stored setting disagrees with the card writes nothing and shows nothing
 * new; the row stays as stored until the author touches the control.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { useDispatch, useSelect } from '@wordpress/data';
import Edit from '../../../src/blocks/checkin-card/edit';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );
jest.mock( '../../../src/editor/stores/post-kinds', () => ( {
	STORE_NAME: 'post-kinds-indieweb/post-kinds',
} ) );
jest.mock( '../../../src/blocks/shared/components', () => ( {
	BlockPlaceholder: ( { children } ) => children,
	LocationDisplay: () => null,
	parseDate: () => null,
} ) );
jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	TextControl: () => null,
	SelectControl: () => null,
	Button: () => null,
	DateTimePicker: () => null,
	Popover: () => null,
	ToggleControl: () => null,
	Spinner: () => null,
	Notice: ( { children } ) => <div role="note">{ children }</div>,
	RadioControl: ( { label, selected, options, onChange } ) => (
		<fieldset>
			<legend>{ label }</legend>
			{ options.map( ( option ) => (
				<input
					key={ option.value }
					type="radio"
					aria-label={ option.label }
					checked={ selected === option.value }
					onChange={ () => onChange( option.value ) }
				/>
			) ) }
		</fieldset>
	),
} ) );

const updateKindMeta = jest.fn();
const updatePostKind = jest.fn();
let meta;

beforeEach( () => {
	// A saved check-in whose stored venue fields match the card.
	meta = {
		checkin_name: 'Ritual Coffee',
		checkin_locality: 'San Francisco',
		geo_privacy: 'approximate',
	};
	updateKindMeta.mockClear();
	updatePostKind.mockClear();
	useDispatch.mockReturnValue( { updateKindMeta, updatePostKind } );
	useSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( () => ( {
			getKindMeta: ( key ) => meta[ key ] || '',
			getSelectedKind: () => 'checkin',
		} ) )
	);
	window.confirm = jest.fn( () => true );
} );

/**
 * Open a check-in card with a venue, as the editor does.
 *
 * @param {Object} saved Attributes saved in the block comment.
 * @return {jest.Mock} The setAttributes spy.
 */
function open( saved = {} ) {
	const setAttributes = jest.fn();
	render(
		<Edit
			attributes={ {
				venueName: 'Ritual Coffee',
				locality: 'San Francisco',
				locationPrivacy: 'approximate',
				showMap: true,
				layout: 'horizontal',
				...saved,
			} }
			setAttributes={ setAttributes }
		/>
	);
	return setAttributes;
}

/**
 * The post meta writes the card made for the privacy row.
 *
 * @return {Array} updateKindMeta() calls for geo_privacy.
 */
function privacyWrites() {
	return updateKindMeta.mock.calls.filter(
		( [ key ] ) => 'geo_privacy' === key
	);
}

describe( 'Check-in Card privacy control', () => {
	it( 'writes Private to the card and the stored setting', () => {
		const setAttributes = open();

		fireEvent.click( screen.getByLabelText( 'Private (hidden)' ) );

		expect( setAttributes ).toHaveBeenCalledWith( {
			locationPrivacy: 'private',
		} );
		expect( privacyWrites() ).toEqual( [ [ 'geo_privacy', 'private' ] ] );
	} );

	it( 'writes Approximate to the card and the stored setting', () => {
		const setAttributes = open( { locationPrivacy: 'private' } );

		fireEvent.click( screen.getByLabelText( 'Approximate' ) );

		expect( setAttributes ).toHaveBeenCalledWith( {
			locationPrivacy: 'approximate',
		} );
		expect( privacyWrites() ).toEqual( [
			[ 'geo_privacy', 'approximate' ],
		] );
	} );

	it( 'writes Public to both once the author confirms', () => {
		const setAttributes = open();

		fireEvent.click( screen.getByLabelText( 'Public (exact location)' ) );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		expect( setAttributes ).toHaveBeenCalledWith( {
			locationPrivacy: 'public',
		} );
		expect( privacyWrites() ).toEqual( [ [ 'geo_privacy', 'public' ] ] );
	} );

	it( 'writes nothing when the author cancels Public', () => {
		window.confirm = jest.fn( () => false );
		const setAttributes = open();

		fireEvent.click( screen.getByLabelText( 'Public (exact location)' ) );

		expect( setAttributes ).not.toHaveBeenCalledWith( {
			locationPrivacy: 'public',
		} );
		expect( privacyWrites() ).toEqual( [] );
	} );

	it( 'leaves a disagreeing stored setting alone until the control changes', () => {
		// Card Public, row Approximate: the editor echo stored the default
		// before the card set the row.
		const setAttributes = open( { locationPrivacy: 'public' } );

		expect( privacyWrites() ).toEqual( [] );
		expect( setAttributes ).not.toHaveBeenCalledWith(
			expect.objectContaining( { locationPrivacy: expect.anything() } )
		);
		// Only the card's own Public warning, nothing about the stored row.
		expect( screen.getAllByRole( 'note' ) ).toHaveLength( 1 );
		expect( screen.getByRole( 'note' ) ).toHaveTextContent(
			'Your exact coordinates will be visible to everyone.'
		);
	} );
} );
