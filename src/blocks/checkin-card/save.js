/**
 * Checkin Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps, RichText } from '@wordpress/block-editor';

/**
 * Save component for the Checkin Card block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block save component.
 */
export default function Save({ attributes }) {
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

    const blockProps = useBlockProps.save({
        className: `checkin-card layout-${layout}`,
    });

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
     * Get venue type label
     */
    const getVenueTypeLabel = () => {
        const labels = {
            place: 'Place',
            restaurant: 'Restaurant',
            cafe: 'Cafe',
            bar: 'Bar',
            hotel: 'Hotel',
            airport: 'Airport',
            park: 'Park',
            museum: 'Museum',
            theater: 'Theater',
            store: 'Store',
            office: 'Office',
            home: 'Home',
            other: 'Other',
        };
        return labels[venueType] || labels.place;
    };

    /**
     * Format full address
     */
    const formatAddress = () => {
        const parts = [];
        if (address) parts.push(address);
        if (locality) parts.push(locality);
        if (region) parts.push(region);
        if (postalCode) parts.push(postalCode);
        if (country) parts.push(country);
        return parts.join(', ');
    };

    /**
     * Generate map URL
     */
    const getMapUrl = () => {
        if (!latitude || !longitude) return null;
        return `https://www.openstreetmap.org/export/embed.html?bbox=${longitude - 0.01},${latitude - 0.01},${longitude + 0.01},${latitude + 0.01}&layer=mapnik&marker=${latitude},${longitude}`;
    };

    /**
     * Generate geo URI
     */
    const getGeoUri = () => {
        if (!latitude || !longitude) return null;
        return `geo:${latitude},${longitude}`;
    };

    return (
        <div {...blockProps}>
            <div className="checkin-card-inner h-entry">
                {/* Photo */}
                {photo && (
                    <div className="checkin-photo">
                        <img
                            src={photo}
                            alt={photoAlt || `Photo at ${venueName}`}
                            className="u-photo"
                            loading="lazy"
                        />
                    </div>
                )}

                <div className="checkin-info">
                    {/* Venue type badge */}
                    <span className="venue-type-badge">
                        <span className="venue-icon" aria-hidden="true">{getVenueIcon()}</span>
                        {getVenueTypeLabel()}
                    </span>

                    {/* Venue name */}
                    {venueName && (
                        <h3 className="venue-name">
                            {venueUrl ? (
                                <a href={venueUrl} className="p-name u-url" target="_blank" rel="noopener noreferrer">
                                    {venueName}
                                </a>
                            ) : (
                                <span className="p-name">{venueName}</span>
                            )}
                        </h3>
                    )}

                    {/* Location with microformats */}
                    <div className="venue-location p-location h-card">
                        {address && <span className="p-street-address">{address}</span>}
                        {(locality || region || country) && (
                            <span className="location-parts">
                                {locality && <span className="p-locality">{locality}</span>}
                                {locality && region && ', '}
                                {region && <span className="p-region">{region}</span>}
                                {(locality || region) && country && ', '}
                                {country && <span className="p-country-name">{country}</span>}
                            </span>
                        )}
                        {postalCode && <span className="p-postal-code">{postalCode}</span>}

                        {/* Geo coordinates */}
                        {latitude && longitude && (
                            <data className="p-geo h-geo" value={getGeoUri()}>
                                <data className="p-latitude" value={latitude} hidden />
                                <data className="p-longitude" value={longitude} hidden />
                            </data>
                        )}
                    </div>

                    {/* Checkin time */}
                    {checkinAt && (
                        <time
                            className="checkin-time dt-published"
                            dateTime={new Date(checkinAt).toISOString()}
                        >
                            {new Date(checkinAt).toLocaleString()}
                        </time>
                    )}

                    {/* Note */}
                    {note && (
                        <div className="checkin-note p-content">
                            <RichText.Content tagName="p" value={note} />
                        </div>
                    )}
                </div>

                {/* Map embed */}
                {showMap && latitude && longitude && (
                    <div className="checkin-map">
                        <iframe
                            title={`Map of ${venueName || 'location'}`}
                            width="100%"
                            height="200"
                            frameBorder="0"
                            scrolling="no"
                            marginHeight="0"
                            marginWidth="0"
                            src={getMapUrl()}
                            loading="lazy"
                        />
                        <a
                            href={`https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=16/${latitude}/${longitude}`}
                            className="map-link"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            View larger map
                        </a>
                    </div>
                )}

                {/* Hidden microformat data */}
                <data className="u-checkin" value={venueUrl || ''} hidden />
                {foursquareId && (
                    <data
                        className="u-uid"
                        value={`https://foursquare.com/v/${foursquareId}`}
                        hidden
                    />
                )}
            </div>
        </div>
    );
}
