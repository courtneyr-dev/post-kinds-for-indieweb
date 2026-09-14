/**
 * Mood vocabulary helper tests.
 *
 * The display and key rules run the same table as MoodVocabularyTest.php
 * (tests/phpunit/fixtures/mood-label-cases.json), so the editor picker and
 * the server-rendered card can't disagree.
 */

import { render, renderHook, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import cases from '../../phpunit/fixtures/mood-label-cases.json';
import {
	MoodSuggestions,
	moodDisplayLabel,
	moodKeyForLabel,
	useMoodVocabulary,
} from '../../../src/blocks/shared/mood-vocabulary';

/**
 * A fresh copy of the module (and its api-fetch mock) with no memoized request.
 *
 * Only for the plain fetch function: React hooks must use the top-level
 * import, or the isolated @wordpress/element copy breaks the renderer.
 *
 * @return {{fetchMoodVocabulary: Function, isolatedApiFetch: jest.Mock}} Isolated module parts.
 */
function isolatedFetch() {
	let fetchMoodVocabulary;
	let isolatedApiFetch;
	jest.isolateModules( () => {
		isolatedApiFetch = require( '@wordpress/api-fetch' );
		( {
			fetchMoodVocabulary,
		} = require( '../../../src/blocks/shared/mood-vocabulary' ) );
	} );
	// The api-fetch mock outlives isolateModules; clear its calls and results.
	isolatedApiFetch.mockReset();
	return { fetchMoodVocabulary, isolatedApiFetch };
}

describe( 'moodDisplayLabel', () => {
	test.each( cases.display )( '$case', ( { authored, key, expected } ) => {
		expect( moodDisplayLabel( authored, key, cases.moods ) ).toBe(
			expected
		);
	} );

	it( 'returns authored text while the vocabulary is still loading', () => {
		expect( moodDisplayLabel( 'Energized', 'energized', [] ) ).toBe(
			'Energized'
		);
		expect( moodDisplayLabel( undefined, '', [] ) ).toBe( '' );
	} );
} );

describe( 'moodKeyForLabel', () => {
	test.each( cases.keyForLabel )( '$case', ( { label, expected } ) => {
		expect( moodKeyForLabel( label, cases.moods ) ).toBe( expected );
	} );

	it( 'ignores surrounding spaces but not casing', () => {
		expect( moodKeyForLabel( ' Energised ', cases.moods ) ).toBe(
			'energized'
		);
		expect( moodKeyForLabel( 'energised', cases.moods ) ).toBe( '' );
	} );
} );

describe( 'fetchMoodVocabulary', () => {
	it( 'requests the moods route once and returns its moods', async () => {
		const { fetchMoodVocabulary, isolatedApiFetch } = isolatedFetch();
		isolatedApiFetch.mockResolvedValue( {
			spelling: 'en_GB',
			locale: 'en_GB',
			version: 'abc',
			moods: cases.moods,
		} );

		await expect( fetchMoodVocabulary() ).resolves.toEqual( cases.moods );
		await fetchMoodVocabulary();

		expect( isolatedApiFetch ).toHaveBeenCalledTimes( 1 );
		expect( isolatedApiFetch ).toHaveBeenCalledWith( {
			path: '/post-kinds-indieweb/v1/moods',
		} );
	} );

	it( 'resolves to no moods on failure and retries on the next call', async () => {
		const { fetchMoodVocabulary, isolatedApiFetch } = isolatedFetch();
		isolatedApiFetch
			.mockRejectedValueOnce( new Error( 'offline' ) )
			.mockResolvedValueOnce( { moods: cases.moods } );

		await expect( fetchMoodVocabulary() ).resolves.toEqual( [] );
		await expect( fetchMoodVocabulary() ).resolves.toEqual( cases.moods );
		expect( isolatedApiFetch ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'treats a response without a moods array as empty', async () => {
		const { fetchMoodVocabulary, isolatedApiFetch } = isolatedFetch();
		isolatedApiFetch.mockResolvedValue( { moods: 'nope' } );

		await expect( fetchMoodVocabulary() ).resolves.toEqual( [] );
	} );
} );

describe( 'useMoodVocabulary and MoodSuggestions', () => {
	it( 'loads moods into the hook', async () => {
		apiFetch.mockResolvedValue( { moods: cases.moods } );

		const { result } = renderHook( () => useMoodVocabulary() );

		expect( result.current ).toEqual( [] );
		await waitFor( () => expect( result.current ).toEqual( cases.moods ) );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/post-kinds-indieweb/v1/moods',
		} );
	} );

	it( 'renders one datalist option per resolved label', () => {
		const { container } = render(
			<MoodSuggestions id="moods" moods={ cases.moods } />
		);

		const options = [ ...container.querySelectorAll( '#moods option' ) ];
		expect( options.map( ( option ) => option.value ) ).toEqual( [
			'Energised',
			'Happy',
		] );
	} );
} );
