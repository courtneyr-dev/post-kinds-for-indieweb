/**
 * Acquisition Card Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { acquisitionIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import Deprecated327Save from './save-deprecated-327';
import DeprecatedSave from './save-deprecated';
import metadata from './block.json';

registerBlockType( metadata.name, {
	...metadata,
	icon: acquisitionIcon,
	edit: Edit,
	save: Save,
	// Newest first: the markup of issue 327, then the markup before it.
	deprecated: [
		{
			attributes: metadata.attributes,
			save: Deprecated327Save,
		},
		{
			attributes: metadata.attributes,
			save: DeprecatedSave,
		},
	],
} );
