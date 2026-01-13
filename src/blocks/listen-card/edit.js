/**
 * Listen Card Block - Edit Component
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
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { listenIcon } from '../shared/icons';
import {
    StarRating,
    CoverImage,
    MediaSearch,
    BlockPlaceholder,
} from '../shared/components';

/**
 * Edit component for the Listen Card block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block edit component.
 */
export default function Edit({ attributes, setAttributes }) {
    const {
        trackTitle,
        artistName,
        albumTitle,
        releaseDate,
        coverImage,
        coverImageAlt,
        listenUrl,
        musicbrainzId,
        rating,
        listenedAt,
        layout,
    } = attributes;

    const [showDatePicker, setShowDatePicker] = useState(false);
    const [isSearching, setIsSearching] = useState(false);

    const blockProps = useBlockProps({
        className: `listen-card layout-${layout}`,
    });

    /**
     * Handle media search result selection
     */
    const handleSearchSelect = (item) => {
        // MusicBrainz returns 'track', other APIs may return 'title' or 'name'
        const trackName = item.track || item.title || item.name || '';
        setAttributes({
            trackTitle: trackName,
            artistName: item.artist || '',
            albumTitle: item.album || '',
            releaseDate: item.releaseDate || item.date || '',
            coverImage: item.cover || item.image || '',
            coverImageAlt: `${trackName} by ${item.artist || ''}`,
            musicbrainzId: item.mbid || item.id || '',
        });
        setIsSearching(false);
    };

    /**
     * Handle cover image selection
     */
    const handleImageSelect = (media) => {
        setAttributes({
            coverImage: media.url,
            coverImageAlt: media.alt || `${trackTitle} by ${artistName}`,
        });
    };

    // Show placeholder if no track info
    if (!trackTitle && !artistName) {
        return (
            <div {...blockProps}>
                <BlockPlaceholder
                    icon={listenIcon}
                    label={__('Listen Card', 'reactions-for-indieweb')}
                    instructions={__('Add a track you listened to. Search for music or enter details manually.', 'reactions-for-indieweb')}
                >
                    {isSearching ? (
                        <div className="search-mode">
                            <MediaSearch
                                type="music"
                                placeholder={__('Search for a song or album...', 'reactions-for-indieweb')}
                                onSelect={handleSearchSelect}
                            />
                            <Button
                                variant="link"
                                onClick={() => setIsSearching(false)}
                            >
                                {__('Enter manually', 'reactions-for-indieweb')}
                            </Button>
                        </div>
                    ) : (
                        <div className="placeholder-actions">
                            <Button
                                variant="primary"
                                onClick={() => setIsSearching(true)}
                            >
                                {__('Search Music', 'reactions-for-indieweb')}
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() => setAttributes({ trackTitle: '' })}
                            >
                                {__('Enter Manually', 'reactions-for-indieweb')}
                            </Button>
                        </div>
                    )}
                </BlockPlaceholder>
            </div>
        );
    }

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Search Music', 'reactions-for-indieweb')} initialOpen={false}>
                    <MediaSearch
                        type="music"
                        placeholder={__('Search for a song or album...', 'reactions-for-indieweb')}
                        onSelect={handleSearchSelect}
                    />
                    <p className="components-base-control__help" style={{ marginTop: '8px' }}>
                        {__('Search MusicBrainz to auto-fill track details.', 'reactions-for-indieweb')}
                    </p>
                </PanelBody>
                <PanelBody title={__('Track Details', 'reactions-for-indieweb')}>
                    <TextControl
                        label={__('Track Title', 'reactions-for-indieweb')}
                        value={trackTitle || ''}
                        onChange={(value) => setAttributes({ trackTitle: value })}
                    />
                    <TextControl
                        label={__('Artist', 'reactions-for-indieweb')}
                        value={artistName || ''}
                        onChange={(value) => setAttributes({ artistName: value })}
                    />
                    <TextControl
                        label={__('Album', 'reactions-for-indieweb')}
                        value={albumTitle || ''}
                        onChange={(value) => setAttributes({ albumTitle: value })}
                    />
                    <TextControl
                        label={__('Release Date', 'reactions-for-indieweb')}
                        value={releaseDate || ''}
                        onChange={(value) => setAttributes({ releaseDate: value })}
                        type="date"
                    />
                </PanelBody>

                <PanelBody title={__('Listen Info', 'reactions-for-indieweb')}>
                    <div className="components-base-control">
                        <label className="components-base-control__label">
                            {__('Rating', 'reactions-for-indieweb')}
                        </label>
                        <StarRating
                            value={rating}
                            onChange={(value) => setAttributes({ rating: value })}
                            max={5}
                        />
                    </div>

                    <div className="components-base-control">
                        <label className="components-base-control__label">
                            {__('Listened At', 'reactions-for-indieweb')}
                        </label>
                        <Button
                            variant="secondary"
                            onClick={() => setShowDatePicker(true)}
                        >
                            {listenedAt
                                ? new Date(listenedAt).toLocaleString()
                                : __('Set date/time', 'reactions-for-indieweb')
                            }
                        </Button>
                        {showDatePicker && (
                            <Popover onClose={() => setShowDatePicker(false)}>
                                <DateTimePicker
                                    currentDate={listenedAt}
                                    onChange={(value) => {
                                        setAttributes({ listenedAt: value });
                                        setShowDatePicker(false);
                                    }}
                                    is12Hour={true}
                                />
                            </Popover>
                        )}
                    </div>

                    <TextControl
                        label={__('Listen URL', 'reactions-for-indieweb')}
                        value={listenUrl || ''}
                        onChange={(value) => setAttributes({ listenUrl: value })}
                        type="url"
                        help={__('Link to the track on a streaming service.', 'reactions-for-indieweb')}
                    />
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

                <PanelBody title={__('Metadata', 'reactions-for-indieweb')} initialOpen={false}>
                    <TextControl
                        label={__('MusicBrainz ID', 'reactions-for-indieweb')}
                        value={musicbrainzId || ''}
                        onChange={(value) => setAttributes({ musicbrainzId: value })}
                        help={__('Used for linking to music databases.', 'reactions-for-indieweb')}
                    />
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <div className="reactions-card h-cite">
                    <div className="cover-image">
                        <MediaUploadCheck>
                            <MediaUpload
                                onSelect={handleImageSelect}
                                allowedTypes={['image']}
                                render={({ open }) => (
                                    <div onClick={open} role="button" tabIndex={0}>
                                        <CoverImage
                                            src={coverImage}
                                            alt={coverImageAlt}
                                            size="medium"
                                        />
                                    </div>
                                )}
                            />
                        </MediaUploadCheck>
                    </div>

                    <div className="listen-info">
                        <RichText
                            tagName="h3"
                            className="track-title p-name"
                            value={trackTitle}
                            onChange={(value) => setAttributes({ trackTitle: value })}
                            placeholder={__('Track title', 'reactions-for-indieweb')}
                        />

                        <RichText
                            tagName="p"
                            className="artist-name p-author h-card"
                            value={artistName}
                            onChange={(value) => setAttributes({ artistName: value })}
                            placeholder={__('Artist name', 'reactions-for-indieweb')}
                        />

                        {(albumTitle || layout !== 'compact') && (
                            <RichText
                                tagName="p"
                                className="album-title"
                                value={albumTitle}
                                onChange={(value) => setAttributes({ albumTitle: value })}
                                placeholder={__('Album title', 'reactions-for-indieweb')}
                            />
                        )}

                        {rating > 0 && (
                            <div className="rating-display">
                                <StarRating value={rating} readonly={true} max={5} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
