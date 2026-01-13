/**
 * RSVP Card Block - Edit Component
 *
 * @package Reactions_For_IndieWeb
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
    ButtonGroup,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { rsvpIcon } from '../shared/icons';
import { BlockPlaceholder } from '../shared/components';

/**
 * Edit component for the RSVP Card block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block edit component.
 */
export default function Edit({ attributes, setAttributes }) {
    const {
        eventName,
        eventUrl,
        eventStart,
        eventEnd,
        eventLocation,
        eventDescription,
        rsvpStatus,
        rsvpNote,
        rsvpAt,
        eventImage,
        eventImageAlt,
        layout,
    } = attributes;

    const [showStartPicker, setShowStartPicker] = useState(false);
    const [showEndPicker, setShowEndPicker] = useState(false);
    const [showRsvpPicker, setShowRsvpPicker] = useState(false);

    const blockProps = useBlockProps({
        className: `rsvp-card layout-${layout} rsvp-${rsvpStatus}`,
    });

    // RSVP status options
    const rsvpStatuses = [
        { label: __('Yes', 'reactions-for-indieweb'), value: 'yes' },
        { label: __('No', 'reactions-for-indieweb'), value: 'no' },
        { label: __('Maybe', 'reactions-for-indieweb'), value: 'maybe' },
        { label: __('Interested', 'reactions-for-indieweb'), value: 'interested' },
        { label: __('Remote', 'reactions-for-indieweb'), value: 'remote' },
    ];

    /**
     * Handle event image selection
     */
    const handleImageSelect = (media) => {
        setAttributes({
            eventImage: media.url,
            eventImageAlt: media.alt || eventName,
        });
    };

    /**
     * Get RSVP status icon
     */
    const getStatusIcon = () => {
        const icons = {
            yes: '✅',
            no: '❌',
            maybe: '🤔',
            interested: '👀',
            remote: '💻',
        };
        return icons[rsvpStatus] || icons.yes;
    };

    /**
     * Get RSVP status label
     */
    const getStatusLabel = () => {
        const labels = {
            yes: __('Going', 'reactions-for-indieweb'),
            no: __('Not Going', 'reactions-for-indieweb'),
            maybe: __('Maybe', 'reactions-for-indieweb'),
            interested: __('Interested', 'reactions-for-indieweb'),
            remote: __('Attending Remotely', 'reactions-for-indieweb'),
        };
        return labels[rsvpStatus] || labels.yes;
    };

    /**
     * Format date range for display
     */
    const formatDateRange = () => {
        if (!eventStart) return null;

        const startDate = new Date(eventStart);
        const endDate = eventEnd ? new Date(eventEnd) : null;

        const startStr = startDate.toLocaleDateString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });

        if (!endDate) return startStr;

        // Same day
        if (startDate.toDateString() === endDate.toDateString()) {
            const endTime = endDate.toLocaleTimeString(undefined, {
                hour: 'numeric',
                minute: '2-digit',
            });
            return `${startStr} - ${endTime}`;
        }

        // Different days
        const endStr = endDate.toLocaleDateString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
        return `${startStr} - ${endStr}`;
    };

    // Show placeholder if no event info
    if (!eventName && !eventUrl) {
        return (
            <div {...blockProps}>
                <BlockPlaceholder
                    icon={rsvpIcon}
                    label={__('RSVP Card', 'reactions-for-indieweb')}
                    instructions={__('Respond to an event with your RSVP status.', 'reactions-for-indieweb')}
                >
                    <div className="placeholder-actions">
                        <TextControl
                            label={__('Event URL', 'reactions-for-indieweb')}
                            value={eventUrl || ''}
                            onChange={(value) => setAttributes({ eventUrl: value })}
                            type="url"
                            placeholder="https://..."
                        />
                        <Button
                            variant="primary"
                            onClick={() => setAttributes({ eventName: '' })}
                        >
                            {__('Add RSVP', 'reactions-for-indieweb')}
                        </Button>
                    </div>
                </BlockPlaceholder>
            </div>
        );
    }

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Event Details', 'reactions-for-indieweb')}>
                    <TextControl
                        label={__('Event Name', 'reactions-for-indieweb')}
                        value={eventName || ''}
                        onChange={(value) => setAttributes({ eventName: value })}
                    />
                    <TextControl
                        label={__('Event URL', 'reactions-for-indieweb')}
                        value={eventUrl || ''}
                        onChange={(value) => setAttributes({ eventUrl: value })}
                        type="url"
                    />
                    <TextControl
                        label={__('Location', 'reactions-for-indieweb')}
                        value={eventLocation || ''}
                        onChange={(value) => setAttributes({ eventLocation: value })}
                    />
                    <TextareaControl
                        label={__('Description', 'reactions-for-indieweb')}
                        value={eventDescription || ''}
                        onChange={(value) => setAttributes({ eventDescription: value })}
                        rows={3}
                    />
                </PanelBody>

                <PanelBody title={__('Event Date & Time', 'reactions-for-indieweb')}>
                    <div className="components-base-control">
                        <label className="components-base-control__label">
                            {__('Start', 'reactions-for-indieweb')}
                        </label>
                        <Button
                            variant="secondary"
                            onClick={() => setShowStartPicker(true)}
                        >
                            {eventStart
                                ? new Date(eventStart).toLocaleString()
                                : __('Set start time', 'reactions-for-indieweb')
                            }
                        </Button>
                        {showStartPicker && (
                            <Popover onClose={() => setShowStartPicker(false)}>
                                <DateTimePicker
                                    currentDate={eventStart}
                                    onChange={(value) => {
                                        setAttributes({ eventStart: value });
                                        setShowStartPicker(false);
                                    }}
                                />
                            </Popover>
                        )}
                    </div>

                    <div className="components-base-control">
                        <label className="components-base-control__label">
                            {__('End', 'reactions-for-indieweb')}
                        </label>
                        <Button
                            variant="secondary"
                            onClick={() => setShowEndPicker(true)}
                        >
                            {eventEnd
                                ? new Date(eventEnd).toLocaleString()
                                : __('Set end time', 'reactions-for-indieweb')
                            }
                        </Button>
                        {showEndPicker && (
                            <Popover onClose={() => setShowEndPicker(false)}>
                                <DateTimePicker
                                    currentDate={eventEnd}
                                    onChange={(value) => {
                                        setAttributes({ eventEnd: value });
                                        setShowEndPicker(false);
                                    }}
                                />
                            </Popover>
                        )}
                    </div>
                </PanelBody>

                <PanelBody title={__('Your RSVP', 'reactions-for-indieweb')}>
                    <SelectControl
                        label={__('Response', 'reactions-for-indieweb')}
                        value={rsvpStatus}
                        options={rsvpStatuses}
                        onChange={(value) => setAttributes({ rsvpStatus: value })}
                    />

                    <div className="components-base-control">
                        <label className="components-base-control__label">
                            {__('RSVP Time', 'reactions-for-indieweb')}
                        </label>
                        <Button
                            variant="secondary"
                            onClick={() => setShowRsvpPicker(true)}
                        >
                            {rsvpAt
                                ? new Date(rsvpAt).toLocaleString()
                                : __('Set time', 'reactions-for-indieweb')
                            }
                        </Button>
                        {showRsvpPicker && (
                            <Popover onClose={() => setShowRsvpPicker(false)}>
                                <DateTimePicker
                                    currentDate={rsvpAt}
                                    onChange={(value) => {
                                        setAttributes({ rsvpAt: value });
                                        setShowRsvpPicker(false);
                                    }}
                                />
                            </Popover>
                        )}
                    </div>
                </PanelBody>

                <PanelBody title={__('Layout', 'reactions-for-indieweb')}>
                    <SelectControl
                        label={__('Layout Style', 'reactions-for-indieweb')}
                        value={layout}
                        options={[
                            { label: __('Horizontal', 'reactions-for-indieweb'), value: 'horizontal' },
                            { label: __('Vertical', 'reactions-for-indieweb'), value: 'vertical' },
                            { label: __('Compact', 'reactions-for-indieweb'), value: 'compact' },
                        ]}
                        onChange={(value) => setAttributes({ layout: value })}
                    />
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <div className="reactions-card h-entry">
                    {/* Event image */}
                    <div className="event-image">
                        <MediaUploadCheck>
                            <MediaUpload
                                onSelect={handleImageSelect}
                                allowedTypes={['image']}
                                render={({ open }) => (
                                    <div onClick={open} role="button" tabIndex={0}>
                                        {eventImage ? (
                                            <img
                                                src={eventImage}
                                                alt={eventImageAlt}
                                                className="u-photo"
                                            />
                                        ) : (
                                            <div className="image-placeholder">
                                                <span className="rsvp-icon">{getStatusIcon()}</span>
                                                <span>{__('Add event image', 'reactions-for-indieweb')}</span>
                                            </div>
                                        )}
                                    </div>
                                )}
                            />
                        </MediaUploadCheck>
                    </div>

                    <div className="rsvp-info">
                        {/* RSVP status badge */}
                        <span className={`rsvp-status-badge status-${rsvpStatus}`}>
                            <span className="status-icon" aria-hidden="true">{getStatusIcon()}</span>
                            {getStatusLabel()}
                        </span>

                        {/* Quick RSVP buttons */}
                        <div className="rsvp-quick-buttons">
                            <ButtonGroup>
                                {rsvpStatuses.map(status => (
                                    <Button
                                        key={status.value}
                                        variant={rsvpStatus === status.value ? 'primary' : 'secondary'}
                                        onClick={() => setAttributes({ rsvpStatus: status.value })}
                                        isSmall
                                    >
                                        {status.label}
                                    </Button>
                                ))}
                            </ButtonGroup>
                        </div>

                        {/* Event name */}
                        <RichText
                            tagName="h3"
                            className="event-name p-name"
                            value={eventName}
                            onChange={(value) => setAttributes({ eventName: value })}
                            placeholder={__('Event name', 'reactions-for-indieweb')}
                        />

                        {/* Event date/time */}
                        {eventStart && (
                            <div className="event-datetime">
                                <span className="datetime-icon" aria-hidden="true">📅</span>
                                <time dateTime={new Date(eventStart).toISOString()}>
                                    {formatDateRange()}
                                </time>
                            </div>
                        )}

                        {/* Event location */}
                        {eventLocation && (
                            <div className="event-location p-location">
                                <span className="location-icon" aria-hidden="true">📍</span>
                                {eventLocation}
                            </div>
                        )}

                        {/* RSVP note */}
                        <RichText
                            tagName="p"
                            className="rsvp-note p-content"
                            value={rsvpNote}
                            onChange={(value) => setAttributes({ rsvpNote: value })}
                            placeholder={__('Add a note about your RSVP...', 'reactions-for-indieweb')}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}
