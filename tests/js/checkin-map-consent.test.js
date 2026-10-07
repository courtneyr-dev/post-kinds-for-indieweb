/**
 * Check-in archive map and site consent tools (assets/js/checkin-map.js).
 *
 * When the server marks the map data-pkiw-consent="required", the script
 * draws nothing, so no tile request leaves the page, until the
 * `pkiw:map-consent` event fires or window.pkiwMapConsent is already true.
 * The list stays as the server printed it meanwhile.
 */

const SCRIPT = '../../assets/js/checkin-map.js';

/**
 * A Leaflet stand-in that records which calls the script made.
 *
 * @return {Object} The stub, also set as the global `L`.
 */
function stubLeaflet() {
	const map = {
		attributionControl: { setPrefix: jest.fn() },
		setView: jest.fn(),
		fitBounds: jest.fn(),
		addLayer: jest.fn(),
		getZoom: () => 10,
		getMaxZoom: () => 19,
		getPane: () => document.createElement( 'div' ),
		on: jest.fn(),
		once: jest.fn(),
	};
	window.L = {
		map: jest.fn( () => map ),
		tileLayer: jest.fn( () => ( { addTo: jest.fn() } ) ),
		divIcon: jest.fn( () => ( {} ) ),
		marker: jest.fn( () => ( {
			on: jest.fn(),
			getElement: () => null,
			getLatLng: () => [ 40.1, -75.1 ],
		} ) ),
		latLngBounds: jest.fn(),
		markerClusterGroup: jest.fn( () => ( {
			addLayer: jest.fn(),
			zoomToShowLayer: jest.fn(),
			on: jest.fn(),
			once: jest.fn(),
			getVisibleParent: ( marker ) => marker,
		} ) ),
	};
	return window.L;
}

/**
 * Print the archive the way Checkin_Map::render_archive() does.
 *
 * @param {boolean} requiresConsent Whether the map carries the marker.
 */
function printArchive( requiresConsent ) {
	const pins = JSON.stringify( [
		{
			lat: 40.1,
			lng: -75.1,
			ids: [ 5 ],
			numbers: [ 1 ],
			label: '1: Coffee. Go to its list entry.',
		},
	] );
	document.body.innerHTML = `
		<div class="pkiw-checkin-archive">
			<div class="pkiw-checkin-archive__map" id="pkiw-checkin-map-1"
				data-pins='${ pins }'
				data-tile-url="https://tiles.example/{z}/{x}/{y}.png"
				data-attribution="Example"
				data-max-zoom="19"
				${ requiresConsent ? 'data-pkiw-consent="required"' : '' }
				hidden></div>
			<ul class="pkiw-checkin-archive__entries">
				<li class="pkiw-checkin-archive__entry" id="pkiw-checkin-entry-5">
					<span class="pkiw-checkin-archive__num" data-map="pkiw-checkin-map-1"
						data-entry="5" data-label="Show Coffee on map">1</span>
					<h2><a href="https://example.org/coffee/">Coffee</a></h2>
				</li>
			</ul>
		</div>`;
}

function loadScript() {
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
}

const mapEl = () => document.getElementById( 'pkiw-checkin-map-1' );
const listNumber = () => document.querySelector( '.pkiw-checkin-archive__num' );

afterEach( () => {
	jest.restoreAllMocks();
	delete window.L;
	delete window.pkiwMapConsent;
	document.body.innerHTML = '';
} );

describe( 'check-in archive map with a consent tool', () => {
	it( 'loads no tiles until the consent event fires, and keeps the list', () => {
		const L = stubLeaflet();
		printArchive( true );
		loadScript();

		expect( L.map ).not.toHaveBeenCalled();
		expect( L.tileLayer ).not.toHaveBeenCalled();
		expect( mapEl().hidden ).toBe( true );
		expect( listNumber().tagName ).toBe( 'SPAN' );
		expect( document.querySelector( '#pkiw-checkin-entry-5 a' ).href ).toBe(
			'https://example.org/coffee/'
		);

		document.dispatchEvent( new Event( 'pkiw:map-consent' ) );

		expect( L.map ).toHaveBeenCalledWith( mapEl(), expect.any( Object ) );
		expect( L.tileLayer ).toHaveBeenCalledWith(
			'https://tiles.example/{z}/{x}/{y}.png',
			expect.any( Object )
		);
		expect( mapEl().hidden ).toBe( false );
		expect( listNumber().tagName ).toBe( 'BUTTON' );
	} );

	it( 'draws the map once when consent fires twice', () => {
		const L = stubLeaflet();
		printArchive( true );
		loadScript();

		document.dispatchEvent( new Event( 'pkiw:map-consent' ) );
		document.dispatchEvent( new Event( 'pkiw:map-consent' ) );

		expect( L.map ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'draws at once when consent was given before the script ran', () => {
		const L = stubLeaflet();
		printArchive( true );
		window.pkiwMapConsent = true;
		loadScript();

		expect( L.map ).toHaveBeenCalledTimes( 1 );
		expect( mapEl().hidden ).toBe( false );
	} );

	it( 'draws when consent fires before the page finished loading', () => {
		const L = stubLeaflet();
		printArchive( true );
		const readyState = jest
			.spyOn( document, 'readyState', 'get' )
			.mockReturnValue( 'loading' );
		loadScript();

		// A consent tool answers without setting window.pkiwMapConsent.
		document.dispatchEvent( new Event( 'pkiw:map-consent' ) );
		readyState.mockReturnValue( 'interactive' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

		expect( L.map ).toHaveBeenCalledTimes( 1 );
		expect( mapEl().hidden ).toBe( false );
	} );
} );

describe( 'check-in archive map without the consent marker', () => {
	it( 'draws when the page loads, as before', () => {
		const L = stubLeaflet();
		printArchive( false );
		loadScript();

		expect( L.map ).toHaveBeenCalledTimes( 1 );
		expect( L.tileLayer ).toHaveBeenCalledTimes( 1 );
		expect( mapEl().hidden ).toBe( false );
	} );
} );
