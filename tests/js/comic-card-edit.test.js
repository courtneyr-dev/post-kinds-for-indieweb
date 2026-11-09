import { fireEvent, render, screen } from '@testing-library/react';
import Edit from '../../src/blocks/comic-card/edit';

jest.mock( '../../src/blocks/shared/components', () => ( {
	StarRating: () => null,
	CoverImage: () => null,
	DayField: () => null,
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => children,
	useBlockProps: () => ( {} ),
	RichText: () => null,
	MediaUpload: () => null,
	MediaUploadCheck: ( { children } ) => children,
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	TextControl: ( { label, value, onChange } ) => {
		const id = `comic-${ label.toLowerCase().replace( /\s+/g, '-' ) }`;
		return (
			<>
				<label htmlFor={ id }>{ label }</label>
				<input
					id={ id }
					value={ value }
					onChange={ ( event ) => onChange( event.target.value ) }
				/>
			</>
		);
	},
	TextareaControl: () => null,
	SelectControl: () => null,
	Button: ( { children } ) => <button>{ children }</button>,
} ) );

describe( 'comic card editor Metron fields', () => {
	it( 'renders labeled controls that write metronId and isbn', () => {
		const setAttributes = jest.fn();
		render( <Edit attributes={ {} } setAttributes={ setAttributes } /> );

		fireEvent.change(
			screen.getByRole( 'textbox', { name: 'Metron ID' } ),
			{
				target: { value: '101' },
			}
		);
		fireEvent.change( screen.getByRole( 'textbox', { name: 'ISBN' } ), {
			target: { value: '9798368809267' },
		} );

		expect( setAttributes ).toHaveBeenNthCalledWith( 1, {
			metronId: '101',
		} );
		expect( setAttributes ).toHaveBeenNthCalledWith( 2, {
			isbn: '9798368809267',
		} );
	} );
} );
