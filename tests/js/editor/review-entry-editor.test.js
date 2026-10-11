/**
 * The review entry editor registration (assets/js/review-entry-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/review-entry-editor.js';

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
			PanelBody: component( 'PanelBody' ),
			ToggleControl: component( 'ToggleControl' ),
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

function edit( context = {}, attributes = {}, setAttributes = () => {} ) {
	const [ , settings ] = register();
	return settings.edit( {
		attributes: Object.assign(
			{
				showSections: true,
				headingLevel: 2,
				linesPerPage: 9,
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

describe( 'review entry editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered review entry block', () => {
		const [ name, settings ] = register();

		expect( name ).toBe( 'post-kinds-indieweb/review-entry' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.icon ).toBe( 'star-filled' );
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

	it( 'registers the grouped archive defaults', () => {
		const [ , settings ] = register();

		expect( settings.attributes.showSections.default ).toBe( true );
		expect( settings.attributes.headingLevel.default ).toBe( 2 );
		expect( settings.attributes.linesPerPage.default ).toBe( 9 );
		expect( settings.attributes.sectionOrder.default ).toBe( 'asc' );
		expect( settings.attributes.emptyGroup.default ).toBe( 'last' );
		expect( settings.attributes.emptyLabel.default ).toBe( '' );
	} );

	it( 'shows a placement notice with no post context', () => {
		const element = edit( {} );

		expect( JSON.stringify( element ) ).toContain(
			'Review entry: place this block inside a Query Loop’s Post Template.'
		);
		expect( findAll( element, 'ServerSideRender' ) ).toHaveLength( 0 );
	} );

	it.each( [ 'taxonomy-kind-review', 'taxonomy-kind', undefined ] )(
		'always previews as review for template slug %s',
		( templateSlug ) => {
			const render = findAll(
				edit( { postId: 12, templateSlug } ),
				'ServerSideRender'
			)[ 0 ];

			expect( render.props.block ).toBe(
				'post-kinds-indieweb/review-entry'
			);
			expect( render.props.urlQueryArgs ).toEqual( {
				post_id: 12,
				pkiw_kind: 'review',
			} );
		}
	);

	it( 'sets lines per page from one to fifty', () => {
		const setAttributes = jest.fn();
		const range = findAll(
			edit( { postId: 12 }, { linesPerPage: 9 }, setAttributes ),
			'RangeControl'
		)[ 0 ];

		expect( range.props.min ).toBe( 1 );
		expect( range.props.max ).toBe( 50 );
		range.props.onChange( 12 );
		expect( setAttributes ).toHaveBeenCalledWith( {
			linesPerPage: 12,
		} );
	} );

	it( 'shows section controls only when section headings are enabled', () => {
		const shown = edit( { postId: 12 }, { showSections: true } );
		const hidden = edit( { postId: 12 }, { showSections: false } );

		expect(
			findAll( shown, 'SelectControl' ).map(
				( control ) => control.props.label
			)
		).toEqual( [ 'Section order', 'Reviews with no category' ] );
		expect( findAll( shown, 'TextControl' ) ).toHaveLength( 1 );
		expect( findAll( hidden, 'SelectControl' ) ).toHaveLength( 0 );
		expect( findAll( hidden, 'TextControl' ) ).toHaveLength( 0 );
	} );

	it( 'wires section and empty-group attribute controls', () => {
		const setAttributes = jest.fn();
		const element = edit( { postId: 12 }, {}, setAttributes );
		const selects = findAll( element, 'SelectControl' );
		const text = findAll( element, 'TextControl' )[ 0 ];

		expect(
			selects[ 0 ].props.options.map( ( option ) => option.label )
		).toEqual( [ 'A to Z', 'Z to A' ] );
		selects[ 0 ].props.onChange( 'desc' );
		expect( setAttributes ).toHaveBeenCalledWith( {
			sectionOrder: 'desc',
		} );
		expect(
			selects[ 1 ].props.options.map( ( option ) => option.label )
		).toEqual( [
			'After the other sections',
			'Before the other sections',
		] );
		selects[ 1 ].props.onChange( 'first' );
		expect( setAttributes ).toHaveBeenCalledWith( {
			emptyGroup: 'first',
		} );
		expect( text.props.help ).toBe( 'Empty uses “Other”.' );
		text.props.onChange( 'Unfiled' );
		expect( setAttributes ).toHaveBeenCalledWith( {
			emptyLabel: 'Unfiled',
		} );
	} );
} );
