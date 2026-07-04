/**
 * Sample value generator for matrix-driven tests.
 *
 * Rule order matches the PHP generator EXACTLY (type rules before name
 * patterns — Task 1 review finding): layout → boolean → number → url-ish
 * name → date-ish name → default string. Do not reorder.
 *
 * @param {string} attr Attribute name.
 * @param {Object} def  Attribute definition from the field matrix.
 * @return {*} Deterministic sample value.
 */
export function sampleFor( attr, def ) {
	const type = Array.isArray( def.type )
		? def.type[ 0 ]
		: def.type || 'string';
	if ( attr === 'layout' ) {
		return def.default ?? 'horizontal';
	}
	if ( type === 'boolean' ) {
		return true;
	}
	if ( type === 'number' || type === 'integer' ) {
		return 4;
	}
	if ( /(url|photo|cover|image)$/i.test( attr ) ) {
		return 'https://example.com/sample-' + attr.toLowerCase();
	}
	if ( /(At|Date)$/.test( attr ) || attr === 'publishDate' ) {
		return '2026-07-04';
	}
	return `Sample ${ attr } value`;
}
