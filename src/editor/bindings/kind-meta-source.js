/**
 * Editor side of the post-kinds-indieweb/kind-meta bindings source.
 *
 * The server registers the source and resolves values on the front end.
 * This half gives the editor's field picker the approved fields and shows
 * each bound block the post's current value. Fields come from PHP
 * (Block_Bindings::get_editor_fields()) so the allowlist lives in one place.
 */

import { registerBlockBindingsSource } from '@wordpress/blocks';
import { store as coreDataStore } from '@wordpress/core-data';

export const SOURCE_NAME = 'post-kinds-indieweb/kind-meta';

/**
 * Build the client source for a list of fields.
 *
 * @param {Array<{key: string, label: string, metaKey: string, labels: Object}>} fields Approved fields.
 * @return {Object} Source settings for registerBlockBindingsSource().
 */
export function createKindMetaSource( fields ) {
	const byKey = Object.fromEntries(
		fields.map( ( field ) => [ field.key, field ] )
	);

	return {
		name: SOURCE_NAME,
		usesContext: [ 'postId', 'postType' ],
		getValues( { select, context, bindings } ) {
			const record =
				context?.postId && context?.postType
					? select( coreDataStore ).getEditedEntityRecord(
							'postType',
							context.postType,
							context.postId
						)
					: null;
			const meta = record?.meta ?? {};
			const termIds = Array.isArray( record?.kind ) ? record.kind : [];
			const kinds = termIds.length
				? (
						select( coreDataStore ).getEntityRecords(
							'taxonomy',
							'kind',
							{
								per_page: -1,
							}
						) ?? []
					)
						.filter( ( term ) => termIds.includes( term.id ) )
						.map( ( term ) => term.slug )
				: [];

			const values = {};
			for ( const [ attribute, binding ] of Object.entries( bindings ) ) {
				const field = byKey[ binding.args?.key ];
				// As on the server: on a post with a kind, a field shows only for
				// its own kind, so a registered meta default never fills another
				// kind's row.
				const raw =
					field &&
					( ! termIds.length || kinds.includes( field.kind ) )
						? meta[ field.metaKey ]
						: undefined;
				const value =
					raw === undefined || raw === null ? '' : String( raw );
				// Empty stays empty so the block shows the field label as
				// its placeholder; the front end drops the row.
				values[ attribute ] = field?.labels?.[ value ] ?? value;
			}
			return values;
		},
		// Read-only: the summary shows card data, it never edits it.
		canUserEditValue: () => false,
		getFieldsList: () =>
			fields.map( ( field ) => ( {
				label: field.label,
				type: 'string',
				args: { key: field.key },
			} ) ),
	};
}

const fields = Array.isArray( window.pkiwKindMetaFields )
	? window.pkiwKindMetaFields
	: [];

if ( fields.length ) {
	registerBlockBindingsSource( createKindMetaSource( fields ) );
}
