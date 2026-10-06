/**
 * Check-ins Feed in the editor.
 *
 * In archive mode (`inherit`) the editor shows what the server prints, the
 * numbered list and map region from Checkin_Map::render_archive(), not the
 * REST article list the standalone feed previews with.
 */

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import Edit from '../../../src/blocks/checkins-feed/edit';

jest.mock( '@wordpress/server-side-render', () => ( {
	__esModule: true,
	default: ( { block, attributes, skipBlockSupportAttributes } ) => (
		<div
			data-testid="server-side-render"
			data-block={ block }
			data-attributes={ JSON.stringify( attributes ) }
			data-skip-block-support-attributes={ String(
				!! skipBlockSupportAttributes
			) }
		/>
	),
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	RangeControl: ( { label, value, onChange } ) => (
		<input
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => onChange( Number( event.target.value ) ) }
		/>
	),
	ToggleControl: () => null,
	SelectControl: () => null,
	Spinner: () => null,
	Placeholder: ( { label } ) => <div>{ label }</div>,
	Disabled: ( { children } ) => (
		<div data-testid="disabled">{ children }</div>
	),
} ) );

const CHECKINS = [
	{
		id: 11,
		title: { rendered: 'Coffee at Ritual' },
		date: '2026-09-12T14:30:00',
		excerpt: { rendered: '' },
	},
	{
		id: 12,
		title: { rendered: 'Lunch at Tartine' },
		date: '2026-09-13T12:00:00',
		excerpt: { rendered: '' },
	},
];

const ATTRIBUTES = {
	count: 24,
	showMap: true,
	showVenue: true,
	showDate: true,
	showExcerpt: false,
	venueId: 0,
	layout: 'list',
	columns: 2,
	headingLevel: 2,
};

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( ( { path } ) =>
		Promise.resolve( path.startsWith( '/wp/v2/checkin' ) ? CHECKINS : [] )
	);
} );

describe( 'Check-ins Feed editor in archive mode', () => {
	const attributes = { ...ATTRIBUTES, inherit: true };

	it( 'previews the server output with the block’s attributes', async () => {
		const { container } = render(
			<Edit attributes={ attributes } setAttributes={ jest.fn() } />
		);

		const preview = await screen.findByTestId( 'server-side-render' );

		expect( preview.dataset.block ).toBe(
			'post-kinds-indieweb/checkins-feed'
		);
		expect( JSON.parse( preview.dataset.attributes ) ).toEqual(
			attributes
		);
		// The wrapper carries the block supports, so the server output
		// leaves them off, and the preview's links don't navigate.
		expect( preview.dataset.skipBlockSupportAttributes ).toBe( 'true' );
		expect( preview.closest( '[data-testid="disabled"]' ) ).not.toBeNull();
		expect(
			container.querySelector( 'article.checkins-feed__item' )
		).toBeNull();
		expect(
			screen.queryByText( 'Map will display here on the frontend' )
		).toBeNull();
	} );

	it( 'does not fetch the REST check-in list', async () => {
		render(
			<Edit attributes={ attributes } setAttributes={ jest.fn() } />
		);

		await screen.findByTestId( 'server-side-render' );

		const paths = apiFetch.mock.calls.map(
			( [ options ] ) => options.path
		);
		expect(
			paths.filter( ( path ) => path.startsWith( '/wp/v2/checkin' ) )
		).toEqual( [] );
	} );

	it( 'keeps the per-page count, which the archive query reads', () => {
		const setAttributes = jest.fn();
		render(
			<Edit attributes={ attributes } setAttributes={ setAttributes } />
		);

		fireEvent.change( screen.getByLabelText( 'Check-ins per page' ), {
			target: { value: '30' },
		} );

		expect( setAttributes ).toHaveBeenCalledWith( { count: 30 } );
	} );
} );

describe( 'Check-ins Feed editor outside an archive', () => {
	it( 'still previews the REST list', async () => {
		const { container } = render(
			<Edit
				attributes={ { ...ATTRIBUTES, inherit: false } }
				setAttributes={ jest.fn() }
			/>
		);

		await waitFor( () =>
			expect(
				container.querySelectorAll( 'article.checkins-feed__item' )
			).toHaveLength( 2 )
		);
		expect( screen.queryByTestId( 'server-side-render' ) ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/checkin?per_page=24&_embed',
		} );
	} );
} );
