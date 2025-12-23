/**
 * Checkin Card Block - Edit Component
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
    SelectControl,
    Button,
    DateTimePicker,
    Popover,
    ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { checkinIcon } from '../shared/icons';
import { BlockPlaceholder, LocationDisplay } from '../shared/components';

/**
 * Edit component for the Checkin Card block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block edit component.
 */
export default function Edit({ attributes, setAttributes }) {
    const {
        venueName,
        venueType,
        address,
        locality,
        region,
        country,
        postalCode,
        latitude,
        longitude,
        venueUrl,
        foursquareId,
        checkinAt,
        note,
        photo,
        photoAlt,
        showMap,
        layout,
    } = attributes;

    const [showDatePicker, setShowDatePicker] = useState(false);

    const blockProps = useBlockProps({
        className: `checkin-card layout-${layout}`,
    });

    // Venue type options
    const venueTypes = [
        { label: __('Place', 'reactions-indieweb'), value: 'place' },
        { label: __('Restaurant', 'reactions-indieweb'), value: 'restaurant' },
        { label: __('Cafe', 'reactions-indieweb'), value: 'cafe' },
        { label: __('Bar', 'reactions-indieweb'), value: 'bar' },
        { label: __('Hotel', 'reactions-indieweb'), value: 'hotel' },
        { label: __('Airport', 'reactions-indieweb'), value: 'airport' },
        { label: __('Park', 'reactions-indieweb'), value: 'park' },
        { label: __('Museum', 'reactions-indieweb'), value: 'museum' },
        { label: __('Theater', 'reactions-indieweb'), value: 'theater' },
        { label: __('Store', 'reactions-indieweb'), value: 'store' },
        { label: __('Office', 'reactions-indieweb'), value: 'office' },
        { label: __('Home', 'reactions-indieweb'), value: 'home' },
        { label: __('Other', 'reactions-indieweb'), value: 'other' },
    ];

    /**
     * Handle photo selection
     */
    const handlePhotoSelect = (media) => {
        setAttributes({
            photo: media.url,
            photoAlt: media.alt || venueName,
        });
    };

    /**
     * Get venue type icon
     */
    const getVenueIcon = () => {
        const icons = {
            place: '📍',
            restaurant: '🍽️',
            cafe: '☕',
            bar: '🍺',
            hotel: '🏨',
            airport: '✈️',
            park: '🌳',
            museum: '🏛️',
            theater: '🎭',
            store: '🛍️',
            office: '🏢',
            home: '🏠',
            other: '📌',
        };
        return icons[venueType] || icons.place;
    };

    /**
     * Format location string
     */
    const formatLocation = () => {
        const parts = [locality, region, country].filter(Boolean);
        return parts.join(', ');
    };

    /**
     * Generate map URL for OpenStreetMap embed
     */
    const getMapUrl = () => {
        if (!latitude || !longitude) {
            return null;
        }
        return `https://www.openstreetmap.org/export/embed.html?bbox=${longitude - 0.01},${latitude - 0.01},${longitude + 0.01},${latitude + 0.01}&layer=mapnik&marker=${latitude},${longitude}`;
    };

    // Show placeholder if no venue info
    if (!venueName && !locality) {
        return (
            <div {...blockProps}>
                <BlockPlaceholder
                    icon={checkinIcon}
                    label={__('Checkin Card', 'reactions-indieweb')}
                    instructions={__('Add a location checkin. Enter the venue details manually.', 'reactions-indieweb')}
                >
                    <div className="placeholder-actions">
                        <Button
                            variant="primary"
                            onClick={() => setAttributes({ venueName: '' })}
                        >
                            {__('Add Checkin', 'reactions-indieweb')}
                        </Button>
                    </div>
                </BlockPlaceholder>
            </div>
        );
    }

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Venue Details', 'reactions-indieweb')}>
                    <TextControl
                        label={__('Venue Name', 'reactions-indieweb')}
                        value={venueName || ''}
                        onChange={(value) => setAttributes({ venueName: value })}
                    />
                    <SelectControl
                        label={__('Venue Type', 'reactions-indieweb')}
                        value={venueType}
                        options={venueTypes}
                        onChange={(value) => setAttributes({ venueType: value })}
                    />
                    <TextControl
                        label={__('Street Address', 'reactions-indieweb')}
                        value={address || ''}
                        onChange={(value) => setAttributes({ address: value })}
                    />
                    <TextControl
                        label={__('City/Locality', 'reactions-indieweb')}
                        value={locality || ''}
                        onChange={(value) => setAttributes({ locality: value })}
                    />
                    <TextControl
                        label={__('State/Region', 'reactions-indieweb')}
                        value={region || ''}
                        onChange={(value) => setAttributes({ region: value })}
                    />
                    <TextControl
                        label={__('Country', 'reactions-indieweb')}
                        value={country || ''}
                        onChange={(value) => setAttributes({ country: value })}
                    />
                    <TextControl
                        label={__('Postal Code', 'reactions-indieweb')}
                        value={postalCode || ''}
                        onChange={(value) => setAttributes({ postalCode: value })}
                    />
                </PanelBody>

                <PanelBody title={__('Coordinates', 'reactions-indieweb')} initialOpen={false}>
                    <TextControl
                        label={__('Latitude', 'reactions-indieweb')}
                        value={latitude || ''}
                        onChange={(value) => setAttributes({ latitude: parseFloat(value) || null })}
                        type="number"
                        step="any"
                    />
                    <TextControl
                        label={__('Longitude', 'reactions-indieweb')}
                        value={longitude || ''}
                        onChange={(value) => setAttributes({ longitude: parseFloat(value) || null })}
                        type="number"
                        step="any"
                    />
                    <ToggleControl
                        label={__('Show Map', 'reactions-indieweb')}
                        checked={showMap}
                        onChange={(value) => setAttributes({ showMap: value })}
                        help={__('Display an embedded OpenStreetMap', 'reactions-indieweb')}
                    />
                </PanelBody>

                <PanelBody title={__('Checkin Details', 'reactions-indieweb')}>
                    <div className="components-base-control">
                        <label className="components-base-control__label">
                            {__('Checkin Time', 'reactions-indieweb')}
                        </label>
                        <Button
                            variant="secondary"
                            onClick={() => setShowDatePicker(true)}
                        >
                            {checkinAt
                                ? new Date(checkinAt).toLocaleString()
                                : __('Set time', 'reactions-indieweb')
                            }
                        </Button>
                        {showDatePicker && (
                            <Popover onClose={() => setShowDatePicker(false)}>
                                <DateTimePicker
                                    currentDate={checkinAt}
                                    onChange={(value) => {
                                        setAttributes({ checkinAt: value });
                                        setShowDatePicker(false);
                                    }}
                                />
                            </Popover>
                        )}
                    </div>
                </PanelBody>

                <PanelBody title={__('Layout', 'reactions-indieweb')}>
                    <SelectControl
                        label={__('Layout Style', 'reactions-indieweb')}
                        value={layout}
                        options={[
                            { label: __('Horizontal', 'reactions-indieweb'), value: 'horizontal' },
                            { label: __('Vertical', 'reactions-indieweb'), value: 'vertical' },
                            { label: __('Map Focus', 'reactions-indieweb'), value: 'map' },
                            { label: __('Compact', 'reactions-indieweb'), value: 'compact' },
                        ]}
                        onChange={(value) => setAttributes({ layout: value })}
                    />
                </PanelBody>

                <PanelBody title={__('Links', 'reactions-indieweb')} initialOpen={false}>
                    <TextControl
                        label={__('Venue URL', 'reactions-indieweb')}
                        value={venueUrl || ''}
                        onChange={(value) => setAttributes({ venueUrl: value })}
                        type="url"
                    />
                    <TextControl
                        label={__('Foursquare ID', 'reactions-indieweb')}
                        value={foursquareId || ''}
                        onChange={(value) => setAttributes({ foursquareId: value })}
                    />
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <div className="checkin-card-inner h-entry">
                    {/* Photo */}
                    <div className="checkin-photo">
                        <MediaUploadCheck>
                            <MediaUpload
                                onSelect={handlePhotoSelect}
                                allowedTypes={['image']}
                                render={({ open }) => (
                                    <div onClick={open} role="button" tabIndex={0}>
                                        {photo ? (
                                            <img
                                                src={photo}
                                                alt={photoAlt}
                                                className="u-photo"
                                            />
                                        ) : (
                                            <div className="photo-placeholder">
                                                <span className="venue-icon">{getVenueIcon()}</span>
                                                <span>{__('Add photo', 'reactions-indieweb')}</span>
                                            </div>
                                        )}
                                    </div>
                                )}
                            />
                        </MediaUploadCheck>
                    </div>

                    <div className="checkin-info">
                        <span className="venue-type-badge">
                            <span className="venue-icon">{getVenueIcon()}</span>
                            {venueTypes.find(t => t.value === venueType)?.label}
                        </span>

                        <RichText
                            tagName="h3"
                            className="venue-name p-name"
                            value={venueName}
                            onChange={(value) => setAttributes({ venueName: value })}
                            placeholder={__('Venue name', 'reactions-indieweb')}
                        />

                        <div className="venue-location p-location h-card">
                            <LocationDisplay
                                address={address}
                                locality={locality}
                                region={region}
                                country={country}
                            />
                        </div>

                        {checkinAt && (
                            <time
                                className="checkin-time dt-published"
                                dateTime={new Date(checkinAt).toISOString()}
                            >
                                {new Date(checkinAt).toLocaleString()}
                            </time>
                        )}

                        <RichText
                            tagName="p"
                            className="checkin-note p-content"
                            value={note}
                            onChange={(value) => setAttributes({ note: value })}
                            placeholder={__('Add a note about this checkin...', 'reactions-indieweb')}
                        />
                    </div>

                    {/* Map preview */}
                    {showMap && latitude && longitude && (
                        <div className="checkin-map">
                            <iframe
                                title={__('Location map', 'reactions-indieweb')}
                                width="100%"
                                height="200"
                                frameBorder="0"
                                scrolling="no"
                                marginHeight="0"
                                marginWidth="0"
                                src={getMapUrl()}
                            />
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
