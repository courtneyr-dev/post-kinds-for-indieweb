/**
 * Media Lookup Block - Edit Component
 *
 * @package Reactions_For_IndieWeb
 */

import { __ } from '@wordpress/i18n';
import {
    useBlockProps,
    InspectorControls,
} from '@wordpress/block-editor';
import {
    PanelBody,
    TextControl,
    SelectControl,
    ToggleControl,
    Button,
    Spinner,
    Notice,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { searchIcon, bookIcon, filmIcon, musicIcon } from '../shared/icons';
import { BlockPlaceholder, CoverImage, MediaSearch } from '../shared/components';

/**
 * Edit component for the Media Lookup block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block edit component.
 */
export default function Edit({ attributes, setAttributes }) {
    const {
        mediaType,
        searchQuery,
        selectedItem,
        displayStyle,
        showImage,
        showDescription,
        linkToSource,
    } = attributes;

    const [isSearching, setIsSearching] = useState(false);

    const blockProps = useBlockProps({
        className: `media-lookup-block type-${mediaType} style-${displayStyle}`,
    });

    // Media type options
    const mediaTypes = [
        { label: __('Book', 'reactions-indieweb'), value: 'book' },
        { label: __('Movie/TV', 'reactions-indieweb'), value: 'movie' },
        { label: __('Music', 'reactions-indieweb'), value: 'music' },
    ];

    // Display style options
    const displayStyles = [
        { label: __('Card', 'reactions-indieweb'), value: 'card' },
        { label: __('Inline', 'reactions-indieweb'), value: 'inline' },
        { label: __('Compact', 'reactions-indieweb'), value: 'compact' },
    ];

    /**
     * Get icon for media type
     */
    const getMediaIcon = () => {
        const icons = {
            book: '📚',
            movie: '🎬',
            music: '🎵',
        };
        return icons[mediaType] || '📦';
    };

    /**
     * Get placeholder text for search
     */
    const getSearchPlaceholder = () => {
        const placeholders = {
            book: __('Search by title, author, or ISBN...', 'reactions-indieweb'),
            movie: __('Search by title, year, or actor...', 'reactions-indieweb'),
            music: __('Search by song, artist, or album...', 'reactions-indieweb'),
        };
        return placeholders[mediaType] || __('Search...', 'reactions-indieweb');
    };

    /**
     * Get source label
     */
    const getSourceLabel = () => {
        const sources = {
            book: 'Open Library',
            movie: 'TMDB',
            music: 'MusicBrainz',
        };
        return sources[mediaType] || 'External API';
    };

    /**
     * Handle search result selection
     */
    const handleSelect = (item) => {
        setAttributes({
            selectedItem: item,
            searchQuery: '',
        });
        setIsSearching(false);
    };

    /**
     * Clear selection
     */
    const clearSelection = () => {
        setAttributes({
            selectedItem: null,
            searchQuery: '',
        });
    };

    /**
     * Render selected item preview
     */
    const renderSelectedItem = () => {
        if (!selectedItem) return null;

        const title = selectedItem.title || selectedItem.name || '';
        const subtitle = selectedItem.author || selectedItem.artist || selectedItem.director || '';
        const year = selectedItem.year || selectedItem.release_year || selectedItem.first_publish_year || '';
        const image = selectedItem.cover || selectedItem.image || selectedItem.poster || '';
        const description = selectedItem.description || selectedItem.overview || '';
        const url = selectedItem.url || selectedItem.link || '';

        return (
            <div className={`media-item-preview style-${displayStyle}`}>
                {showImage && image && (
                    <div className="media-image">
                        <CoverImage
                            src={image}
                            alt={title}
                            size={displayStyle === 'compact' ? 'small' : 'medium'}
                        />
                    </div>
                )}

                <div className="media-info">
                    <h3 className="media-title">
                        {linkToSource && url ? (
                            <a href={url} target="_blank" rel="noopener noreferrer">
                                {title}
                            </a>
                        ) : (
                            title
                        )}
                    </h3>

                    {subtitle && (
                        <p className="media-subtitle">{subtitle}</p>
                    )}

                    {year && (
                        <span className="media-year">{year}</span>
                    )}

                    {showDescription && description && displayStyle !== 'compact' && (
                        <p className="media-description">
                            {description.length > 200
                                ? `${description.substring(0, 200)}...`
                                : description
                            }
                        </p>
                    )}

                    <div className="media-source">
                        <span className="source-label">
                            {__('via', 'reactions-indieweb')} {getSourceLabel()}
                        </span>
                    </div>
                </div>

                <Button
                    variant="secondary"
                    onClick={clearSelection}
                    className="clear-selection"
                    isSmall
                >
                    {__('Change', 'reactions-indieweb')}
                </Button>
            </div>
        );
    };

    // Show placeholder if no item selected
    if (!selectedItem) {
        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title={__('Media Type', 'reactions-indieweb')}>
                        <SelectControl
                            label={__('Type', 'reactions-indieweb')}
                            value={mediaType}
                            options={mediaTypes}
                            onChange={(value) => setAttributes({ mediaType: value })}
                        />
                    </PanelBody>
                </InspectorControls>

                <BlockPlaceholder
                    icon={<span className="media-type-icon">{getMediaIcon()}</span>}
                    label={__('Media Lookup', 'reactions-indieweb')}
                    instructions={__('Search for media to embed information about a book, movie, or music.', 'reactions-indieweb')}
                >
                    <div className="media-lookup-search">
                        <div className="media-type-selector">
                            {mediaTypes.map(type => (
                                <Button
                                    key={type.value}
                                    variant={mediaType === type.value ? 'primary' : 'secondary'}
                                    onClick={() => setAttributes({ mediaType: type.value })}
                                    isSmall
                                >
                                    {type.label}
                                </Button>
                            ))}
                        </div>

                        <MediaSearch
                            type={mediaType}
                            placeholder={getSearchPlaceholder()}
                            onSelect={handleSelect}
                        />
                    </div>
                </BlockPlaceholder>
            </div>
        );
    }

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Display Settings', 'reactions-indieweb')}>
                    <SelectControl
                        label={__('Display Style', 'reactions-indieweb')}
                        value={displayStyle}
                        options={displayStyles}
                        onChange={(value) => setAttributes({ displayStyle: value })}
                    />
                    <ToggleControl
                        label={__('Show Image', 'reactions-indieweb')}
                        checked={showImage}
                        onChange={(value) => setAttributes({ showImage: value })}
                    />
                    <ToggleControl
                        label={__('Show Description', 'reactions-indieweb')}
                        checked={showDescription}
                        onChange={(value) => setAttributes({ showDescription: value })}
                    />
                    <ToggleControl
                        label={__('Link to Source', 'reactions-indieweb')}
                        checked={linkToSource}
                        onChange={(value) => setAttributes({ linkToSource: value })}
                    />
                </PanelBody>

                <PanelBody title={__('Media Info', 'reactions-indieweb')} initialOpen={false}>
                    <p className="components-base-control__help">
                        {__('Data fetched from', 'reactions-indieweb')} {getSourceLabel()}
                    </p>
                    {selectedItem && (
                        <div className="selected-item-details">
                            <p><strong>{__('Title:', 'reactions-indieweb')}</strong> {selectedItem.title || selectedItem.name}</p>
                            {selectedItem.id && (
                                <p><strong>{__('ID:', 'reactions-indieweb')}</strong> {selectedItem.id}</p>
                            )}
                        </div>
                    )}
                    <Button
                        variant="secondary"
                        onClick={clearSelection}
                        isDestructive
                    >
                        {__('Remove & Search Again', 'reactions-indieweb')}
                    </Button>
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <div className="media-lookup-inner h-cite">
                    {renderSelectedItem()}
                </div>
            </div>
        </>
    );
}
