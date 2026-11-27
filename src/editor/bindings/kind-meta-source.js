/**
 * Editor side of the post-kinds-indieweb/kind-meta bindings source.
 *
 * The server registers the source and resolves values on the front end.
 * This half gives the editor's field picker the approved fields and shows
 * each bound block the post's current value. Loaded on every block editor,
 * Site Editor included, as its own bundle. Fields come from PHP
 * (Block_Bindings::get_editor_fields()) so the allowlist lives in one place.
 */

import { store as blockEditorStore } from '@wordpress/block-editor';
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { store as coreDataStore } from '@wordpress/core-data';

export const SOURCE_NAME = 'post-kinds-indieweb/kind-meta';

/**
 * Text as rich-text HTML: & and < escaped, as core's escapeHTML does.
 *
 * @param {string} text Plain text.
 * @return {string} HTML that shows the text literally.
 */
const escapeHTML = ( text ) =>
	text.replace( /&(?!#?\w+;)/g, '&amp;' ).replace( /</g, '&lt;' );

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
		getValues( { select, context, clientId, bindings } ) {
			const hasPost = Boolean( context?.postId && context?.postType );
			const store = select( coreDataStore );
			const record = hasPost
				? store.getEditedEntityRecord(
						'postType',
						context.postType,
						context.postId
					)
				: null;
			const meta = record?.meta ?? {};
			// REST fills unset meta with its registered default, and any meta
			// edit copies the whole meta object into the edits, so a key
			// counts as set only when it is stored or this session changed
			// it from the saved value. Core's REST meta handler skips a
			// write equal to the default, so an unchanged default never
			// gets stored on save either.
			const stored = record?.pkiw_stored_kind_fields;
			const savedMeta = hasPost
				? ( store.getEntityRecord?.(
						'postType',
						context.postType,
						context.postId
					)?.meta ?? {} )
				: {};
			const editedMeta = hasPost
				? ( store.getEntityRecordEdits?.(
						'postType',
						context.postType,
						context.postId
					)?.meta ?? {} )
				: {};
			const isPresent = ( field ) =>
				! Array.isArray( stored ) ||
				stored.includes( field.key ) ||
				( Object.hasOwn( editedMeta, field.metaKey ) &&
					editedMeta[ field.metaKey ] !==
						savedMeta[ field.metaKey ] );
			const termIds = Array.isArray( record?.kind ) ? record.kind : [];
			const kinds = termIds.length
				? (
						store.getEntityRecords( 'taxonomy', 'kind', {
							per_page: -1,
						} ) ?? []
					)
						.filter( ( term ) => termIds.includes( term.id ) )
						.map( ( term ) => term.slug )
				: [];
			const isListItem =
				clientId &&
				'core/list-item' ===
					select( blockEditorStore ).getBlockName?.( clientId );

			const values = {};
			for ( const [ attribute, binding ] of Object.entries( bindings ) ) {
				const field = byKey[ binding.args?.key ];
				// As on the server: a List Item summary row shows a field only
				// for its own kind (or a post with no kind) and only from a
				// stored value, so a registered meta default never fills a
				// row. Other blocks keep the plain meta value.
				const summary = isListItem && 'content' === attribute;
				const raw =
					field &&
					( ! summary ||
						( ( ! termIds.length ||
							kinds.includes( field.kind ) ) &&
							isPresent( field ) ) )
						? meta[ field.metaKey ]
						: undefined;
				const value =
					raw === undefined || raw === null ? '' : String( raw );
				// Empty stays empty so the block shows the field label as
				// its placeholder; the front end drops the row.
				const text = field?.labels?.[ value ] ?? value;
				// Rich-text content is HTML, so its text is escaped; other
				// attributes take the plain string.
				values[ attribute ] =
					'content' === attribute ? escapeHTML( text ) : text;
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
