/**
 * Acquisition card in the editor (issue 239).
 *
 * The sidebar gets an Acquired date control that stores a plain Y-m-d in
 * acquiredAt, and an image picked without alt text stores '' rather than an
 * invented description.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import Edit from '../../../src/blocks/acquisition-card/edit';

let mockPickedMedia = null;

jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: () => ( {} ),
	InspectorControls: ( { children } ) => <div>{ children }</div>,
	RichText: () => null,
	MediaUpload: ( { onSelect } ) => (
		<button type="button" onClick={ () => onSelect( mockPickedMedia ) }>
			pick photo
		</button>
	),
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
	SelectControl: () => null,
	ToggleControl: () => null,
} ) );

const editPost = jest.fn();
const setAttributes = jest.fn();

beforeEach( () => {
	mockPickedMedia = null;
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
	title: 'Walnut Desk Lamp',
	acquisitionType: 'purchase',
	acquiredAt: '',
};

describe( 'Acquisition card editor', () => {
	it( 'writes a picked date to acquiredAt as Y-m-d', () => {
		render( <Edit attributes={ CARD } setAttributes={ setAttributes } /> );

		const control = screen.getByLabelText( 'Acquired' );
		expect( control.getAttribute( 'type' ) ).toBe( 'date' );

		fireEvent.change( control, { target: { value: '2026-08-01' } } );

		expect( setAttributes ).toHaveBeenCalledWith( {
			acquiredAt: '2026-08-01',
		} );
	} );

	it( 'writes an empty string when the date is cleared', () => {
		render(
			<Edit
				attributes={ { ...CARD, acquiredAt: '2026-08-01' } }
				setAttributes={ setAttributes }
			/>
		);

		const control = screen.getByLabelText( 'Acquired' );
		expect( control.value ).toBe( '2026-08-01' );

		fireEvent.change( control, { target: { value: '' } } );

		expect( setAttributes ).toHaveBeenCalledWith( { acquiredAt: '' } );
	} );

	it( 'stores an empty photoAlt when the media has no alt text', () => {
		mockPickedMedia = {
			url: 'https://example.test/lamp.jpg',
			alt: '',
		};
		render( <Edit attributes={ CARD } setAttributes={ setAttributes } /> );

		fireEvent.click( screen.getByText( 'pick photo' ) );

		expect( setAttributes ).toHaveBeenCalledWith( {
			photo: 'https://example.test/lamp.jpg',
			photoAlt: '',
		} );
	} );

	it( 'stores the media alt text verbatim when there is one', () => {
		mockPickedMedia = {
			url: 'https://example.test/lamp.jpg',
			alt: 'A brass lamp on a desk',
		};
		render( <Edit attributes={ CARD } setAttributes={ setAttributes } /> );

		fireEvent.click( screen.getByText( 'pick photo' ) );

		expect( setAttributes ).toHaveBeenCalledWith( {
			photo: 'https://example.test/lamp.jpg',
			photoAlt: 'A brass lamp on a desk',
		} );
	} );
} );
