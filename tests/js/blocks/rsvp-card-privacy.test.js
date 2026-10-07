/**
 * The RSVP Card's location toggle sets both the card and the post's stored
 * setting (issue 358, "Card sets both").
 *
 * Switching "Show event location publicly" writes the card's
 * `locationVisibility` and the post's `_pkiw_rsvp_location_privacy` through
 * the post-kinds store's updateKindMeta(). Opening a post writes nothing; the
 * row stays as stored until the author touches the toggle.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { useDispatch, useSelect } from '@wordpress/data';
import Edit from '../../../src/blocks/rsvp-card/edit';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );
jest.mock( '../../../src/editor/stores/post-kinds', () => ( {
	STORE_NAME: 'post-kinds-indieweb/post-kinds',
} ) );
jest.mock( '../../../src/blocks/shared/components', () => ( {
	BlockPlaceholder: ( { children } ) => children,
	parseDate: () => null,
} ) );
jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	TextControl: () => null,
	TextareaControl: () => null,
	SelectControl: () => null,
	Button: () => null,
	ButtonGroup: ( { children } ) => children,
	DateTimePicker: () => null,
	Popover: () => null,
	ToggleControl: ( { label, checked, onChange } ) => (
		<input
			type="checkbox"
			aria-label={ label }
			checked={ checked }
			onChange={ ( event ) => onChange( event.target.checked ) }
		/>
	),
} ) );

const updateKindMeta = jest.fn();

beforeEach( () => {
	updateKindMeta.mockClear();
	useDispatch.mockReturnValue( { updateKindMeta } );
	useSelect.mockReturnValue( {} );
} );

/**
 * Open an RSVP card with an event, as the editor does.
 *
 * @param {Object} saved Attributes saved in the block comment.
 * @return {jest.Mock} The setAttributes spy.
 */
function open( saved = {} ) {
	const setAttributes = jest.fn();
	render(
		<Edit
			attributes={ {
				eventName: 'Quillfeather Meetup',
				eventLocation: 'Back room, 358 Quill Lane',
				locationVisibility: 'private',
				rsvpStatus: 'yes',
				layout: 'horizontal',
				...saved,
			} }
			setAttributes={ setAttributes }
		/>
	);
	return setAttributes;
}

const toggle = () => screen.getByLabelText( 'Show event location publicly' );

describe( 'RSVP Card location toggle', () => {
	it( 'writes public to the card and the stored setting when turned on', () => {
		const setAttributes = open();

		fireEvent.click( toggle() );

		expect( setAttributes ).toHaveBeenCalledWith( {
			locationVisibility: 'public',
		} );
		expect( updateKindMeta.mock.calls ).toEqual( [
			[ 'rsvp_location_privacy', 'public' ],
		] );
	} );

	it( 'writes private to the card and the stored setting when turned off', () => {
		const setAttributes = open( { locationVisibility: 'public' } );

		fireEvent.click( toggle() );

		expect( setAttributes ).toHaveBeenCalledWith( {
			locationVisibility: 'private',
		} );
		expect( updateKindMeta.mock.calls ).toEqual( [
			[ 'rsvp_location_privacy', 'private' ],
		] );
	} );

	it( 'sets only the card where the post-kinds store is not registered', () => {
		// The site editor and widgets load the block without the store.
		useDispatch.mockReturnValue( undefined );
		const setAttributes = open();

		fireEvent.click( toggle() );

		expect( setAttributes ).toHaveBeenCalledWith( {
			locationVisibility: 'public',
		} );
		expect( updateKindMeta ).not.toHaveBeenCalled();
	} );

	it( 'writes nothing when the card opens', () => {
		const setAttributes = open( { locationVisibility: 'public' } );

		expect( toggle() ).toBeChecked();
		expect( updateKindMeta ).not.toHaveBeenCalled();
		expect( setAttributes ).not.toHaveBeenCalled();
	} );
} );
