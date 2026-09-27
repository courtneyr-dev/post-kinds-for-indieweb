/**
 * Comic Card Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { comicsIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: comicsIcon,
	edit: Edit,
	save: Save,
} );
