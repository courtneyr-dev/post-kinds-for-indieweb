/**
 * Media Lookup Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps } from '@wordpress/block-editor';

/**
 * Save component for the Media Lookup block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element|null} Block save component.
 */
export default function Save({ attributes }) {
    const {
        mediaType,
        selectedItem,
        displayStyle,
        showImage,
        showDescription,
        linkToSource,
    } = attributes;

    // Don't render if no item selected
    if (!selectedItem) {
        return null;
    }

    const blockProps = useBlockProps.save({
        className: `media-lookup-block type-${mediaType} style-${displayStyle}`,
    });

    // Extract common fields
    const title = selectedItem.title || selectedItem.name || '';
    const subtitle = selectedItem.author || selectedItem.artist || selectedItem.director || '';
    const year = selectedItem.year || selectedItem.release_year || selectedItem.first_publish_year || '';
    const image = selectedItem.cover || selectedItem.image || selectedItem.poster || '';
    const description = selectedItem.description || selectedItem.overview || '';
    const url = selectedItem.url || selectedItem.link || '';
    const id = selectedItem.id || selectedItem.key || '';

    /**
     * Get source URL based on media type
     */
    const getSourceUrl = () => {
        if (url) return url;

        switch (mediaType) {
            case 'book':
                if (selectedItem.key) {
                    return `https://openlibrary.org${selectedItem.key}`;
                }
                if (selectedItem.isbn) {
                    return `https://openlibrary.org/isbn/${selectedItem.isbn}`;
                }
                break;
            case 'movie':
                if (selectedItem.tmdb_id) {
                    const type = selectedItem.media_type === 'tv' ? 'tv' : 'movie';
                    return `https://www.themoviedb.org/${type}/${selectedItem.tmdb_id}`;
                }
                if (selectedItem.imdb_id) {
                    return `https://www.imdb.com/title/${selectedItem.imdb_id}`;
                }
                break;
            case 'music':
                if (selectedItem.musicbrainz_id) {
                    return `https://musicbrainz.org/recording/${selectedItem.musicbrainz_id}`;
                }
                break;
        }
        return null;
    };

    const sourceUrl = getSourceUrl();

    /**
     * Get microformat class based on media type
     */
    const getMicroformatClass = () => {
        switch (mediaType) {
            case 'book':
                return 'h-cite p-read-of';
            case 'movie':
                return 'h-cite p-watch-of';
            case 'music':
                return 'h-cite p-listen-of';
            default:
                return 'h-cite';
        }
    };

    return (
        <div {...blockProps}>
            <div className={`media-lookup-inner ${getMicroformatClass()}`}>
                {/* Cover image */}
                {showImage && image && (
                    <div className="media-image">
                        <img
                            src={image}
                            alt={title}
                            className="u-photo"
                            loading="lazy"
                        />
                    </div>
                )}

                <div className="media-info">
                    {/* Title */}
                    <h3 className="media-title p-name">
                        {linkToSource && sourceUrl ? (
                            <a
                                href={sourceUrl}
                                className="u-url"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {title}
                            </a>
                        ) : (
                            title
                        )}
                    </h3>

                    {/* Subtitle (author/artist/director) */}
                    {subtitle && (
                        <p className="media-subtitle p-author h-card">
                            <span className="p-name">{subtitle}</span>
                        </p>
                    )}

                    {/* Year */}
                    {year && (
                        <span className="media-year">
                            <time className="dt-published">{year}</time>
                        </span>
                    )}

                    {/* Description */}
                    {showDescription && description && displayStyle !== 'compact' && (
                        <p className="media-description p-summary">
                            {description.length > 200
                                ? `${description.substring(0, 200)}...`
                                : description
                            }
                        </p>
                    )}

                    {/* Additional metadata based on type */}
                    <div className="media-meta">
                        {mediaType === 'book' && selectedItem.isbn && (
                            <data className="p-isbn" value={selectedItem.isbn} hidden />
                        )}
                        {mediaType === 'book' && selectedItem.publisher && (
                            <span className="p-publisher">{selectedItem.publisher}</span>
                        )}
                        {mediaType === 'movie' && selectedItem.runtime && (
                            <span className="runtime">{selectedItem.runtime} min</span>
                        )}
                        {mediaType === 'music' && selectedItem.album && (
                            <span className="album">{selectedItem.album}</span>
                        )}
                    </div>
                </div>

                {/* Hidden microformat data */}
                {sourceUrl && <data className="u-uid" value={sourceUrl} hidden />}
                {id && <data className="p-uid" value={id} hidden />}
            </div>
        </div>
    );
}
