/**
 * Quote archive editor block registrations.
 */

const SCRIPT = '../../../assets/js/quote-index-editor.js';

function component( name ) {
	return name;
}

function load( withServerSideRender = true ) {
	const registerBlockType = jest.fn();
	window.wp = {
		blocks: { registerBlockType },
		serverSideRender: withServerSideRender ? 'ServerSideRender' : undefined,
		element: {
			createElement: ( type, props, ...children ) => ( {
				type,
				props: props || {},
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
			ToggleControl: component( 'ToggleControl' ),
			RangeControl: component( 'RangeControl' ),
			SelectControl: component( 'SelectControl' ),
			TextControl: component( 'TextControl' ),
		},
	};
	jest.isolateModules( () => require( SCRIPT ) );

	return registerBlockType;
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

describe( 'quote archive editor', () => {
	afterEach( () => {
		delete window.wp;
		jest.resetModules();
	} );

	it( 'registers the entry, authors, and works blocks with PHP defaults', () => {
		const registerBlockType = load();
		expect( registerBlockType ).toHaveBeenCalledTimes( 3 );
		const registrations = Object.fromEntries(
			registerBlockType.mock.calls.map( ( [ name, settings ] ) => [
				name,
				settings,
			] )
		);
		const entry = registrations[ 'post-kinds-indieweb/quote-entry' ];
		expect( entry.attributes.linesPerPage.default ).toBe( 8 );
		expect( entry.attributes.headingLevel.default ).toBe( 3 );
		expect( entry.attributes.showSections.default ).toBe( true );
		expect( entry.attributes.sectionOrder.default ).toBe( 'asc' );
		expect( entry.attributes.emptyGroup.default ).toBe( 'last' );
		expect( entry.attributes.emptyLabel.default ).toBe( '' );
		expect(
			registrations[ 'post-kinds-indieweb/quote-authors' ].attributes
				.heading.default
		).toBe( 'By author' );
		expect(
			registrations[ 'post-kinds-indieweb/quote-works' ].attributes
				.heading.default
		).toBe( 'By source work' );
	} );

	it( 'passes the post and quote kind to the entry server preview', () => {
		const registerBlockType = load();
		const entry = registerBlockType.mock.calls[ 0 ][ 1 ];
		const tree = entry.edit( {
			attributes: { linesPerPage: 8 },
			context: { postId: 42 },
			setAttributes: jest.fn(),
		} );
		const preview = findAll( tree, 'ServerSideRender' )[ 0 ];
		expect( preview.props.block ).toBe( 'post-kinds-indieweb/quote-entry' );
		expect( preview.props.urlQueryArgs ).toEqual( {
			post_id: 42,
			pkiw_kind: 'quote',
		} );
	} );

	it( 'wraps both index previews in Disabled and exposes heading controls', () => {
		const registerBlockType = load();
		for ( const [ name, settings ] of registerBlockType.mock.calls.slice(
			1
		) ) {
			const tree = settings.edit( {
				attributes: { heading: 'Heading' },
				setAttributes: jest.fn(),
			} );
			expect( findAll( tree, 'Disabled' ) ).toHaveLength( 1 );
			expect( findAll( tree, 'TextControl' )[ 0 ].props.label ).toBe(
				'Heading'
			);
			expect( findAll( tree, 'ServerSideRender' )[ 0 ].props.block ).toBe(
				name
			);
		}
	} );

	it( 'does not register without ServerSideRender', () => {
		expect( load( false ) ).not.toHaveBeenCalled();
	} );
} );
