/**
 * Media Lookup Block
 *
 * @package Reactions_For_IndieWeb
 */

import { registerBlockType } from '@wordpress/blocks';
import { searchIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

/**
 * Register the Media Lookup block.
 */
registerBlockType(metadata.name, {
    ...metadata,
    icon: searchIcon,
    edit: Edit,
    save: Save,
});
