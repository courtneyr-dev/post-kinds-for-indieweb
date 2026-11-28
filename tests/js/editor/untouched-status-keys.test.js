/**
 * The save middleware that names untouched status defaults.
 */

jest.mock( '@wordpress/core-data', () => ( { store: 'core' } ) );
jest.mock( '@wordpress/editor', () => ( { store: 'core/editor' } ) );
jest.mock( '../../../src/editor/stores/post-kinds', () => ( {
	STORE_NAME: 'post-kinds-indieweb/post-kinds',
} ) );

import { createUntouchedStatusMiddleware } from '../../../src/editor/untouched-status-keys';

const FIELDS = [
	{
		key: 'read_title',
		metaKey: '_pkiw_read_title',
		labels: {},
	},
	{
		key: 'read_status',
		metaKey: '_pkiw_read_status',
		labels: { reading: 'Currently Reading', finished: 'Finished' },
	},
	{
		key: 'watch_status',
		metaKey: '_pkiw_watch_status',
		labels: { 'to-watch': 'To Watch' },
	},
];

// Saved post 9: title stored, both statuses at their REST-filled defaults.
const SAVED_META = {
	_pkiw_read_title: 'Kindred',
	_pkiw_read_status: 'reading',
	_pkiw_watch_status: 'to-watch',
};

function run( options, { stored = [ 'read_title' ], touched = [] } = {} ) {
	const select = ( store ) =>
		( {
			'core/editor': {
				getCurrentPostId: () => 9,
				getCurrentPostType: () => 'post',
			},
			core: {
				getPostType: () => ( {
					rest_base: 'posts',
					rest_namespace: 'wp/v2',
				} ),
				getEntityRecord: () => ( {
					meta: SAVED_META,
					pkiw_stored_kind_fields: stored,
				} ),
			},
			'post-kinds-indieweb/post-kinds': {
				getTouchedKindMeta: () => touched,
			},
		} )[ store ];
	const next = jest.fn( ( sent ) => sent );
	createUntouchedStatusMiddleware( select )( options, next );
	return next.mock.calls[ 0 ][ 0 ];
}

const save = ( meta, path = '/wp/v2/posts/9' ) => ( {
	path,
	method: 'PUT',
	data: { title: 'Kindred', meta },
} );

describe( 'untouched status middleware', () => {
	beforeEach( () => {
		window.pkiwKindMetaFields = FIELDS;
	} );
	afterEach( () => {
		delete window.pkiwKindMetaFields;
	} );

	it( 'lists the defaults a title-only save carries, and no other field', () => {
		const sent = run(
			save( { ...SAVED_META, _pkiw_read_title: 'Kindred, revised' } )
		);
		expect( sent.data.pkiw_untouched_status_keys ).toEqual( [
			'_pkiw_read_status',
			'_pkiw_watch_status',
		] );
		expect( sent.data.meta._pkiw_read_status ).toBe( 'reading' );
	} );

	it( 'leaves out a status that is stored, changed, or set by the author', () => {
		const changed = run(
			save( { ...SAVED_META, _pkiw_read_status: 'finished' } )
		);
		expect( changed.data.pkiw_untouched_status_keys ).toEqual( [
			'_pkiw_watch_status',
		] );

		const stored = run( save( SAVED_META ), {
			stored: [ 'read_title', 'read_status' ],
		} );
		expect( stored.data.pkiw_untouched_status_keys ).toEqual( [
			'_pkiw_watch_status',
		] );

		// Choosing "Currently Reading" sends the value already shown.
		const chosen = run( save( SAVED_META ), {
			touched: [ 'read_status' ],
		} );
		expect( chosen.data.pkiw_untouched_status_keys ).toEqual( [
			'_pkiw_watch_status',
		] );
	} );

	it( 'touches only saves of the post open in the editor', () => {
		const query = run( save( SAVED_META, '/wp/v2/posts/9?_locale=user' ) );
		expect( query.data.pkiw_untouched_status_keys ).toHaveLength( 2 );

		for ( const options of [
			save( SAVED_META, '/wp/v2/posts/10' ),
			save( SAVED_META, '/wp/v2/pages/9' ),
			save( SAVED_META, '/wp/v2/posts/9/autosaves' ),
			{ path: '/wp/v2/posts/9', method: 'GET' },
			{ path: '/wp/v2/posts/9', method: 'PUT', data: { title: 'x' } },
		] ) {
			expect( run( options ) ).toBe( options );
		}
	} );

	it( 'passes every request through when PHP sent no fields', () => {
		delete window.pkiwKindMetaFields;
		const options = save( SAVED_META );
		expect( run( options ) ).toBe( options );
	} );
} );
