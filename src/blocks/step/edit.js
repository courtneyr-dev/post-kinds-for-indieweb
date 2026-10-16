/**
 * Craft Step Block - Edit Component
 *
 * One step: a photo and a note, in that order, with the pair locked in place.
 * Block movers order the steps themselves. While the photo has no alt text
 * the step shows a warning. The warning only reads the image: it never writes
 * alt text, so the author decides what the picture says.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	useInnerBlocksProps,
	Warning,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';

const TEMPLATE = [
	[ 'core/image' ],
	[
		'core/paragraph',
		{
			placeholder: __(
				'Describe this step',
				'post-kinds-for-indieweb-in-block-themes'
			),
		},
	],
];

/**
 * Whether an image block has a picture and no alt text.
 *
 * @param {Object} block Block from the block editor.
 * @return {boolean} True for a core/image with a url and a blank alt.
 */
function isMissingAlt( block ) {
	if ( 'core/image' !== block?.name ) {
		return false;
	}
	const { url, alt } = block.attributes || {};
	return !! url && ( 'string' !== typeof alt || '' === alt.trim() );
}

export default function Edit( { clientId } ) {
	const blockProps = useBlockProps( { className: 'pkiw-craft-step' } );
	const { children, ...innerBlocksProps } = useInnerBlocksProps( blockProps, {
		template: TEMPLATE,
		templateLock: 'all',
	} );
	const missingAlt = useSelect(
		( select ) =>
			select( blockEditorStore )
				.getBlocks( clientId )
				.some( isMissingAlt ),
		[ clientId ]
	);

	return (
		<li { ...innerBlocksProps }>
			{ missingAlt && (
				<Warning>
					{ __(
						'This step’s image has no alt text.',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				</Warning>
			) }
			{ children }
		</li>
	);
}
