/**
 * Craft Step Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { listItem } from '@wordpress/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: listItem,
	edit: Edit,
	save: Save,
} );
