/**
 * Reply Card Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { replyIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: replyIcon,
	edit: Edit,
	save: Save,
} );
