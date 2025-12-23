/**
 * Watch Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps, RichText } from '@wordpress/block-editor';

/**
 * Save component for the Watch Card block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block save component.
 */
export default function Save({ attributes }) {
    const {
        mediaTitle,
        mediaType,
        showTitle,
        seasonNumber,
        episodeNumber,
        episodeTitle,
        releaseYear,
        director,
        posterImage,
        posterImageAlt,
        watchUrl,
        tmdbId,
        imdbId,
        rating,
        isRewatch,
        watchedAt,
        review,
        layout,
    } = attributes;

    const blockProps = useBlockProps.save({
        className: `watch-card layout-${layout} type-${mediaType}`,
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

    // Format episode string
    const getEpisodeString = () => {
        if (mediaType !== 'episode') {
            return null;
        }

        let str = '';
        if (seasonNumber) {
            str += `S${String(seasonNumber).padStart(2, '0')}`;
        }
        if (episodeNumber) {
            str += `E${String(episodeNumber).padStart(2, '0')}`;
        }
        if (episodeTitle) {
            str += ` - ${episodeTitle}`;
        }
        return str || null;
    };

    // Get TMDB URL
    const getTmdbUrl = () => {
        if (!tmdbId) return null;
        const type = mediaType === 'movie' ? 'movie' : 'tv';
        return `https://www.themoviedb.org/${type}/${tmdbId}`;
    };

    // Get IMDb URL
    const getImdbUrl = () => {
        if (!imdbId) return null;
        return `https://www.imdb.com/title/${imdbId}`;
    };

    return (
        <div {...blockProps}>
            <div className="watch-card-inner h-cite">
                {/* Poster image */}
                {posterImage && (
                    <div className="poster-image">
                        <img
                            src={posterImage}
                            alt={posterImageAlt || mediaTitle}
                            className="u-photo"
                            loading="lazy"
                        />
                    </div>
                )}

                <div className="watch-info">
                    {/* Show title for episodes */}
                    {mediaType === 'episode' && showTitle && (
                        <p className="show-title">{showTitle}</p>
                    )}

                    {/* Media title */}
                    {mediaTitle && (
                        <h3 className="media-title p-name">
                            {watchUrl ? (
                                <a href={watchUrl} className="u-url" target="_blank" rel="noopener noreferrer">
                                    {mediaTitle}
                                </a>
                            ) : (
                                mediaTitle
                            )}
                        </h3>
                    )}

                    {/* Episode info */}
                    {getEpisodeString() && (
                        <p className="episode-info">{getEpisodeString()}</p>
                    )}

                    {/* Meta line */}
                    <div className="meta-line">
                        {releaseYear && (
                            <span className="year">({releaseYear})</span>
                        )}
                        {director && (
                            <span className="director p-author h-card">
                                <span className="p-name">{director}</span>
                            </span>
                        )}
                        {isRewatch && (
                            <span className="rewatch-badge">Rewatch</span>
                        )}
                    </div>

                    {/* Rating */}
                    {renderStars()}

                    {/* Review */}
                    {review && (
                        <div className="watch-review p-content">
                            <RichText.Content tagName="p" value={review} />
                        </div>
                    )}

                    {/* Watched timestamp */}
                    {watchedAt && (
                        <time
                            className="watched-at dt-published"
                            dateTime={new Date(watchedAt).toISOString()}
                        >
                            {new Date(watchedAt).toLocaleString()}
                        </time>
                    )}

                    {/* External links */}
                    <div className="external-links">
                        {getImdbUrl() && (
                            <a href={getImdbUrl()} target="_blank" rel="noopener noreferrer" className="imdb-link">
                                IMDb
                            </a>
                        )}
                        {getTmdbUrl() && (
                            <a href={getTmdbUrl()} target="_blank" rel="noopener noreferrer" className="tmdb-link">
                                TMDB
                            </a>
                        )}
                    </div>
                </div>

                {/* Hidden microformat data */}
                <data className="u-watch-of" value={watchUrl || ''} hidden />
                {tmdbId && (
                    <data className="u-uid" value={getTmdbUrl()} hidden />
                )}
            </div>
        </div>
    );
}
