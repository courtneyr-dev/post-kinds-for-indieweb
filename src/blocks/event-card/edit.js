/**
 * Event Card Block - Edit Component
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	RichText,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	TextareaControl,
	SelectControl,
	Button,
	DateTimePicker,
	Popover,
	Notice,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { eventIcon } from '../shared/icons';
import { BlockPlaceholder, parseDate } from '../shared/components';

/**
 * Edit component for the Event Card block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		eventName,
		eventStart,
		eventEnd,
		eventLocation,
		eventUrl,
		eventDescription,
		eventImage,
		eventImageAlt,
		calendarSource,
		calendarEventId,
		layout,
	} = attributes;

	const [ showStartPicker, setShowStartPicker ] = useState( false );
	const [ showEndPicker, setShowEndPicker ] = useState( false );

	const blockProps = useBlockProps( {
		className: `event-card layout-${ layout } pk-card k-event`,
	} );

	const calendarSources = [
		{
			label: __( 'None', 'post-kinds-for-indieweb-in-block-themes' ),
			value: '',
		},
		{
			label: __(
				'The Events Calendar',
				'post-kinds-for-indieweb-in-block-themes'
			),
			value: 'the-events-calendar',
		},
		{
			label: __(
				'My Calendar',
				'post-kinds-for-indieweb-in-block-themes'
			),
			value: 'my-calendar',
		},
	];

	/**
	 * Handle event image selection
	 *
	 * @param {Object} media Selected media object.
	 */
	const handleImageSelect = ( media ) => {
		setAttributes( {
			eventImage: media.url,
			eventImageAlt: media.alt || eventName,
		} );
	};

	/**
	 * Format date range for display
	 */
	const formatDateRange = () => {
		const startDate = parseDate( eventStart );

		if ( ! startDate ) {
			return null;
		}

		const endDate = parseDate( eventEnd );

		const startStr = startDate.toLocaleDateString( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
		} );

		if ( ! endDate ) {
			return startStr;
		}

		// Same day
		if ( startDate.toDateString() === endDate.toDateString() ) {
			const endTime = endDate.toLocaleTimeString( undefined, {
				hour: 'numeric',
				minute: '2-digit',
			} );
			return `${ startStr } - ${ endTime }`;
		}

		// Different days
		const endStr = endDate.toLocaleDateString( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
		} );
		return `${ startStr } - ${ endStr }`;
	};

	const hasCalendarRef = calendarSource && calendarEventId > 0;
	const eventStartDate = parseDate( eventStart );

	// Show placeholder if no event info at all
	if ( ! eventName && ! eventUrl && ! hasCalendarRef ) {
		return (
			<div { ...blockProps }>
				<BlockPlaceholder
					icon={ eventIcon }
					label={ __(
						'Event Card',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					instructions={ __(
						'Announce an event with date, time, and location.',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<div className="placeholder-actions">
						<TextControl
							label={ __(
								'Event Name',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ eventName || '' }
							onChange={ ( value ) =>
								setAttributes( { eventName: value } )
							}
						/>
						<TextControl
							label={ __(
								'Event URL',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ eventUrl || '' }
							onChange={ ( value ) =>
								setAttributes( { eventUrl: value } )
							}
							type="url"
							placeholder="https://..."
						/>
					</div>
				</BlockPlaceholder>
			</div>
		);
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Event Details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<TextControl
						label={ __(
							'Event Name',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ eventName || '' }
						onChange={ ( value ) =>
							setAttributes( { eventName: value } )
						}
					/>
					<TextControl
						label={ __(
							'Event URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ eventUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { eventUrl: value } )
						}
						type="url"
					/>
					<TextControl
						label={ __(
							'Location',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ eventLocation || '' }
						onChange={ ( value ) =>
							setAttributes( { eventLocation: value } )
						}
					/>
					<TextareaControl
						label={ __(
							'Description',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ eventDescription || '' }
						onChange={ ( value ) =>
							setAttributes( { eventDescription: value } )
						}
						rows={ 3 }
					/>
				</PanelBody>

				<PanelBody
					title={ __(
						'Event Date & Time',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<div className="components-base-control">
						<span className="components-base-control__label">
							{ __(
								'Start',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</span>
						<Button
							variant="secondary"
							onClick={ () => setShowStartPicker( true ) }
							aria-label={ __(
								'Set event start time',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						>
							{ eventStart
								? new Date( eventStart ).toLocaleString()
								: __(
										'Set start time',
										'post-kinds-for-indieweb-in-block-themes'
								  ) }
						</Button>
						{ showStartPicker && (
							<Popover
								onClose={ () => setShowStartPicker( false ) }
							>
								<DateTimePicker
									currentDate={ eventStart }
									onChange={ ( value ) => {
										setAttributes( { eventStart: value } );
										setShowStartPicker( false );
									} }
								/>
							</Popover>
						) }
					</div>

					<div className="components-base-control">
						<span className="components-base-control__label">
							{ __(
								'End',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</span>
						<Button
							variant="secondary"
							onClick={ () => setShowEndPicker( true ) }
							aria-label={ __(
								'Set event end time',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						>
							{ eventEnd
								? new Date( eventEnd ).toLocaleString()
								: __(
										'Set end time',
										'post-kinds-for-indieweb-in-block-themes'
								  ) }
						</Button>
						{ showEndPicker && (
							<Popover
								onClose={ () => setShowEndPicker( false ) }
							>
								<DateTimePicker
									currentDate={ eventEnd }
									onChange={ ( value ) => {
										setAttributes( { eventEnd: value } );
										setShowEndPicker( false );
									} }
								/>
							</Popover>
						) }
					</div>
				</PanelBody>

				<PanelBody
					title={ __(
						'Calendar Source',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ !! calendarSource }
				>
					<SelectControl
						label={ __(
							'Source Plugin',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ calendarSource }
						options={ calendarSources }
						onChange={ ( value ) =>
							setAttributes( { calendarSource: value } )
						}
						help={ __(
							'Pull name, dates, and venue from a calendar plugin event at render time.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					{ calendarSource && (
						<>
							<TextControl
								label={ __(
									'Event ID',
									'post-kinds-for-indieweb-in-block-themes'
								) }
								type="number"
								min={ 0 }
								value={ calendarEventId || 0 }
								onChange={ ( value ) =>
									setAttributes( {
										calendarEventId:
											parseInt( value, 10 ) || 0,
									} )
								}
							/>
							<Notice status="info" isDismissible={ false }>
								{ __(
									'Calendar data overrides the fields above when the plugin is active. If it is deactivated, the fields above render instead.',
									'post-kinds-for-indieweb-in-block-themes'
								) }
							</Notice>
						</>
					) }
				</PanelBody>

				<PanelBody
					title={ __(
						'Layout',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Layout Style',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ layout }
						options={ [
							{
								label: __(
									'Horizontal',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'horizontal',
							},
							{
								label: __(
									'Vertical',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'vertical',
							},
							{
								label: __(
									'Compact',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'compact',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card h-event">
					{ /* Event image */ }
					<div className="event-image">
						<MediaUploadCheck>
							<MediaUpload
								onSelect={ handleImageSelect }
								allowedTypes={ [ 'image' ] }
								render={ ( { open } ) => (
									<div
										onClick={ open }
										onKeyDown={ ( e ) => {
											if (
												e.key === 'Enter' ||
												e.key === ' '
											) {
												e.preventDefault();
												open();
											}
										} }
										role="button"
										tabIndex={ 0 }
									>
										{ eventImage ? (
											<img
												src={ eventImage }
												alt={ eventImageAlt }
												className="u-photo"
											/>
										) : (
											<div className="image-placeholder">
												<span
													className="event-icon"
													aria-hidden="true"
												>
													📅
												</span>
												<span>
													{ __(
														'Add event image',
														'post-kinds-for-indieweb-in-block-themes'
													) }
												</span>
											</div>
										) }
									</div>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="event-info">
						{ /* Calendar source badge */ }
						{ hasCalendarRef && (
							<span className="event-calendar-badge">
								{ __(
									'Linked calendar event',
									'post-kinds-for-indieweb-in-block-themes'
								) }
								{ ' #' + calendarEventId }
							</span>
						) }

						{ /* Event name */ }
						<RichText
							tagName="h3"
							className="event-name p-name"
							value={ eventName }
							onChange={ ( value ) =>
								setAttributes( { eventName: value } )
							}
							placeholder={ __(
								'Event name',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						{ /* Event date/time */ }
						{ eventStartDate && (
							<div className="event-datetime">
								<span
									className="datetime-icon"
									aria-hidden="true"
								>
									📅
								</span>
								<time dateTime={ eventStartDate.toISOString() }>
									{ formatDateRange() }
								</time>
							</div>
						) }

						{ /* Event location */ }
						{ eventLocation && (
							<div className="event-location p-location">
								<span
									className="location-icon"
									aria-hidden="true"
								>
									📍
								</span>
								{ eventLocation }
							</div>
						) }

						{ /* Event description */ }
						<RichText
							tagName="p"
							className="event-description p-summary"
							value={ eventDescription }
							onChange={ ( value ) =>
								setAttributes( { eventDescription: value } )
							}
							placeholder={ __(
								'Describe the event…',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
