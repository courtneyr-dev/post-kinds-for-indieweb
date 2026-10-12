/**
 * The Reading time editor registration (assets/js/reading-time-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/reading-time-editor.js';

function component( name ) {
	return name;
}

function register() {
	const registerBlockType = jest.fn();
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
		},
		components: {
			Disabled: component( 'Disabled' ),
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

function edit( context = { postId: 123, postType: 'post' } ) {
	const [ , settings ] = register();
	return settings.edit( { attributes: {}, context } );
}

describe( 'reading time editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered block inside post templates', () => {
		const [ name, settings ] = register();

		expect( name ).toBe( 'post-kinds-indieweb/reading-time' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.ancestor ).toEqual( [ 'core/post-template' ] );
		expect( settings.usesContext ).toEqual( [ 'postId', 'postType' ] );
		expect( settings.save() ).toBeNull();
	} );

	it( 'previews the server render behind Disabled', () => {
		const element = edit();
		const disabled = findAll( element, 'Disabled' );
		const render = findAll( element, 'ServerSideRender' );

		expect( disabled ).toHaveLength( 1 );
		expect( render ).toHaveLength( 1 );
		expect( findAll( disabled[ 0 ], 'ServerSideRender' ) ).toHaveLength(
			1
		);
		expect( render[ 0 ].props.block ).toBe(
			'post-kinds-indieweb/reading-time'
		);
	} );

	it( 'passes the context post id to the block renderer request', () => {
		const render = findAll(
			edit( { postId: 456, postType: 'post' } ),
			'ServerSideRender'
		)[ 0 ];

		expect( render.props.urlQueryArgs ).toEqual( { post_id: 456 } );
	} );
} );
