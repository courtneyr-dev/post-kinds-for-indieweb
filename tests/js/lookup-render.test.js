/**
 * admin/js/lookup-render.js — lookup/search result items must render as
 * text, never as parsed HTML (finding K2).
 *
 * admin/js/admin.js used to build these results by string-concatenating
 * API response fields straight into `.html()`, so a provider's title
 * field containing markup (e.g. `<img src=x onerror=...>`) executed in
 * the admin's browser. This module builds the same markup with
 * `document.createElement`/`textContent` instead, so a malicious title
 * can only ever appear as literal text.
 */

const {
	buildLookupResultItem,
	buildSearchResultItem,
} = require( '../../admin/js/lookup-render.js' );

const MALICIOUS_TITLE = '<img src=x onerror="window.pwned = true">';

describe( 'buildLookupResultItem', () => {
	it( 'renders a malicious title as text, not an element', () => {
		const el = buildLookupResultItem( { title: MALICIOUS_TITLE } );

		expect( el.querySelector( 'img' ) ).toBeNull();
		expect( el.querySelector( 'strong' ).textContent ).toBe(
			MALICIOUS_TITLE
		);
	} );

	it( 'falls back to name when title is absent', () => {
		const el = buildLookupResultItem( { name: 'A Track' } );
		expect( el.querySelector( 'strong' ).textContent ).toBe( 'A Track' );
	} );

	it( 'appends artist and year as plain text', () => {
		const el = buildLookupResultItem( {
			title: 'A Track',
			artist: MALICIOUS_TITLE,
			year: '2020',
		} );

		expect( el.querySelector( 'img' ) ).toBeNull();
		expect( el.textContent ).toBe( 'A Track' + MALICIOUS_TITLE + ' (2020)' );
	} );
} );

describe( 'buildSearchResultItem', () => {
	it( 'renders a malicious title as text, not an element', () => {
		const el = buildSearchResultItem( { title: MALICIOUS_TITLE } );

		expect( el.querySelector( 'img' ) ).toBeNull();
		expect(
			el.querySelector( '.search-result-title' ).textContent
		).toBe( MALICIOUS_TITLE );
	} );

	it( 'renders a legitimate image src attribute', () => {
		const el = buildSearchResultItem( {
			title: 'A Movie',
			image: 'https://example.com/cover.jpg',
		} );

		const img = el.querySelector( 'img' );
		expect( img ).not.toBeNull();
		expect( img.getAttribute( 'src' ) ).toBe(
			'https://example.com/cover.jpg'
		);
	} );

	it( 'joins artist, year and author as plain text in the subtitle', () => {
		const el = buildSearchResultItem( {
			title: 'A Movie',
			artist: MALICIOUS_TITLE,
			year: '2020',
			author: ' by Someone',
		} );

		const subtitle = el.querySelector( '.search-result-subtitle' );
		expect( subtitle.querySelector( 'img' ) ).toBeNull();
		expect( subtitle.textContent ).toBe(
			MALICIOUS_TITLE + ' - 2020 by Someone'
		);
	} );
} );
