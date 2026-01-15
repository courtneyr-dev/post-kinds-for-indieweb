/**
 * Like Card Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { likeIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: likeIcon,
	edit: Edit,
	save: Save,
} );
