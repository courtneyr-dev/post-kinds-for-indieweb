/**
 * Repost Card Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { repostIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: repostIcon,
	edit: Edit,
	save: Save,
} );
