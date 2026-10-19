/**
 * Listen block detection.
 *
 * @package
 * @since   1.9.0
 */

export const LISTEN_BLOCK_NAME = 'post-kinds-indieweb/listen-card';

/**
 * Whether a block list contains this plugin's Listen Card.
 *
 * Searches inner blocks too, so a card nested in a group or columns still
 * counts.
 *
 * @param {Array<Object>} blockList Blocks to search.
 * @return {boolean} True when a Listen Card is present.
 */
export default function hasListenBlock( blockList ) {
	if ( ! Array.isArray( blockList ) ) {
		return false;
	}

	for ( const block of blockList ) {
		if ( LISTEN_BLOCK_NAME === block?.name ) {
			return true;
		}

		if (
			block?.innerBlocks?.length &&
			hasListenBlock( block.innerBlocks )
		) {
			return true;
		}
	}

	return false;
}
