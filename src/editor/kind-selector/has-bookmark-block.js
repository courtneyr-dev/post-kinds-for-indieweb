/**
 * Bookmark block detection.
 *
 * Used to decide whether a bookmark post already has somewhere to put its
 * link, so the editor does not add a second one.
 *
 * @package
 * @since   1.0.0
 */

/**
 * Blocks that already give a bookmark post somewhere to put its URL.
 *
 * This plugin's own card belongs here as much as the third-party ones. Leaving
 * it out meant every bookmark post built with a Post Kinds Bookmark Card also
 * got an empty Embed block it never needed.
 *
 * @type {Array<string>}
 */
export const BOOKMARK_BLOCK_NAMES = [
	'core/embed',
	'post-kinds-indieweb/bookmark-card',
	'mamaduka/bookmark-card',
	'indieblocks/bookmark',
];

/**
 * Whether a block list already contains somewhere to put a bookmark's link.
 *
 * Searches inner blocks too, so a card nested in a group or columns still
 * counts.
 *
 * @param {Array<Object>} blockList Blocks to search.
 * @return {boolean} True when one of the bookmark blocks is present.
 */
export default function hasBookmarkBlock( blockList ) {
	if ( ! Array.isArray( blockList ) ) {
		return false;
	}

	for ( const block of blockList ) {
		if ( BOOKMARK_BLOCK_NAMES.includes( block?.name ) ) {
			return true;
		}

		if (
			block?.innerBlocks?.length &&
			hasBookmarkBlock( block.innerBlocks )
		) {
			return true;
		}
	}

	return false;
}
