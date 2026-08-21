/**
 * Suggest a play-card block style from what's known about the game.
 *
 * Order of signal strength: explicit platform > lookup source > title
 * keywords. Returns a style slug (matching block.json "styles") or null
 * when there's no confident signal — callers only apply a suggestion
 * when the user hasn't picked a style themselves.
 */

const CONSOLE_PLATFORMS =
	/playstation|xbox|nintendo|switch|wii|sega|atari|dreamcast|gamecube|3ds|ps\d/i;

const COMPUTER_PLATFORMS =
	/windows|mac|linux|pc|ios|android|steam deck|browser|web/i;

const TITLE_HINTS = [
	[
		/\bttrpg\b|\brpg\b|dungeons\s*&?\s*dragons|\bd&d\b|\bdnd\b|pathfinder|call of cthulhu|\b5e\b|shadowrun/i,
		'ttrpg',
	],
	[ /\bdice\b|yahtzee|farkle|\bkniffel\b|tenzi/i, 'dice-game' ],
	[
		/\bcard game\b|\btcg\b|\bccg\b|magic: the gathering|\bmtg\b|pok[eé]mon tcg|\buno\b|solitaire/i,
		'card-game',
	],
];

/**
 * Map platform/source/title metadata to a block style slug.
 *
 * @param {Object} game          Game metadata.
 * @param {string} game.platform Platform label (see PLATFORM_OPTIONS).
 * @param {string} game.source   Lookup source ('bgg', 'rawg', 'steam', …).
 * @param {string} game.title    Game title.
 * @return {?string} Style slug or null.
 */
export function suggestPlayStyle( { platform = '', source = '', title = '' } ) {
	const p = platform.toLowerCase();

	if ( p ) {
		if ( p === 'board game' || p === 'miniatures' ) {
			return 'board-game';
		}
		if ( p === 'card game' ) {
			return 'card-game';
		}
		if ( p === 'tabletop rpg' ) {
			return 'ttrpg';
		}
		if ( p === 'dice game' ) {
			return 'dice-game';
		}
		if ( CONSOLE_PLATFORMS.test( p ) ) {
			return 'console-game';
		}
		if ( COMPUTER_PLATFORMS.test( p ) ) {
			return 'computer-game';
		}
	}

	for ( const [ pattern, slug ] of TITLE_HINTS ) {
		if ( pattern.test( title ) ) {
			return slug;
		}
	}

	// BGG catalogs tabletop; RAWG and Steam catalog video games.
	if ( source === 'bgg' ) {
		return 'board-game';
	}
	if ( source === 'steam' ) {
		return 'computer-game';
	}
	if ( source === 'rawg' ) {
		return 'console-game';
	}

	return null;
}

/**
 * Merge a suggested style into a block className, respecting any style
 * the user already picked.
 *
 * @param {string}  currentClassName Existing className attribute value.
 * @param {?string} slug             Style slug from suggestPlayStyle().
 * @return {?string} New className, or null when nothing should change.
 */
export function applySuggestedStyle( currentClassName = '', slug ) {
	if ( ! slug || /(^|\s)is-style-/.test( currentClassName || '' ) ) {
		return null;
	}
	const base = ( currentClassName || '' ).trim();
	return base ? `${ base } is-style-${ slug }` : `is-style-${ slug }`;
}
