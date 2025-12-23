/**
 * Watch Card Block
 *
 * @package Reactions_For_IndieWeb
 */

import { registerBlockType } from '@wordpress/blocks';
import { watchIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

/**
 * Register the Watch Card block.
 */
registerBlockType(metadata.name, {
    ...metadata,
    icon: watchIcon,
    edit: Edit,
    save: Save,
});
