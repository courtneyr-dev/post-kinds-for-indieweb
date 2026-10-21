/**
 * Comic card detection.
 *
 * @package
 * @since   1.9.0
 */

export const COMIC_CARD_BLOCK_NAME = 'post-kinds-indieweb/comic-card';

/**
 * Whether a block list contains this plugin's Comic Card.
 *
 * Searches inner blocks too, so a card nested in a group or columns still
 * counts.
 *
 * @param {Array<Object>} blockList Blocks to search.
 * @return {boolean} True when a Comic Card is present.
 */
export default function hasComicCard( blockList ) {
	if ( ! Array.isArray( blockList ) ) {
		return false;
	}

	for ( const block of blockList ) {
		if ( COMIC_CARD_BLOCK_NAME === block?.name ) {
			return true;
		}

		if ( block?.innerBlocks?.length && hasComicCard( block.innerBlocks ) ) {
			return true;
		}
	}

	return false;
}
