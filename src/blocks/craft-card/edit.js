/**
 * Craft Card Block - Edit Component
 *
 * Two facts: the craft type, free text kept exactly as typed, and the time
 * spent, typed as hours and minutes and stored as whole minutes. The canvas
 * shows the server's markup, the same description list a visitor gets, and
 * an editor-only placeholder while both facts are empty.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	Disabled,
	Placeholder,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * A whole number of at least 0 from a field's text.
 *
 * @param {string|number} value Field text.
 * @return {number} The number, 0 for empty or invalid text.
 */
function wholeNumber( value ) {
	const number = Math.floor( Number( value ) );
	return Number.isFinite( number ) && number > 0 ? number : 0;
}

export default function Edit( { attributes, setAttributes } ) {
	const { craftType, timeMinutes } = attributes;
	const total = wholeNumber( timeMinutes );
	const hours = Math.floor( total / 60 );
	const minutes = total % 60;
	const blockProps = useBlockProps( {
		className: 'craft-card-block pk-card k-craft',
	} );

	const setTime = ( nextHours, nextMinutes ) => {
		const next = wholeNumber( nextHours ) * 60 + wholeNumber( nextMinutes );
		setAttributes( { timeMinutes: next > 0 ? next : undefined } );
	};

	const empty = ! ( craftType && craftType.trim() ) && total <= 0;

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Craft details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ true }
				>
					<TextControl
						label={ __(
							'Craft type',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ craftType || '' }
						onChange={ ( value ) =>
							setAttributes( { craftType: value || undefined } )
						}
						help={ __(
							'Any text. It prints exactly as you type it.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<div className="pkiw-craft-time-fields">
						<TextControl
							label={ __(
								'Hours',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							type="number"
							min={ 0 }
							value={ hours }
							onChange={ ( value ) => setTime( value, minutes ) }
						/>
						<TextControl
							label={ __(
								'Minutes',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							type="number"
							min={ 0 }
							value={ minutes }
							onChange={ ( value ) => setTime( hours, value ) }
						/>
					</div>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ empty ? (
					<Placeholder
						label={ __(
							'Craft card',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						instructions={ __(
							'Add the craft type and the time it took in the block settings. A card with neither prints nothing.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				) : (
					<Disabled>
						<ServerSideRender
							block="post-kinds-indieweb/craft-card"
							attributes={ attributes }
						/>
					</Disabled>
				) }
			</div>
		</>
	);
}
