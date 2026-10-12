/**
 * The chicken entry editor registration (assets/js/chicken-entry-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/chicken-entry-editor.js';

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
			PanelBody: component( 'PanelBody' ),
			ToggleControl: component( 'ToggleControl' ),
			RangeControl: component( 'RangeControl' ),
			SelectControl: component( 'SelectControl' ),
		},
	};
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
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

function textChildren( node, found = [] ) {
	if ( 'string' === typeof node ) {
		found.push( node );
		return found;
	}
	if ( ! node || 'object' !== typeof node ) {
		return found;
	}
	if ( Array.isArray( node ) ) {
		node.forEach( ( child ) => textChildren( child, found ) );
		return found;
	}
	( node.children || [] ).forEach( ( child ) =>
		textChildren( child, found )
	);
	return found;
}

function edit( context = {}, attributes = {}, setAttributes = () => {} ) {
	const [ , settings ] = register();
	return settings.edit( {
		attributes: Object.assign(
			{
				showSections: true,
				headingLevel: 2,
				linesPerPage: 24,
				sectionOrder: 'asc',
				emptyGroup: 'last',
				emptyLabel: '',
			},
			attributes
		),
		context,
		setAttributes,
	} );
}

describe( 'chicken entry editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered chicken entry block once', () => {
		const [ name, settings ] = register();

		expect( name ).toBe( 'post-kinds-indieweb/chicken-entry' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.usesContext ).toEqual(
			expect.arrayContaining( [ 'templateSlug', 'postId' ] )
		);
		expect( settings.ancestor ).toEqual( [ 'core/post-template' ] );
		expect( settings.supports ).toEqual( {
			html: false,
			reusable: false,
		} );
	} );

	it( 'registers the grouped archive defaults', () => {
		const [ , settings ] = register();

		expect( settings.attributes.showSections.default ).toBe( true );
		expect( settings.attributes.headingLevel.default ).toBe( 2 );
		expect( settings.attributes.linesPerPage.default ).toBe( 24 );
	} );

	it( 'previews as chicken for the current post', () => {
		const render = findAll(
			edit( { postId: 14, templateSlug: 'taxonomy-kind-chicken' } ),
			'ServerSideRender'
		)[ 0 ];

		expect( render.props.block ).toBe(
			'post-kinds-indieweb/chicken-entry'
		);
		expect( render.props.urlQueryArgs ).toEqual( {
			post_id: 14,
			pkiw_kind: 'chicken',
		} );
	} );

	it( 'wires the section heading level and line controls', () => {
		const setAttributes = jest.fn();
		const element = edit( { postId: 14 }, {}, setAttributes );

		findAll( element, 'ToggleControl' )[ 0 ].props.onChange( false );
		expect( setAttributes ).toHaveBeenCalledWith( {
			showSections: false,
		} );

		findAll( element, 'SelectControl' )[ 0 ].props.onChange( '3' );
		expect( setAttributes ).toHaveBeenCalledWith( {
			headingLevel: 3,
		} );

		findAll( element, 'RangeControl' )[ 0 ].props.onChange( 30 );
		expect( setAttributes ).toHaveBeenCalledWith( {
			linesPerPage: 30,
		} );
	} );

	it( 'shows a placement notice with no post context', () => {
		const element = edit( {} );

		expect( findAll( element, 'ServerSideRender' ) ).toHaveLength( 0 );
	} );

	it( 'does not bake chicken item text into the editor output', () => {
		const element = edit( { postId: 14 } );

		// Labels sit in control props; the only child text is none at all.
		expect( textChildren( element ) ).toEqual( [] );
		expect( JSON.stringify( element ) ).not.toMatch(
			/Hen at|Backyard|Coop|visitor/
		);
	} );
} );
