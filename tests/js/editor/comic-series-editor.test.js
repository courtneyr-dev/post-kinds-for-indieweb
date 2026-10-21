/**
 * The comic series editor registration.
 */

const SCRIPT = '../../../assets/js/comic-series-editor.js';
const BLOCK = 'post-kinds-indieweb/comic-series';

function register( overrides = {} ) {
	const registerBlockType = jest.fn();
	const component = ( name ) => name;
	window.wp = {
		blocks: { registerBlockType },
		serverSideRender: component( 'ServerSideRender' ),
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

function flatten( node ) {
	if ( ! node || 'object' !== typeof node ) {
		return [];
	}
	if ( Array.isArray( node ) ) {
		return node.flatMap( flatten );
	}
	return [ node, ...flatten( node.children ) ];
}

function control( tree, label ) {
	const found = tree.find(
		( node ) => 'TextControl' === node.type && label === node.props?.label
	);
	expect( found ).toBeDefined();
	return found;
}

describe( 'comic series editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the dynamic block and its label attributes', () => {
		const [ name, settings ] = registered();

		expect( name ).toBe( BLOCK );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.attributes ).toEqual( {
			label: { type: 'string', default: 'Series' },
			allLabel: { type: 'string', default: 'All series' },
		} );
		expect( settings.save() ).toBeNull();
	} );

	it( 'renders both label controls and writes only their attributes', () => {
		const [ , settings ] = registered();
		const setAttributes = jest.fn();
		const tree = flatten(
			settings.edit( {
				attributes: { label: 'Collections', allLabel: 'Everything' },
				setAttributes,
			} )
		);
		const panel = tree.find(
			( node ) =>
				'PanelBody' === node.type && 'Labels' === node.props?.title
		);

		expect( panel ).toBeDefined();
		expect( control( tree, 'Navigation label' ).props.value ).toBe(
			'Collections'
		);
		expect( control( tree, 'All series label' ).props.value ).toBe(
			'Everything'
		);

		control( tree, 'Navigation label' ).props.onChange( 'Story arcs' );
		control( tree, 'All series label' ).props.onChange( 'Every arc' );

		expect( setAttributes.mock.calls ).toEqual( [
			[ { label: 'Story arcs' } ],
			[ { allLabel: 'Every arc' } ],
		] );
	} );

	it( 'renders the server preview inside Disabled with current attributes', () => {
		const [ , settings ] = registered();
		const attributes = { label: 'Series', allLabel: 'All series' };
		const tree = flatten(
			settings.edit( { attributes, setAttributes: jest.fn() } )
		);
		const disabled = tree.find( ( node ) => 'Disabled' === node.type );
		const render = flatten( disabled ).find(
			( node ) => 'ServerSideRender' === node.type
		);

		expect( render.props ).toEqual( {
			block: BLOCK,
			attributes,
		} );
	} );

	it.each( [
		[ 'server renderer', { serverSideRender: undefined } ],
		[ 'blocks API', { blocks: undefined } ],
	] )( 'does nothing without the %s', ( label, overrides ) => {
		const registerBlockType = register( overrides );

		expect( label ).toBeTruthy();
		expect( registerBlockType ).not.toHaveBeenCalled();
	} );
} );
