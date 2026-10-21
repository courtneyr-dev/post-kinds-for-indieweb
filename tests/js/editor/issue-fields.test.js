/**
 * Issue fields document panel.
 */

const SCRIPT = '../../../assets/js/issue-fields-editor.js';

function component( name ) {
	return name;
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

function load( options = {} ) {
	const registerPlugin = jest.fn();
	const editPost = jest.fn();
	const stores = {
		'core/editor': {
			getEditedPostAttribute( attribute ) {
				return 'kind' === attribute
					? options.kind || []
					: options.meta || {};
			},
		},
	};
	if ( options.selectedKind ) {
		stores[ 'post-kinds-indieweb/post-kinds' ] = {
			getSelectedKind: () => options.selectedKind,
		};
	}

	window.pkiwIssueFields = { termId: options.termId || 264 };
	window.wp = {
		plugins: false === options.plugins ? undefined : { registerPlugin },
		editor: {
			PluginDocumentSettingPanel:
				false === options.panel
					? undefined
					: component( 'PluginDocumentSettingPanel' ),
		},
		element: {
			createElement: ( type, props, ...children ) => ( {
				type,
				props: props || {},
				children,
			} ),
		},
		components: {
			TextControl: component( 'TextControl' ),
			SelectControl: component( 'SelectControl' ),
		},
		data: {
			select: ( store ) => stores[ store ],
			useSelect: ( callback ) => callback( ( store ) => stores[ store ] ),
			useDispatch: () => ( { editPost } ),
		},
		i18n: { __: ( text ) => text },
	};

	jest.isolateModules( () => require( SCRIPT ) );

	return { editPost, registerPlugin };
}

function renderPanel( registerPlugin ) {
	const rendered = registerPlugin.mock.calls[ 0 ][ 1 ].render();

	return 'function' === typeof rendered.type
		? rendered.type( rendered.props )
		: rendered;
}

describe( 'issue fields editor', () => {
	afterEach( () => {
		delete window.wp;
		delete window.pkiwIssueFields;
		jest.resetModules();
	} );

	it( 'registers the issue fields plugin and renders four controls for the issue term', () => {
		const { registerPlugin } = load( { kind: [ 264 ] } );

		expect( registerPlugin ).toHaveBeenCalledTimes( 1 );
		expect( registerPlugin.mock.calls[ 0 ][ 0 ] ).toBe(
			'pkiw-issue-fields'
		);
		const tree = renderPanel( registerPlugin );
		expect( tree.type ).toBe( 'PluginDocumentSettingPanel' );
		expect( tree.props.name ).toBe( 'pkiw-issue-fields' );
		expect( tree.props.title ).toBe( 'Issue details' );
		expect( findAll( tree, 'TextControl' ) ).toHaveLength( 3 );
		expect( findAll( tree, 'SelectControl' ) ).toHaveLength( 1 );
	} );

	it( 'returns null for a post of another kind', () => {
		const { registerPlugin } = load( { kind: [ 18 ] } );

		expect( registerPlugin.mock.calls[ 0 ][ 1 ].render() ).toBeNull();
	} );

	it( 'uses the optional post-kinds store when the term list differs', () => {
		const { registerPlugin } = load( {
			kind: [ 18 ],
			selectedKind: 'issue',
		} );

		expect( renderPanel( registerPlugin ).type ).toBe(
			'PluginDocumentSettingPanel'
		);
	} );

	it( 'keeps an unset status empty and offers only the three specified choices', () => {
		const { registerPlugin } = load( { kind: [ 264 ], meta: {} } );
		const tree = renderPanel( registerPlugin );
		const status = findAll( tree, 'SelectControl' )[ 0 ];

		expect( status.props.value ).toBe( '' );
		expect( status.props.options ).toEqual( [
			{ label: '—', value: '' },
			{ label: 'Open', value: 'open' },
			{ label: 'Closed', value: 'closed' },
		] );
	} );

	it( 'updates exactly one meta key from each control', () => {
		const { editPost, registerPlugin } = load( {
			kind: [ 264 ],
			meta: {
				_pkiw_cite_url: 'https://a.example.net/acorn-ledger',
				_pkiw_cite_name: 'acorn-ledger',
				_pkiw_issue_number: '101',
				_pkiw_issue_status: 'open',
			},
		} );
		const tree = renderPanel( registerPlugin );
		const controls = [
			...findAll( tree, 'TextControl' ),
			...findAll( tree, 'SelectControl' ),
		];

		controls.forEach( ( control, index ) =>
			control.props.onChange( `value-${ index }` )
		);
		expect( editPost.mock.calls ).toEqual( [
			[ { meta: { _pkiw_cite_url: 'value-0' } } ],
			[ { meta: { _pkiw_cite_name: 'value-1' } } ],
			[ { meta: { _pkiw_issue_number: 'value-2' } } ],
			[ { meta: { _pkiw_issue_status: 'value-3' } } ],
		] );
	} );

	it( 'does nothing when the plugins API is missing', () => {
		let loaded;
		expect( () => {
			loaded = load( { plugins: false } );
		} ).not.toThrow();
		expect( loaded.registerPlugin ).not.toHaveBeenCalled();
	} );
} );
