import { registerBlockType } from '@wordpress/blocks';
import { tag as tagIcon } from '@wordpress/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: tagIcon,
	edit: Edit,
	save: Save,
} );
