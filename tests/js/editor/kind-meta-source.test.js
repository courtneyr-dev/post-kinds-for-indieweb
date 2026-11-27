/**
 * The editor half of the kind-meta bindings source.
 */

jest.mock( '@wordpress/blocks', () => ( {
	registerBlockBindingsSource: jest.fn(),
} ) );
jest.mock( '@wordpress/core-data', () => ( { store: 'core' } ) );

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

function selectWithMeta( meta, kind = [ 7 ], extra = {}, edits = {} ) {
	const getEditedEntityRecord = jest.fn( () => ( { meta, kind, ...extra } ) );
	const getEntityRecords = jest.fn( () => KIND_TERMS );
	const getEntityRecordEdits = jest.fn( () => edits );
	return {
		select: () => ( {
			getEditedEntityRecord,
			getEntityRecords,
			getEntityRecordEdits,
		} ),
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

	it( 'shows nothing on a post of another kind, even with a meta default', () => {
		const source = createKindMetaSource( FIELDS );
		const { select } = selectWithMeta(
			{ _pkiw_read_title: 'Stray', _pkiw_read_status: 'reading' },
			[ 8 ]
		);
		expect(
			source.getValues( {
				select,
				context: { postId: 4, postType: 'post' },
				bindings: {
					content: { args: { key: 'read_title' } },
					title: { args: { key: 'read_status' } },
				},
			} )
		).toEqual( { content: '', title: '' } );
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
		const bindings = {
			content: { args: { key: 'read_title' } },
			title: { args: { key: 'read_status' } },
		};
		const meta = {
			_pkiw_read_title: 'Kindred',
			_pkiw_read_status: 'reading',
		};
		const stored = { pkiw_stored_kind_fields: [ 'read_title' ] };

		const { select } = selectWithMeta( meta, [ 7 ], stored );
		expect(
			source.getValues( {
				select,
				context: { postId: 9, postType: 'post' },
				bindings,
			} )
		).toEqual( { content: 'Kindred', title: '' } );

		// A status set in this session counts before the post is saved.
		const edited = selectWithMeta(
			{ ...meta, _pkiw_read_status: 'finished' },
			[ 7 ],
			stored,
			{ meta: { _pkiw_read_status: 'finished' } }
		);
		expect(
			source.getValues( {
				select: edited.select,
				context: { postId: 9, postType: 'post' },
				bindings,
			} )
		).toEqual( { content: 'Kindred', title: 'Finished' } );
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
