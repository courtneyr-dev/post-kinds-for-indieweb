/**
 * Turn typed AI suggestions into editor actions.
 *
 * A suggestion only ever fills an empty citation field. A field the author
 * already filled keeps the author's value, so manual edits and looked-up
 * citation metadata survive every AI outcome.
 *
 * @package
 * @since 1.11.0
 */

import { __ } from '@wordpress/i18n';

export const META_PREFIX = '_pkiw_';

/**
 * Citation fields a suggestion may name, with their visible labels.
 *
 * @return {Object<string, string>} Field key => label.
 */
export const suggestionFields = () => ( {
	cite_name: __( 'Title', 'post-kinds-for-indieweb-in-block-themes' ),
	cite_author: __( 'Author', 'post-kinds-for-indieweb-in-block-themes' ),
	cite_summary: __( 'Summary', 'post-kinds-for-indieweb-in-block-themes' ),
} );

/**
 * Whether a meta value already holds text.
 *
 * @param {?(string|number|boolean)} value Meta value.
 * @return {boolean} True when the author or a lookup filled it.
 */
export const isFilled = ( value ) =>
	typeof value === 'string' && value.trim() !== '';

/**
 * Plan what to offer for each suggestion.
 *
 * Drops anything that isn't a typed suggestion for a known field. Each kept
 * suggestion says whether it can be applied or whether the current value
 * stays.
 *
 * @param {Array}  suggestions Suggestions from the auto-populate route.
 * @param {Object} meta        Current post meta.
 * @return {Array<{field:string,label:string,value:string,keep:boolean}>} Plan.
 */
export function planSuggestions( suggestions, meta ) {
	if ( ! Array.isArray( suggestions ) ) {
		return [];
	}

	const fields = suggestionFields();
	const current = meta || {};

	return suggestions
		.filter(
			( suggestion ) =>
				suggestion &&
				suggestion.source === 'ai' &&
				Object.prototype.hasOwnProperty.call(
					fields,
					suggestion.field
				) &&
				isFilled( suggestion.value )
		)
		.map( ( suggestion ) => ( {
			field: suggestion.field,
			label: fields[ suggestion.field ],
			value: suggestion.value,
			keep: isFilled( current[ META_PREFIX + suggestion.field ] ),
		} ) );
}

/**
 * The meta edit applying one suggestion, or null when the field is filled.
 *
 * Checked again at apply time, because the author may have typed into the
 * field after the suggestion arrived.
 *
 * @param {{field:string,value:string}} suggestion Planned suggestion.
 * @param {Object}                      meta       Current post meta.
 * @return {Object|null} Meta edit, or null.
 */
export function applyEdit( suggestion, meta ) {
	const key = META_PREFIX + suggestion.field;
	if ( isFilled( ( meta || {} )[ key ] ) ) {
		return null;
	}

	return { [ key ]: suggestion.value };
}
