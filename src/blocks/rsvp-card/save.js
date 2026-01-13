/**
 * RSVP Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps, RichText } from '@wordpress/block-editor';

/**
 * Save component for the RSVP Card block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block save component.
 */
export default function Save({ attributes }) {
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

    const blockProps = useBlockProps.save({
        className: `rsvp-card layout-${layout} rsvp-${rsvpStatus}`,
    });

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
            yes: 'Going',
            no: 'Not Going',
            maybe: 'Maybe',
            interested: 'Interested',
            remote: 'Attending Remotely',
        };
        return labels[rsvpStatus] || labels.yes;
    };

    /**
     * Format date range
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

        if (startDate.toDateString() === endDate.toDateString()) {
            const endTime = endDate.toLocaleTimeString(undefined, {
                hour: 'numeric',
                minute: '2-digit',
            });
            return `${startStr} - ${endTime}`;
        }

        const endStr = endDate.toLocaleDateString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
        return `${startStr} - ${endStr}`;
    };

    return (
        <div {...blockProps}>
            <div className="reactions-card h-entry">
                {/* Event image */}
                {eventImage && (
                    <div className="reactions-card__media">
                        <img
                            src={eventImage}
                            alt={eventImageAlt || `${eventName} event`}
                            className="reactions-card__image u-photo"
                            loading="lazy"
                        />
                    </div>
                )}

                <div className="reactions-card__content">
                    {/* RSVP status badge with p-rsvp microformat */}
                    <span className={`reactions-card__badge reactions-card__badge--${rsvpStatus}`}>
                        <span className="reactions-card__badge-icon" aria-hidden="true">{getStatusIcon()}</span>
                        <data className="p-rsvp" value={rsvpStatus}>
                            {getStatusLabel()}
                        </data>
                    </span>

                    {/* Event details with h-event microformat */}
                    <div className="reactions-card__event p-in-reply-to h-event">
                        {/* Event name */}
                        {eventName && (
                            <h3 className="reactions-card__title">
                                {eventUrl ? (
                                    <a href={eventUrl} className="p-name u-url" target="_blank" rel="noopener noreferrer">
                                        {eventName}
                                    </a>
                                ) : (
                                    <span className="p-name">{eventName}</span>
                                )}
                            </h3>
                        )}

                        {/* Event date/time */}
                        {eventStart && (
                            <div className="reactions-card__meta-row">
                                <span className="reactions-card__meta-icon" aria-hidden="true">📅</span>
                                <time className="dt-start" dateTime={new Date(eventStart).toISOString()}>
                                    {formatDateRange()}
                                </time>
                                {eventEnd && (
                                    <data className="dt-end" value={new Date(eventEnd).toISOString()} hidden />
                                )}
                            </div>
                        )}

                        {/* Event location */}
                        {eventLocation && (
                            <div className="reactions-card__meta-row">
                                <span className="reactions-card__meta-icon" aria-hidden="true">📍</span>
                                <span className="p-location">{eventLocation}</span>
                            </div>
                        )}

                        {/* Event description */}
                        {eventDescription && (
                            <p className="reactions-card__meta p-summary">{eventDescription}</p>
                        )}
                    </div>

                    {/* RSVP note */}
                    {rsvpNote && (
                        <div className="reactions-card__notes p-content">
                            <RichText.Content tagName="p" value={rsvpNote} />
                        </div>
                    )}

                    {/* RSVP timestamp */}
                    {rsvpAt && (
                        <time
                            className="reactions-card__timestamp dt-published"
                            dateTime={new Date(rsvpAt).toISOString()}
                        >
                            RSVPed {new Date(rsvpAt).toLocaleDateString()}
                        </time>
                    )}
                </div>

                {/* Hidden microformat data */}
                {eventUrl && <data className="u-in-reply-to" value={eventUrl} hidden />}
            </div>
        </div>
    );
}
