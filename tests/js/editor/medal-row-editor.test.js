/**
 * The medal row editor registration (assets/js/medal-row-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/medal-row-editor.js';

/**
 * Load the script and return the block name and settings it registers.
 *
 * @param {Object} overrides Parts of the stand-in to replace.
 * @return {jest.Mock} registerBlockType.
 */
function register( overrides = {} ) {
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
			RangeControl: component( 'RangeControl' ),
			SelectControl: component( 'SelectControl' ),
			TextControl: component( 'TextControl' ),
		},
		...overrides,
	};
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
	return registerBlockType;
}

function registered() {
	const registerBlockType = register();
	expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
	return registerBlockType.mock.calls[ 0 ];
}

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
	const [ , settings ] = registered();
	return settings.edit( {
		attributes: Object.assign(
			{ count: 3, headingLevel: 2, heading: '' },
			attributes
		),
		setAttributes,
	} );
}

describe( 'medal row editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered block', () => {
		const [ name, settings ] = registered();

		expect( name ).toBe( 'post-kinds-indieweb/medal-row' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.supports ).toEqual( { html: false, reusable: false } );
		expect( settings.save() ).toBeNull();
	} );

	it( 'does nothing without the server renderer or the components', () => {
		expect(
			register( { serverSideRender: undefined } )
		).not.toHaveBeenCalled();
		expect( register( { components: undefined } ) ).not.toHaveBeenCalled();
	} );

	it( 'previews the medals with the block attributes, behind Disabled', () => {
		const element = edit( { count: 4 } );
		const disabled = findAll( element, 'Disabled' );
		const render = findAll( element, 'ServerSideRender' );

		expect( disabled ).toHaveLength( 1 );
		expect( render ).toHaveLength( 1 );
		expect( findAll( disabled[ 0 ], 'ServerSideRender' ) ).toHaveLength(
			1
		);
		expect( render[ 0 ].props.block ).toBe(
			'post-kinds-indieweb/medal-row'
		);
		expect( render[ 0 ].props.attributes ).toEqual( {
			count: 4,
			headingLevel: 2,
			heading: '',
		} );
	} );

	it( 'says why the canvas is empty when no workout qualifies', () => {
		const render = findAll( edit(), 'ServerSideRender' )[ 0 ];
		const placeholder = render.props.EmptyResponsePlaceholder();

		expect( JSON.stringify( placeholder ) ).toContain(
			'No personal bests yet'
		);
		expect( JSON.stringify( placeholder ) ).not.toMatch(
			/public|private/i
		);
	} );

	it( 'sets how many medals within one to six', () => {
		const setAttributes = jest.fn();
		const range = findAll(
			edit( { count: 5 }, setAttributes ),
			'RangeControl'
		)[ 0 ];

		expect( range.props.min ).toBe( 1 );
		expect( range.props.max ).toBe( 6 );
		expect( range.props.value ).toBe( 5 );
		range.props.onChange( 2 );
		expect( setAttributes ).toHaveBeenCalledWith( { count: 2 } );
	} );

	it( 'sets the heading level as a number from 2 to 5', () => {
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
			'5',
		] );
		select.props.onChange( '4' );
		expect( setAttributes ).toHaveBeenCalledWith( { headingLevel: 4 } );
	} );

	it( 'edits the heading text, with the default as its placeholder', () => {
		const setAttributes = jest.fn();
		const text = findAll(
			edit( { heading: 'Hall of fame' }, setAttributes ),
			'TextControl'
		)[ 0 ];

		expect( text.props.value ).toBe( 'Hall of fame' );
		expect( text.props.placeholder ).toBe( 'Personal bests' );
		text.props.onChange( 'Medals' );
		expect( setAttributes ).toHaveBeenCalledWith( { heading: 'Medals' } );
	} );
} );
