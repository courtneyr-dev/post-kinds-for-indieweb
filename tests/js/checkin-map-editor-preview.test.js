/**
 * Check-in archive map in the block editor (assets/js/checkin-map.js).
 *
 * The editor previews the Check-ins Feed through the block renderer, so the
 * archive markup lands in the canvas after the map script has run, and again
 * whenever the preview reloads. With window.pkiwCheckinMapWatch set, which
 * the editor enqueue prints, the script draws each map as it arrives, behind
 * the same consent rule as on the front end.
 */

const SCRIPT = '../../assets/js/checkin-map.js';

// Every observer the script starts, so each test can stop them.
const observers = [];
const RealObserver = window.MutationObserver;
window.MutationObserver = class extends RealObserver {
	constructor( callback ) {
		super( callback );
		observers.push( this );
	}
};

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
 * The archive markup Checkin_Map::render_archive() prints.
 *
 * @param {string}  mapId           Map element id.
 * @param {boolean} requiresConsent Whether the map carries the marker.
 * @return {string} HTML.
 */
function archive( mapId, requiresConsent = false ) {
	const pins = JSON.stringify( [
		{
			lat: 40.1,
			lng: -75.1,
			ids: [ 5 ],
			numbers: [ 1 ],
			label: '1: Coffee. Go to its list entry.',
		},
	] );
	return `
		<div class="pkiw-checkin-archive">
			<div class="pkiw-checkin-archive__map" id="${ mapId }"
				data-pins='${ pins }'
				data-tile-url="https://tiles.example/{z}/{x}/{y}.png"
				data-attribution="Example"
				data-max-zoom="19"
				${ requiresConsent ? 'data-pkiw-consent="required"' : '' }
				hidden></div>
			<ul class="pkiw-checkin-archive__entries">
				<li class="pkiw-checkin-archive__entry" id="pkiw-checkin-entry-5">
					<span class="pkiw-checkin-archive__num" data-map="${ mapId }"
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

// What ServerSideRender does when the block renderer answers.
function preview( html ) {
	const container = document.createElement( 'div' );
	container.className = 'block-editor-server-side-render';
	container.innerHTML = html;
	document.body.appendChild( container );
	return container;
}

// Mutation observers report in a microtask.
const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const mapEl = ( id ) => document.getElementById( id );
const listNumber = ( root ) =>
	root.querySelector( '.pkiw-checkin-archive__num' );

afterEach( () => {
	observers.splice( 0 ).forEach( ( observer ) => observer.disconnect() );
	jest.restoreAllMocks();
	delete window.L;
	delete window.pkiwMapConsent;
	delete window.pkiwCheckinMapWatch;
	document.body.innerHTML = '';
} );

describe( 'check-in archive map in the editor canvas', () => {
	it( 'draws a map the preview adds after the script ran', async () => {
		const L = stubLeaflet();
		window.pkiwCheckinMapWatch = true;
		loadScript();
		expect( L.map ).not.toHaveBeenCalled();

		const container = preview( archive( 'pkiw-checkin-map-7' ) );
		await settle();

		expect( L.map ).toHaveBeenCalledTimes( 1 );
		expect( L.map ).toHaveBeenCalledWith(
			mapEl( 'pkiw-checkin-map-7' ),
			expect.any( Object )
		);
		expect( L.tileLayer ).toHaveBeenCalledWith(
			'https://tiles.example/{z}/{x}/{y}.png',
			expect.any( Object )
		);
		expect( mapEl( 'pkiw-checkin-map-7' ).hidden ).toBe( false );
		expect( listNumber( container ).tagName ).toBe( 'BUTTON' );
	} );

	it( 'draws the new map when the preview reloads', async () => {
		const L = stubLeaflet();
		window.pkiwCheckinMapWatch = true;
		loadScript();

		const container = preview( archive( 'pkiw-checkin-map-7' ) );
		await settle();
		container.innerHTML = archive( 'pkiw-checkin-map-8' );
		await settle();

		expect( L.map ).toHaveBeenCalledTimes( 2 );
		expect( L.map.mock.calls[ 1 ][ 0 ] ).toBe(
			mapEl( 'pkiw-checkin-map-8' )
		);
		expect( mapEl( 'pkiw-checkin-map-8' ).hidden ).toBe( false );
	} );

	it( 'draws a map that was already there once, not again on later changes', async () => {
		const L = stubLeaflet();
		window.pkiwCheckinMapWatch = true;
		preview( archive( 'pkiw-checkin-map-7' ) );
		loadScript();

		expect( L.map ).toHaveBeenCalledTimes( 1 );

		// Leaflet and the list buttons add nodes inside the archive.
		mapEl( 'pkiw-checkin-map-7' ).appendChild(
			document.createElement( 'div' )
		);
		preview( '<p>Another block</p>' );
		await settle();

		expect( L.map ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'waits for consent on a map the preview adds, as on the front end', async () => {
		const L = stubLeaflet();
		window.pkiwCheckinMapWatch = true;
		loadScript();

		const container = preview( archive( 'pkiw-checkin-map-7', true ) );
		await settle();

		expect( L.map ).not.toHaveBeenCalled();
		expect( L.tileLayer ).not.toHaveBeenCalled();
		expect( mapEl( 'pkiw-checkin-map-7' ).hidden ).toBe( true );
		expect( listNumber( container ).tagName ).toBe( 'SPAN' );

		document.dispatchEvent( new Event( 'pkiw:map-consent' ) );

		expect( L.map ).toHaveBeenCalledTimes( 1 );
		expect( mapEl( 'pkiw-checkin-map-7' ).hidden ).toBe( false );
	} );

	it( 'skips a waiting map the reloaded preview removed', async () => {
		const L = stubLeaflet();
		window.pkiwCheckinMapWatch = true;
		loadScript();

		const container = preview( archive( 'pkiw-checkin-map-7', true ) );
		await settle();
		container.innerHTML = archive( 'pkiw-checkin-map-8', true );
		await settle();
		document.dispatchEvent( new Event( 'pkiw:map-consent' ) );

		expect( L.map ).toHaveBeenCalledTimes( 1 );
		expect( L.map.mock.calls[ 0 ][ 0 ] ).toBe(
			mapEl( 'pkiw-checkin-map-8' )
		);
	} );

	it( 'draws a map the preview adds after consent was given', async () => {
		const L = stubLeaflet();
		window.pkiwCheckinMapWatch = true;
		loadScript();
		document.dispatchEvent( new Event( 'pkiw:map-consent' ) );

		preview( archive( 'pkiw-checkin-map-7', true ) );
		await settle();

		expect( L.map ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'check-in archive map on the front end', () => {
	it( 'draws the maps on the page once and watches for nothing', async () => {
		const L = stubLeaflet();
		preview( archive( 'pkiw-checkin-map-7' ) );
		loadScript();

		preview( archive( 'pkiw-checkin-map-8' ) );
		await settle();

		expect( L.map ).toHaveBeenCalledTimes( 1 );
		expect( mapEl( 'pkiw-checkin-map-8' ).hidden ).toBe( true );
		expect( observers ).toHaveLength( 0 );
	} );
} );
