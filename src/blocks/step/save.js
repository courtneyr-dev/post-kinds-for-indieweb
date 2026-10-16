/**
 * Craft Step Block - Save Component
 *
 * One list item, holding the step's photo and note.
 *
 * @package
 */

import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';

/**
 * @return {JSX.Element} The list item.
 */
export default function Save() {
	const blockProps = useBlockProps.save( {
		className: 'pkiw-craft-step',
	} );

	return (
		<li { ...blockProps }>
			<InnerBlocks.Content />
		</li>
	);
}
