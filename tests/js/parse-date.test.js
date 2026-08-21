/**
 * parseDate — the guard between string date attributes and toISOString().
 *
 * Block attributes typed as bare strings accept whatever an importer,
 * Micropub client, or REST write hands them, and toISOString() is the one
 * Date method that throws on an Invalid Date instead of degrading. The
 * event and RSVP cards crashed into Gutenberg's error boundary over
 * exactly this; every date render now goes through parseDate first.
 */

import { parseDate } from '../../src/blocks/shared/components';

describe( 'parseDate', () => {
	it( 'parses an ISO datetime', () => {
		const date = parseDate( '2026-09-01T19:00:00' );
		expect( date ).toBeInstanceOf( Date );
		expect( () => date.toISOString() ).not.toThrow();
	} );

	it( 'parses a date-only string', () => {
		expect( parseDate( '2026-07-04' ) ).toBeInstanceOf( Date );
	} );

	it.each( [
		[ 'free text', 'next Tuesday' ],
		[ 'the e2e field-matrix sample', 'Sample eventStart value' ],
		[ 'empty string', '' ],
		[ 'undefined', undefined ],
		[ 'null', null ],
	] )( 'returns null for %s', ( _label, value ) => {
		expect( parseDate( value ) ).toBeNull();
	} );

	it( 'never yields a Date whose toISOString() throws', () => {
		// The property the crash depended on: anything parseDate returns
		// must be safe to serialize.
		const inputs = [
			'2026-09-01T19:00:00',
			'garbage',
			'0000-99-99',
			'2026-13-45T99:99:99',
			'Sat Jul 04 2026',
		];
		const serialize = () =>
			inputs
				.map( parseDate )
				.filter( ( date ) => date !== null )
				.map( ( date ) => date.toISOString() );
		expect( serialize ).not.toThrow();
	} );
} );
