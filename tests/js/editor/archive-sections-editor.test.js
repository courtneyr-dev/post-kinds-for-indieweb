/**
 * The archive-sections marker's editor registration (assets/js/archive-sections-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/archive-sections-editor.js';
const BLOCK = 'post-kinds-indieweb/archive-sections';

/**
 * Load the script and return the settings it registers the block with.
 *
 * @param {Object} [groups]      Group keys and their default labels, by kind.
 * @param {Array}  [dateKinds]   Kind slugs grouped by post date.
 * @param {Object} [dateFormats] Default PHP date formats, by kind.
 * @return {Object} Block settings.
 */
function register( groups, dateKinds, dateFormats ) {
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
		i18n: {
			__: ( text ) => text,
			sprintf: ( format, ...args ) =>
				args.reduce( ( out, arg ) => out.replace( '%s', arg ), format ),
		},
		blockEditor: {
			useBlockProps: ( props ) => props,
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
	if ( groups ) {
		window.pkiwArchiveSections = { groups };
	}
	if ( dateKinds ) {
		window.pkiwArchiveSectionsDateKinds = dateKinds;
	}
	if ( dateFormats ) {
		window.pkiwArchiveSectionsDateFormats = dateFormats;
	}
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
	expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
	expect( registerBlockType.mock.calls[ 0 ][ 0 ] ).toBe( BLOCK );
	return registerBlockType.mock.calls[ 0 ][ 1 ];
}

// Every element in a rendered tree, depth first.
function flatten( node ) {
	if ( ! node || 'object' !== typeof node ) {
		return [];
	}
	if ( Array.isArray( node ) ) {
		return node.flatMap( flatten );
	}
	return [ node, ...flatten( node.children ) ];
}

const defaults = {
	showSections: true,
	headingLevel: 2,
	linesPerPage: 0,
	sectionOrder: 'asc',
	emptyGroup: 'last',
	emptyLabel: '',
	groupLabels: {},
	dateFormat: '',
	leadCount: 0,
	leadLabel: '',
};

/**
 * Render the edit function.
 *
 * @param {Object} settings   Block settings.
 * @param {Object} attributes Attributes over the defaults.
 * @param {Object} context    Block context.
 * @return {{tree: Object[], setAttributes: jest.Mock}} The rendered elements and the attribute spy.
 */
function edit( settings, attributes = {}, context = {} ) {
	const setAttributes = jest.fn();
	const element = settings.edit( {
		attributes: { ...defaults, ...attributes },
		context: { postId: 31, templateSlug: 'taxonomy-kind-play', ...context },
		setAttributes,
	} );
	return { tree: flatten( element ), setAttributes };
}

// The control of a type whose label is the given text.
function control( tree, type, label ) {
	const found = tree.find(
		( node ) =>
			type === node.type && node.props && label === node.props.label
	);
	expect( found ).toBeDefined();
	return found;
}

describe( 'archive sections editor', () => {
	afterEach( () => {
		delete window.wp;
		delete window.pkiwArchiveSections;
		delete window.pkiwArchiveSectionsDateKinds;
		delete window.pkiwArchiveSectionsDateFormats;
	} );

	it( 'registers inside a Post Template with the server-side attributes', () => {
		const settings = register();

		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.ancestor ).toEqual( [ 'core/post-template' ] );
		expect( settings.usesContext ).toEqual(
			expect.arrayContaining( [ 'postId', 'templateSlug' ] )
		);
		expect( Object.keys( settings.attributes ).sort() ).toEqual(
			Object.keys( defaults ).sort()
		);
		expect( settings.attributes.groupLabels ).toMatchObject( {
			type: 'object',
			role: 'content',
		} );
		expect( settings.attributes.emptyLabel ).toMatchObject( {
			type: 'string',
			role: 'content',
		} );
		expect( settings.attributes.dateFormat ).toEqual( {
			type: 'string',
			default: '',
		} );
		expect( settings.attributes.leadCount ).toEqual( {
			type: 'integer',
			default: 0,
		} );
		expect( settings.attributes.leadLabel ).toEqual( {
			type: 'string',
			default: '',
			role: 'content',
		} );
		expect( settings.save() ).toBeNull();
	} );

	it( 'previews through the block renderer as the kind whose archive template holds it', () => {
		const { tree } = edit( register() );
		const render = tree.find(
			( node ) => 'ServerSideRender' === node.type
		);

		expect( render.props.block ).toBe( BLOCK );
		expect( render.props.urlQueryArgs ).toEqual( {
			post_id: 31,
			pkiw_kind: 'play',
		} );
	} );

	it( 'names no kind outside a kind template', () => {
		const { tree } = edit(
			register(),
			{},
			{ templateSlug: 'taxonomy-kind' }
		);
		const render = tree.find(
			( node ) => 'ServerSideRender' === node.type
		);

		expect( render.props.urlQueryArgs ).toEqual( { post_id: 31 } );
	} );

	it( 'asks to be placed in a Post Template when there is no loop post', () => {
		const { tree } = edit( register(), {}, { postId: undefined } );

		expect(
			tree.find( ( node ) => 'ServerSideRender' === node.type )
		).toBeUndefined();
	} );

	it.each( [
		[
			'ToggleControl',
			'Show section headings',
			false,
			{ showSections: false },
		],
		[ 'SelectControl', 'Heading level', '3', { headingLevel: 3 } ],
		[ 'RangeControl', 'Posts per page', 12, { linesPerPage: 12 } ],
		[ 'RangeControl', 'Posts per page', undefined, { linesPerPage: 0 } ],
		[ 'SelectControl', 'Section order', 'desc', { sectionOrder: 'desc' } ],
		[
			'SelectControl',
			'Posts in no group',
			'first',
			{ emptyGroup: 'first' },
		],
		[
			'TextControl',
			'Heading for posts in no group',
			'Play',
			{ emptyLabel: 'Play' },
		],
	] )( '%s “%s” writes its attribute', ( type, label, value, expected ) => {
		const { tree, setAttributes } = edit( register() );

		control( tree, type, label ).props.onChange( value );

		expect( setAttributes ).toHaveBeenCalledWith( expected );
	} );

	it( 'offers heading levels 2 to 4, the levels the engine prints', () => {
		const { tree } = edit( register() );
		const options = control( tree, 'SelectControl', 'Heading level' ).props
			.options;

		expect( options.map( ( option ) => option.value ) ).toEqual( [
			'2',
			'3',
			'4',
		] );
	} );

	it( 'gives each group key of the template’s kind a heading field', () => {
		const settings = register( {
			play: { video: 'Video games', board: 'Board games' },
			read: { reading: 'Currently Reading' },
		} );
		const { tree, setAttributes } = edit( settings, {
			groupLabels: { video: 'Arcade' },
		} );
		const video = control( tree, 'TextControl', 'Heading for Video games' );
		const board = control( tree, 'TextControl', 'Heading for Board games' );

		expect( video.props.value ).toBe( 'Arcade' );
		expect( board.props.value ).toBe( '' );
		expect(
			tree.find(
				( node ) =>
					node.props &&
					'Heading for Currently Reading' === node.props.label
			)
		).toBeUndefined();

		board.props.onChange( 'Game Night' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			groupLabels: { video: 'Arcade', board: 'Game Night' },
		} );

		video.props.onChange( '' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			groupLabels: {},
		} );
	} );

	it( 'keeps a field for a label the block already holds, whatever the template', () => {
		const { tree, setAttributes } = edit(
			register(),
			{ groupLabels: { board: 'Game Night' } },
			{ templateSlug: 'taxonomy-kind' }
		);
		const board = control( tree, 'TextControl', 'Heading for board' );

		expect( board.props.value ).toBe( 'Game Night' );
		board.props.onChange( 'Tabletop' );
		expect( setAttributes ).toHaveBeenCalledWith( {
			groupLabels: { board: 'Tabletop' },
		} );
	} );

	it( 'hides the heading settings while section headings are off', () => {
		const { tree } = edit( register( { play: { video: 'Video games' } } ), {
			showSections: false,
		} );
		const labels = tree
			.filter( ( node ) => node.props && node.props.label )
			.map( ( node ) => node.props.label );

		expect( labels ).toEqual( [
			'Show section headings',
			'Posts per page',
		] );
	} );

	it( 'uses a date format control for month-grouped kind templates', () => {
		const { tree, setAttributes } = edit(
			register( undefined, [ 'article' ] ),
			{ dateFormat: 'M Y' },
			{ templateSlug: 'taxonomy-kind-article' }
		);
		const labels = tree
			.filter( ( node ) => node.props && node.props.label )
			.map( ( node ) => node.props.label );
		const date = control( tree, 'TextControl', 'Date format' );

		expect( labels ).toEqual(
			expect.arrayContaining( [
				'Show section headings',
				'Heading level',
				'Posts per page',
				'Date format',
			] )
		);
		expect( labels ).not.toContain( 'Section order' );
		expect( labels ).not.toContain( 'Posts in no group' );
		expect( labels ).not.toContain( 'Heading for posts in no group' );
		expect( date.props.placeholder ).toBe( 'F Y' );
		expect( date.props.help ).toBe( 'A PHP date format. Empty uses F Y.' );
		expect( date.props.value ).toBe( 'M Y' );

		date.props.onChange( 'Y-m' );
		expect( setAttributes ).toHaveBeenCalledWith( { dateFormat: 'Y-m' } );
	} );

	it.each( [
		[ 'video', { photo: 'F Y', video: 'Y' }, 'Y' ],
		[ 'photo', { photo: 'F Y', video: 'Y' }, 'F Y' ],
		[ 'jam', { photo: 'F Y', video: 'Y' }, 'F Y' ],
		[ 'article', undefined, 'F Y' ],
	] )(
		'uses the %s kind’s default date format',
		( kind, dateFormats, expected ) => {
			const { tree } = edit(
				register(
					undefined,
					[ 'video', 'photo', 'jam', 'article' ],
					dateFormats
				),
				{},
				{ templateSlug: 'taxonomy-kind-' + kind }
			);
			const date = control( tree, 'TextControl', 'Date format' );

			expect( date.props.placeholder ).toBe( expected );
			expect( date.props.help ).toBe(
				'A PHP date format. Empty uses ' + expected + '.'
			);
		}
	);

	it.each( [ 'photo', 'jam' ] )(
		'shows lead controls for the month-grouped %s kind',
		( kind ) => {
			const { tree } = edit(
				register( undefined, [ 'photo', 'jam', 'video' ], {
					photo: 'F Y',
					jam: 'F Y',
					video: 'Y',
				} ),
				{},
				{ templateSlug: 'taxonomy-kind-' + kind }
			);

			expect(
				control(
					tree,
					'ToggleControl',
					'Feature the newest post on page 1'
				).props.help
			).toBe(
				'Prints the newest post first, above the month sections, on the first page only.'
			);
			expect(
				control( tree, 'TextControl', 'Heading for the featured post' )
					.props.help
			).toBe( 'Empty prints no heading.' );
		}
	);

	it( 'writes the lead attributes from the month controls', () => {
		const { tree, setAttributes } = edit(
			register( undefined, [ 'photo' ], { photo: 'F Y' } ),
			{ leadLabel: 'Featured' },
			{ templateSlug: 'taxonomy-kind-photo' }
		);
		const toggle = control(
			tree,
			'ToggleControl',
			'Feature the newest post on page 1'
		);
		const label = control(
			tree,
			'TextControl',
			'Heading for the featured post'
		);

		expect( label.props.value ).toBe( 'Featured' );
		toggle.props.onChange( true );
		expect( setAttributes ).toHaveBeenLastCalledWith( { leadCount: 1 } );
		toggle.props.onChange( false );
		expect( setAttributes ).toHaveBeenLastCalledWith( { leadCount: 0 } );
		label.props.onChange( 'Latest' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			leadLabel: 'Latest',
		} );
	} );

	it.each( [
		[ 0, false ],
		[ 1, true ],
	] )( 'reads leadCount %d as checked %s', ( leadCount, expected ) => {
		const { tree } = edit(
			register( undefined, [ 'photo' ], { photo: 'F Y' } ),
			{ leadCount },
			{ templateSlug: 'taxonomy-kind-photo' }
		);

		expect(
			control(
				tree,
				'ToggleControl',
				'Feature the newest post on page 1'
			).props.checked
		).toBe( expected );
	} );

	it.each( [
		[ 'video', true ],
		[ 'play', true ],
		[ 'photo', false ],
	] )(
		'hides lead controls for %s when showSections is %s',
		( kind, showSections ) => {
			const { tree } = edit(
				register( undefined, [ 'photo', 'video' ], {
					photo: 'F Y',
					video: 'Y',
				} ),
				{ showSections },
				{ templateSlug: 'taxonomy-kind-' + kind }
			);
			const labels = tree
				.filter( ( node ) => node.props && node.props.label )
				.map( ( node ) => node.props.label );

			expect( labels ).not.toContain(
				'Feature the newest post on page 1'
			);
			expect( labels ).not.toContain( 'Heading for the featured post' );
		}
	);

	it( 'keeps group controls for templates outside the date-kind list', () => {
		const { tree } = edit(
			register( undefined, [ 'article' ] ),
			{},
			{ templateSlug: 'taxonomy-kind-eat' }
		);
		const labels = tree
			.filter( ( node ) => node.props && node.props.label )
			.map( ( node ) => node.props.label );

		expect( labels ).toEqual(
			expect.arrayContaining( [
				'Section order',
				'Posts in no group',
				'Heading for posts in no group',
			] )
		);
		expect( labels ).not.toContain( 'Date format' );
	} );

	it( "keeps today's controls when the date-kind global is absent", () => {
		const { tree } = edit(
			register(),
			{},
			{ templateSlug: 'taxonomy-kind-article' }
		);
		const labels = tree
			.filter( ( node ) => node.props && node.props.label )
			.map( ( node ) => node.props.label );

		expect( labels ).not.toContain( 'Date format' );
	} );

	it( 'hides date format while section headings are off', () => {
		const { tree } = edit(
			register( undefined, [ 'article' ] ),
			{ showSections: false },
			{ templateSlug: 'taxonomy-kind-article' }
		);
		const labels = tree
			.filter( ( node ) => node.props && node.props.label )
			.map( ( node ) => node.props.label );

		expect( labels ).not.toContain( 'Date format' );
	} );
} );
