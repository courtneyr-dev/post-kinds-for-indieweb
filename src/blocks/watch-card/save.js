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
            <div className="reactions-card__rating p-rating" aria-label={`Rating: ${rating} out of 5 stars`}>
                {Array.from({ length: 5 }, (_, i) => (
                    <span
                        key={i}
                        className={`star ${i < rating ? 'filled' : ''}`}
                        aria-hidden="true"
                    >
                        ★
                    </span>
                ))}
                <span className="reactions-card__rating-value">{rating}/5</span>
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
            <div className="reactions-card h-cite">
                {/* Poster image */}
                {posterImage && (
                    <div className="reactions-card__media reactions-card__media--portrait">
                        <img
                            src={posterImage}
                            alt={posterImageAlt || mediaTitle}
                            className="reactions-card__image u-photo"
                            loading="lazy"
                        />
                    </div>
                )}

                <div className="reactions-card__content">
                    {/* Badges row */}
                    <div className="reactions-card__badges">
                        <span className={`reactions-card__badge reactions-card__badge--${mediaType}`}>
                            {mediaType === 'movie' ? 'Movie' : mediaType === 'tv' ? 'TV' : 'Episode'}
                        </span>
                        {isRewatch && (
                            <span className="reactions-card__badge reactions-card__badge--rewatch">Rewatch</span>
                        )}
                    </div>

                    {/* Show title for episodes */}
                    {mediaType === 'episode' && showTitle && (
                        <p className="reactions-card__meta">{showTitle}</p>
                    )}

                    {/* Media title */}
                    {mediaTitle && (
                        <h3 className="reactions-card__title p-name">
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
                        <p className="reactions-card__subtitle">{getEpisodeString()}</p>
                    )}

                    {/* Meta line */}
                    {(releaseYear || director) && (
                        <p className="reactions-card__meta">
                            {releaseYear && <span>({releaseYear})</span>}
                            {releaseYear && director && ' • '}
                            {director && (
                                <span className="p-author h-card">
                                    <span className="p-name">{director}</span>
                                </span>
                            )}
                        </p>
                    )}

                    {/* Rating */}
                    {renderStars()}

                    {/* Review */}
                    {review && (
                        <div className="reactions-card__notes p-content">
                            <RichText.Content tagName="p" value={review} />
                        </div>
                    )}

                    {/* Watched timestamp */}
                    {watchedAt && (
                        <time
                            className="reactions-card__timestamp dt-published"
                            dateTime={new Date(watchedAt).toISOString()}
                        >
                            {new Date(watchedAt).toLocaleString()}
                        </time>
                    )}

                    {/* External links */}
                    {(getImdbUrl() || getTmdbUrl()) && (
                        <div className="reactions-card__links">
                            {getImdbUrl() && (
                                <a href={getImdbUrl()} target="_blank" rel="noopener noreferrer">
                                    IMDb
                                </a>
                            )}
                            {getTmdbUrl() && (
                                <a href={getTmdbUrl()} target="_blank" rel="noopener noreferrer">
                                    TMDB
                                </a>
                            )}
                        </div>
                    )}
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
