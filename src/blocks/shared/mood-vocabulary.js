/**
 * Mood vocabulary for editor pickers.
 *
 * Labels come from GET /post-kinds-indieweb/v1/moods, already resolved for
 * the site's mood spelling setting rather than the current user's interface
 * language, so a suggestion the author picks is the text the site publishes.
 * moodDisplayLabel() mirrors PKIW\Mood_Vocabulary::display_label().
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

export const MOOD_VOCABULARY_PATH = '/post-kinds-indieweb/v1/moods';

let vocabularyRequest = null;

/**
 * Fetch the resolved mood vocabulary once per editor load.
 *
 * @return {Promise<Array<{key: string, label: string, variants: string[]}>>} Moods, or [] when the request fails.
 */
export function fetchMoodVocabulary() {
	if ( ! vocabularyRequest ) {
		vocabularyRequest = apiFetch( { path: MOOD_VOCABULARY_PATH } )
			.then( ( data ) =>
				Array.isArray( data?.moods ) ? data.moods : []
			)
			.catch( () => {
				// Typing a label still works without suggestions; retry next mount.
				vocabularyRequest = null;
				return [];
			} );
	}

	return vocabularyRequest;
}

/**
 * React hook returning the resolved mood vocabulary ([] until loaded).
 *
 * @return {Array<{key: string, label: string, variants: string[]}>} Moods.
 */
export function useMoodVocabulary() {
	const [ moods, setMoods ] = useState( [] );

	useEffect( () => {
		let active = true;
		fetchMoodVocabulary().then( ( list ) => {
			if ( active ) {
				setMoods( list );
			}
		} );
		return () => {
			active = false;
		};
	}, [] );

	return moods;
}

/**
 * Key for text that exactly matches a mood's current label.
 *
 * Only the label resolved for the site's spelling counts: typing another
 * spelling ("Energized" on a UK-spelling site) is a custom label.
 *
 * @param {string} label Label text.
 * @param {Array}  moods Resolved moods.
 * @return {string} Mood key, or '' for custom text.
 */
export function moodKeyForLabel( label, moods ) {
	const text = typeof label === 'string' ? label.trim() : '';
	if ( '' === text ) {
		return '';
	}

	const match = moods.find( ( mood ) => mood.label === text );
	return match ? match.key : '';
}

/**
 * Display text for a stored label, matching the server render.
 *
 * @param {string} authored Stored label text.
 * @param {string} key      Stored mood key.
 * @param {Array}  moods    Resolved moods.
 * @return {string} Resolved label for untouched vocabulary picks, otherwise the authored text.
 */
export function moodDisplayLabel( authored, key, moods ) {
	const text = typeof authored === 'string' ? authored : '';
	if ( ! key ) {
		return text;
	}

	const mood = moods.find( ( item ) => item.key === key );
	if ( ! mood ) {
		return text;
	}

	const trimmed = text.trim();
	if ( '' === trimmed || ( mood.variants || [] ).includes( trimmed ) ) {
		return mood.label;
	}

	return text;
}

/**
 * Native suggestion list for a text input's `list` attribute.
 *
 * @param {Object} props       Component props.
 * @param {string} props.id    Element id the input's `list` references.
 * @param {Array}  props.moods Resolved moods.
 * @return {JSX.Element} Datalist element.
 */
export function MoodSuggestions( { id, moods } ) {
	return (
		<datalist id={ id }>
			{ moods.map( ( mood ) => (
				<option key={ mood.key } value={ mood.label } />
			) ) }
		</datalist>
	);
}
