/** Route drawing for the shared map view. */

const SCRIPT = '../../assets/js/map-view.js';

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
	const path = document.createElementNS(
		'http://www.w3.org/2000/svg',
		'path'
	);
	const polyline = { addTo: jest.fn(), getElement: () => path };
	window.L = {
		map: jest.fn( () => map ),
		tileLayer: jest.fn( () => ( { addTo: jest.fn() } ) ),
		polyline: jest.fn( () => polyline ),
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
	return { L: window.L, path, polyline };
}

function printMap() {
	const pins = [
		{
			lat: 40.1,
			lng: -75.1,
			ids: [ 244 ],
			numbers: [ 1 ],
			label: '1: Trip. Go to its list entry.',
		},
	];
	const routes = [
		{
			entry: 244,
			points: [
				[ 40.1, -75.1 ],
				[ 41.1, -76.1 ],
			],
		},
		{
			entry: 245,
			points: [
				[ 42.1, -77.1 ],
				[ 43.1, -78.1 ],
			],
		},
	];
	document.body.innerHTML = `
		<div class="pkiw-travel-archive">
			<figure class="pkiw-travel-map" id="pkiw-travel-map-1"
				data-pins='${ JSON.stringify( pins ) }'
				data-routes='${ JSON.stringify( routes ) }'
				data-tile-url="https://tiles.example/{z}/{x}/{y}.png"
				data-max-zoom="19" hidden></figure>
			<article class="pkiw-travel-entry" id="pkiw-travel-entry-244">
				<span class="pkiw-travel-map__num" data-map="pkiw-travel-map-1" data-entry="244" data-label="Show Trip on map">1</span>
				<a href="https://example.org/trip/">Trip</a>
			</article>
		</div>`;
}

afterEach( () => {
	delete window.L;
	document.body.innerHTML = '';
} );

it( 'draws one noninteractive token-colored polyline per route', () => {
	const stub = stubLeaflet();
	printMap();
	jest.isolateModules( () => require( SCRIPT ) );

	expect( stub.L.polyline ).toHaveBeenCalledTimes( 2 );
	expect( stub.L.polyline ).toHaveBeenNthCalledWith(
		1,
		[
			[ 40.1, -75.1 ],
			[ 41.1, -76.1 ],
		],
		{ className: 'pkiw-route', interactive: false }
	);
	expect( stub.L.polyline.mock.calls[ 0 ][ 1 ] ).not.toHaveProperty(
		'color'
	);
	expect( stub.polyline.addTo ).toHaveBeenCalledTimes( 2 );
	expect( stub.path.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
	expect( document.querySelector( '.pkiw-travel-map__num' ).tagName ).toBe(
		'BUTTON'
	);
} );
