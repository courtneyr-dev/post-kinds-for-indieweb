/**
 * Tests for kind-meta type coercion.
 *
 * Regression coverage for "meta._pkiw_read_isbn is not of type string" —
 * external lookups (OpenLibrary ISBNs, TMDB ids, years) return numbers for
 * fields registered as strings, and REST rejects the whole post save.
 */
import { coerceMetaValue } from '../../../src/editor/stores/coerce-meta-value';

describe( 'coerceMetaValue', () => {
	beforeEach( () => {
		window.pkiwAdminEditor = {
			metaFieldTypes: {
				read_isbn: 'string',
				read_title: 'string',
				watch_tmdb_id: 'string',
				watch_year: 'string',
				watch_rating: 'number',
				read_pages_total: 'integer',
				watch_spoilers: 'boolean',
			},
		};
	} );

	afterEach( () => {
		delete window.pkiwAdminEditor;
	} );

	it( 'stringifies numbers written to string fields', () => {
		expect( coerceMetaValue( 'read_isbn', 9780593156025 ) ).toBe(
			'9780593156025'
		);
		expect( coerceMetaValue( 'watch_tmdb_id', 97546 ) ).toBe( '97546' );
		expect( coerceMetaValue( 'watch_year', 2020 ) ).toBe( '2020' );
	} );

	it( 'leaves correctly-typed strings untouched', () => {
		expect( coerceMetaValue( 'read_title', 'A Day of Fallen Night' ) ).toBe(
			'A Day of Fallen Night'
		);
	} );

	it( 'numifies numeric strings written to number fields', () => {
		expect( coerceMetaValue( 'watch_rating', '4' ) ).toBe( 4 );
		expect( coerceMetaValue( 'read_pages_total', '880' ) ).toBe( 880 );
	} );

	it( 'leaves non-numeric strings alone on number fields', () => {
		expect( coerceMetaValue( 'watch_rating', 'unrated' ) ).toBe(
			'unrated'
		);
	} );

	it( 'drops objects and arrays on string fields rather than writing garbage', () => {
		expect( coerceMetaValue( 'read_isbn', { isbn: 1 } ) ).toBe( '' );
		expect( coerceMetaValue( 'read_isbn', [ '123' ] ) ).toBe( '' );
	} );

	it( 'passes through null and undefined', () => {
		expect( coerceMetaValue( 'read_isbn', null ) ).toBeNull();
		expect( coerceMetaValue( 'read_isbn', undefined ) ).toBeUndefined();
	} );

	it( 'passes through fields it has no registered type for', () => {
		expect( coerceMetaValue( 'not_a_registered_field', 42 ) ).toBe( 42 );
	} );

	it( 'passes values through when the type map is absent entirely', () => {
		delete window.pkiwAdminEditor;
		expect( coerceMetaValue( 'read_isbn', 9780593156025 ) ).toBe(
			9780593156025
		);
	} );

	it( 'leaves booleans on boolean fields alone', () => {
		expect( coerceMetaValue( 'watch_spoilers', true ) ).toBe( true );
	} );
} );
