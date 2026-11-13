export const TAG_CARD_BLOCK_NAME = 'post-kinds-indieweb/tag-card';

export default function hasTagCard( blockList ) {
	if ( ! Array.isArray( blockList ) ) {
		return false;
	}
	return blockList.some( ( block ) => TAG_CARD_BLOCK_NAME === block?.name || hasTagCard( block?.innerBlocks ) );
}
