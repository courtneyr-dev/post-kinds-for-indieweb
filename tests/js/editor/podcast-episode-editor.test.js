/**
 * The podcast episode editor registration.
 */

const SCRIPT = '../../../assets/js/podcast-episode-editor.js';

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
			PanelBody: component( 'PanelBody' ),
			ToggleControl: component( 'ToggleControl' ),
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
	const [ , settings ] = registered();
	const defaults = Object.fromEntries(
		Object.entries( settings.attributes ).map( ( [ key, value ] ) => [
			key,
			value.default,
		] )
	);
	return settings.edit( {
		attributes: { ...defaults, ...attributes },
		context,
		setAttributes,
	} );
}

describe( 'podcast episode editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered block once', () => {
		const [ name, settings ] = registered();

		expect( name ).toBe( 'post-kinds-indieweb/podcast-episode' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.ancestor ).toEqual( [ 'core/post-template' ] );
		expect( settings.usesContext ).toEqual( [
			'postId',
			'postType',
			'queryId',
			'templateSlug',
		] );
		expect( settings.supports ).toEqual( {
			html: false,
			reusable: false,
		} );
		expect( settings.save() ).toBeNull();
	} );

	it( 'registers the PHP block defaults', () => {
		const [ , settings ] = registered();

		expect( settings.attributes ).toMatchObject( {
			linesPerPage: { type: 'integer', default: 12 },
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
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

	it( 'asks for a Query Loop when there is no post context', () => {
		const element = edit();

		expect( findAll( element, 'ServerSideRender' ) ).toHaveLength( 0 );
		expect( textChildren( element ).join( ' ' ) ).toContain(
			'Podcast episodes: place this block inside a Query Loop’s Post Template.'
		);
	} );

	it( 'previews the podcast view for the current post', () => {
		const render = findAll(
			edit( {
				postId: 27,
				templateSlug: 'taxonomy-kind-listen-podcast',
			} ),
			'ServerSideRender'
		)[ 0 ];

		expect( render.props.block ).toBe(
			'post-kinds-indieweb/podcast-episode'
		);
		expect( render.props.urlQueryArgs ).toEqual( {
			post_id: 27,
			pkiw_kind: 'listen',
			pkiw_view: 'podcast',
		} );
		expect( render.props.attributes.linesPerPage ).toBe( 12 );
	} );

	it( 'wires every inspector control to its attribute', () => {
		const setAttributes = jest.fn();
		const element = edit( { postId: 27 }, {}, setAttributes );
		const range = findAll( element, 'RangeControl' )[ 0 ];
		const toggle = findAll( element, 'ToggleControl' )[ 0 ];
		const selects = findAll( element, 'SelectControl' );
		const text = findAll( element, 'TextControl' )[ 0 ];

		expect( range.props.label ).toBe( 'Lines per page' );
		expect( range.props.min ).toBe( 1 );
		expect( range.props.max ).toBe( 100 );
		range.props.onChange( 18 );
		toggle.props.onChange( false );
		selects
			.find( ( control ) => control.props.label === 'Heading level' )
			.props.onChange( '3' );
		selects
			.find( ( control ) => control.props.label === 'Station order' )
			.props.onChange( 'desc' );
		text.props.onChange( 'Unnamed shows' );

		expect( setAttributes.mock.calls ).toEqual( [
			[ { linesPerPage: 18 } ],
			[ { showSections: false } ],
			[ { headingLevel: 3 } ],
			[ { sectionOrder: 'desc' } ],
			[ { emptyLabel: 'Unnamed shows' } ],
		] );
	} );

	it( 'omits the empty-label control when TextControl is unavailable', () => {
		const [ , settings ] = ( () => {
			const registerBlockType = register( {
				components: {
					PanelBody: 'PanelBody',
					ToggleControl: 'ToggleControl',
					RangeControl: 'RangeControl',
					SelectControl: 'SelectControl',
				},
			} );
			return registerBlockType.mock.calls[ 0 ];
		} )();
		const element = settings.edit( {
			attributes: {
				linesPerPage: 12,
				showSections: true,
				headingLevel: 2,
				sectionOrder: 'asc',
				emptyGroup: 'last',
				emptyLabel: '',
			},
			context: { postId: 27 },
			setAttributes: jest.fn(),
		} );

		expect( findAll( element, 'TextControl' ) ).toHaveLength( 0 );
	} );
} );
