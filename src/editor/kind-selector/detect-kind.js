/**
 * Post kind detection from editor content.
 *
 * @package
 * @since   1.9.0
 */

import hasListenBlock from './has-listen-block';

/**
 * Map block names to kind slugs (for auto-detection).
 *
 * @type {Object}
 */
export const BLOCK_KIND_MAP = {
	'indieblocks/reply': 'reply',
	'indieblocks/like': 'like',
	'indieblocks/repost': 'repost',
	'indieblocks/bookmark': 'bookmark',
	'mamaduka/bookmark-card': 'bookmark',
	'core/gallery': 'photo',
	'core/image': 'photo',
	'core/video': 'video',
	'core/audio': 'audio',
};

/**
 * Detect kind from post content and blocks.
 *
 * @param {Array}  blockList Post blocks.
 * @param {string} title     Post title.
 * @return {string|null} Detected kind or null.
 */
export function detectKindFromContent( blockList, title ) {
	if ( hasListenBlock( blockList ) ) {
		return 'listen';
	}

	// Check for IndieBlocks blocks first.
	for ( const block of blockList ) {
		if ( BLOCK_KIND_MAP[ block.name ] ) {
			return BLOCK_KIND_MAP[ block.name ];
		}

		// Check inner blocks recursively.
		if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
			const innerKind = detectKindFromContent( block.innerBlocks, title );
			if ( innerKind ) {
				return innerKind;
			}
		}
	}

	// Check for media-heavy posts.
	const hasGallery = blockList.some( ( b ) => b.name === 'core/gallery' );
	const imageCount = blockList.filter(
		( b ) => b.name === 'core/image'
	).length;

	if ( hasGallery || imageCount >= 3 ) {
		return 'photo';
	}

	if ( blockList.some( ( b ) => b.name === 'core/video' ) ) {
		return 'video';
	}

	// Determine article vs note based on title.
	if ( title && title.trim().length > 0 ) {
		return 'article';
	}

	return 'note';
}
