/**
 * The mood year grid editor registration (assets/js/mood-year-grid-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/mood-year-grid-editor.js';

/**
 * Load the script and return the block name and settings it registers.
 *
 * @return {Array} [ name, settings ].
 */
function register() {
	const registerBlockType = jest.fn();
	const component = ( name ) => name;
	window.wp = {
		blocks: { registerBlockType },
		serverSideRender: 'ServerSideRender',
		element: {
			createElement: ( type, props, ...children ) => ( {
				type,
				props,
				children,
			} ),
		},
		i18n: { __: ( text ) => text },
		blockEditor: {
			useBlockProps: ( props ) => props || {},
			InspectorControls: component( 'InspectorControls' ),
		},
		components: {
			Disabled: component( 'Disabled' ),
			PanelBody: component( 'PanelBody' ),
			SelectControl: component( 'SelectControl' ),
			ToggleControl: component( 'ToggleControl' ),
		},
	};
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
	expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
	return registerBlockType.mock.calls[ 0 ];
}

// Every element of a type in a rendered tree, depth first.
function findAll( node, type, found = [] ) {
	if ( ! node || 'object' !== typeof node ) {
		return found;
	}
	if ( Array.isArray( node ) ) {
		node.forEach( ( child ) => findAll( child, type, found ) );
		return found;
	}
	if ( node.type === type ) {
		found.push( node );
	}
	( node.children || [] ).forEach( ( child ) =>
		findAll( child, type, found )
	);
	return found;
}

function edit( attributes = {}, setAttributes = () => {} ) {
	const [ , settings ] = register();
	return settings.edit( {
		attributes: Object.assign(
			{ headingLevel: 2, showLegend: true },
			attributes
		),
		setAttributes,
	} );
}

describe( 'mood year grid editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered block with its two attributes', () => {
		const [ name, settings ] = register();

		expect( name ).toBe( 'post-kinds-indieweb/mood-year-grid' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.supports ).toEqual( { html: false, reusable: false } );
		expect( settings.attributes ).toEqual( {
			headingLevel: { type: 'integer', default: 2 },
			showLegend: { type: 'boolean', default: true },
		} );
		expect( settings.save() ).toBeNull();
	} );

	it( 'does nothing when the editor globals are missing', () => {
		window.wp = {};
		expect( () => {
			jest.isolateModules( () => {
				require( SCRIPT );
			} );
		} ).not.toThrow();
	} );

	it( 'previews the grid with the block attributes, behind Disabled', () => {
		const element = edit( { headingLevel: 3, showLegend: false } );
		const disabled = findAll( element, 'Disabled' );
		const render = findAll( element, 'ServerSideRender' );

		expect( disabled ).toHaveLength( 1 );
		expect( render ).toHaveLength( 1 );
		expect( findAll( disabled[ 0 ], 'ServerSideRender' ) ).toHaveLength(
			1
		);
		expect( render[ 0 ].props.block ).toBe(
			'post-kinds-indieweb/mood-year-grid'
		);
		expect( render[ 0 ].props.attributes ).toEqual( {
			headingLevel: 3,
			showLegend: false,
		} );
	} );

	it( 'says why the canvas is empty when no mood is published', () => {
		const render = findAll( edit(), 'ServerSideRender' )[ 0 ];
		const placeholder = render.props.EmptyResponsePlaceholder();

		expect( JSON.stringify( placeholder ) ).toContain( 'No moods yet' );
	} );

	it( 'sets the heading level as a number from 2 to 4', () => {
		const setAttributes = jest.fn();
		const select = findAll(
			edit( { headingLevel: 3 }, setAttributes ),
			'SelectControl'
		)[ 0 ];

		expect( select.props.value ).toBe( '3' );
		expect( select.props.options.map( ( o ) => o.value ) ).toEqual( [
			'2',
			'3',
			'4',
		] );
		select.props.onChange( '4' );
		expect( setAttributes ).toHaveBeenCalledWith( { headingLevel: 4 } );
	} );

	it( 'toggles the legend, which is on unless set to false', () => {
		const setAttributes = jest.fn();
		const on = findAll( edit( {}, setAttributes ), 'ToggleControl' )[ 0 ];
		const off = findAll(
			edit( { showLegend: false } ),
			'ToggleControl'
		)[ 0 ];

		expect( on.props.checked ).toBe( true );
		expect( off.props.checked ).toBe( false );
		on.props.onChange( false );
		expect( setAttributes ).toHaveBeenCalledWith( { showLegend: false } );
		on.props.onChange( true );
		expect( setAttributes ).toHaveBeenCalledWith( { showLegend: true } );
	} );
} );
