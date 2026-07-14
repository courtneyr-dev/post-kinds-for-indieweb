/**
 * Post Kinds for IndieWeb - Kind Selector Sidebar Panel
 *
 * Main sidebar panel component for selecting post kinds and managing
 * kind-specific metadata in the block editor.
 *
 * @package
 * @since   1.0.0
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useCallback, useRef } from '@wordpress/element';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { STORE_NAME } from '../stores/post-kinds';
import KindGrid from './components/KindGrid';
import KindFields from './components/KindFields';
import AutoDetectionNotice from './components/AutoDetectionNotice';
import { kindIcons } from './icons';

/**
 * Block names that indicate specific kinds.
 *
 * @type {Object}
 */
/**
 * Map block names to kind slugs (for auto-detection).
 *
 * @type {Object}
 */
const BLOCK_KIND_MAP = {
	'indieblocks/reply': 'reply',
	'indieblocks/like': 'like',
	'indieblocks/repost': 'repost',
	'indieblocks/bookmark': 'bookmark',
	'mamaduka/bookmark-card': 'bookmark',
	'core/gallery': 'photo',
	'core/image': 'photo',
	'core/video': 'video',
	'core/audio': 'listen',
};

/**
 * Map kind slugs to their corresponding card block names.
 * When a kind is selected, we auto-insert this block if not present.
 * Note: Bookmark kind is handled separately with embed type detection.
 *
 * @return {Object} Kind to block name mapping.
 */
function getKindCardBlockMap() {
	return {
		listen: 'post-kinds-indieweb/listen-card',
		watch: 'post-kinds-indieweb/watch-card',
		read: 'post-kinds-indieweb/read-card',
		checkin: 'post-kinds-indieweb/checkin-card',
		rsvp: 'post-kinds-indieweb/rsvp-card',
		play: 'post-kinds-indieweb/play-card',
		eat: 'post-kinds-indieweb/eat-card',
		drink: 'post-kinds-indieweb/drink-card',
		favorite: 'post-kinds-indieweb/favorite-card',
		jam: 'post-kinds-indieweb/jam-card',
		wish: 'post-kinds-indieweb/wish-card',
		mood: 'post-kinds-indieweb/mood-card',
		acquisition: 'post-kinds-indieweb/acquisition-card',
		// Note: bookmark is handled separately - see insertBookmarkBlock()
	};
}

/**
 * Get the appropriate block name for a bookmark based on embed type and oEmbed support.
 *
 * @param {string}  embedType        User's embed type preference (auto, oembed, bookmark-card, none).
 * @param {boolean} hasOembedSupport Whether the URL has oEmbed support.
 * @return {string|null} Block name to use, or null for no block.
 */
function getBookmarkBlockName( embedType, hasOembedSupport ) {
	const bookmarkCardActive = window.pkiwAdminEditor?.bookmarkCardActive;

	switch ( embedType ) {
		case 'oembed':
			return 'core/embed';
		case 'bookmark-card':
			return bookmarkCardActive ? 'mamaduka/bookmark-card' : null;
		case 'none':
			return null;
		case 'auto':
		default:
			// Auto mode: prefer oEmbed if supported, otherwise bookmark card.
			if ( hasOembedSupport ) {
				return 'core/embed';
			}
			return bookmarkCardActive ? 'mamaduka/bookmark-card' : null;
	}
}

/**
 * Detect kind from post content and blocks.
 *
 * @param {Array}  blockList Post blocks.
 * @param {string} title     Post title.
 * @return {string|null} Detected kind or null.
 */
function detectKindFromContent( blockList, title ) {
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

/**
 * Kind Selector Panel Component
 *
 * Renders the sidebar panel for selecting and configuring post kinds.
 *
 * @return {JSX.Element} The panel component.
 */
export default function KindSelectorPanel() {
	const {
		selectedKind,
		autoDetectedKind,
		isAutoDetectionEnabled,
		availableKinds,
		isInitialized,
		postTitle,
		blocks,
		citeUrl,
		bookmarkEmbedType,
	} = useSelect( ( select ) => {
		const kindsStore = select( STORE_NAME );
		const editor = select( editorStore );
		const blockEditor = select( blockEditorStore );

		return {
			selectedKind: kindsStore.getSelectedKind(),
			autoDetectedKind: kindsStore.getAutoDetectedKind(),
			isAutoDetectionEnabled: kindsStore.isAutoDetectionEnabled(),
			availableKinds: kindsStore.getAvailableKinds(),
			isInitialized: kindsStore.isInitialized(),
			postTitle: editor.getEditedPostAttribute( 'title' ),
			blocks: blockEditor.getBlocks(),
			citeUrl: kindsStore.getKindMeta( 'cite_url' ),
			bookmarkEmbedType:
				kindsStore.getKindMeta( 'bookmark_embed_type' ) || 'auto',
		};
	}, [] );

	const {
		initialize,
		setAutoDetectedKind,
		updatePostKind,
		disableAutoDetection,
	} = useDispatch( STORE_NAME );

	const { insertBlocks, replaceBlock } = useDispatch( blockEditorStore );

	/**
	 * Check if a card block for the given kind already exists in the post.
	 *
	 * @param {string} kind Kind slug.
	 * @return {boolean} True if card block exists.
	 */
	const hasCardBlockForKind = useCallback(
		( kind ) => {
			const kindCardBlockMap = getKindCardBlockMap();
			const blockName = kindCardBlockMap[ kind ];
			if ( ! blockName ) {
				return true; // No card block defined for this kind
			}

			// Check if any block matches the card block name
			const checkBlocks = ( blockList ) => {
				for ( const block of blockList ) {
					if ( block.name === blockName ) {
						return true;
					}
					// Check inner blocks recursively
					if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
						if ( checkBlocks( block.innerBlocks ) ) {
							return true;
						}
					}
				}
				return false;
			};

			return checkBlocks( blocks );
		},
		[ blocks ]
	);

	/**
	 * Insert card block for the given kind at the beginning of the post.
	 *
	 * @param {string} kind Kind slug.
	 */
	const insertCardBlock = useCallback(
		( kind ) => {
			const kindCardBlockMap = getKindCardBlockMap();
			const blockName = kindCardBlockMap[ kind ];
			if ( ! blockName ) {
				return; // No card block defined for this kind
			}

			// Don't insert if block already exists
			if ( hasCardBlockForKind( kind ) ) {
				return;
			}

			// Create and insert the block at the beginning
			const newBlock = createBlock( blockName );
			insertBlocks( newBlock, 0 );
		},
		[ hasCardBlockForKind, insertBlocks ]
	);

	// Initialize store on mount.
	useEffect( () => {
		if ( ! isInitialized ) {
			initialize();
		}
	}, [ isInitialized, initialize ] );

	// Track if we've already inserted an embed block for bookmark kind this session.
	const hasInsertedBookmarkEmbedRef = useRef( false );

	// Auto-insert embed block when bookmark kind is set (e.g., via post format).
	useEffect( () => {
		// Only proceed after initialization and when kind is bookmark.
		if ( ! isInitialized || selectedKind !== 'bookmark' ) {
			return;
		}

		// Only insert once per session.
		if ( hasInsertedBookmarkEmbedRef.current ) {
			return;
		}

		// Check if a bookmark-related block already exists.
		const checkForBookmarkBlock = ( blockList ) => {
			for ( const block of blockList ) {
				if (
					block.name === 'core/embed' ||
					block.name === 'mamaduka/bookmark-card' ||
					block.name === 'indieblocks/bookmark'
				) {
					return true;
				}
				if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
					if ( checkForBookmarkBlock( block.innerBlocks ) ) {
						return true;
					}
				}
			}
			return false;
		};

		if ( checkForBookmarkBlock( blocks ) ) {
			hasInsertedBookmarkEmbedRef.current = true;
			return;
		}

		// Insert a core/embed block at the beginning.
		// It will be converted to Bookmark Card if oEmbed fails.
		const newBlock = createBlock( 'core/embed' );
		insertBlocks( newBlock, 0 );
		hasInsertedBookmarkEmbedRef.current = true;
	}, [ isInitialized, selectedKind, blocks, insertBlocks ] );

	// Auto-detect kind based on content.
	useEffect( () => {
		if ( ! isAutoDetectionEnabled || selectedKind ) {
			return;
		}

		const detectedKind = detectKindFromContent( blocks, postTitle );
		setAutoDetectedKind( detectedKind );
	}, [
		blocks,
		postTitle,
		isAutoDetectionEnabled,
		selectedKind,
		setAutoDetectedKind,
	] );

	// Sync Bookmark Card block attributes to citation post meta.
	const { updateKindMeta } = useDispatch( STORE_NAME );

	useEffect( () => {
		// Only sync for bookmark kind when Bookmark Card plugin is active.
		if ( ! window.pkiwAdminEditor?.bookmarkCardActive ) {
			return;
		}

		// Find the Bookmark Card block in the post.
		const findBookmarkCard = ( blockList ) => {
			for ( const block of blockList ) {
				if ( block.name === 'mamaduka/bookmark-card' ) {
					return block;
				}
				if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
					const found = findBookmarkCard( block.innerBlocks );
					if ( found ) {
						return found;
					}
				}
			}
			return null;
		};

		const bookmarkCard = findBookmarkCard( blocks );
		if ( ! bookmarkCard ) {
			return;
		}

		// Sync block attributes to post meta.
		const { url, title, description, publisher, image } =
			bookmarkCard.attributes;

		if ( url ) {
			updateKindMeta( 'cite_url', url );
		}
		if ( title ) {
			updateKindMeta( 'cite_name', title );
		}
		if ( description ) {
			updateKindMeta( 'cite_summary', description );
		}
		if ( publisher ) {
			updateKindMeta( 'cite_author', publisher );
		}
		if ( image ) {
			updateKindMeta( 'cite_photo', image );
		}
	}, [ blocks, updateKindMeta ] );

	// Track last URL we inserted a block for to avoid duplicate insertions.
	const lastInsertedUrlRef = useRef( '' );

	// Handle bookmark block insertion based on URL and embed type.
	useEffect( () => {
		// Only process for bookmark kind.
		const currentKind = selectedKind || autoDetectedKind;
		if ( currentKind !== 'bookmark' ) {
			return;
		}

		// Need a valid URL.
		if ( ! citeUrl || ! citeUrl.startsWith( 'http' ) ) {
			return;
		}

		// Don't re-insert for the same URL.
		if ( lastInsertedUrlRef.current === citeUrl ) {
			return;
		}

		// Check if we already have a bookmark-related block in the post.
		const hasBookmarkBlock = blocks.some(
			( block ) =>
				block.name === 'core/embed' ||
				block.name === 'mamaduka/bookmark-card' ||
				block.name === 'indieblocks/bookmark'
		);

		if ( hasBookmarkBlock ) {
			// Already have a block, update the ref.
			lastInsertedUrlRef.current = citeUrl;
			return;
		}

		// For "none" embed type, don't insert any block.
		if ( bookmarkEmbedType === 'none' ) {
			lastInsertedUrlRef.current = citeUrl;
			return;
		}

		// Check oEmbed support and insert appropriate block.
		const insertBookmarkBlock = async () => {
			let hasOembedSupport = false;

			// Check oEmbed support if we might need it.
			if (
				bookmarkEmbedType === 'auto' ||
				bookmarkEmbedType === 'oembed'
			) {
				try {
					const response = await apiFetch( {
						path: `/post-kinds-indieweb/v1/check-oembed?url=${ encodeURIComponent(
							citeUrl
						) }`,
					} );
					hasOembedSupport = response?.supported || false;
				} catch {
					hasOembedSupport = false;
				}
			}

			// Determine which block to insert.
			const blockName = getBookmarkBlockName(
				bookmarkEmbedType,
				hasOembedSupport
			);

			if ( ! blockName ) {
				lastInsertedUrlRef.current = citeUrl;
				return;
			}

			// Create and insert the block.
			let newBlock;
			if ( blockName === 'core/embed' ) {
				newBlock = createBlock( 'core/embed', { url: citeUrl } );
			} else if ( blockName === 'mamaduka/bookmark-card' ) {
				newBlock = createBlock( 'mamaduka/bookmark-card', {
					url: citeUrl,
				} );
			}

			if ( newBlock ) {
				insertBlocks( newBlock, 0 );
				lastInsertedUrlRef.current = citeUrl;
			}
		};

		insertBookmarkBlock();
	}, [
		citeUrl,
		bookmarkEmbedType,
		selectedKind,
		autoDetectedKind,
		blocks,
		insertBlocks,
	] );

	// Track which embed blocks we've already processed to avoid infinite loops.
	const processedEmbedsRef = useRef( new Set() );

	// Detect failed embed blocks and convert them to bookmark cards.
	// This runs with a delay to give embeds time to resolve first.
	useEffect( () => {
		// Only process for bookmark kind when Bookmark Card plugin is active.
		const currentKind = selectedKind || autoDetectedKind;
		if ( currentKind !== 'bookmark' ) {
			return;
		}

		if ( ! window.pkiwAdminEditor?.bookmarkCardActive ) {
			return;
		}

		// Find embed blocks that might need conversion.
		const findEmbedsToCheck = ( blockList ) => {
			const embeds = [];
			for ( const block of blockList ) {
				if ( block.name === 'core/embed' && block.attributes?.url ) {
					const { url, providerNameSlug } = block.attributes;

					// Skip if already processed or if it has a known provider (successful embed).
					if ( processedEmbedsRef.current.has( block.clientId ) ) {
						continue;
					}

					// If it already has a provider slug, it's working - skip it.
					if ( providerNameSlug ) {
						processedEmbedsRef.current.add( block.clientId );
						continue;
					}

					embeds.push( { block, url } );
				}

				// Check inner blocks recursively.
				if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
					embeds.push( ...findEmbedsToCheck( block.innerBlocks ) );
				}
			}
			return embeds;
		};

		const embedsToCheck = findEmbedsToCheck( blocks );

		if ( embedsToCheck.length === 0 ) {
			return;
		}

		// Delay the check to give WordPress time to resolve the embed.
		const timeoutId = setTimeout( async () => {
			for ( const { block, url } of embedsToCheck ) {
				// Skip if already processed (might have been processed by another run).
				if ( processedEmbedsRef.current.has( block.clientId ) ) {
					continue;
				}

				// Mark as processed.
				processedEmbedsRef.current.add( block.clientId );

				// Check if this URL has oEmbed support.
				let hasOembedSupport = false;
				try {
					const response = await apiFetch( {
						path: `/post-kinds-indieweb/v1/check-oembed?url=${ encodeURIComponent(
							url
						) }`,
					} );
					hasOembedSupport = response?.supported || false;
				} catch {
					hasOembedSupport = false;
				}

				// If no oEmbed support, convert to bookmark card.
				if ( ! hasOembedSupport ) {
					const bookmarkCard = createBlock(
						'mamaduka/bookmark-card',
						{ url }
					);
					replaceBlock( block.clientId, bookmarkCard );
				}
			}
		}, 1500 ); // Wait 1.5 seconds for embed to try resolving.

		return () => clearTimeout( timeoutId );
	}, [ blocks, selectedKind, autoDetectedKind, replaceBlock ] );

	/**
	 * Check if a bookmark-related block already exists in the post.
	 *
	 * @return {boolean} True if a bookmark block exists.
	 */
	const hasBookmarkBlock = useCallback( () => {
		const checkBlocks = ( blockList ) => {
			for ( const block of blockList ) {
				if (
					block.name === 'core/embed' ||
					block.name === 'mamaduka/bookmark-card' ||
					block.name === 'indieblocks/bookmark'
				) {
					return true;
				}
				if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
					if ( checkBlocks( block.innerBlocks ) ) {
						return true;
					}
				}
			}
			return false;
		};
		return checkBlocks( blocks );
	}, [ blocks ] );

	/**
	 * Insert an embed block for bookmark kind.
	 * Uses core/embed which will fall back to Bookmark Card if oEmbed fails.
	 */
	const insertBookmarkEmbedBlock = useCallback( () => {
		// Don't insert if a bookmark block already exists.
		if ( hasBookmarkBlock() ) {
			return;
		}

		// Insert a core/embed block - it will be converted to Bookmark Card
		// if oEmbed fails (handled by the failed embed detection useEffect).
		const newBlock = createBlock( 'core/embed' );
		insertBlocks( newBlock, 0 );
	}, [ hasBookmarkBlock, insertBlocks ] );

	/**
	 * Handle kind selection.
	 *
	 * @param {string} kind Kind slug.
	 */
	function handleKindSelect( kind ) {
		updatePostKind( kind );
		disableAutoDetection();

		// For bookmark kind, insert embed block at the start.
		// Failed embeds will be converted to Bookmark Card automatically.
		if ( kind === 'bookmark' ) {
			insertBookmarkEmbedBlock();
			return;
		}

		// Auto-insert the corresponding card block if not already present
		insertCardBlock( kind );
	}

	/**
	 * Handle accepting auto-detected kind.
	 */
	function handleAcceptAutoDetected() {
		if ( autoDetectedKind ) {
			updatePostKind( autoDetectedKind );
			disableAutoDetection();

			// For bookmark kind, insert embed block.
			if ( autoDetectedKind === 'bookmark' ) {
				insertBookmarkEmbedBlock();
				return;
			}

			// Auto-insert the corresponding card block if not already present
			insertCardBlock( autoDetectedKind );
		}
	}

	// Get the current kind (selected or auto-detected).
	const currentKind = selectedKind || autoDetectedKind || 'note';
	const currentKindData = availableKinds.find(
		( k ) => k.slug === currentKind
	);

	// Get icon for current kind.
	const KindIcon = kindIcons[ currentKind ] || kindIcons.note;

	return (
		<PluginDocumentSettingPanel
			name="post-kinds-indieweb-kind-selector"
			title={ __( 'Post Kind', 'post-kinds-for-indieweb' ) }
			className="post-kinds-indieweb-kind-panel"
			icon={ <KindIcon /> }
		>
			{ /* Auto-detection notice */ }
			{ ! selectedKind && autoDetectedKind && (
				<AutoDetectionNotice
					detectedKind={ autoDetectedKind }
					kindLabel={ currentKindData?.name || autoDetectedKind }
					onAccept={ handleAcceptAutoDetected }
					onDismiss={ () => setAutoDetectedKind( null ) }
				/>
			) }

			{ /* Kind selection grid */ }
			<KindGrid
				kinds={ availableKinds }
				selectedKind={ currentKind }
				onSelect={ handleKindSelect }
			/>

			{ /* Kind-specific fields */ }
			{ currentKind && <KindFields kind={ currentKind } /> }
		</PluginDocumentSettingPanel>
	);
}
