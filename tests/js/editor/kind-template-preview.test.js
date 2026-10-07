/**
 * The Site Editor preview of a kind archive template (assets/js/kind-template-preview.js).
 *
 * The script is a plain file that reads WordPress globals, so each test hands
 * it a small stand-in for `window.wp` and inspects the element it returns.
 */

const SCRIPT = '../../../assets/js/kind-template-preview.js';

/**
 * Load the script against a stand-in editor and return its BlockEdit wrapper.
 *
 * @param {Object} state What the stand-in core store answers with.
 * @return {Function} The component the script wraps BlockEdit in.
 */
function load( state ) {
	const addFilter = jest.fn();
	const stores = {
		core: {
			getEntityRecords: ( kind, name, query ) => {
				state.termQueries.push( [ kind, name, query ] );
				return state.terms;
			},
		},
		'core/block-editor': {
			getBlocks: ( clientId ) => {
				state.blockQueries.push( clientId );
				return state.blocks;
			},
		},
	};
	window.wp = {
		hooks: { addFilter },
		compose: { createHigherOrderComponent: ( wrap ) => wrap },
		data: { useSelect: ( map ) => map( ( name ) => stores[ name ] ) },
		element: {
			createElement: ( type, props, ...children ) => ( {
				type,
				props,
				children,
			} ),
			useMemo: ( make ) => make(),
		},
	};
	window.pkiwKindTemplatePreview = state.settings;
	if ( state.entries ) {
		window.pkiwGroupedEntries = state.entries;
	}
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
	expect( addFilter ).toHaveBeenCalledTimes( 1 );
	expect( addFilter.mock.calls[ 0 ][ 0 ] ).toBe( 'editor.BlockEdit' );
	return addFilter.mock.calls[ 0 ][ 2 ]( 'BlockEdit' );
}

// Core sets an inheriting Query Loop's perPage to the site's setting in the editor.
const inheriting = {
	perPage: 10,
	postType: 'post',
	order: 'desc',
	orderBy: 'date',
	inherit: true,
};

function editorState( overrides = {} ) {
	return {
		terms: [ { id: 7 } ],
		termQueries: [],
		blocks: [],
		blockQueries: [],
		settings: { perPage: {} },
		...overrides,
	};
}

function postTemplate( context = {} ) {
	return {
		name: 'core/post-template',
		clientId: 'post-template-1',
		context: {
			queryId: 229,
			templateSlug: 'taxonomy-kind-recipe',
			query: inheriting,
			...context,
		},
	};
}

describe( 'kind template preview', () => {
	afterEach( () => {
		delete window.wp;
		delete window.pkiwKindTemplatePreview;
		delete window.pkiwGroupedEntries;
	} );

	it( "previews a kind's archive template with that kind's posts", () => {
		const state = editorState();
		const props = postTemplate();
		const element = load( state )( props );

		expect( element.type ).toBe( 'BlockEdit' );
		expect( element.props ).toEqual( {
			...props,
			context: {
				queryId: 229,
				templateSlug: 'taxonomy-kind-recipe',
				query: {
					perPage: 10,
					postType: 'post',
					order: 'desc',
					orderBy: 'date',
					inherit: false,
					taxQuery: { kind: [ 7 ], include: { kind: [ 7 ] } },
				},
			},
		} );
		expect( state.termQueries[ 0 ] ).toEqual( [
			'taxonomy',
			'kind',
			{ slug: 'recipe', per_page: 1, _fields: 'id' },
		] );
	} );

	it( 'shows as many posts as the site says that archive shows per page', () => {
		const state = editorState( {
			settings: { perPage: { recipe: 4, comics: 12 } },
		} );
		const element = load( state )( postTemplate() );

		expect( element.props.context.query.perPage ).toBe( 4 );
	} );

	it( 'asks for a menu kind in menu order, and for any other kind in the loop’s own order', () => {
		const settings = { perPage: {}, grouped: [ 'eat', 'drink' ] };
		const menu = load( editorState( { settings } ) )(
			postTemplate( { templateSlug: 'taxonomy-kind-eat' } )
		);
		const shelf = load( editorState( { settings } ) )(
			postTemplate( { templateSlug: 'taxonomy-kind-listen' } )
		);

		expect( menu.props.context.query.orderBy ).toBe( 'pkiw_group' );
		expect( shelf.props.context.query.orderBy ).toBe( 'date' );
	} );

	// A menu entry as the editor holds it, inside a group inside the Post Template.
	const menuEntry = ( attributes ) => [
		{
			name: 'core/group',
			attributes: {},
			innerBlocks: [
				{
					name: 'post-kinds-indieweb/menu-entry',
					attributes,
					innerBlocks: [],
				},
			],
		},
	];

	it( 'shows the lines per page the menu entry sets, ahead of the site’s number', () => {
		const settings = { perPage: { eat: 6 }, grouped: [ 'eat' ] };
		const eat = { templateSlug: 'taxonomy-kind-eat' };
		const state = editorState( {
			settings,
			blocks: menuEntry( { linesPerPage: 4 } ),
		} );
		const set = load( state )( postTemplate( eat ) );
		const unset = load(
			editorState( {
				settings,
				blocks: menuEntry( { linesPerPage: 0 } ),
			} )
		)( postTemplate( eat ) );

		expect( set.props.context.query.perPage ).toBe( 4 );
		expect( unset.props.context.query.perPage ).toBe( 6 );
		expect( state.blockQueries ).toEqual( [ 'post-template-1' ] );
	} );

	it.each( [
		[ {}, 'pkiw_group' ],
		[ { sectionOrder: 'desc' }, 'pkiw_group_desc' ],
		[ { emptyGroup: 'first' }, 'pkiw_group_empty_first' ],
		[
			{ sectionOrder: 'desc', emptyGroup: 'first' },
			'pkiw_group_desc_empty_first',
		],
	] )(
		'asks for the menu order that matches the menu entry’s settings %j',
		( attributes, orderBy ) => {
			const element = load(
				editorState( {
					settings: { perPage: {}, grouped: [ 'eat' ] },
					blocks: menuEntry( attributes ),
				} )
			)( postTemplate( { templateSlug: 'taxonomy-kind-eat' } ) );

			expect( element.props.context.query.orderBy ).toBe( orderBy );
		}
	);

	// An entry block other than the menu entry, as a kind's template holds it.
	const shelfEntry = ( attributes ) => [
		{
			name: 'pkiw-test/shelf-entry',
			attributes,
			innerBlocks: [],
		},
	];

	it( 'reads the settings of any registered entry block', () => {
		const element = load(
			editorState( {
				settings: { perPage: { read: 12 }, grouped: [ 'read' ] },
				entries: {
					'post-kinds-indieweb/menu-entry': { fixed: false },
					'pkiw-test/shelf-entry': { fixed: false },
				},
				blocks: shelfEntry( {
					linesPerPage: 3,
					sectionOrder: 'desc',
					emptyGroup: 'first',
				} ),
			} )
		)( postTemplate( { templateSlug: 'taxonomy-kind-read' } ) );

		expect( element.props.context.query.perPage ).toBe( 3 );
		expect( element.props.context.query.orderBy ).toBe(
			'pkiw_group_desc_empty_first'
		);
	} );

	// The play archive's Post Template: the stream card, then the archive-sections marker.
	const playTemplate = ( attributes ) => [
		{
			name: 'post-kinds-indieweb/stream-card',
			attributes: { headingLevel: 3 },
			innerBlocks: [],
		},
		{
			name: 'post-kinds-indieweb/archive-sections',
			attributes,
			innerBlocks: [],
		},
	];
	const registeredEntries = {
		'post-kinds-indieweb/menu-entry': { fixed: false },
		'post-kinds-indieweb/archive-sections': { fixed: false },
	};

	it( 'finds the archive-sections marker through pkiwGroupedEntries and pages by its linesPerPage', () => {
		const state = editorState( {
			settings: { perPage: { play: 6 }, grouped: [ 'eat', 'play' ] },
			entries: registeredEntries,
			blocks: playTemplate( { linesPerPage: 12 } ),
		} );
		const element = load( state )(
			postTemplate( { templateSlug: 'taxonomy-kind-play' } )
		);

		expect( element.props.context.query ).toEqual( {
			perPage: 12,
			postType: 'post',
			order: 'desc',
			orderBy: 'pkiw_group',
			inherit: false,
			taxQuery: { kind: [ 7 ], include: { kind: [ 7 ] } },
		} );
		expect( state.termQueries[ 0 ][ 2 ].slug ).toBe( 'play' );
	} );

	it( 'falls back to the site’s play page size when the marker sets none', () => {
		const element = load(
			editorState( {
				settings: { perPage: { play: 6 }, grouped: [ 'play' ] },
				entries: registeredEntries,
				blocks: playTemplate( {} ),
			} )
		)( postTemplate( { templateSlug: 'taxonomy-kind-play' } ) );

		expect( element.props.context.query.perPage ).toBe( 6 );
	} );

	it( 'asks for the read shelves in the order the marker sets', () => {
		const element = load(
			editorState( {
				settings: { perPage: {}, grouped: [ 'read' ] },
				entries: registeredEntries,
				blocks: playTemplate( {
					linesPerPage: 12,
					emptyGroup: 'first',
				} ),
			} )
		)( postTemplate( { templateSlug: 'taxonomy-kind-read' } ) );

		expect( element.props.context.query.perPage ).toBe( 12 );
		expect( element.props.context.query.orderBy ).toBe(
			'pkiw_group_empty_first'
		);
	} );

	it( 'ignores a block the entry list doesn’t name', () => {
		const element = load(
			editorState( {
				settings: { perPage: { read: 12 }, grouped: [ 'read' ] },
				entries: { 'post-kinds-indieweb/menu-entry': { fixed: false } },
				blocks: shelfEntry( { linesPerPage: 3, sectionOrder: 'desc' } ),
			} )
		)( postTemplate( { templateSlug: 'taxonomy-kind-read' } ) );

		expect( element.props.context.query.perPage ).toBe( 12 );
		expect( element.props.context.query.orderBy ).toBe( 'pkiw_group' );
	} );

	it( 'skips an entry inside a Query Loop nested in the Post Template', () => {
		const settings = { perPage: { eat: 6 }, grouped: [ 'eat' ] };
		const eat = { templateSlug: 'taxonomy-kind-eat' };
		const nested = {
			name: 'core/query',
			attributes: { query: { inherit: false } },
			innerBlocks: [
				{
					name: 'core/post-template',
					attributes: {},
					innerBlocks: menuEntry( {
						linesPerPage: 2,
						sectionOrder: 'desc',
					} ),
				},
			],
		};
		const alone = load( editorState( { settings, blocks: [ nested ] } ) )(
			postTemplate( eat )
		);
		const after = load(
			editorState( {
				settings,
				blocks: [ nested, ...menuEntry( { linesPerPage: 4 } ) ],
			} )
		)( postTemplate( eat ) );

		expect( alone.props.context.query.perPage ).toBe( 6 );
		expect( alone.props.context.query.orderBy ).toBe( 'pkiw_group' );
		expect( after.props.context.query.perPage ).toBe( 4 );
		expect( after.props.context.query.orderBy ).toBe( 'pkiw_group' );
	} );

	it( 'asks for an entry that fixes a date bucket newest first, not in grouped order', () => {
		const element = load(
			editorState( {
				settings: { perPage: {}, grouped: [ 'repost' ] },
				entries: { 'pkiw-test/month-entry': { fixed: true } },
				blocks: [
					{
						name: 'pkiw-test/month-entry',
						attributes: { linesPerPage: 9, sectionOrder: 'desc' },
						innerBlocks: [],
					},
				],
			} )
		)(
			postTemplate( {
				templateSlug: 'taxonomy-kind-repost',
				query: { ...inheriting, orderBy: 'title', order: 'asc' },
			} )
		);

		expect( element.props.context.query.orderBy ).toBe( 'date' );
		expect( element.props.context.query.order ).toBe( 'desc' );
		expect( element.props.context.query.perPage ).toBe( 9 );
	} );

	it( 'leaves the Query Loop’s own query object as it was', () => {
		load( editorState() )( postTemplate() );

		expect( inheriting ).toEqual( {
			perPage: 10,
			postType: 'post',
			order: 'desc',
			orderBy: 'date',
			inherit: true,
		} );
	} );

	it.each( [
		[
			'the template is the general kind archive',
			{ templateSlug: 'taxonomy-kind' },
		],
		[
			'the template belongs to another taxonomy',
			{ templateSlug: 'taxonomy-venue-cafe' },
		],
		[ 'a post is being edited', { templateSlug: undefined } ],
		[
			'the Query Loop sets its own query',
			{ query: { ...inheriting, inherit: false } },
		],
		[
			'the Post Template has no Query Loop above it',
			{ query: undefined },
		],
	] )( 'leaves the block alone when %s', ( label, context ) => {
		const state = editorState();
		const props = postTemplate( context );
		const element = load( state )( props );

		expect( element ).toEqual( { type: 'BlockEdit', props, children: [] } );
		expect( state.termQueries ).toEqual( [] );
	} );

	it.each( [
		[ "the kind's term hasn't loaded", null ],
		[ 'no kind has that slug', [] ],
	] )( 'leaves the block alone when %s', ( label, terms ) => {
		const props = postTemplate();
		const element = load( editorState( { terms } ) )( props );

		expect( element ).toEqual( { type: 'BlockEdit', props, children: [] } );
	} );

	it( 'leaves every other block alone and asks the store nothing for it', () => {
		const state = editorState();
		const props = {
			name: 'core/query-pagination',
			clientId: 'pager-1',
			context: {
				templateSlug: 'taxonomy-kind-recipe',
				query: inheriting,
			},
		};
		const element = load( state )( props );

		expect( element ).toEqual( { type: 'BlockEdit', props, children: [] } );
		expect( state.termQueries ).toEqual( [] );
	} );
} );
