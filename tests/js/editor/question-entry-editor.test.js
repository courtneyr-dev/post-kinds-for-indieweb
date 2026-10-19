/**
 * The question entry editor registration.
 */

const SCRIPT = '../../../assets/js/question-entry-editor.js';

function component( name ) {
	return name;
}

function load( options = {} ) {
	const registerBlockType = jest.fn();
	window.pkiwQuestionEntry = options.groups
		? { groups: options.groups }
		: undefined;
	window.wp = {
		blocks: { registerBlockType },
		serverSideRender:
			false === options.serverSideRender ? undefined : 'ServerSideRender',
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

function edit( settings, attributes = {}, setAttributes = jest.fn() ) {
	return settings.edit( {
		attributes: Object.assign(
			{
				showSections: true,
				headingLevel: 2,
				linesPerPage: 12,
				sectionOrder: 'asc',
				emptyGroup: 'last',
				emptyLabel: '',
				groupLabels: {},
			},
			attributes
		),
		context: { postId: 42, templateSlug: 'taxonomy-kind-question' },
		setAttributes,
	} );
}

describe( 'question entry editor', () => {
	afterEach( () => {
		delete window.wp;
		delete window.pkiwQuestionEntry;
		jest.resetModules();
	} );

	it( 'registers one server-rendered question entry block', () => {
		const registerBlockType = load( {
			groups: {
				answered: 'Answered',
				waiting: 'Waiting for an answer',
			},
		} );

		expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
		const [ name, settings ] = registerBlockType.mock.calls[ 0 ];
		expect( name ).toBe( 'post-kinds-indieweb/question-entry' );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.usesContext ).toEqual(
			expect.arrayContaining( [
				'postId',
				'postType',
				'queryId',
				'templateSlug',
			] )
		);
		expect( settings.supports ).toEqual( { html: false, reusable: false } );
		expect( settings.save() ).toBeNull();
	} );

	it( 'renders ServerSideRender with the block name and current attributes', () => {
		const registerBlockType = load( { groups: {} } );
		const settings = registerBlockType.mock.calls[ 0 ][ 1 ];
		const rendered = findAll(
			edit( settings, { linesPerPage: 18 } ),
			'ServerSideRender'
		);

		expect( rendered ).toHaveLength( 1 );
		expect( rendered[ 0 ].props.block ).toBe(
			'post-kinds-indieweb/question-entry'
		);
		expect( rendered[ 0 ].props.attributes.linesPerPage ).toBe( 18 );
	} );

	it( 'shows the section controls with a default of twelve lines', () => {
		const registerBlockType = load( { groups: {} } );
		const settings = registerBlockType.mock.calls[ 0 ][ 1 ];
		const tree = edit( settings );
		const range = findAll( tree, 'RangeControl' )[ 0 ];
		const selects = findAll( tree, 'SelectControl' );
		const toggles = findAll( tree, 'ToggleControl' );

		expect( range.props.value ).toBe( 12 );
		expect( range.props.min ).toBe( 1 );
		expect( range.props.max ).toBe( 50 );
		expect( toggles ).toHaveLength( 1 );
		expect( selects.map( ( control ) => control.props.label ) ).toEqual( [
			'Section order',
			'Questions with no status',
		] );
	} );

	it( 'creates one heading control per configured status', () => {
		const groups = {
			answered: 'Answered',
			waiting: 'Waiting for an answer',
		};
		const registerBlockType = load( { groups } );
		const settings = registerBlockType.mock.calls[ 0 ][ 1 ];
		const controls = findAll( edit( settings ), 'TextControl' );

		expect( controls.map( ( control ) => control.props.label ) ).toEqual( [
			'Questions with no status',
			'Answered',
			'Waiting for an answer',
		] );
	} );

	it( 'updates one group label without dropping the other', () => {
		const registerBlockType = load( {
			groups: {
				answered: 'Answered',
				waiting: 'Waiting for an answer',
			},
		} );
		const settings = registerBlockType.mock.calls[ 0 ][ 1 ];
		const setAttributes = jest.fn();
		const controls = findAll(
			edit(
				settings,
				{
					groupLabels: {
						answered: 'Solved',
						waiting: 'Open',
					},
				},
				setAttributes
			),
			'TextControl'
		);

		controls[ 1 ].props.onChange( 'Answered here' );
		expect( setAttributes ).toHaveBeenCalledWith( {
			groupLabels: {
				answered: 'Answered here',
				waiting: 'Open',
			},
		} );
	} );

	it( 'does not crash when the localized groups object is missing', () => {
		const registerBlockType = load();

		expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
		const settings = registerBlockType.mock.calls[ 0 ][ 1 ];
		expect( () => edit( settings ) ).not.toThrow();
	} );

	it( 'returns before registration when ServerSideRender is absent', () => {
		const registerBlockType = load( {
			groups: {},
			serverSideRender: false,
		} );

		expect( registerBlockType ).not.toHaveBeenCalled();
	} );
} );
