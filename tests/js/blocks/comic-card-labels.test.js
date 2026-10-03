/**
 * Tests for the Comic Card's label and date logic.
 *
 * The editor preview reads its status label, issue line and date lines from
 * this module; render.php prints the same strings on the front end
 * (ComicCardTest covers that half).
 *
 * @package
 */

import {
	calendarDay,
	dateLines,
	displayDay,
	issueParts,
	statusLabel,
} from '../../../src/blocks/comic-card/labels';

describe( 'statusLabel', () => {
	it( 'names each stored status the way the front end does', () => {
		expect( statusLabel( 'to-read' ) ).toBe( 'To read' );
		expect( statusLabel( 'reading' ) ).toBe( 'Currently reading' );
		expect( statusLabel( 'finished' ) ).toBe( 'Finished' );
		expect( statusLabel( 'abandoned' ) ).toBe( 'Set aside' );
	} );

	it( 'returns nothing for a status it does not know', () => {
		expect( statusLabel( 'devoured' ) ).toBe( '' );
	} );
} );

describe( 'issueParts', () => {
	it( 'lists series, volume and issue number in that order', () => {
		expect(
			issueParts( { series: 'Saga', volume: '2', issueNumber: '7' } )
		).toEqual( [ 'Saga', 'Vol. 2', '#7' ] );
	} );

	it( 'leaves out what is not stored', () => {
		expect( issueParts( { series: 'Saga' } ) ).toEqual( [ 'Saga' ] );
		expect( issueParts( { issueNumber: '1A' } ) ).toEqual( [ '#1A' ] );
		expect( issueParts( {} ) ).toEqual( [] );
	} );
} );

describe( 'calendarDay', () => {
	it( 'keeps the day of a bare date', () => {
		expect( calendarDay( '2026-09-06' ) ).toBe( '2026-09-06' );
	} );

	it( 'keeps the day of a date-time, whatever its offset', () => {
		expect( calendarDay( '2026-09-06T00:00:00' ) ).toBe( '2026-09-06' );
		expect( calendarDay( '2026-09-06T23:30:00-04:00' ) ).toBe(
			'2026-09-06'
		);
	} );

	it( 'returns nothing for an empty or unreadable value', () => {
		expect( calendarDay( '' ) ).toBe( '' );
		expect( calendarDay( undefined ) ).toBe( '' );
		expect( calendarDay( 'last week' ) ).toBe( '' );
		expect( calendarDay( '2026-13-40' ) ).toBe( '' );
	} );
} );

describe( 'displayDay', () => {
	it( 'prints the stored day, not the day before', () => {
		expect( displayDay( '2026-09-06', 'en-US' ) ).toBe(
			'September 6, 2026'
		);
	} );
} );

describe( 'dateLines', () => {
	const dates = { startedAt: '2026-08-30', finishedAt: '2026-09-02' };

	it( 'labels a comic still being read as started and shows no end date', () => {
		expect(
			dateLines( { ...dates, readStatus: 'reading' }, 'en-US' )
		).toEqual( [ 'Started: August 30, 2026' ] );
	} );

	it( 'gives a finished comic its completion date', () => {
		expect(
			dateLines( { ...dates, readStatus: 'finished' }, 'en-US' )
		).toEqual( [
			'Started: August 30, 2026',
			'Finished: September 2, 2026',
		] );
	} );

	it( 'labels the end date of a comic set aside', () => {
		expect(
			dateLines( { ...dates, readStatus: 'abandoned' }, 'en-US' )
		).toEqual( [
			'Started: August 30, 2026',
			'Set aside: September 2, 2026',
		] );
	} );

	it( 'shows no dates for a comic not started', () => {
		expect(
			dateLines( { ...dates, readStatus: 'to-read' }, 'en-US' )
		).toEqual( [] );
	} );

	it( 'shows no dates when none are stored', () => {
		expect( dateLines( { readStatus: 'reading' }, 'en-US' ) ).toEqual( [] );
	} );
} );
