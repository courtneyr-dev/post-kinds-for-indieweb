/**
 * Reactions for IndieWeb - Editor Entry Point
 *
 * Initializes the editor-side functionality including the Kind Selector
 * sidebar panel and post kinds data store.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { register } from '@wordpress/data';
import { dispatch, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { store as postKindsStore, STORE_NAME } from './stores/post-kinds';
import KindSelectorPanel from './kind-selector';

// Register the data store.
register( postKindsStore );

// Register the plugin sidebar panel.
registerPlugin( 'reactions-indieweb-kind-selector', {
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
 *   window.dispatchEvent( new CustomEvent( 'reactions-indieweb-set-kind', {
 *       detail: { kind: 'listen' }
 *   } ) );
 *
 * Valid kind slugs: note, article, reply, like, repost, bookmark, rsvp,
 *                   checkin, listen, watch, read, event, photo, video, review
 */
window.addEventListener( 'reactions-indieweb-set-kind', ( event ) => {
	const { kind } = event.detail || {};

	if ( kind && select( STORE_NAME ) ) {
		dispatch( STORE_NAME ).updatePostKind( kind );
	}
} );
