/**
 * The Reactions → Check-ins admin screen draws its map from the tile layer
 * the server localizes (Checkin_Map::tile_layer(), so pkiw_map_tile_url and
 * pkiw_map_tile_attribution apply), and falls back to OpenStreetMap when
 * the object is missing.
 *
 * The script is a jQuery IIFE with a private Dashboard object, so the test
 * loads it against a small jQuery double and a fake Leaflet, then presses
 * the Map toggle the way an admin would.
 */

const SCRIPT = '../../assets/js/checkin-dashboard.js';

/**
 * Load the script and open its Map view.
 *
 * @return {Object} The fake Leaflet, for assertions.
 */
function openMapView() {
	const handlers = [];
	const chain = {
		length: 1,
		on: ( _event, handler ) => {
			handlers.push( handler );
			return chain;
		},
		html: () => chain,
		addClass: () => chain,
		removeClass: () => chain,
		data: () => 'map',
		val: () => '',
	};
	global.jQuery = ( arg ) =>
		arg === document ? { ready: ( callback ) => callback() } : chain;

	const layer = {
		addTo: jest.fn( () => layer ),
		clearLayers: jest.fn(),
		addLayer: jest.fn(),
	};
	const map = {
		setView: jest.fn( () => map ),
		addLayer: jest.fn(),
		fitBounds: jest.fn(),
		invalidateSize: jest.fn(),
	};
	global.L = {
		map: jest.fn( () => map ),
		tileLayer: jest.fn( () => layer ),
		layerGroup: jest.fn( () => layer ),
	};

	jest.isolateModules( () => {
		require( SCRIPT );
	} );

	// bindEvents() binds the view toggles first.
	handlers[ 0 ]( { preventDefault() {}, currentTarget: {} } );

	return global.L;
}

describe( 'admin Check-ins screen tile layer', () => {
	beforeEach( () => {
		global.reactionsCheckinDashboard = {
			restUrl: '/wp-json/post-kinds/v1/',
			nonce: 'nonce',
			i18n: { loading: 'Loading', noCheckins: 'None' },
		};
		// loadData() never resolves, so only the map view renders.
		global.fetch = jest.fn( () => new Promise( () => {} ) );
	} );

	afterEach( () => {
		delete window.pkiwCheckinDashboard;
		delete global.L;
		delete global.jQuery;
	} );

	it( 'uses the localized tile URL and attribution', () => {
		window.pkiwCheckinDashboard = {
			tileUrl: 'https://tiles.example.com/{z}/{x}/{y}.png',
			tileAttribution:
				'Tiles by <a href="https://example.com">Example</a>',
		};

		const leaflet = openMapView();

		expect( leaflet.tileLayer ).toHaveBeenCalledTimes( 1 );
		expect( leaflet.tileLayer ).toHaveBeenCalledWith(
			'https://tiles.example.com/{z}/{x}/{y}.png',
			expect.objectContaining( {
				attribution:
					'Tiles by <a href="https://example.com">Example</a>',
			} )
		);
	} );

	it( 'falls back to OpenStreetMap without the localized object', () => {
		const leaflet = openMapView();

		expect( leaflet.tileLayer ).toHaveBeenCalledWith(
			'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			expect.objectContaining( {
				attribution:
					'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			} )
		);
	} );
} );
