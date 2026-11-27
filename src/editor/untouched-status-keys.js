/**
 * Tell the server which status defaults a save carries untouched.
 *
 * Any meta edit makes the editor send the whole meta object, so a status
 * the author never set rides along at its registered default. The server
 * drops a default status only when the save names its key in
 * pkiw_untouched_status_keys, so an explicit REST write is stored as sent.
 */

import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';

import { isFieldPresent } from './bindings/field-presence';
import { STORE_NAME } from './stores/post-kinds';

export const UNTOUCHED_STATUS_KEYS = 'pkiw_untouched_status_keys';

/**
 * Status meta keys a save sends that the author neither stored nor set.
 *
 * @param {Object}        args
 * @param {Array<Object>} args.fields   Summary fields from PHP (window.pkiwKindMetaFields).
 * @param {Object}        args.sentMeta Meta the save sends.
 * @param {Object|null}   args.saved    Saved post record.
 * @param {Array<string>} args.touched  Field keys the author set in this session.
 * @return {Array<string>} Meta keys.
 */
export function untouchedStatusKeys( { fields, sentMeta, saved, touched } ) {
	return fields
		.filter(
			( field ) =>
				Object.keys( field.labels ?? {} ).length &&
				Object.hasOwn( sentMeta, field.metaKey ) &&
				! isFieldPresent( field, {
					stored: saved?.pkiw_stored_kind_fields,
					savedMeta: saved?.meta ?? {},
					currentMeta: sentMeta,
					touched,
				} )
		)
		.map( ( field ) => field.metaKey );
}

/**
 * Build the api-fetch middleware for the post open in the editor.
 *
 * @param {(store: Object|string) => Object} select Registry select.
 * @return {(options: Object, next: (options: Object) => Promise<Object>) => Promise<Object>} api-fetch middleware.
 */
export function createUntouchedStatusMiddleware( select ) {
	return ( options, next ) => {
		const meta = options.data?.meta;
		const fields = Array.isArray( window.pkiwKindMetaFields )
			? window.pkiwKindMetaFields
			: [];
		if (
			! [ 'POST', 'PUT', 'PATCH' ].includes(
				( options.method ?? 'GET' ).toUpperCase()
			) ||
			! meta ||
			'object' !== typeof meta ||
			! fields.length
		) {
			return next( options );
		}

		const postId = select( editorStore ).getCurrentPostId?.();
		const postType = select( editorStore ).getCurrentPostType?.();
		const typeObject = postType
			? select( coreStore ).getPostType( postType )
			: null;
		const path = ( options.path ?? '' ).split( '?' )[ 0 ];
		const target = typeObject?.rest_base
			? `/${ typeObject.rest_namespace || 'wp/v2' }/${
					typeObject.rest_base
				}/${ postId }`
			: null;
		if ( ! target || path.replace( /^\/?/, '/' ) !== target ) {
			return next( options );
		}

		const keys = untouchedStatusKeys( {
			fields,
			sentMeta: meta,
			saved: select( coreStore ).getEntityRecord(
				'postType',
				postType,
				postId
			),
			touched: select( STORE_NAME )?.getTouchedKindMeta?.() ?? [],
		} );
		return next( {
			...options,
			data: { ...options.data, [ UNTOUCHED_STATUS_KEYS ]: keys },
		} );
	};
}
