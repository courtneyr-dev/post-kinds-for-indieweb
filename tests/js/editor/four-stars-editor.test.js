/**
 * The Four Stars editor registration (assets/js/four-stars-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/four-stars-editor.js';

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
			{ count: 6, headingLevel: 2, heading: '' },
			attributes
		),
		setAttributes,
	} );
}

describe( 'four stars editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered block without JS-side defaults', () => {
		const [ name, settings ] = register();

		expect( name ).toBe( 'post-kinds-indieweb/four-stars' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.icon ).toBe( 'star-filled' );
		expect( settings.supports ).toEqual( {
			html: false,
			reusable: false,
		} );
		expect( settings.attributes ).toBeUndefined();
		expect( settings.save() ).toBeNull();
	} );

	it( 'previews the block through ServerSideRender inside Disabled', () => {
		const element = edit( { count: 4 } );
		const disabled = findAll( element, 'Disabled' );
		const render = findAll( element, 'ServerSideRender' );

		expect( disabled ).toHaveLength( 1 );
		expect( render ).toHaveLength( 1 );
		expect( findAll( disabled[ 0 ], 'ServerSideRender' ) ).toHaveLength(
			1
		);
		expect( render[ 0 ].props.block ).toBe(
			'post-kinds-indieweb/four-stars'
		);
		expect( render[ 0 ].props.attributes ).toEqual( {
			count: 4,
			headingLevel: 2,
			heading: '',
		} );
	} );

	it( 'says why the canvas is empty when no review qualifies', () => {
		const render = findAll( edit(), 'ServerSideRender' )[ 0 ];
		const placeholder = render.props.EmptyResponsePlaceholder();

		expect( placeholder.type ).toBe( 'p' );
		expect( placeholder.props.className ).toBe(
			'pkiw-four-stars-editor__empty'
		);
		expect( JSON.stringify( placeholder ) ).toContain(
			'No review is rated four out of five yet'
		);
	} );

	it( 'sets the heading text', () => {
		const setAttributes = jest.fn();
		const text = findAll(
			edit( { heading: 'Four star shelf' }, setAttributes ),
			'TextControl'
		)[ 0 ];

		expect( text.props.value ).toBe( 'Four star shelf' );
		expect( text.props.placeholder ).toBe( 'Four Stars' );
		text.props.onChange( 'Top reviews' );
		expect( setAttributes ).toHaveBeenCalledWith( {
			heading: 'Top reviews',
		} );
	} );

	it( 'sets heading level as a number from two to four', () => {
		const setAttributes = jest.fn();
		const select = findAll(
			edit( { headingLevel: 3 }, setAttributes ),
			'SelectControl'
		)[ 0 ];

		expect( select.props.value ).toBe( '3' );
		expect( select.props.options ).toEqual( [
			{ label: 'H2', value: '2' },
			{ label: 'H3', value: '3' },
			{ label: 'H4', value: '4' },
		] );
		select.props.onChange( '3' );
		expect( setAttributes ).toHaveBeenCalledWith( { headingLevel: 3 } );
	} );

	it( 'sets how many reviews from one to six', () => {
		const setAttributes = jest.fn();
		const range = findAll(
			edit( { count: 5 }, setAttributes ),
			'RangeControl'
		)[ 0 ];

		expect( range.props.min ).toBe( 1 );
		expect( range.props.max ).toBe( 6 );
		range.props.onChange( 2 );
		expect( setAttributes ).toHaveBeenCalledWith( { count: 2 } );
	} );
} );
