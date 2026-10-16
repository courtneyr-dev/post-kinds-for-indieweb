/**
 * Craft Card Block
 *
 * Dynamic block: server-side rendering lives in `render.php`, so the saved
 * markup is only the block comment.
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import { SVG, Path } from '@wordpress/primitives';
import Edit from './edit';
import metadata from './block.json';

const craftIcon = (
	<SVG
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		fill="currentColor"
	>
		<Path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z" />
	</SVG>
);

registerBlockType( metadata.name, {
	...metadata,
	icon: craftIcon,
	edit: Edit,
	save: () => null,
} );
