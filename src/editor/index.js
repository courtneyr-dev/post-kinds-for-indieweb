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

/**
 * Internal dependencies
 */
import { store as postKindsStore } from './stores/post-kinds';
import KindSelectorPanel from './kind-selector';

// Register the data store.
register( postKindsStore );

// Register the plugin sidebar panel.
registerPlugin( 'reactions-indieweb-kind-selector', {
	render: KindSelectorPanel,
	icon: null, // Icon is rendered in the panel itself.
} );
