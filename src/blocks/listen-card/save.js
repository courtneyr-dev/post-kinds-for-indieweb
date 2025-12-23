/**
 * Listen Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps, RichText } from '@wordpress/block-editor';
import { starIcon, starOutlineIcon } from '../shared/icons';

/**
 * Save component for the Listen Card block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block save component.
 */
export default function Save({ attributes }) {
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

    const blockProps = useBlockProps.save({
        className: `listen-card layout-${layout}`,
    });

    // Generate stars for rating
    const renderStars = () => {
        if (!rating || rating <= 0) {
            return null;
        }

        return (
            <div className="rating-display p-rating" aria-label={`Rating: ${rating} out of 5 stars`}>
                {Array.from({ length: 5 }, (_, i) => (
                    <span
                        key={i}
                        className={`star ${i < rating ? 'filled' : ''}`}
                        aria-hidden="true"
                    >
                        ★
                    </span>
                ))}
                <span className="rating-value">{rating}/5</span>
            </div>
        );
    };

    return (
        <div {...blockProps}>
            <div className="listen-card-inner h-cite">
                {/* Cover image */}
                {coverImage && (
                    <div className="cover-image">
                        <img
                            src={coverImage}
                            alt={coverImageAlt || `${trackTitle} by ${artistName}`}
                            className="u-photo"
                            loading="lazy"
                        />
                    </div>
                )}

                <div className="listen-info">
                    {/* Track title */}
                    {trackTitle && (
                        <h3 className="track-title p-name">
                            {listenUrl ? (
                                <a href={listenUrl} className="u-url" target="_blank" rel="noopener noreferrer">
                                    {trackTitle}
                                </a>
                            ) : (
                                trackTitle
                            )}
                        </h3>
                    )}

                    {/* Artist */}
                    {artistName && (
                        <p className="artist-name">
                            <span className="p-author h-card">
                                <span className="p-name">{artistName}</span>
                            </span>
                        </p>
                    )}

                    {/* Album */}
                    {albumTitle && (
                        <p className="album-title">
                            {albumTitle}
                            {releaseDate && (
                                <span className="release-date">
                                    {' '}({new Date(releaseDate).getFullYear()})
                                </span>
                            )}
                        </p>
                    )}

                    {/* Rating */}
                    {renderStars()}

                    {/* Listened timestamp */}
                    {listenedAt && (
                        <time
                            className="listened-at dt-published"
                            dateTime={new Date(listenedAt).toISOString()}
                        >
                            {new Date(listenedAt).toLocaleString()}
                        </time>
                    )}
                </div>

                {/* Hidden microformat data */}
                <data className="u-listen-of" value={listenUrl || ''} hidden />
                {musicbrainzId && (
                    <data
                        className="u-uid"
                        value={`https://musicbrainz.org/recording/${musicbrainzId}`}
                        hidden
                    />
                )}
            </div>
        </div>
    );
}
