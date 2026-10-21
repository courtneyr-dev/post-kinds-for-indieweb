/**
 * Collection item block registration.
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: 'archive',
	edit: Edit,
	save: () => null,
} );
