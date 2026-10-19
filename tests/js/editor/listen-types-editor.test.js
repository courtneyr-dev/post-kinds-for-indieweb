/**
 * The listen types editor registration.
 */

const SCRIPT = '../../../assets/js/listen-types-editor.js';

function register( overrides = {} ) {
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
		blockEditor: { useBlockProps: ( props ) => props || {} },
		components: { Disabled: 'Disabled' },
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

describe( 'listen types editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered block once', () => {
		const [ name, settings ] = registered();

		expect( name ).toBe( 'post-kinds-indieweb/listen-types' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.supports ).toEqual( {
			html: false,
			reusable: false,
		} );
		expect( settings.save() ).toBeNull();
	} );

	it.each( [
		[ 'server renderer', { serverSideRender: undefined } ],
		[ 'blocks API', { blocks: undefined } ],
	] )( 'does nothing without the %s', ( label, overrides ) => {
		const registerBlockType = register( overrides );

		expect( label ).toBeTruthy();
		expect( registerBlockType ).not.toHaveBeenCalled();
	} );

	it( 'renders the server preview inside Disabled', () => {
		const [ , settings ] = registered();
		const element = settings.edit( { attributes: {} } );
		const disabled = findAll( element, 'Disabled' );
		const renders = findAll( element, 'ServerSideRender' );

		expect( disabled ).toHaveLength( 1 );
		expect( findAll( disabled[ 0 ], 'ServerSideRender' ) ).toHaveLength(
			1
		);
		expect( renders ).toHaveLength( 1 );
		expect( renders[ 0 ].props.block ).toBe(
			'post-kinds-indieweb/listen-types'
		);
	} );
} );
