/**
 * Comic Card labels and dates.
 *
 * The editor preview and render.php print the same status labels, issue
 * line and date lines; this module is the editor's copy of that logic.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Reader-facing label for a stored reading status.
 *
 * @param {string} status Stored readStatus.
 * @return {string} Label, or an empty string for an unknown status.
 */
export function statusLabel( status ) {
	switch ( status ) {
		case 'to-read':
			return __( 'To read', 'post-kinds-for-indieweb-in-block-themes' );
		case 'reading':
			return __(
				'Currently reading',
				'post-kinds-for-indieweb-in-block-themes'
			);
		case 'finished':
			return __( 'Finished', 'post-kinds-for-indieweb-in-block-themes' );
		case 'abandoned':
			return __( 'Set aside', 'post-kinds-for-indieweb-in-block-themes' );
		default:
			return '';
	}
}

/**
 * The stored parts of the issue line: series, volume, issue number.
 *
 * @param {Object} attributes             Block attributes.
 * @param {string} attributes.series      Series name.
 * @param {string} attributes.volume      Volume.
 * @param {string} attributes.issueNumber Issue number.
 * @return {string[]} Parts to show, in order.
 */
export function issueParts( { series, volume, issueNumber } ) {
	const parts = [];
	if ( series ) {
		parts.push( series );
	}
	if ( volume ) {
		parts.push(
			sprintf(
				/* translators: %s: volume number */
				__( 'Vol. %s', 'post-kinds-for-indieweb-in-block-themes' ),
				volume
			)
		);
	}
	if ( issueNumber ) {
		parts.push(
			sprintf(
				/* translators: %s: issue number */
				__( '#%s', 'post-kinds-for-indieweb-in-block-themes' ),
				issueNumber
			)
		);
	}
	return parts;
}

/**
 * The calendar day a stored date opens with.
 *
 * Reading dates are days, not instants, so the day is read from the text
 * and never converted through a timezone.
 *
 * @param {string} value Stored date or date-time.
 * @return {string} YYYY-MM-DD, or an empty string.
 */
export function calendarDay( value ) {
	const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(
		String( value || '' ).trim()
	);
	if ( ! match ) {
		return '';
	}
	const [ day, year, month, date ] = match;
	const check = new Date(
		Date.UTC( Number( year ), Number( month ) - 1, Number( date ) )
	);
	return check.getUTCMonth() === Number( month ) - 1 &&
		check.getUTCDate() === Number( date )
		? day
		: '';
}

/**
 * A stored date as a long, readable day.
 *
 * @param {string} value    Stored date or date-time.
 * @param {string} [locale] Locale; the browser's when omitted.
 * @return {string} Display date, or an empty string.
 */
export function displayDay( value, locale ) {
	const day = calendarDay( value );
	if ( ! day ) {
		return '';
	}
	const [ year, month, date ] = day.split( '-' ).map( Number );
	return new Date( Date.UTC( year, month - 1, date, 12 ) ).toLocaleDateString(
		locale,
		{
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			timeZone: 'UTC',
		}
	);
}

/**
 * The date lines a card shows for its status.
 *
 * A comic not started shows none; one still being read shows only its start.
 *
 * @param {Object} attributes            Block attributes.
 * @param {string} attributes.readStatus Stored status.
 * @param {string} attributes.startedAt  Stored start date.
 * @param {string} attributes.finishedAt Stored end date.
 * @param {string} [locale]              Locale; the browser's when omitted.
 * @return {string[]} Lines to show, in order.
 */
export function dateLines( { readStatus, startedAt, finishedAt }, locale ) {
	const lines = [];
	if ( readStatus === 'to-read' ) {
		return lines;
	}
	const started = displayDay( startedAt, locale );
	if ( started ) {
		lines.push(
			sprintf(
				/* translators: %s: date */
				__( 'Started: %s', 'post-kinds-for-indieweb-in-block-themes' ),
				started
			)
		);
	}
	const ended = displayDay( finishedAt, locale );
	if ( ended && readStatus === 'finished' ) {
		lines.push(
			sprintf(
				/* translators: %s: date */
				__( 'Finished: %s', 'post-kinds-for-indieweb-in-block-themes' ),
				ended
			)
		);
	} else if ( ended && readStatus === 'abandoned' ) {
		lines.push(
			sprintf(
				/* translators: %s: date */
				__(
					'Set aside: %s',
					'post-kinds-for-indieweb-in-block-themes'
				),
				ended
			)
		);
	}
	return lines;
}
