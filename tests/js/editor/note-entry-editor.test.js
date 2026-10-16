/**
 * The note entry editor registration (assets/js/note-entry-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/note-entry-editor.js';

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
		attributes: Object.assign( { headingLevel: 3 }, attributes ),
		context,
		setAttributes,
	} );
}

describe( 'note entry editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered note entry block once', () => {
		const [ name, settings ] = register();

		expect( name ).toBe( 'post-kinds-indieweb/note-entry' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.usesContext ).toEqual(
			expect.arrayContaining( [ 'templateSlug', 'postId' ] )
		);
		expect( settings.ancestor ).toEqual( [ 'core/post-template' ] );
		expect( settings.supports ).toEqual( {
			html: false,
			reusable: false,
		} );
		expect( settings.attributes.headingLevel.default ).toBe( 3 );
		expect( settings.save() ).toBeNull();
	} );

	it( 'previews the loop post and names the kind on a note template', () => {
		const render = findAll(
			edit( { postId: 14, templateSlug: 'taxonomy-kind-note' } ),
			'ServerSideRender'
		)[ 0 ];

		expect( render.props.block ).toBe( 'post-kinds-indieweb/note-entry' );
		expect( render.props.urlQueryArgs ).toEqual( {
			post_id: 14,
			pkiw_kind: 'note',
		} );
	} );

	it( 'sends only the post on a template that is not a kind archive', () => {
		const render = findAll(
			edit( { postId: 14, templateSlug: 'archive' } ),
			'ServerSideRender'
		)[ 0 ];

		expect( render.props.urlQueryArgs ).toEqual( { post_id: 14 } );
	} );

	it( 'wires the heading level control', () => {
		const setAttributes = jest.fn();
		const element = edit( { postId: 14 }, {}, setAttributes );

		findAll( element, 'SelectControl' )[ 0 ].props.onChange( '4' );

		expect( setAttributes ).toHaveBeenCalledWith( { headingLevel: 4 } );
	} );

	it( 'shows a placement notice with no post context', () => {
		const element = edit( {} );

		expect( findAll( element, 'ServerSideRender' ) ).toHaveLength( 0 );
		expect( textChildren( element ).join( ' ' ) ).toMatch(
			/Post Template/
		);
	} );

	it( 'does not bake note text into the editor output', () => {
		const element = edit( { postId: 14 } );

		expect( textChildren( element ) ).toEqual( [] );
	} );
} );
