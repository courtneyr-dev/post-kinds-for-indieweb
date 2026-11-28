/**
 * The editor half of the kind-meta bindings source.
 */

jest.mock( '@wordpress/blocks', () => ( {
	registerBlockBindingsSource: jest.fn(),
} ) );
jest.mock( '@wordpress/core-data', () => ( { store: 'core' } ) );
jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

import { registerBlockBindingsSource } from '@wordpress/blocks';
import { createKindMetaSource } from '../../../src/editor/bindings/kind-meta-source';

const FIELDS = [
	{
		key: 'read_title',
		label: 'Book Title',
		kind: 'read',
		metaKey: '_pkiw_read_title',
		labels: {},
	},
	{
		key: 'read_status',
		label: 'Reading Status',
		kind: 'read',
		metaKey: '_pkiw_read_status',
		labels: { finished: 'Finished' },
	},
];

const KIND_TERMS = [
	{ id: 7, slug: 'read' },
	{ id: 8, slug: 'note' },
];

// Block client IDs the mocked block-editor store knows.
const BLOCK_NAMES = { row: 'core/list-item', para: 'core/paragraph' };

function selectWithMeta(
	meta,
	kind = [ 7 ],
	extra = {},
	edits = {},
	savedMeta = meta
) {
	const getEditedEntityRecord = jest.fn( () => ( { meta, kind, ...extra } ) );
	const getEntityRecord = jest.fn( () => ( { meta: savedMeta, kind } ) );
	const getEntityRecords = jest.fn( () => KIND_TERMS );
	const getEntityRecordEdits = jest.fn( () => edits );
	const getBlockName = jest.fn( ( clientId ) => BLOCK_NAMES[ clientId ] );
	return {
		select: ( store ) =>
			'core/block-editor' === store
				? { getBlockName }
				: {
						getEditedEntityRecord,
						getEntityRecord,
						getEntityRecords,
						getEntityRecordEdits,
					},
		getEditedEntityRecord,
	};
}

describe( 'kind-meta bindings source', () => {
	it( 'offers exactly the approved fields to the field picker', () => {
		const source = createKindMetaSource( FIELDS );
		expect( source.getFieldsList() ).toEqual( [
			{
				label: 'Book Title',
				type: 'string',
				args: { key: 'read_title' },
			},
			{
				label: 'Reading Status',
				type: 'string',
				args: { key: 'read_status' },
			},
		] );
	} );

	it( 'reads each bound value from the context post, with status labels', () => {
		const source = createKindMetaSource( FIELDS );
		const { select, getEditedEntityRecord } = selectWithMeta( {
			_pkiw_read_title: 'Kindred',
			_pkiw_read_status: 'finished',
		} );
		const values = source.getValues( {
			select,
			context: { postId: 12, postType: 'post' },
			bindings: {
				content: { args: { key: 'read_title' } },
				title: { args: { key: 'read_status' } },
			},
		} );
		expect( getEditedEntityRecord ).toHaveBeenCalledWith(
			'postType',
			'post',
			12
		);
		expect( values ).toEqual( { content: 'Kindred', title: 'Finished' } );
	} );

	it( 'returns empty strings for missing data, unknown keys and missing context', () => {
		const source = createKindMetaSource( FIELDS );
		const { select } = selectWithMeta( {} );
		expect(
			source.getValues( {
				select,
				context: { postId: 3, postType: 'post' },
				bindings: {
					content: { args: { key: 'read_title' } },
					other: { args: { key: 'checkin_address' } },
				},
			} )
		).toEqual( { content: '', other: '' } );
		expect(
			source.getValues( {
				select,
				context: {},
				bindings: { content: { args: { key: 'read_title' } } },
			} )
		).toEqual( { content: '' } );
	} );

	it( 'shows nothing in a summary row on a post of another kind, even with a meta default', () => {
		const source = createKindMetaSource( FIELDS );
		const { select } = selectWithMeta(
			{ _pkiw_read_title: 'Stray', _pkiw_read_status: 'reading' },
			[ 8 ]
		);
		expect(
			source.getValues( {
				select,
				clientId: 'row',
				context: { postId: 4, postType: 'post' },
				bindings: { content: { args: { key: 'read_status' } } },
			} )
		).toEqual( { content: '' } );
	} );

	it( 'keeps showing values on a post with no kind term', () => {
		const source = createKindMetaSource( FIELDS );
		const { select } = selectWithMeta( { _pkiw_read_title: 'Legacy' }, [] );
		expect(
			source.getValues( {
				select,
				context: { postId: 5, postType: 'post' },
				bindings: { content: { args: { key: 'read_title' } } },
			} )
		).toEqual( { content: 'Legacy' } );
	} );

	it( 'escapes text bound to rich-text content and keeps other attributes plain', () => {
		const source = createKindMetaSource( FIELDS );
		const { select } = selectWithMeta( {
			_pkiw_read_title: 'Kindred <img src=x onerror=alert(1)> & co',
		} );
		const values = source.getValues( {
			select,
			context: { postId: 6, postType: 'post' },
			bindings: {
				content: { args: { key: 'read_title' } },
				alt: { args: { key: 'read_title' } },
			},
		} );
		expect( values.content ).toBe(
			'Kindred &lt;img src=x onerror=alert(1)> &amp; co'
		);
		expect( values.alt ).toBe(
			'Kindred <img src=x onerror=alert(1)> & co'
		);
	} );

	it( 'shows only stored values, so a registered default fills no row', () => {
		const source = createKindMetaSource( FIELDS );
		const saved = {
			_pkiw_read_title: 'Kindred',
			_pkiw_read_status: 'reading',
		};
		const stored = { pkiw_stored_kind_fields: [ 'read_title' ] };
		const row = ( select, key ) =>
			source.getValues( {
				select,
				clientId: 'row',
				context: { postId: 9, postType: 'post' },
				bindings: { content: { args: { key } } },
			} ).content;

		const { select } = selectWithMeta( saved, [ 7 ], stored );
		expect( row( select, 'read_title' ) ).toBe( 'Kindred' );
		expect( row( select, 'read_status' ) ).toBe( '' );

		// A status set in this session counts before the post is saved.
		const changed = { ...saved, _pkiw_read_status: 'finished' };
		const edited = selectWithMeta(
			changed,
			[ 7 ],
			stored,
			{
				meta: changed,
			},
			saved
		);
		expect( row( edited.select, 'read_status' ) ).toBe( 'Finished' );
	} );

	it( 'keeps an untouched default status empty after editing only the title', () => {
		const source = createKindMetaSource( FIELDS );
		const saved = {
			_pkiw_read_title: 'Kindred',
			_pkiw_read_status: 'reading',
		};
		// Core copies the whole meta object, default status included, into
		// the edits when any one meta key changes.
		const edits = {
			meta: { ...saved, _pkiw_read_title: 'Kindred, revised' },
		};
		const { select } = selectWithMeta(
			edits.meta,
			[ 7 ],
			{ pkiw_stored_kind_fields: [ 'read_title' ] },
			edits,
			saved
		);
		expect(
			source.getValues( {
				select,
				clientId: 'row',
				context: { postId: 9, postType: 'post' },
				bindings: { content: { args: { key: 'read_status' } } },
			} )
		).toEqual( { content: '' } );
	} );

	it( 'resolves a legacy paragraph binding on any kind, as the front end does', () => {
		const source = createKindMetaSource( FIELDS );
		const meta = {
			_pkiw_read_title: 'Kindred',
			_pkiw_read_status: 'reading',
		};
		// A Note post where read_title is stored but the status is a default.
		const { select } = selectWithMeta( meta, [ 8 ], {
			pkiw_stored_kind_fields: [ 'read_title' ],
		} );
		const values = ( clientId ) =>
			source.getValues( {
				select,
				clientId,
				context: { postId: 11, postType: 'post' },
				bindings: { content: { args: { key: 'read_title' } } },
			} );
		expect( values( 'para' ) ).toEqual( { content: 'Kindred' } );
		expect( values( 'row' ) ).toEqual( { content: '' } );
		// Keys the client source doesn't know still resolve to empty.
		expect(
			source.getValues( {
				select,
				clientId: 'para',
				context: { postId: 11, postType: 'post' },
				bindings: { content: { args: { key: 'checkin_address' } } },
			} )
		).toEqual( { content: '' } );
	} );

	it( 'never lets the editor write card data through a binding', () => {
		expect( createKindMetaSource( FIELDS ).canUserEditValue() ).toBe(
			false
		);
	} );

	it( 'registers only when PHP supplied the field list', () => {
		expect( registerBlockBindingsSource ).not.toHaveBeenCalled();

		jest.isolateModules( () => {
			window.pkiwKindMetaFields = FIELDS;
			require( '../../../src/editor/bindings/kind-meta-source' );
		} );
		delete window.pkiwKindMetaFields;

		expect( registerBlockBindingsSource ).toHaveBeenCalledTimes( 1 );
		const [ source ] = registerBlockBindingsSource.mock.calls[ 0 ];
		expect( source.name ).toBe( 'post-kinds-indieweb/kind-meta' );
		expect( source.label ).toBeUndefined();
	} );
} );
