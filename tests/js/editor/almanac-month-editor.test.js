/**
 * The almanac month editor registration (assets/js/almanac-month-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/almanac-month-editor.js';

/**
 * Load the script and return the block name and settings it registers.
 *
 * @param {Object} overrides Parts of the `wp` stand-in to replace.
 * @return {Array} [ name, settings ], or [] when nothing registered.
 */
function register( overrides = {} ) {
	const registerBlockType = jest.fn();
	const component = ( name ) => name;
	window.wp = Object.assign(
		{
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
		},
		overrides
	);
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
	return registerBlockType.mock.calls[ 0 ] || [];
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

function edit( attributes = {}, setAttributes = () => {}, context = {} ) {
	const [ , settings ] = register();
	return settings.edit( {
		attributes: Object.assign(
			{ headingLevel: 2, showYearStrip: true },
			attributes
		),
		setAttributes,
		context,
	} );
}

describe( 'almanac month editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered block', () => {
		const [ name, settings ] = register();

		expect( name ).toBe( 'post-kinds-indieweb/almanac-month' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.supports ).toEqual( { html: false, reusable: false } );
		expect( settings.usesContext ).toEqual( [ 'templateSlug' ] );
		expect( settings.save() ).toBeNull();
	} );

	it( 'registers nothing without the server-side render component', () => {
		const [ name ] = register( { serverSideRender: undefined } );

		expect( name ).toBeUndefined();
	} );

	it( 'previews the month with the block attributes, behind Disabled', () => {
		const element = edit( { headingLevel: 3, showYearStrip: false } );
		const disabled = findAll( element, 'Disabled' );
		const render = findAll( element, 'ServerSideRender' );

		expect( disabled ).toHaveLength( 1 );
		expect( findAll( disabled[ 0 ], 'ServerSideRender' ) ).toHaveLength(
			1
		);
		expect( render ).toHaveLength( 1 );
		expect( render[ 0 ].props.block ).toBe(
			'post-kinds-indieweb/almanac-month'
		);
		expect( render[ 0 ].props.attributes ).toEqual( {
			headingLevel: 3,
			showYearStrip: false,
		} );
	} );

	it( 'sends the template kind with the preview request', () => {
		const render = findAll(
			edit( {}, () => {}, { templateSlug: 'taxonomy-kind-weather' } ),
			'ServerSideRender'
		)[ 0 ];

		expect( render.props.urlQueryArgs ).toEqual( {
			pkiw_kind: 'weather',
		} );
	} );

	it( 'sends no kind from a template that is not a kind archive', () => {
		const archive = findAll(
			edit( {}, () => {}, { templateSlug: 'archive' } ),
			'ServerSideRender'
		)[ 0 ];
		const none = findAll( edit(), 'ServerSideRender' )[ 0 ];

		expect( archive.props.urlQueryArgs ).toEqual( {} );
		expect( none.props.urlQueryArgs ).toEqual( {} );
	} );

	it( 'says why the canvas is empty when no weather post exists', () => {
		const render = findAll( edit(), 'ServerSideRender' )[ 0 ];

		expect(
			JSON.stringify( render.props.EmptyResponsePlaceholder() )
		).toContain( 'No weather posts yet' );
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

	it( 'toggles the year strip', () => {
		const setAttributes = jest.fn();
		const toggle = findAll(
			edit( { showYearStrip: true }, setAttributes ),
			'ToggleControl'
		)[ 0 ];

		expect( toggle.props.label ).toBe( 'Show year strip' );
		expect( toggle.props.checked ).toBe( true );
		toggle.props.onChange( false );
		expect( setAttributes ).toHaveBeenCalledWith( {
			showYearStrip: false,
		} );
	} );

	it( 'reads a missing year strip attribute as on', () => {
		const toggle = findAll(
			edit( { showYearStrip: undefined } ),
			'ToggleControl'
		)[ 0 ];

		expect( toggle.props.checked ).toBe( true );
	} );
} );
