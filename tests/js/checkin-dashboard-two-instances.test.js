/**
 * Check-in Dashboard view script (src/blocks/checkin-dashboard/view.js)
 * with two dashboards on one page (issue 352).
 *
 * Each dashboard draws its own map on its own container. Before the fix the
 * script kept one page-wide "map drawn" flag and looked the map up by a
 * fixed ID, so the second dashboard never got a map.
 */

const SCRIPT = '../../src/blocks/checkin-dashboard/view.js';

/**
 * A Leaflet stand-in that records which containers got a map.
 *
 * @return {Object} The stub, also set as the global `L`.
 */
function stubLeaflet() {
	window.L = {
		map: jest.fn( () => {
			const map = {
				setView: jest.fn( () => map ),
				fitBounds: jest.fn(),
				addLayer: jest.fn(),
			};
			return map;
		} ),
		tileLayer: jest.fn( () => ( { addTo: jest.fn() } ) ),
		marker: jest.fn( () => ( { bindPopup: jest.fn() } ) ),
		markerClusterGroup: jest.fn( () => ( { addLayer: jest.fn() } ) ),
	};
	return window.L;
}

/**
 * Markup for one dashboard as render.php prints it with the map layout.
 *
 * @param {string} id Map element ID.
 * @return {string} HTML.
 */
function dashboard( id ) {
	const checkins = JSON.stringify( [
		{ lat: 40.2, lng: -74.7, title: 'Example Hall', url: '#' },
	] );
	return `<div class="checkin-dashboard-frontend" data-show-map="true" data-layout="map">
		<div class="checkin-view-map active">
			<div id="${ id }" class="checkin-map" data-checkins='${ checkins }'></div>
		</div>
	</div>`;
}

describe( 'Check-in Dashboard with two instances', () => {
	// Each require() adds a DOMContentLoaded listener to the shared
	// document; remove them so one test's script doesn't run in the next.
	let listeners = [];
	const addEventListener = document.addEventListener.bind( document );

	beforeEach( () => {
		jest.resetModules();
		listeners = [];
		jest.spyOn( document, 'addEventListener' ).mockImplementation(
			( type, handler, options ) => {
				listeners.push( [ type, handler, options ] );
				addEventListener( type, handler, options );
			}
		);
		document.body.innerHTML =
			dashboard( 'checkin-frontend-map-1' ) +
			dashboard( 'checkin-frontend-map-2' );
	} );

	afterEach( () => {
		listeners.forEach( ( [ type, handler, options ] ) =>
			document.removeEventListener( type, handler, options )
		);
		jest.restoreAllMocks();
		delete window.L;
		document.body.innerHTML = '';
	} );

	it( 'draws a map in each dashboard, on that dashboard’s own container', () => {
		const L = stubLeaflet();
		require( SCRIPT );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

		const containers = L.map.mock.calls.map( ( call ) => call[ 0 ].id );
		expect( containers ).toEqual( [
			'checkin-frontend-map-1',
			'checkin-frontend-map-2',
		] );
	} );

	it( 'draws each map once even when its map view is chosen again', () => {
		const L = stubLeaflet();
		document
			.querySelectorAll( '.checkin-dashboard-frontend' )
			.forEach( ( el ) => {
				el.insertAdjacentHTML(
					'afterbegin',
					'<button class="view-btn" data-view="map">Map</button>'
				);
			} );
		require( SCRIPT );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		document
			.querySelectorAll( '.view-btn' )
			.forEach( ( btn ) => btn.click() );

		expect( L.map ).toHaveBeenCalledTimes( 2 );
	} );
} );
