import { render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import Edit from '../../../src/blocks/tag-card/edit';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => children,
	MediaUploadCheck: ( { children } ) => children,
	MediaUpload: ( { render: renderButton } ) => renderButton( { open: jest.fn() } ),
	useBlockProps: () => ( {} ),
} ) );
jest.mock( '@wordpress/components', () => ( {
	Button: ( { children } ) => <button>{ children }</button>,
	FormTokenField: ( { label, value } ) => <div aria-label={ label }>{ value.map( ( token ) => <span key={ token }>{ token }</span> ) }</div>,
	Notice: ( { children } ) => <div role="status">{ children }</div>,
	PanelBody: ( { children } ) => children,
	RadioControl: () => null,
	TextControl: ( { label } ) => <label>{ label }<input /></label>,
	TextareaControl: ( { label } ) => <label>{ label }<textarea /></label>,
} ) );

describe( 'Tag Card editor', () => {
	it( 'shows a person name, initial, privacy notice and ordered labels without a request', () => {
		render(
			<Edit
				attributes={ { targetType: 'person', title: 'Avery', url: 'https://person.example/', labels: [ 'First', 'Second' ] } }
				setAttributes={ jest.fn() }
			/>
		);
		expect( screen.getAllByText( 'Avery' ).length ).toBeGreaterThan( 0 );
		expect( screen.getByText( 'A' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'published and public' );
		expect( screen.queryByLabelText( 'Description' ) ).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Labels' ) ).toHaveTextContent( 'FirstSecond' );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'shows Description only for a content target', () => {
		render( <Edit attributes={ { targetType: 'content', labels: [] } } setAttributes={ jest.fn() } /> );
		expect( screen.getByLabelText( 'Description' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
	} );
} );
