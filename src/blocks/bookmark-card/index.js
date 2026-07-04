/**
 * Bookmark Card Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { bookmarkIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: bookmarkIcon,
	edit: Edit,
	save: Save,
} );
