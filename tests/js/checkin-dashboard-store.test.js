/**
 * Check-in Dashboard Interactivity API store.
 */

const mockStore = jest.fn();
const mockGetContext = jest.fn();
const mockGetElement = jest.fn();

jest.mock(
	'@wordpress/interactivity',
	() => ( {
		store: mockStore,
		getContext: mockGetContext,
		getElement: mockGetElement,
	} ),
	{ virtual: true }
);

const SCRIPT = '../../src/blocks/checkin-dashboard/view.js';
const CHECKINS = [
	{
		latitude: '40.2',
		longitude: '-74.7',
		venue_name: 'Example Hall',
		address: '1 Example Way',
		permalink: 'https://example.com/checkin',
	},
];

let definition;

function loadStore() {
	mockStore.mockImplementation( ( namespace, config ) => {
		definition = config;
		return config;
	} );
	require( SCRIPT );
	return definition;
}

function mapElement( checkins = CHECKINS ) {
	const element = document.createElement( 'div' );
	element.className = 'checkin-map';
	if ( null !== checkins ) {
		element.dataset.checkins =
			typeof checkins === 'string'
				? checkins
				: JSON.stringify( checkins );
	}
	return element;
}

function dashboard( map = mapElement() ) {
	const root = document.createElement( 'div' );
	root.className = 'checkin-dashboard-frontend';
	const panel = document.createElement( 'div' );
	panel.className = 'checkin-view-map';
	if ( map ) {
		panel.appendChild( map );
	}
	root.appendChild( panel );
	document.body.appendChild( root );
	return { root, panel, map };
}

function stubLeaflet() {
	const map = {
		setView: jest.fn(),
		fitBounds: jest.fn(),
		addLayer: jest.fn(),
	};
	map.setView.mockReturnValue( map );
	const marker = { bindPopup: jest.fn() };
	const cluster = { addLayer: jest.fn() };
	window.L = {
		map: jest.fn( () => map ),
		tileLayer: jest.fn( () => ( { addTo: jest.fn() } ) ),
		marker: jest.fn( () => marker ),
		markerClusterGroup: jest.fn( () => cluster ),
		layerGroup: jest.fn( () => cluster ),
	};
	return { L: window.L, map, marker, cluster };
}

function watchMap( context, panel ) {
	mockGetContext.mockReturnValue( context );
	mockGetElement.mockReturnValue( { ref: panel } );
	definition.callbacks.drawMap();
}

describe( 'Check-in Dashboard store', () => {
	beforeEach( () => {
		jest.resetModules();
		jest.clearAllMocks();
		document.body.innerHTML = '';
		delete window.L;
		loadStore();
	} );

	afterEach( () => {
		delete window.L;
	} );

	it( 'registers the permanent namespace and store members', () => {
		expect( mockStore ).toHaveBeenCalledWith(
			'post-kinds-indieweb/checkin-dashboard',
			expect.objectContaining( {
				state: expect.any( Object ),
				actions: { selectView: expect.any( Function ) },
				callbacks: { drawMap: expect.any( Function ) },
			} )
		);
	} );

	it.each( [
		[ 'grid', 'grid', true ],
		[ 'map', 'grid', false ],
		[ 'timeline', 'timeline', true ],
	] )(
		'derives selection for view %s and name %s',
		( view, name, expected ) => {
			mockGetContext.mockReturnValue( { view, name } );
			expect( definition.state.isSelected ).toBe( expected );
		}
	);

	it( 'selects the button context view', () => {
		const context = { view: 'grid', name: 'timeline' };
		mockGetContext.mockReturnValue( context );

		definition.actions.selectView();

		expect( context.view ).toBe( 'timeline' );
	} );

	it( 'draws when the watched view becomes map and for an initial map view', () => {
		const first = dashboard();
		const second = dashboard();
		const { L } = stubLeaflet();

		watchMap( { view: 'grid', name: 'map' }, first.panel );
		expect( L.map ).not.toHaveBeenCalled();
		watchMap( { view: 'map', name: 'map' }, first.panel );
		watchMap( { view: 'map', name: 'map' }, second.panel );

		expect( L.map.mock.calls.map( ( call ) => call[ 0 ] ) ).toEqual( [
			first.map,
			second.map,
		] );
	} );

	it( 'draws each dashboard once when map is selected again', () => {
		const first = dashboard();
		const second = dashboard();
		const { L } = stubLeaflet();

		watchMap( { view: 'map', name: 'map' }, first.panel );
		watchMap( { view: 'map', name: 'map' }, second.panel );
		watchMap( { view: 'map', name: 'map' }, first.panel );
		watchMap( { view: 'map', name: 'map' }, second.panel );

		expect( L.map ).toHaveBeenCalledTimes( 2 );
	} );

	it.each( [
		[ 'missing container', null, true ],
		[ 'missing data', null, false ],
		[ 'bad JSON', '{bad', false ],
		[ 'empty list', [], false ],
	] )( 'returns silently for %s', ( label, data, removeContainer ) => {
		const fixture = dashboard(
			removeContainer ? null : mapElement( data )
		);
		const { L } = stubLeaflet();

		expect( () =>
			watchMap( { view: 'map', name: 'map' }, fixture.panel )
		).not.toThrow();
		expect( L.map ).not.toHaveBeenCalled();
	} );

	it( 'returns silently when Leaflet is unavailable', () => {
		const fixture = dashboard();

		expect( () =>
			watchMap( { view: 'map', name: 'map' }, fixture.panel )
		).not.toThrow();
	} );

	it( 'retries a failed map draw on a repeated Map selection', () => {
		const fixture = dashboard();
		const context = { view: 'grid', name: 'map' };
		mockGetContext.mockReturnValue( context );
		mockGetElement.mockReturnValue( { ref: fixture.root } );

		definition.actions.selectView();
		watchMap( context, fixture.panel );
		mockGetElement.mockReturnValue( { ref: fixture.root } );
		const { L } = stubLeaflet();
		definition.actions.selectView();
		definition.actions.selectView();

		expect( L.map ).toHaveBeenCalledTimes( 1 );
		expect( L.map ).toHaveBeenCalledWith( fixture.map );
	} );

	it( 'does not attempt a map draw from a Grid selection', () => {
		const fixture = dashboard();
		const context = { view: 'grid', name: 'grid' };
		mockGetContext.mockReturnValue( context );
		mockGetElement.mockReturnValue( { ref: fixture.root } );

		const spy = jest.spyOn( fixture.root, 'querySelector' );
		expect( () => definition.actions.selectView() ).not.toThrow();
		expect( context.view ).toBe( 'grid' );
		expect( spy ).not.toHaveBeenCalled();
	} );

	it( 'uses server tile settings and the OSM fallbacks', () => {
		const custom = dashboard();
		custom.map.dataset.tileUrl = 'https://tiles.example/{z}/{x}/{y}.png';
		custom.map.dataset.attribution = 'Example tiles';
		const fallback = dashboard();
		const { L } = stubLeaflet();

		watchMap( { view: 'map', name: 'map' }, custom.panel );
		watchMap( { view: 'map', name: 'map' }, fallback.panel );

		expect( L.tileLayer.mock.calls[ 0 ] ).toEqual( [
			'https://tiles.example/{z}/{x}/{y}.png',
			{ attribution: 'Example tiles' },
		] );
		expect( L.tileLayer.mock.calls[ 1 ] ).toEqual( [
			'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			{
				attribution:
					'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			},
		] );
	} );

	it( 'escapes marker popup content and quoted permalink attributes', () => {
		const fixture = dashboard(
			mapElement( [
				{
					latitude: '40.2',
					longitude: '-74.7',
					venue_name: '<b>x</b>',
					address: '<i>street</i>',
					permalink: 'https://example.com/?q="x"',
				},
			] )
		);
		const { L, marker } = stubLeaflet();

		watchMap( { view: 'map', name: 'map' }, fixture.panel );

		expect( L.marker ).toHaveBeenCalledWith( [ '40.2', '-74.7' ], {
			alt: '<b>x</b>',
		} );
		expect( marker.bindPopup ).toHaveBeenCalledWith(
			'<div class="checkin-popup"><strong>&lt;b&gt;x&lt;/b&gt;</strong><br><span>&lt;i&gt;street&lt;/i&gt;</span><br><a href="https://example.com/?q=&quot;x&quot;">View post: &lt;b&gt;x&lt;/b&gt;</a></div>'
		);
	} );

	it( 'falls back to a layer group when marker clustering is unavailable', () => {
		const fixture = dashboard();
		const { L, cluster } = stubLeaflet();
		delete L.markerClusterGroup;

		watchMap( { view: 'map', name: 'map' }, fixture.panel );

		expect( L.layerGroup ).toHaveBeenCalledTimes( 1 );
		expect( cluster.addLayer ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'adds the marker layer and fits bounds with the existing padding', () => {
		const fixture = dashboard();
		const { map, cluster } = stubLeaflet();

		watchMap( { view: 'map', name: 'map' }, fixture.panel );

		expect( map.addLayer ).toHaveBeenCalledWith( cluster );
		expect( map.fitBounds ).toHaveBeenCalledWith( [ [ '40.2', '-74.7' ] ], {
			padding: [ 50, 50 ],
		} );
	} );
} );
