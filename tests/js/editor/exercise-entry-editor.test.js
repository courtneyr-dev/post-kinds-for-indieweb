/**
 * The exercise entry editor registration (assets/js/exercise-entry-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/exercise-entry-editor.js';

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
	return settings.edit( {
		attributes: Object.assign(
			{
				showSections: true,
				headingLevel: 2,
				linesPerPage: 0,
				weeksPerPage: 4,
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

describe( 'exercise entry editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered exercise entry block once', () => {
		const [ name, settings ] = registered();

		expect( name ).toBe( 'post-kinds-indieweb/exercise-entry' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.usesContext ).toEqual(
			expect.arrayContaining( [ 'templateSlug', 'postId' ] )
		);
		expect( settings.ancestor ).toEqual( [ 'core/post-template' ] );
		expect( settings.supports ).toEqual( {
			html: false,
			reusable: false,
		} );
		expect( settings.save() ).toBeNull();
	} );

	it( 'registers the week defaults the PHP block declares', () => {
		const [ , settings ] = registered();

		expect( settings.attributes.weeksPerPage ).toEqual( {
			type: 'integer',
			default: 4,
		} );
		expect( settings.attributes.showSections.default ).toBe( true );
		expect( settings.attributes.headingLevel.default ).toBe( 2 );
		expect( settings.attributes.linesPerPage.default ).toBe( 0 );
	} );

	it( 'does nothing when the server renderer is missing', () => {
		const registerBlockType = register( { serverSideRender: undefined } );

		expect( registerBlockType ).not.toHaveBeenCalled();
	} );

	it( 'previews as exercise for the current post', () => {
		const render = findAll(
			edit( { postId: 14, templateSlug: 'taxonomy-kind-exercise' } ),
			'ServerSideRender'
		)[ 0 ];

		expect( render.props.block ).toBe(
			'post-kinds-indieweb/exercise-entry'
		);
		expect( render.props.urlQueryArgs ).toEqual( {
			post_id: 14,
			pkiw_kind: 'exercise',
		} );
		expect( render.props.attributes.weeksPerPage ).toBe( 4 );
	} );

	it( 'sets the weeks to a page from one to twelve', () => {
		const setAttributes = jest.fn();
		const range = findAll(
			edit( { postId: 14 }, { weeksPerPage: 6 }, setAttributes ),
			'RangeControl'
		);

		expect( range ).toHaveLength( 1 );
		expect( range[ 0 ].props.min ).toBe( 1 );
		expect( range[ 0 ].props.max ).toBe( 12 );
		expect( range[ 0 ].props.value ).toBe( 6 );
		range[ 0 ].props.onChange( 8 );
		expect( setAttributes ).toHaveBeenCalledWith( { weeksPerPage: 8 } );
	} );

	it( 'has no lines-per-page control, because the engine ignores it beside weeks', () => {
		const labels = findAll( edit( { postId: 14 } ), 'RangeControl' ).map(
			( control ) => control.props.label
		);

		expect( labels ).toEqual( [ 'Weeks per page' ] );
	} );

	it( 'wires the week headings toggle and the heading level', () => {
		const setAttributes = jest.fn();
		const element = edit( { postId: 14 }, {}, setAttributes );

		findAll( element, 'ToggleControl' )[ 0 ].props.onChange( false );
		expect( setAttributes ).toHaveBeenCalledWith( {
			showSections: false,
		} );

		findAll( element, 'SelectControl' )[ 0 ].props.onChange( '3' );
		expect( setAttributes ).toHaveBeenCalledWith( { headingLevel: 3 } );
	} );

	it( 'asks for a Query Loop when there is no post context', () => {
		const element = edit( {} );

		expect( findAll( element, 'ServerSideRender' ) ).toHaveLength( 0 );
		expect( textChildren( element ).join( ' ' ) ).toContain(
			'Post Template'
		);
	} );

	it( 'bakes no workout text or visibility wording into the canvas', () => {
		const element = edit( { postId: 14 } );

		expect( textChildren( element ) ).toEqual( [] );
		expect( JSON.stringify( element ) ).not.toMatch(
			/Lakeside|km|Public|private|heart|calorie/i
		);
	} );
} );
