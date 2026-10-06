/**
 * Keyboard use of the check-in archive map (assets/js/checkin-map.js).
 *
 * The vendored leaflet.markercluster 1.4.1 zooms a cluster on click only,
 * and Leaflet 1.9.4 delivers Enter or Space on a marker as `keypress`, which
 * markercluster forwards as `clusterkeypress`. Pins should also take focus
 * in list order, whatever order markercluster adds their elements in.
 */

const SCRIPT = '../../assets/js/checkin-map.js';

/**
 * A Leaflet and markercluster stand-in. Each pin's element lands in the
 * marker pane in reverse list order, as markercluster added them on dev.
 *
 * @param {(markers: Object[]) => Map} layout Maps clustered markers to their cluster.
 * @return {Object} Handles on the stub.
 */
function stubLeaflet( layout = () => new Map() ) {
	const handlers = { map: {}, group: {} };
	const pane = document.createElement( 'div' );
	const markers = [];
	const state = { zoom: 10, maxZoom: 19, parents: new Map() };

	const listen = ( bucket, once ) => ( type, fn ) => {
		( bucket[ type ] = bucket[ type ] || [] ).push( { fn, once } );
	};
	const fire = ( bucket, type, event ) => {
		const list = bucket[ type ] || [];
		bucket[ type ] = list.filter( ( handler ) => ! handler.once );
		list.forEach( ( handler ) => handler.fn( event ) );
	};

	const group = {
		addLayer: jest.fn(),
		zoomToShowLayer: jest.fn(),
		on: listen( handlers.group, false ),
		once: listen( handlers.group, true ),
		getVisibleParent: ( marker ) => state.parents.get( marker ) || marker,
	};

	const map = {
		attributionControl: { setPrefix: jest.fn() },
		setView: jest.fn(),
		fitBounds: jest.fn(),
		getZoom: () => state.zoom,
		getMaxZoom: () => state.maxZoom,
		getPane: () => pane,
		on: listen( handlers.map, false ),
		once: listen( handlers.map, true ),
		addLayer: jest.fn( () => {
			state.parents = layout( markers );
			[ ...markers ].reverse().forEach( ( marker ) => {
				const element = group.getVisibleParent( marker ).getElement();
				if ( element.parentNode !== pane ) {
					pane.appendChild( element );
				}
			} );
		} ),
	};

	window.L = {
		map: jest.fn( ( el ) => {
			el.appendChild( pane );
			return map;
		} ),
		tileLayer: jest.fn( () => ( { addTo: jest.fn() } ) ),
		divIcon: jest.fn( () => ( {} ) ),
		marker: jest.fn( () => {
			const element = document.createElement( 'div' );
			element.tabIndex = 0;
			const marker = {
				on: jest.fn(),
				getElement: () => element,
				getLatLng: () => [ 40.1, -75.1 ],
			};
			markers.push( marker );
			return marker;
		} ),
		latLngBounds: jest.fn(),
		markerClusterGroup: jest.fn( () => group ),
	};

	return {
		pane,
		markers,
		state,
		fireMap: ( type, event ) => fire( handlers.map, type, event ),
		fireGroup: ( type, event ) => fire( handlers.group, type, event ),
	};
}

/**
 * A cluster stand-in holding some of the markers.
 *
 * @param {Object[]} children Child markers, in markercluster's order.
 * @return {Object} The cluster.
 */
function cluster( children ) {
	const element = document.createElement( 'div' );
	element.tabIndex = 0;
	return {
		getElement: () => element,
		getAllChildMarkers: () => children,
		zoomToBounds: jest.fn(),
		spiderfy: jest.fn(),
	};
}

/**
 * Print an archive page with five mapped check-ins, numbered 1 to 5.
 */
function printArchive() {
	const pins = [ 1, 2, 3, 4, 5 ].map( ( number ) => ( {
		lat: 40 + number / 10,
		lng: -75,
		ids: [ number ],
		numbers: [ number ],
		label: `${ number }: Check-in ${ number }. Go to its list entry.`,
	} ) );
	document.body.innerHTML = `
		<div class="pkiw-checkin-archive">
			<div class="pkiw-checkin-archive__map" id="pkiw-checkin-map-1"
				data-pins='${ JSON.stringify( pins ) }'
				data-tile-url="https://tiles.example/{z}/{x}/{y}.png"
				data-max-zoom="19" hidden></div>
		</div>`;
}

function loadScript() {
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
}

/**
 * The marker elements in the pane, as list numbers (0 for anything else).
 *
 * @param {Object} stub Result of stubLeaflet().
 * @return {number[]} Numbers in DOM order.
 */
function paneOrder( stub ) {
	return Array.from( stub.pane.children ).map(
		( child ) =>
			stub.markers.findIndex(
				( marker ) => marker.getElement() === child
			) + 1
	);
}

/**
 * Split a cluster the way markercluster does after a zoom: the cluster's
 * element goes, its children's elements arrive in markercluster's order.
 *
 * @param {Object}   stub     Result of stubLeaflet().
 * @param {Object}   pin      The cluster.
 * @param {Object[]} children Its markers.
 */
function splitCluster( stub, pin, children ) {
	stub.state.parents = new Map();
	pin.getElement().remove();
	children.forEach( ( marker ) =>
		stub.pane.appendChild( marker.getElement() )
	);
}

afterEach( () => {
	delete window.L;
	document.body.innerHTML = '';
} );

describe( 'a cluster pin and the keyboard', () => {
	let stub;
	let clusterPin;
	let children;

	beforeEach( () => {
		printArchive();
		stub = stubLeaflet( ( markers ) => {
			// Check-ins 3 and 1 share a cluster, in markercluster's order.
			children = [ markers[ 2 ], markers[ 0 ] ];
			clusterPin = cluster( children );
			return new Map(
				children.map( ( marker ) => [ marker, clusterPin ] )
			);
		} );
		loadScript();
		clusterPin.getElement().focus();
	} );

	it.each( [ [ 'Enter' ], [ ' ' ] ] )(
		'%p zooms in and focuses the cluster’s first pin in list order',
		( key ) => {
			const preventDefault = jest.fn();
			stub.fireGroup( 'clusterkeypress', {
				layer: clusterPin,
				originalEvent: { key, preventDefault },
			} );

			expect( preventDefault ).toHaveBeenCalled();
			expect( clusterPin.zoomToBounds ).toHaveBeenCalledWith( {
				padding: [ 48, 48 ],
			} );

			splitCluster( stub, clusterPin, children );
			stub.fireMap( 'moveend' );

			expect( document.activeElement ).toBe(
				stub.markers[ 0 ].getElement()
			);
		}
	);

	it( 'ignores other keys', () => {
		const preventDefault = jest.fn();
		stub.fireGroup( 'clusterkeypress', {
			layer: clusterPin,
			originalEvent: { key: 'a', preventDefault },
		} );

		expect( clusterPin.zoomToBounds ).not.toHaveBeenCalled();
		expect( preventDefault ).not.toHaveBeenCalled();
	} );

	it( 'spreads the cluster at the maximum zoom and focuses its first pin', () => {
		stub.state.zoom = 19;
		stub.fireGroup( 'clusterkeypress', {
			layer: clusterPin,
			originalEvent: { key: 'Enter', preventDefault: jest.fn() },
		} );

		expect( clusterPin.spiderfy ).toHaveBeenCalled();
		expect( clusterPin.zoomToBounds ).not.toHaveBeenCalled();

		splitCluster( stub, clusterPin, children );
		stub.fireGroup( 'spiderfied' );

		expect( document.activeElement ).toBe( stub.markers[ 0 ].getElement() );
	} );

	it( 'puts the cluster where its first check-in sits in the tab order', () => {
		expect( stub.pane.firstElementChild ).toBe( clusterPin.getElement() );
		expect( paneOrder( stub ).slice( 1 ) ).toEqual( [ 2, 4, 5 ] );
	} );
} );

describe( 'pin tab order', () => {
	it( 'follows the list numbers', () => {
		printArchive();
		const stub = stubLeaflet();
		loadScript();

		expect( paneOrder( stub ) ).toEqual( [ 1, 2, 3, 4, 5 ] );
	} );

	it( 'is restored after a redraw, and the focused pin keeps focus', () => {
		printArchive();
		const stub = stubLeaflet();
		loadScript();

		const third = stub.markers[ 2 ].getElement();
		third.focus();
		stub.pane.prepend( stub.markers[ 4 ].getElement() );
		stub.fireMap( 'moveend' );

		expect( paneOrder( stub ) ).toEqual( [ 1, 2, 3, 4, 5 ] );
		expect( document.activeElement ).toBe( third );
	} );
} );
