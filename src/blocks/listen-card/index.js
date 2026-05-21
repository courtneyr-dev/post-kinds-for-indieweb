/**
 * Listen Card Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { listenIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import DeprecatedSave from './save-deprecated';
import metadata from './block.json';

/**
 * Register the Listen Card block.
 */
registerBlockType( metadata.name, {
	...metadata,
	icon: listenIcon,
	edit: Edit,
	save: Save,
	deprecated: [
		{
			attributes: metadata.attributes,
			save: DeprecatedSave,
		},
	],
} );
