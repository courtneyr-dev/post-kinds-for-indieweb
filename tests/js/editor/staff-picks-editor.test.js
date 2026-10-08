/**
 * The Staff Picks editor registration (assets/js/staff-picks-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/staff-picks-editor.js';

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
			RangeControl: component( 'RangeControl' ),
			SelectControl: component( 'SelectControl' ),
			TextControl: component( 'TextControl' ),
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
			{ count: 3, headingLevel: 2, heading: '' },
			attributes
		),
		setAttributes,
	} );
}

describe( 'staff picks editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered block', () => {
		const [ name, settings ] = register();

		expect( name ).toBe( 'post-kinds-indieweb/staff-picks' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.supports ).toEqual( { html: false, reusable: false } );
		expect( settings.save() ).toBeNull();
	} );

	it( 'previews the picks with the block attributes, behind Disabled', () => {
		const element = edit( { count: 4 } );
		const disabled = findAll( element, 'Disabled' );
		const render = findAll( element, 'ServerSideRender' );

		expect( disabled ).toHaveLength( 1 );
		expect( render ).toHaveLength( 1 );
		expect( findAll( disabled[ 0 ], 'ServerSideRender' ) ).toHaveLength(
			1
		);
		expect( render[ 0 ].props.block ).toBe(
			'post-kinds-indieweb/staff-picks'
		);
		expect( render[ 0 ].props.attributes ).toEqual( {
			count: 4,
			headingLevel: 2,
			heading: '',
		} );
	} );

	it( 'says why the canvas is empty when no play qualifies', () => {
		const render = findAll( edit(), 'ServerSideRender' )[ 0 ];
		const placeholder = render.props.EmptyResponsePlaceholder();

		expect( JSON.stringify( placeholder ) ).toContain(
			'No rated board game plays yet'
		);
	} );

	it( 'sets how many picks within one to six', () => {
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
			edit( { heading: 'Our favorites' }, setAttributes ),
			'TextControl'
		)[ 0 ];

		expect( text.props.value ).toBe( 'Our favorites' );
		expect( text.props.placeholder ).toBe( 'Staff Picks' );
		text.props.onChange( 'Top of the table' );
		expect( setAttributes ).toHaveBeenCalledWith( {
			heading: 'Top of the table',
		} );
	} );
} );
