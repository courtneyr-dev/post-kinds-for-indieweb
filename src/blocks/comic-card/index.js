/**
 * Comic Card Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { comicIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

/**
 * Register the Comic Card block.
 */
registerBlockType( metadata.name, {
	...metadata,
	icon: comicIcon,
	edit: Edit,
	save: Save,
} );
