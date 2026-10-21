/**
 * Collection item editor coverage for issue 240.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { useSelect } from '@wordpress/data';
import Edit from '../../../src/blocks/collection-item/edit';

let referencedPost = null;
let searchResults = [];

jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => <div>{ children }</div>,
	MediaUpload: ( { onSelect } ) => (
		<button type="button" onClick={ () => onSelect( { id: 41 } ) }>
			Choose image
		</button>
	),
	MediaUploadCheck: ( { children } ) => children,
	useBlockProps: () => ( {} ),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick } ) => (
		<button type="button" onClick={ onClick }>
			{ children }
		</button>
	),
	Notice: ( { children } ) => <div role="alert">{ children }</div>,
	PanelBody: ( { children } ) => <div>{ children }</div>,
	Placeholder: ( { children, label } ) => (
		<div>
			<span>{ label }</span>
			{ children }
		</div>
	),
	RadioControl: ( { label, options, selected, onChange } ) => (
		<fieldset>
			<legend>{ label }</legend>
			{ options.map( ( option ) => (
				<div key={ option.value }>
					<input
						type="radio"
						value={ option.value }
						checked={ selected === option.value }
						onChange={ () => onChange( option.value ) }
						aria-label={ option.label }
					/>
				</div>
			) ) }
		</fieldset>
	),
	SelectControl: ( { label, options, value, onChange } ) => (
		<div>
			<span>{ label }</span>
			<select
				aria-label={ label }
				value={ value }
				onChange={ ( event ) =>
					onChange( Number( event.target.value ) )
				}
			>
				{ options.map( ( option ) => (
					<option key={ option.value } value={ option.value }>
						{ option.label }
					</option>
				) ) }
			</select>
		</div>
	),
	TextControl: ( { label, onChange, type = 'text', value } ) => (
		<div>
			<span>{ label }</span>
			<input
				aria-label={ label }
				type={ type }
				value={ value }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
		</div>
	),
} ) );

jest.mock(
	'@wordpress/server-side-render',
	() =>
		( { EmptyResponsePlaceholder } ) =>
			EmptyResponsePlaceholder ? (
				<EmptyResponsePlaceholder />
			) : (
				<div>Server render</div>
			)
);

const setAttributes = jest.fn();
const ATTRIBUTES = {
	postId: 0,
	title: '',
	date: '',
	source: '',
	url: '',
	mediaId: 0,
	addedAt: '',
	headingLevel: 2,
};

beforeEach( () => {
	setAttributes.mockReset();
	referencedPost = null;
	searchResults = [];
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( {
			getEntityRecord: () => referencedPost,
			getEntityRecords: () => searchResults,
		} ) )
	);
} );

describe( 'Collection item editor', () => {
	it( 'sets an ISO 8601 addedAt value when the block is inserted', () => {
		render(
			<Edit attributes={ ATTRIBUTES } setAttributes={ setAttributes } />
		);

		const call = setAttributes.mock.calls.find(
			( [ value ] ) => value.addedAt
		);
		expect( call ).toBeDefined();
		expect( new Date( call[ 0 ].addedAt ).toISOString() ).toBe(
			call[ 0 ].addedAt
		);
	} );

	it( 'keeps an existing addedAt value when the block moves or rerenders', () => {
		render(
			<Edit
				attributes={ {
					...ATTRIBUTES,
					addedAt: '2026-10-05T12:30:00.000Z',
				} }
				setAttributes={ setAttributes }
			/>
		);

		expect( setAttributes ).not.toHaveBeenCalledWith(
			expect.objectContaining( { addedAt: expect.anything() } )
		);
	} );

	it( 'stores only the internal post reference and clears external fields', () => {
		searchResults = [
			{
				id: 73,
				date: '2026-09-21T13:00:00',
				featured_media: 28,
				link: 'https://example.test/fern-atlas/',
				title: { rendered: 'The Fern Atlas' },
			},
		];

		render(
			<Edit
				attributes={ {
					...ATTRIBUTES,
					addedAt: '2026-10-05T12:30:00.000Z',
				} }
				setAttributes={ setAttributes }
			/>
		);
		fireEvent.click( screen.getByLabelText( 'Post on this site' ) );
		fireEvent.change( screen.getByLabelText( 'Search posts' ), {
			target: { value: 'fern' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'The Fern Atlas' } )
		);

		expect( setAttributes ).toHaveBeenCalledWith( {
			postId: 73,
			title: '',
			date: '',
			url: '',
			mediaId: 0,
		} );
	} );

	it.each( [
		[ 'draft', '' ],
		[ 'publish', 'secret' ],
	] )(
		'warns when a referenced post is not public (%s)',
		( status, password ) => {
			referencedPost = { id: 73, status, password };

			render(
				<Edit
					attributes={ {
						...ATTRIBUTES,
						postId: 73,
						addedAt: '2026-10-05T12:30:00.000Z',
					} }
					setAttributes={ setAttributes }
				/>
			);

			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				"This post isn't public, so it won't show on the site."
			);
		}
	);

	it( 'offers exactly heading levels 2, 3, and 4', () => {
		render(
			<Edit
				attributes={ {
					...ATTRIBUTES,
					addedAt: '2026-10-05T12:30:00.000Z',
				} }
				setAttributes={ setAttributes }
			/>
		);

		const values = Array.from(
			screen.getByLabelText( 'Heading level' ).options
		).map( ( option ) => Number( option.value ) );
		expect( values ).toEqual( [ 2, 3, 4 ] );
	} );
} );
