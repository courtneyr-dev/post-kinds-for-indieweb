/**
 * Wish card in the editor (issue 238).
 *
 * The Wish Details panel gets a Wished date control that stores a plain
 * Y-m-d in wishedAt. It sets a picked day, clears it, and shows nothing for
 * a stored value that is not a date.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import Edit from '../../../src/blocks/wish-card/edit';
import { displayDay } from '../../../src/blocks/comic-card/labels';

jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: () => ( {} ),
	InspectorControls: ( { children } ) => <div>{ children }</div>,
	RichText: () => null,
	MediaUpload: () => null,
	MediaUploadCheck: ( { children } ) => children,
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => <div>{ children }</div>,
	TextControl: () => null,
	SelectControl: () => null,
	Button: ( { children, onClick } ) => (
		<button type="button" onClick={ onClick }>
			{ children }
		</button>
	),
	Popover: ( { children } ) => <div data-testid="popover">{ children }</div>,
	DatePicker: ( { onChange } ) => (
		<button
			type="button"
			onClick={ () => onChange( '2026-09-01T00:00:00' ) }
		>
			pick day
		</button>
	),
} ) );

jest.mock( '../../../src/components/standard-site-panel', () => () => null );

const editPost = jest.fn();
const setAttributes = jest.fn();

beforeEach( () => {
	editPost.mockReset();
	setAttributes.mockReset();
	useDispatch.mockImplementation( () => ( { editPost } ) );
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( {
			getEditedPostAttribute: () => [ 5 ],
		} ) )
	);
} );

const CARD = {
	title: 'Brass Lantern',
	wishType: 'item',
	priority: 'medium',
};

describe( 'Wish card editor', () => {
	it( 'labels the control Wished and starts it unset', () => {
		render( <Edit attributes={ CARD } setAttributes={ setAttributes } /> );

		expect( screen.getByText( 'Wished' ) ).toBeTruthy();
		expect( screen.getByText( 'Set date' ) ).toBeTruthy();
		expect( screen.queryByText( 'Clear' ) ).toBeNull();
	} );

	it( 'writes a picked day to wishedAt as Y-m-d', () => {
		render( <Edit attributes={ CARD } setAttributes={ setAttributes } /> );

		fireEvent.click( screen.getByText( 'Set date' ) );
		fireEvent.click( screen.getByText( 'pick day' ) );

		expect( setAttributes ).toHaveBeenCalledTimes( 1 );
		expect( setAttributes ).toHaveBeenCalledWith( {
			wishedAt: '2026-09-01',
		} );
	} );

	it( 'shows the stored day, also when an older value carries a time', () => {
		render(
			<Edit
				attributes={ { ...CARD, wishedAt: '2026-09-01T09:30' } }
				setAttributes={ setAttributes }
			/>
		);

		expect( screen.getByText( displayDay( '2026-09-01' ) ) ).toBeTruthy();
		expect( screen.queryByText( 'Set date' ) ).toBeNull();
		expect( setAttributes ).not.toHaveBeenCalled();
	} );

	it( 'clears wishedAt to an empty string', () => {
		render(
			<Edit
				attributes={ { ...CARD, wishedAt: '2026-09-01' } }
				setAttributes={ setAttributes }
			/>
		);

		fireEvent.click( screen.getByText( 'Clear' ) );

		expect( setAttributes ).toHaveBeenCalledTimes( 1 );
		expect( setAttributes ).toHaveBeenCalledWith( { wishedAt: '' } );
	} );

	it( 'shows no date for a stored value that is not a date', () => {
		render(
			<Edit
				attributes={ { ...CARD, wishedAt: 'someday' } }
				setAttributes={ setAttributes }
			/>
		);

		expect( screen.getByText( 'Set date' ) ).toBeTruthy();
		expect( screen.queryByText( 'Clear' ) ).toBeNull();
		expect( setAttributes ).not.toHaveBeenCalled();
	} );

	it( 'ignores a day that does not exist on the calendar', () => {
		render(
			<Edit
				attributes={ { ...CARD, wishedAt: '2026-02-30' } }
				setAttributes={ setAttributes }
			/>
		);

		expect( screen.getByText( 'Set date' ) ).toBeTruthy();
		expect( screen.queryByText( 'Clear' ) ).toBeNull();
	} );
} );
