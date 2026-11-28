/**
 * Whether a summary field holds a value the author set.
 *
 * REST fills unset meta with its registered default, and any meta edit
 * copies the whole meta object into the edits and the save request, so a
 * field counts as set only when it is stored, the author set it in this
 * session, or its current value differs from the saved one.
 *
 * @param {{key: string, metaKey: string}} field             Summary field.
 * @param {Object}                         state
 * @param {Array<string>|undefined}        state.stored      Stored field keys (pkiw_stored_kind_fields); not an array when the server didn't say.
 * @param {Object}                         state.savedMeta   Meta of the saved record.
 * @param {Object}                         state.currentMeta Meta edits, or the meta a save sends.
 * @param {Array<string>}                  [state.touched]   Field keys the author set in this session.
 * @return {boolean} Whether the field counts as set.
 */
export function isFieldPresent(
	field,
	{ stored, savedMeta, currentMeta, touched = [] }
) {
	return (
		! Array.isArray( stored ) ||
		stored.includes( field.key ) ||
		touched.includes( field.key ) ||
		( Object.hasOwn( currentMeta, field.metaKey ) &&
			currentMeta[ field.metaKey ] !== savedMeta[ field.metaKey ] )
	);
}
