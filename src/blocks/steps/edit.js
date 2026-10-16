/**
 * Craft Steps Block - Edit Component
 *
 * An ordered list that holds only steps. A new list starts with one step.
 *
 * @package
 */

import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

const ALLOWED_BLOCKS = [ 'post-kinds-indieweb/step' ];
const TEMPLATE = [ [ 'post-kinds-indieweb/step' ] ];

export default function Edit() {
	const blockProps = useBlockProps( { className: 'pkiw-craft-steps' } );
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		allowedBlocks: ALLOWED_BLOCKS,
		template: TEMPLATE,
	} );

	return <ol { ...innerBlocksProps } />;
}
