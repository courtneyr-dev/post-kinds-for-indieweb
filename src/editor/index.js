/**
 * Post Kinds for IndieWeb - Editor Entry Point
 *
 * Initializes the editor-side functionality including the Kind Selector
 * sidebar panel and post kinds data store.
 *
 * @package
 * @since   1.0.0
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { register, dispatch, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { store as postKindsStore, STORE_NAME } from './stores/post-kinds';
import KindSelectorPanel from './kind-selector';

// Register the data store.
register( postKindsStore );

// Register the plugin sidebar panel.
registerPlugin( 'post-kinds-indieweb-kind-selector', {
	render: KindSelectorPanel,
	icon: null, // Icon is rendered in the panel itself.
} );

/**
 * External Integration API
 *
 * Listen for external plugins wanting to set the post kind.
 * This allows integration with plugins like Post Formats for Block Themes.
 *
 * Usage by other plugins:
 *   window.dispatchEvent( new CustomEvent( 'post-kinds-indieweb-set-kind', {
 *       detail: { kind: 'listen' }
 *   } ) );
 *
 * Valid kind slugs: note, article, reply, like, repost, bookmark, rsvp,
 *                   checkin, listen, watch, read, event, photo, video, review
 * @param event
 */
const handleExternalKindChange = ( event ) => {
	const { kind } = event.detail || {};

	if ( kind && select( STORE_NAME ) ) {
		dispatch( STORE_NAME ).updatePostKind( kind );
	}
};

// Listen for the current event name.
window.addEventListener(
	'post-kinds-indieweb-set-kind',
	handleExternalKindChange
);

// Backward compatibility: also listen for the legacy event name.
// This maintains integration with Post Formats for Block Themes and other plugins
// that may still use the old event name from before the plugin rename.
window.addEventListener(
	'reactions-indieweb-set-kind',
	handleExternalKindChange
);
