/**
 * Editor registration of the server-rendered blocks that ship as plain scripts
 * (assets/js/stream-card-editor.js, assets/js/recipe-courses-editor.js).
 *
 * Each test hands the script a stand-in for `window.wp` and inspects what the
 * edit function returns.
 */

/**
 * Load a plain editor script and return the settings it registers per block name.
 *
 * @param {string} file Path to the script.
 * @return {Object} Block settings keyed by block name.
 */
function register( file ) {
	const registered = {};
	window.wp = {
		blocks: {
			registerBlockType: ( name, settings ) => {
				registered[ name ] = settings;
			},
		},
		serverSideRender: 'ServerSideRender',
		element: {
			createElement: ( type, props, ...children ) => ( {
				type,
				props,
				children,
			} ),
		},
		i18n: { __: ( text ) => text },
		blockEditor: { useBlockProps: ( props ) => props },
		components: { Disabled: 'Disabled' },
	};
	jest.isolateModules( () => {
		require( file );
	} );
	return registered;
}

/**
 * Find the first element of a type in a stand-in element tree.
 *
 * @param {Object} element Element to search.
 * @param {string} type    Type to find.
 * @return {Object|undefined} The element, if any.
 */
function find( element, type ) {
	if ( ! element || 'object' !== typeof element ) {
		return undefined;
	}
	if ( element.type === type ) {
		return element;
	}
	for ( const child of element.children || [] ) {
		const found = find( child, type );
		if ( found ) {
			return found;
		}
	}
	return undefined;
}

afterEach( () => {
	delete window.wp;
} );

describe( 'stream card in the editor', () => {
	const NAME = 'post-kinds-indieweb/stream-card';

	it( 'asks the server for the card with the block’s own attributes', () => {
		const { edit } = register( '../../../assets/js/stream-card-editor.js' )[
			NAME
		];
		const attributes = { headingLevel: 2, className: 'is-style-example' };
		const render = find(
			edit( { context: { postId: 5 }, attributes } ),
			'ServerSideRender'
		);

		expect( render.props.block ).toBe( NAME );
		expect( render.props.attributes ).toEqual( attributes );
		expect( render.props.urlQueryArgs ).toEqual( { post_id: 5 } );
	} );

	it( 'shows a placeholder outside a Query Loop', () => {
		const { edit } = register( '../../../assets/js/stream-card-editor.js' )[
			NAME
		];

		expect(
			find( edit( { context: {}, attributes: {} } ), 'ServerSideRender' )
		).toBeUndefined();
	} );
} );

describe( 'recipe courses in the editor', () => {
	const NAME = 'post-kinds-indieweb/recipe-courses';

	it( 'registers the block for the server to render', () => {
		const settings = register(
			'../../../assets/js/recipe-courses-editor.js'
		)[ NAME ];

		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.save() ).toBeNull();
	} );

	it( 'prints the server’s links with the block’s attributes, and keeps them from navigating', () => {
		const { edit } = register(
			'../../../assets/js/recipe-courses-editor.js'
		)[ NAME ];
		const attributes = { className: 'example-tabs' };
		const tree = edit( { attributes } );
		const disabled = find( tree, 'Disabled' );
		const render = find( disabled, 'ServerSideRender' );

		expect( render.props.block ).toBe( NAME );
		expect( render.props.attributes ).toEqual( attributes );
	} );
} );

describe( 'recent specials in the editor', () => {
	const NAME = 'post-kinds-indieweb/menu-specials';
	const FILE = '../../../assets/js/menu-specials-editor.js';

	it( 'registers the block for the server to render', () => {
		const settings = register( FILE )[ NAME ];

		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.save() ).toBeNull();
	} );

	it( 'prints the server’s specials with the block’s attributes, and keeps the links from navigating', () => {
		const { edit } = register( FILE )[ NAME ];
		const attributes = { kind: 'eat', count: 3, showPhotos: false };
		const disabled = find( edit( { attributes } ), 'Disabled' );
		const render = find( disabled, 'ServerSideRender' );

		expect( render.props.block ).toBe( NAME );
		expect( render.props.attributes ).toEqual( attributes );
	} );
} );
