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

function selectWithMeta( meta, kind = [ 7 ] ) {
	const getEditedEntityRecord = jest.fn( () => ( { meta, kind } ) );
	const getEntityRecords = jest.fn( () => KIND_TERMS );
	return {
		select: () => ( { getEditedEntityRecord, getEntityRecords } ),
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
