/**
 * Venue Detail in the editor (issue 492).
 *
 * The venue taxonomy is `pkiw_venue` with no rest_base, so its route is
 * `/wp/v2/pkiw_venue`. `/wp/v2/venue` is Simple Location's post type, or a
 * 404 without it.
 */

import { render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import Edit from '../../../src/blocks/venue-detail/edit';

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	RangeControl: () => null,
	ToggleControl: () => null,
	SelectControl: ( { label, options } ) => (
		<select aria-label={ label }>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	),
	Spinner: () => null,
	Placeholder: ( { children } ) => <div>{ children }</div>,
} ) );

const VENUES = [
	{
		id: 7,
		name: 'Ritual Coffee',
		meta: { address: '1026 Valencia St', city: 'San Francisco' },
	},
	{ id: 8, name: 'Tartine', meta: { city: 'San Francisco' } },
];

const ATTRIBUTES = {
	venueId: 0,
	showMap: false,
	showAddress: true,
	showCheckins: false,
	checkinCount: 5,
};

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( ( { path } ) => {
		if ( path === '/wp/v2/pkiw_venue?per_page=100' ) {
			return Promise.resolve( VENUES );
		}
		if ( path === '/wp/v2/pkiw_venue/7' ) {
			return Promise.resolve( VENUES[ 0 ] );
		}
		return Promise.reject( new Error( `No route: ${ path }` ) );
	} );
} );

describe( 'Venue Detail editor', () => {
	it( 'lists venues from the pkiw_venue route', async () => {
		render(
			<Edit attributes={ ATTRIBUTES } setAttributes={ jest.fn() } />
		);

		expect(
			await screen.findAllByRole( 'option', { name: 'Ritual Coffee' } )
		).not.toHaveLength( 0 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/pkiw_venue?per_page=100',
		} );
		const paths = apiFetch.mock.calls.map(
			( [ options ] ) => options.path
		);
		expect(
			paths.filter( ( path ) => path.startsWith( '/wp/v2/venue' ) )
		).toEqual( [] );
	} );

	it( 'previews the chosen venue from its term, address from meta', async () => {
		render(
			<Edit
				attributes={ { ...ATTRIBUTES, venueId: 7 } }
				setAttributes={ jest.fn() }
			/>
		);

		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { name: 'Ritual Coffee' } )
			).toBeInTheDocument()
		);
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/pkiw_venue/7',
		} );
		expect(
			screen.getByText( '1026 Valencia St, San Francisco' )
		).toBeInTheDocument();
	} );
} );
