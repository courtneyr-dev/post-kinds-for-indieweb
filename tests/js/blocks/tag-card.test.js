import { render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import Edit from '../../../src/blocks/tag-card/edit';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/data', () => ( {
	useSelect: ( callback ) =>
		callback( () => ( {
			getMedia: ( id ) =>
				id === 42
					? { source_url: 'https://site.example/local.jpg' }
					: null,
		} ) ),
} ) );
jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => children,
	MediaUploadCheck: ( { children } ) => children,
	MediaUpload: ( { render: renderButton } ) =>
		renderButton( { open: jest.fn() } ),
	useBlockProps: () => ( {} ),
} ) );
jest.mock( '@wordpress/components', () => ( {
	Button: ( { children } ) => <button>{ children }</button>,
	FormTokenField: ( { label, value } ) => (
		<div aria-label={ label }>
			{ value.map( ( token ) => (
				<span key={ token }>{ token }</span>
			) ) }
		</div>
	),
	Notice: ( { children } ) => <div role="status">{ children }</div>,
	PanelBody: ( { children } ) => children,
	RadioControl: () => null,
	TextControl: ( { label } ) => (
		<label htmlFor={ `mock-${ label }` }>
			{ label }
			<input id={ `mock-${ label }` } />
		</label>
	),
	TextareaControl: ( { label } ) => (
		<label htmlFor={ `mock-${ label }` }>
			{ label }
			<textarea id={ `mock-${ label }` } />
		</label>
	),
} ) );

describe( 'Tag Card editor', () => {
	it( 'shows a person name, initial, privacy notice and ordered labels without a request', () => {
		render(
			<Edit
				attributes={ {
					targetType: 'person',
					title: 'Avery',
					url: 'https://person.example/path',
					labels: [ 'First', 'Second' ],
					taggedAt: '2026-11-15',
				} }
				setAttributes={ jest.fn() }
			/>
		);
		expect( screen.getAllByText( 'Avery' ).length ).toBeGreaterThan( 0 );
		expect( screen.getByText( 'A' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'published and public'
		);
		expect(
			screen.queryByLabelText( 'Description' )
		).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Labels' ) ).toHaveTextContent(
			'FirstSecond'
		);
		expect( screen.getByText( 'person.example' ) ).toHaveClass(
			'pkiw-tag-card__host'
		);
		expect( screen.getByText( 'Labels:' ) ).toHaveClass(
			'screen-reader-text'
		);
		expect(
			screen.getByText( '2026-11-15' ).closest( 'time' )
		).toHaveAttribute( 'datetime', '2026-11-15' );
		expect( screen.getByRole( 'link', { name: 'Avery' } ) ).toHaveClass(
			'p-name',
			'u-url'
		);
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'shows Description only for a content target', () => {
		render(
			<Edit
				attributes={ {
					targetType: 'content',
					url: 'https://content.example/story',
					title: 'Tagged story',
					description: 'Summary',
					labels: [],
				} }
				setAttributes={ jest.fn() }
			/>
		);
		expect( screen.getByLabelText( 'Description' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Tagged story' } )
		).toHaveClass( 'u-tag-of' );
		expect( screen.getByText( 'content.example' ) ).toHaveClass(
			'pkiw-tag-card__host'
		);
		expect( screen.getByText( 'Summary' ) ).toHaveClass(
			'pkiw-tag-card__description'
		);
	} );

	it( 'previews the selected media-library person photo with front-end classes', () => {
		const { container } = render(
			<Edit
				attributes={ {
					targetType: 'person',
					title: 'Avery',
					imageId: 42,
					labels: [],
				} }
				setAttributes={ jest.fn() }
			/>
		);
		const photo = container.querySelector( 'img.u-photo' );
		expect( photo ).toHaveAttribute(
			'src',
			'https://site.example/local.jpg'
		);
		expect( photo ).toHaveAttribute( 'alt', '' );
		expect(
			container.querySelector( '.pkiw-tag-card__initial' )
		).not.toBeInTheDocument();
	} );
} );
