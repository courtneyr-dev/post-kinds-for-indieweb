/**
 * Sleep Card Block - Edit Component
 *
 * The sidebar holds the night's duration (hours and minutes, stored as whole
 * minutes), the bedtime and wake time, and the switch that lets the two
 * times print. The canvas shows the server's public view of the card, which
 * is the same markup a visitor gets: the health rule has no editor branch.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
	Disabled,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Longest night the card stores: a day.
 *
 * @type {number}
 */
const MAX_MINUTES = 1440;

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
	const { durationMinutes, bedtime, wakeTime, showTimesPublicly } =
		attributes;
	const total = wholeNumber( durationMinutes );
	const hours = Math.floor( total / 60 );
	const minutes = total % 60;
	const blockProps = useBlockProps( {
		className: 'sleep-card-block pk-card k-sleep',
	} );

	const setDuration = ( nextHours, nextMinutes ) => {
		const next = Math.min(
			MAX_MINUTES,
			wholeNumber( nextHours ) * 60 + wholeNumber( nextMinutes )
		);
		setAttributes( { durationMinutes: next > 0 ? next : undefined } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Night',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ true }
				>
					<TextControl
						label={ __(
							'Hours',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="number"
						min={ 0 }
						max={ 24 }
						value={ hours > 0 ? hours : '' }
						onChange={ ( value ) => setDuration( value, minutes ) }
					/>
					<TextControl
						label={ __(
							'Minutes',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="number"
						min={ 0 }
						max={ 59 }
						value={ minutes > 0 ? minutes : '' }
						onChange={ ( value ) => setDuration( hours, value ) }
					/>
					<TextControl
						label={ __(
							'Bedtime',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="datetime-local"
						value={ bedtime || '' }
						onChange={ ( value ) =>
							setAttributes( { bedtime: value || undefined } )
						}
					/>
					<TextControl
						label={ __(
							'Wake time',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="datetime-local"
						value={ wakeTime || '' }
						onChange={ ( value ) =>
							setAttributes( { wakeTime: value || undefined } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Privacy',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ true }
				>
					<ToggleControl
						label={ __(
							'Show bedtime and wake time publicly',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ !! showTimesPublicly }
						onChange={ ( value ) =>
							setAttributes( { showTimesPublicly: value } )
						}
						help={ __(
							'The duration always prints. Bedtime and wake time print only when this is on.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<p className="pkiw-card-preview-label">
					{ __(
						'Public view, as last saved',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				</p>
				<Disabled>
					<ServerSideRender
						block="post-kinds-indieweb/sleep-card"
						attributes={ attributes }
					/>
				</Disabled>
			</div>
		</>
	);
}
