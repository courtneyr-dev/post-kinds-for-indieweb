/**
 * Checkin Card Block - Edit Component
 *
 * Enhanced with location search, geolocation, and privacy controls.
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
    RadioControl,
    Notice,
    Spinner,
} from '@wordpress/components';
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { checkinIcon } from '../shared/icons';
import { BlockPlaceholder, LocationDisplay } from '../shared/components';

/**
 * Debounce utility function
 *
 * @param {Function} func Function to debounce.
 * @param {number} wait Milliseconds to wait.
 * @returns {Function} Debounced function.
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

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
        locationPrivacy,
        osmId,
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
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState([]);
    const [isSearching, setIsSearching] = useState(false);
    const [isLocating, setIsLocating] = useState(false);
    const [error, setError] = useState(null);
    const [showSearch, setShowSearch] = useState(false);

    const searchInputRef = useRef(null);

    const blockProps = useBlockProps({
        className: `checkin-card layout-${layout}`,
    });

    // Venue type options
    const venueTypes = [
        { label: __('Place', 'reactions-for-indieweb'), value: 'place' },
        { label: __('Restaurant', 'reactions-for-indieweb'), value: 'restaurant' },
        { label: __('Cafe', 'reactions-for-indieweb'), value: 'cafe' },
        { label: __('Bar', 'reactions-for-indieweb'), value: 'bar' },
        { label: __('Hotel', 'reactions-for-indieweb'), value: 'hotel' },
        { label: __('Airport', 'reactions-for-indieweb'), value: 'airport' },
        { label: __('Park', 'reactions-for-indieweb'), value: 'park' },
        { label: __('Museum', 'reactions-for-indieweb'), value: 'museum' },
        { label: __('Theater', 'reactions-for-indieweb'), value: 'theater' },
        { label: __('Store', 'reactions-for-indieweb'), value: 'store' },
        { label: __('Office', 'reactions-for-indieweb'), value: 'office' },
        { label: __('Home', 'reactions-for-indieweb'), value: 'home' },
        { label: __('Other', 'reactions-for-indieweb'), value: 'other' },
    ];

    // Privacy options
    const privacyOptions = [
        {
            label: __('Public (exact location)', 'reactions-for-indieweb'),
            value: 'public',
        },
        {
            label: __('Approximate (city level)', 'reactions-for-indieweb'),
            value: 'approximate',
        },
        {
            label: __('Private (hidden)', 'reactions-for-indieweb'),
            value: 'private',
        },
    ];

    /**
     * Search for venues using the REST API proxy
     */
    const searchVenues = useCallback(
        debounce(async (query) => {
            if (!query || query.trim().length < 3) {
                setSearchResults([]);
                return;
            }

            setIsSearching(true);
            setError(null);

            try {
                const results = await apiFetch({
                    path: `/reactions-indieweb/v1/location/search?query=${encodeURIComponent(query)}`,
                });

                setSearchResults(results || []);
            } catch (err) {
                setError(err.message || __('Search failed. Please try again.', 'reactions-for-indieweb'));
                setSearchResults([]);
            } finally {
                setIsSearching(false);
            }
        }, 500),
        []
    );

    /**
     * Handle search input change
     */
    const handleSearchChange = (value) => {
        setSearchQuery(value);
        searchVenues(value);
    };

    /**
     * Use browser geolocation to get current location
     */
    const useCurrentLocation = async () => {
        if (!navigator.geolocation) {
            setError(__('Geolocation is not supported by your browser.', 'reactions-for-indieweb'));
            return;
        }

        setIsLocating(true);
        setError(null);

        navigator.geolocation.getCurrentPosition(
            async (position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;

                try {
                    // Reverse geocode via REST API proxy
                    const result = await apiFetch({
                        path: `/reactions-indieweb/v1/location/reverse?lat=${lat}&lon=${lng}`,
                    });

                    if (result) {
                        selectVenue(result);
                    } else {
                        // Just set coords if reverse geocode failed
                        setAttributes({
                            latitude: lat,
                            longitude: lng,
                            venueName: __('Current Location', 'reactions-for-indieweb'),
                        });
                    }
                } catch (err) {
                    // Still set coordinates even if reverse geocode fails
                    setAttributes({
                        latitude: lat,
                        longitude: lng,
                        venueName: __('Current Location', 'reactions-for-indieweb'),
                    });
                } finally {
                    setIsLocating(false);
                    setShowSearch(false);
                }
            },
            (err) => {
                setIsLocating(false);
                switch (err.code) {
                    case err.PERMISSION_DENIED:
                        setError(__('Location access was denied.', 'reactions-for-indieweb'));
                        break;
                    case err.POSITION_UNAVAILABLE:
                        setError(__('Location information is unavailable.', 'reactions-for-indieweb'));
                        break;
                    case err.TIMEOUT:
                        setError(__('Location request timed out.', 'reactions-for-indieweb'));
                        break;
                    default:
                        setError(__('Could not detect your location.', 'reactions-for-indieweb'));
                }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    };

    /**
     * Handle venue selection from search results
     */
    const selectVenue = (result) => {
        const addr = result.address || {};

        setAttributes({
            venueName: result.name || result.display_name?.split(',')[0] || '',
            address: [addr.house_number, addr.road].filter(Boolean).join(' ') || addr.road || '',
            locality: addr.locality || addr.city || addr.town || addr.village || '',
            region: addr.region || addr.state || '',
            country: addr.country || '',
            postalCode: addr.postcode || '',
            latitude: result.latitude || parseFloat(result.lat) || null,
            longitude: result.longitude || parseFloat(result.lon) || null,
            osmId: result.osm_full_id || '',
            venueUrl: result.extra?.website || '',
        });

        // Clear search state
        setSearchQuery('');
        setSearchResults([]);
        setShowSearch(false);
    };

    /**
     * Handle privacy change with confirmation for public
     */
    const handlePrivacyChange = (newPrivacy) => {
        if (newPrivacy === 'public' && locationPrivacy !== 'public') {
            // eslint-disable-next-line no-alert
            const confirmed = window.confirm(
                __('You are about to make your precise location public. Are you sure?', 'reactions-for-indieweb')
            );
            if (!confirmed) {
                return;
            }
        }
        setAttributes({ locationPrivacy: newPrivacy });
    };

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
        // Adjust zoom based on privacy
        const zoom = locationPrivacy === 'public' ? 16 : 11;
        const bbox = locationPrivacy === 'public' ? 0.01 : 0.1;
        return `https://www.openstreetmap.org/export/embed.html?bbox=${longitude - bbox},${latitude - bbox},${longitude + bbox},${latitude + bbox}&layer=mapnik&marker=${latitude},${longitude}`;
    };

    /**
     * Clear venue and start fresh
     */
    const clearVenue = () => {
        setAttributes({
            venueName: '',
            address: '',
            locality: '',
            region: '',
            country: '',
            postalCode: '',
            latitude: null,
            longitude: null,
            osmId: '',
        });
        setShowSearch(true);
    };

    // Show placeholder if no venue info
    if (!venueName && !locality && !showSearch) {
        return (
            <div {...blockProps}>
                <BlockPlaceholder
                    icon={checkinIcon}
                    label={__('Checkin Card', 'reactions-for-indieweb')}
                    instructions={__('Check in to a location. Use your current location or search for a venue.', 'reactions-for-indieweb')}
                >
                    {error && (
                        <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                            {error}
                        </Notice>
                    )}

                    <div className="checkin-placeholder-actions">
                        <Button
                            variant="primary"
                            onClick={useCurrentLocation}
                            disabled={isLocating}
                            icon={isLocating ? null : 'location'}
                        >
                            {isLocating ? (
                                <>
                                    <Spinner />
                                    {__('Detecting...', 'reactions-for-indieweb')}
                                </>
                            ) : (
                                __('Use Current Location', 'reactions-for-indieweb')
                            )}
                        </Button>

                        <Button
                            variant="secondary"
                            onClick={() => setShowSearch(true)}
                            icon="search"
                        >
                            {__('Search for Venue', 'reactions-for-indieweb')}
                        </Button>

                        <Button
                            variant="tertiary"
                            onClick={() => setAttributes({ venueName: '' })}
                        >
                            {__('Enter Manually', 'reactions-for-indieweb')}
                        </Button>
                    </div>
                </BlockPlaceholder>
            </div>
        );
    }

    // Show search interface
    if (showSearch && !venueName) {
        return (
            <div {...blockProps}>
                <div className="checkin-search-state">
                    <h3>{__('Search for a location', 'reactions-for-indieweb')}</h3>

                    {error && (
                        <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                            {error}
                        </Notice>
                    )}

                    <div className="checkin-search-wrapper">
                        <TextControl
                            ref={searchInputRef}
                            value={searchQuery}
                            onChange={handleSearchChange}
                            placeholder={__('Search for a venue or address...', 'reactions-for-indieweb')}
                            autoFocus
                        />

                        {isSearching && <Spinner />}

                        {searchResults.length > 0 && (
                            <ul className="checkin-search-results" role="listbox">
                                {searchResults.map((result, index) => (
                                    <li key={result.place_id || index}>
                                        <button
                                            type="button"
                                            className="checkin-result-item"
                                            onClick={() => selectVenue(result)}
                                        >
                                            <strong className="result-name">
                                                {result.name || result.display_name?.split(',')[0]}
                                            </strong>
                                            <span className="result-address">
                                                {result.formatted_address || result.display_name}
                                            </span>
                                            {result.type && (
                                                <span className="result-type">{result.type}</span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="checkin-search-actions">
                        <Button
                            variant="secondary"
                            onClick={useCurrentLocation}
                            disabled={isLocating}
                        >
                            {isLocating ? __('Detecting...', 'reactions-for-indieweb') : __('Use Current Location', 'reactions-for-indieweb')}
                        </Button>

                        <Button
                            variant="tertiary"
                            onClick={() => {
                                setShowSearch(false);
                                setAttributes({ venueName: '' });
                            }}
                        >
                            {__('Enter Manually', 'reactions-for-indieweb')}
                        </Button>

                        <Button
                            variant="link"
                            onClick={() => setShowSearch(false)}
                        >
                            {__('Cancel', 'reactions-for-indieweb')}
                        </Button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Privacy Settings', 'reactions-for-indieweb')}>
                    <RadioControl
                        label={__('Location Privacy', 'reactions-for-indieweb')}
                        help={__('Control how much location detail is shown publicly.', 'reactions-for-indieweb')}
                        selected={locationPrivacy || 'approximate'}
                        options={privacyOptions}
                        onChange={handlePrivacyChange}
                    />

                    <div className="privacy-explanations">
                        {locationPrivacy === 'public' && (
                            <Notice status="warning" isDismissible={false}>
                                {__('Your exact coordinates will be visible to everyone.', 'reactions-for-indieweb')}
                            </Notice>
                        )}
                        {locationPrivacy === 'approximate' && (
                            <p className="description">
                                {__('Only city/region will be shown. Coordinates are stored but not displayed.', 'reactions-for-indieweb')}
                            </p>
                        )}
                        {locationPrivacy === 'private' && (
                            <p className="description">
                                {__('Location is saved for your records but not shown publicly.', 'reactions-for-indieweb')}
                            </p>
                        )}
                    </div>
                </PanelBody>

                <PanelBody title={__('Venue Details', 'reactions-for-indieweb')}>
                    <TextControl
                        label={__('Venue Name', 'reactions-for-indieweb')}
                        value={venueName || ''}
                        onChange={(value) => setAttributes({ venueName: value })}
                    />
                    <SelectControl
                        label={__('Venue Type', 'reactions-for-indieweb')}
                        value={venueType}
                        options={venueTypes}
                        onChange={(value) => setAttributes({ venueType: value })}
                    />
                    <TextControl
                        label={__('Street Address', 'reactions-for-indieweb')}
                        value={address || ''}
                        onChange={(value) => setAttributes({ address: value })}
                    />
                    <TextControl
                        label={__('City/Locality', 'reactions-for-indieweb')}
                        value={locality || ''}
                        onChange={(value) => setAttributes({ locality: value })}
                    />
                    <TextControl
                        label={__('State/Region', 'reactions-for-indieweb')}
                        value={region || ''}
                        onChange={(value) => setAttributes({ region: value })}
                    />
                    <TextControl
                        label={__('Country', 'reactions-for-indieweb')}
                        value={country || ''}
                        onChange={(value) => setAttributes({ country: value })}
                    />
                    <TextControl
                        label={__('Postal Code', 'reactions-for-indieweb')}
                        value={postalCode || ''}
                        onChange={(value) => setAttributes({ postalCode: value })}
                    />

                    <Button
                        variant="secondary"
                        onClick={() => setShowSearch(true)}
                        style={{ marginTop: '12px' }}
                    >
                        {__('Search Different Location', 'reactions-for-indieweb')}
                    </Button>
                </PanelBody>

                <PanelBody title={__('Coordinates', 'reactions-for-indieweb')} initialOpen={false}>
                    <TextControl
                        label={__('Latitude', 'reactions-for-indieweb')}
                        value={latitude || ''}
                        onChange={(value) => setAttributes({ latitude: parseFloat(value) || null })}
                        type="number"
                        step="any"
                    />
                    <TextControl
                        label={__('Longitude', 'reactions-for-indieweb')}
                        value={longitude || ''}
                        onChange={(value) => setAttributes({ longitude: parseFloat(value) || null })}
                        type="number"
                        step="any"
                    />
                    <ToggleControl
                        label={__('Show Map', 'reactions-for-indieweb')}
                        checked={showMap}
                        onChange={(value) => setAttributes({ showMap: value })}
                        help={locationPrivacy === 'private'
                            ? __('Map is hidden when privacy is set to private.', 'reactions-for-indieweb')
                            : __('Display an embedded OpenStreetMap.', 'reactions-for-indieweb')
                        }
                        disabled={locationPrivacy === 'private'}
                    />
                </PanelBody>

                <PanelBody title={__('Checkin Details', 'reactions-for-indieweb')}>
                    <div className="components-base-control">
                        <label className="components-base-control__label">
                            {__('Checkin Time', 'reactions-for-indieweb')}
                        </label>
                        <Button
                            variant="secondary"
                            onClick={() => setShowDatePicker(true)}
                        >
                            {checkinAt
                                ? new Date(checkinAt).toLocaleString()
                                : __('Set time', 'reactions-for-indieweb')
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

                <PanelBody title={__('Layout', 'reactions-for-indieweb')}>
                    <SelectControl
                        label={__('Layout Style', 'reactions-for-indieweb')}
                        value={layout}
                        options={[
                            { label: __('Horizontal', 'reactions-for-indieweb'), value: 'horizontal' },
                            { label: __('Vertical', 'reactions-for-indieweb'), value: 'vertical' },
                            { label: __('Map Focus', 'reactions-for-indieweb'), value: 'map' },
                            { label: __('Compact', 'reactions-for-indieweb'), value: 'compact' },
                        ]}
                        onChange={(value) => setAttributes({ layout: value })}
                    />
                </PanelBody>

                <PanelBody title={__('Links', 'reactions-for-indieweb')} initialOpen={false}>
                    <TextControl
                        label={__('Venue URL', 'reactions-for-indieweb')}
                        value={venueUrl || ''}
                        onChange={(value) => setAttributes({ venueUrl: value })}
                        type="url"
                    />
                    <TextControl
                        label={__('Foursquare ID', 'reactions-for-indieweb')}
                        value={foursquareId || ''}
                        onChange={(value) => setAttributes({ foursquareId: value })}
                    />
                    {osmId && (
                        <p className="description">
                            {__('OSM ID:', 'reactions-for-indieweb')} {osmId}
                        </p>
                    )}
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <div className="reactions-card h-entry">
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
                                                <span>{__('Add photo', 'reactions-for-indieweb')}</span>
                                            </div>
                                        )}
                                    </div>
                                )}
                            />
                        </MediaUploadCheck>
                    </div>

                    <div className="checkin-info">
                        <div className="checkin-header">
                            <span className="venue-type-badge">
                                <span className="venue-icon">{getVenueIcon()}</span>
                                {venueTypes.find(t => t.value === venueType)?.label}
                            </span>

                            {locationPrivacy === 'private' && (
                                <span className="privacy-badge private">
                                    🔒 {__('Private', 'reactions-for-indieweb')}
                                </span>
                            )}
                            {locationPrivacy === 'approximate' && (
                                <span className="privacy-badge approximate">
                                    📍 {__('Approximate', 'reactions-for-indieweb')}
                                </span>
                            )}
                        </div>

                        <RichText
                            tagName="h3"
                            className="venue-name p-name"
                            value={venueName}
                            onChange={(value) => setAttributes({ venueName: value })}
                            placeholder={__('Venue name', 'reactions-for-indieweb')}
                        />

                        <div className="venue-location p-location h-card">
                            <LocationDisplay
                                address={locationPrivacy === 'public' ? address : ''}
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
                            placeholder={__('Add a note about this checkin...', 'reactions-for-indieweb')}
                        />

                        <Button
                            variant="link"
                            isDestructive
                            onClick={clearVenue}
                            className="change-venue-button"
                        >
                            {__('Change venue', 'reactions-for-indieweb')}
                        </Button>
                    </div>

                    {/* Map preview - hidden for private */}
                    {showMap && latitude && longitude && locationPrivacy !== 'private' && (
                        <div className="checkin-map">
                            <iframe
                                title={__('Location map', 'reactions-for-indieweb')}
                                width="100%"
                                height="200"
                                frameBorder="0"
                                scrolling="no"
                                marginHeight="0"
                                marginWidth="0"
                                src={getMapUrl()}
                            />
                            {locationPrivacy === 'approximate' && (
                                <p className="map-note">
                                    {__('Showing approximate area. Exact location hidden.', 'reactions-for-indieweb')}
                                </p>
                            )}
                        </div>
                    )}

                    {locationPrivacy === 'private' && latitude && longitude && (
                        <div className="checkin-private-notice">
                            <span className="dashicons dashicons-lock"></span>
                            {__('Location saved privately', 'reactions-for-indieweb')}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
