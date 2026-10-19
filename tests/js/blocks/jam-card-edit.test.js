/**
 * Jam card in the editor (issue 235).
 *
 * The sidebar gets a Date jammed control that stores a plain Y-m-d in
 * jammedAt. Older cards hold a 'YYYY-MM-DDTHH:mm' string; the control shows
 * the day part and writes only a day.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import Edit from '../../../src/blocks/jam-card/edit';

jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: () => ( {} ),
	InspectorControls: ( { children } ) => <div>{ children }</div>,
	RichText: () => null,
	MediaUpload: () => null,
	MediaUploadCheck: ( { children } ) => children,
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => <div>{ children }</div>,
	TextControl: ( { label, value, onChange, type } ) => (
		<input
			aria-label={ label }
			type={ type || 'text' }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	),
	SandBox: () => null,
	Disabled: ( { children } ) => children,
} ) );

jest.mock( '@wordpress/core-data', () => ( { store: 'core' } ) );
jest.mock( '../../../src/components/standard-site-panel', () => () => null );
jest.mock( '../../../src/blocks/shared/components', () => ( {
	MediaSearch: () => null,
} ) );

const editPost = jest.fn();
const setAttributes = jest.fn();

beforeEach( () => {
	editPost.mockReset();
	setAttributes.mockReset();
	useDispatch.mockImplementation( () => ( { editPost } ) );
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( {
			getEditedPostAttribute: () => [ 5 ],
			getEmbedPreview: () => null,
		} ) )
	);
} );

const CARD = {
	title: 'Rhiannon',
	artist: 'Fleetwood Mac',
	jammedAt: '',
};

describe( 'Jam card editor', () => {
	it( 'writes a picked day to jammedAt as Y-m-d', () => {
		render( <Edit attributes={ CARD } setAttributes={ setAttributes } /> );

		const control = screen.getByLabelText( 'Date jammed' );
		expect( control.getAttribute( 'type' ) ).toBe( 'date' );

		fireEvent.change( control, { target: { value: '2026-09-01' } } );

		expect( setAttributes ).toHaveBeenCalledTimes( 1 );
		expect( setAttributes ).toHaveBeenCalledWith( {
			jammedAt: '2026-09-01',
		} );
	} );

	it( 'writes an empty string when the date is cleared', () => {
		render(
			<Edit
				attributes={ { ...CARD, jammedAt: '2026-09-01' } }
				setAttributes={ setAttributes }
			/>
		);

		fireEvent.change( screen.getByLabelText( 'Date jammed' ), {
			target: { value: '' },
		} );

		expect( setAttributes ).toHaveBeenCalledTimes( 1 );
		expect( setAttributes ).toHaveBeenCalledWith( { jammedAt: '' } );
	} );

	it( 'shows the day of an older stored date-time', () => {
		render(
			<Edit
				attributes={ { ...CARD, jammedAt: '2026-09-01T00:30' } }
				setAttributes={ setAttributes }
			/>
		);

		expect( screen.getByLabelText( 'Date jammed' ).value ).toBe(
			'2026-09-01'
		);
		expect( setAttributes ).not.toHaveBeenCalled();
	} );

	it( 'shows an empty control for a stored value that is not a date', () => {
		render(
			<Edit
				attributes={ { ...CARD, jammedAt: 'last Tuesday' } }
				setAttributes={ setAttributes }
			/>
		);

		expect( screen.getByLabelText( 'Date jammed' ).value ).toBe( '' );
	} );

	it( 'shows an empty control when jammedAt was never set', () => {
		render(
			<Edit
				attributes={ { title: 'Rhiannon' } }
				setAttributes={ setAttributes }
			/>
		);

		expect( screen.getByLabelText( 'Date jammed' ).value ).toBe( '' );
	} );

	it( 'leaves the other attributes and the meta sync alone', () => {
		render(
			<Edit
				attributes={ { ...CARD, jammedAt: '2026-09-01T00:30' } }
				setAttributes={ setAttributes }
			/>
		);

		fireEvent.change( screen.getByLabelText( 'Date jammed' ), {
			target: { value: '2026-09-02' },
		} );

		expect( setAttributes ).toHaveBeenCalledWith( {
			jammedAt: '2026-09-02',
		} );
		// The sync effect writes the four registered keys and nothing else.
		const allowed = [
			'_pkiw_jam_album',
			'_pkiw_jam_artist',
			'_pkiw_jam_cover',
			'_pkiw_jam_url',
		];
		const written = editPost.mock.calls.flatMap( ( [ update ] ) =>
			Object.keys( update.meta || {} )
		);
		expect( written.length ).toBeGreaterThan( 0 );
		written.forEach( ( key ) => expect( allowed ).toContain( key ) );
	} );
} );
