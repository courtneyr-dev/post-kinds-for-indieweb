import {
	InspectorControls,
	InnerBlocks,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
export default function Edit( { attributes, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Wrapped strip',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<TextControl
						label={ __(
							'Kind slug',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ attributes.kind || '' }
						onChange={ ( kind ) => setAttributes( { kind } ) }
					/>
					<TextControl
						label={ __(
							'Year',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="number"
						value={ attributes.year || '' }
						onChange={ ( value ) =>
							setAttributes( {
								year: Number.parseInt( value, 10 ) || undefined,
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<InnerBlocks />
		</div>
	);
}
