/**
 * Listen Card Block
 *
 * @package Reactions_For_IndieWeb
 */

import { registerBlockType } from '@wordpress/blocks';
import { listenIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

/**
 * Register the Listen Card block.
 */
registerBlockType(metadata.name, {
    ...metadata,
    icon: listenIcon,
    edit: Edit,
    save: Save,
});
