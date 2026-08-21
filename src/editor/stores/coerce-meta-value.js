/**
 * Coerce a kind-meta value to its registered REST type before writing.
 *
 * External lookups return numbers where meta is registered as a string —
 * OpenLibrary ISBNs, TMDB/Trakt ids, release years — and REST rejects the
 * whole post update on the next save ("meta._pkiw_read_isbn is not of type
 * string"). The registered types are published by PHP as
 * `window.pkiwAdminEditor.metaFieldTypes`; unknown fields pass through
 * untouched so this can never mangle a field it doesn't know about.
 *
 * @param {string} key   Field name (without the meta prefix).
 * @param {*}      value Value about to be written.
 * @return {*} Coerced value.
 */
export function coerceMetaValue( key, value ) {
	if ( value === null || value === undefined ) {
		return value;
	}

	const types = window?.pkiwAdminEditor?.metaFieldTypes;
	const type = types?.[ key ];

	if ( ! type ) {
		return value;
	}

	if ( type === 'string' && typeof value !== 'string' ) {
		// Objects/arrays would stringify to "[object Object]" — drop them
		// rather than write garbage the user would have to notice.
		if ( typeof value === 'object' ) {
			return '';
		}
		return String( value );
	}

	if (
		( type === 'number' || type === 'integer' ) &&
		typeof value === 'string'
	) {
		const num = Number( value );
		return Number.isNaN( num ) ? value : num;
	}

	return value;
}
