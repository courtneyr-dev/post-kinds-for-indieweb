/**
 * Event Card Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { eventIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

/**
 * Register the Event Card block.
 */
registerBlockType( metadata.name, {
	...metadata,
	icon: eventIcon,
	edit: Edit,
	save: Save,
} );
