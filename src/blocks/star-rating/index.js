/**
 * Star Rating Block
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { starIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';
import deprecated from './deprecated';

/**
 * Register the Star Rating block.
 */
registerBlockType( metadata.name, {
	...metadata,
	icon: starIcon,
	edit: Edit,
	save: Save,
	deprecated,
} );
