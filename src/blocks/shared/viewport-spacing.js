/**
 * Card spacing that follows the editor's selected viewport.
 *
 * WordPress 7.1 stores per-viewport styles under `style['@mobile']` and
 * `style['@tablet']`, and renders them as `@media` rules on the front end.
 * WordPress 7.2 exports getStyleForState() and setStyleForState() from
 * `@wordpress/block-editor` for reading and writing the selected state.
 * Where those exports are missing the card control stays hidden and the
 * core Dimensions panel remains the way to set padding.
 */

import * as blockEditor from '@wordpress/block-editor';

const SIDES = [ 'top', 'right', 'bottom', 'left' ];

/**
 * The public style-state helpers, or null on a core that doesn't export them.
 *
 * @param {Object} pkg The `@wordpress/block-editor` package.
 * @return {?Object} Helpers with getStyleForState and setStyleForState, or null.
 */
export function getStyleStateApi( pkg = blockEditor ) {
	const { getStyleForState, setStyleForState } = pkg ?? {};
	return typeof getStyleForState === 'function' &&
		typeof setStyleForState === 'function'
		? { getStyleForState, setStyleForState }
		: null;
}

/**
 * Spacing preset options, the same origins core's spacing control reads.
 *
 * @param {Array|undefined} custom   User presets.
 * @param {Array|undefined} theme    Theme presets.
 * @param {Array|undefined} defaults Core default presets, when enabled.
 * @return {Array<{label: string, value: string}>} Options; '' means no card value.
 */
export function spacingOptions( custom, theme, defaults ) {
	return [ ...( custom ?? [] ), ...( theme ?? [] ), ...( defaults ?? [] ) ]
		.filter( ( size ) => size?.slug )
		.map( ( size ) => ( {
			label: size.name || size.slug,
			value: `var:preset|spacing|${ size.slug }`,
		} ) );
}

/**
 * The card padding preset set for a style state, when all sides share it.
 *
 * @param {Object|undefined} style Block style attribute.
 * @param {Object}           state Selected style state.
 * @param {Object}           api   Helpers from getStyleStateApi().
 * @return {string} Preset reference, or '' when unset or mixed.
 */
export function getCardPadding( style, state, api ) {
	const padding = api.getStyleForState( style ?? {}, state )?.spacing
		?.padding;
	if ( typeof padding === 'string' ) {
		return padding;
	}
	const first = padding?.top;
	return first && SIDES.every( ( side ) => padding[ side ] === first )
		? first
		: '';
}

/**
 * The style attribute with card padding set, or cleared, for one state.
 *
 * Writes only spacing.padding inside the selected state, so the base
 * style, other states and every other style property stay as they were.
 *
 * @param {Object|undefined} style Block style attribute.
 * @param {Object}           state Selected style state.
 * @param {string}           value Preset reference, or '' to clear.
 * @param {Object}           api   Helpers from getStyleStateApi().
 * @return {Object|undefined} New style attribute.
 */
export function setCardPadding( style, state, value, api ) {
	const current = api.getStyleForState( style ?? {}, state ) ?? {};
	const spacing = { ...current.spacing };
	if ( value ) {
		spacing.padding = Object.fromEntries(
			SIDES.map( ( side ) => [ side, value ] )
		);
	} else {
		delete spacing.padding;
	}
	return api.setStyleForState( style ?? {}, state, { ...current, spacing } );
}
