/**
 * Craft Steps Block - Save Component
 *
 * The numbered list the craft project pattern ships: an ol holding its steps.
 *
 * @package
 */

import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';

/**
 * @return {JSX.Element} The ordered list of steps.
 */
export default function Save() {
	const blockProps = useBlockProps.save( {
		className: 'pkiw-craft-steps',
	} );

	return (
		<ol { ...blockProps }>
			<InnerBlocks.Content />
		</ol>
	);
}
