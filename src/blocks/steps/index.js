/**
 * Craft Steps Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { formatListNumbered } from '@wordpress/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: formatListNumbered,
	edit: Edit,
	save: Save,
} );
